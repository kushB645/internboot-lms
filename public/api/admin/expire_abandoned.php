<?php
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_admin_access($conn);
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'POST required', null, 405);
}

try {
    require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/service.php';
    
    $sql = "SELECT id, candidate_id FROM attempts WHERE status = 'in_progress' AND end_time <= NOW()";
    $result = $conn->query($sql);
    
    $expiredCount = 0;
    while ($row = $result->fetch_assoc()) {
        $attemptId = (int)$row['id'];
        
        $stmt = $conn->prepare("UPDATE attempts SET status = 'expired', submitted_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $attemptId);
        $stmt->execute();
        $stmt->close();
        
        try {
            evaluate_attempt($conn, $attemptId, true);
            $expiredCount++;
        } catch (Exception $e) {
            // ignore
        }
    }
    
    send_json_response('success', "Expired and evaluated {$expiredCount} abandoned attempts.", ['expired_count' => $expiredCount], 200);
} catch (Exception $e) {
    send_json_response('error', $e->getMessage(), null, 400);
}
