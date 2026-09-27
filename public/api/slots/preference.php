<?php
// Path: public/api/slots/preference.php

require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/service.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/controller.php';
require_once __DIR__ . '/../../../src/core/candidate_resolver.php';

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

    $options = q_all($conn, "SELECT 
        s.id as provisional_schedule_id, 
        s.exam_date as date, 
        CONCAT(es.start_time, '-', es.end_time) as time_slot
    FROM exam_schedules s
    JOIN batches b ON b.id = s.batch_id
    JOIN exam_slots es ON es.exam_schedule_id = s.id
    WHERE s.status = 'provisional' 
      AND b.assessment_id = ? 
      AND s.exam_date >= CURDATE()
    ORDER BY s.exam_date ASC, es.start_time ASC", 'i', [$assessmentId]);

    $response = [
        'current_preference' => [
            'provisional_schedule_id' => $enrollment['provisional_schedule_id'] ?? null,
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
    $provisionalScheduleId = isset($input['provisional_schedule_id']) ? (int)$input['provisional_schedule_id'] : 0;

    if ($assessmentId <= 0 || $provisionalScheduleId <= 0) {
        send_json_response('error', 'assessment_id and provisional_schedule_id are required', null, 400);
    }

    try {
        $result = record_candidate_provisional_preference($candidateId, $assessmentId, $provisionalScheduleId, $conn);
        send_json_response('success', 'Preference recorded successfully', $result, 200);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Do NOT mask business logic exceptions like cutoff passed or invalid slots
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
