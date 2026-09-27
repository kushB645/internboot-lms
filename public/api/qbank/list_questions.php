<?php
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_admin_access($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}
require_once __DIR__ . '/../../../src/modules/m1_ai_qbank/controller.php';

// Accept GET or POST requests
$requestData = $_SERVER['REQUEST_METHOD'] === 'POST' 
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : $_GET;

// Execute M1 controller handler
handle_list_questions_request($requestData, $conn);
?>
