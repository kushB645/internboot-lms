<?php
/**
 * Note: Source of truth for the exam engine HTML/JS runner is src/modules/m6_exam_engine/exam.php.
 * public/take-exam.php is the public entrypoint copy.
 */

if (file_exists(__DIR__ . '/../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../src/core/bootstrap.php';
} elseif (file_exists(dirname(__DIR__) . '/src/core/bootstrap.php')) {
    require_once dirname(__DIR__) . '/src/core/bootstrap.php';
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Session guard
require_once __DIR__ . '/../src/core/candidate_resolver.php';
$candidateId = validate_candidate_session($conn);
if ($candidateId === null) {
    header('Location: /login.php');
    exit;
}

// Delegate to the single source of truth for the exam engine UI/logic
require_once __DIR__ . '/../src/modules/m6_exam_engine/exam.php';