<?php
require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/modules/m7_evaluation_admin/service.php';

$sql = "SELECT id, candidate_id FROM attempts WHERE status = 'in_progress' AND end_time <= NOW()";
$result = $conn->query($sql);

$expiredCount = 0;
while ($row = $result->fetch_assoc()) {
    $attemptId = (int)$row['id'];
    $candidateId = (int)$row['candidate_id'];
    
    // Auto-expire
    $stmt = $conn->prepare("UPDATE attempts SET status = 'expired', submitted_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $attemptId);
    $stmt->execute();
    $stmt->close();

    // Evaluate
    try {
        evaluate_attempt($conn, $attemptId, true);
        $expiredCount++;
    } catch (Exception $e) {
        echo "Error evaluating attempt {$attemptId}: " . $e->getMessage() . "\n";
    }
}
echo "Expired and evaluated {$expiredCount} abandoned attempts.\n";
