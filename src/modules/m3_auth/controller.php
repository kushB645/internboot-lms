<?php
// Path: src/modules/m3_auth/controller.php

require_once __DIR__ . '/service.php';

/**
 * Handles POST /api/auth/register.php
 */
function handle_register_request(array $data, mysqli $conn): void {
    $ipAddress = get_client_ip();
    if (check_registration_rate_limit($conn, $ipAddress)) {
        if (!headers_sent()) {
            header('Retry-After: 900');
        }
        send_json_response('error', 'Too many registration attempts. Please try again later.', null, 429);
    }

    record_registration_attempt($conn, $ipAddress);

    $fullName = sanitize_string($data['full_name'] ?? '');
    $email    = sanitize_string($data['email'] ?? '');
    $phone    = sanitize_string($data['phone'] ?? '');
    $password = (string) ($data['password'] ?? '');
    $confirm  = (string) ($data['confirm_password'] ?? '');
    $role     = 'candidate';

    if ($fullName === '' || $email === '' || $phone === '' || $password === '') {
        send_json_response('error', 'All fields are required.', null, 422);
    }
    if (!is_valid_full_name($fullName)) {
        send_json_response('error', 'Full name must be under 100 characters, start with a letter, and contain only valid characters.', null, 422);
    }
    if (!is_valid_email($email)) {
        send_json_response('error', 'Enter a valid email address.', null, 422);
    }
    if (!preg_match('/^\+\d{1,3}\d{6,14}$/', $phone)) {
        send_json_response('error', 'Enter a valid international phone number.', null, 422);
    }
    if (strlen($password) < 8) {
        send_json_response('error', 'Password must be at least 8 characters.', null, 422);
    }
    if ($password !== $confirm) {
        send_json_response('error', 'Passwords do not match.', null, 422);
    }

    $result = initiate_registration($conn, $fullName, $email, $phone, $password, 'candidate');

    if (!$result['success']) {
        send_json_response('error', $result['message'], null, $result['code'] ?? 409);
    }

    send_json_response('success', $result['message'] ?? 'Verification code sent to your email.', ['email' => $email], 200);
}

function handle_verify_otp_request(array $data, mysqli $conn): void {
    $email = sanitize_string($data['email'] ?? '');
    $otp   = sanitize_string($data['otp'] ?? '');

    if ($email === '' || $otp === '') {
        send_json_response('error', 'Email and verification code are required.', null, 422);
    }

    $result = complete_registration_with_otp($conn, $email, $otp);

    if (!$result['success']) {
        send_json_response('error', $result['message'], null, $result['code'] ?? 400);
    }

    send_json_response('success', 'Registration successful. Please log in.', ['user_id' => $result['user_id']], 201);
}

function handle_resend_otp_request(array $data, mysqli $conn): void {
    $email = sanitize_string($data['email'] ?? '');

    if ($email === '') {
        send_json_response('error', 'Email is required.', null, 422);
    }

    $pending = find_latest_pending_verification($conn, $email);

    if (!$pending) {
        send_json_response('success', 'A new verification code has been sent.', null, 200);
    }

    $age = isset($pending['age_seconds']) ? (int)$pending['age_seconds'] : 0;
    if ($age < OTP_RESEND_COOLDOWN_SECONDS) {
        $remaining = OTP_RESEND_COOLDOWN_SECONDS - $age;
        if (!headers_sent()) {
            header('Retry-After: ' . $remaining);
        }
        send_json_response('error', "Please wait {$remaining} seconds before requesting a new code.", null, 429);
    }

    $resendCount = (int)($pending['resend_count'] ?? 0);
    if ($resendCount >= OTP_MAX_RESENDS) {
        send_json_response('error', 'Resend limit reached. Please register again later.', null, 429);
    }

    $otp = (string) random_int(100000, 999999);
    $saved = save_pending_registration($conn, $email, $otp, $pending['full_name'], $pending['phone'], $pending['password_hash'], $pending['role'], $resendCount + 1);

    if (!$saved) {
        error_log('Failed to save resend OTP registration for ' . $email);
        send_json_response('success', 'A new verification code has been sent.', null, 200);
    }

    try {
        require_once __DIR__ . '/../../core/Mailer.php';
        $sent = send_otp_email($email, $pending['full_name'], $otp);
        if (!$sent) {
            error_log('Could not send verification email to ' . $email);
        }
    } catch (Throwable $e) {
        error_log('Resend OTP mail error for ' . $email . ': ' . $e->getMessage());
    }

    send_json_response('success', 'A new verification code has been sent.', null, 200);
}

