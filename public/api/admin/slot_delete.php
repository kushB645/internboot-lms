<?php
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/service.php';

require_csrf();

$role = resolve_admin_role($conn);
if ($role !== 'admin' && $role !== 'staff') {
    send_json_response('error', 'Unauthorized access', null, 403);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$slotId = $input['slot_id'] ?? null;

if (!$slotId || !is_numeric($slotId)) {
    send_json_response('error', 'Valid slot ID is required', null, 400);
    exit;
}

$slotId = (int)$slotId;

$conn->begin_transaction();
try {
    // Check if slot exists
    $stmt = $conn->prepare("SELECT exam_schedule_id FROM exam_slots WHERE id = ?");
    $stmt->bind_param("i", $slotId);
    $stmt->execute();
    $res = $stmt->get_result();
    $slotRow = $res->fetch_assoc();
    $stmt->close();

    if (!$slotRow) {
        throw new Exception("Slot not found.");
    }
    
    $scheduleId = $slotRow['exam_schedule_id'];

    // Delete related attempts
    $stmt = $conn->prepare("DELETE FROM attempts WHERE exam_slot_id = ?");
    $stmt->bind_param("i", $slotId);
    $stmt->execute();
    $stmt->close();
    
    // Delete slot
    $stmt = $conn->prepare("DELETE FROM exam_slots WHERE id = ?");
    $stmt->bind_param("i", $slotId);
    $stmt->execute();
    $stmt->close();

    // Check if schedule has other slots
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM exam_slots WHERE exam_schedule_id = ?");
    $stmt->bind_param("i", $scheduleId);
    $stmt->execute();
    $cRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($cRow['c'] == 0) {
        $stmt = $conn->prepare("UPDATE enrollments SET provisional_schedule_id = NULL WHERE provisional_schedule_id = ?");
        $stmt->bind_param("i", $scheduleId);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare("DELETE FROM exam_schedules WHERE id = ?");
        $stmt->bind_param("i", $scheduleId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    send_json_response('success', 'Slot deleted successfully', null, 200);
} catch (Exception $e) {
    $conn->rollback();
    send_json_response('error', $e->getMessage(), null, 500);
}
