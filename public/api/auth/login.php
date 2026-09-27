<?php
// Path: public/api/auth/login.php

// Require core bootstrap initializer
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m3_auth/controller.php';

// Ensure HTTP method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'Only POST request method is allowed', null, 405);
}

// Read raw JSON body payload
$rawInput = file_get_contents('php://input');
$requestData = json_decode($rawInput, true);

if (!is_array($requestData)) {
    send_json_response('error', 'Invalid JSON body payload provided', null, 400);
}

// Delegate execution to M3 Auth Controller
handle_login_request($requestData, $conn);
?>
