<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response('error', 'Method not allowed. Use GET.', null, 405);
}

send_json_response('success', 'Security token loaded.', ['token' => csrf_token()]);
