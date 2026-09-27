<?php
// Path: public/verify-certificate.php
declare(strict_types=1);

require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/core/helpers.php';
require_once __DIR__ . '/../src/core/response.php';

// Rate limiting configuration: max 20 requests per 15 minutes per IP
const VERIFY_RATE_LIMIT_MAX = 20;
const VERIFY_RATE_LIMIT_WINDOW_MINUTES = 15;

function is_verification_rate_limited(mysqli $conn, string $ip): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS cnt
         FROM certificate_verification_attempts
         WHERE ip_address = ?
           AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    if (!$stmt) {
        return false;
    }
    $window = VERIFY_RATE_LIMIT_WINDOW_MINUTES;
    $stmt->bind_param('si', $ip, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['cnt'] ?? 0)) >= VERIFY_RATE_LIMIT_MAX;
}

function record_verification_attempt(mysqli $conn, string $ip): void
{
    $stmt = $conn->prepare('INSERT INTO certificate_verification_attempts (ip_address) VALUES (?)');
    if ($stmt) {
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $stmt->close();
    }

    // Occasional cleanup of records older than 24 hours
    if (random_int(1, 50) === 1) {
        @$conn->query('DELETE FROM certificate_verification_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    }
}

function fetch_public_certificate(mysqli $conn, string $certificateNumber): ?array
{
    $stmt = $conn->prepare(
        'SELECT ce.certificate_number,
                ce.issue_date,
                ce.level,
                l.level_name,
                c.full_name AS candidate_name,
                a.title AS assessment_title
         FROM certificates ce
         JOIN candidates c ON c.id = ce.candidate_id
         JOIN results r ON r.id = ce.result_id
         JOIN attempts at ON at.id = r.attempt_id
         JOIN assessments a ON a.id = at.assessment_id
         LEFT JOIN levels l ON l.level_number = ce.level
         WHERE ce.certificate_number = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $certificateNumber);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $levelNum = (int)$row['level'];
    $levelName = !empty($row['level_name']) ? (string)$row['level_name'] : "Level {$levelNum}";

    // Return strictly non-sensitive fields only
    return [
        'certificate_number' => (string)$row['certificate_number'],
        'candidate_name'     => (string)$row['candidate_name'],
        'assessment_title'   => (string)$row['assessment_title'],
        'level'              => $levelNum,
        'level_name'         => $levelName,
        'issue_date'         => (string)$row['issue_date'],
    ];
}

// Determine if the request wants JSON
$isJsonRequest = false;
$acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
$requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
$formatParam = $_GET['format'] ?? $_POST['format'] ?? '';

if (
    $formatParam === 'json' ||
    str_contains($acceptHeader, 'application/json') ||
    strtolower($requestedWith) === 'xmlhttprequest'
) {
    $isJsonRequest = true;
}

// Retrieve certificate number parameter
$rawCert = $_GET['certificate_number'] ?? $_POST['certificate_number'] ?? '';
if ($rawCert === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    if ($rawInput !== false && $rawInput !== '') {
        $json = json_decode($rawInput, true);
        if (is_array($json) && !empty($json['certificate_number'])) {
            $rawCert = (string)$json['certificate_number'];
        }
    }
}
$certNumber = trim((string)$rawCert);
$clientIp = get_client_ip();

// Handle JSON API Request
if ($isJsonRequest) {
    if ($certNumber === '') {
        send_json_response('error', 'Certificate number is required.', null, 400);
    }

    if (is_verification_rate_limited($conn, $clientIp)) {
        send_json_response('error', 'Too many verification attempts. Please try again in 15 minutes.', null, 429);
    }

    record_verification_attempt($conn, $clientIp);
    $certificate = fetch_public_certificate($conn, $certNumber);

    if (!$certificate) {
        send_json_response('success', 'Certificate not found.', ['verified' => false]);
    }

    send_json_response('success', 'Certificate verified.', [
        'verified'    => true,
        'certificate' => $certificate,
    ]);
}

// Handle HTML Page Request
$searched = ($certNumber !== '');
$rateLimited = false;
$certResult = null;

if ($searched) {
    if (is_verification_rate_limited($conn, $clientIp)) {
        $rateLimited = true;
    } else {
        record_verification_attempt($conn, $clientIp);
        $certResult = fetch_public_certificate($conn, $certNumber);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verify Certificate — InternBoot Platform</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <style>
    body {
      background-color: #0f172a;
      color: #e2e8f0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .verify-container {
      max-width: 680px;
      margin: 60px auto;
      padding: 0 20px;
      flex: 1;
    }
    .verify-card {
      background-color: #1e293b;
      border: 1px solid #334155;
      border-radius: 16px;
      padding: 36px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    }
    .verify-title {
      font-size: 26px;
      font-weight: 700;
      color: #f8fafc;
      margin-bottom: 8px;
    }
    .verify-sub {
      color: #94a3b8;
      font-size: 14px;
      margin-bottom: 24px;
    }
    .form-control-cert {
      background-color: #0f172a;
      border: 1px solid #475569;
      color: #f8fafc;
      font-size: 16px;
      padding: 12px 16px;
      border-radius: 8px;
    }
    .form-control-cert:focus {
      background-color: #0f172a;
      border-color: #3b82f6;
      color: #f8fafc;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
    }
    .btn-verify {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      border: none;
      color: #fff;
      font-weight: 600;
      padding: 12px 24px;
      border-radius: 8px;
      transition: all 0.2s;
    }
    .btn-verify:hover {
      background: linear-gradient(135deg, #1d4ed8, #1e40af);
      color: #fff;
    }
    .result-card {
      margin-top: 28px;
      border-radius: 12px;
      padding: 24px;
    }
    .result-valid {
      background-color: rgba(16, 185, 129, 0.08);
      border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .result-invalid {
      background-color: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.3);
    }
    .badge-verified {
      background-color: #10b981;
      color: #fff;
      font-size: 13px;
      padding: 6px 12px;
      border-radius: 9999px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .detail-row {
      display: flex;
      justify-content: space-between;
      padding: 10px 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.07);
    }
    .detail-row:last-child {
      border-bottom: none;
    }
    .detail-label {
      color: #94a3b8;
      font-size: 14px;
    }
    .detail-val {
      color: #f8fafc;
      font-weight: 600;
      font-size: 14px;
      text-align: right;
    }
    footer {
      text-align: center;
      padding: 20px;
      color: #64748b;
      font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="verify-container">
    <div class="verify-card">
      <div class="text-center mb-4">
        <h1 class="verify-title">Certificate Verification</h1>
        <p class="verify-sub">Verify the authenticity of credentials issued by the InternBoot Assessment Engine.</p>
      </div>

      <form method="GET" action="verify-certificate.php" class="row g-2">
        <div class="col-sm-8">
          <input type="text"
                 name="certificate_number"
                 class="form-control form-control-cert w-100"
                 placeholder="e.g. IB-2026-123456"
                 value="<?= htmlspecialchars($certNumber) ?>"
                 required>
        </div>
        <div class="col-sm-4">
          <button type="submit" class="btn btn-verify w-100">Verify</button>
        </div>
      </form>

      <?php if ($rateLimited): ?>
        <div class="result-card result-invalid mt-4">
          <div class="d-flex align-items-center gap-2 mb-2 text-danger fw-bold">
            <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995z"/></svg>
            Too Many Requests
          </div>
          <p class="text-muted mb-0 small">You have exceeded the verification request limit. Please try again after 15 minutes.</p>
        </div>
      <?php elseif ($searched && $certResult): ?>
        <div class="result-card result-valid mt-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="badge-verified">
              <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425a.247.247 0 0 1 .02-.022z"/></svg>
              Verified Authentic
            </span>
            <small class="text-muted">Issued: <?= htmlspecialchars($certResult['issue_date']) ?></small>
          </div>

          <div class="detail-row">
            <span class="detail-label">Candidate Name</span>
            <span class="detail-val"><?= htmlspecialchars($certResult['candidate_name']) ?></span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Assessment</span>
            <span class="detail-val"><?= htmlspecialchars($certResult['assessment_title']) ?></span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Qualification Level</span>
            <span class="detail-val">Level <?= (int)$certResult['level'] ?> &mdash; <?= htmlspecialchars($certResult['level_name']) ?></span>
          </div>
          <div class="detail-row">
            <span class="detail-label">Certificate Number</span>
            <span class="detail-val text-monospace"><?= htmlspecialchars($certResult['certificate_number']) ?></span>
          </div>
        </div>
      <?php elseif ($searched && !$certResult): ?>
        <div class="result-card result-invalid mt-4">
          <div class="d-flex align-items-center gap-2 mb-2 text-danger fw-bold">
            <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/></svg>
            Certificate Not Found
          </div>
          <p class="text-muted mb-0 small">
            No active certificate matching <strong><?= htmlspecialchars($certNumber) ?></strong> was found. Please verify the certificate number and try again.
          </p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <footer>
    &copy; <?= date('Y') ?> InternBoot Platform. All rights reserved. &bull; <a href="login.php" class="text-secondary text-decoration-none">Login</a>
  </footer>
</body>
</html>
