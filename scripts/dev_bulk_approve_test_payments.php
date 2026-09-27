<?php
/**
 * scripts/dev_bulk_approve_test_payments.php
 * DEV ONLY TOOLING - DO NOT DEPLOY TO PRODUCTION.
 * Bulk approves payments for test candidates and triggers batch formation.
 */

// We assume this is run from the project root.
require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/modules/m5_batch_slots/service.php';

// 1. SAFETY GUARDS
$appEnv = function_exists('get_app_env') ? get_app_env() : (env_value('APP_ENV') ?: 'production');
$dbHost = env_value('DB_HOST', '127.0.0.1');
$dbName = env_value('DB_NAME', '');

echo "Environment: " . $appEnv . "\n";
echo "DB Host: " . $dbHost . "\n";
echo "DB Name: " . $dbName . "\n";

$res = $conn->query("SELECT DATABASE() AS db");
if ($res) {
    echo "Current Database: " . $res->fetch_assoc()['db'] . "\n";
}

if (!in_array($appEnv, ['development', 'testing'])) {
    die("FATAL: Refusing to run in non-dev environment: $appEnv\n");
}

if (stripos($dbHost, '.proxy.rlwy.net') !== false || stripos($dbHost, 'railway.internal') !== false || ($dbHost !== 'localhost' && $dbHost !== '127.0.0.1')) {
    die("FATAL: Refusing to run against suspected remote/production DB host: $dbHost\n");
}

$seedAdminId = env_value('SEED_ADMIN_USER_ID') ?: env_value('SEED_ADMIN') ?: 1; // Fallback or check env

// 2. PRE-CHECK COUNT
$sqlCount = "
    SELECT COUNT(*) AS total
    FROM payments p
    JOIN candidates c ON c.id = p.candidate_id
    JOIN users u ON u.id = c.user_id
    WHERE u.email LIKE 'candidate0%@test.com'
      AND p.status = 'pending'
";
$resCount = $conn->query($sqlCount);
$totalPending = $resCount->fetch_assoc()['total'];
echo "Found $totalPending pending payments for candidate0%@test.com.\n";

if ($totalPending == 0) {
    echo "No pending payments to process.\n";
    exit(0);
}

// 3. FETCH TARGET ROWS
$sqlFetch = "
    SELECT p.id AS payment_id, p.candidate_id, p.assessment_id, p.status
    FROM payments p
    JOIN candidates c ON c.id = p.candidate_id
    JOIN users u ON u.id = c.user_id
    WHERE u.email LIKE 'candidate0%@test.com'
      AND p.status = 'pending'
";
$resRows = $conn->query($sqlFetch);
$rows = [];
while ($row = $resRows->fetch_assoc()) {
    $rows[] = $row;
}

// 4. TRANSACTION
$conn->begin_transaction();
$successCount = 0;
$failCount = 0;

$stmtUpdatePayment = $conn->prepare("UPDATE payments SET status = 'success', payment_date = NOW() WHERE id = ? AND status = 'pending'");
$stmtInsertEnrollment = $conn->prepare("
    INSERT INTO enrollments (candidate_id, assessment_id, payment_id, eligibility_status)
    VALUES (?, ?, ?, 'eligible')
    ON DUPLICATE KEY UPDATE payment_id = VALUES(payment_id), eligibility_status = 'eligible'
");
$stmtInsertLog = $conn->prepare("
    INSERT INTO admin_logs (user_id, action, details, ip_address)
    VALUES (?, 'manual_test_payment_approval', ?, 'CLI/script')
");

$lastAssessmentId = null;

try {
    foreach ($rows as $row) {
        $paymentId = $row['payment_id'];
        $candidateId = $row['candidate_id'];
        $assessmentId = $row['assessment_id'];
        $lastAssessmentId = $assessmentId;

        $stmtUpdatePayment->bind_param('i', $paymentId);
        $stmtUpdatePayment->execute();
        
        if ($stmtUpdatePayment->affected_rows === 1) {
            $stmtInsertEnrollment->bind_param('iii', $candidateId, $assessmentId, $paymentId);
            $stmtInsertEnrollment->execute();
            
            $details = json_encode([
                'payment_id' => $paymentId,
                'candidate_id' => $candidateId,
                'note' => 'bulk dev-only approval for seeded test batch'
            ]);
            $stmtInsertLog->bind_param('is', $seedAdminId, $details);
            $stmtInsertLog->execute();
            
            $successCount++;
        } else {
            $failCount++;
        }
    }
    $conn->commit();
    echo "Processed: $successCount successful, $failCount failed.\n";
} catch (Exception $e) {
    $conn->rollback();
    die("Transaction failed: " . $e->getMessage() . "\n");
}

// 5. BATCH FORMATION
if ($successCount > 0 && $lastAssessmentId) {
    echo "Calling create_all_eligible_batches for Assessment ID $lastAssessmentId...\n";
    $batchesFormed = create_all_eligible_batches($lastAssessmentId, $conn);
    echo "Batches formed: " . print_r($batchesFormed, true) . "\n";
}

// 6. VERIFICATION QUERIES
echo "\n--- VERIFICATION ---\n";

$q1 = "
    SELECT COUNT(*) AS c 
    FROM payments p 
    JOIN candidates c ON c.id=p.candidate_id
    JOIN users u ON u.id=c.user_id
    WHERE u.email LIKE 'candidate0%@test.com' AND p.status='success'
";
echo "Successful payments count: " . $conn->query($q1)->fetch_assoc()['c'] . "\n";

$q2 = "
    SELECT COUNT(*) AS c 
    FROM enrollments e 
    JOIN candidates c ON c.id=e.candidate_id
    JOIN users u ON u.id=c.user_id
    WHERE u.email LIKE 'candidate0%@test.com' AND e.eligibility_status='eligible'
";
echo "Eligible enrollments count: " . $conn->query($q2)->fetch_assoc()['c'] . "\n";

$q3 = "
    SELECT b.id, b.batch_number, COUNT(*) AS candidate_count
    FROM batches b
    JOIN enrollments e ON e.batch_id = b.id
    GROUP BY b.id
";
$resB = $conn->query($q3);
if ($resB) {
    while ($r = $resB->fetch_assoc()) {
        echo "Batch " . $r['batch_number'] . " (ID: " . $r['id'] . ") has " . $r['candidate_count'] . " candidates.\n";
    }
}
