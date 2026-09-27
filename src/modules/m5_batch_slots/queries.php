<?php
// Path: src/modules/m5_batch_slots/queries.php

/**
 * Executes prepared statement to fetch a configuration value from settings table.
 */
function get_setting_value(string $key, mysqli $conn): ?string {
    $sql = "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $dbErr = @$conn->error ?: 'query error';
        throw new Exception("Database query preparation failed: " . $dbErr);
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ? (string)$row['setting_value'] : null;
}

/**
 * Counts eligible candidates not yet assigned to a batch for a specific assessment.
 */
function get_unbatched_eligible_count(int $assessmentId, mysqli $conn): int {
    $sql = "SELECT COUNT(*) AS total 
            FROM enrollments 
            WHERE assessment_id = ? 
              AND eligibility_status = 'eligible' 
              AND batch_id IS NULL";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $dbErr = @$conn->error ?: 'query error';
        throw new Exception("Database query preparation failed: " . $dbErr);
    }
    $stmt->bind_param("i", $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['total'] : 0;
}

/**
 * Fetches unbatched eligible enrollments up to a limit for batch assignment.
 * When called inside an open transaction, locks selected rows for update.
 */
function get_unbatched_eligible_enrollments(int $assessmentId, int $limit, mysqli $conn): array {
    $sql = "SELECT id, candidate_id, assessment_id 
            FROM enrollments 
            WHERE assessment_id = ? 
              AND eligibility_status = 'eligible' 
              AND batch_id IS NULL 
            ORDER BY created_at ASC, id ASC 
            LIMIT ? 
            FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $dbErr = @$conn->error ?: 'query error';
        throw new Exception("Database query preparation failed: " . $dbErr);
    }
    $stmt->bind_param("ii", $assessmentId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Inserts a new batch record into the batches table.
 */
function insert_batch(string $batchNumber, int $assessmentId, mysqli $conn): int {
    $sql = "INSERT INTO batches (batch_number, assessment_id) 
            VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare batch insert query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("si", $batchNumber, $assessmentId);
    $stmt->execute();
    $batchId = (int)$stmt->insert_id;
    $stmt->close();

    return $batchId;
}

/**
 * Assigns an allocated batch_id to candidate enrollment records.
 */
function assign_batch_to_enrollments(int $batchId, array $enrollmentIds, mysqli $conn): int {
    if (empty($enrollmentIds)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
    $types = 'i' . str_repeat('i', count($enrollmentIds));

    $sql = "UPDATE enrollments 
            SET batch_id = ?, updated_at = NOW() 
            WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare enrollment batch assignment query: " . (@$conn->error ?: 'query error'));
    }

    $params = array_merge([$batchId], array_map('intval', $enrollmentIds));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected;
}

/**
 * Inserts an exam schedule entry (Saturday/Sunday) for a batch.
 */
function insert_exam_schedule(int $batchId, string $examDate, mysqli $conn): int {
    $sql = "INSERT INTO exam_schedules (batch_id, exam_date, status, created_at) 
            VALUES (?, ?, 'scheduled', NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare exam schedule insert query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("is", $batchId, $examDate);
    $stmt->execute();
    $scheduleId = (int)$stmt->insert_id;
    $stmt->close();

    return $scheduleId;
}

/**
 * Inserts an exam time slot under an exam schedule.
 */
function insert_exam_slot(int $scheduleId, string $startTime, string $endTime, int $capacity, mysqli $conn): int {
    $sql = "INSERT INTO exam_slots (exam_schedule_id, start_time, end_time, capacity, seats_remaining, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare exam slot insert query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("issii", $scheduleId, $startTime, $endTime, $capacity, $capacity);
    $stmt->execute();
    $slotId = (int)$stmt->insert_id;
    $stmt->close();

    return $slotId;
}

/**
 * Fetches candidate enrollment details for an assessment.
 */
function get_candidate_enrollment(int $candidateId, int $assessmentId, mysqli $conn): ?array {
    $sql = "SELECT id, candidate_id, assessment_id, batch_id, eligibility_status, provisional_schedule_id 
            FROM enrollments 
            WHERE candidate_id = ? AND assessment_id = ? 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare candidate enrollment query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("ii", $candidateId, $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Checks if a candidate already has an active or booked exam attempt for an assessment.
 */
function get_candidate_booked_attempt(int $candidateId, int $assessmentId, mysqli $conn): ?array {
    $retakeAllowed = get_setting_value('retake_allowed', $conn) === '1';
    $statusFilter = $retakeAllowed ? "'in_progress'" : "'in_progress','submitted','expired'";
    $sql = "SELECT id, candidate_id, assessment_id, exam_slot_id, status, start_time 
            FROM attempts 
            WHERE candidate_id = ? AND assessment_id = ? AND status IN ($statusFilter) 
            ORDER BY id DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare attempt check query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("ii", $candidateId, $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Fetches candidate enrollment with an exclusive row lock (FOR UPDATE).
 * Serializes parallel booking requests for the same candidate and assessment.
 */
function get_candidate_enrollment_for_update(int $candidateId, int $assessmentId, mysqli $conn): ?array {
    $sql = "SELECT id, candidate_id, assessment_id, batch_id, eligibility_status, provisional_schedule_id 
            FROM enrollments 
            WHERE candidate_id = ? AND assessment_id = ? 
            LIMIT 1 
            FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare candidate enrollment lock query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("ii", $candidateId, $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Checks if a candidate already has an attempt within an active transaction with FOR UPDATE.
 * Prevents parallel race condition across different slots.
 */
function get_candidate_booked_attempt_for_update(int $candidateId, int $assessmentId, mysqli $conn): ?array {
    $retakeAllowed = get_setting_value('retake_allowed', $conn) === '1';
    $statusFilter = $retakeAllowed ? "'in_progress'" : "'in_progress','submitted','expired'";
    $sql = "SELECT id, candidate_id, assessment_id, exam_slot_id, status, start_time 
            FROM attempts 
            WHERE candidate_id = ? AND assessment_id = ? AND status IN ($statusFilter) 
            ORDER BY id DESC 
            LIMIT 1 
            FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare attempt lock query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("ii", $candidateId, $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Fetches the candidate's latest attempt for an assessment with an exclusive row lock (FOR UPDATE).
 */
function get_candidate_latest_attempt_for_update(int $candidateId, int $assessmentId, mysqli $conn): ?array {
    $sql = "SELECT id, candidate_id, assessment_id, exam_slot_id, status, start_time 
            FROM attempts 
            WHERE candidate_id = ? AND assessment_id = ? 
            ORDER BY id DESC 
            LIMIT 1 
            FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare attempt lock query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("ii", $candidateId, $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Fetches slot details joined with schedule and batch records.
 */
function get_slot_details(int $slotId, mysqli $conn): ?array {
    $sql = "SELECT 
                s.id AS slot_id,
                s.exam_schedule_id,
                s.start_time,
                s.end_time,
                s.capacity,
                s.seats_remaining,
                sch.batch_id,
                sch.exam_date,
                sch.status AS schedule_status,
                b.assessment_id,
                b.batch_number
            FROM exam_slots s
            JOIN exam_schedules sch ON s.exam_schedule_id = sch.id
            JOIN batches b ON sch.batch_id = b.id
            WHERE s.id = ? 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare slot details query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("i", $slotId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Atomically decrements remaining seats for an exam slot at the database query level.
 * Prevents race condition: updates only if seats_remaining > 0.
 * Returns the number of affected rows (1 if seat secured, 0 if full).
 */
function decrement_slot_capacity(int $slotId, mysqli $conn): int {
    $sql = "UPDATE exam_slots 
            SET seats_remaining = seats_remaining - 1, 
                updated_at = NOW() 
            WHERE id = ? AND seats_remaining > 0";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare slot decrement query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("i", $slotId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected;
}

/**
 * Inserts a new attempt for a candidate.
 */
function insert_attempt(int $candidateId, int $assessmentId, int $slotId, mysqli $conn, string $status = 'in_progress'): int {
    $sql = "INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, created_at) 
            VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare attempt insert query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("iiis", $candidateId, $assessmentId, $slotId, $status);
    $stmt->execute();
    $attemptId = (int)$stmt->insert_id;
    $stmt->close();

    return $attemptId;
}

/**
 * Fetches available exam slots (seats_remaining > 0) for a given batch.
 */
function get_available_slots_by_batch(int $batchId, mysqli $conn): array {
    $sql = "SELECT 
                s.id AS exam_slot_id,
                s.exam_schedule_id,
                sch.batch_id,
                sch.exam_date,
                s.start_time,
                s.end_time,
                s.capacity,
                s.seats_remaining
            FROM exam_slots s
            JOIN exam_schedules sch ON s.exam_schedule_id = sch.id
            WHERE sch.batch_id = ? 
              AND sch.status = 'scheduled' 
              AND (sch.exam_date > CURDATE() OR (sch.exam_date = CURDATE() AND s.end_time > CURTIME()))
              AND s.seats_remaining > 0
            ORDER BY sch.exam_date ASC, s.start_time ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare batch slots query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("i", $batchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Fetches all available exam slots for an assessment across scheduled batches.
 */
function get_available_slots_by_assessment(int $assessmentId, mysqli $conn): array {
    $sql = "SELECT 
                s.id AS exam_slot_id,
                s.exam_schedule_id,
                sch.batch_id,
                sch.exam_date,
                s.start_time,
                s.end_time,
                s.capacity,
                s.seats_remaining
            FROM exam_slots s
            JOIN exam_schedules sch ON s.exam_schedule_id = sch.id
            JOIN batches b ON sch.batch_id = b.id
            WHERE b.assessment_id = ? 
              AND sch.status = 'scheduled' 
              AND (sch.exam_date > CURDATE() OR (sch.exam_date = CURDATE() AND s.end_time > CURTIME()))
              AND s.seats_remaining > 0
            ORDER BY sch.exam_date ASC, s.start_time ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare assessment slots query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("i", $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}



/**
 * Fetches preference groups that have reached the threshold.
 */
function get_preference_groups_meeting_threshold(int $assessmentId, int $threshold, mysqli $conn): array {
    $sql = "SELECT preferred_date, preferred_time_slot, COUNT(*) as candidate_count, GROUP_CONCAT(id) as enrollment_ids 
            FROM enrollments 
            WHERE assessment_id = ? 
              AND batch_id IS NULL 
              AND eligibility_status = 'eligible' 
              AND preferred_date IS NOT NULL 
            GROUP BY preferred_date, preferred_time_slot 
            HAVING COUNT(*) >= ?";
            
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("ii", $assessmentId, $threshold);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $groups = [];
    foreach ($rows as $row) {
        $groups[] = [
            'preferred_date' => $row['preferred_date'],
            'preferred_time_slot' => $row['preferred_time_slot'],
            'candidate_count' => (int)$row['candidate_count'],
            'enrollment_ids' => explode(',', $row['enrollment_ids'])
        ];
    }
    
    return $groups;
}

/**
 * Sets a candidate's preference for an enrollment if they aren't batched yet.
 */
function set_candidate_preference(int $enrollmentId, string $preferredDate, string $preferredTimeSlot, mysqli $conn): void {
    $sql = "UPDATE enrollments 
            SET preferred_date = ?, preferred_time_slot = ?, updated_at = NOW() 
            WHERE id = ? AND batch_id IS NULL";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare set_candidate_preference query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param("ssi", $preferredDate, $preferredTimeSlot, $enrollmentId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    if ($affected === 0) {
        // We do a quick check to see if they're already batched or don't exist
        $sqlCheck = "SELECT batch_id FROM enrollments WHERE id = ?";
        $stmtCheck = $conn->prepare($sqlCheck);
        if ($stmtCheck) {
            $stmtCheck->bind_param("i", $enrollmentId);
            $stmtCheck->execute();
            $res = $stmtCheck->get_result();
            if ($row = $res->fetch_assoc()) {
                if ($row['batch_id'] !== null) {
                    throw new Exception("Candidate is already assigned to a batch. Cannot change preference.");
                }
            }
        }
    }
}
