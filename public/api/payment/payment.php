<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function request_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

require_once __DIR__ . '/../../../src/core/candidate_resolver.php';

function get_fee(mysqli $conn): float {
    $s = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key='exam_fee' LIMIT 1");
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r ? (float)$r['setting_value'] : 2999.00;
}

$action = $_GET['action'] ?? '';
$input = request_body();
if (!$action && isset($input['action'])) {
    $action = (string)$input['action'];
}
if (!$action) {
    $action = $_SERVER['REQUEST_METHOD'] === 'GET' ? 'details' : 'create';
}

// TODO: This is a mock payment flow for development/testing only.
// It MUST be replaced with a real gateway (e.g., PayU, Easebuzz, Razorpay)
// with server-to-server signature verification before real production launch.
if ($action === 'create' || $action === 'verify') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json_response('error', 'Method not allowed. Use POST.', null, 405);
    }
    $demoMode = env_value('M4_DEMO_MODE', '0') === '1';
    $demoSecret = trim((string)env_value('M4_DEMO_SECRET', ''));
    
    if (!$demoMode || empty($demoSecret)) {
        send_json_response('error', 'Payment gateway not configured. Mock payment is disabled.', null, 403);
    }
}

try {
    if ($action === 'details') {
        $candidateId = resolve_candidate_id($_GET);
        $assessmentId = isset($_GET['assessment_id']) && ctype_digit((string)$_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;

        $s = $conn->prepare('SELECT id, full_name, phone, user_id FROM candidates WHERE id = ? LIMIT 1');
        $s->bind_param('i', $candidateId);
        $s->execute();
        $c = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$c) send_json_response('error', 'Candidate not found.', null, 404);

        if ($assessmentId > 0) {
            $s = $conn->prepare('SELECT id, title, description, duration_minutes, total_questions, status FROM assessments WHERE id = ? LIMIT 1');
            $s->bind_param('i', $assessmentId);
            $s->execute();
            $a = $s->get_result()->fetch_assoc();
            $s->close();
        } else {
            $s = $conn->prepare("SELECT id, title, description, duration_minutes, total_questions, status FROM assessments WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            $s->execute();
            $a = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$a) {
                $s = $conn->prepare('SELECT id, title, description, duration_minutes, total_questions, status FROM assessments ORDER BY id DESC LIMIT 1');
                $s->execute();
                $a = $s->get_result()->fetch_assoc();
                $s->close();
            }
        }
        if (!$a) send_json_response('error', 'No assessment found.', null, 404);

        $aid = (int)$a['id'];
        $s = $conn->prepare('SELECT id, amount, status, reference_number, payment_date, created_at FROM payments WHERE candidate_id = ? AND assessment_id = ? ORDER BY id DESC LIMIT 1');
        $s->bind_param('ii', $candidateId, $aid);
        $s->execute();
        $p = $s->get_result()->fetch_assoc();
        $s->close();

        $s = $conn->prepare('SELECT id, eligibility_status FROM enrollments WHERE candidate_id = ? AND assessment_id = ? LIMIT 1');
        $s->bind_param('ii', $candidateId, $aid);
        $s->execute();
        $e = $s->get_result()->fetch_assoc();
        $s->close();

        send_json_response('success', 'Payment details retrieved', [
            'candidate' => ['id' => (int)$c['id'], 'name' => $c['full_name'], 'phone' => $c['phone']],
            'assessment' => ['id' => $aid, 'title' => $a['title'], 'description' => $a['description'], 'duration' => (int)$a['duration_minutes'], 'questions' => (int)$a['total_questions']],
            'fee' => $p ? (float)$p['amount'] : get_fee($conn),
            'payment' => $p,
            'enrollment' => $e
        ]);
    }

    if ($action === 'create') {
        $candidateId = resolve_candidate_id($input);
        $assessmentId = (int)($input['assessment_id'] ?? 0);
        if ($assessmentId < 1) {
            $s = $conn->prepare("SELECT id FROM assessments WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            if ($row) $assessmentId = (int)$row['id'];
        }
        if ($assessmentId < 1) send_json_response('error', 'Assessment is required.', null, 400);
$s = $conn->prepare("SELECT id FROM assessments WHERE id = ?");
        $s->bind_param('i', $assessmentId);
        $s->execute();
        $exists = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$exists) {
            send_json_response('error', 'Assessment not found.', null, 404);
        }

        $s = $conn->prepare("SELECT id, amount, status, reference_number, payment_date FROM payments WHERE candidate_id = ? AND assessment_id = ? AND status = 'success' ORDER BY id DESC LIMIT 1");
        $s->bind_param('ii', $candidateId, $assessmentId);
        $s->execute();
        $success = $s->get_result()->fetch_assoc();
        $s->close();
        if ($success) {
            send_json_response('success', 'Already paid for this assessment', [
                'already_paid' => true,
                'payment' => $success
            ]);
        }

        $expectedFee = get_fee($conn);
        if (isset($input['amount']) && (float)$input['amount'] !== $expectedFee) {
            send_json_response('error', 'Invalid payment amount supplied.', null, 400);
        }
        $amount = $expectedFee;

        $ref = 'IB-PAY-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $s = $conn->prepare("INSERT INTO payments (candidate_id, assessment_id, amount, status, reference_number) VALUES (?, ?, ?, 'pending', ?)");
        $s->bind_param('iids', $candidateId, $assessmentId, $amount, $ref);
        $s->execute();
        $paymentId = $s->insert_id;
        $s->close();

        // Secure token verification setup (from Razorpay standalone demo)
        $token = bin2hex(random_bytes(32));
        $_SESSION['m4_demo_payment'] = [
            'payment_id' => $paymentId,
            'candidate_id' => $candidateId,
            'assessment_id' => $assessmentId,
            'token_hash' => hash('sha256', $token),
            'expires' => time() + 900
        ];

        send_json_response('success', 'Payment initialized. Verification token issued.', [
            'payment_id' => $paymentId,
            'reference_number' => $ref,
            'amount' => $amount,
            'token' => $token,
            'mode' => 'demo'
        ], 201);
    }

    if ($action === 'verify') {
        $paymentId = (int)($input['payment_id'] ?? 0);
        $token = (string)($input['token'] ?? '');
        $sesh = $_SESSION['m4_demo_payment'] ?? null;

        if (!$sesh || $paymentId < 1 || !hash_equals((string)($sesh['token_hash'] ?? ''), hash('sha256', $token)) || (int)($sesh['payment_id'] ?? 0) !== $paymentId || time() > (int)($sesh['expires'] ?? 0)) {
            send_json_response('error', 'Demo payment verification failed. Please start the payment again.', null, 403);
        }

        $conn->begin_transaction();
        try {
            $s = $conn->prepare("SELECT id, candidate_id, assessment_id, amount, status, reference_number FROM payments WHERE id = ? FOR UPDATE");
            $s->bind_param('i', $paymentId);
            $s->execute();
            $p = $s->get_result()->fetch_assoc();
            $s->close();

            if (!$p) throw new RuntimeException('Payment record not found.');

            $expectedCandidateId = resolve_candidate_id($input);
            if ((int)$p['candidate_id'] !== $expectedCandidateId) {
                throw new RuntimeException('Payment candidate does not match authenticated candidate.');
            }

            if ($p['status'] !== 'success') {
                $s = $conn->prepare("UPDATE payments SET status = 'success', payment_date = NOW() WHERE id = ? AND status = 'pending'");
                $s->bind_param('i', $paymentId);
                $s->execute();
                $s->close();
            }

            $candidateId = (int)$p['candidate_id'];
            $assessmentId = (int)$p['assessment_id'];

            // Note: The payments table has AFTER INSERT and AFTER UPDATE triggers 
            // that automatically synchronize successful payments to enrollments.
            // This application-level insert is kept as an additional defense-in-depth measure.
            $s = $conn->prepare("INSERT INTO enrollments (candidate_id, assessment_id, payment_id, eligibility_status) VALUES (?, ?, ?, 'eligible') ON DUPLICATE KEY UPDATE payment_id = VALUES(payment_id), eligibility_status = 'eligible'");
            $s->bind_param('iii', $candidateId, $assessmentId, $paymentId);
            $s->execute();
            $s->close();

            $conn->commit();
        } catch (RuntimeException $e) {
            $conn->rollback();
            error_log('Payment verification error: ' . $e->getMessage());
            send_json_response('error', 'Payment verification failed.', null, 403);
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Payment verification DB/server error: ' . $e->getMessage());
            send_json_response('error', 'An internal error occurred during payment verification.', null, 500);
        }

        unset($_SESSION['m4_demo_payment']);

        require_once __DIR__ . '/../../../src/modules/m5_batch_slots/service.php';
        try {
            $batchesFormed = create_all_eligible_batches($assessmentId, $conn);
            if (!empty($batchesFormed)) {
                error_log('Auto-batch formation triggered after payment verify: ' . count($batchesFormed) . ' batch(es) formed for assessment ' . $assessmentId);
            }
        } catch (Throwable $e) {
            error_log('Auto-batch formation failed after payment verify (non-fatal): ' . $e->getMessage());
        }

        send_json_response('success', 'Payment verified server-side and enrollment marked eligible.', [
            'payment' => [
                'id' => $paymentId,
                'reference_number' => $p['reference_number'],
                'amount' => (float)$p['amount'],
                'status' => 'success',
                'payment_date' => date('Y-m-d H:i:s')
            ],
            'enrollment_status' => 'eligible'
        ]);
    }

    send_json_response('error', 'Unsupported action.', null, 400);

} catch (Throwable $e) {
    error_log('Payment system error: ' . $e->getMessage());
    send_json_response('error', 'An unexpected server error occurred.', null, 500);
}
