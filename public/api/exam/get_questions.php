<?php

// Load central bootstrap
if (file_exists(dirname(__DIR__, 3) . '/src/core/bootstrap.php')) {
    require_once dirname(__DIR__, 3) . '/src/core/bootstrap.php';
} elseif (file_exists(__DIR__ . '/../../../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../../../src/core/bootstrap.php';
} else {
    require_once __DIR__ . '/../src/core/bootstrap.php';
}

require_once __DIR__ . '/../../../src/modules/m5_batch_slots/queries.php';
try {

    /*
     * 1. Candidate authentication
     */
    $candidateId = require_candidate_auth($conn);

    /*
     * 2. Attempt ID
     */
    require_once __DIR__ . '/../../../src/core/helpers.php';
    $attemptId = isset($_GET['attempt_id'])
        ? (int) $_GET['attempt_id']
        : 0;

    if ($attemptId <= 0) {
        send_json_response('error', 'Invalid attempt ID', null, 400);
    }

    /*
     * 3. Validate attempt ownership
     */
    $attemptSql = "
        SELECT
            id,
            candidate_id,
            assessment_id,
            exam_slot_id,
            status,
            start_time,
            end_time
        FROM attempts
        WHERE id = ?
          AND candidate_id = ?
        LIMIT 1
    ";

    $attemptStmt = $conn->prepare($attemptSql);

    if (!$attemptStmt) {
        throw new Exception('Failed to prepare attempt query');
    }

    $attemptStmt->bind_param(
        "ii",
        $attemptId,
        $candidateId
    );

    $attemptStmt->execute();

    $attemptResult = $attemptStmt->get_result();

    $attempt = $attemptResult->fetch_assoc();

    $attemptStmt->close();

    if (!$attempt) {
        send_json_response('error', 'Attempt not found or access denied', null, 404);
    }

    /*
     * 4. Only in-progress attempts can load questions.
     */
    if ($attempt['status'] !== 'in_progress') {
        send_json_response('error', 'Questions are not available for this attempt', [
            'status' => $attempt['status']
        ], 403);
    }

    /*
     * 5. Server-side expiry check
     * Note: Initial start timing (start_time & end_time) is set exclusively
     * by start_exam.php after passing the scheduled date/window gate.
     */
    if (empty($attempt['end_time'])) {
        send_json_response('error', 'Attempt timing is not initialized', null, 500);
    }

    $now = new DateTime();
    $endTime = new DateTime($attempt['end_time']);

    if ($now >= $endTime) {

        $expireSql = "
            UPDATE attempts
            SET
                status = 'expired',
                submitted_at = NOW()
            WHERE id = ?
              AND candidate_id = ?
              AND status = 'in_progress'
        ";

        $expireStmt = $conn->prepare($expireSql);

        if (!$expireStmt) {
            throw new Exception('Failed to prepare expiry update');
        }

        $expireStmt->bind_param(
            "ii",
            $attemptId,
            $candidateId
        );

        $expireStmt->execute();

        $expireStmt->close();

        require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/service.php';
        try {
            evaluate_attempt($conn, $attemptId, true);
        } catch (Throwable $evalError) {
            error_log('Auto-evaluation failed for attempt ' . $attemptId . ': ' . $evalError->getMessage());
        }

        send_json_response('error', 'Exam time has expired', [
            'status' => 'expired'
        ], 403);
    }


    /*
     * 6. Get assessment configuration
     */
    $assessmentSql = "
        SELECT
            id,
            total_questions
        FROM assessments
        WHERE id = ?
        LIMIT 1
    ";

    $assessmentStmt = $conn->prepare($assessmentSql);

    if (!$assessmentStmt) {
        throw new Exception('Failed to prepare assessment query');
    }

    $assessmentStmt->bind_param(
        "i",
        $attempt['assessment_id']
    );

    $assessmentStmt->execute();

    $assessmentResult = $assessmentStmt->get_result();

    $assessment = $assessmentResult->fetch_assoc();

    $assessmentStmt->close();

    $totalQuestions = ($assessment && !empty($assessment['total_questions']))
        ? (int) $assessment['total_questions']
        : 100;

    if ($totalQuestions <= 0) {
        $totalQuestions = 100;
    }

    /*
     * 7. Select deterministic randomized questions.
     *
     * Same attempt = same order after refresh.
     * Different attempt = different order.
     */
    $conn->begin_transaction();
    try {
        // Try to read an existing snapshot, locking it against concurrent builds.
        $snapshotSql = "
            SELECT q.id AS question_id, q.question_text, q.type, q.difficulty
            FROM attempt_questions aq
            JOIN questions q ON q.id = aq.question_id
            WHERE aq.attempt_id = ?
            ORDER BY aq.position ASC
            FOR UPDATE
        ";
        $snapStmt = $conn->prepare($snapshotSql);
        $snapStmt->bind_param("i", $attemptId);
        $snapStmt->execute();
        $fetchedQuestions = $snapStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $snapStmt->close();

        if (empty($fetchedQuestions)) {
            $slotId = (int)$attempt['exam_slot_id'];
            
            // Check if THIS batch already has questions assigned to SOME attempt.
            $slotSql = "
                SELECT q.id AS question_id, q.question_text, q.type, q.difficulty
                FROM attempt_questions aq
                JOIN attempts a ON a.id = aq.attempt_id
                JOIN questions q ON q.id = aq.question_id
                WHERE a.exam_slot_id = ?
                GROUP BY q.id, q.question_text, q.type, q.difficulty
                ORDER BY MIN(aq.position) ASC
            ";
            $slotStmt = $conn->prepare($slotSql);
            $slotStmt->bind_param("i", $slotId);
            $slotStmt->execute();
            $fetchedQuestions = $slotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $slotStmt->close();

            if (empty($fetchedQuestions)) {
                // FIRST candidate in the batch! Assign new questions from 'approved' pool.
                $poolSql = "
                    SELECT q.id AS question_id, q.question_text, q.type, q.difficulty
                    FROM questions q INNER JOIN question_banks qb ON qb.id = q.question_bank_id
                    WHERE qb.assessment_id = ? AND q.type = 'MCQ' AND q.approval_status = 'approved'
                    ORDER BY MD5(CONCAT(?, ':', q.id)) LIMIT ?
                ";
                $poolStmt = $conn->prepare($poolSql);
                $poolStmt->bind_param("iii", $attempt['assessment_id'], $slotId, $totalQuestions);
                $poolStmt->execute();
                $fetchedQuestions = $poolStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $poolStmt->close();
                
                // Shuffle deterministically using slotId
                srand($slotId);
                shuffle($fetchedQuestions);
                srand();
                
                // ARCHIVE these questions so future batches cannot use them!
                if (!empty($fetchedQuestions)) {
                    $qIdsToArchive = array_column($fetchedQuestions, 'question_id');
                    $inClauseArchive = implode(',', array_fill(0, count($qIdsToArchive), '?'));
                    $archiveSql = "UPDATE questions SET approval_status = 'archived' WHERE id IN ($inClauseArchive)";
                    $archiveStmt = $conn->prepare($archiveSql);
                    $typesArchive = str_repeat('i', count($qIdsToArchive));
                    $archiveStmt->bind_param($typesArchive, ...$qIdsToArchive);
                    $archiveStmt->execute();
                    $archiveStmt->close();
                }
            }

            $insertSql = "INSERT INTO attempt_questions (attempt_id, question_id, position) VALUES (?, ?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            foreach ($fetchedQuestions as $pos => $q) {
                $position = $pos + 1;
                $qId = (int)$q['question_id'];
                $insertStmt->bind_param("iii", $attemptId, $qId, $position);
                $insertStmt->execute();
            }
            $insertStmt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $questions = [];

    $questions = [];
    $questionIds = array_column($fetchedQuestions, 'question_id');

    if (!empty($questionIds)) {
        $inClause = implode(',', array_fill(0, count($questionIds), '?'));
        
        /*
         * 8. Batch get options.
         *
         * IMPORTANT:
         * is_correct is NEVER returned.
         */
        $optionSql = "
            SELECT
                id AS option_id,
                option_text,
                question_id
            FROM options
            WHERE question_id IN ($inClause)
        ";

        $optionStmt = $conn->prepare($optionSql);
        if (!$optionStmt) {
            throw new Exception('Failed to prepare option query');
        }
        
        $types = str_repeat('i', count($questionIds));
        $optionStmt->bind_param($types, ...$questionIds);
        $optionStmt->execute();
        $optionResult = $optionStmt->get_result();

        $allOptions = [];
        while ($opt = $optionResult->fetch_assoc()) {
            $allOptions[$opt['question_id']][] = [
                'option_id' => (int)$opt['option_id'],
                'option_text' => $opt['option_text']
            ];
        }
        $optionStmt->close();

        // Sort options per question using the same logic as ORDER BY MD5(CONCAT(?, ':', id))
        foreach ($allOptions as $qid => &$opts) {
            usort($opts, function($a, $b) use ($attemptId) {
                $hashA = md5($attemptId . ':' . $a['option_id']);
                $hashB = md5($attemptId . ':' . $b['option_id']);
                return strcmp($hashA, $hashB);
            });
        }
        unset($opts);

        /*
         * 9. Batch load previously saved answers.
         *
         * Only selected_option_id is returned.
         * is_correct remains server-side for M7.
         */
        $answerSql = "
            SELECT
                question_id,
                selected_option_id
            FROM answers
            WHERE attempt_id = ?
              AND question_id IN ($inClause)
        ";

        $answerStmt = $conn->prepare($answerSql);
        if (!$answerStmt) {
            throw new Exception('Failed to prepare answer query');
        }

        $answerTypes = 'i' . $types;
        $answerParams = array_merge([$attemptId], $questionIds);
        $answerStmt->bind_param($answerTypes, ...$answerParams);
        $answerStmt->execute();
        $answerResult = $answerStmt->get_result();

        $allAnswers = [];
        while ($ans = $answerResult->fetch_assoc()) {
            $allAnswers[$ans['question_id']] = $ans['selected_option_id'] !== null ? (int)$ans['selected_option_id'] : null;
        }
        $answerStmt->close();

        /*
         * 10. Build question response.
         */
        foreach ($fetchedQuestions as $question) {
            $questionId = (int) $question['question_id'];
            $questions[] = [
                'question_id' => $questionId,
                'question_text' => $question['question_text'],
                'type' => $question['type'],
                'difficulty' => $question['difficulty'],
                'options' => $allOptions[$questionId] ?? [],
                'selected_option_id' => $allAnswers[$questionId] ?? null
            ];
        }
    }

    /*
     * 11. Return questions using standardized helper.
     */
    send_json_response('success', 'Questions retrieved successfully', [
        'success' => true,
        'attempt_id' => $attemptId,
        'assessment_id' => (int) $attempt['assessment_id'],
        'total_questions' => count($questions),
        'questions' => $questions
    ], 200);

} catch (Throwable $e) {
    error_log('get_questions error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}
