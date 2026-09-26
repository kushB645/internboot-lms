<?php
require_once __DIR__ . '/../../../src/core/bootstrap.php';
try {
    $candidateId = require_candidate_auth($conn);
    require_csrf();
    $input = json_decode(file_get_contents('php://input'), true);
    $attemptId = (int)($input['attempt_id'] ?? 0);
    if ($attemptId <= 0) send_json_response('error', 'Invalid attempt', null, 400);

    $stmt = $conn->prepare("UPDATE attempts SET violations = violations + 1 WHERE id = ? AND candidate_id = ? AND status = 'in_progress'");
    $stmt->bind_param('ii', $attemptId, $candidateId);
    $stmt->execute();
    $stmt->close();
    
    // Fetch new count — scoped to this candidate to prevent IDOR read-back.
    // Closing Finding: "Violation-count read-back is not candidate-scoped" (InternBoot MVP audit).
    $stmt = $conn->prepare('SELECT violations, status FROM attempts WHERE id = ? AND candidate_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $attemptId, $candidateId);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc()['violations'] ?? 0;
    $stmt->close();

    send_json_response('success', 'Logged', ['violations' => $v], 200);
} catch (Throwable $e) {
    send_json_response('error', $e->getMessage(), null, 400);
}
