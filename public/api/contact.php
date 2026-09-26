<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'Only POST requests are supported.', null, 405);
}

// Support both JSON body and standard Form POST
$rawInput = file_get_contents('php://input');
$data = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if (empty($data)) {
    $data = $_POST;
}

$firstName = trim((string)($data['first_name'] ?? $data['firstName'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? $data['lastName'] ?? ''));
if ($firstName === '' && !empty($data['name'])) {
    $parts = explode(' ', trim((string)$data['name']), 2);
    $firstName = $parts[0];
    $lastName = $parts[1] ?? '';
}
$email = trim((string)($data['email'] ?? ''));
$phone = trim((string)($data['phone'] ?? $data['phone_number'] ?? ''));
$inquiryType = trim((string)($data['inquiry_type'] ?? $data['inquiryType'] ?? $data['type_of_inquiry'] ?? 'General Inquiry'));
$message = trim((string)($data['message'] ?? ''));

if ($firstName === '') {
    send_json_response('error', 'First name is required.', ['field' => 'first_name'], 422);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json_response('error', 'A valid email address is required.', ['field' => 'email'], 422);
}

if ($message === '' || strlen($message) < 5) {
    send_json_response('error', 'Please enter a message with at least 5 characters.', ['field' => 'message'], 422);
}

$ticketId = 'IB-TKT-' . date('ymd') . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 4));

$inquiryRecord = [
    'ticket_id' => $ticketId,
    'first_name' => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
    'last_name' => htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'),
    'full_name' => trim(htmlspecialchars($firstName . ' ' . $lastName, ENT_QUOTES, 'UTF-8')),
    'email' => filter_var($email, FILTER_SANITIZE_EMAIL),
    'phone' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
    'inquiry_type' => htmlspecialchars($inquiryType, ENT_QUOTES, 'UTF-8'),
    'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
    'created_at' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
];

// Persist to local JSON storage log
$storageDir = __DIR__ . '/../../storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}
$logFile = $storageDir . '/inquiries.json';
$existing = [];
if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    if (!empty($content)) {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
}
$existing[] = $inquiryRecord;
// Keep the last 200 inquiries
if (count($existing) > 200) {
    $existing = array_slice($existing, -200);
}
@file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

send_json_response('success', 'Thank you! Your message has been received. Our candidate support team will get back to you shortly.', [
    'ticket_id' => $ticketId,
    'full_name' => $inquiryRecord['full_name'],
    'inquiry_type' => $inquiryRecord['inquiry_type']
]);
