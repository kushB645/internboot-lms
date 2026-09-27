<?php

// Load central bootstrap
if (file_exists(dirname(__DIR__, 3) . '/src/core/bootstrap.php')) {
    require_once dirname(__DIR__, 3) . '/src/core/bootstrap.php';
} elseif (file_exists(__DIR__ . '/../../../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../../../src/core/bootstrap.php';
} else {
    require_once __DIR__ . '/../src/core/bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'Method not allowed. Use POST.', null, 405);
}

require_csrf();

try {

    /*
     * 1. Candidate authentication
     */
    $candidateId = require_candidate_auth($conn);

    /*
     * 2. Attempt ID supplied by M5
     */
    $rawInput = file_get_contents('php://input');
    $body = json_decode($rawInput, true);
    if (!is_array($body)) {
        $body = [];
    }

    $attemptId = (int)($_POST['attempt_id'] ?? $body['attempt_id'] ?? $_GET['attempt_id'] ?? 0);

    if ($attemptId <= 0) {
        send_json_response('error', 'Invalid attempt ID', null, 400);
    }

    /*
     * 3. Fetch attempt + official slot timing + assessment duration.
     *
     * Ownership is checked using:
     *     a.id = ?
     *     a.candidate_id = ?
     *
     * This prevents IDOR/tampering.
     */
    $sql = "
        SELECT
            a.id AS attempt_id,
            a.candidate_id,
            a.assessment_id,
            a.exam_slot_id,
            a.status,
            a.start_time,
            a.end_time,
            a.submitted_at,

            es.start_time AS slot_start_time,
            es.end_time AS slot_end_time,

            sch.exam_date,
            sch.status AS schedule_status,

            ass.duration_minutes,
            ass.total_questions

        FROM attempts a

        LEFT JOIN exam_slots es
            ON es.id = a.exam_slot_id

        LEFT JOIN exam_schedules sch
            ON sch.id = es.exam_schedule_id

        LEFT JOIN assessments ass
            ON ass.id = a.assessment_id

        WHERE a.id = ?
          AND a.candidate_id = ?

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Failed to prepare attempt query');
    }

    $stmt->bind_param(
        "ii",
        $attemptId,
        $candidateId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $attempt = $result->fetch_assoc();

    $stmt->close();

    /*
     * 4. Attempt must exist and belong to logged-in candidate.
     */
    if (!$attempt) {
        send_json_response('error', 'Attempt not found or access denied', null, 404);
    }

    /*
     * 5. Completed attempts cannot be restarted.
     */
    if (
        $attempt['status'] === 'submitted' ||
        $attempt['status'] === 'expired'
    ) {
        send_json_response('error', 'This attempt is no longer available', [
            'status' => $attempt['status']
        ], 403);
    }

    /*
     * 5a. Schedule Status Gate: Only active schedules (scheduled/in_progress) can be started or resumed.
     */
    $validScheduleStatuses = ['scheduled', 'in_progress'];
    if (!empty($attempt['schedule_status']) && !in_array($attempt['schedule_status'], $validScheduleStatuses, true)) {
        $msg = ($attempt['schedule_status'] === 'cancelled')
            ? 'This exam schedule has been cancelled'
            : 'This exam schedule is no longer active';
        send_json_response('error', $msg, [
            'schedule_status' => $attempt['schedule_status']
        ], 409);
    }

    /*
     * 5b. Scheduled Date and Time-Window Gate (First Start Only)
     * Restrict exam access to the assigned candidate, date, and slot window.
     */
    if (empty($attempt['start_time'])) {
        $now = new DateTime();
        $today = $now->format('Y-m-d');

        $examDate = !empty($attempt['exam_date']) ? $attempt['exam_date'] : null;
        $slotStartTime = !empty($attempt['slot_start_time']) ? $attempt['slot_start_time'] : null;
        $slotEndTime = !empty($attempt['slot_end_time']) ? $attempt['slot_end_time'] : null;

        if ($examDate !== null) {
            if ($examDate > $today) {
                send_json_response('error', "Your exam is scheduled for {$examDate}. This assessment is not yet active.", null, 403);
            }
            if ($examDate < $today) {
                send_json_response('error', "Your scheduled exam window has passed.", null, 403);
            }

            if ($slotStartTime !== null && $slotEndTime !== null) {
                $slotStart = new DateTime($examDate . ' ' . $slotStartTime);
                $grace = (int) get_setting_value('slot_grace_minutes', $conn);
                if ($grace <= 0) $grace = 30;
                $slotEnd = (new DateTime())->setTimestamp(
                    strtotime($examDate . ' ' . $slotEndTime) + ($grace * 60)
                );

                if ($now < $slotStart) {
                    send_json_response('error', "Your exam slot opens at {$slotStartTime}.", null, 403);
                }
                if ($now > $slotEnd) {
                    send_json_response('error', "Your exam slot has closed.", null, 403);
                }
            }
        }
    }

    /*
     * 6. If attempt has not started yet,
     *    M6 starts it using SERVER time.
     *
     *    M5's integration document explicitly specifies
     *    server NOW() as the start time.
     */
    if (empty($attempt['start_time'])) {

        $startTime = date('Y-m-d H:i:s');

        /*
         * Assessment duration comes from the assessment.
         * Current M6 requirement = 60 minutes.
         */
        $durationMinutes = (int) $attempt['duration_minutes'];

        if ($durationMinutes <= 0) {
            $durationMinutes = 60;
        }

        $calculatedEnd = strtotime(
            $startTime . " +{$durationMinutes} minutes"
        );

        // Cap actual end_time at slot's scheduled end_time if a slot boundary exists
        if (!empty($attempt['exam_date']) && !empty($attempt['slot_end_time'])) {
            $grace = (int) get_setting_value('slot_grace_minutes', $conn);
            if ($grace <= 0) $grace = 30;
            $slotEndTimestamp = strtotime($attempt['exam_date'] . ' ' . $attempt['slot_end_time']) + ($grace * 60);
            if ($slotEndTimestamp !== false && $slotEndTimestamp < $calculatedEnd) {
                $calculatedEnd = $slotEndTimestamp;
            }
        }

        $endTime = date('Y-m-d H:i:s', $calculatedEnd);

        /*
         * Atomically initialize the attempt.
         */
        $updateSql = "
            UPDATE attempts
            SET
                status = 'in_progress',
                start_time = ?,
                end_time = ?
            WHERE id = ?
              AND candidate_id = ?
              AND status = 'in_progress'
              AND start_time IS NULL
        ";

        $updateStmt = $conn->prepare($updateSql);

        if (!$updateStmt) {
            throw new Exception('Failed to prepare attempt update');
        }

        $updateStmt->bind_param(
            "ssii",
            $startTime,
            $endTime,
            $attemptId,
            $candidateId
        );

        $updateStmt->execute();

        if ($updateStmt->affected_rows === 1) {
            $attempt['start_time'] = $startTime;
            $attempt['end_time'] = $endTime;
        } else {
            // dusri request jeet gayi race — DB se actual values lo
            $refetch = $conn->prepare('SELECT start_time, end_time FROM attempts WHERE id = ? AND candidate_id = ?');
            $refetch->bind_param('ii', $attemptId, $candidateId);
            $refetch->execute();
            $fresh = $refetch->get_result()->fetch_assoc();
            $refetch->close();
            if ($fresh) {
                $attempt['start_time'] = $fresh['start_time'];
                $attempt['end_time'] = $fresh['end_time'];
            }
        }

        $updateStmt->close();
    }

    /*
     * 7. Calculate remaining time using SERVER time.
     */
    $now = new DateTime();

    $end = new DateTime(
        $attempt['end_time']
    );

    $remainingSeconds = max(
        0,
        $end->getTimestamp() - $now->getTimestamp()
    );

    /*
     * 8. Automatically expire if time has ended.
     */
    if ($remainingSeconds <= 0) {

        $expireSql = "
            UPDATE attempts
            SET
                status = 'expired',
                submitted_at = NOW()
            WHERE id = ?
              AND candidate_id = ?
              AND status = 'in_progress'
        ";

        $expireStmt = $conn->prepare($expireSql);

        if (!$expireStmt) {
            throw new Exception('Failed to prepare expiry update');
        }

        $expireStmt->bind_param(
            "ii",
            $attemptId,
            $candidateId
        );

        $expireStmt->execute();
        $expireStmt->close();

        require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/service.php';
        try {
            evaluate_attempt($conn, $attemptId, true);
        } catch (Throwable $evalError) {
            error_log('Auto-evaluation failed for attempt ' . $attemptId . ': ' . $evalError->getMessage());
        }

        send_json_response('error', 'Exam time has expired', [
            'status' => 'expired'
        ], 403);
    }

    /*
     * 9. Successful response using standard helper.
     * Report the actual number of questions served for this attempt, falling back to assessment configuration if unassigned.
     */
    $servedQuestions = function_exists('get_attempt_served_question_count')
        ? get_attempt_served_question_count($conn, $attemptId)
        : 0;
    $totalQuestions = ($servedQuestions > 0)
        ? $servedQuestions
        : (isset($attempt['total_questions']) ? (int) $attempt['total_questions'] : 0);

    send_json_response('success', 'Exam started successfully', [
        'success' => true,
        'attempt_id' => (int) $attempt['attempt_id'],
        'candidate_id' => (int) $attempt['candidate_id'],
        'assessment_id' => (int) $attempt['assessment_id'],
        'exam_slot_id' => (int) $attempt['exam_slot_id'],

        'status' => $attempt['status'],

        'start_time' => $attempt['start_time'],
        'end_time' => $attempt['end_time'],

        'slot_start_time' => $attempt['slot_start_time'],
        'slot_end_time' => $attempt['slot_end_time'],
        'exam_date' => $attempt['exam_date'],

        'duration_minutes' => (int) $attempt['duration_minutes'],
        'total_questions' => $totalQuestions,

        'remaining_seconds' => $remainingSeconds
    ], 200);

} catch (Throwable $e) {
    error_log('start_exam error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}