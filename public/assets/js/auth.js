// Path: public/assets/js/auth.js

function showAlert(box, type, message) {
  box.classList.remove('d-none', 'error', 'success');
  box.classList.add(type);
  box.textContent = message;
}

async function postJson(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  return res.json();
}

// Role card toggle (Student / Admin selection on register & login pages)
document.querySelectorAll('.ib-role-card').forEach(card => {
  card.addEventListener('click', () => {
    const group = card.closest('.ib-role-group');
    group.querySelectorAll('.ib-role-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    card.querySelector('input[type="radio"]').checked = true;
  });
});

// Password show/hide eye toggle
document.querySelectorAll('.ib-toggle-password').forEach(btn => {
  btn.addEventListener('click', () => {
    const input = document.getElementById(btn.dataset.target);
    if (input.type === 'password') {
      input.type = 'text';
      btn.classList.add('is-visible');
      btn.setAttribute('aria-label', 'Hide password');
    } else {
      input.type = 'password';
      btn.classList.remove('is-visible');
      btn.setAttribute('aria-label', 'Show password');
    }
  });
});

let pendingEmail = '';

// STEP 1: Registration form -> sends OTP, shows OTP screen
const registerForm = document.getElementById('registerForm');
if (registerForm) {
  registerForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('registerBtn');
    const alertBox = document.getElementById('formAlert');

    const payload = {
      full_name: document.getElementById('full_name').value.trim(),
      email: document.getElementById('email').value.trim(),
      phone: `${document.getElementById('country_code').value}${document.getElementById('phone').value.trim()}`,
      password: document.getElementById('password').value,
      confirm_password: document.getElementById('confirm_password').value,
    };

    if (payload.password !== payload.confirm_password) {
      showAlert(alertBox, 'error', 'Passwords do not match.');
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Sending code...';

    try {
      const data = await postJson('/api/auth/register.php', payload);
      if (data.status === 'success') {
        pendingEmail = payload.email;
        document.getElementById('otpEmailDisplay').textContent = pendingEmail;
        registerForm.classList.add('d-none');
        document.getElementById('otpForm').classList.remove('d-none');
      } else {
        showAlert(alertBox, 'error', data.message || 'Registration failed.');
        btn.disabled = false;
        btn.textContent = 'Register now';
      }
    } catch (err) {
      showAlert(alertBox, 'error', 'Something went wrong. Please try again.');
      btn.disabled = false;
      btn.textContent = 'Register now';
    }
  });
}

// STEP 2: OTP verification form -> creates the actual account
const otpForm = document.getElementById('otpForm');
if (otpForm) {
  otpForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('verifyOtpBtn');
    const alertBox = document.getElementById('otpAlert');

    const payload = {
      email: pendingEmail,
      otp: document.getElementById('otp_code').value.trim(),
    };

    btn.disabled = true;
    btn.textContent = 'Verifying...';

    try {
      const data = await postJson('/api/auth/verify-otp.php', payload);
      if (data.status === 'success') {
        showAlert(alertBox, 'success', 'Account created! Redirecting to login...');
        setTimeout(() => { window.location.href = '/login.php'; }, 1200);
      } else {
        showAlert(alertBox, 'error', data.message || 'Verification failed.');
        btn.disabled = false;
        btn.textContent = 'Verify & Create Account';
      }
    } catch (err) {
      showAlert(alertBox, 'error', 'Something went wrong. Please try again.');
      btn.disabled = false;
      btn.textContent = 'Verify & Create Account';
    }
  });
}

// Resend OTP code
const resendBtn = document.getElementById('resendOtpBtn');
if (resendBtn) {
  resendBtn.addEventListener('click', async function () {
    const alertBox = document.getElementById('otpAlert');
    resendBtn.disabled = true;
    resendBtn.textContent = 'Resending...';

    try {
      const data = await postJson('/api/auth/resend-otp.php', { email: pendingEmail });
      showAlert(alertBox, data.status === 'success' ? 'success' : 'error', data.message);
    } catch (err) {
      showAlert(alertBox, 'error', 'Could not resend code.');
    }

    resendBtn.disabled = false;
    resendBtn.textContent = 'Resend Code';
  });
}

// LOGIN form (unchanged from your original — no OTP needed for login)
const loginForm = document.getElementById('loginForm');
if (loginForm) {
  loginForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('loginBtn');
    const alertBox = document.getElementById('formAlert');

    const payload = {
      email: document.getElementById('email').value.trim(),
      password: document.getElementById('password').value,
      role: loginForm.querySelector('input[name="role"]:checked')?.value,
    };

    btn.disabled = true;
    btn.textContent = 'Logging in...';

    try {
      const data = await postJson('/api/auth/login.php', payload);
      if (data.status === 'success') {
        showAlert(alertBox, 'success', data.message);
        const redirect = (data.data && data.data.redirect) || '/dashboard.html';
        setTimeout(() => { window.location.href = redirect; }, 600);
      } else {
        showAlert(alertBox, 'error', data.message || 'Login failed.');
        btn.disabled = false;
        btn.textContent = 'Login to Dashboard';
      }
    } catch (err) {
      showAlert(alertBox, 'error', 'Something went wrong. Please try again.');
      btn.disabled = false;
      btn.textContent = 'Login to Dashboard';
    }
  });
}

// Forgot Password Form
const forgotForm = document.getElementById('forgotPasswordForm');
if (forgotForm) {
  forgotForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('forgotBtn');
    const alertBox = document.getElementById('forgotFormAlert');

    const payload = {
      email: document.getElementById('email').value.trim()
    };

    btn.disabled = true;
    btn.textContent = 'Sending...';

    try {
      const data = await postJson('/api/auth/forgot-password.php', payload);
      if (data.status === 'success') {
        showAlert(alertBox, 'success', data.message);
      } else {
        showAlert(alertBox, 'error', data.message || 'Failed to send reset link.');
      }
    } catch (err) {
      showAlert(alertBox, 'error', 'Something went wrong. Please try again.');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Send Reset Link';
    }
  });
}

// Reset Password Form
const resetForm = document.getElementById('resetPasswordForm');
if (resetForm) {
  resetForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('resetBtn');
    const alertBox = document.getElementById('resetFormAlert');

    const payload = {
      token: document.getElementById('resetToken').value,
      email: document.getElementById('resetEmail').value,
      new_password: document.getElementById('new_password').value,
      confirm_password: document.getElementById('confirm_password').value
    };

    if (payload.new_password !== payload.confirm_password) {
      showAlert(alertBox, 'error', 'Passwords do not match.');
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Resetting...';

    try {
      const data = await postJson('/api/auth/reset-password.php', payload);
      if (data.status === 'success') {
        showAlert(alertBox, 'success', data.message);
        setTimeout(() => { window.location.href = '/login.php'; }, 2000);
      } else {
        showAlert(alertBox, 'error', data.message || 'Failed to reset password.');
        btn.disabled = false;
        btn.textContent = 'Reset Password';
      }
    } catch (err) {
      showAlert(alertBox, 'error', 'Something went wrong. Please try again.');
      btn.disabled = false;
      btn.textContent = 'Reset Password';
    }
  });
}