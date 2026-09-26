<?php
/**
 * InternBoot M7 database bootstrap.
 *
 * Supports both the project's DB_* variables and all Railway MySQL variables:
 * MYSQL_DATABASE, MYSQL_PUBLIC_URL, MYSQL_ROOT_PASSWORD, MYSQL_URL,
 * MYSQLDATABASE, MYSQLHOST, MYSQLPASSWORD, MYSQLPORT, MYSQLUSER.
 */
function load_local_env(string $file): void
{
    if (!is_file($file)) return;

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

load_local_env(__DIR__ . '/.env');

function env_value(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) return (string)$_ENV[$key];
    $value = getenv($key);
    return $value === false ? $default : (string)$value;
}

function parse_mysql_url(string $url): array
{
    $parts = parse_url(trim($url));
    if ($parts === false || empty($parts['host'])) return [];

    return [
        'host' => $parts['host'],
        'port' => isset($parts['port']) ? (int)$parts['port'] : 3306,
        'user' => isset($parts['user']) ? urldecode($parts['user']) : 'root',
        'password' => isset($parts['pass']) ? urldecode($parts['pass']) : '',
        'database' => isset($parts['path']) ? ltrim($parts['path'], '/') : 'railway',
    ];
}

/*
 * Priority resolution:
 * - If running on Railway (RAILWAY_ENVIRONMENT is set) and MYSQL_URL is present,
 *   prefer MYSQL_URL (private/internal network) over MYSQL_PUBLIC_URL.
 * - Otherwise (local development), prefer MYSQL_PUBLIC_URL.
 * - Explicit DB_* variables always take highest priority.
 */
$publicUrl = env_value('MYSQL_PUBLIC_URL', '');
$privateUrl = env_value('MYSQL_URL', '');
$publicConfig = $publicUrl !== '' ? parse_mysql_url($publicUrl) : [];
$privateConfig = $privateUrl !== '' ? parse_mysql_url($privateUrl) : [];

$isRailway = env_value('RAILWAY_ENVIRONMENT') !== null && env_value('RAILWAY_ENVIRONMENT') !== '';
$primaryConfig = ($isRailway && !empty($privateConfig)) ? $privateConfig : $publicConfig;
$secondaryConfig = ($isRailway && !empty($privateConfig)) ? $publicConfig : $privateConfig;

$host = env_value('DB_HOST');
$port = env_value('DB_PORT');
$user = env_value('DB_USER');
$password = env_value('DB_PASSWORD');
$dbname = env_value('DB_NAME');

if ($host === null || $host === '') $host = $primaryConfig['host'] ?? env_value('MYSQLHOST') ?? $secondaryConfig['host'] ?? '127.0.0.1';
if ($port === null || $port === '') $port = (string)($primaryConfig['port'] ?? env_value('MYSQLPORT') ?? $secondaryConfig['port'] ?? 3306);
if ($user === null || $user === '') $user = $primaryConfig['user'] ?? env_value('MYSQLUSER') ?? $secondaryConfig['user'] ?? 'root';
if ($password === null || $password === '') {
    $password = $primaryConfig['password'] ?? env_value('MYSQL_ROOT_PASSWORD') ?? env_value('MYSQLPASSWORD') ?? $secondaryConfig['password'] ?? '';
}
if ($dbname === null || $dbname === '') $dbname = $primaryConfig['database'] ?? env_value('MYSQL_DATABASE') ?? env_value('MYSQLDATABASE') ?? $secondaryConfig['database'] ?? 'railway';

/* The pasted Railway value can accidentally contain another variable assignment. */
if (str_starts_with((string)$password, 'MYSQL_') && str_contains((string)$password, '=')) {
    $password = env_value('MYSQL_ROOT_PASSWORD') ?? $publicConfig['password'] ?? '';
}

$port = (int)$port;
if ($port < 1 || $port > 65535) $port = 3306;

if ($isRailway && str_contains((string)$host, '.proxy.rlwy.net')) {
    $privateHost = env_value('MYSQLHOST', 'mysql.railway.internal');
    if ($privateHost !== '') {
        $host = $privateHost;
        error_log("db.php: overriding public proxy host with Railway private network host for in-cluster connection.");
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $password, $dbname, $port);
    $conn->set_charset('utf8mb4');
    // Synchronize MySQL DB session time zone with PHP timezone offset (e.g. +05:30)
    $conn->query("SET time_zone = '" . date('P') . "'");
} catch (mysqli_sql_exception $e) {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $prefersHtml = str_contains($accept, 'text/html') || str_contains($accept, 'application/pdf');
    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') && !$prefersHtml;
    if ($isApi) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed. Check Railway public MySQL credentials and host/port in .env.',
            'data' => null
        ]);
        exit;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Database Connection Error</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box}.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:32px;max-width:480px;width:100%;text-align:center}h1{font-size:20px;margin:0 0 12px;color:#f87171}p{font-size:14px;color:#94a3b8;line-height:1.6;margin:0}</style></head><body><div class="card"><h1>Database Connection Failed</h1><p>Database connection failed. Check DB_* or Railway MYSQL_* credentials in .env.</p></div></body></html>';
    exit;
}

