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
    $stmt = $conn->prepare('SELECT violations, status FROM attempts WHERE id = ? AND candidate_id = ?');
    $stmt->bind_param('ii', $attemptId, $candidateId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        // attempt_id does not exist or belongs to a different candidate
        send_json_response('error', 'Attempt not found or access denied', null, 404);
    }

    if ($row['status'] !== 'in_progress') {
        // UPDATE above was a no-op (attempt already submitted/expired).
        // Return current stored count with 409 so the frontend knows the
        // increment did not apply — avoids silently returning a stale count
        // that could be mistaken for "no violations yet".
        send_json_response('error', 'Attempt is no longer in progress', ['violations' => (int)$row['violations']], 409);
    }

    $v = (int)$row['violations'];

    send_json_response('success', 'Logged', ['violations' => $v], 200);
} catch (Exception $e) {
    send_json_response('error', $e->getMessage(), null, 400);
}
