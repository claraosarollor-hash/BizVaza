<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'users';

if (!adminCan('users') && !adminCan('team')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$uid = intval($_GET['id'] ?? 0);
if (!$uid) { header('Location: users.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch();
if (!$user) { header('Location: users.php'); exit; }

$flash = '';

$actorIsOwner = ($GLOBALS['admin_pages'] ?? null) === null;
$targetIsAdmin = $user['role'] === 'admin';
$canMutate = adminCan('users')
    && ($actorIsOwner || !$targetIsAdmin);
function requireUserMutate($canMutate): void {
    if (!$canMutate) {
        $_SESSION['flash'] = 'You do not have permission to modify that account.';
        header('Location: users.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_plan') {
    validate_csrf();
    requireUserMutate($canMutate);
    $plan_id = intval($_POST['plan_id']);
    $plan_expires = !empty($_POST['plan_expires_at']) ? $_POST['plan_expires_at'] : null;
    $no_expiry = isset($_POST['no_expiry']);
    if ($no_expiry) $plan_expires = null;
    $pdo->prepare("UPDATE users SET plan_id = ?, plan_updated_at = NOW(), plan_expires_at = ? WHERE id = ?")->execute([$plan_id, $plan_expires, $uid]);
    $proId = proPlanId($pdo);
    $freeId = freePlanId($pdo);
    if ($proId > 0 && $plan_id !== $proId) clearPremiumSelections($pdo, $uid);
    logActivity($pdo, $_SESSION['user_id'], 'admin_plan_change', "Admin changed {$user['full_name']}'s plan to " . ($proId > 0 && $plan_id === $proId ? 'Pro' : 'Free'), 'user', $uid);
    if ($proId > 0 && $plan_id === $proId) {
        $plan_note = $plan_expires
            ? "Your plan was changed to Pro by an administrator — expires " . date('M j, Y', strtotime($plan_expires)) . "."
            : "Your plan was changed to Pro by an administrator — no expiry.";
        addNotification($pdo, $uid, 'Plan Upgraded', $plan_note);
    } else {
        addNotification($pdo, $uid, 'Plan Changed', "Your plan was changed to Free by an administrator.");
    }
    $flash = 'Plan updated successfully.';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$uid]); $user = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_suspend') {
    validate_csrf();
    requireUserMutate($canMutate);
    $new = $user['suspended'] ? 0 : 1;
    $pdo->prepare("UPDATE users SET suspended = ? WHERE id = ?")->execute([$new, $uid]);
    logActivity($pdo, $_SESSION['user_id'], 'admin_' . ($new ? 'suspend' : 'unsuspend'), "Admin " . ($new ? 'suspended' : 'unsuspended') . " {$user['full_name']}", 'user', $uid);
    addNotification($pdo, $uid, $new ? 'Account Suspended' : 'Account Reactivated', $new
        ? "Your account has been suspended by an administrator. Contact support if you believe this is a mistake."
        : "Your account has been reactivated. Welcome back!");
    $flash = $new ? 'User suspended.' : 'User activated.';
    $user['suspended'] = $new;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    validate_csrf();
    requireUserMutate($canMutate);
    $pw = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (strlen($pw) < 8) $flash = 'Password must be at least 8 characters.';
    elseif ($pw !== $confirm) $flash = 'Passwords do not match.';
    else {
        $pdo->prepare("UPDATE users SET password = ?, password_updated_at = NOW() WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
        logActivity($pdo, $_SESSION['user_id'], 'admin_password_reset', "Admin reset {$user['full_name']}'s password", 'user', $uid);
        addNotification($pdo, $uid, 'Password Reset', "Your password has been reset by an administrator. Please log in with your new password.");
        $flash = 'Password reset successfully.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
    validate_csrf();
    requireUserMutate($canMutate);
    $fn = trim($_POST['full_name'] ?? $user['full_name']);
    $bn = trim($_POST['business_name'] ?? $user['business_name']);
    $em = trim($_POST['email'] ?? $user['email']);
    $ph = trim($_POST['phone'] ?? $user['phone']);
    $bp = trim($_POST['business_phone'] ?? $user['business_phone'] ?? '');
    $ba = trim($_POST['business_address'] ?? $user['business_address'] ?? '');
    $cur = strtoupper(trim($_POST['currency'] ?? $user['currency'] ?? 'NGN'));
    $bk = trim($_POST['bank_name'] ?? $user['bank_name'] ?? '');
    $an = trim($_POST['account_name'] ?? $user['account_name'] ?? '');
    $anum = trim($_POST['account_number'] ?? $user['account_number'] ?? '');
    $pdo->prepare("UPDATE users SET full_name=?, business_name=?, email=?, phone=?, business_phone=?, business_address=?, currency=?, bank_name=?, account_name=?, account_number=? WHERE id=?")->execute([$fn, $bn, $em, $ph, $bp, $ba, $cur, $bk, $an, $anum, $uid]);
    logActivity($pdo, $_SESSION['user_id'], 'admin_profile_edit', "Admin edited {$user['full_name']}'s profile", 'user', $uid);
    addNotification($pdo, $uid, 'Profile Updated', "Your profile has been updated by an administrator. This includes your name, business details, and banking information.");
    $flash = 'Profile updated successfully.';
    $user['full_name'] = $fn; $user['business_name'] = $bn; $user['email'] = $em; $user['phone'] = $ph;
    $user['business_phone'] = $bp; $user['business_address'] = $ba; $user['currency'] = $cur;
    $user['bank_name'] = $bk; $user['account_name'] = $an; $user['account_number'] = $anum;
}

$proPlanId = proPlanId($pdo);
$freePlanIdV = freePlanId($pdo);
$planName = ($proPlanId > 0 && (int)($user['plan_id'] ?? 0) === $proPlanId) ? 'Pro' : 'Free';

$invCount = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ?"); $invCount->execute([$uid]); $invCount = $invCount->fetchColumn();
$revTotal = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = ? AND status = 'paid'"); $revTotal->execute([$uid]); $revTotal = $revTotal->fetchColumn();
$invoices = $pdo->prepare("SELECT * FROM invoices WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"); $invoices->execute([$uid]); $invoices = $invoices->fetchAll();
$receipts = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"); $receipts->execute([$uid]); $receipts = $receipts->fetchAll();
$activities = $pdo->prepare("SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"); $activities->execute([$uid]); $activities = $activities->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin: <?= htmlspecialchars($user['full_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);display:flex;min-height:100vh;font-size:13px;color:var(--text)}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--topbar-bg);border-bottom:1px solid var(--border);padding:16px 28px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.topbar h2{font-size:18px;font-weight:600;color:var(--text)}
.content{padding:28px 24px 0;flex:1;max-width:900px}
.flash{background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
.flash.error{background:var(--error-bg);border-color:rgba(239,68,68,.35);color:var(--error)}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 16px;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.card-head h3{font-size:14px;font-weight:600;color:var(--text)}
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 20px}
.profile-grid .item .lbl{font-size:11px;color:var(--text-light);font-weight:500}
.profile-grid .item .val{font-size:13px;color:var(--text);font-weight:600;margin-top:1px}
.badge{display:inline-block;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:600}
.b-pro{background:var(--success-bg);color:var(--success)}
.b-free{background:var(--accent-bg);color:var(--accent)}
.b-suspended{background:var(--error-bg);color:var(--error);border:1px solid rgba(239,68,68,.35)}
.b-admin{background:rgba(99,102,241,.12);color:#4338ca}
.action-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.action-box{background:var(--bg-card-alt);border:1px solid var(--border);border-radius:8px;padding:14px}
.action-box h4{font-size:13px;font-weight:700;color:var(--text);margin-bottom:8px}
.action-box label{display:block;font-size:11px;font-weight:500;color:var(--text-light);margin-bottom:4px;margin-top:6px}
.action-box input,.action-box select{width:100%;padding:7px 10px;border:1px solid var(--border-input);border-radius:6px;font-size:12px;font-family:'Inter',sans-serif;outline:none;background:var(--bg-card)}
.action-box input:focus,.action-box select:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;padding:7px 14px;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border-input);background:var(--bg-card);color:var(--text);text-decoration:none;font-family:'Inter',sans-serif;margin-top:6px}
.btn:hover{background:var(--btn-hover)}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-hover)}
.btn-danger{background:var(--error);color:#fff;border-color:var(--error)}
.btn-danger:hover{background:#b91c1c}
.btn-success{background:var(--success);color:#fff;border-color:var(--success)}
.btn-success:hover{background:#047857}
.tabs{display:flex;gap:2px;margin-bottom:12px}
.tab{flex:1;text-align:center;padding:9px;border-radius:6px 6px 0 0;cursor:pointer;font-size:12px;font-weight:600;background:var(--bg-card-alt);color:var(--text-light);border:none;font-family:'Inter',sans-serif}
.tab.active{background:var(--bg-card);color:var(--text);border:1px solid var(--border);border-bottom:1px solid var(--bg-card)}
.tab-panel{display:none}
.tab-panel.active{display:block}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;color:var(--text-muted);font-weight:500;text-align:left;padding:8px 10px;background:var(--table-header);border-bottom:1px solid var(--table-border);white-space:nowrap}
tbody td{padding:10px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--table-border)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--bg-hover)}
.checkbox-row{display:flex;align-items:center;gap:6px;margin-top:4px}
.checkbox-row input[type=checkbox]{width:auto}
@media(max-width:700px){.action-grid{grid-template-columns:1fr}}
@media(max-width:600px){.profile-grid{grid-template-columns:1fr}.profile-form-grid{grid-template-columns:1fr!important}.bank-grid{grid-template-columns:1fr!important}}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar">
<div style="display:flex;align-items:center;gap:12px">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<a href="users.php" style="color:var(--text-light);text-decoration:none;font-size:13px">&larr; Users</a>
<h2><?= htmlspecialchars($user['full_name']) ?></h2>
</div>
<div>
<span class="badge <?= !empty($user['suspended']) ? 'b-suspended' : (($proPlanId > 0 && (int)($user['plan_id'] ?? 0) === $proPlanId) ? 'b-pro' : 'b-free') ?>"><?= !empty($user['suspended']) ? 'Suspended' : $planName ?></span>
<?php if ($user['role'] === 'admin'): ?><span class="badge b-admin" style="margin-left:6px">Admin</span><?php endif; ?>
</div>
</div>
<div class="content">

<?php if ($flash): ?><div class="flash<?= strpos($flash, 'must') !== false || strpos($flash, 'not') !== false ? ' error' : '' ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card">
<div class="card-head"><h3>Account Details</h3></div>
<div class="profile-grid">
<div class="item"><div class="lbl">Full Name</div><div class="val"><?= htmlspecialchars($user['full_name']) ?></div></div>
<div class="item"><div class="lbl">Business</div><div class="val"><?= htmlspecialchars($user['business_name']) ?></div></div>
<div class="item"><div class="lbl">Email</div><div class="val"><?= htmlspecialchars($user['email']) ?></div></div>
<div class="item"><div class="lbl">Phone</div><div class="val"><?= htmlspecialchars($user['phone']) ?></div></div>
<div class="item"><div class="lbl">Business Phone</div><div class="val"><?= htmlspecialchars($user['business_phone'] ?? '—') ?></div></div>
<div class="item"><div class="lbl">Currency</div><div class="val"><?= htmlspecialchars($user['currency'] ?? 'NGN') ?></div></div>
            <div class="item"><div class="lbl">Plan</div><div class="val"><?= $planName ?><?= $user['plan_expires_at'] ? ' (expires ' . date('M j, Y', strtotime($user['plan_expires_at'])) . ')' : ($planName === 'Pro' ? ' (no expiry)' : '') ?></div></div>
<div class="item"><div class="lbl">Invoices / Revenue</div><div class="val"><?= $invCount ?> invoices &middot; ₦<?= number_format($revTotal, 2) ?></div></div>
<div class="item"><div class="lbl">Bank Name</div><div class="val"><?= htmlspecialchars($user['bank_name'] ?? '—') ?></div></div>
<div class="item"><div class="lbl">Account Name</div><div class="val"><?= htmlspecialchars($user['account_name'] ?? '—') ?></div></div>
<div class="item"><div class="lbl">Account Number</div><div class="val"><?= htmlspecialchars($user['account_number'] ?? '—') ?></div></div>
<div class="item"><div class="lbl">Business Address</div><div class="val"><?= nl2br(htmlspecialchars($user['business_address'] ?? '—')) ?></div></div>
<div class="item"><div class="lbl">Joined</div><div class="val"><?= date('M j, Y g:i A', strtotime($user['created_at'])) ?></div></div>
<div class="item"><div class="lbl">Last Active</div><div class="val"><?= $user['last_active'] ? date('M j, Y g:i A', strtotime($user['last_active'])) : '<span style="color:var(--text-muted)">Never</span>' ?></div></div>
<div class="item"><div class="lbl">Password Updated</div><div class="val"><?= $user['password_updated_at'] ? date('M j, Y g:i A', strtotime($user['password_updated_at'])) : '<span style="color:var(--text-muted)">Never</span>' ?></div></div>
<div class="item"><div class="lbl">Abbreviation</div><div class="val"><?= htmlspecialchars($user['business_abbr'] ?? '—') ?></div></div>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Actions</h3></div>
<div class="action-grid">
<div class="action-box">
<h4>Change Plan</h4>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="change_plan">
<label>Plan</label>
            <select name="plan_id">
<?php
$stmtPlans = $pdo->query("SELECT id, name, slug FROM plans ORDER BY id");
while ($pl = $stmtPlans->fetch()):
    $plLabel = ($pl['slug'] === 'pro' || stripos($pl['name'], 'pro') !== false) ? 'Pro' : htmlspecialchars($pl['name']);
?>
<option value="<?= (int)$pl['id'] ?>"<?= (int)($user['plan_id'] ?? 0) === (int)$pl['id'] ? ' selected' : '' ?>><?= $plLabel ?></option>
<?php endwhile; ?>
            </select>
<label>Expires At</label>
<input type="date" name="plan_expires_at" value="<?= $user['plan_expires_at'] ? date('Y-m-d', strtotime($user['plan_expires_at'])) : date('Y-m-d', strtotime('+30 days')) ?>">
<div class="checkbox-row"><input type="checkbox" name="no_expiry" value="1" id="noExpiry" onchange="document.querySelector('input[name=plan_expires_at]').disabled=this.checked"><label for="noExpiry">No expiry</label></div>
<button class="btn btn-primary" type="submit">Save Plan</button>
</form>
</div>
<div class="action-box">
<h4><?= $user['suspended'] ? 'Activate' : 'Suspend' ?> Account</h4>
<p style="font-size:11px;color:var(--text-light);margin-bottom:6px"><?= $user['suspended'] ? 'User is currently suspended. They cannot log in.' : 'User can currently log in normally.' ?></p>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="toggle_suspend">
<button class="btn <?= $user['suspended'] ? 'btn-success' : 'btn-danger' ?>" type="submit"><?= $user['suspended'] ? 'Activate User' : 'Suspend User' ?></button>
</form>
</div>
<div class="action-box">
<h4>Reset Password</h4>
<p style="font-size:11px;color:var(--text-light);margin-bottom:6px">Last changed: <?= $user['password_updated_at'] ? date('M j, Y g:i A', strtotime($user['password_updated_at'])) : '<span style="color:var(--text-muted)">Never</span>' ?></p>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="reset_password">
<label>New Password</label>
            <input type="password" name="new_password" id="newPassword" required minlength="8">
            <label>Confirm</label>
            <input type="password" name="confirm_password" id="confirmPassword" required minlength="8">
<div style="display:flex;gap:6px;margin-top:6px">
<button class="btn btn-primary" type="submit">Reset</button>
<button class="btn" type="button" onclick="generatePassword()">Generate Random</button>
</div>
</form>
<script>
function generatePassword(){
  var c='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()',p='';
  for(var i=0;i<12;i++)p+=c.charAt(Math.floor(Math.random()*c.length));
  document.getElementById('newPassword').value=p;
  document.getElementById('confirmPassword').value=p;
}
</script>
</div>
<div class="action-box">
<h4>Edit Profile</h4>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="edit_profile">
<div class="profile-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
<div><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>"></div>
<div><label>Business</label><input type="text" name="business_name" value="<?= htmlspecialchars($user['business_name']) ?>"></div>
<div><label>Email</label><input type="text" name="email" value="<?= htmlspecialchars($user['email']) ?>"></div>
<div><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($user['phone']) ?>"></div>
<div><label>Business Phone</label><input type="text" name="business_phone" value="<?= htmlspecialchars($user['business_phone'] ?? '') ?>"></div>
<div><label>Currency</label><input type="text" name="currency" value="<?= htmlspecialchars($user['currency'] ?? 'NGN') ?>"></div>
</div>
<label>Address</label><input type="text" name="business_address" value="<?= htmlspecialchars($user['business_address'] ?? '') ?>">
<div class="bank-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:6px">
<div><label>Bank</label><input type="text" name="bank_name" value="<?= htmlspecialchars($user['bank_name'] ?? '') ?>"></div>
<div><label>Acct Name</label><input type="text" name="account_name" value="<?= htmlspecialchars($user['account_name'] ?? '') ?>"></div>
<div><label>Acct No</label><input type="text" name="account_number" value="<?= htmlspecialchars($user['account_number'] ?? '') ?>"></div>
</div>
<button class="btn btn-primary" type="submit">Save Profile</button>
</form>
</div>
</div>
</div>

<div class="card">
<div class="tabs">
<button class="tab active" onclick="switchTab(this,'tabInvoices')">Invoices (<?= count($invoices) ?>)</button>
<button class="tab" onclick="switchTab(this,'tabReceipts')">Receipts (<?= count($receipts) ?>)</button>
<button class="tab" onclick="switchTab(this,'tabActivity')">Activity (<?= count($activities) ?>)</button>
</div>
<div id="tabInvoices" class="tab-panel active">
<?php if (count($invoices) > 0): ?>
<div class="table-responsive"><table><thead><tr><th>#</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
<tbody><?php foreach ($invoices as $inv): ?><tr><td><?= htmlspecialchars($inv['invoice_number']) ?></td><td><?= htmlspecialchars($inv['customer_name']) ?></td><td>₦<?= number_format($inv['amount'], 2) ?></td><td><span class="badge <?= $inv['status'] == 'paid' ? 'b-pro' : 'b-free' ?>"><?= ucfirst($inv['status']) ?></span></td><td><?= date('M j, Y', strtotime($inv['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--text-muted);text-align:center;padding:16px">No invoices</p><?php endif; ?>
</div>
<div id="tabReceipts" class="tab-panel">
<?php if (count($receipts) > 0): ?>
<div class="table-responsive"><table><thead><tr><th>#</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
<tbody><?php foreach ($receipts as $r): ?><tr><td><?= htmlspecialchars($r['receipt_number']) ?></td><td><?= htmlspecialchars($r['customer_name']) ?></td><td>₦<?= number_format($r['amount'], 2) ?></td><td><span class="badge <?= $r['status'] == 'paid' ? 'b-pro' : ($r['status'] == 'failed' ? 'b-suspended' : 'b-free') ?>"><?= ucfirst($r['status']) ?></span></td><td><?= date('M j, Y', strtotime($r['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--text-muted);text-align:center;padding:16px">No receipts</p><?php endif; ?>
</div>
<div id="tabActivity" class="tab-panel">
<?php if (count($activities) > 0): ?>
<div class="table-responsive"><table><thead><tr><th>Action</th><th>Description</th><th>Date</th></tr></thead>
<tbody><?php foreach ($activities as $a): ?><tr><td><?= htmlspecialchars($a['action_type']) ?></td><td><?= htmlspecialchars($a['description']) ?></td><td><?= date('M j, Y g:i A', strtotime($a['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--text-muted);text-align:center;padding:16px">No activity</p><?php endif; ?>
</div>
</div>

</div>
<script>
function switchTab(el, id) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(id).classList.add('active');
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>