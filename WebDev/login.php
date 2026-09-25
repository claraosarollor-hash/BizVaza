<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ratelimit.php';

$error = '';
$success = isset($_GET['reset']) ? 'Password reset successful! You can now log in with your new password.' : '';
$suspended = isset($_GET['suspended']) ? 'Your account has been suspended by the administrator.' : '';
$dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rlKey = $ip . '|' . strtolower($email);
        [$rlCount, $rlFresh] = rl_state('login', $rlKey, 900);
        if ($rlFresh && $rlCount >= 5) {
            http_response_code(429);
            $error = 'Too many failed attempts. Please try again in 15 minutes.';
        } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $passwordOk = $user
            ? password_verify($password, $user['password'])
            : password_verify($password, $dummyHash);

        if ($user && $passwordOk) {
            if (!empty($user['suspended'])) {
                $error = 'Your account has been suspended. Please contact support.';
            } else {
            rl_reset('login', $rlKey);
            session_regenerate_id(true);
            unset($_SESSION['_csrf_token']);
            $_SESSION['pw_epoch'] = (string)($user['password_updated_at'] ?? '');
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['business_name'] = $user['business_name'];
            $_SESSION['business_abbr'] = $user['business_abbr'] ?? generateAbbr($user['business_name']);
            $_SESSION['email'] = $user['email'];
            $_SESSION['avatar_initials'] = getAvatarInitials($user['full_name']);
            $_SESSION['business_phone'] = $user['business_phone'] ?? '';
            $_SESSION['business_address'] = $user['business_address'] ?? '';
            $_SESSION['bank_name'] = $user['bank_name'] ?? '';
            $_SESSION['account_name'] = $user['account_name'] ?? '';
            $_SESSION['account_number'] = $user['account_number'] ?? '';
            $_SESSION['invoice_default_notes'] = $user['invoice_default_notes'] ?? '';
            $_SESSION['receipt_default_notes'] = $user['receipt_default_notes'] ?? '';
            $_SESSION['phone'] = $user['phone'] ?? '';
            $_SESSION['currency'] = $user['currency'] ?? 'NGN';
            $_SESSION['logo_path'] = $user['logo_path'] ?? '';
            $_SESSION['role'] = $user['role'] ?? 'user';
            $proId = proPlanId($pdo);
            $freeId = freePlanId($pdo);
            $plan_id = (int)($user['plan_id'] ?? $freeId);
            $plan_expires = $user['plan_expires_at'] ?? null;
            if ($plan_expires && strtotime($plan_expires) < time() && $proId > 0 && $plan_id === $proId) {
                $plan_id = $freeId;
                $pdo->prepare("UPDATE users SET plan_id = ?, plan_updated_at = NOW() WHERE id = ?")->execute([$freeId, $user['id']]);
                clearPremiumSelections($pdo, $user['id']);
                addNotification($pdo, $user['id'], 'Subscription Expired', 'Your Pro plan has expired and your account was moved to the Free plan. Upgrade anytime to restore Pro features.');
            }
            $_SESSION['plan_id'] = $plan_id;
            $_SESSION['plan_name'] = ($proId > 0 && $plan_id === $proId) ? 'Pro' : 'Free';
            $_SESSION['is_pro'] = ($proId > 0 && $plan_id === $proId);
            $_SESSION['trial_ends_at'] = $user['trial_ends_at'] ?? null;
            $_SESSION['account_locked'] = ($user['role'] !== 'admin')
                && !($proId > 0 && $plan_id === $proId)
                && !(!empty($user['trial_ends_at']) && strtotime($user['trial_ends_at']) > time());
            $_SESSION['selected_template_id'] = $user['selected_template_id'] ?? null;
            $_SESSION['selected_receipt_template_id'] = $user['selected_receipt_template_id'] ?? null;
            logActivityOnly($pdo, $user['id'], 'login', 'Signed in to BizVaza.');
            header('Location: ' . (!empty($_SESSION['account_locked']) ? 'paywall.php' : 'dashboard.php'));
            exit;
            }
        } else {
            rl_record('login', $rlKey, 900);
            $error = 'Invalid email or password.';
        }
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Log In</title>
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

<h2 style="font-size:22px;font-weight:700;color:var(--text);text-align:center;margin-bottom:24px">Welcome Back</h2>

<?php if ($suspended): ?>
<div class="error"><?= htmlspecialchars($suspended) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div style="background:var(--success-bg);color:var(--success);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:18px;border:1px solid rgba(16,185,129,.35)"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<div class="form-group">
<label for="email">Email Address</label>
<input type="email" id="email" name="email" placeholder="you@example.com" required/>
</div>
<div class="form-group">
<label for="password">Password</label>
<div class="pw-wrap">
<input type="password" id="password" name="password" placeholder="Enter your password" required style="padding-right:40px"/>
<button type="button" class="pw-toggle" onclick="togglePw(this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div>
<div style="text-align:right;margin-top:6px"><a href="forgot-password.php" style="font-size:12px;color:var(--accent);font-weight:500;text-decoration:none">Forgot your password?</a></div>
</div>
<button type="submit" class="auth-btn">Log In</button>
<div class="auth-switch">
Don't have an account? <a href="register.php">Sign Up</a>
</div>
</form>
</div>
<div class="auth-footer">
 <a href="index.html">&larr; Back to Home</a>
</div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
