<?php

// Load central bootstrap
if (file_exists(dirname(__DIR__, 3) . '/src/core/bootstrap.php')) {
    require_once dirname(__DIR__, 3) . '/src/core/bootstrap.php';
} elseif (file_exists(__DIR__ . '/../../../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../../../src/core/bootstrap.php';
} else {
    require_once __DIR__ . '/../src/core/bootstrap.php';
}

try {

    /*
     * 1. Candidate authentication
     */
    $candidateId = require_candidate_auth($conn);
    require_csrf();

    /*
     * 2. Only POST is allowed.
     */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json_response('error', 'POST request required', null, 405);
    }

    /*
     * 3. Read JSON body.
     */
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        send_json_response('error', 'Invalid JSON body', null, 400);
    }

    $attemptId = isset($input['attempt_id'])
        ? (int) $input['attempt_id']
        : 0;

    $questionId = isset($input['question_id'])
        ? (int) $input['question_id']
        : 0;

    $selectedOptionId = isset($input['selected_option_id'])
        ? (int) $input['selected_option_id']
        : 0;

    if (
        $attemptId <= 0 ||
        $questionId <= 0 ||
        $selectedOptionId <= 0
    ) {
        send_json_response('error', 'Invalid answer data', null, 400);
    }

    /*
     * 4. Verify attempt ownership and status.
     */
    $attemptSql = "
        SELECT
            id,
            assessment_id,
            status,
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

    if ($attempt['status'] !== 'in_progress') {
        send_json_response('error', 'This attempt is no longer active', [
            'status' => $attempt['status']
        ], 403);
    }

    /*
     * 5. Server-side expiry check.
     * Note: Initial start timing (start_time & end_time) is set exclusively
     * by start_exam.php after passing the scheduled date/window gate.
     */
    if (empty($attempt['end_time'])) {
        send_json_response('error', 'Attempt timing is not initialized', null, 500);
    }

    $now = new DateTime();

    $endTime = new DateTime(
        $attempt['end_time']
    );

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
     * 6. Get the assessment's configured question count.
     */
    /*
     * 7. IMPORTANT:
     *
     * Verify that the question is actually part of THIS
     * attempt's frozen question set.
     */
    $assignedQuestionSql = "
        SELECT 1
        FROM attempt_questions
        WHERE attempt_id = ? AND question_id = ?
        LIMIT 1
    ";

    $assignedStmt = $conn->prepare($assignedQuestionSql);

    if (!$assignedStmt) {
        throw new Exception('Failed to prepare assigned question query');
    }

    $assignedStmt->bind_param("ii", $attemptId, $questionId);
    $assignedStmt->execute();
    $assignedResult = $assignedStmt->get_result();

    $questionAssigned = ($assignedResult->num_rows > 0);

    $assignedStmt->close();

    if (!$questionAssigned) {
        send_json_response('error', 'Question is not assigned to this attempt', null, 400);
    }

    /*
     * 8. Verify selected option belongs to this question.
     *
     * is_correct is intentionally NOT selected.
     */
    $optionSql = "
        SELECT
            id
        FROM options
        WHERE id = ?
          AND question_id = ?
        LIMIT 1
    ";

    $optionStmt = $conn->prepare($optionSql);

    if (!$optionStmt) {
        throw new Exception('Failed to prepare option query');
    }

    $optionStmt->bind_param(
        "ii",
        $selectedOptionId,
        $questionId
    );

    $optionStmt->execute();

    $optionResult = $optionStmt->get_result();

    $validOption = $optionResult->fetch_assoc();

    $optionStmt->close();

    if (!$validOption) {
        send_json_response('error', 'Invalid option for this question', null, 400);
    }

    /*
     * 9. Insert or update the answer.
     *
     * M6 stores the selected option.
     * M7 handles correctness/evaluation.
     */
    $answerSql = "
        INSERT INTO answers
        (
            attempt_id,
            question_id,
            selected_option_id
        )
        VALUES
        (
            ?,
            ?,
            ?
        )
        ON DUPLICATE KEY UPDATE
            selected_option_id = VALUES(selected_option_id),
            updated_at = CURRENT_TIMESTAMP
    ";

    $answerStmt = $conn->prepare($answerSql);

    if (!$answerStmt) {
        throw new Exception('Failed to prepare answer query');
    }

    $answerStmt->bind_param(
        "iii",
        $attemptId,
        $questionId,
        $selectedOptionId
    );

    $answerStmt->execute();

    $answerStmt->close();

    /*
     * 10. Success response using standardized helper.
     */
    send_json_response('success', 'Answer saved successfully', [
        'success' => true,
        'attempt_id' => $attemptId,
        'question_id' => $questionId,
        'selected_option_id' => $selectedOptionId
    ], 200);

} catch (Throwable $e) {
    error_log('save_answer error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}