<?php
if (!function_exists('env_value')) {
    function env_value(string $key, ?string $default = null): ?string {
        $val = getenv($key);
        if ($val !== false) return $val;
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}
/**
 * src/core/helpers.php
 *
 * Shared utility helpers used across all modules.
 */

/**
 * Check whether an IP address falls within a given CIDR range or matches an exact IP.
 * Supports IPv4 and IPv6, including CIDR subnets (e.g. 10.0.0.0/8, 172.16.0.0/12, ::1).
 *
 * @param string $ip     The IP address to check.
 * @param string $range  An exact IP address or CIDR notation (e.g. 192.168.0.0/16).
 * @return bool True if $ip matches or falls inside $range.
 */
function ip_in_range(string $ip, string $range): bool
{
    $ip = trim($ip);
    $range = trim($range);

    if ($range === '' || $ip === '') {
        return false;
    }

    if (str_starts_with(strtolower($ip), '::ffff:')) {
        $ip = substr($ip, 7);
    }

    if ($ip === $range) {
        return true;
    }

    if (str_contains($range, '/')) {
        [$subnet, $bitsStr] = explode('/', $range, 2);
        if (!is_numeric($bitsStr)) {
            return false;
        }
        $bits = (int)$bitsStr;

        // IPv4 CIDR matching
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($bits < 0 || $bits > 32) {
                return false;
            }
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        // IPv6 CIDR matching
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($bits < 0 || $bits > 128) {
                return false;
            }
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $bytes = (int)($bits / 8);
            $extraBits = $bits % 8;

            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }

            if ($extraBits > 0) {
                $mask = chr((0xFF << (8 - $extraBits)) & 0xFF);
                if ((ord($ipBin[$bytes]) & ord($mask)) !== (ord($subnetBin[$bytes]) & ord($mask))) {
                    return false;
                }
            }

            return true;
        }
    }

    return false;
}

/**
 * Resolve the real client IP address for security and rate-limiting decisions.
 *
 * SECURITY MODEL & TRUST ASSUMPTIONS:
 * - Direct Connections (No Reverse Proxy):
 *   Headers like X-Forwarded-For and X-Real-IP are client-supplied HTTP headers.
 *   An attacker can set them to arbitrary values to rotate their apparent IP on every
 *   request and bypass IP-based rate limiting. When connecting directly or when the
 *   immediate peer ($_SERVER['REMOTE_ADDR']) is NOT a configured trusted proxy,
 *   all client-supplied proxy headers are strictly ignored and REMOTE_ADDR is used.
 *
 * - Behind a Known Reverse Proxy (e.g. Nginx, Cloudflare, AWS ALB, Railway Edge):
 *   When the immediate connecting peer ($_SERVER['REMOTE_ADDR']) matches an entry in
 *   the TRUSTED_PROXIES configuration (IP or CIDR range), we trust that the proxy has
 *   appended the legitimate connecting IP to the X-Forwarded-For header.
 *   Because client-side proxies or attackers prepend entries to the left:
 *     X-Forwarded-For: <attacker_spoofed_ip>, <real_client_ip>, <trusted_proxy_hop>
 *   we inspect the chain from RIGHT to LEFT. We skip intermediate trusted proxy IPs,
 *   and select the first untrusted IP encountered from the right. This is the genuine
 *   client IP that connected to the edge proxy, completely defeating spoofed entries.
 *   If X-Forwarded-For is absent, X-Real-IP set by the trusted proxy is inspected.
 *
 * Configuration:
 *   TRUSTED_PROXIES: Comma-separated list of trusted proxy IPs or CIDR ranges.
 *                    e.g. '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
 *                    If empty, no proxy headers are trusted (direct connection mode).
 *   TRUST_PROXY_HEADERS: Legacy flag. If explicitly set to '0', disables proxy header
 *                        trust regardless of proxy configuration.
 *
 * @return string A valid IPv4 or IPv6 address.
 */
