<?php
date_default_timezone_set('Asia/Kolkata');

$rootDir = dirname(__DIR__, 2);

require_once $rootDir . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/candidate_resolver.php';

$appEnv = get_app_env();

if ($appEnv !== 'development' && demo_mode()) {
    die("FATAL: M4_DEMO_MODE cannot be enabled outside development environments.");
}

$otpPepper = env_value('OTP_PEPPER', '');
if ($appEnv !== 'development' && ($otpPepper === '' || $otpPepper === 'change_this_pepper_in_production')) {
    die("FATAL: OTP_PEPPER must be set to a unique, non-default secret outside development environments.");
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e): void {
    error_log(sprintf("[%s] Unhandled Exception: %s in %s on line %d\nStack trace:\n%s", 
        date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
    
    if (!headers_sent()) {
        send_json_response('error', 'An internal server error occurred.', null, 500);
    }
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    error_log(sprintf("[%s] PHP Error [%d]: %s in %s on line %d", 
        date('Y-m-d H:i:s'), $errno, $errstr, $errfile, $errline));
    return true;
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        error_log(sprintf("[%s] PHP Fatal Error [%d]: %s in %s on line %d", 
            date('Y-m-d H:i:s'), $error['type'], $error['message'], $error['file'], $error['line']));
        
        if (!headers_sent()) {
            if (function_exists('send_json_response')) {
                send_json_response('error', 'An internal server error occurred.', null, 500);
            } else {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'An internal server error occurred.',
                    'data' => null
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => is_request_https()
    ]);
}

/**
 * Send standard security headers site-wide.
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Strict-Transport-Security: only over HTTPS in production
    $appEnv = function_exists('get_app_env') ? get_app_env() : 'production';
    $isHttps = is_request_https();

    if ($appEnv === 'production' && $isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.tailwindcss.com https://unpkg.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com data:; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
    header('Content-Security-Policy: ' . $csp);
}

send_security_headers();

class AdminAccessDeniedException extends RuntimeException {}

function resolve_admin_role(mysqli $conn): ?string
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        return null;
    }

    $now = time();
    $lastCheck = $_SESSION['role_checked_at'] ?? 0;

    // Cache hit and within 60s TTL
    if (($now - $lastCheck) < 60 && !empty($_SESSION['role'])) {
        return $_SESSION['role'];
    }

    // Cache miss or TTL expired: check DB
    $stmt = $conn->prepare('SELECT role, is_active FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($user && (int)$user['is_active'] === 1) {
        $_SESSION['role'] = $user['role'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['role_checked_at'] = $now;
        return $user['role'];
    }

    // Deactivated or not found: fully destroy session so user_id doesn't linger
    destroy_session();
    return null;
}

function is_admin_authenticated(mysqli $conn): bool
{
    $role = resolve_admin_role($conn);
    return in_array($role, ['admin', 'staff'], true);
}

function require_admin_access(mysqli $conn): void
{
    if (is_admin_authenticated($conn)) return;

    send_json_response('error', 'Administrator access required.', null, 403);
}

function require_admin_access_or_throw(mysqli $conn, string $message = 'Administrator access required.'): void
{
    if (is_admin_authenticated($conn)) return;

    throw new AdminAccessDeniedException($message);
}

function require_admin_only(mysqli $conn): void
{
    $role = resolve_admin_role($conn);
    if ($role === 'admin') return;

    send_json_response('error', 'Admin-only action. Your account role does not have permission.', ['reason' => 'admin_only'], 403);
}



function csrf_token(): string
{
    if (empty($_SESSION['m7_csrf'])) {
        $_SESSION['m7_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['m7_csrf'];
}

function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['m7_csrf'] ?? '';
    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        send_json_response('error', 'Invalid or missing security token. Refresh the page and try again.', null, 419);
    }
}
