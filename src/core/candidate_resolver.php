<?php
// Path: src/core/candidate_resolver.php

function demo_mode(): bool {
    return ($_ENV['M4_DEMO_MODE'] ?? '0') === '1';
}

/**
 * Validate active candidate session with 60s cache TTL.
 * Returns candidate_id (int) if valid and active, or null if invalid/deactivated.
 * On deactivation/invalidation, fully destroys the session.
 */
function validate_candidate_session(?mysqli $conn = null): ?int {
    $hasSession = isset($_SESSION['candidate_id']) || isset($_SESSION['user_id']);
    if (!$hasSession) {
        return null;
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            $conn = get_db_connection();
        }
    }

    $now = time();
    $lastCheck = $_SESSION['candidate_checked_at'] ?? 0;

    // Path 1: candidate_id is cached and active status checked within 60s TTL
    if (
        isset($_SESSION['candidate_id']) &&
        ctype_digit((string)$_SESSION['candidate_id']) &&
        ($now - $lastCheck) < 60
    ) {
        return (int)$_SESSION['candidate_id'];
    }

    // Path 2: Cache miss or 60s TTL expired — re-validate is_active from DB
    $candidateId = (isset($_SESSION['candidate_id']) && ctype_digit((string)$_SESSION['candidate_id']))
        ? (int)$_SESSION['candidate_id']
        : null;
    $userId = (isset($_SESSION['user_id']) && ctype_digit((string)$_SESSION['user_id']))
        ? (int)$_SESSION['user_id']
        : null;

    $user = null;
    if ($candidateId !== null && $candidateId > 0 && $userId !== null && $userId > 0) {
        $stmt = $conn->prepare(
            'SELECT c.id AS candidate_id, c.user_id, u.is_active
             FROM candidates c
             JOIN users u ON u.id = c.user_id
             WHERE c.id = ? AND c.user_id = ?
             LIMIT 1'
        );
        $stmt->bind_param('ii', $candidateId, $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif ($candidateId !== null && $candidateId > 0) {
        $stmt = $conn->prepare(
            'SELECT c.id AS candidate_id, c.user_id, u.is_active
             FROM candidates c
             JOIN users u ON u.id = c.user_id
             WHERE c.id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $candidateId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif ($userId !== null && $userId > 0) {
        $stmt = $conn->prepare(
            'SELECT c.id AS candidate_id, c.user_id, u.is_active
             FROM candidates c
             JOIN users u ON u.id = c.user_id
             WHERE u.id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($user && (int)$user['is_active'] === 1 && !empty($user['candidate_id'])) {
        $_SESSION['candidate_id']        = (int)$user['candidate_id'];
        $_SESSION['user_id']             = (int)$user['user_id'];
        $_SESSION['candidate_checked_at'] = $now;
        return (int)$user['candidate_id'];
    }

    // Do not destroy the session if this is an admin/staff request 
    // (admins are not candidates, so a missing candidate row is expected)
    $role = $_SESSION['role'] ?? '';

    // Self-sufficient fallback: If role isn't populated in session (e.g. non-standard login path), 
    // fetch it defensively before deciding to wipe the session.
    if ($role === '' && isset($_SESSION['user_id']) && function_exists('resolve_admin_role')) {
        $role = resolve_admin_role($conn) ?? '';
    }

    if ($role === 'admin' || $role === 'staff') {
        return null;
    }

    // Deactivated or not found in DB: fully destroy session so user_id doesn't linger
    destroy_session();
    return null;
}

function resolve_candidate_id(array $input = []): int {
    global $conn;

    $hasSession = isset($_SESSION['candidate_id']) || isset($_SESSION['user_id']);

    if ($hasSession) {
        $cid = validate_candidate_session($conn);
        if ($cid !== null) {
            return $cid;
        }

        // Deactivated or invalid: session was destroyed by validate_candidate_session()
        send_json_response('error', 'Candidate authentication/session is required.', null, 401);
        exit;
    }

    // FAIL-SAFE GUARD: Require a dedicated secret to prevent accidental impersonation if APP_ENV is misconfigured
    $expectedSecret = (string)env_value('M4_DEMO_SECRET', '');
    $providedSecret = $input['demo_secret'] ?? $_GET['demo_secret'] ?? '';

    if (
        !$hasSession && 
        demo_mode() && 
        is_dev_env() && 
        $expectedSecret !== '' && 
        hash_equals($expectedSecret, $providedSecret)
    ) {
        $cid = $input['candidate_id'] ?? $_GET['candidate_id'] ?? 1;
        if (ctype_digit((string)$cid)) return (int)$cid;
    }

    send_json_response('error', 'Candidate authentication/session is required.', null, 401);
    exit;
}

/**
 * Shared candidate authentication helper for exam endpoints.
 * Enforces active candidate status via validate_candidate_session(), which validates
 * users.is_active on a 60-second cache TTL and destroys the session upon deactivation.
 * Sends 401 response and exits if candidate is unauthenticated, deactivated, or missing.
 */
function require_candidate_auth(?mysqli $conn = null): int {
    if ($conn === null) {
        global $conn;
    }

    $candidateId = validate_candidate_session($conn);
    if ($candidateId === null) {
        send_json_response('error', 'Candidate authentication required', null, 401);
    }

    return $candidateId;
}


