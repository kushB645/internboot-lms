<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m3_auth/controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'Method not allowed. Use POST.', null, 405);
}

require_csrf();

handle_logout_request();