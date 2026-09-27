<?php
declare(strict_types=1);

function render_certificate_error_page(string $message, int $statusCode = 500): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Unavailable — InternBoot</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 32px; max-width: 480px; width: 100%; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        h1 { font-size: 20px; margin: 0 0 12px; color: #f87171; }
        p { font-size: 14px; line-height: 1.6; color: #94a3b8; margin: 0 0 24px; }
        .btn { display: inline-block; background: #3b82f6; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 500; font-size: 14px; }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Certificate Unavailable</h1>
        <p>' . htmlspecialchars($message) . '</p>
        <a href="javascript:window.close()" class="btn">Close Window</a>
    </div>
</body>
</html>';
    exit;
}

try {
    require_once __DIR__ . '/../../../src/core/bootstrap.php';
    require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/queries.php';
    require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/service.php';
    require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/pdf.php';

    $resultId = require_positive_int($_GET['result_id'] ?? null, 'result_id');
    
    // Fetch ALREADY-ISSUED certificate. No generating rows in GET.
    $row = q_one($conn, "SELECT ce.id, ce.certificate_number, ce.issue_date,
        r.level_assigned, r.percentage, c.id candidate_id, c.full_name, u.email, a.title assessment_title
      FROM certificates ce
      JOIN results r ON r.id=ce.result_id
      JOIN attempts at ON at.id=r.attempt_id
      JOIN candidates c ON c.id=at.candidate_id
      JOIN users u ON u.id=c.user_id
      JOIN assessments a ON a.id=at.assessment_id
      WHERE ce.result_id=?", 'i', [$resultId]);

    if (!$row) {
        throw new InvalidArgumentException('Certificate not yet issued.');
    }
    
    $levelRow = q_one($conn, 'SELECT level_name FROM levels WHERE level_number=?', 'i', [(int)$row['level_assigned']]);
    $levelName = $levelRow ? $levelRow['level_name'] : ('Level ' . $row['level_assigned']);
    
    $data = [
        'id' => $row['id'],
        'certificate_number' => $row['certificate_number'],
        'issue_date' => $row['issue_date'],
        'candidate' => $row['full_name'],
        'email' => $row['email'],
        'assessment' => $row['assessment_title'],
        'percentage' => $row['percentage'],
        'level' => $row['level_assigned'],
        'level_name' => $levelName,
        'candidate_id' => $row['candidate_id']
    ];

    $role = resolve_admin_role($conn);
    if ($role !== 'admin') {
        require_once __DIR__ . '/../../../src/core/candidate_resolver.php';
        $candidateId = validate_candidate_session($conn);
        if ($candidateId === null || $candidateId !== (int)$data['candidate_id']) {
            throw new AdminAccessDeniedException('Access denied. You can only view your own certificate.');
        }
    }

    output_certificate_pdf($data);
} catch (AdminAccessDeniedException $e) {
    render_certificate_error_page($e->getMessage(), 403);

} catch (InvalidArgumentException $e) {
    render_certificate_error_page($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('InternBoot M7 certificate PDF error: ' . $e->getMessage());
    render_certificate_error_page(is_dev_env() ? $e->getMessage() : 'Certificate could not be generated. Please try again or contact support.', 500);
}



