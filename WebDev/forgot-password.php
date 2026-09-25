<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/ratelimit.php';

$msg = '';
$error = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $input = trim($_POST['email_or_phone'] ?? '');
    if ($input) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rlKey = $ip . '|' . strtolower($input);
        [$rlCount, $rlFresh] = rl_state('forgot', $rlKey, 600);
        if ($rlFresh && $rlCount >= 3) {
            $error = 'Too many reset requests. Please wait a few minutes before trying again.';
        } else {
        rl_record('forgot', $rlKey, 600);
        $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE email = ? OR phone = ? LIMIT 1");
        $stmt->execute([$input, $input]);
        $user = $stmt->fetch();

        if ($user) {
            $email = $user['email'];
            // Remove old unused tokens for this email
            $pdo->prepare("DELETE FROM password_resets WHERE email = ? AND used = 0 AND expires_at < NOW()")->execute([$email]);
            $pdo->prepare("DELETE FROM password_resets WHERE email = ? AND used = 0")->execute([$email]);

            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $token, $expires]);

            $reset_link = SITE_URL . '/reset-password.php?token=' . $token;
            sendResetEmail($pdo, $email, $user['full_name'] ?: $email, $reset_link);
            logActivity($pdo, $user['id'], 'password_reset_requested', 'A password reset was requested for your account. If this wasn\'t you, please contact support.');
        }
        $sent = true;
        }
    } else {
        $error = 'Please enter your email or phone number.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>BizVaza — Forgot Password</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="auth-body">
<div class="auth-wrap">
<div class="auth-card">
<a class="auth-logo" href="index.html">
<div class="auth-logo-icon">
<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 2L4 11h7l-2 7 9-10h-7l2-8z" fill="#fff"/></svg>
</div>
<span class="auth-logo-text">BizVaza</span>
</a>

<h2 style="font-size:22px;font-weight:700;color:var(--text);text-align:center;margin-bottom:8px">Forgot Password</h2>
<p style="font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:24px">Enter your registered email or phone number</p>

<?php if ($sent): ?>
<div style="background:var(--success-bg);color:var(--success);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:18px;border:1px solid rgba(16,185,129,.35)">If an account exists for that email or phone number, we've emailed you a password reset link.</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<div class="form-group">
<label for="email_or_phone">Email or Phone Number</label>
<input type="text" id="email_or_phone" name="email_or_phone" placeholder="you@example.com or +234..." required/>
</div>
<button type="submit" class="auth-btn">Send Reset Link</button>
</form>

<div class="auth-switch">
<a href="login.php">&larr; Back to Login</a>
</div>
</div>
<div class="auth-footer">
<a href="index.html">&larr; Back to Home</a>
</div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
