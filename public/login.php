<?php
require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/core/candidate_resolver.php';

if (isset($_SESSION['user_id']) || isset($_SESSION['candidate_id'])) {
    $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';

    if (in_array($role, ['admin', 'staff'], true)) {
        $validatedRole = resolve_admin_role($conn);
        if ($validatedRole && in_array($validatedRole, ['admin', 'staff'], true)) {
            header('Location: /admin/index.html');
            exit;
        }
    } elseif ($role === 'candidate' || isset($_SESSION['candidate_id'])) {
        $validatedCid = validate_candidate_session($conn);
        if ($validatedCid !== null) {
            header('Location: /dashboard.html');
            exit;
        }
    } else {
        // Unknown or unset role: check admin/staff first, then candidate
        $validatedRole = resolve_admin_role($conn);
        if ($validatedRole && in_array($validatedRole, ['admin', 'staff'], true)) {
            header('Location: /admin/index.html');
            exit;
        }
        $validatedCid = validate_candidate_session($conn);
        if ($validatedCid !== null) {
            header('Location: /dashboard.html');
            exit;
        }
    }

    // If validation failed (deactivated, row missing, or session stale), destroy session entirely
    destroy_session();
}

$pageTitle = 'Log In — InternBoot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="assets/css/auth.css?v=8">
</head>
<body>

<main class="ib-auth-page ib-login-page">
  <div class="ib-auth-grid">

    <section class="ib-brand-panel">
      <img src="assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-brand-logo">
      <p class="ib-brand-kicker">Your Gateway to Professional Growth</p>
      <div class="ib-login-welcome">
        <h2>Welcome Back!</h2>
        <p>Log in to continue your assessment journey, discover your skill level, and unlock verified certification opportunities.</p>
      </div>
      <ul class="ib-brand-features">
        <li><strong>Download certificates anytime</strong></li>
        <li><strong>Connect with mentors &amp; peers</strong></li>
      </ul>
      <div class="ib-login-illustration" aria-hidden="true">
        <span class="ib-illustration-lines"></span>
        <span class="ib-illustration-card"><i></i><i></i><i></i></span>
        <span class="ib-illustration-check">✓</span>
      </div>
    </section>

    <section class="ib-form-panel">
      <div class="ib-auth-card">

        <img src="assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-mobile-logo">

        <p class="ib-login-eyebrow">👋 &nbsp;Welcome back</p>
        <h1 class="ib-auth-title">Student Login</h1>
        <span class="ib-login-title-line"></span>
        <p class="ib-auth-sub">Enter your credentials to access your dashboard</p>

        <form id="loginForm" novalidate>

      <div class="ib-form-row">
      <label for="email">Email Address</label>
        <input type="email" class="ib-input" id="email" name="email" autocomplete="email" required>
      </div>
      <div class="ib-form-row">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <label for="password" class="mb-0">Password</label>
            <a href="forgot-password.php" class="text-decoration-none" style="font-size: 0.85rem; color: #1E4FD1;">Forgot password?</a>
        </div>
        <div class="ib-password-wrap">
          <input type="password" class="ib-input" id="password" name="password" autocomplete="current-password" required>
          <button type="button" class="ib-toggle-password" data-target="password" aria-label="Show password">
            <svg class="eye-icon eye-open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-icon eye-closed" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </div>

      <div id="formAlert" class="ib-alert d-none"></div>

      <button type="submit" class="ib-btn-primary" id="loginBtn">Login to Dashboard</button>
        </form>

        <div class="ib-login-divider"><span>OR</span></div>
        <p class="ib-login-new">Don't have an account?</p>
        <a class="ib-login-register" href="register.php">Register Here</a>
        <p class="ib-login-security">♧ &nbsp;256-bit SSL Encrypted&nbsp; · &nbsp;<strong>InternBoot</strong></p>
      </div>
    </section>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="assets/js/auth.js"></script>
</body>
</html>