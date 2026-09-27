<?php
require_once __DIR__ . '/queries.php';

function m7_dashboard(mysqli $conn): array { return get_dashboard_stats($conn); }
function m7_candidates(mysqli $conn): array { return get_candidates($conn); }
function m7_results(mysqli $conn): array { return get_results($conn); }
function m7_pending_attempts(mysqli $conn): array { return get_pending_attempts($conn); }
function m7_certificates(mysqli $conn): array { return get_certificates($conn); }
function m7_placements(mysqli $conn): array { sync_placement_records($conn); return get_placement_records($conn); }
function m7_questions(mysqli $conn, bool $isAdmin = false): array { return get_questions($conn, $isAdmin); }
function m7_question_status_counts(mysqli $conn): array { return get_question_status_counts($conn); }
function m7_question_banks(mysqli $conn): array { return get_all_question_banks($conn); }
function m7_batches(mysqli $conn): array { return get_batches($conn); }
function m7_settings(mysqli $conn): array { return get_settings($conn); }

function evaluate_attempt(mysqli $conn, int $attemptId, bool $generateCertificate=false, bool $forceRegrade=false): array
{
    $conn->begin_transaction();
    try {
        $attempt=get_attempt_for_update($conn,$attemptId);
        if(!$attempt) throw new InvalidArgumentException('Attempt not found.');

        if($attempt['status']==='submitted'){
            $existing=q_one($conn,'SELECT r.*, c.full_name FROM results r JOIN attempts a ON a.id=r.attempt_id JOIN candidates c ON c.id=a.candidate_id WHERE r.attempt_id=?','i',[$attemptId]);
            if ($existing) {
                if (!$forceRegrade) {
                    $conn->commit();
                    return ['result'=>$existing,'already_evaluated'=>true];
                }
            }
        }

        if ($attempt['status'] === 'expired') {
            $existing = q_one($conn, 'SELECT r.*, c.full_name FROM results r JOIN attempts a ON a.id=r.attempt_id JOIN candidates c ON c.id=a.candidate_id WHERE r.attempt_id=?', 'i', [$attemptId]);
            if ($existing) {
                if (!$forceRegrade) {
                    $conn->commit();
                    return ['result' => $existing, 'already_evaluated' => true];
                }
            }
        }

        // Guard: only 'expired' (and 'in_progress' whose timer has silently run out)
        // may be evaluated. A genuinely live in_progress attempt must not be graded.
        if($attempt['status']==='in_progress'){
            // Check whether the wall-clock end_time has already passed inside this
            // transaction (same logic M6 uses for lazy expiry on candidate requests).
            $timerRow=q_one($conn,'SELECT end_time <= NOW() AS timer_expired FROM attempts WHERE id=? FOR UPDATE','i',[$attemptId]);
            if($timerRow && (int)$timerRow['timer_expired']===1){
                // Timer has run out but M6 hasn't lazy-expired it yet.
                // Auto-expire here so grading is fair and consistent with M6 behaviour.
                $stmt = $conn->prepare("UPDATE attempts SET status='expired', submitted_at=NOW() WHERE id=? AND status='in_progress'");
                $stmt->bind_param('i', $attemptId);
                $stmt->execute();
                $stmt->close();
                // Re-read the attempt so $attempt['status'] reflects the new state downstream.
                $attempt=get_attempt_for_update($conn,$attemptId);
            } else {
                // Candidate is actively mid-exam — reject without touching anything.
                throw new InvalidArgumentException(
                    'Cannot evaluate an attempt that is still in progress. '.
                    'The candidate must submit or the attempt must expire first.'
                );
            }
        }

        // Defensive catch-all: reject any status that is neither 'submitted' nor 'expired'.
        // With the current ENUM('in_progress','submitted','expired') this branch is
        // unreachable, but guards against future schema changes.
        if($attempt['status']!=='expired' && $attempt['status']!=='submitted'){
            throw new InvalidArgumentException(
                "Attempt has unexpected status '{$attempt['status']}' and cannot be evaluated."
            );
        }

        require_once __DIR__ . '/../m5_batch_slots/queries.php';
        $negativeMarkingEnabled = get_setting_value('negative_marking_enabled', $conn) === '1';
        $negativeMarkValue = (float)(get_setting_value('negative_marking_value', $conn) ?? '0.25');

        $answers=get_answer_rows($conn,$attemptId);
        $score=0.0;
        foreach($answers as $answer){
            $isCorrect=(int)$answer['is_correct'];
            $wasAnswered=(int)$answer['was_answered'];
            if ($isCorrect) {
                $score += 1;
            } elseif ($negativeMarkingEnabled && $wasAnswered) {
                $score -= $negativeMarkValue;
            }
            save_answer_correctness($conn,(int)$answer['answer_id'],$isCorrect);
        }
        $score = max(0, $score);

        $servedRaw = function_exists('get_attempt_served_question_count')
            ? get_attempt_served_question_count($conn, $attemptId)
            : (int) q_one($conn, "SELECT COUNT(*) AS cnt FROM attempt_questions WHERE attempt_id = ?", 'i', [$attemptId])['cnt'];
        $servedCount = $servedRaw > 0 ? $servedRaw : max(1, (int)$attempt['total_questions']);
        $percentage = round(($score / $servedCount) * 100, 2);
        $level=get_level_for_percentage($conn,$percentage);
        if(!$level) throw new RuntimeException('No level mapping exists for this percentage.');

        $resultId=upsert_result($conn,$attemptId,(float)$score,$percentage,(int)$level['level_number']);
        mark_attempt_evaluated($conn,$attemptId);
        $existingResult = null;
        if ($forceRegrade) {
            $existingResult = q_one($conn, 'SELECT * FROM results WHERE attempt_id=?', 'i', [$attemptId]);
        }
        $resultId=upsert_result($conn,$attemptId,$score,$percentage,(int)$level['level_number']);
        ensure_placement_record($conn,(int)$attempt['candidate_id'],$resultId);

        $certificate = null;
        $minCertLevel = (int)(get_setting_value('min_certificate_level', $conn) ?? 4);
        $minCertPct   = (float)(get_setting_value('min_certificate_percentage', $conn) ?? 40.0);
        $isEligible   = ((int)$level['level_number'] <= $minCertLevel && $percentage >= $minCertPct);

        if ($isEligible) {
            // Check before upserting so we can distinguish a fresh issuance from
            // a no-op idempotent call (both return the same array shape).
            $certAlreadyExisted = (bool)q_one($conn, 'SELECT id FROM certificates WHERE result_id=?', 'i', [$resultId]);

            // upsert_certificate() is idempotent: returns existing row if present,
            // so re-evaluating the same attempt never creates duplicate cert rows.
            $certificate = upsert_certificate(
                $conn,
                (int)$attempt['candidate_id'],
                $resultId,
                (int)$level['level_number']
            );

            if (!$certAlreadyExisted) {
                // Freshly auto-issued — log with a distinct action label for audit.
                create_admin_log(
                    $conn,
                    $_SESSION['user_id'] ?? null,
                    'auto_generate_certificate',
                    json_encode([
                        'attempt_id'         => $attemptId,
                        'result_id'          => $resultId,
                        'certificate_id'     => $certificate['id'],
                        'certificate_number' => $certificate['certificate_number'],
                        'percentage'         => $percentage,
                        'level'              => (int)$level['level_number'],
                    ])
                );
            }
        } elseif ($forceRegrade && $existingResult) {
            // Re-grade dropped this candidate below the threshold.
            // Certificate already issued (if any) is NOT revoked per product decision,
            // but we log the conflict so admins can review it manually.
            $existingCert = q_one($conn, 'SELECT * FROM certificates WHERE result_id=?', 'i', [$existingResult['id']]);
            if ($existingCert) {
                create_admin_log($conn, $_SESSION['user_id'] ?? null, 're_grade_certificate_conflict', json_encode([
                    'attempt_id'     => $attemptId,
                    'old_percentage' => (float)$existingResult['percentage'],
                    'old_level'      => (int)$existingResult['level_assigned'],
                    'new_percentage' => $percentage,
                    'new_level'      => (int)$level['level_number'],
                ]));
            }
        }

        create_admin_log($conn,$_SESSION['user_id']??null,'evaluate_attempt',json_encode([
            'attempt_id'=>$attemptId,'result_id'=>$resultId,'percentage'=>$percentage,'level'=>$level['level_number']
        ]));

        $conn->commit();

        return [
            'result_id'=>$resultId,
            'attempt_id'=>$attemptId,
            'candidate_id'=>(int)$attempt['candidate_id'],
            'score'=>$score,
            'total_questions'=>$servedCount,
            'percentage'=>$percentage,
            'level'=>(int)$level['level_number'],
            'level_name'=>$level['level_name'],
            'certificate'=>$certificate
        ];
    }catch(Throwable $e){
        $conn->rollback();
        throw $e;
    }
}