function handle_login_request(array $data, mysqli $conn): void {
    $email        = sanitize_string($data['email'] ?? '');
    $password     = (string) ($data['password'] ?? '');
    $expectedRole = sanitize_string($data['role'] ?? '');
    $ipAddress    = get_client_ip();

    if ($email === '' || $password === '') {
        send_json_response('error', 'Email and password are required.', null, 422);
    }
    if (!is_valid_email($email)) {
        send_json_response('error', 'Enter a valid email address.', null, 422);
    }

    if (check_login_rate_limit($conn, $email, $ipAddress)) {
        send_json_response('error', 'Too many failed login attempts. Please try again in 15 minutes.', null, 429);
    }

    $result = authenticate_candidate($conn, $email, $password);

    if (!$result['success']) {
        record_failed_login($conn, $email, $ipAddress);
        send_json_response('error', $result['message'], null, 401);
    }

    $dbRole = $result['user']['role'];
    if ($expectedRole === 'admin') {
        $roleMatch = in_array($dbRole, ['admin', 'staff'], true);
    } elseif ($expectedRole === 'candidate') {
        $roleMatch = ($dbRole === 'candidate');
    } else {
        $roleMatch = ($expectedRole === '' || $expectedRole === $dbRole);
    }

    if (!$roleMatch) {
        record_failed_login($conn, $email, $ipAddress);
        $label = $expectedRole === 'admin' ? 'an Admin' : 'a Student';
        send_json_response('error', "This account is not registered as {$label}.", null, 403);
    }

    clear_failed_logins($conn, $email, $ipAddress);

    session_regenerate_id(true);

    $_SESSION['user_id']      = $result['user']['id'];
    $_SESSION['role']         = $result['user']['role'];
    $_SESSION['full_name']    = $result['user']['full_name'] ?? '';

    if ($result['user']['role'] === 'candidate' && empty($result['user']['candidate_id'])) {
        error_log("Data integrity issue: candidate-role user {$result['user']['id']} has no candidates row");
    }
    $_SESSION['candidate_id'] = $result['user']['candidate_id'] ?? null;
    $_SESSION['candidate_checked_at'] = time();
    $_SESSION['role_checked_at']      = time();

    $redirectUrl = in_array($result['user']['role'], ['admin', 'staff'], true) ? '/admin/index.html' : '/dashboard.html';

    send_json_response('success', 'Login successful.', [
        'role'     => $result['user']['role'],
        'redirect' => $redirectUrl,
    ], 200);
}




/**
 * Handles POST /api/auth/logout.php
 */
function handle_logout_request(): void {
    destroy_session();
    send_json_response('success', 'Logged out successfully.', ['redirect' => '/login.php'], 200);
}


/**
 * Handles POST /api/admin/create-staff.php
 * Admin-authenticated staff creation path.
 */
