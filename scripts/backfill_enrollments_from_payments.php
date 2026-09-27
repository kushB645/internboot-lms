<?php
// Path: scripts/backfill_enrollments_from_payments.php
//
// Backfills enrollments for any successful payments that lack an eligible enrollment.
// This uses an INSERT ... ON DUPLICATE KEY UPDATE to remain idempotent.

require_once __DIR__ . '/../src/core/bootstrap.php';

echo "=================================================\n";
echo "Enrollments Backfill — Starting\n";
echo "=================================================\n\n";

$sql = "
    INSERT INTO enrollments (candidate_id, assessment_id, payment_id, eligibility_status)
    SELECT p.candidate_id, p.assessment_id, p.id, 'eligible'
    FROM payments p
    WHERE p.status = 'success'
      AND p.id = (
        SELECT MAX(p2.id) FROM payments p2
        WHERE p2.candidate_id = p.candidate_id
          AND p2.assessment_id = p.assessment_id
          AND p2.status = 'success'
      )
    ON DUPLICATE KEY UPDATE
      payment_id = VALUES(payment_id),
      eligibility_status = 'eligible'
";

if ($conn->query($sql) === TRUE) {
    $affected = $conn->affected_rows;
    // affected_rows includes 1 for insert, 2 for update on duplicate key
    echo "[OK]   Backfill query executed successfully. Affected rows (inserts + 2*updates): {$affected}\n";
} else {
    echo "[ERROR] Failed to execute backfill query: " . $conn->error . "\n";
    exit(1);
}

echo "\n=================================================\n";
echo "Backfill complete.\n";
echo "=================================================\n";
