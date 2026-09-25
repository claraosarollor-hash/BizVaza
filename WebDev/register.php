<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $full_name = trim($_POST['full_name'] ?? '');
    $business_name = trim($_POST['business_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($full_name && $business_name && $email && $phone && $password) {
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
        $stmt->execute([$email, $phone]);
        if ($stmt->fetch()) {
            $error = 'An account with this email or phone already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $business_abbr = generateAbbr($business_name);
            $trialEnd = date('Y-m-d H:i:s', strtotime('+' . TRIAL_MONTHS . ' months'));
            $freeId = freePlanId($pdo) ?: 1;
            $stmt = $pdo->prepare("INSERT INTO users (full_name, business_name, business_abbr, email, phone, business_phone, password, currency, plan_id, role, password_updated_at, trial_ends_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'user', NOW(), ?)");
            $stmt->execute([$full_name, $business_name, $business_abbr, $email, $phone, $phone, $hash, 'NGN', $freeId, $trialEnd]);

            session_regenerate_id(true);
            unset($_SESSION['_csrf_token']);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['pw_epoch'] = date('Y-m-d H:i:s');
            $_SESSION['full_name'] = $full_name;
            $_SESSION['business_name'] = $business_name;
            $_SESSION['business_abbr'] = $business_abbr;
            $_SESSION['email'] = $email;
            $_SESSION['avatar_initials'] = getAvatarInitials($full_name);
            $_SESSION['business_phone'] = '';
            $_SESSION['business_address'] = '';
            $_SESSION['bank_name'] = '';
            $_SESSION['account_name'] = '';
            $_SESSION['account_number'] = '';
            $_SESSION['phone'] = $phone;
            $_SESSION['currency'] = 'NGN';
            $_SESSION['logo_path'] = '';
            $_SESSION['role'] = 'user';
            $_SESSION['plan_id'] = $freeId;
            $_SESSION['plan_name'] = 'Free';
            $_SESSION['is_pro'] = false;
            $_SESSION['trial_ends_at'] = $trialEnd;
            $_SESSION['account_locked'] = false;
            $_SESSION['selected_template_id'] = null;
            $_SESSION['selected_receipt_template_id'] = null;
            $new_uid = intval($_SESSION['user_id']);
            logActivity($pdo, $new_uid, 'account_created', "Welcome to BizVaza, {$full_name}! Your account was created successfully — your free trial starts now.", 'user', $new_uid);
            header('Location: dashboard.php');
            exit;
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
<title>BizVaza — Sign Up</title>
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

<h2 style="font-size:22px;font-weight:700;color:var(--text);text-align:center;margin-bottom:24px">Create Your Account</h2>

<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<div class="form-group">
<label for="full_name">Full Name</label>
<input type="text" id="full_name" name="full_name" placeholder="Personal name" required/>
</div>
<div class="form-group">
<label for="business_name">Business Name</label>
<input type="text" id="business_name" name="business_name" placeholder="Your business name" required/>
</div>
<div class="form-group">
<label for="email">Email Address</label>
<input type="email" id="email" name="email" placeholder="you@example.com" required/>
</div>
<div class="form-group">
<label for="phone">WhatsApp / Phone Number</label>
<input type="tel" id="phone" name="phone" placeholder="+234 801 234 5678" required/>
</div>
<div class="form-group">
<label for="password">Password</label>
<div class="pw-wrap">
            <input type="password" id="password" name="password" placeholder="At least 8 characters" required minlength="8" style="padding-right:40px"/>
<button type="button" class="pw-toggle" onclick="togglePw(this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div>
</div>
<button type="submit" class="auth-btn">Create Account</button>
<div class="auth-switch">
Already have an account? <a href="login.php">Log In</a>
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
