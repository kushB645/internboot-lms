<?php
// Path: scripts/verify_payment_sync.php
// Validates that payments triggers successfully sync enrollments.
require_once __DIR__ . '/../src/core/bootstrap.php';

echo "Verifying payment sync trigger...\n";
$conn->begin_transaction();

try {
    $email = 'test_sync_' . time() . '@example.com';
    $conn->query("INSERT INTO users(email, password, role) VALUES ('$email', 'dummy', 'candidate')");
    $userId = $conn->insert_id;

    $conn->query("INSERT INTO candidates(user_id, full_name, phone) VALUES ($userId, 'Sync Test', '000000000')");
    $candId = $conn->insert_id;
    
    $res = $conn->query("SELECT id FROM assessments LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        throw new Exception("No assessments found, run seed.php first.");
    }
    $assId = $res->fetch_assoc()['id'];

    $ref = 'REF_' . time();
    $conn->query("INSERT INTO payments(candidate_id, assessment_id, amount, status, reference_number) 
                  VALUES ($candId, $assId, 100, 'success', '$ref')");
    $paymentId = $conn->insert_id;

    $enrollmentRes = $conn->query("SELECT * FROM enrollments WHERE payment_id=$paymentId");
    if ($enrollmentRes && $enrollmentRes->num_rows > 0) {
        $row = $enrollmentRes->fetch_assoc();
        if ($row['eligibility_status'] === 'eligible') {
            echo "[OK] Trigger successfully created an eligible enrollment for direct INSERT.\n";
        } else {
            throw new Exception("Enrollment created but status was '{$row['eligibility_status']}', expected 'eligible'");
        }
    } else {
        throw new Exception("No enrollment created by INSERT trigger.");
    }

    $ref2 = $ref . '_2';
    $conn->query("INSERT INTO payments(candidate_id, assessment_id, amount, status, reference_number) 
                  VALUES ($candId, $assId, 100, 'pending', '$ref2')");
    $paymentId2 = $conn->insert_id;
    
    $conn->query("UPDATE payments SET status='success' WHERE id=$paymentId2");
    
    $enrollmentRes2 = $conn->query("SELECT * FROM enrollments WHERE payment_id=$paymentId2");
    if ($enrollmentRes2 && $enrollmentRes2->num_rows > 0) {
        $row2 = $enrollmentRes2->fetch_assoc();
        if ($row2['eligibility_status'] === 'eligible') {
            echo "[OK] Trigger successfully updated enrollment for direct UPDATE.\n";
        } else {
            throw new Exception("Enrollment created but status was '{$row2['eligibility_status']}', expected 'eligible'");
        }
    } else {
        throw new Exception("No enrollment created by UPDATE trigger.");
    }

    echo "All tests passed!\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $conn->rollback();
    echo "Cleanup complete via transaction rollback.\n";
}
