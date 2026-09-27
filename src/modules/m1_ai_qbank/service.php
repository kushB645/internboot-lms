<?php
// Path: src/modules/m1_ai_qbank/service.php

require_once __DIR__ . '/queries.php';

/**
 * Service logic to manually add an approved question and its 4 options inside a transaction.
 */
function add_manual_question(int $qbankId, string $questionText, string $difficulty, array $options, mysqli $conn, string $approvalStatus = 'pending'): array {
    // 1. Validate Question Bank exists
    $stmt = $conn->prepare("SELECT id FROM question_banks WHERE id = ?");
    $stmt->bind_param("i", $qbankId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        throw new InvalidArgumentException("Question Bank not found.");
    }
    $stmt->close();

    // 2. Validate exactly 1 correct option exists
    $correctCount = 0;
    foreach ($options as $opt) {
        if (!isset($opt['option_text']) || trim($opt['option_text']) === '') {
            throw new InvalidArgumentException("Option text cannot be empty.");
        }
        if (!empty($opt['is_correct'])) {
            $correctCount++;
        }
    }

    if ($correctCount !== 1) {
        throw new InvalidArgumentException("Exactly 1 option must be marked as correct (is_correct = 1).");
    }

    // 3. Begin Atomic Database Transaction
    $conn->begin_transaction();

    try {
        // Insert question record
        $questionId = insert_question($qbankId, $questionText, $difficulty, $conn, $approvalStatus);

        // Insert 4 option records
        foreach ($options as $opt) {
            $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
            insert_question_option($questionId, trim($opt['option_text']), $isCorrect, $conn);
        }

        // Commit Transaction
        $conn->commit();

        return [
            'question_id' => $questionId,
            'question_bank_id' => $qbankId,
            'question_text' => $questionText,
            'difficulty' => $difficulty,
            'approval_status' => $approvalStatus,
        ];
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception("Transaction Failed: " . $e->getMessage());
    }
}

/**
 * Service logic to fetch all approved questions with options for a given question bank.
 * Security Note: $isAdmin flag controls whether the `is_correct` answer key is included.
 */
function fetch_approved_qbank_questions(int $qbankId, mysqli $conn, bool $isAdmin = false): array {
    $questions = get_approved_questions_by_qbank($qbankId, $conn);

    $formattedQuestions = [];
    foreach ($questions as $q) {
        $questionId = (int)$q['id'];
        $options = get_options_by_question_id($questionId, $conn);

        // Map options data safely
        $mappedOptions = array_map(function($opt) use ($isAdmin) {
            $optionData = [
                'id' => (int)$opt['id'],
                'option_text' => $opt['option_text']
            ];

            // Only expose answer key if requested by Admin session
            if ($isAdmin) {
                $optionData['is_correct'] = (int)$opt['is_correct'];
            }

            return $optionData;
        }, $options);

        $formattedQuestions[] = [
            'id' => $questionId,
            'question_text' => $q['question_text'],
            'difficulty' => $q['difficulty'],
            'options' => $mappedOptions
        ];
    }

    return [
        'question_bank_id' => $qbankId,
        'total_questions' => count($formattedQuestions),
        'questions' => $formattedQuestions
    ];
}

/**
 * AI-backed question generation service function.
 * Generates questions using configured AI_PROVIDER (gemini or openai), validates output strictly,
 * and inserts validated questions into DB with approval_status = 'pending' in an atomic transaction.
 */
