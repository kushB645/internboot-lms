<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RuntimeException;

class EvaluateAttemptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $conn;
        // Clean database before each test
        $this->cleanDatabase($conn);
    }

    protected function tearDown(): void
    {
        global $conn;
        // Clean database after each test
        $this->cleanDatabase($conn);
        parent::tearDown();
    }

    private function cleanDatabase(\mysqli &$conn): void
    {
        if (!@$conn->ping()) {
            global $host, $user, $pass, $dbName, $port;
            $conn = new \mysqli($host, $user, $pass, $dbName, (int)$port);
        }
        @$conn->rollback();
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $tables = [
            'admin_logs', 'answers', 'attempt_questions', 'attempts', 'batches', 
            'candidates', 'certificate_verification_attempts', 'certificates', 
            'email_verifications', 'enrollments', 'exam_schedules', 'exam_slots', 
            'login_attempts', 'options', 'password_resets', 'payments', 
            'placement_records', 'question_banks', 'questions', 'registration_attempts', 
            'results', 'users', 'assessments', 'settings'
        ];
        foreach ($tables as $table) {
            $conn->query("TRUNCATE TABLE `{$table}`");
        }
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    private function seedUserAndCandidate(\mysqli $conn): int
    {
        $conn->query("INSERT INTO users (email, password, role) VALUES ('test@example.com', 'hash', 'candidate')");
        $userId = $conn->insert_id;
        $conn->query("INSERT INTO candidates (user_id, full_name, phone) VALUES ({$userId}, 'Test User', '1234567890')");
        return $conn->insert_id;
    }

    private function seedAssessmentAndDependencies(\mysqli $conn, int $totalQuestions = 10): array
    {
        $conn->query("INSERT INTO assessments (title, total_questions, duration_minutes, status) VALUES ('Test Assessment', {$totalQuestions}, 60, 'active')");
        $assessmentId = $conn->insert_id;

        $conn->query("INSERT INTO batches (batch_number, assessment_id) VALUES ('BATCH1', {$assessmentId})");
        $batchId = $conn->insert_id;

        $conn->query("INSERT INTO exam_schedules (batch_id, exam_date, status) VALUES ({$batchId}, CURDATE(), 'scheduled')");
        $scheduleId = $conn->insert_id;

        $conn->query("INSERT INTO exam_slots (exam_schedule_id, start_time, end_time, capacity, seats_remaining) VALUES ({$scheduleId}, '10:00:00', '11:00:00', 50, 50)");
        $slotId = $conn->insert_id;

        $conn->query("INSERT INTO question_banks (assessment_id, name, status) VALUES ({$assessmentId}, 'Test QBank', 'approved')");
        $qBankId = $conn->insert_id;

        return ['assessment_id' => $assessmentId, 'slot_id' => $slotId, 'qbank_id' => $qBankId];
    }

    private function seedQuestions(\mysqli $conn, int $qBankId, int $count): array
    {
        $questionIds = [];
        for ($i = 0; $i < $count; $i++) {
            $conn->query("INSERT INTO questions (question_bank_id, question_text, difficulty, approval_status) VALUES ({$qBankId}, 'Q{$i}', 'easy', 'approved')");
            $qId = $conn->insert_id;
            
            // Insert correct option
            $conn->query("INSERT INTO options (question_id, option_text, is_correct) VALUES ({$qId}, 'Correct', 1)");
            $correctOptionId = $conn->insert_id;
            
            // Insert incorrect option
            $conn->query("INSERT INTO options (question_id, option_text, is_correct) VALUES ({$qId}, 'Wrong', 0)");
            $wrongOptionId = $conn->insert_id;
            
            $questionIds[] = ['id' => $qId, 'correct_option_id' => $correctOptionId, 'wrong_option_id' => $wrongOptionId];
        }
        return $questionIds;
    }

    private function setNegativeMarking(\mysqli $conn, bool $enabled, float $value = 0.25): void
    {
        $enabledVal = $enabled ? '1' : '0';
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('negative_marking_enabled', '{$enabledVal}') ON DUPLICATE KEY UPDATE setting_value = '{$enabledVal}'");
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('negative_marking_value', '{$value}') ON DUPLICATE KEY UPDATE setting_value = '{$value}'");
    }

    public function test_correct_scoring_no_negative_marking(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $this->setNegativeMarking($conn, false);

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);
        $questions = $this->seedQuestions($conn, $deps['qbank_id'], 10);

        // Seed attempt
        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;

        // 7 correct, 3 incorrect
        for ($i = 0; $i < 10; $i++) {
            $q = $questions[$i];
            $opt = ($i < 7) ? $q['correct_option_id'] : $q['wrong_option_id'];
            $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$q['id']}, {$opt})");
        }

        $result = evaluate_attempt($conn, $attemptId);

        $this->assertEquals(7.0, $result['score']);
        $this->assertEquals(70.0, $result['percentage']);
        // 70.00 maps to Level 2 (Intermediate) based on schema: 70.00 to 84.99 -> Level 2
        $this->assertEquals(2, $result['level']);
    }

    public function test_negative_marking_applied_only_to_attempted_wrong_answers(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $this->setNegativeMarking($conn, true, 0.25);

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);
        $questions = $this->seedQuestions($conn, $deps['qbank_id'], 10);

        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;

        // 7 correct, 2 incorrect, 1 unanswered
        for ($i = 0; $i < 10; $i++) {
            $q = $questions[$i];
            if ($i < 7) {
                $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$q['id']}, {$q['correct_option_id']})");
            } elseif ($i < 9) {
                $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$q['id']}, {$q['wrong_option_id']})");
            } else {
                $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$q['id']}, NULL)");
            }
        }

        $result = evaluate_attempt($conn, $attemptId);

        $this->assertEquals(6.5, $result['score']); // 7 - (2 * 0.25)
        $this->assertEquals(65.0, $result['percentage']);
        $this->assertEquals(3, $result['level']); // 55.00 to 69.99 -> Level 3
    }

    public function test_score_never_goes_negative(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $this->setNegativeMarking($conn, true, 0.25);

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);
        $questions = $this->seedQuestions($conn, $deps['qbank_id'], 10);

        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;

        // All incorrect
        for ($i = 0; $i < 10; $i++) {
            $q = $questions[$i];
            $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$q['id']}, {$q['wrong_option_id']})");
        }

        $result = evaluate_attempt($conn, $attemptId);

        $this->assertEquals(0.0, $result['score']);
        $this->assertEquals(0.0, $result['percentage']);
        $this->assertEquals(5, $result['level']);
    }

    public static function levelBoundaryProvider(): array
    {
        return [
            [39.99, 5],
            [40.00, 4],
            [54.99, 4],
            [55.00, 3],
            [69.99, 3],
            [70.00, 2],
            [84.99, 2],
            [85.00, 1],
            [100.00, 1],
        ];
    }

    /**
     * @dataProvider levelBoundaryProvider
     */
    public function test_level_boundaries_are_inclusive_correctly(float $percentage, int $expectedLevel): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $level = get_level_for_percentage($conn, $percentage);
        $this->assertNotNull($level);
        $this->assertEquals($expectedLevel, (int)$level['level_number']);
    }

    public function test_certificate_issued_when_eligible(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $this->setNegativeMarking($conn, false);

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);
        $questions = $this->seedQuestions($conn, $deps['qbank_id'], 10);

        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;

        // 5 correct (50% -> Level 4)
        for ($i = 0; $i < 10; $i++) {
            $q = $questions[$i];
            $opt = ($i < 5) ? $q['correct_option_id'] : $q['wrong_option_id'];
            $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$q['id']}, {$opt})");
        }

        $result = evaluate_attempt($conn, $attemptId);

        $this->assertNotNull($result['certificate']);
        
        $certRes = $conn->query("SELECT * FROM certificates WHERE result_id = {$result['result_id']}");
        $this->assertEquals(1, $certRes->num_rows);
        $certRow = $certRes->fetch_assoc();
        $this->assertNotEmpty($certRow['certificate_number']);
    }

    public function test_certificate_withheld_when_below_threshold(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $this->setNegativeMarking($conn, false);

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);
        $questions = $this->seedQuestions($conn, $deps['qbank_id'], 10);

        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;

        // 3 correct (30% -> Level 5)
        for ($i = 0; $i < 10; $i++) {
            $q = $questions[$i];
            $opt = ($i < 3) ? $q['correct_option_id'] : $q['wrong_option_id'];
            $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$q['id']}, {$opt})");
        }

        $result = evaluate_attempt($conn, $attemptId);

        $this->assertNull($result['certificate']);
        
        $certRes = $conn->query("SELECT * FROM certificates WHERE result_id = {$result['result_id']}");
        $this->assertEquals(0, $certRes->num_rows);
    }

    public function test_idempotent_reevaluation_does_not_duplicate_certificate(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);
        $questions = $this->seedQuestions($conn, $deps['qbank_id'], 10);

        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;

        for ($i = 0; $i < 10; $i++) {
            $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$questions[$i]['id']}, {$questions[$i]['correct_option_id']})");
        }

        $result1 = evaluate_attempt($conn, $attemptId);
        $this->assertNotNull($result1['certificate']);

        $result2 = evaluate_attempt($conn, $attemptId);
        $this->assertTrue($result2['already_evaluated']);

        $certRes = $conn->query("SELECT * FROM certificates WHERE result_id = {$result1['result_id']}");
        $this->assertEquals(1, $certRes->num_rows);
    }

    public function test_force_regrade_updates_result_but_does_not_revoke_existing_certificate(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);
        $questions = $this->seedQuestions($conn, $deps['qbank_id'], 10);

        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;

        // Initially 100%
        for ($i = 0; $i < 10; $i++) {
            $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$questions[$i]['id']}, {$questions[$i]['correct_option_id']})");
        }

        $result1 = evaluate_attempt($conn, $attemptId);
        $this->assertEquals(1, $result1['level']);

        // Manipulate answers to fail (30%)
        $conn->query("UPDATE answers SET selected_option_id = {$questions[0]['wrong_option_id']} WHERE attempt_id = {$attemptId} AND question_id != {$questions[0]['id']} AND question_id != {$questions[1]['id']} AND question_id != {$questions[2]['id']}");

        $result2 = evaluate_attempt($conn, $attemptId, false, true);

        $this->assertEquals(30.0, $result2['percentage']);
        $this->assertEquals(5, $result2['level']);

        // Check certificate is untouched
        $certRes = $conn->query("SELECT * FROM certificates WHERE result_id = {$result1['result_id']}");
        $this->assertEquals(1, $certRes->num_rows);

        // Check admin log
        $logRes = $conn->query("SELECT * FROM admin_logs WHERE action = 're_grade_certificate_conflict'");
        $this->assertEquals(1, $logRes->num_rows);
    }

    public function test_cannot_evaluate_a_live_in_progress_attempt(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);

        // Live attempt (end_time in the future)
        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, end_time) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'in_progress', DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $attemptId = $conn->insert_id;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot evaluate an attempt that is still in progress');
        
        evaluate_attempt($conn, $attemptId);
    }

    public function test_lazily_expires_and_evaluates_in_progress_attempt_past_end_time(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);

        // Expired attempt (end_time in the past) but still marked 'in_progress'
        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, end_time) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'in_progress', DATE_SUB(NOW(), INTERVAL 1 HOUR))");
        $attemptId = $conn->insert_id;

        // Will evaluate successfully (score 0 since no answers)
        $result = evaluate_attempt($conn, $attemptId);

        $this->assertEquals(0.0, $result['score']);
        
        // Assert attempt status is now 'expired'
        $attemptRow = $conn->query("SELECT status FROM attempts WHERE id = {$attemptId}")->fetch_assoc();
        $this->assertEquals('expired', $attemptRow['status']);
    }

    public function test_rejects_unknown_attempt_status_defensively(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);

        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;
        
        // This simulates a future schema change bypassing the enum artificially in memory/db.
        // Since MySQL enum constraints might block us from inserting an invalid string, 
        // we assert the exception text by looking at code review, 
        // or by creating a mock row (which we can't easily do).
        // Let's modify the attempt status directly via MySQL strict mode off if needed, 
        // but MySQL ENUM will truncate or error. We'll skip this or just mock the DB fetch if possible.
        // Actually, we can just temporarily change the column type to VARCHAR to test this defensive branch!
        
        $conn->query("ALTER TABLE attempts MODIFY COLUMN status VARCHAR(50)");
        $conn->query("UPDATE attempts SET status = 'unknown_future_status' WHERE id = {$attemptId}");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Attempt has unexpected status 'unknown_future_status' and cannot be evaluated.");

        try {
            evaluate_attempt($conn, $attemptId);
        } finally {
            $conn->query("DELETE FROM attempts WHERE id = {$attemptId}");
            $conn->query("ALTER TABLE attempts MODIFY COLUMN status ENUM('in_progress', 'submitted', 'expired') NOT NULL DEFAULT 'in_progress'");
        }
    }

    public function test_placement_record_created_on_first_evaluation(): void
    {
        global $conn;
        require_once __DIR__ . '/../../src/modules/m7_evaluation_admin/service.php';

        $candidateId = $this->seedUserAndCandidate($conn);
        $deps = $this->seedAssessmentAndDependencies($conn, 10);
        $questions = $this->seedQuestions($conn, $deps['qbank_id'], 10);

        $conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status) VALUES ({$candidateId}, {$deps['assessment_id']}, {$deps['slot_id']}, 'submitted')");
        $attemptId = $conn->insert_id;

        // 100% -> Level 1 -> Eligible for placement
        for ($i = 0; $i < 10; $i++) {
            $conn->query("INSERT INTO answers (attempt_id, question_id, selected_option_id) VALUES ({$attemptId}, {$questions[$i]['id']}, {$questions[$i]['correct_option_id']})");
        }

        $result = evaluate_attempt($conn, $attemptId);

        $placementRes = $conn->query("SELECT * FROM placement_records WHERE candidate_id = {$candidateId}");
        $this->assertEquals(1, $placementRes->num_rows);
        $placement = $placementRes->fetch_assoc();
        $this->assertEquals($result['result_id'], $placement['result_id']);
    }
}
