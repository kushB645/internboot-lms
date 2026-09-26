<?php
// Path: public/api/admin/close_slot.php
// Admin: close a provisional slot so no more candidates can prefer it

require_once __DIR__ . '/../../../src/core/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'POST required', null, 405);
}

require_csrf();
session_start_safe();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId || !in_array($_SESSION['role'] ?? '', ['admin','staff'], true)) {
    send_json_response('error', 'Forbidden', null, 403);
}

$input = json_decode(file_get_contents('php://input'), true);
$scheduleId = isset($input['schedule_id']) ? (int)$input['schedule_id'] : 0;
$action = $input['action'] ?? 'close'; // 'close' or 'reopen'

if ($scheduleId <= 0) {
    send_json_response('error', 'schedule_id required', null, 400);
}

try {
    $isClosed = ($action === 'close') ? 1 : 0;
    $stmt = $conn->prepare("UPDATE exam_schedules SET is_closed = ?, updated_at = NOW() WHERE id = ? AND status = 'provisional'");
    $stmt->bind_param('ii', $isClosed, $scheduleId);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        // Maybe already closed or doesn't exist
        $check = $conn->query("SELECT id, is_closed FROM exam_schedules WHERE id = $scheduleId")->fetch_assoc();
        if (!$check) {
            send_json_response('error', 'Slot not found', null, 404);
        }
    }
    $stmt->close();

    // Log admin action
    $action_label = $isClosed ? 'close_slot' : 'reopen_slot';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $details = json_encode(['schedule_id' => $scheduleId, 'action' => $action]);
    $logStmt = $conn->prepare("INSERT INTO admin_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    $logStmt->bind_param('isss', $userId, $action_label, $details, $ip);
    $logStmt->execute();
    $logStmt->close();

    // Mark notification as read if closing
    if ($isClosed) {
        $conn->query("UPDATE admin_notifications SET is_read = 1 WHERE related_id = $scheduleId AND type = 'batch_ready'");
    }

    send_json_response('success', $isClosed ? 'Slot closed successfully' : 'Slot reopened successfully', [
        'schedule_id' => $scheduleId,
        'is_closed'   => (bool)$isClosed,
    ], 200);

} catch (Throwable $e) {
    error_log('close_slot error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}