function handle_create_staff_request(array $data, mysqli $conn): void {
    require_admin_only($conn);
    require_csrf();

    $fullName = sanitize_string($data['full_name'] ?? '');
    $email    = sanitize_string($data['email'] ?? '');
    $phone    = sanitize_string($data['phone'] ?? '');
    $password = (string) ($data['password'] ?? '');
    $confirm  = (string) ($data['confirm_password'] ?? '');
    $role     = sanitize_string($data['role'] ?? 'staff');

    if (!in_array($role, ['admin', 'staff'], true)) {
        send_json_response('error', 'Role must be either admin or staff.', null, 422);
        return;
    }

    if ($fullName === '' || $email === '' || $password === '' || $confirm === '') {
        send_json_response('error', 'All fields are required.', null, 422);
        return;
    }
    if (!is_valid_full_name($fullName)) {
        send_json_response('error', 'Full name must be under 100 characters, start with a letter, and contain only valid characters.', null, 422);
        return;
    }
    if (!is_valid_email($email)) {
        send_json_response('error', 'Enter a valid email address.', null, 422);
        return;
    }

    if ($phone !== '' && !preg_match('/^[6-9]\d{9}$/', $phone)) {
        send_json_response('error', 'Enter a valid 10-digit phone number.', null, 422);
        return;
    }
    if (strlen($password) < 8) {
        send_json_response('error', 'Password must be at least 8 characters.', null, 422);
        return;
    }
    if ($password !== $confirm) {
        send_json_response('error', 'Passwords do not match.', null, 422);
        return;
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $result = insert_admin_user($conn, $email, $passwordHash, $fullName, $phone, $role);

    if (!$result['success']) {
        send_json_response('error', $result['message'], null, $result['code'] ?? 400);
        return;
    }

    $newUserId = (int)$result['user_id'];
    require_once __DIR__ . '/../m7_evaluation_admin/queries.php';
    if (function_exists('create_admin_log')) {
        create_admin_log(
            $conn,
            $_SESSION['user_id'] ?? null,
            'create_staff',
            json_encode(['new_user_id' => $newUserId, 'email' => $email, 'role' => $role])
        );
    }

    send_json_response('success', 'Staff account created successfully.', [
        'user_id' => $newUserId,
        'email'   => $email,
        'role'    => $role,
    ], 201);
}

function handle_forgot_password_request(array $data, mysqli $conn): void {
    $email = sanitize_string($data['email'] ?? '');
    if (!is_valid_email($email)) {
        send_json_response('error', 'Enter a valid email address.', null, 422);
    }

    $user = find_user_by_email($conn, $email);
    if ($user) {
        $userId = (int)$user['id'];

        // Check cooldown (< 60s since last reset request)
        $stmt = $conn->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $resRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $inCooldown = ($resRow && isset($resRow['age']) && (int)$resRow['age'] < 60);

        // If cooldown is active, skip generating/sending a second email internally to prevent spam,
        // but do NOT return 429 or Retry-After header, so attackers cannot distinguish registered emails.
        if (!$inCooldown) {
            try {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $stmt = $conn->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
                $stmt->bind_param('is', $userId, $tokenHash);
                $stmt->execute();
                $stmt->close();

                require_once __DIR__ . '/../../core/Mailer.php';
                $rawAppUrl = env_value('APP_URL');
                if (empty($rawAppUrl)) {
                    if (!is_dev_env()) {
                        error_log('CRITICAL CONFIG WARNING: APP_URL environment variable is not configured. Password reset links will default to http://localhost:8080 in production!');
                    }
                    $rawAppUrl = 'http://localhost:8080';
                }
                $appUrl = rtrim($rawAppUrl, '/');
                $resetLink = "{$appUrl}/reset-password.php?token={$token}&email=" . urlencode($email);

                $name = $user['full_name'] ?? 'User';
                send_password_reset_email($email, $name, $resetLink);
            } catch (Throwable $e) {
                // Log failure internally but never return 500 to the client, preventing email enumeration via mail failure
                error_log('Password reset processing failed for ' . $email . ': ' . $e->getMessage());
            }
        }
    }

    send_json_response('success', 'If an account exists for this email, a reset link has been sent.', null, 200);
}

function handle_reset_password_request(array $data, mysqli $conn): void {
    $email = sanitize_string($data['email'] ?? '');
    $token = sanitize_string($data['token'] ?? '');
    $password = (string)($data['new_password'] ?? '');
    $confirm = (string)($data['confirm_password'] ?? '');

    if ($email === '' || $token === '' || $password === '' || $confirm === '') {
        send_json_response('error', 'All fields are required.', null, 422);
    }
    
    if (strlen($password) < 8) {
        send_json_response('error', 'Password must be at least 8 characters.', null, 422);
    }
    if ($password !== $confirm) {
        send_json_response('error', 'Passwords do not match.', null, 422);
    }

    $user = find_user_by_email($conn, $email);
    if (!$user) {
        send_json_response('error', 'Invalid or expired reset link.', null, 400);
    }

    $userId = (int)$user['id'];
    
    $stmt = $conn->prepare('SELECT id, token_hash FROM password_resets WHERE user_id = ? AND is_used = 0 AND expires_at > NOW() ORDER BY id DESC');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $resRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $validResetId = null;
    $submittedHash = hash('sha256', $token);

    foreach ($resRows as $row) {
        if (hash_equals($row['token_hash'], $submittedHash)) {
            $validResetId = (int)$row['id'];
            break;
        }
    }

    if (!$validResetId) {
        send_json_response('error', 'Invalid or expired reset link.', null, 400);
    }

    $conn->begin_transaction();
    try {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $newHash, $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE password_resets SET is_used = 1 WHERE user_id = ? AND is_used = 0');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('InternBoot password reset error: ' . $e->getMessage());
        send_json_response('error', 'Could not reset password. Please try again.', null, 500);
    }

    send_json_response('success', 'Password reset successfully. You can now log in.', null, 200);
}
?>
