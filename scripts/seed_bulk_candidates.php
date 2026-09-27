<?php
// Path: scripts/seed_bulk_candidates.php

require_once __DIR__ . '/../src/core/bootstrap.php';

if (!function_exists('is_dev_env') || !is_dev_env()) {
    echo "[ERROR] This script only runs when APP_ENV is a dev/local environment. Refusing to run against what looks like production.\n";
    exit(1);
}

function seed_die(string $msg): void {
    echo "[ERROR] {$msg}\n";
    exit(1);
}

echo "=================================================\n";
echo "InternBoot Bulk Candidates Seed — Starting\n";
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

// Setup batch/slot chain for groups A & B
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
    echo "[OK]   Reusing existing batch_id={$batchId} and slot_id={$slotId}.\n";
} else {
    $batchNumber = 'TEST-BATCH-BULK';
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
        $capacity = 500;
        $stmt = $conn->prepare("INSERT INTO exam_slots (exam_schedule_id, start_time, end_time, capacity, seats_remaining) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issii', $scheduleId, $startTime, $endTime, $capacity, $capacity);
        $stmt->execute();
        $slotId = $stmt->insert_id;
        $stmt->close();

        $conn->commit();
        echo "[OK]   Created new batch_id={$batchId} and slot_id={$slotId} for testing.\n";
    } catch (Exception $e) {
        $conn->rollback();
        seed_die("Failed to create batch/slot: " . $e->getMessage());
    }
}

$dummyPassword = "TestPass123!";
$passwordHash = password_hash($dummyPassword, PASSWORD_BCRYPT);

$groupStats = [
    'A' => ['created' => 0, 'skipped' => 0, 'errors' => 0],
    'B' => ['created' => 0, 'skipped' => 0, 'errors' => 0],
    'C' => ['created' => 0, 'skipped' => 0, 'errors' => 0],
    'D' => ['created' => 0, 'skipped' => 0, 'errors' => 0],
];

$totalProcessed = 0;
$phoneCounter = 1;

function generate_phone(int $counter): string {
    return "9" . substr((string)time(), -5) . str_pad((string)$counter, 4, "0", STR_PAD_LEFT);
}

function process_candidate(string $group, string $email, string $fullName, bool $doPayment, bool $doTest) {
    global $conn, $assessmentId, $batchId, $slotId, $fee, $passwordHash, $groupStats, $totalProcessed, $phoneCounter;
    
    $totalProcessed++;
    $phone = generate_phone($phoneCounter++);
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $existingUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existingUser) {
        $groupStats[$group]['skipped']++;
        return;
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
        
        if ($doPayment) {
            $payStatus = 'success';
            $payDate = date('Y-m-d H:i:s', strtotime('-3 days'));
            $referenceNum = "BULKPAY-{$group}-{$candidateId}-" . time();
            $stmt = $conn->prepare("INSERT INTO payments (candidate_id, assessment_id, amount, status, reference_number, payment_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iidsss', $candidateId, $assessmentId, $fee, $payStatus, $referenceNum, $payDate);
            $stmt->execute();
            $stmt->close();
            
            if ($doTest) {
                // Assign batch_id for group A and B
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
                
                $percentage = rand(4000, 9500) / 100;
                $stmt = $conn->prepare("SELECT level_number FROM levels WHERE ? BETWEEN min_percentage AND max_percentage LIMIT 1");
                $stmt->bind_param('d', $percentage);
                $stmt->execute();
                $levelRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $level = $levelRow ? (int)$levelRow['level_number'] : 1;
                
                $stmt = $conn->prepare("INSERT INTO results (attempt_id, total_score, percentage, level_assigned) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('iddi', $attemptId, $percentage, $percentage, $level);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        $conn->commit();
        $groupStats[$group]['created']++;
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "[ERROR] Failed for {$email}: " . $e->getMessage() . "\n";
        $groupStats[$group]['errors']++;
    }
    
    if ($totalProcessed % 50 === 0) {
        echo "[INFO]  Processed {$totalProcessed} candidates so far...\n";
    }
}

// Group A: 130 (registered + fee paid + test conducted)
for ($i = 1; $i <= 130; $i++) {
    process_candidate('A', "candA{$i}@example.com", "Cand A {$i}", true, true);
}

// Group B: 1 (livematch2501@gmail.com)
process_candidate('B', "livematch2501@gmail.com", "Live Match", true, true);

// Group C: 200 (registered + fee paid + test NOT conducted)
for ($i = 1; $i <= 200; $i++) {
    process_candidate('C', "candC{$i}@example.com", "Cand C {$i}", true, false);
}

// Group D: 169 (registration only)
for ($i = 1; $i <= 169; $i++) {
    process_candidate('D', "candD{$i}@example.com", "Cand D {$i}", false, false);
}

echo "\n=================================================\n";
echo "Bulk Seeding Complete.\n";
echo "Summary:\n";
foreach ($groupStats as $g => $s) {
    echo "  Group {$g}: Created={$s['created']}, Skipped={$s['skipped']}, Errors={$s['errors']}\n";
}
echo "=================================================\n";
