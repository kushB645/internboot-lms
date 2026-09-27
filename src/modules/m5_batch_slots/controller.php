<?php
// Path: src/modules/m5_batch_slots/controller.php

require_once __DIR__ . '/service.php';
require_once __DIR__ . '/../../core/candidate_resolver.php';

/**
 * Validates and parses a positive integer (> 0).
 * Returns null if input is malformed, array, non-numeric string, zero, or negative.
 */
function parse_positive_int($val): ?int {
    if (is_int($val)) {
        return $val > 0 ? $val : null;
    }
    if (is_string($val) && preg_match('/^[1-9]\d*$/', trim($val))) {
        return (int)$val;
    }
    return null;
}

/**
 * Controller handler for reserving an exam slot for an eligible candidate.
 * Matches API Contract: POST /api/slots/book.php
 *
 * Security (IDOR Protection):
 * Reads candidate identity from $_SESSION['candidate_id'] set at login by M3.
 * Rejects with 403 if candidate_id in payload conflicts with authenticated session.
 */
function handle_book_slot_request(array $input, mysqli $conn): void {
    require_csrf();
    $role = resolve_admin_role($conn);
    $sessionCandidateId = validate_candidate_session($conn);

    $bodyCandidateId = null;
    if (array_key_exists('candidate_id', $input) && $input['candidate_id'] !== null) {
        $parsed = parse_positive_int($input['candidate_id']);
        if ($parsed === null) {
            send_json_response('error', 'A valid candidate_id is required', null, 400);
            return;
        }
        $bodyCandidateId = $parsed;
    }

    // 1. IDOR Authentication & Session Cross-Check
    if ($sessionCandidateId !== null) {
        if ($bodyCandidateId !== null && $bodyCandidateId !== $sessionCandidateId) {
            send_json_response('error', 'Forbidden: candidate_id does not match authenticated session', null, 403);
            return;
        }
        $candidateId = $sessionCandidateId;
    } elseif ($role === 'admin') {
        if ($bodyCandidateId === null) {
            send_json_response('error', 'A valid candidate_id is required', null, 400);
            return;
        }
        $candidateId = $bodyCandidateId;
    } else {
        // No authenticated session found
        send_json_response('error', 'Unauthorized: candidate authentication session required', null, 401);
        return;
    }

    // 2. Strict Input Validation Checks
    if (!array_key_exists('assessment_id', $input) || $input['assessment_id'] === null) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
        return;
    }
    $assessmentId = parse_positive_int($input['assessment_id']);
    if ($assessmentId === null) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
        return;
    }

    if (!array_key_exists('exam_slot_id', $input) || $input['exam_slot_id'] === null) {
        send_json_response('error', 'A valid exam_slot_id is required', null, 400);
        return;
    }
    $examSlotId = parse_positive_int($input['exam_slot_id']);
    if ($examSlotId === null) {
        send_json_response('error', 'A valid exam_slot_id is required', null, 400);
        return;
    }

    try {
        $bookingData = book_exam_slot($candidateId, $assessmentId, $examSlotId, $conn);
        send_json_response('success', 'Exam slot booked successfully', $bookingData, 200);
        return;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Mask internal database / SQL errors to avoid leaking schema names
        if ($e instanceof mysqli_sql_exception || str_contains($msg, "Table '") || str_contains($msg, "doesn't exist") || str_contains($msg, 'SQLSTATE') || str_contains($msg, 'Failed to prepare') || str_contains($msg, 'Database')) {
            error_log("Database error in slot booking: " . $msg);
            send_json_response('error', is_dev_env() ? $msg : 'An internal server error occurred.', null, 500);
            return;
        }
        if (stripos($msg, 'already has a booked slot') !== false || stripos($msg, 'fully booked') !== false) {
            send_json_response('error', $msg, null, 409);
            return;
        }
        if (stripos($msg, 'not eligible') !== false) {
            send_json_response('error', $msg, null, 403);
            return;
        }
        error_log("Slot booking error: " . $msg);
        send_json_response('error', is_dev_env() ? $msg : 'An internal server error occurred.', null, 400);
        return;
    }
}

