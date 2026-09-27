<?php
// Path: public/reset-password.php
require_once __DIR__ . '/../src/core/bootstrap.php';

// Sensitive single-use token in query string: prevent Referer leakage
header('Referrer-Policy: no-referrer', true);

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

if (!$token || !$email) {
    header('Location: /login.php');
    exit;
}

$pageTitle = 'Reset Password — InternBoot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<main class="ib-auth-page-rich">
  <div class="ib-auth-card-full">
    <img src="assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-form-logo">
    <h1 class="ib-auth-title text-center">Create new <span class="ib-gradient-text">Password</span></h1>
    <p class="ib-auth-sub text-center">Enter your new password below.</p>

    <form id="resetPasswordForm" novalidate>
      <input type="hidden" id="resetToken" name="token" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" id="resetEmail" name="email" value="<?= htmlspecialchars($email) ?>">

      <div class="ib-form-row">
        <label for="new_password">New Password</label>
        <div class="ib-password-wrap">
          <input type="password" class="ib-input" id="new_password" name="new_password" required minlength="8">
          <button type="button" class="ib-toggle-password" data-target="new_password" aria-label="Show password"></button>
        </div>
      </div>
      <div class="ib-form-row">
        <label for="confirm_password">Confirm Password</label>
        <div class="ib-password-wrap">
          <input type="password" class="ib-input" id="confirm_password" name="confirm_password" required minlength="8">
          <button type="button" class="ib-toggle-password" data-target="confirm_password" aria-label="Show password"></button>
        </div>
      </div>

      <div id="resetFormAlert" class="ib-alert d-none"></div>

      <button type="submit" class="ib-btn-primary" id="resetBtn">Reset Password</button>
    </form>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="assets/js/auth.js"></script>
</body>
</html>
