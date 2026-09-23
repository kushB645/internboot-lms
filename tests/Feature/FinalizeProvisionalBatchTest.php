<?php
// VERIFICATION_TOKEN: VERIFY-25BCE14D1F630DEA

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Exception;

// Load dependencies
require_once __DIR__ . '/../../src/core/bootstrap.php';
require_once __DIR__ . '/../../src/modules/m5_batch_slots/service.php';
require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/queries.php';
require_once __DIR__ . '/../../src/modules/m5_batch_slots/queries.php';

class FinalizeProvisionalBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $conn;
        $this->cleanDatabase($conn);
    }

    protected function tearDown(): void
    {
        global $conn;
        $this->cleanDatabase($conn);
        parent::tearDown();
    }

    private function cleanDatabase(\mysqli &$conn): void
    {
        if (!@$conn->ping()) {
            global $host, $user, $pass, $dbName, $port;
            $conn = new \mysqli($host, $user, $pass, $dbName, $port);
        }
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        $tables = ['answers', 'attempt_questions', 'attempts', 'options', 'questions', 'certificates', 'exam_slots', 'exam_schedules', 'enrollments', 'batches', 'assessments', 'candidates', 'payments', 'users', 'notifications'];
        foreach ($tables as $t) {
            $conn->query("TRUNCATE TABLE `$t`");
        }
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
    }

    private function setupProvisionalBatch(\mysqli $conn, int $capacity = 50, ?int $existingAssessmentId = null): array
    {
        if ($existingAssessmentId === null) {
            $conn->query("INSERT INTO assessments (title, description, duration_minutes, status) VALUES ('Test Assessment', 'Desc', 60, 'active')");
            $assessmentId = $conn->insert_id;
        } else {
            $assessmentId = $existingAssessmentId;
        }

        // Create a provisional batch
        $batchName = 'PROV-' . uniqid();
        $batch = create_provisional_batch($conn, $batchName, $assessmentId, date('Y-m-d', strtotime('+3 days')), $capacity);
        
        return [
            'assessment_id' => $assessmentId,
            'schedule_id' => $batch['schedule_id'],
            'batch_id' => $batch['batch_id'],
            'exam_date' => $batch['exam_date'],
            'start_time' => $batch['slots'][0]['start_time']
        ];
    }

    private function createCandidateAndEnroll(\mysqli $conn, int $assessmentId): int
    {
        $conn->query("INSERT INTO users (email, password, role) VALUES ('test".uniqid()."@example.com', 'pass', 'candidate')");
        $userId = $conn->insert_id;
        
        static $phoneCounter = 1000;
        $phoneCounter++;
        $conn->query("INSERT INTO candidates (user_id, full_name, phone) VALUES ($userId, 'Test User', '12345678" . $phoneCounter . "')");
        $candidateId = $conn->insert_id;

        $conn->query("INSERT INTO enrollments (candidate_id, assessment_id, eligibility_status) VALUES ($candidateId, $assessmentId, 'eligible')");
        return $candidateId;
    }

    public function testFinalizeWithExcessCandidatesSetsCapacityCorrectly()
    {
        global $conn;
        
        // 1. Setup provisional batch with capacity 2
        $setup = $this->setupProvisionalBatch($conn, 2);
        
        // 2. Enroll 5 candidates and set their preference to this schedule
        $candidateIds = [];
        for ($i = 0; $i < 5; $i++) {
            $candidateId = $this->createCandidateAndEnroll($conn, $setup['assessment_id']);
            record_candidate_provisional_preference($candidateId, $setup['assessment_id'], $setup['schedule_id'], $conn);
            $candidateIds[] = $candidateId;
        }

        // 3. Finalize the batch
        $res = finalize_provisional_batch($setup['schedule_id'], $setup['assessment_id'], $conn);
        $this->assertEquals(5, $res['assigned_count']);

        // 4. Assert exam_slots capacity is 5
        $stmt = $conn->prepare("SELECT capacity, seats_remaining, id FROM exam_slots WHERE exam_schedule_id = ?");
        $stmt->bind_param("i", $setup['schedule_id']);
        $stmt->execute();
        $slot = $stmt->get_result()->fetch_assoc();
        
        $this->assertEquals(5, $slot['capacity']);
        $this->assertEquals(5, $slot['seats_remaining']);

        // 5. Assert all 5 can book without "fully booked" error
        $successCount = 0;
        foreach ($candidateIds as $cId) {
            $bookRes = book_exam_slot($cId, $setup['assessment_id'], $slot['id'], $conn);
            if (isset($bookRes['attempt_id'])) {
                $successCount++;
            }
        }
        $this->assertEquals(5, $successCount);
    }

    public function testLiveCountsEndpointReturnsAllProvisionalSlots()
    {
        global $conn;
        
        $setup1 = $this->setupProvisionalBatch($conn, 100);
        $setup2 = $this->setupProvisionalBatch($conn, 100, $setup1['assessment_id']);
        
        // Add 1 candidate to setup1
        $c1 = $this->createCandidateAndEnroll($conn, $setup1['assessment_id']);
        record_candidate_provisional_preference($c1, $setup1['assessment_id'], $setup1['schedule_id'], $conn);

        // Fetch counts for assessment 1
        $slots = fetch_provisional_slots_with_counts($setup1['assessment_id'], $conn);
        
        $this->assertCount(2, $slots);
        
        // Check slot 1 count
        $slot1 = array_filter($slots, fn($s) => $s['schedule_id'] == $setup1['schedule_id']);
        $this->assertEquals(1, array_values($slot1)[0]['current_count']);
        
        // Check slot 2 count
        $slot2 = array_filter($slots, fn($s) => $s['schedule_id'] == $setup2['schedule_id']);
        $this->assertEquals(0, array_values($slot2)[0]['current_count']);
    }

    public function testReassignmentCutoffBlocksAt1hr59min()
    {
        // Exam is at 10:00:00. Cutoff is 08:00:00.
        // If current time is 08:01:00 (1hr 59min before), it should be false (blocked).
        // If current time is 07:59:00 (2hr 01min before), it should be true (allowed).
        
        $examDate = '2025-01-01';
        $startTime = '10:00:00';
        
        $allowedTime = '2025-01-01 07:59:00';
        $this->assertTrue(is_within_reassignment_cutoff($examDate, $startTime, $allowedTime));
        
        $blockedTime = '2025-01-01 08:01:00';
        $this->assertFalse(is_within_reassignment_cutoff($examDate, $startTime, $blockedTime));
    }
}