/**
 * Controller handler for batch threshold check and automatic batch creation.
 * Matches API Contract: POST /api/slots/auto_batch.php
 *
 * Security: Enforces Admin role access (strictly admin, staff excluded).
 */
function handle_auto_batch_request(array $input, mysqli $conn): void {
    require_csrf();
    // RBAC Security Check: Strictly Admin Access Only
    $role = resolve_admin_role($conn);
    if ($role !== 'admin') {
        send_json_response('error', 'Unauthorized: admin access required', null, 403);
        return;
    }

    if (!array_key_exists('assessment_id', $input) || $input['assessment_id'] === null) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
        return;
    }
    $assessmentId = parse_positive_int($input['assessment_id']);
    if ($assessmentId === null) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
        return;
    }

    $customThreshold = null;
    if (array_key_exists('threshold', $input) && $input['threshold'] !== null) {
        $customThreshold = parse_positive_int($input['threshold']);
        if ($customThreshold === null) {
            send_json_response('error', 'Threshold must be a positive integer', null, 400);
            return;
        }
    }

        try {
        if (array_key_exists('schedule_id', $input) && $input['schedule_id'] !== null) {
            $scheduleId = parse_positive_int($input['schedule_id']);
            if ($scheduleId === null) {
                send_json_response('error', 'A valid schedule_id is required', null, 400);
                return;
            }
            $result = finalize_provisional_batch($scheduleId, $assessmentId, $conn);
            send_json_response('success', 'Batch finalized and candidates assigned', $result, 201);
            return;
        }

        $batches = create_all_eligible_batches($assessmentId, $conn, $customThreshold);

        if (!empty($batches)) {
            $firstBatch = $batches[0];
            $threshold = get_batch_threshold($conn, $customThreshold);
            $eligibleCount = get_unbatched_eligible_count($assessmentId, $conn);

            // Backward-compatible unified payload: flat properties + batches array
            $responseData = array_merge($firstBatch, [
                'assessment_id' => $assessmentId,
                'batches_formed' => count($batches),
                'batches' => $batches,
                'remaining_eligible_count' => $eligibleCount,
                'threshold' => $threshold
            ]);

            send_json_response('success', count($batches) . ' batch(es) and exam schedules formed successfully', $responseData, 201);
            return;
        } else {
            $threshold = get_batch_threshold($conn, $customThreshold);
            $eligibleCount = get_unbatched_eligible_count($assessmentId, $conn);
            $data = [
                'assessment_id' => $assessmentId,
                'eligible_count' => $eligibleCount,
                'threshold' => $threshold,
                'batch_formed' => false,
                'needed_to_form_batch' => max(0, $threshold - $eligibleCount)
            ];
            send_json_response('success', 'Candidate count below threshold. Batch not formed yet.', $data, 200);
            return;
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        error_log("Error in auto batch: " . $msg);
        send_json_response('error', is_dev_env() ? $msg : 'An internal server error occurred.', null, 500);
        return;
    }
}

/**
 * Controller handler for listing available exam slots.
 * Matches API Contract: GET /api/slots/available.php
 */
