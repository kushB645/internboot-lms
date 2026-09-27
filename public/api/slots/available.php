<?php
// Path: public/api/slots/available.php

// Require core bootstrap initializer
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/controller.php';

// Ensure HTTP method is GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response('error', 'Only GET request method is allowed', null, 405);
}

// Delegate execution to M5 Batch Slots Controller
handle_list_slots_request($_GET, $conn);
?>
