<?php
function send_json_response(string $status, string $message, $data = null, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
