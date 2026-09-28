<?php
// Path: public/api/slots/public_slots.php
// Returns all open provisional slots with candidate preference counts
// Used by candidates to browse and choose a slot

require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/core/candidate_resolver.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/queries.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response('error', 'Only GET is allowed', null, 405);
}

$candidateId = validate_candidate_session($conn);
if (!$candidateId) {
    send_json_response('error', 'Unauthorized', null, 401);
}

$assessmentId = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
if ($assessmentId <= 0) {
    send_json_response('error', 'assessment_id is required', null, 400);
}

try {
    // Dynamically check and cancel any underfilled slots (< 100 candidates) within 30 minutes
    check_and_notify_underfilled_slots($conn, $assessmentId);

    // Get candidate's current enrollment & preference
    $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
    if (!$enrollment) {
        send_json_response('error', 'Not enrolled in this assessment', null, 403);
    }

    $myPreference = (int)($enrollment['provisional_schedule_id'] ?? 0);
    $preferenceFailed = false;
    $cutoffTime = time() + (30 * 60); // 30 minutes from now

    if ($myPreference > 0) {
        $pSql = "
            SELECT s.status, s.exam_date, s.is_closed, es.start_time 
            FROM exam_schedules s 
            LEFT JOIN exam_slots es ON es.exam_schedule_id = s.id 
            WHERE s.id = ? LIMIT 1
        ";
        $pStmt = $conn->prepare($pSql);
        $pStmt->bind_param('i', $myPreference);
        $pStmt->execute();
        $pRes = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();
        
        if ($pRes) {
            $isPast = false;
            if ($pRes['status'] === 'provisional' && !empty($pRes['start_time'])) {
                $examDateTimeStr = $pRes['exam_date'] . ' ' . $pRes['start_time'];
                if (strtotime($examDateTimeStr) <= $cutoffTime) {
                    $isPast = true;
                }
            } elseif (strtotime($pRes['exam_date']) < strtotime(date('Y-m-d'))) {
                $isPast = true;
            }

            if ($pRes['status'] === 'cancelled' || $pRes['is_closed'] == 1 || $isPast) {
                $preferenceFailed = true;
                $myPreference = 0; // Clear it so they can pick a new one
            }
        } else {
            $preferenceFailed = true;
            $myPreference = 0;
        }
    }

    $cutoffString = date('Y-m-d H:i:s', $cutoffTime);

    // Get all open provisional slots with their candidate counts
    $sql = "
        SELECT
            s.id AS schedule_id,
            s.exam_date,
            s.is_closed,
            es.id AS slot_id,
            es.start_time,
            es.end_time,
            es.capacity,
            COUNT(e.id) AS preference_count,
            COALESCE(st.batch_threshold, 100) AS threshold
        FROM exam_schedules s
        JOIN exam_slots es ON es.exam_schedule_id = s.id
        JOIN batches b ON b.id = s.batch_id
        LEFT JOIN enrollments e ON e.provisional_schedule_id = s.id
            AND e.eligibility_status = 'eligible'
            AND e.batch_id IS NULL
        LEFT JOIN (
            SELECT setting_value AS batch_threshold
            FROM settings WHERE setting_key = 'batch_threshold' LIMIT 1
        ) st ON 1=1
        WHERE s.status = 'provisional'
          AND b.assessment_id = ?
          AND s.is_closed = 0
          AND CONCAT(s.exam_date, ' ', es.start_time) > ?
        GROUP BY s.id, es.id, st.batch_threshold
        ORDER BY s.exam_date ASC, es.start_time ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $assessmentId, $cutoffString);
    $stmt->execute();
    $res = $stmt->get_result();
    $slots = [];
    while ($row = $res->fetch_assoc()) {
        $count = (int)$row['preference_count'];
        $threshold = (int)($row['threshold'] ?? 100);
        $slots[] = [
            'schedule_id'      => (int)$row['schedule_id'],
            'slot_id'          => (int)$row['slot_id'],
            'exam_date'        => $row['exam_date'],
            'start_time'       => $row['start_time'],
            'end_time'         => $row['end_time'],
            'preference_count' => $count,
            'threshold'        => $threshold,
            'percentage'       => min(100, round(($count / max(1,$threshold)) * 100)),
            'is_my_preference' => ($myPreference > 0 && $myPreference === (int)$row['schedule_id']),
            'is_closed'        => (bool)$row['is_closed'],
        ];
    }
    $stmt->close();

    // Check if candidate already has a confirmed batch (enrolled in a scheduled batch)
    $batchAlready = null;
    if (!empty($enrollment['batch_id'])) {
        $bsql = "SELECT b.batch_number, s.exam_date, es.start_time, es.end_time
                 FROM batches b
                 JOIN exam_schedules s ON s.batch_id = b.id
                 JOIN exam_slots es ON es.exam_schedule_id = s.id
                 WHERE b.id = ?
                 LIMIT 1";
        $bs = $conn->prepare($bsql);
        $bs->bind_param('i', (int)$enrollment['batch_id']);
        $bs->execute();
        $batchAlready = $bs->get_result()->fetch_assoc();
        $bs->close();
    }

    // Check if candidate has recent batch_not_formed notification
    $notifStmt = $conn->prepare("
        SELECT id, message, created_at, is_read 
        FROM notifications 
        WHERE candidate_id = ? AND type = 'batch_not_formed'
        ORDER BY id DESC LIMIT 1
    ");
    $notifStmt->bind_param("i", $candidateId);
    $notifStmt->execute();
    $batchNotFormedNotif = $notifStmt->get_result()->fetch_assoc();
    $notifStmt->close();

    send_json_response('success', 'Slots retrieved', [
        'slots'                  => $slots,
        'my_preference_id'       => $myPreference ?: null,
        'preference_failed'      => $preferenceFailed || ($batchNotFormedNotif && !$batchNotFormedNotif['is_read']),
        'batch_not_formed_alert' => $batchNotFormedNotif ? [
            'message' => $batchNotFormedNotif['message'],
            'created_at' => $batchNotFormedNotif['created_at'],
            'is_read' => (bool)$batchNotFormedNotif['is_read']
        ] : null,
        'batch_assigned'         => $batchAlready,
        'is_eligible'            => ($enrollment['eligibility_status'] === 'eligible'),
    ], 200);

} catch (Throwable $e) {
    error_log('public_slots error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}