function get_client_ip(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Normalize IPv6-mapped IPv4 prefix if present (::ffff:1.2.3.4 -> 1.2.3.4)
    if (str_starts_with(strtolower($remoteAddr), '::ffff:')) {
        $remoteAddr = substr($remoteAddr, 7);
    }

    // Retrieve trusted proxies list from configuration
    $trustedProxiesRaw = (string)(env_value('TRUSTED_PROXIES', ''));
    $trustedProxies = array_filter(array_map('trim', explode(',', $trustedProxiesRaw)));

    // Legacy flag: if explicitly '0', never trust proxy headers
    if (env_value('TRUST_PROXY_HEADERS', null) === '0') {
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
    }

    // If no trusted proxies are configured, assume direct connection and ignore headers
    if (empty($trustedProxies)) {
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
    }

    // Check whether the immediate connecting peer is a trusted proxy
    $isRemoteTrusted = false;
    foreach ($trustedProxies as $trustedProxy) {
        if (ip_in_range($remoteAddr, $trustedProxy)) {
            $isRemoteTrusted = true;
            break;
        }
    }

    // If REMOTE_ADDR is not in the trusted proxy allowlist, do NOT trust any headers
    if (!$isRemoteTrusted) {
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
    }

    // REMOTE_ADDR is a trusted proxy: inspect X-Forwarded-For
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = array_map('trim', explode(',', $forwarded));

        // Traverse backwards from right to left (closest to our trusted proxy first).
        // Skip any hops that are themselves trusted proxies; the rightmost untrusted
        // IP encountered is the genuine client IP appended by our proxy.
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $candidate = $parts[$i];
            if (str_starts_with(strtolower($candidate), '::ffff:')) {
                $candidate = substr($candidate, 7);
            }

            if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }

            $isHopTrusted = false;
            foreach ($trustedProxies as $trustedProxy) {
                if (ip_in_range($candidate, $trustedProxy)) {
                    $isHopTrusted = true;
                    break;
                }
            }

            if (!$isHopTrusted) {
                return $candidate;
            }
        }

        // Fallback: if every entry in X-Forwarded-For was in the trusted proxy list,
        // take the leftmost valid IP.
        foreach ($parts as $candidate) {
            if (str_starts_with(strtolower($candidate), '::ffff:')) {
                $candidate = substr($candidate, 7);
            }
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    // If X-Forwarded-For was absent, inspect X-Real-IP set by the trusted proxy
    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '') {
        if (str_starts_with(strtolower($realIp), '::ffff:')) {
            $realIp = substr($realIp, 7);
        }
        if (filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }
    }

    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
}

/**
 * Completely destroy the current session, clearing memory, cookies, and storage.
 */
function destroy_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
}

/**
 * Retrieve the normalized current application environment string ('production', 'development', etc.).
 * Defaults to 'production' if unset or invalid.
 */
function get_app_env(): string
{
    $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
    return strtolower(trim((string)$env));
}

/**
 * Determine whether the application is running in local development mode.
 * Safe by default: returns true ONLY if APP_ENV is explicitly 'development'.
 */
function is_dev_env(): bool
{
    return get_app_env() === 'development';
}

/**
 * Count the actual number of questions served for a given exam attempt (from attempt_questions).
 *
 * @param mysqli $conn
 * @param int $attemptId
 * @return int Number of served questions, or 0 if none yet assigned.
 */
function get_attempt_served_question_count(mysqli $conn, int $attemptId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM attempt_questions WHERE attempt_id = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $attemptId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int)$row['cnt'] : 0;
}

/**
 * Determine if the current request is served over HTTPS, checking direct connection
 * and proxy headers.
 */
function is_request_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

/**
 * Generate a SHA-256 hash of an OTP using a server-side pepper.
 */
function hash_otp(string $otp): string
{
    $pepper = env_value('OTP_PEPPER', 'default_pepper_if_unset');
    return hash('sha256', $otp . $pepper);
}
