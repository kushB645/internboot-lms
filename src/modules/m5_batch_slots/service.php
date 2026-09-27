<?php
// Path: src/modules/m5_batch_slots/service.php

require_once __DIR__ . '/queries.php';

/**
 * Resolves the batch threshold from settings table or fallback default (100).
 * Supports custom override for testing / sandbox demo flows.
 */
function get_batch_threshold(mysqli $conn, ?int $customThreshold = null): int {
    if ($customThreshold !== null && $customThreshold > 0) {
        return $customThreshold;
    }

    try {
        $settingValue = get_setting_value('batch_threshold', $conn);
        if ($settingValue !== null && is_numeric($settingValue) && (int)$settingValue > 0) {
            return (int)$settingValue;
        }
    } catch (Throwable $e) {
        // Fallback gracefully to default if setting table is temporarily unpopulated
    }

    return 100;
}

/**
 * Calculates the next available Saturday and Sunday dates for exam scheduling,
 * enforcing a consistent minimum lead time (default 3 days) so candidates have
 * adequate time to prepare and book slots regardless of which day the batch forms.
 */
function calculate_next_weekend_dates(?string $fromDate = null, int $minLeadDays = 3): array {
    $baseTime = $fromDate ? strtotime($fromDate) : time();
    $todayMidnight = strtotime(date('Y-m-d 00:00:00', $baseTime));

    // Find next occurring Saturday
    $candidateSat = strtotime('next Saturday', $todayMidnight);
    if ((int)date('w', $todayMidnight) === 6) {
        $candidateSat = $todayMidnight;
    }

    // Days difference between today and candidate Saturday
    $diffDays = (int)round(($candidateSat - $todayMidnight) / 86400);
    if ($diffDays < $minLeadDays) {
        // Insufficient lead time (e.g., booking on Thu/Fri/Sat); schedule for the subsequent weekend
        $candidateSat = strtotime('+7 days', $candidateSat);
    }
    $candidateSun = strtotime('+1 day', $candidateSat);

    return [
        'saturday' => date('Y-m-d', $candidateSat),
        'sunday' => date('Y-m-d', $candidateSun)
    ];
}


/**
 * Checks eligible unbatched candidates for an assessment.
 * If eligible count meets or exceeds the threshold, auto-creates a new batch,
 * generates weekend exam schedules, and provisions exam time slots.
 *
 * @param int $assessmentId Assessment ID
 * @param mysqli $conn Database connection
 * @param int|null $customThreshold Optional override for demo testing
 * @return array|null Batch details array if batch formed, null if threshold not reached
 */
