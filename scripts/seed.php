<?php
// Path: scripts/seed.php
//
// Creates the first admin user, an initial active assessment, and a question
// bank — making a fresh installation usable without hand-crafting raw SQL.
//
// Usage (from repository root):
//   php scripts/seed.php
//
// Required env vars (set in .env or export before running):
//   SEED_ADMIN_EMAIL    — e.g. admin@internboot.com
//   SEED_ADMIN_PASSWORD — strong password for the first admin account
//   SEED_ADMIN_NAME     — display name stored in candidates.full_name
//
// Optional env vars (all have sensible defaults):
//   SEED_ASSESSMENT_TITLE       — default: "InternBoot Level Assessment"
//   SEED_ASSESSMENT_DURATION    — minutes, default: 60
//   SEED_ASSESSMENT_QUESTIONS   — total questions, default: 50
//   SEED_QBANK_NAME             — default: "Main Question Bank"
//
// Safe to re-run: every step is idempotent (skips if data already exists).

require_once __DIR__ . '/../src/core/bootstrap.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

function seed_ok(string $msg): void  { echo "[OK]   {$msg}\n"; }
function seed_skip(string $msg): void { echo "[SKIP] {$msg}\n"; }
function seed_die(string $msg): void  { echo "[ERROR] {$msg}\n"; exit(1); }

function seed_env(string $key, string $default = ''): string
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v !== false && $v !== '') ? (string)$v : $default;
}

// ── Validate required env vars ────────────────────────────────────────────────

$adminEmail    = seed_env('SEED_ADMIN_EMAIL');
$adminPassword = seed_env('SEED_ADMIN_PASSWORD');
$adminName     = seed_env('SEED_ADMIN_NAME', 'Platform Admin');

if ($adminEmail === '') {
    seed_die('SEED_ADMIN_EMAIL is not set. Export it or add it to .env before running seed.php.');
}
if ($adminPassword === '') {
    seed_die('SEED_ADMIN_PASSWORD is not set. Export it or add it to .env before running seed.php.');
}
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    seed_die("SEED_ADMIN_EMAIL ({$adminEmail}) is not a valid email address.");
}
if (strlen($adminPassword) < 8) {
    seed_die('SEED_ADMIN_PASSWORD must be at least 8 characters long.');
}

$assessmentTitle     = seed_env('SEED_ASSESSMENT_TITLE',     'InternBoot Level Assessment');
$assessmentDuration  = (int)seed_env('SEED_ASSESSMENT_DURATION',  '60');
$assessmentQuestions = (int)seed_env('SEED_ASSESSMENT_QUESTIONS', '50');
$qbankName           = seed_env('SEED_QBANK_NAME',           'Main Question Bank');

echo "=================================================\n";
echo "InternBoot Seed — Starting\n";
echo "=================================================\n\n";

// ── Step 1: Admin user ────────────────────────────────────────────────────────

$adminRoleCheck = 'admin';
$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND role = ? LIMIT 1');
$stmt->bind_param('ss', $adminEmail, $adminRoleCheck);
$stmt->execute();
$existingAdmin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existingAdmin) {
    seed_skip("Admin user '{$adminEmail}' already exists (id={$existingAdmin['id']}) — skipping.");
    $adminUserId = (int)$existingAdmin['id'];
} else {
    $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
    $adminRole = 'admin';
    $isActive = 1;

    $stmt = $conn->prepare('INSERT INTO users (email, password, role, is_active) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('sssi', $adminEmail, $passwordHash, $adminRole, $isActive);
    if (!$stmt->execute()) {
        seed_die('Failed to insert admin user: ' . $stmt->error);
    }
    $adminUserId = (int)$conn->insert_id;
    $stmt->close();

    seed_ok("Admin user created — email: {$adminEmail}, id: {$adminUserId}");
}

// ── Step 2: Active assessment ─────────────────────────────────────────────────

$stmt = $conn->prepare("SELECT id FROM assessments WHERE status = 'active' LIMIT 1");
$stmt->execute();
$existingAssessment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existingAssessment) {
    seed_skip("An active assessment already exists (id={$existingAssessment['id']}) — skipping.");
    $assessmentId = (int)$existingAssessment['id'];
} else {
    $status = 'active';
    $stmt = $conn->prepare(
        'INSERT INTO assessments (title, duration_minutes, total_questions, status) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('siis', $assessmentTitle, $assessmentDuration, $assessmentQuestions, $status);
    if (!$stmt->execute()) {
        seed_die('Failed to insert assessment: ' . $stmt->error);
    }
    $assessmentId = (int)$conn->insert_id;
    $stmt->close();

    seed_ok("Assessment created — title: \"{$assessmentTitle}\", id: {$assessmentId}");
}

// ── Step 3: Question bank linked to assessment ────────────────────────────────

$stmt = $conn->prepare('SELECT id FROM question_banks WHERE assessment_id = ? LIMIT 1');
$stmt->bind_param('i', $assessmentId);
$stmt->execute();
$existingQbank = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existingQbank) {
    seed_skip("Question bank already exists for assessment {$assessmentId} (id={$existingQbank['id']}) — skipping.");
    $qbankId = (int)$existingQbank['id'];
} else {
    $qbankStatus = 'approved';
    $stmt = $conn->prepare('INSERT INTO question_banks (assessment_id, name, status) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $assessmentId, $qbankName, $qbankStatus);
    if (!$stmt->execute()) {
        seed_die('Failed to insert question bank: ' . $stmt->error);
    }
    $qbankId = (int)$conn->insert_id;
    $stmt->close();

    seed_ok("Question bank created — name: \"{$qbankName}\", id: {$qbankId}, linked to assessment: {$assessmentId}");
}


// ── Step 4: Settings ──────────────────────────────────────────────────────────

$defaultSettings = [
    'question_ratio_easy' => '30',
    'question_ratio_medium' => '40',
    'question_ratio_hard' => '30',
    'slot_grace_minutes' => '30',
    'slot_capacity_floor' => '50',
    'slot_start_time_1' => '10:00:00',
    'slot_start_time_2' => '14:00:00'
];

$stmt = $conn->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
foreach ($defaultSettings as $key => $val) {
    $stmt->bind_param('ss', $key, $val);
    $stmt->execute();
}
$stmt->close();
seed_ok('Settings seeded.');

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n=================================================\n";
echo "Seed complete.\n";
echo "  Admin user id:   {$adminUserId}\n";
echo "  Assessment id:   {$assessmentId}\n";
echo "  Question bank id:{$qbankId}\n";
echo "=================================================\n";
echo "Next step: log in at /admin/index.html with {$adminEmail}\n";
echo "           then use the admin panel to add exam questions.\n";
