<?php
// Path: src/modules/m1_ai_qbank/controller.php

require_once __DIR__ . '/service.php';

/**
 * Controller handler for adding a manual question.
 */
function handle_add_question_request(array $input, mysqli $conn): void {
    require_admin_access($conn);
    require_csrf();
    $qbankId = (int)($input['question_bank_id'] ?? 0);
    $questionText = trim($input['question_text'] ?? '');
    $difficulty = strtolower(trim($input['difficulty'] ?? 'medium'));
    $options = $input['options'] ?? [];

    // Validation checks
    if ($qbankId <= 0) {
        send_json_response('error', 'Valid question_bank_id is required', null, 400);
    }

    if (empty($questionText)) {
        send_json_response('error', 'Question text cannot be empty', null, 400);
    }

    $validDifficulties = ['easy', 'medium', 'hard'];
    if (!in_array($difficulty, $validDifficulties, true)) {
        send_json_response('error', 'Difficulty must be easy, medium, or hard', null, 400);
    }

    if (!is_array($options) || count($options) !== 4) {
        send_json_response('error', 'Exactly 4 options must be provided in an array', null, 400);
    }

    $role = resolve_admin_role($conn);
    if ($role !== 'admin') {
        $approvalStatus = 'pending';
    } else {
        $approvalStatus = $input['approval_status'] ?? 'pending';
        if (!in_array($approvalStatus, ['pending', 'approved', 'rejected'], true)) {
            $approvalStatus = 'pending';
        }
    }

    try {
        $result = add_manual_question($qbankId, $questionText, $difficulty, $options, $conn, $approvalStatus);
        send_json_response('success', 'Question added successfully', $result, 201);
    } catch (InvalidArgumentException $e) {
        send_json_response('error', $e->getMessage(), null, 400);
    } catch (Throwable $e) {
        error_log('InternBoot M1 add question error: ' . $e->getMessage());
        send_json_response('error', is_dev_env() ? $e->getMessage() : 'Failed to add question. Please try again.', null, 500);
    }
}

/**
 * Controller handler for listing approved questions of a qbank.
 */
function handle_list_questions_request(array $input, mysqli $conn): void {
    require_admin_access($conn);
    $qbankId = (int)($input['question_bank_id'] ?? ($_GET['question_bank_id'] ?? 0));

    if ($qbankId <= 0) {
        send_json_response('error', 'Valid question_bank_id parameter is required', null, 400);
    }

    $role = resolve_admin_role($conn);
    $isAdmin = $role === 'admin';

    try {
        $data = fetch_approved_qbank_questions($qbankId, $conn, $isAdmin);
        send_json_response('success', 'Approved questions retrieved successfully', $data, 200);
    } catch (Throwable $e) {
        error_log('InternBoot M1 list questions error: ' . $e->getMessage());
        send_json_response('error', is_dev_env() ? $e->getMessage() : 'Failed to retrieve questions.', null, 500);
    }
}

/**
 * Controller handler for AI-backed question generation.
 */
function handle_generate_questions_request(array $input, mysqli $conn): void {
    require_admin_access($conn);
    require_csrf();
    $qbankId = (int)($input['question_bank_id'] ?? 0);
    $topic = trim((string)($input['topic'] ?? ''));
    $count = (int)($input['count'] ?? 0);

    $easy = max(0, (int)($input['easy_count'] ?? 0));
    $med = max(0, (int)($input['medium_count'] ?? 0));
    $hard = max(0, (int)($input['hard_count'] ?? 0));
    $diffSum = $easy + $med + $hard;

    if ($count <= 0 && $diffSum > 0) {
        $count = $diffSum;
    }
    if ($count <= 0) {
        $count = 5;
    }

    if ($qbankId <= 0) {
        send_json_response('error', 'Valid question_bank_id is required', null, 400);
    }

    if ($topic === '') {
        send_json_response('error', 'Topic description is required', null, 400);
    }

    if ($count <= 0) {
        send_json_response('error', 'Count must be a positive integer', null, 400);
    }

    if ($count > 100) {
        $count = 100;
    }
    @set_time_limit(300);

    $difficultyMix = trim((string)($input['difficulty_mix'] ?? ''));
    if ($difficultyMix === '' && $diffSum > 0) {
        $difficultyMix = "easy:{$easy},medium:{$med},hard:{$hard}";
    }

    try {
        $result = generate_questions_via_ai($qbankId, $topic, $count, $difficultyMix, $conn);
        send_json_response('success', "Generated {$result['inserted']} question(s), pending admin approval", $result, 201);
    } catch (InvalidArgumentException $e) {
        send_json_response('error', $e->getMessage(), null, 400);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        error_log('InternBoot M1 question generation error: ' . $msg);
        if (str_contains($msg, 'AI provider') || str_contains($msg, 'network')) {
            send_json_response('error', 'AI Generation Error: ' . $msg, null, 502);
        } else {
            send_json_response('error', $msg, null, 500);
        }
    }
}
/**
 * Controller handler for AI status check.
 */
function handle_ai_status_request(mysqli $conn): void {
    require_admin_access($conn);
    
    $provider = strtolower(trim((string)env_value('AI_PROVIDER', 'gemini')));
    $configured = false;
    
    if ($provider === 'gemini') {
        $apiKey = trim((string)env_value('GEMINI_API_KEY', ''));
        $configured = $apiKey !== '' && $apiKey !== 'YOUR_GEMINI_API_KEY';
    } elseif ($provider === 'openai') {
        $apiKey = trim((string)env_value('OPENAI_API_KEY', ''));
        $configured = $apiKey !== '' && $apiKey !== 'YOUR_OPENAI_API_KEY';
    }
    
    send_json_response('success', 'AI status retrieved', ['provider' => $provider, 'configured' => $configured], 200);
}



function handle_edit_question_request(array $input, mysqli $conn): void {
    require_admin_access($conn);
    require_csrf();
    $questionId = (int)($input['question_id'] ?? 0);
    $questionText = trim($input['question_text'] ?? '');
    $difficulty = strtolower(trim($input['difficulty'] ?? 'medium'));
    $options = $input['options'] ?? [];
    if ($questionId <= 0 || empty($questionText)) {
        send_json_response('error', 'Valid question_id and text required', null, 400);
    }
    if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
        send_json_response('error', 'Invalid difficulty', null, 400);
    }
    if (count($options) !== 4) {
        send_json_response('error', 'Exactly 4 options must be provided', null, 400);
    }
    $role = resolve_admin_role($conn);
    $forcePending = ($role !== 'admin');

    try {
        edit_manual_question($questionId, $questionText, $difficulty, $options, $conn, $forcePending);
        send_json_response('success', 'Question edited', null, 200);
    } catch (Throwable $e) {
        send_json_response('error', $e->getMessage(), null, 400);
    }
}

function handle_delete_question_request(array $input, mysqli $conn): void {
    require_admin_access($conn);
    require_csrf();
    $questionId = (int)($input['question_id'] ?? 0);
    if ($questionId <= 0) {
        send_json_response('error', 'Valid question_id required', null, 400);
    }

    $role = resolve_admin_role($conn);
    if ($role !== 'admin') {
        send_json_response('error', 'Only administrators can delete questions', null, 403);
    }

    try {
        $stmt = $conn->prepare("DELETE FROM questions WHERE id = ?");
        $stmt->bind_param('i', $questionId);
        $stmt->execute();
        $stmt->close();
        send_json_response('success', 'Question deleted completely', null, 200);
    } catch (Throwable $e) {
        send_json_response('error', $e->getMessage(), null, 400);
    }
}
