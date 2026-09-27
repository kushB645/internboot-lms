<?php
// Path: scripts/apply-schema.php
//
// Applies schema.sql to the configured database.
//
// Usage (from repository root):
//   php scripts/apply-schema.php           -- safe, idempotent (CREATE TABLE IF NOT EXISTS)
//   php scripts/apply-schema.php --force   -- drops all tables first (DESTROYS ALL DATA)
//
// Trigger handling: Uses a custom statement splitter that respects string literals,
// comments, and DELIMITER directives.

require_once __DIR__ . '/../src/core/bootstrap.php';

$force = in_array('--force', $argv, true);

if ($force) {
    echo "[--force] Destructive mode: Executing reset-schema.sql...\n";
    $resetFile = __DIR__ . '/reset-schema.sql';
    if (!file_exists($resetFile)) {
        die("Error: reset-schema.sql not found at {$resetFile}\n");
    }
    $resetSql = file_get_contents($resetFile);
    if (!$conn->multi_query($resetSql)) {
        die("Error executing reset-schema.sql: " . $conn->error . "\n");
    }
    // clear results
    while ($conn->next_result()) {;}
    echo "Destructive reset complete. Proceeding to apply schema...\n";
} else {
    echo "Safe mode: Existing data will not be dropped (use --force for destructive reset).\n";
}

echo "Reading schema.sql...\n";
$schemaFile = __DIR__ . '/../schema.sql';
if (!file_exists($schemaFile)) {
    die("Error: schema.sql not found at {$schemaFile}\n");
}

$sqlContent = file_get_contents($schemaFile);
if ($sqlContent === false) {
    die("Error: Could not read schema.sql\n");
}

// Normalise line endings
$sqlContent = str_replace("\r\n", "\n", $sqlContent);

// Custom statement splitter that respects string literals and DELIMITER
$statements = [];
$currentStmt = '';
$inString = false;
$stringChar = '';
$delimiter = ';';
$lines = explode("\n", $sqlContent);

foreach ($lines as $line) {
    $trimmedLine = trim($line);
    
    // Check for DELIMITER change, but only if not inside a string
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
                // Ignore the rest of the line for single-line comments
                $currentStmt .= substr($line, $i);
                break;
            } elseif (substr($line, $i, strlen($delimiter)) === $delimiter) {
                // Found delimiter
                $stmtStr = trim($currentStmt);
                if ($stmtStr !== '') {
                    $statements[] = $stmtStr;
                }
                $currentStmt = '';
                $i += strlen($delimiter) - 1; // skip rest of delimiter
            } else {
                $currentStmt .= $c;
            }
        } else {
            // Inside string
            $currentStmt .= $c;
            if ($c === $stringChar) {
                // Check if escaped (simplified: only works if not escaped by \ or double quote, but SQL uses \ or '' mostly)
                // Let's do proper escape check
                $escaped = false;
                $backslashes = 0;
                for ($j = $i - 1; $j >= 0 && $line[$j] === '\\'; $j--) {
                    $backslashes++;
                }
                if ($backslashes % 2 !== 0) {
                    $escaped = true;
                }
                if (!$escaped) {
                    $inString = false;
                }
            }
        }
    }
    $currentStmt .= "\n";
}
if (trim($currentStmt) !== '') {
    $statements[] = trim($currentStmt);
}

echo "Parsed " . count($statements) . " statements from schema.sql.\n";
echo "=================================================\n";

$totalStatements   = 0;
$executedStatements = 0;
$warnStatements     = 0;
$triggerWarnings = 0;

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;

    $stmtNoComments = trim(preg_replace('/^\s*--[^\n]*\n?/m', '', $stmt));
    if ($stmtNoComments === '') continue;

    $totalStatements++;

    try {
        if (!$conn->query($stmt)) {
            echo "[WARN] Statement failed: " . $conn->error . "\n";
            echo "       " . substr(str_replace(["\n", "\r"], ' ', $stmtNoComments), 0, 70) . "\n";
            $warnStatements++;
        } else {
            $executedStatements++;
        }
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $benign = stripos($msg, 'Duplicate entry') !== false
               || stripos($msg, 'already exists') !== false
               || stripos($msg, 'Duplicate column') !== false
               || stripos($msg, 'Duplicate key') !== false;
        if (!$benign) {
            echo "[WARN] Exception: {$msg}\n";
            echo "       " . substr(str_replace(["\n", "\r"], ' ', $stmtNoComments), 0, 70) . "\n";
            $warnStatements++;
        } else {
            $executedStatements++;
        }
    }
}

echo "=================================================\n";
echo "Installation complete.\n";
echo "Total:    {$totalStatements}\n";
echo "Executed: {$executedStatements}\n";
echo "Warnings: {$warnStatements}\n";
echo "=================================================\n";

if ($warnStatements > 0) {
    echo "Schema installation finished WITH ERRORS. Review warnings above.\n";
    exit(1);
}
