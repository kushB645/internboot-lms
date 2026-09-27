<?php
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_admin_access($conn);
require_csrf();

$attemptId = (int)($_POST['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    send_json_response('error', 'Valid attempt_id required', null, 400);
}

try {
    require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/service.php';
    $result = evaluate_attempt($conn, $attemptId, true, true);
    send_json_response('success', 'Attempt re-graded successfully', $result, 200);
} catch (Exception $e) {
    send_json_response('error', $e->getMessage(), null, 400);
}