function generate_questions_via_ai(
    int $qbankId,
    string $topic,
    string $qType,
    int $easyCount,
    int $mediumCount,
    int $hardCount,
    mysqli $conn
): array {
    set_time_limit(180);

    // 1. Validate Question Bank exists
    $stmt = $conn->prepare("SELECT id FROM question_banks WHERE id = ?");
    $stmt->bind_param("i", $qbankId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        throw new InvalidArgumentException("Question Bank not found.");
    }
    $stmt->close();

    // 2. Resolve AI Provider & Key
    $provider = strtolower(trim((string)env_value('AI_PROVIDER', 'gemini')));
    if ($provider === 'gemini') {
        $apiKey = env_value('GEMINI_API_KEY', '');
        $model = env_value('GEMINI_MODEL', 'gemini-2.0-flash');
    } elseif ($provider === 'openai') {
        $apiKey = env_value('OPENAI_API_KEY', '');
        $model = env_value('OPENAI_MODEL', 'gpt-4o-mini');
    } else {
        throw new RuntimeException("Unsupported AI provider '{$provider}'. Configured AI_PROVIDER must be 'gemini' or 'openai'.");
    }

    if (empty($apiKey)) {
        throw new RuntimeException("AI provider '{$provider}' is configured but its API key is missing from environment.");
    }

    // 3. Build AI Prompt
    $totalCount = $easyCount + $mediumCount + $hardCount;
    $difficultyMix = "{$easyCount} Easy, {$mediumCount} Medium, {$hardCount} Hard";
    
    $prompt = "Generate exactly {$totalCount} multiple-choice test questions (MCQs) about the topic: \"{$topic}\". These must be strictly MCQ-based test questions.\n";
    if (!empty($difficultyMix)) {
        $prompt .= "Target difficulty distribution/mix: {$difficultyMix}.\n";
    }
    if (!empty($qType)) {
        $prompt .= "Question style: {$qType}.\n";
    }
    
    $prompt .= "Return ONLY a valid JSON array of question objects. Do NOT include markdown code blocks, backticks, prose, or extra text before or after the JSON.\n";
    $prompt .= "Each question object in the JSON array MUST have this exact schema:\n";
    $prompt .= "[\n";
    $prompt .= "  {\n";
    $prompt .= "    \"question_text\": \"Question text here?\",\n";
    $prompt .= "    \"difficulty\": \"easy|medium|hard\",\n";
    $prompt .= "    \"options\": [\n";
    $prompt .= "      {\"option_text\": \"Option A text\", \"is_correct\": true},\n";
    $prompt .= "      {\"option_text\": \"Option B text\", \"is_correct\": false},\n";
    $prompt .= "      {\"option_text\": \"Option C text\", \"is_correct\": false},\n";
    $prompt .= "      {\"option_text\": \"Option D text\", \"is_correct\": false}\n";
    $prompt .= "    ]\n";
    $prompt .= "  }\n";
    $prompt .= "]\n";
    $prompt .= "Rules:\n";
    $prompt .= "1. Each question MUST have exactly 4 options.\n";
    $prompt .= "2. Exactly one option per question MUST have is_correct set to true.\n";
    $prompt .= "3. All question_text and option_text MUST be non-empty strings.\n";
    $prompt .= "4. Output ONLY raw JSON array.";

    // 4. Perform Provider HTTP Request via cURL
    $rawText = '';
    try {
        if ($provider === 'gemini') {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent?key=" . rawurlencode($apiKey);
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'responseMimeType' => 'application/json'
                ]
            ];

            $response = false;
            $httpCode = 0;
            $curlErr = '';
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_TIMEOUT => 120,
                    CURLOPT_CONNECTTIMEOUT => 60,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_WHATEVER
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if (!in_array($httpCode, [429, 500, 502, 503, 504], true) || $attempt === 2) {
                    break;
                }
                sleep($attempt + 1);
            }

            if ($response === false || !empty($curlErr)) {
                throw new RuntimeException("AI provider request failed: " . ($curlErr ?: 'Network or cURL timeout error'));
            }
            if ($httpCode !== 200) {
                error_log("DUMP: " . $response);
                $errData = json_decode((string)$response, true);
                $errMsg = $errData['error']['message'] ?? "HTTP response code {$httpCode}";
                throw new RuntimeException("AI provider request failed: {$errMsg}");
            }

            $resData = json_decode((string)$response, true);
            $rawText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } else {
            // OpenAI
            $url = "https://api.openai.com/v1/chat/completions";
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a technical question generator. Respond with a JSON array ONLY.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.7
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($curlErr)) {
                throw new RuntimeException("AI provider request failed: " . ($curlErr ?: 'Network or cURL timeout error'));
            }
            if ($httpCode !== 200) {
                $errData = json_decode((string)$response, true);
                $errMsg = $errData['error']['message'] ?? "HTTP response code {$httpCode}";
                throw new RuntimeException("AI provider request failed: {$errMsg}");
            }

            $resData = json_decode((string)$response, true);
            $rawText = $resData['choices'][0]['message']['content'] ?? '';
        }
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'AI provider request failed')) {
            throw $e;
        }
        throw new RuntimeException("AI provider request failed: " . $e->getMessage());
    }

    // 5. Clean & Parse JSON Output
    $cleanJson = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
    $cleanJson = preg_replace('/\s*```$/', '', $cleanJson);
    $cleanJson = trim($cleanJson);

    $items = json_decode($cleanJson, true);
    if (!is_array($items)) {
        throw new RuntimeException("AI provider returned invalid JSON response.");
    }
    if (isset($items['questions']) && is_array($items['questions'])) {
        $items = $items['questions'];
    } elseif (isset($items['data']) && is_array($items['data'])) {
        $items = $items['data'];
    }

    if (!is_array($items) || empty($items)) {
        throw new RuntimeException("AI provider returned no questions or an empty array.");
    }

    // 6. Strict Batch Validation
    $validationErrors = [];
    $validatedQuestions = [];
    $allowedDifficulties = ['easy', 'medium', 'hard'];

    foreach ($items as $idx => $item) {
        $itemNum = $idx + 1;
        $itemValid = true;

        if (!is_array($item)) {
            $validationErrors[] = "Item #{$itemNum} is not a valid question object";
            continue;
        }

        $questionText = isset($item['question_text']) ? trim((string)$item['question_text']) : '';
        if ($questionText === '') {
            $validationErrors[] = "Item #{$itemNum} is missing or has empty 'question_text'";
            $itemValid = false;
            continue;
        }

        $difficulty = isset($item['difficulty']) ? strtolower(trim((string)$item['difficulty'])) : 'medium';
        if ($difficulty === '') {
            $difficulty = 'medium';
        } elseif (!in_array($difficulty, $allowedDifficulties, true)) {
            $validationErrors[] = "Item #{$itemNum} has invalid difficulty '{$difficulty}' (must be easy, medium, or hard)";
            $itemValid = false;
            continue;
        }

        $options = $item['options'] ?? null;
        if (!is_array($options) || count($options) !== 4) {
            $validationErrors[] = "Item #{$itemNum} does not have exactly 4 options";
            continue;
        }

        $correctCount = 0;
        $validatedOptions = [];

        foreach ($options as $optIdx => $opt) {
            $optNum = $optIdx + 1;
            if (!is_array($opt)) {
                $validationErrors[] = "Item #{$itemNum} option #{$optNum} is invalid";
                $itemValid = false;
                continue;
            }

            $optText = isset($opt['option_text']) ? trim((string)$opt['option_text']) : '';
            if ($optText === '') {
                $validationErrors[] = "Item #{$itemNum} option #{$optNum} has empty text";
                $itemValid = false;
                continue;
            }

            $isCorrect = false;
            if (isset($opt['is_correct'])) {
                $isCorrect = ($opt['is_correct'] === true || $opt['is_correct'] === 1 || $opt['is_correct'] === '1' || $opt['is_correct'] === 'true');
            }

            if ($isCorrect) {
                $correctCount++;
            }

            $validatedOptions[] = [
                'option_text' => $optText,
                'is_correct' => $isCorrect ? 1 : 0
            ];
        }

        if (!$itemValid) {
            continue;
        }

        if ($correctCount !== 1) {
            $validationErrors[] = "Item #{$itemNum} has {$correctCount} correct options (exactly 1 is required)";
            continue;
        }

        $validatedQuestions[] = [
            'question_text' => $questionText,
            'difficulty' => $difficulty,
            'options' => $validatedOptions
        ];
    }

    if (empty($validatedQuestions)) {
        $errCount = count($validationErrors);
        $reasonStr = implode("; ", array_slice($validationErrors, 0, 5));
        throw new RuntimeException("AI generation validation failed ({$errCount} error(s)): {$reasonStr}");
    }

    // 7. Atomic Database Inserts (All inserted with approval_status = 'pending')
    $conn->begin_transaction();
    $questionIds = [];

    try {
        $existingTexts = [];
        $stmt = $conn->prepare("SELECT question_text FROM questions WHERE question_bank_id = ?");
        $stmt->bind_param('i', $qbankId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $existingTexts[strtolower(trim($row['question_text']))] = true;
        }
        $stmt->close();

        foreach ($validatedQuestions as $q) {
            $normalized = strtolower(trim($q['question_text']));
            if (isset($existingTexts[$normalized])) {
                $validationErrors[] = "Duplicate question skipped: \"" . substr($q['question_text'], 0, 60) . "\"";
                continue;
            }
            $existingTexts[$normalized] = true;

            shuffle($q['options']);

            $qId = insert_question($qbankId, $q['question_text'], $q['difficulty'], $conn, 'pending');
            foreach ($q['options'] as $opt) {
                insert_question_option($qId, $opt['option_text'], $opt['is_correct'], $conn);
            }
            $questionIds[] = $qId;
        }

        if (empty($questionIds)) {
            throw new Exception("No new questions were inserted — all items were either invalid or duplicates.");
        }

        $conn->commit();

        return [
            'requested' => $totalCount,
            'inserted' => count($questionIds),
            'question_ids' => $questionIds,
            'skipped' => count($validationErrors),
            'skipped_reasons' => array_slice($validationErrors, 0, 10)
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw new Exception("Database insertion failed: " . $e->getMessage());
    }
}

function edit_manual_question(int $questionId, string $questionText, string $difficulty, array $options, mysqli $conn): void {
    $correctCount = 0;
    foreach ($options as $opt) {
        if (trim($opt['option_text']) === '') throw new InvalidArgumentException("Option text empty");
        if (!empty($opt['is_correct'])) $correctCount++;
    }
    if ($correctCount !== 1) throw new InvalidArgumentException("Exactly 1 correct option required");

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE questions SET question_text=?, difficulty=? WHERE id=?");
        $stmt->bind_param('ssi', $questionText, $difficulty, $questionId);
        $stmt->execute();
        $stmt->close();

        $delStmt = $conn->prepare("DELETE FROM options WHERE question_id=?");
        $delStmt->bind_param('i', $questionId);
        $delStmt->execute();
        $delStmt->close();

        foreach ($options as $opt) {
            $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
            insert_question_option($questionId, trim($opt['option_text']), $isCorrect, $conn);
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}
