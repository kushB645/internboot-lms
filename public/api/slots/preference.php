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

    $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
    if (!$enrollment) {
        send_json_response('error', 'Candidate is not enrolled in the specified assessment', null, 403);
    }

    // Return available provisional slots with preference counts
    $stmt = $conn->prepare("SELECT 
        s.id as provisional_schedule_id, 
        s.exam_date as date, 
        s.is_closed,
        CONCAT(es.start_time, '-', es.end_time) as time_slot,
        COUNT(e.id) as preference_count
    FROM exam_schedules s
    JOIN batches b ON b.id = s.batch_id
    JOIN exam_slots es ON es.exam_schedule_id = s.id
    LEFT JOIN enrollments e ON e.provisional_schedule_id = s.id
        AND e.eligibility_status = 'eligible'
        AND e.batch_id IS NULL
    WHERE s.status = 'provisional' 
      AND b.assessment_id = ? 
      AND s.exam_date >= CURDATE()
      AND s.is_closed = 0
    GROUP BY s.id, es.id
    ORDER BY s.exam_date ASC, es.start_time ASC");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $options = [];
    while ($row = $res->fetch_assoc()) {
        $options[] = [
            'provisional_schedule_id' => (int)$row['provisional_schedule_id'],
            'date'                    => $row['date'],
            'time_slot'               => $row['time_slot'],
            'preference_count'        => (int)$row['preference_count'],
        ];
    }
    $stmt->close();

    $response = [
        'current_preference' => [
            'preferred_date' => $enrollment['preferred_date'] ?? null,
            'preferred_time_slot' => $enrollment['preferred_time_slot'] ?? null,
        ],
        'options' => $options
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
    $preferredDate = isset($input['preferred_date']) ? trim($input['preferred_date']) : '';
    $preferredTimeSlot = isset($input['preferred_time_slot']) ? trim($input['preferred_time_slot']) : '';

    if ($assessmentId <= 0 || empty($preferredDate) || empty($preferredTimeSlot)) {
        send_json_response('error', 'assessment_id, preferred_date, and preferred_time_slot are required', null, 400);
    }

    try {
        $result = record_candidate_provisional_preference($candidateId, $assessmentId, $provisionalScheduleId, $conn);
        
        // After saving preference, check if this slot has reached the threshold → notify admin
        $threshold = (int)(get_setting_value('batch_threshold', $conn) ?? 100);
        $countRow = $conn->query("SELECT COUNT(*) as cnt, s.batch_alert_sent
            FROM enrollments e
            JOIN exam_schedules s ON s.id = e.provisional_schedule_id
            WHERE e.provisional_schedule_id = $provisionalScheduleId
              AND e.eligibility_status = 'eligible'
              AND e.batch_id IS NULL");
        $countData = $countRow ? $countRow->fetch_assoc() : null;
        $currentCount = $countData ? (int)$countData['cnt'] : 0;
        $alertAlreadySent = $countData ? (int)$countData['batch_alert_sent'] : 0;

        if ($currentCount >= $threshold && !$alertAlreadySent) {
            // Get slot info for the notification
            $slotInfo = $conn->query("SELECT s.exam_date, es.start_time, es.end_time, b.batch_number
                FROM exam_schedules s
                JOIN exam_slots es ON es.exam_schedule_id = s.id
                JOIN batches b ON b.id = s.batch_id
                WHERE s.id = $provisionalScheduleId LIMIT 1")->fetch_assoc();

            if ($slotInfo) {
                $msg = "Slot {$slotInfo['exam_date']} {$slotInfo['start_time']}-{$slotInfo['end_time']} has reached {$currentCount} candidates (threshold: {$threshold}). Please create a batch now.";
                $conn->query("INSERT INTO admin_notifications (type, title, message, related_id) 
                    VALUES ('batch_ready', 'Batch Ready to Create!', " . $conn->real_escape_string($msg) . ", $provisionalScheduleId)");
                $conn->query("UPDATE exam_schedules SET batch_alert_sent=1 WHERE id=$provisionalScheduleId");
            }
        }

        // Return the live count so frontend can update
        $result['preference_count'] = $currentCount;
        $result['threshold']        = $threshold;
        
        send_json_response('success', 'Preference recorded successfully', $result, 200);
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
