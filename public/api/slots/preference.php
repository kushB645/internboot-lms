<?php
// Path: public/api/slots/preference.php

require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/service.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/controller.php';
require_once __DIR__ . '/../../../src/core/candidate_resolver.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/queries.php';

// Authenticate Candidate
$candidateId = validate_candidate_session($conn);
if (!$candidateId) {
    send_json_response('error', 'Unauthorized: candidate authentication required', null, 401);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $assessmentId = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
    if ($assessmentId <= 0) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
    }

    // 1. Run dynamic check for slots starting within 30 minutes with < 100 candidates
    check_and_notify_underfilled_slots($conn, $assessmentId);

    $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
    if (!$enrollment) {
        send_json_response('error', 'Candidate is not enrolled in the specified assessment', null, 403);
    }

    // 2. Check if candidate has recent batch_not_formed notification
    $notifStmt = $conn->prepare("
        SELECT id, message, created_at, is_read 
        FROM notifications 
        WHERE candidate_id = ? AND type = 'batch_not_formed'
        ORDER BY id DESC LIMIT 1
    ");
    $notifStmt->bind_param("i", $candidateId);
    $notifStmt->execute();
    $batchNotFormedNotif = $notifStmt->get_result()->fetch_assoc();
    $notifStmt->close();

    $threshold = get_batch_threshold($conn);
    $cutoffString = date('Y-m-d H:i:s', time() + (30 * 60)); // 30 minutes from now

    // 3. Return available provisional slots (excluding cancelled, closed, and slots starting within 30 mins)
    $stmt = $conn->prepare("SELECT 
        s.id as provisional_schedule_id, 
        s.exam_date as date, 
        s.is_closed,
        CONCAT(es.start_time, '-', es.end_time) as time_slot,
        es.start_time,
        es.end_time,
        COUNT(e.id) as preference_count,
        COALESCE(st.batch_threshold, 100) AS threshold
    FROM exam_schedules s
    JOIN batches b ON b.id = s.batch_id
    JOIN exam_slots es ON es.exam_schedule_id = s.id
    LEFT JOIN enrollments e ON e.provisional_schedule_id = s.id
        AND e.eligibility_status = 'eligible'
        AND e.batch_id IS NULL
    LEFT JOIN (
        SELECT setting_value AS batch_threshold
        FROM settings WHERE setting_key = 'batch_threshold' LIMIT 1
    ) st ON 1=1
    WHERE s.status = 'provisional' 
      AND b.assessment_id = ? 
      AND s.is_closed = 0
      AND CONCAT(s.exam_date, ' ', es.start_time) > ?
    GROUP BY s.id, es.id, st.batch_threshold
    ORDER BY s.exam_date ASC, es.start_time ASC");
    $stmt->bind_param('is', $assessmentId, $cutoffString);
    $stmt->execute();
    $res = $stmt->get_result();
    $options = [];
    while ($row = $res->fetch_assoc()) {
        $cnt = (int)$row['preference_count'];
        $th = (int)$row['threshold'];
        $options[] = [
            'provisional_schedule_id' => (int)$row['provisional_schedule_id'],
            'date'                    => $row['date'],
            'time_slot'               => $row['time_slot'],
            'start_time'              => $row['start_time'],
            'end_time'                => $row['end_time'],
            'preference_count'        => $cnt,
            'threshold'               => $th,
            'percentage'              => min(100, round(($cnt / max(1, $th)) * 100)),
        ];
    }
    $stmt->close();

    // Check if candidate already has a confirmed batch
    $hasConfirmedBatch = !empty($enrollment['batch_id']);
    $confirmedBatchData = null;
    if ($hasConfirmedBatch) {
        $bsql = "SELECT b.batch_number, s.exam_date, es.start_time, es.end_time
                 FROM batches b
                 JOIN exam_schedules s ON s.batch_id = b.id
                 JOIN exam_slots es ON es.exam_schedule_id = s.id
                 WHERE b.id = ? LIMIT 1";
        $bs = $conn->prepare($bsql);
        $bs->bind_param('i', (int)$enrollment['batch_id']);
        $bs->execute();
        $confirmedBatchData = $bs->get_result()->fetch_assoc();
        $bs->close();
    }

    $response = [
        'current_preference' => [
            'preferred_date' => $enrollment['preferred_date'] ?? null,
            'preferred_time_slot' => $enrollment['preferred_time_slot'] ?? null,
            'provisional_schedule_id' => $enrollment['provisional_schedule_id'] ? (int)$enrollment['provisional_schedule_id'] : null
        ],
        'batch_not_formed_alert' => $batchNotFormedNotif ? [
            'message' => $batchNotFormedNotif['message'],
            'created_at' => $batchNotFormedNotif['created_at'],
            'is_read' => (bool)$batchNotFormedNotif['is_read']
        ] : null,
        'has_confirmed_batch' => $hasConfirmedBatch,
        'confirmed_batch' => $confirmedBatchData,
        'can_choose_slot' => (!$hasConfirmedBatch && $enrollment['eligibility_status'] === 'eligible'),
        'options' => $options,
        'threshold' => $threshold
    ];

    send_json_response('success', 'Preferences retrieved', $response, 200);

} elseif ($method === 'POST') {
    require_csrf();
    
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        send_json_response('error', 'Invalid JSON payload', null, 400);
    }

    $assessmentId = isset($input['assessment_id']) ? (int)$input['assessment_id'] : 0;
    $provisionalScheduleId = isset($input['provisional_schedule_id']) ? (int)$input['provisional_schedule_id'] : 0;
    $preferredDate = isset($input['preferred_date']) ? trim($input['preferred_date']) : '';
    $preferredTimeSlot = isset($input['preferred_time_slot']) ? trim($input['preferred_time_slot']) : '';

    if ($assessmentId <= 0) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
    }

    // 1. Run dynamic check for slots starting within 30 minutes with < 100 candidates
    check_and_notify_underfilled_slots($conn, $assessmentId);

    $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
    if (!$enrollment) {
        send_json_response('error', 'Candidate is not enrolled in the specified assessment', null, 403);
    }
    if ($enrollment['eligibility_status'] !== 'eligible') {
        send_json_response('error', 'Candidate is not eligible to choose a slot. Complete payment first.', null, 403);
    }
    if (!empty($enrollment['batch_id'])) {
        send_json_response('error', 'Your batch is already confirmed. You cannot re-select a slot.', null, 400);
    }

    // 2. Resolve provisionalScheduleId if not provided directly
    if ($provisionalScheduleId <= 0 && (!empty($preferredDate) || !empty($preferredTimeSlot))) {
        $startTime = '';
        if (!empty($preferredTimeSlot)) {
            $parts = explode('-', $preferredTimeSlot);
            $startTime = trim($parts[0]);
        }
        $findSql = "
            SELECT s.id, s.exam_date, es.start_time, es.end_time
            FROM exam_schedules s
            JOIN batches b ON b.id = s.batch_id
            JOIN exam_slots es ON es.exam_schedule_id = s.id
            WHERE s.status = 'provisional'
              AND s.is_closed = 0
              AND b.assessment_id = ?
        ";
        if (!empty($preferredDate)) {
            $findSql .= " AND s.exam_date = '" . $conn->real_escape_string($preferredDate) . "'";
        }
        if (!empty($startTime)) {
            $findSql .= " AND es.start_time LIKE '" . $conn->real_escape_string($startTime) . "%'";
        }
        $findSql .= " LIMIT 1";

        $findRes = $conn->query($findSql);
        if ($findRes && ($row = $findRes->fetch_assoc())) {
            $provisionalScheduleId = (int)$row['id'];
            $preferredDate = $row['exam_date'];
            $preferredTimeSlot = $row['start_time'] . '-' . $row['end_time'];
        }
    }

    if ($provisionalScheduleId <= 0) {
        send_json_response('error', 'A valid slot selection (provisional_schedule_id or date/time) is required', null, 400);
    }

    // 3. Verify schedule is open and NOT within 30 minutes of start time
    $chkStmt = $conn->prepare("
        SELECT s.id, s.status, s.is_closed, s.exam_date, es.start_time, es.end_time
        FROM exam_schedules s
        JOIN exam_slots es ON es.exam_schedule_id = s.id
        WHERE s.id = ? LIMIT 1
    ");
    $chkStmt->bind_param("i", $provisionalScheduleId);
    $chkStmt->execute();
    $schedInfo = $chkStmt->get_result()->fetch_assoc();
    $chkStmt->close();

    if (!$schedInfo || $schedInfo['status'] !== 'provisional' || (int)$schedInfo['is_closed'] === 1) {
        send_json_response('error', 'The selected slot is closed or no longer available. Please choose another slot.', null, 400);
    }

    $slotDateTimeStr = $schedInfo['exam_date'] . ' ' . $schedInfo['start_time'];
    if (strtotime($slotDateTimeStr) <= time() + (30 * 60)) {
        send_json_response('error', 'Slot booking closes 30 minutes before exam start time. Please choose a later slot.', null, 400);
    }

    try {
        $result = record_candidate_provisional_preference($candidateId, $assessmentId, $provisionalScheduleId, $conn);

        // Update preferred_date and preferred_time_slot on enrollments
        if (empty($preferredDate)) {
            $preferredDate = $schedInfo['exam_date'];
        }
        if (empty($preferredTimeSlot)) {
            $preferredTimeSlot = $schedInfo['start_time'] . '-' . $schedInfo['end_time'];
        }
        $enrollmentId = (int)$enrollment['id'];
        $upPref = $conn->prepare("UPDATE enrollments SET preferred_date = ?, preferred_time_slot = ? WHERE id = ?");
        $upPref->bind_param("ssi", $preferredDate, $preferredTimeSlot, $enrollmentId);
        $upPref->execute();
        $upPref->close();

        // Mark any prior batch_not_formed notification as read now that candidate chose a new slot
        $conn->query("UPDATE notifications SET is_read = 1 WHERE candidate_id = $candidateId AND type = 'batch_not_formed'");

        // Check if slot has reached threshold (100)
        $threshold = get_batch_threshold($conn);
        $countRow = $conn->query("SELECT COUNT(*) as cnt, s.batch_alert_sent
            FROM enrollments e
            JOIN exam_schedules s ON s.id = e.provisional_schedule_id
            WHERE e.provisional_schedule_id = $provisionalScheduleId
              AND e.eligibility_status = 'eligible'
              AND e.batch_id IS NULL");
        $countData = $countRow ? $countRow->fetch_assoc() : null;
        $currentCount = $countData ? (int)$countData['cnt'] : 0;
        $alertAlreadySent = $countData ? (int)$countData['batch_alert_sent'] : 0;

        if ($currentCount >= $threshold) {
            // Threshold reached! We can auto-finalize or alert admin
            if (!$alertAlreadySent) {
                $msg = "Slot {$schedInfo['exam_date']} {$schedInfo['start_time']}-{$schedInfo['end_time']} has reached {$currentCount} candidates (threshold: {$threshold}). Batch ready to create!";
                $conn->query("INSERT INTO admin_notifications (type, title, message, related_id) 
                    VALUES ('batch_ready', 'Batch Ready to Create!', " . $conn->real_escape_string($msg) . ", $provisionalScheduleId)");
                $conn->query("UPDATE exam_schedules SET batch_alert_sent=1 WHERE id=$provisionalScheduleId");
            }
        }

        $result['preference_count'] = $currentCount;
        $result['threshold']        = $threshold;
        $result['percentage']       = min(100, round(($currentCount / max(1, $threshold)) * 100));

        send_json_response('success', 'Slot preference recorded successfully. Your batch will form once 100 candidates choose this slot.', $result, 200);

    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'cutoff') !== false || stripos($msg, 'invalid') !== false || stripos($msg, 'eligible') !== false || stripos($msg, 'enrolled') !== false || stripos($msg, 'already assigned') !== false) {
            send_json_response('error', $msg, null, 400);
        } else {
            error_log("Preference recording error: " . $msg);
            send_json_response('error', is_dev_env() ? $msg : 'An error occurred processing the request.', null, 500);
        }
    }

} else {
    send_json_response('error', 'Method not allowed', null, 405);
}
