<?php
// Path: public/api/notifications/list.php
// Returns admin notifications

require_once __DIR__ . '/../../../src/core/bootstrap.php';

require_once __DIR__ . '/../../../src/core/bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'PATCH'], true)) {
    send_json_response('error', 'Only GET/PATCH is allowed', null, 405);
}

// Admin auth
session_start_safe();
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    send_json_response('error', 'Unauthorized', null, 401);
}
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'staff'], true)) {
    send_json_response('error', 'Forbidden', null, 403);
}

// PATCH: mark all read
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $conn->query("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
    send_json_response('success', 'All notifications marked as read', ['updated' => $conn->affected_rows], 200);
}

try {
    $unreadOnly = isset($_GET['unread']) && $_GET['unread'] === '1';
    $limit = min((int)($_GET['limit'] ?? 20), 50);

    $sql = "SELECT id, type, title, message, related_id, is_read, created_at
            FROM admin_notifications";
    if ($unreadOnly) $sql .= " WHERE is_read = 0";
    $sql .= " ORDER BY created_at DESC LIMIT " . $limit;

    $res = $conn->query($sql);
    $notifications = [];
    while ($row = $res->fetch_assoc()) {
        $notifications[] = [
            'id'         => (int)$row['id'],
            'type'       => $row['type'],
            'title'      => $row['title'],
            'message'    => $row['message'],
            'related_id' => $row['related_id'] ? (int)$row['related_id'] : null,
            'is_read'    => (bool)$row['is_read'],
            'created_at' => $row['created_at'],
        ];
    }

    // Unread count
    $unreadCount = (int)$conn->query("SELECT COUNT(*) as c FROM admin_notifications WHERE is_read=0")->fetch_assoc()['c'];

    send_json_response('success', 'Notifications retrieved', [
        'notifications' => $notifications,
        'unread_count'  => $unreadCount,
    ], 200);

} catch (Throwable $e) {
    error_log('notifications/list error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}
