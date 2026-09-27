<?php
// Path: tests/bootstrap.php

// Load .env to get DB credentials
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            $_ENV[$key] = $value;
        }
    }
}

// Force testing environment to protect production data
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_NAME'] = 'internboot_test';
$_ENV['OTP_PEPPER'] = 'test_pepper_123';
$_ENV['M4_DEMO_MODE'] = '0';
$_SERVER['M4_DEMO_MODE'] = '0';
putenv('M4_DEMO_MODE=0');

// Create the database before db.php tries to connect to it
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';
$port = $_ENV['DB_PORT'] ?? 3306;

try {
    $tempConn = @new mysqli($host, $user, $pass, '', (int)$port);
    if (!$tempConn->connect_error) {
        $tempConn->query("CREATE DATABASE IF NOT EXISTS `internboot_test`");
        $tempConn->close();
    }
} catch (\Throwable $e) {
    // Ignore, let db.php handle it and crash if needed
}

require_once __DIR__ . '/../src/core/bootstrap.php';

// Since PHPUnit includes the bootstrap file inside a method, db.php's $conn is local.
// We must establish a global connection manually.
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';
$port = $_ENV['DB_PORT'] ?? 3306;
$dbName = 'internboot_test';

$GLOBALS['conn'] = new mysqli($host, $user, $pass, $dbName, (int)$port);
$conn = $GLOBALS['conn'];

// Ensure we are connected to the test database
if ($dbName !== 'internboot_test') {
    die("FATAL: Tests must run against a database named 'internboot_test'. Currently pointing to: {$dbName}");
}

// Attempt to create the test database and select it
$conn->query("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
$conn->select_db($dbName);

// Helper function to apply schema
function apply_test_schema(mysqli $conn) {
    // Drop all tables first for a clean state
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $conn->query("DROP TABLE IF EXISTS `{$row[0]}`");
    }
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    $schemaFile = __DIR__ . '/../schema.sql';
    $sqlContent = file_get_contents($schemaFile);
    $sqlContent = str_replace("\r\n", "\n", $sqlContent);

    $statements = [];
    $currentStmt = '';
    $inString = false;
    $stringChar = '';
    $delimiter = ';';
    $lines = explode("\n", $sqlContent);

    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if (!$inString && preg_match('/^DELIMITER\s+(.+)$/i', $trimmedLine, $matches)) {
            $delimiter = $matches[1];
            continue;
        }
        
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if (!$inString) {
                if ($c === "'" || $c === '"') {
                    $inString = true;
                    $stringChar = $c;
                    $currentStmt .= $c;
                } elseif (substr($line, $i, 2) === '--') {
                    $currentStmt .= substr($line, $i);
                    break;
                } elseif (substr($line, $i, strlen($delimiter)) === $delimiter) {
                    $stmtStr = trim($currentStmt);
                    if ($stmtStr !== '') $statements[] = $stmtStr;
                    $currentStmt = '';
                    $i += strlen($delimiter) - 1;
                } else {
                    $currentStmt .= $c;
                }
            } else {
                $currentStmt .= $c;
                if ($c === $stringChar) {
                    $escaped = false;
                    $backslashes = 0;
                    for ($j = $i - 1; $j >= 0 && $line[$j] === '\\'; $j--) {
                        $backslashes++;
                    }
                    if ($backslashes % 2 !== 0) $escaped = true;
                    if (!$escaped) $inString = false;
                }
            }
        }
        $currentStmt .= "\n";
    }
    if (trim($currentStmt) !== '') $statements[] = trim($currentStmt);

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $stmtNoComments = trim(preg_replace('/^\s*--[^\n]*\n?/m', '', $stmt));
        if ($stmtNoComments === '') continue;
        
        try {
            $conn->query($stmt);
        } catch (\mysqli_sql_exception $e) {
            $msg = $e->getMessage();
            $benign = stripos($msg, 'Duplicate entry') !== false || 
                      stripos($msg, 'already exists') !== false ||
                      stripos($msg, 'Duplicate column') !== false ||
                      stripos($msg, 'Duplicate key') !== false;
            if (!$benign) {
                die("Schema error: " . $msg . "\n" . $stmtNoComments);
            }
        }
    }
}

$res = $conn->query("SHOW TABLES LIKE 'users'");
if ($res && $res->num_rows === 0) {
    // Apply schema once per test suite run (only if tables don't exist)
    apply_test_schema($conn);
    echo "Test database '{$dbName}' initialized and schema applied.\n";
} else {
    echo "Test database '{$dbName}' already initialized.\n";
}
