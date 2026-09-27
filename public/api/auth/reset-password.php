<?php
// Path: public/api/auth/reset-password.php
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m3_auth/controller.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'Method not allowed', null, 405);
}

$requestData = json_decode(file_get_contents('php://input'), true) ?? [];
handle_reset_password_request($requestData, $conn);
