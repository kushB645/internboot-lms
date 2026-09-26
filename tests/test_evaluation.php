<?php
/**
 * Standalone Test Script for M7 Evaluation Logic
 * Run via: php tests/test_evaluation.php
 */

require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/modules/m7_evaluation_admin/service.php';
require_once __DIR__ . '/../src/modules/m7_evaluation_admin/queries.php';

echo "Running M7 Evaluation Tests...\n\n";

$passed = 0;
$failed = 0;

function assertEqual($expected, $actual, $testName) {
    global $passed, $failed;
    if ($expected === $actual) {
        echo "✅ PASS: $testName\n";
        $passed++;
    } else {
        echo "❌ FAIL: $testName (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")\n";
        $failed++;
    }
}

function testLevelMapping($conn) {
    echo "--- Testing Level Mapping ---\n";
    
    $levels = [
        ['pct' => 90, 'expected' => 1], // Top Level
        ['pct' => 85, 'expected' => 1],
        ['pct' => 84.99, 'expected' => 2],
        ['pct' => 70, 'expected' => 2],
        ['pct' => 69.99, 'expected' => 3],
        ['pct' => 55, 'expected' => 3],
        ['pct' => 54.99, 'expected' => 4],
        ['pct' => 40, 'expected' => 4],
        ['pct' => 39.99, 'expected' => 5], // Lowest Level
        ['pct' => 0, 'expected' => 5],
    ];

    foreach ($levels as $t) {
        $l = get_level_for_percentage($conn, $t['pct']);
        $levelNum = $l ? (int)$l['level_number'] : null;
        assertEqual($t['expected'], $levelNum, "Score {$t['pct']}% should map to Level {$t['expected']}");
    }
    echo "\n";
}

try {
    $conn->begin_transaction(); // Wrap tests in transaction so we can rollback

    testLevelMapping($conn);

    $conn->rollback(); // Don't persist test data
    
    echo "--- Test Summary ---\n";
    echo "Total Passed: $passed\n";
    echo "Total Failed: $failed\n";
    
    if ($failed > 0) {
        exit(1);
    }
    exit(0);

} catch (Exception $e) {
    $conn->rollback();
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    exit(1);
}
