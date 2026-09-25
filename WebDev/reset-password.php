<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$error = '';
$msg = '';
$token_valid = false;
$token_value = '';

if (isset($_GET['token'])) {
    $token_value = trim($_GET['token']);
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token_value]);
    $reset = $stmt->fetch();
    if ($reset) {
        $token_valid = true;
    } else {
        $error = 'Invalid or expired reset link. Please request a new one.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token'])) {
    validate_csrf();
    $token_value = trim($_POST['token']);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Fetch first, then claim atomically (single-use, race-safe).
        $stmt = $pdo->prepare("SELECT id, email FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$token_value]);
        $reset = $stmt->fetch();
        if (!$reset) {
            $error = 'Invalid or expired reset link. Please request a new one.';
        } else {
            $claim = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
            $claim->execute([$reset['id']]);
            if ($claim->rowCount() === 0) {
                $error = 'Invalid or expired reset link. Please request a new one.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $uidStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $uidStmt->execute([$reset['email']]);
                $reset_uid = intval($uidStmt->fetchColumn());
                $stmt = $pdo->prepare("UPDATE users SET password = ?, password_updated_at = NOW() WHERE email = ?");
                $stmt->execute([$hash, $reset['email']]);

                logActivity($pdo, $reset_uid, 'password_reset_completed', 'Your password was reset successfully via the email link.');

                header('Location: login.php?reset=1');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>BizVaza — Reset Password</title>
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

<h2 style="font-size:22px;font-weight:700;color:var(--text);text-align:center;margin-bottom:8px">Reset Password</h2>
<p style="font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:24px">Enter your new password</p>

<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($token_valid): ?>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="token" value="<?= htmlspecialchars($token_value) ?>">
<div class="form-group">
<label for="password">New Password</label>
<div class="pw-wrap">
            <input type="password" id="password" name="password" placeholder="At least 8 characters" required minlength="8" style="padding-right:40px"/>
<button type="button" class="pw-toggle" onclick="togglePw(this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div>
</div>
<div class="form-group">
<label for="confirm_password">Confirm Password</label>
<div class="pw-wrap">
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat your password" required minlength="8" style="padding-right:40px"/>
<button type="button" class="pw-toggle" onclick="togglePw(this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div>
</div>
<button type="submit" class="auth-btn">Reset Password</button>
</form>
<?php else: ?>
<div class="auth-switch"><a href="forgot-password.php">Request a new reset link</a></div>
<?php endif; ?>

<div class="auth-switch" style="margin-top:<?= $token_valid ? '20px' : '12px' ?>">
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
