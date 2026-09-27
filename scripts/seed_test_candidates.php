<?php
// Path: scripts/seed_test_candidates.php

require_once __DIR__ . '/../src/core/bootstrap.php';

if (!function_exists('is_dev_env') || !is_dev_env()) {
    echo "[ERROR] This script only runs when APP_ENV is a dev/local environment. Refusing to run against what looks like production.\n";
    exit(1);
}

function seed_ok(string $msg): void  { echo "[OK]   {$msg}\n"; }
function seed_skip(string $msg): void { echo "[SKIP] {$msg}\n"; }
function seed_die(string $msg): void  { echo "[ERROR] {$msg}\n"; exit(1); }

function seed_env(string $key, string $default = ''): string
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v !== false && $v !== '') ? (string)$v : $default;
}

$count = (int)seed_env('SEED_TEST_CANDIDATE_COUNT', '5');
$prefix = seed_env('SEED_TEST_CANDIDATE_PREFIX', 'testcert');

echo "=================================================\n";
echo "InternBoot Test Candidate Seed — Starting\n";
echo "=================================================\n\n";

$stmt = $conn->prepare("SELECT id FROM assessments WHERE status='active' LIMIT 1");
$stmt->execute();
$existingAssessment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existingAssessment) {
    seed_die("No active assessment found. Run scripts/seed.php first.");
}
$assessmentId = (int)$existingAssessment['id'];

$stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key='exam_fee' LIMIT 1");
$stmt->execute();
$feeRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$fee = $feeRow ? (float)$feeRow['setting_value'] : 2999.00;

// Setup batch/slot chain once
$stmt = $conn->prepare("SELECT es.id AS slot_id, b.id AS batch_id 
    FROM exam_slots es 
    JOIN exam_schedules s ON s.id=es.exam_schedule_id 
    JOIN batches b ON b.id=s.batch_id 
    WHERE b.assessment_id=? LIMIT 1");
$stmt->bind_param('i', $assessmentId);
$stmt->execute();
$slotInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($slotInfo) {
    $batchId = (int)$slotInfo['batch_id'];
    $slotId = (int)$slotInfo['slot_id'];
    seed_ok("Reusing existing batch_id={$batchId} and slot_id={$slotId}.");
} else {
    $batchNumber = 'TEST-BATCH-1';
    $examDate = date('Y-m-d', strtotime('last saturday'));
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('INSERT INTO batches (batch_number, assessment_id) VALUES (?, ?)');
        $stmt->bind_param('si', $batchNumber, $assessmentId);
        $stmt->execute();
        $batchId = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO exam_schedules (batch_id, exam_date, status) VALUES (?, ?, 'completed')");
        $stmt->bind_param('is', $batchId, $examDate);
        $stmt->execute();
        $scheduleId = $stmt->insert_id;
        $stmt->close();

        $startTime = '10:00:00';
        $endTime = '11:00:00';
        $capacity = 100;
        $stmt = $conn->prepare("INSERT INTO exam_slots (exam_schedule_id, start_time, end_time, capacity, seats_remaining) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issii', $scheduleId, $startTime, $endTime, $capacity, $capacity);
        $stmt->execute();
        $slotId = $stmt->insert_id;
        $stmt->close();

        $conn->commit();
        seed_ok("Created new batch_id={$batchId} and slot_id={$slotId} for testing.");
    } catch (Exception $e) {
        $conn->rollback();
        seed_die("Failed to create batch/slot: " . $e->getMessage());
    }
}

$createdCount = 0;
$skippedCount = 0;
$createdEmails = [];
$dummyPassword = "TestPass123!";
$passwordHash = password_hash($dummyPassword, PASSWORD_BCRYPT);

for ($i = 1; $i <= $count; $i++) {
    $email = "{$prefix}{$i}@example.com";
    $fullName = "Test Candidate {$i}";
    $phone = "9" . substr((string)time(), -5) . str_pad((string)$i, 4, "0", STR_PAD_LEFT);
    $referenceNum = "TESTPAY-{$i}-" . time();
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $existingUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existingUser) {
        seed_skip("User {$email} already exists — skipping.");
        $skippedCount++;
        continue;
    }
    
    $conn->begin_transaction();
    try {
        $role = 'candidate';
        $isActive = 1;
        $stmt = $conn->prepare("INSERT INTO users (email, password, role, is_active) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sssi', $email, $passwordHash, $role, $isActive);
        $stmt->execute();
        $userId = $stmt->insert_id;
        $stmt->close();
        
        $stmt = $conn->prepare("INSERT INTO candidates (user_id, full_name, phone, profile_details) VALUES (?, ?, ?, NULL)");
        $stmt->bind_param('iss', $userId, $fullName, $phone);
        $stmt->execute();
        $candidateId = $stmt->insert_id;
        $stmt->close();
        
        $payStatus = 'success';
        $payDate = date('Y-m-d H:i:s', strtotime('-3 days'));
        $stmt = $conn->prepare("INSERT INTO payments (candidate_id, assessment_id, amount, status, reference_number, payment_date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iidsss', $candidateId, $assessmentId, $fee, $payStatus, $referenceNum, $payDate);
        $stmt->execute();
        $paymentId = $stmt->insert_id;
        $stmt->close();
        
        // The trigger on payments table automatically creates the enrollment row.
        // We just need to update it with the batch_id.
        $stmt = $conn->prepare("UPDATE enrollments SET batch_id = ? WHERE candidate_id = ? AND assessment_id = ?");
        $stmt->bind_param('iii', $batchId, $candidateId, $assessmentId);
        $stmt->execute();
        $stmt->close();
        
        $attStatus = 'submitted';
        $startTime = date('Y-m-d H:i:s', strtotime('-90 minutes'));
        $endTime = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        $violations = 0;
        $stmt = $conn->prepare("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, start_time, end_time, submitted_at, violations, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiissssis', $candidateId, $assessmentId, $slotId, $attStatus, $startTime, $endTime, $endTime, $violations, $startTime);
        $stmt->execute();
        $attemptId = $stmt->insert_id;
        $stmt->close();
        
        $percentage = rand(4000, 9500) / 100; // 40.00 to 95.00
        $stmt = $conn->prepare("SELECT level_number FROM levels WHERE ? BETWEEN min_percentage AND max_percentage LIMIT 1");
        $stmt->bind_param('d', $percentage);
        $stmt->execute();
        $levelRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $level = $levelRow ? (int)$levelRow['level_number'] : 1;
        
        $totalScore = $percentage; // Just placeholder for total score
        $stmt = $conn->prepare("INSERT INTO results (attempt_id, total_score, percentage, level_assigned) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iddi', $attemptId, $totalScore, $percentage, $level);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        seed_ok("Created test candidate: {$email}");
        $createdCount++;
        $createdEmails[] = $email;
        
    } catch (Exception $e) {
        $conn->rollback();
        seed_die("Failed to create candidate {$email}: " . $e->getMessage());
    }
}

echo "\n=================================================\n";
echo "Seed Test Candidates Complete.\n";
echo "  Created: {$createdCount}\n";
echo "  Skipped: {$skippedCount}\n";
echo "  Dummy Password: {$dummyPassword}\n";
if ($createdCount > 0) {
    echo "  Emails:\n";
    foreach ($createdEmails as $em) {
        echo "    - {$em}\n";
    }
}
echo "=================================================\n";
