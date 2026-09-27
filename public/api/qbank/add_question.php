<?php
// Path: public/api/qbank/add_question.php

require_once __DIR__ . '/../../../src/core/bootstrap.php';

require_admin_access($conn);
require_csrf();

require_once __DIR__ . '/../../../src/modules/m1_ai_qbank/controller.php';

// Ensure HTTP method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'Only POST request method is allowed', null, 405);
}

// Parse JSON input payload
$rawInput = file_get_contents('php://input');
$requestData = json_decode($rawInput, true);

if (!is_array($requestData)) {
    send_json_response('error', 'Invalid JSON payload provided', null, 400);
}

// Execute M1 controller handler
handle_add_question_request($requestData, $conn);
?>