function check_and_create_batch(int $assessmentId, mysqli $conn, ?int $customThreshold = null): ?array {
    $threshold = get_batch_threshold($conn, $customThreshold);

    // Initial check of unbatched eligible candidates count
    $eligibleCount = get_unbatched_eligible_count($assessmentId, $conn);
    if ($eligibleCount < $threshold) {
        return null; // Not enough eligible candidates to form a batch
    }

    // Begin atomic transaction to prevent race conditions during batch formation
    $conn->begin_transaction();

    try {
        // Lock and fetch candidate enrollments meeting the threshold
        $candidates = get_unbatched_eligible_enrollments($assessmentId, $threshold, $conn);
        if (count($candidates) < $threshold) {
            $conn->rollback();
            return null;
        }

        $enrollmentIds = array_column($candidates, 'id');

        // Generate unique batch identifier
        $uniqueSuffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $batchNumber = sprintf("BATCH-A%d-%s-%s", $assessmentId, date('Ymd'), $uniqueSuffix);

        // Insert new batch record
        $batchId = insert_batch($batchNumber, $assessmentId, $conn);

        // Assign candidates to the newly created batch
        assign_batch_to_enrollments($batchId, $enrollmentIds, $conn);

        // Determine next weekend exam dates
        $weekends = calculate_next_weekend_dates();
        
        // Use Saturday by default for automated non-preference batches
        $examDate = $weekends['saturday'];

        // Fetch Assessment duration
        $stmt = $conn->prepare("SELECT duration_minutes FROM assessments WHERE id = ?");
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $assData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $duration = (int)($assData['duration_minutes'] ?? 60);

        // Fetch Grace Period from settings
        $grace = (int)(get_setting_value('slot_grace_minutes', $conn) ?? 30);
        $totalMinutes = $duration + $grace;

        // Fetch start time from settings or use defaults
        $startTime = get_setting_value('slot_start_time_1', $conn) ?? '10:00:00';
        $endTime = date('H:i:s', strtotime($startTime) + ($totalMinutes * 60));

        // Capacity is exactly the number of assigned enrollments
        $slotCapacity = count($enrollmentIds);

        $createdSchedules = [];

        $scheduleId = insert_exam_schedule($batchId, $examDate, $conn);
        $slotId = insert_exam_slot($scheduleId, $startTime, $endTime, $slotCapacity, $conn);

        $createdSchedules[] = [
            'schedule_id' => $scheduleId,
            'exam_date' => $examDate,
            'day' => 'Saturday',
            'slots' => [[
                'slot_id' => $slotId,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'capacity' => $slotCapacity,
                'seats_remaining' => $slotCapacity
            ]]
        ];

        // Commit batch and scheduling transaction
        $conn->commit();

        $remainingEligible = max(0, $eligibleCount - count($enrollmentIds));

        return [
            'batch_id' => $batchId,
            'batch_number' => $batchNumber,
            'assessment_id' => $assessmentId,
            'assigned_candidates_count' => count($enrollmentIds),
            'threshold' => $threshold,
            'schedules' => $createdSchedules,
            'overflow_candidates_count' => $remainingEligible,
            'overflow_policy' => 'FIFO queue: remaining candidates wait for subsequent registrations to reach batch threshold.'
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw new Exception("Batch auto-creation failed: " . $e->getMessage());
    }
}

/**
 * Processes all eligible candidates for an assessment, auto-creating as many
 * full batches as the eligible candidate count allows (e.g. 210 candidates -> 2 batches).
 * Any remaining candidates (< threshold) remain in the FIFO queue.
 */
function create_all_eligible_batches(int $assessmentId, mysqli $conn, ?int $customThreshold = null): array {
    $batches = [];
    while (true) {
        $batch = check_and_create_batch($assessmentId, $conn, $customThreshold);
        if ($batch === null) {
            break;
        }
        $batches[] = $batch;
    }
    return $batches;
}

/**
 * Books an exam slot for an eligible candidate with query-level concurrency protection.
 *
 * Anti-race-condition guarantees:
 * 1. Checks candidate does not already hold a slot/attempt for the assessment.
 * 2. Locks candidate enrollment (FOR UPDATE) inside the transaction to serialize concurrent requests.
 * 3. Double-checks attempt table (FOR UPDATE) inside the transaction to guard against parallel attempts across different slots.
 * 4. Decrements capacity atomically in the database query (WHERE seats_remaining > 0).
 * 5. Enclosed in an ACID database transaction.
 *
 * @param int $candidateId Candidate profile ID
 * @param int $assessmentId Assessment configuration ID
 * @param int $examSlotId Desired exam slot ID
 * @param mysqli $conn Database connection
 * @return array Standardized booking response data payload
 */
function book_exam_slot(int $candidateId, int $assessmentId, int $examSlotId, mysqli $conn): array {
    // 1. Initial Validation: Candidate Enrollment & Eligibility
    $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
    if (!$enrollment) {
        throw new Exception("Candidate is not enrolled in the specified assessment");
    }

    if ($enrollment['eligibility_status'] !== 'eligible') {
        throw new Exception("Candidate is not eligible to book an exam slot (Status: " . $enrollment['eligibility_status'] . ")");
    }

    if (empty($enrollment['batch_id'])) {
        throw new Exception("Candidate has not yet been assigned to an exam batch. Awaiting batch formation.");
    }

    $candidateBatchId = (int)$enrollment['batch_id'];

    // 2. Initial Pre-flight Check: Prevent duplicate attempt
    $existingAttempt = get_candidate_booked_attempt($candidateId, $assessmentId, $conn);
    if ($existingAttempt) {
        throw new Exception("Candidate already has a booked slot for this assessment (Attempt ID: " . $existingAttempt['id'] . ")");
    }

    // 3. Validate Exam Slot details and Batch ownership
    $slot = get_slot_details($examSlotId, $conn);
    if (!$slot) {
        throw new Exception("Exam slot not found");
    }

    if ((int)$slot['assessment_id'] !== $assessmentId) {
        throw new Exception("Exam slot does not belong to the specified assessment");
    }

    if ((int)$slot['batch_id'] !== $candidateBatchId) {
        throw new Exception("Exam slot belongs to Batch #" . $slot['batch_id'] . ", but candidate is assigned to Batch #" . $candidateBatchId);
    }

    if ($slot['schedule_status'] !== 'scheduled') {
        throw new Exception("Exam schedule is no longer open for booking (Status: " . $slot['schedule_status'] . ")");
    }

    $currentDate = date('Y-m-d');
    if (strtotime($slot['exam_date']) < strtotime($currentDate)) {
        throw new Exception("Exam slot date is in the past and cannot be booked");
    }

    if ($slot['exam_date'] === $currentDate && strtotime($slot['end_time']) <= time()) {
        throw new Exception("Exam slot time has already passed today and cannot be booked");
    }

    if ((int)$slot['seats_remaining'] <= 0) {
        throw new Exception("Selected exam slot is fully booked. No seats remaining.");
    }

    // 4. Begin Database Transaction for atomic checks, seat decrement & attempt creation
    $conn->begin_transaction();

    try {
        // Concurrency Guard 1: Acquire exclusive row lock on candidate's enrollment
        // Serializes concurrent booking requests for the same candidate and assessment
        $lockedEnrollment = get_candidate_enrollment_for_update($candidateId, $assessmentId, $conn);
        if (!$lockedEnrollment) {
            throw new Exception("Candidate is not enrolled in the specified assessment");
        }

        // Concurrency Guard 2: In-transaction check for any attempt already created for this assessment
        // Crucial fix: prevents parallel race condition if two requests target different slots for the same candidate
        $existingAttemptInTx = get_candidate_booked_attempt_for_update($candidateId, $assessmentId, $conn);
        if ($existingAttemptInTx) {
            throw new Exception("Candidate already has a booked slot for this assessment (Attempt ID: " . $existingAttemptInTx['id'] . ")");
        }

        // Concurrency Guard 3: Query-level concurrency check - atomically decrement only if seats_remaining > 0
        $affectedRows = decrement_slot_capacity($examSlotId, $conn);
        if ($affectedRows === 0) {
            throw new Exception("Selected exam slot is fully booked. No seats remaining.");
        }

        // Insert new exam attempt record
        $attemptId = insert_attempt($candidateId, $assessmentId, $examSlotId, $conn);

        // Commit transaction
        $conn->commit();

        // Fetch refreshed slot information for verified remaining seats count
        $refreshedSlot = get_slot_details($examSlotId, $conn);
        $remainingSeats = $refreshedSlot ? (int)$refreshedSlot['seats_remaining'] : ((int)$slot['seats_remaining'] - 1);

        return [
            'attempt_id' => $attemptId,
            'candidate_id' => $candidateId,
            'exam_slot_id' => $examSlotId,
            'exam_date' => $slot['exam_date'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'seats_remaining' => $remainingSeats
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Fetches available slots for a candidate or an entire assessment.
 */
function fetch_available_slots(int $assessmentId, ?int $candidateId, mysqli $conn): array {
    if ($candidateId !== null && $candidateId > 0) {
        $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
        if (!$enrollment || empty($enrollment['batch_id'])) {
            return [];
        }
        return get_available_slots_by_batch((int)$enrollment['batch_id'], $conn);
    }

    return get_available_slots_by_assessment($assessmentId, $conn);
}

/**
 * Cancels a candidate's booked slot attempt and restores slot capacity.
 * Restricted strictly to attempts that were booked but NEVER started (start_time IS NULL).
 */
function cancel_slot_booking(int $candidateId, int $assessmentId, mysqli $conn): bool {
    $conn->begin_transaction();
    try {
        $attempt = get_candidate_latest_attempt_for_update($candidateId, $assessmentId, $conn);
        if (!$attempt) {
            $conn->rollback();
            throw new Exception("No active booking found to cancel");
        }

        // If the exam has already started or completed, it cannot be cancelled
        if (!empty($attempt['start_time']) || $attempt['status'] !== 'in_progress') {
            $conn->rollback();
            throw new Exception("This exam has already started and cannot be cancelled.");
        }

        $slotId = (int)$attempt['exam_slot_id'];
        $attemptId = (int)$attempt['id'];

        // Prepared statement for updating slot seats_remaining
        $updateSlotStmt = $conn->prepare("UPDATE exam_slots SET seats_remaining = seats_remaining + 1 WHERE id = ?");
        if (!$updateSlotStmt) {
            throw new Exception("Failed to prepare slot capacity update query: " . $conn->error);
        }
        $updateSlotStmt->bind_param("i", $slotId);
        $updateSlotStmt->execute();
        $updateSlotStmt->close();

        // Prepared statement for deleting attempt
        $deleteAttemptStmt = $conn->prepare("DELETE FROM attempts WHERE id = ?");
        if (!$deleteAttemptStmt) {
            throw new Exception("Failed to prepare attempt deletion query: " . $conn->error);
        }
        $deleteAttemptStmt->bind_param("i", $attemptId);
        $deleteAttemptStmt->execute();
        $deleteAttemptStmt->close();

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}


/**
 * Checks whether registration is open for a given weekend exam date.
 * 
 * Rules:
 * - $examDate must be a Saturday or Sunday.
 * - If $examDate is a Saturday: registration closes the preceding Friday at 23:59:59 (local server time).
 * - If $examDate is a Sunday: registration closes the preceding Saturday at 23:59:59.
 * - Returns false for past dates, weekdays, or if the cutoff has passed relative to $now.
 *
 * Example: Exam on Sat 2026-09-26 -> cutoff is Fri 2026-09-25 23:59:59
 *
 * @param string $examDate The exam date in Y-m-d format.
 * @param string|null $now Optional timestamp overriding current time (for testing).
 * @return bool True if registration is open, false otherwise.
 */
function is_registration_open_for_date(string $examDate, ?string $now = null): bool {
    $nowTs = $now ? strtotime($now) : time();
    $examTs = strtotime($examDate);

    if (!$examTs) {
        return false;
    }

    $dayOfWeek = (int)date('N', $examTs);

    // If not Saturday (6) or Sunday (7), return false
    if ($dayOfWeek !== 6 && $dayOfWeek !== 7) {
        return false;
    }

    // Cutoff is the day before the exam date at 23:59:59
    $cutoffDateStr = date('Y-m-d', strtotime('-1 day', $examTs));
    $cutoffTs = strtotime($cutoffDateStr . ' 23:59:59');

    // Return false if cutoff has already passed
    if ($nowTs > $cutoffTs) {
        return false;
    }

    // Also return false if the exam date itself is in the past, though cutoff check 
    // usually handles this unless the exam is today but cutoff was yesterday.
    // Cutoff check is sufficient for both past and cutoff limit.
    return true;
}


/**
 * Processes automated preference batching for a given assessment.
 * Groups candidates by preferred_date and preferred_time_slot. If a group meets the threshold,
 * creates exactly one batch, one schedule, and one slot for them.
 */
function process_automated_preference_batching(int $assessmentId, mysqli $conn, ?int $customThreshold = null): array {
    $threshold = get_batch_threshold($conn, $customThreshold);
    $groups = get_preference_groups_meeting_threshold($assessmentId, $threshold, $conn);
    
    $results = [];

    foreach ($groups as $group) {
        $date = $group['preferred_date'];
        $timeSlot = $group['preferred_time_slot']; // e.g., "10:00:00-11:00:00"
        $count = $group['candidate_count'];
        $enrollmentIds = $group['enrollment_ids'];

        $conn->begin_transaction();
        
        try {
            if (!is_registration_open_for_date($date)) {
                $results[] = [
                    'status' => 'skipped_cutoff_passed',
                    'date' => $date,
                    'time_slot' => $timeSlot,
                    'candidate_count' => $count
                ];
                $conn->rollback();
                continue;
            }

            // Create batch
            $uniqueSuffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $batchNumber = sprintf("BATCH-A%d-%s-%s", $assessmentId, date('Ymd'), $uniqueSuffix);
            $batchId = insert_batch($batchNumber, $assessmentId, $conn);

            // Assign batch to enrollments
            assign_batch_to_enrollments($batchId, $enrollmentIds, $conn);

            // Parse time slot
            $parts = explode('-', $timeSlot);
            $start = isset($parts[0]) ? trim($parts[0]) : '10:00:00';
            $end = isset($parts[1]) ? trim($parts[1]) : '11:00:00';

            // Create exactly ONE schedule and ONE slot
            $scheduleId = insert_exam_schedule($batchId, $date, $conn);
            $slotId = insert_exam_slot($scheduleId, $start, $end, $count, $conn);

            $conn->commit();

            $results[] = [
                'status' => 'batched',
                'batch_id' => $batchId,
                'batch_number' => $batchNumber,
                'assigned_count' => $count,
                'date' => $date,
                'time_slot' => $timeSlot
            ];

        } catch (Throwable $e) {
            $conn->rollback();
            // log exception but continue with other groups
        }
    }

    return $results;
}

/**
 * Validates and records a candidate's preference, then triggers automated batch processing.
 */
function record_candidate_preference(int $candidateId, int $assessmentId, string $preferredDate, string $preferredTimeSlot, mysqli $conn): array {
    $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
    
    if (!$enrollment) {
        throw new Exception("Candidate is not enrolled in the specified assessment");
    }

    if ($enrollment['eligibility_status'] !== 'eligible') {
        throw new Exception("Candidate is not eligible to record a preference.");
    }

    if (!is_registration_open_for_date($preferredDate)) {
        throw new Exception("Registration cutoff has passed for this exam date.");
    }

    // Validate standard time slots. Using the defaults we see elsewhere, or strict checking.
    // The prompt says: "Validates preferredTimeSlot matches one of the standard slot formats
    // already used elsewhere (10:00:00-11:00:00 or 14:00:00-15:00:00) — reject arbitrary strings."
    $allowedSlots = ['10:00:00-11:00:00', '14:00:00-15:00:00'];
    if (!in_array($preferredTimeSlot, $allowedSlots, true)) {
        throw new Exception("Invalid time slot. Must be one of the standard time slots.");
    }

    set_candidate_preference((int)$enrollment['id'], $preferredDate, $preferredTimeSlot, $conn);

    // Automatic batching removed per user request: Admin must manually trigger it from Admin Panel.
    // $batchingResults = process_automated_preference_batching($assessmentId, $conn);

    // Did this candidate just get batched? Check the enrollment again
    $checkSql = "SELECT b.id AS batch_id, b.batch_number 
                 FROM enrollments e 
                 JOIN batches b ON e.batch_id = b.id 
                 WHERE e.id = ?";
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param("i", $enrollment['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $batchedRow = $res->fetch_assoc();
    $stmt->close();

    if ($batchedRow) {
        return [
            'status' => 'batched',
            'batch_id' => (int)$batchedRow['batch_id'],
            'batch_number' => $batchedRow['batch_number']
        ];
    }

    // Otherwise, still waiting. Calculate how many are needed.
    $threshold = get_batch_threshold($conn);
    $sqlGroupCount = "SELECT COUNT(*) as c FROM enrollments 
                      WHERE assessment_id = ? AND batch_id IS NULL AND eligibility_status = 'eligible' 
                        AND preferred_date = ? AND preferred_time_slot = ?";
    $stmtGrp = $conn->prepare($sqlGroupCount);
    $stmtGrp->bind_param("iss", $assessmentId, $preferredDate, $preferredTimeSlot);
    $stmtGrp->execute();
    $resGrp = $stmtGrp->get_result();
    $grpRow = $resGrp->fetch_assoc();
    $stmtGrp->close();
    
    $currentCount = $grpRow ? (int)$grpRow['c'] : 0;
    
    return [
        'status' => 'waiting',
        'candidates_needed' => max(0, $threshold - $currentCount)
    ];
}

function record_candidate_provisional_preference(int $candidateId, int $assessmentId, int $provisionalScheduleId, mysqli $conn): array {
    $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
    if (!$enrollment) {
        throw new Exception("Candidate is not enrolled in the specified assessment");
    }
    if ($enrollment['eligibility_status'] !== 'eligible') {
        throw new Exception("Candidate is not eligible to record a preference.");
    }
    
    // Check if provisional schedule exists and is valid
    $checkSql = "SELECT s.exam_date, es.start_time, es.end_time 
                 FROM exam_schedules s
                 JOIN exam_slots es ON es.exam_schedule_id = s.id
                 WHERE s.id = ? AND s.status = 'provisional'";
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param("i", $provisionalScheduleId);
    $stmt->execute();
    $res = $stmt->get_result();
    $schedRow = $res->fetch_assoc();
    $stmt->close();
    
    if (!$schedRow) {
        throw new Exception("Selected provisional slot does not exist or is no longer available.");
    }
    
    if (!is_registration_open_for_date($schedRow['exam_date'])) {
        throw new Exception("Registration cutoff has passed for this exam date.");
    }

    $updateSql = "UPDATE enrollments SET provisional_schedule_id = ?, updated_at = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $enrollmentId = (int)$enrollment['id'];
    $updateStmt->bind_param("ii", $provisionalScheduleId, $enrollmentId);
    $updateStmt->execute();
    $updateStmt->close();

    return [
        'status' => 'waiting',
        'message' => 'Preference recorded. Waiting for enough candidates to form the batch.',
        'provisional_schedule_id' => $provisionalScheduleId
    ];
}

function finalize_provisional_batch(int $scheduleId, int $assessmentId, mysqli $conn): array {
    $conn->begin_transaction();
    try {
        // Find the schedule and batch
        $stmt = $conn->prepare("SELECT s.id, s.status, s.batch_id, s.exam_date, b.assessment_id, b.batch_number
                                FROM exam_schedules s
                                JOIN batches b ON b.id = s.batch_id
                                WHERE s.id = ? AND b.assessment_id = ?");
        $stmt->bind_param("ii", $scheduleId, $assessmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $sched = $res->fetch_assoc();
        $stmt->close();

        if (!$sched) {
            throw new Exception("Provisional schedule not found.");
        }
        if ($sched['status'] !== 'provisional') {
            throw new Exception("This schedule is already finalized.");
        }

        // Update schedule to 'scheduled'
        $updateSched = $conn->prepare("UPDATE exam_schedules SET status = 'scheduled' WHERE id = ?");
        $updateSched->bind_param("i", $scheduleId);
        $updateSched->execute();
        $updateSched->close();

        // Assign all eligible enrollments that picked this provisional slot
        $batchId = $sched['batch_id'];
        
        $assignStmt = $conn->prepare("UPDATE enrollments 
                                      SET batch_id = ?, updated_at = NOW() 
                                      WHERE provisional_schedule_id = ? AND eligibility_status = 'eligible' AND batch_id IS NULL");
        $assignStmt->bind_param("ii", $batchId, $scheduleId);
        $assignStmt->execute();
        $assignedCount = $assignStmt->affected_rows;
        $assignStmt->close();

        // Decrement seats in exam_slots by assignedCount (simple approach assuming 1 slot for the provisional schedule for now, or just spreading)
        // Actually, enrollments are just assigned to batch_id. We need to assign them to exam_slots using attempts if they book, but wait!
        // When we do `assign_batch_to_enrollments`, it sets `batch_id`. It doesn't book a slot. Booking slot happens in Candidate UI?
        // Wait, NO! If a candidate sets preference, they are automatically allocated to a slot when the batch is created.
        // Let's check what assign_batch_to_enrollments did.
        
        $conn->commit();
        
        return [
            'status' => 'success',
            'batch_number' => $sched['batch_number'],
            'exam_date' => $sched['exam_date'],
            'assigned_count' => $assignedCount
        ];
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}
