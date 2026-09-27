<?php
require_once __DIR__ . '/../src/core/bootstrap.php';

$sql = "DELETE FROM email_verifications WHERE expires_at <= NOW()";
if ($conn->query($sql) === TRUE) {
    $expiredCount = $conn->affected_rows;
    echo "Successfully purged {$expiredCount} expired OTPs.\n";
} else {
    echo "Error purging expired OTPs: " . $conn->error . "\n";
}