function handle_list_slots_request(array $input, mysqli $conn): void {
    $role = resolve_admin_role($conn);
    $sessionCandidateId = validate_candidate_session($conn);

    if ($sessionCandidateId === null && $role !== 'admin') {
        send_json_response('error', 'Unauthorized: candidate authentication required', null, 401);
        return;
    }

    if (!array_key_exists('assessment_id', $input) || $input['assessment_id'] === null) {
        send_json_response('error', 'A valid assessment_id parameter is required', null, 400);
        return;
    }
    $assessmentId = parse_positive_int($input['assessment_id']);
    if ($assessmentId === null) {
        send_json_response('error', 'A valid assessment_id parameter is required', null, 400);
        return;
    }

    $queryCandidateId = null;
    if (array_key_exists('candidate_id', $input) && $input['candidate_id'] !== null && $input['candidate_id'] !== '') {
        $queryCandidateId = parse_positive_int($input['candidate_id']);
        if ($queryCandidateId === null) {
            send_json_response('error', 'A valid candidate_id parameter is required', null, 400);
            return;
        }
    }

    // IDOR Security Check on candidate scoping
    if ($queryCandidateId !== null) {
        if ($sessionCandidateId !== null) {
            if ($queryCandidateId !== $sessionCandidateId && $role !== 'admin') {
                send_json_response('error', 'Forbidden: cannot view slots for another candidate', null, 403);
                return;
            }
            $candidateId = $queryCandidateId;
        } elseif ($role === 'admin') {
            $candidateId = $queryCandidateId;
        } else {
            send_json_response('error', 'Unauthorized: candidate authentication required', null, 401);
            return;
        }
    } else {
        $candidateId = $sessionCandidateId;
    }

    try {
        $slots = fetch_available_slots($assessmentId, $candidateId, $conn);
        send_json_response('success', 'Available exam slots retrieved successfully', $slots, 200);
        return;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        error_log("Error listing available slots: " . $msg);
        send_json_response('error', 'An internal server error occurred.', null, 500);
        return;
    }
}

/**
 * Controller handler for cancelling a candidate's booked exam slot.
 * Matches API Contract: POST /api/slots/cancel.php
 */
function handle_cancel_slot_booking_request(array $input, mysqli $conn): void {
    require_csrf();
    $role = resolve_admin_role($conn);
    $sessionCandidateId = validate_candidate_session($conn);

    $bodyCandidateId = null;
    if (array_key_exists('candidate_id', $input) && $input['candidate_id'] !== null) {
        $parsed = parse_positive_int($input['candidate_id']);
        if ($parsed === null) {
            send_json_response('error', 'A valid candidate_id is required', null, 400);
            return;
        }
        $bodyCandidateId = $parsed;
    }

    // 1. IDOR Authentication & Session Cross-Check
    if ($sessionCandidateId !== null) {
        if ($bodyCandidateId !== null && $bodyCandidateId !== $sessionCandidateId) {
            send_json_response('error', 'Forbidden: candidate_id does not match authenticated session', null, 403);
            return;
        }
        $candidateId = $sessionCandidateId;
    } elseif ($role === 'admin') {
        if ($bodyCandidateId === null) {
            send_json_response('error', 'A valid candidate_id is required', null, 400);
            return;
        }
        $candidateId = $bodyCandidateId;
    } else {
        // No authenticated session found
        send_json_response('error', 'Unauthorized: candidate authentication session required', null, 401);
        return;
    }

    // 2. Strict Input Validation Checks
    if (!array_key_exists('assessment_id', $input) || $input['assessment_id'] === null) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
        return;
    }
    $assessmentId = parse_positive_int($input['assessment_id']);
    if ($assessmentId === null) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
        return;
    }

    try {
        $cancelled = cancel_slot_booking($candidateId, $assessmentId, $conn);
        if ($cancelled) {
            send_json_response('success', 'Slot booking cancelled successfully', null, 200);
        } else {
            send_json_response('error', 'No active booking found to cancel', null, 404);
        }
        return;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Mask internal database / SQL errors to avoid leaking schema details
        if ($e instanceof mysqli_sql_exception || str_contains($msg, "Table '") || str_contains($msg, "doesn't exist") || str_contains($msg, 'SQLSTATE') || str_contains($msg, 'Failed to prepare') || str_contains($msg, 'Database')) {
            error_log("Database error in slot cancellation: " . $msg);
            send_json_response('error', is_dev_env() ? $msg : 'An internal server error occurred.', null, 500);
            return;
        }
        if (stripos($msg, 'already started') !== false || stripos($msg, 'cannot be cancelled') !== false) {
            send_json_response('error', $msg, null, 400);
            return;
        }
        if (stripos($msg, 'No active booking') !== false) {
            send_json_response('error', $msg, null, 404);
            return;
        }
        error_log("Error cancelling slot booking: " . $msg);
        send_json_response('error', $msg, null, 400);
        return;
    }
}
