<?php
// Path: scripts/finalize_monday_batches.php
// This script is meant to be run via a cron job every Monday morning.

require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/modules/m5_batch_slots/service.php';
require_once __DIR__ . '/../src/core/mailer.php';

echo "Running Monday Batch Finalization...\n";

// Get the upcoming Saturday and Sunday dates
$today = new DateTime();
if ($today->format('N') != 1) { // 1 = Monday
    echo "Warning: Script is intended to be run on Monday. Proceeding anyway...\n";
}

$nextSaturday = (clone $today)->modify('next saturday')->format('Y-m-d');
$nextSunday = (clone $today)->modify('next sunday')->format('Y-m-d');

$targetDates = ["'$nextSaturday'", "'$nextSunday'"];
$datesIn = implode(',', $targetDates);

$conn->begin_transaction();

try {
    // 1. Fetch all provisional schedules for the coming weekend
    $sql = "SELECT s.id as schedule_id, b.assessment_id, b.batch_number, s.exam_date,
            (SELECT COUNT(*) FROM enrollments e WHERE e.provisional_schedule_id = s.id AND e.eligibility_status = 'eligible' AND e.batch_id IS NULL) as candidate_count
            FROM exam_schedules s
            JOIN batches b ON s.batch_id = b.id
            WHERE s.status = 'provisional' AND s.exam_date IN ($datesIn)";
            
    $result = $conn->query($sql);
    
    $threshold = get_batch_threshold($conn);
    
    while ($row = $result->fetch_assoc()) {
        $scheduleId = (int)$row['schedule_id'];
        $assessmentId = (int)$row['assessment_id'];
        $candidateCount = (int)$row['candidate_count'];
        
        if ($candidateCount >= $threshold) {
            // Threshold met: Finalize batch
            echo "Finalizing batch {$row['batch_number']} for date {$row['exam_date']} with {$candidateCount} candidates.\n";
            finalize_provisional_batch($scheduleId, $assessmentId, $conn);
            
            // Notify candidates that the batch is confirmed? 
            // In the real system, you'd send an email to all candidates enrolled in this batch here.
        } else {
            // Threshold NOT met: Cancel provisional batch and notify candidates to pick next slot
            echo "Cancelling batch {$row['batch_number']} for date {$row['exam_date']} - only {$candidateCount} candidates.\n";
            
            $cancelSql = "UPDATE exam_schedules SET status = 'cancelled' WHERE id = ?";
            $cancelStmt = $conn->prepare($cancelSql);
            $cancelStmt->bind_param("i", $scheduleId);
            $cancelStmt->execute();
            $cancelStmt->close();
            
            // Unset provisional_schedule_id so candidates can pick a new slot, or leave it so they know it was cancelled?
            // Let's unset it so they can pick again, and send them an email.
            $getUsersSql = "SELECT u.email, u.full_name, e.id as enrollment_id 
                            FROM enrollments e
                            JOIN users u ON e.candidate_id = u.id -- Wait, enrollments.candidate_id is candidates.id not users.id
                            WHERE e.provisional_schedule_id = ? AND e.eligibility_status = 'eligible' AND e.batch_id IS NULL";
            // Fix join
            $getUsersSql = "SELECT u.email, u.full_name, e.id as enrollment_id 
                            FROM enrollments e
                            JOIN candidates c ON e.candidate_id = c.id
                            JOIN users u ON c.user_id = u.id
                            WHERE e.provisional_schedule_id = ? AND e.eligibility_status = 'eligible' AND e.batch_id IS NULL";
            $uStmt = $conn->prepare($getUsersSql);
            $uStmt->bind_param("i", $scheduleId);
            $uStmt->execute();
            $uRes = $uStmt->get_result();
            
            $enrollmentIdsToReset = [];
            while ($uRow = $uRes->fetch_assoc()) {
                $enrollmentIdsToReset[] = $uRow['enrollment_id'];
                
                // Send email notification (dummy implementation for now)
                $subject = "Assessment Slot Cancelled - Please Choose a New Slot";
                $body = "Dear {$uRow['full_name']},\n\nUnfortunately, the minimum candidate threshold for your chosen slot on {$row['exam_date']} was not reached. Please log in and choose a new available slot for your assessment.\n\nThank you.";
                // Assuming send_email is available via mailer.php
                if (function_exists('send_email')) {
                    send_email($uRow['email'], $subject, $body);
                }
            }
            $uStmt->close();
            
            if (!empty($enrollmentIdsToReset)) {
                $placeholders = implode(',', array_fill(0, count($enrollmentIdsToReset), '?'));
                $resetSql = "UPDATE enrollments SET provisional_schedule_id = NULL WHERE id IN ($placeholders)";
                $resetStmt = $conn->prepare($resetSql);
                $types = str_repeat('i', count($enrollmentIdsToReset));
                $resetStmt->bind_param($types, ...$enrollmentIdsToReset);
                $resetStmt->execute();
                $resetStmt->close();
            }
        }
    }
    
    $conn->commit();
    echo "Monday Batch Finalization completed successfully.\n";
} catch (Exception $e) {
    $conn->rollback();
    echo "Error: " . $e->getMessage() . "\n";
}
