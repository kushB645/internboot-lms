<?php
require_once __DIR__ . '/../src/core/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard.html');
    exit;
}

$pageTitle = 'Register — InternBoot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="assets/css/auth.css?v=6">
</head>
<body>

<main class="ib-auth-page">
  <div class="ib-auth-grid">

    <section class="ib-brand-panel">
      <img src="assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-brand-logo">
      <h2 class="ib-brand-title">Your Gateway to Professional Growth</h2>
      <p class="ib-brand-sub">Take your assessment, earn a verified certificate, and build a stronger path toward your next opportunity.</p>
      <ul class="ib-brand-features">
        <li><strong>Level 1–5 Assessment</strong><span>Show what you know with a structured skill evaluation</span></li>
        <li><strong>Verified Certification</strong><span>Earn an official certificate that strengthens your profile</span></li>
        <li><strong>Practical Experience</strong><span>Work on real-world projects with expert guidance</span></li>
        <li><strong>Placement Support</strong><span>Build your portfolio and move closer to your career goals</span></li>
      </ul>
      <div class="ib-brand-stats">
        <span><strong>10,000+</strong>Students</span>
        <span><strong>500+</strong>Companies</span>
        <span><strong>4.9/5</strong>Rating</span>
      </div>
    </section>

    <section class="ib-form-panel">
      <div class="ib-auth-card">
        <img src="assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-mobile-logo">

        <h1 class="ib-auth-title text-center">Create Account</h1>
        <p class="ib-auth-sub text-center">Fill in your details to start your internship journey</p>

        <form id="registerForm" novalidate>

          <div class="ib-role-group ib-register-role" style="justify-content: center;">
            <label class="ib-role-card selected" id="roleCardStudent" style="max-width: 100%;">
              <input type="radio" name="role" value="candidate" checked hidden>
              <span class="ib-role-icon">🎓</span>
              <span class="ib-role-label">Candidate Registration</span>
              <span class="ib-role-desc">Take assessments &amp; earn certificates</span>
            </label>
          </div>

          <div class="ib-form-row">
            <label for="full_name">Full Name</label>
            <input type="text" class="ib-input" id="full_name" name="full_name" autocomplete="name" required>
          </div>
          <div class="ib-form-row">
            <label for="email">Email Address</label>
            <input type="email" class="ib-input" id="email" name="email" autocomplete="email" required>
          </div>
          <div class="ib-form-row">
            <label for="phone">Phone Number</label>
            <div class="ib-phone-input">
              <select class="ib-country-code" id="country_code" name="country_code" aria-label="Country code">
                <option value="+91" selected>+91</option>
                <option value="+1">+1</option>
                <option value="+7">+7</option>
                <option value="+20">+20</option>
                <option value="+27">+27</option>
                <option value="+30">+30</option>
                <option value="+31">+31</option>
                <option value="+32">+32</option>
                <option value="+33">+33</option>
                <option value="+34">+34</option>
                <option value="+39">+39</option>
                <option value="+40">+40</option>
                <option value="+41">+41</option>
                <option value="+43">+43</option>
                <option value="+44">+44</option>
                <option value="+45">+45</option>
                <option value="+46">+46</option>
                <option value="+47">+47</option>
                <option value="+48">+48</option>
                <option value="+49">+49</option>
                <option value="+52">+52</option>
                <option value="+55">+55</option>
                <option value="+60">+60</option>
                <option value="+61">+61</option>
                <option value="+62">+62</option>
                <option value="+63">+63</option>
                <option value="+64">+64</option>
                <option value="+65">+65</option>
                <option value="+66">+66</option>
                <option value="+81">+81</option>
                <option value="+82">+82</option>
                <option value="+84">+84</option>
                <option value="+86">+86</option>
                <option value="+90">+90</option>
                <option value="+92">+92</option>
                <option value="+93">+93</option>
                <option value="+94">+94</option>
                <option value="+95">+95</option>
                <option value="+98">+98</option>
                <option value="+212">+212</option>
                <option value="+213">+213</option>
                <option value="+216">+216</option>
                <option value="+218">+218</option>
                <option value="+234">+234</option>
                <option value="+254">+254</option>
                <option value="+255">+255</option>
                <option value="+256">+256</option>
                <option value="+966">+966</option>
                <option value="+971">+971</option>
                <option value="+972">+972</option>
                <option value="+973">+973</option>
                <option value="+974">+974</option>
                <option value="+977">+977</option>
              </select>
              <input type="tel" class="ib-input" id="phone" name="phone" maxlength="14" inputmode="numeric" autocomplete="tel-national" required>
            </div>
          </div>
          <div class="ib-form-row">
            <label for="password">Password</label>
            <div class="ib-password-wrap">
              <input type="password" class="ib-input" id="password" name="password" minlength="8" autocomplete="new-password" required>
            </div>
          </div>
          <div class="ib-form-row">
            <label for="confirm_password">Confirm Password</label>
            <div class="ib-password-wrap">
              <input type="password" class="ib-input" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
            </div>
          </div>

          <div id="formAlert" class="ib-alert d-none"></div>

          <button type="submit" class="ib-btn-primary" id="registerBtn">Register now</button>
        </form>

        <form id="otpForm" novalidate class="d-none">
          <p class="ib-auth-sub text-center" style="margin-bottom: 28px;">
            We've sent a 6-digit code to <strong id="otpEmailDisplay"></strong>. Enter it below to verify your account.
          </p>

          <div class="ib-form-row">
            <label for="otp_code">Verification Code</label>
            <input type="text" class="ib-input" id="otp_code" name="otp_code" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" style="letter-spacing: 8px; font-size: 1.4rem; text-align: center; font-weight: 700;" required>
          </div>

          <div id="otpAlert" class="ib-alert d-none"></div>

          <button type="submit" class="ib-btn-primary" id="verifyOtpBtn">Verify &amp; Create Account</button>
          <button type="button" id="resendOtpBtn" class="ib-btn-primary" style="background: transparent; color: var(--ib-blue); margin-top: 12px; box-shadow: none;">Resend Code</button>
        </form>

        <p class="ib-auth-footer">Already registered? <a href="login.php">Log in</a></p>
      </div>
    </section>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="assets/js/auth.js"></script>
</body>
</html>