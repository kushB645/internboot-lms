<?php
// Path: public/forgot-password.php
require_once __DIR__ . '/../src/core/bootstrap.php';
$pageTitle = 'Forgot Password — InternBoot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<main class="ib-auth-page-rich">
  <div class="ib-auth-card-full">
    <img src="assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-form-logo">
    <h1 class="ib-auth-title text-center">Reset your <span class="ib-gradient-text">Password</span></h1>
    <p class="ib-auth-sub text-center">Enter your email and we'll send you a reset link.</p>

    <form id="forgotPasswordForm" novalidate>
      <div class="ib-form-row">
        <label for="email">Email Address</label>
        <input type="email" class="ib-input" id="email" name="email" autocomplete="email" required>
      </div>

      <div id="forgotFormAlert" class="ib-alert d-none"></div>

      <button type="submit" class="ib-btn-primary" id="forgotBtn">Send Reset Link</button>
    </form>

    <p class="ib-auth-footer text-center mt-3"><a href="login.php">Back to Login</a></p>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="assets/js/auth.js"></script>
</body>
</html>