function generate_certificate(mysqli $conn,int $resultId): array
{
    $row=q_one($conn,"SELECT r.id result_id,r.level_assigned,r.percentage,c.id candidate_id,c.full_name,u.email,a.title assessment_title
      FROM results r
      JOIN attempts at ON at.id=r.attempt_id
      JOIN candidates c ON c.id=at.candidate_id
      JOIN users u ON u.id=c.user_id
      JOIN assessments a ON a.id=at.assessment_id
      WHERE r.id=?",'i',[$resultId]);

    if(!$row) throw new InvalidArgumentException('Result not found.');

    require_once __DIR__ . '/../m5_batch_slots/queries.php';
    $minCertLevel = (int)(get_setting_value('min_certificate_level', $conn) ?? 4);
    $minCertPct = (float)(get_setting_value('min_certificate_percentage', $conn) ?? 40.0);

    if ((int)$row['level_assigned'] > $minCertLevel || (float)$row['percentage'] < $minCertPct) {
        throw new InvalidArgumentException(
            sprintf(
                'Candidate result does not qualify for certificate issuance. Requires Level %d or better (minimum %.1f%% score), but achieved Level %d (%.2f%%).',
                $minCertLevel,
                $minCertPct,
                (int)$row['level_assigned'],
                (float)$row['percentage']
            )
        );
    }

    $levelRow = q_one($conn, 'SELECT level_name FROM levels WHERE level_number=?', 'i', [(int)$row['level_assigned']]);
    $levelName = $levelRow ? $levelRow['level_name'] : ('Level ' . $row['level_assigned']);

    return upsert_certificate(
        $conn,
        (int)$row['candidate_id'],
        $resultId,
        (int)$row['level_assigned']
    ) + [
        'candidate'=>$row['full_name'],
        'email'=>$row['email'],
        'assessment'=>$row['assessment_title'],
        'percentage'=>$row['percentage'],
        'level'=>$row['level_assigned'],
        'level_name'=>$levelName
    ];
}

function generate_next_certificate(mysqli $conn): array
{
    require_once __DIR__ . '/../m5_batch_slots/queries.php';
    $minCertLevel = (int)(get_setting_value('min_certificate_level', $conn) ?? 4);
    $minCertPct = (float)(get_setting_value('min_certificate_percentage', $conn) ?? 40.0);

    $row=q_one($conn,"SELECT r.id
        FROM results r
        LEFT JOIN certificates c ON c.result_id=r.id
        WHERE c.id IS NULL AND r.level_assigned <= ? AND r.percentage >= ?
        ORDER BY r.created_at ASC
        LIMIT 1", 'id', [$minCertLevel, $minCertPct]);

    if(!$row) throw new InvalidArgumentException('There are no results waiting for a certificate.');
    return generate_certificate($conn,(int)$row['id']);
}
