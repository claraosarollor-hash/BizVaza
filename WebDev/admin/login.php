<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ratelimit.php';

$error = '';
$dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rlKey = $ip . '|' . strtolower($email);
        [$rlCount, $rlFresh] = rl_state('login_admin', $rlKey, 900);
        if ($rlFresh && $rlCount >= 5) {
            http_response_code(429);
            $error = 'Too many failed attempts. Please try again in 15 minutes.';
        } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            if (!empty($user['suspended'])) {
                password_verify($password, $dummyHash);
                $error = 'Invalid email or password.';
            } elseif (password_verify($password, $user['password'])) {
                rl_reset('login_admin', $rlKey);
                session_regenerate_id(true);
                unset($_SESSION['_csrf_token']);
                $_SESSION['pw_epoch'] = (string)($user['password_updated_at'] ?? '');
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['business_name'] = $user['business_name'];
                $_SESSION['business_abbr'] = $user['business_abbr'] ?? '';
                $_SESSION['email'] = $user['email'];
                $_SESSION['avatar_initials'] = getAvatarInitials($user['full_name']);
                $_SESSION['role'] = 'admin';
                header('Location: index.php');
                exit;
            } else {
                rl_record('login_admin', $rlKey, 900);
                $error = 'Invalid email or password.';
            }
        } else {
            password_verify($password, $dummyHash);
            rl_record('login_admin', $rlKey, 900);
            $error = 'Invalid email or password.';
        }
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Login</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Inter",sans-serif;background:#0d2141;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:24px}
.auth-wrap{width:400px;max-width:100%}
.auth-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:40px 36px 36px;box-shadow:0 8px 32px rgba(0,0,0,.2)}
.auth-logo{display:flex;align-items:center;gap:10px;justify-content:center;margin-bottom:28px;text-decoration:none}
.auth-logo-icon{width:40px;height:40px;background:#0d2141;border-radius:10px;display:flex;align-items:center;justify-content:center}
.auth-logo-icon svg{width:22px;height:22px}
.auth-logo-text{font-size:20px;font-weight:800;color:var(--text);letter-spacing:.02em}
.admin-badge{display:inline-block;background:#d1fae5;color:#059669;font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:20px}
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px}
.form-group input{width:100%;padding:12px 14px;border:1px solid var(--border-input);border-radius:8px;font-size:14px;font-family:"Inter",sans-serif;color:var(--text);background:var(--bg-card);outline:none;transition:border-color .15s}
.form-group input:focus{border-color:var(--accent)}
.form-group input::placeholder{color:var(--text-muted)}
.auth-btn{width:100%;padding:13px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;font-family:"Inter",sans-serif;cursor:pointer;margin-top:8px}
.auth-btn:hover{background:var(--accent-hover)}
.error{background:var(--error-bg);color:var(--error);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:18px;border:1px solid rgba(239,68,68,.35)}
.back-link{display:block;text-align:center;margin-top:20px;font-size:13px;color:var(--text-light);text-decoration:none}
.back-link:hover{color:var(--text)}
</style>
</head>
<body>
<div class="auth-wrap">
<div class="auth-card">
<div style="text-align:center">
<div class="admin-badge">Admin</div>
</div>
<a class="auth-logo" href="#">
<div class="auth-logo-icon">
<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 2L4 11h7l-2 7 9-10h-7l2-8z" fill="#fff"/></svg>
</div>
<span class="auth-logo-text">BizVaza</span>
</a>
<h2 style="font-size:20px;font-weight:700;color:var(--text);text-align:center;margin-bottom:24px">Admin Portal</h2>
<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<form method="post">
<?= csrf_field() ?>
<div class="form-group">
<label for="email">Admin Email</label>
<input type="email" id="email" name="email" placeholder="admin@bizvaza.com" required/>
</div>
<div class="form-group">
<label for="password">Password</label>
<input type="password" id="password" name="password" placeholder="Enter password" required/>
</div>
<button type="submit" class="auth-btn">Sign In</button>
</form>
<a href="../index.html" class="back-link">&larr; Back to Main Site</a>
</div>
</div>
</body>
</html>
