<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    header('Location: ' . $base . '/login.php');
    exit;
}

// Always refresh session data from DB so admin edits take effect immediately
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$u = $stmt->fetch();

if (!$u) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Kick suspended users out
if (!empty($u['suspended'])) {
    session_destroy();
    header('Location: login.php?suspended=1');
    exit;
}

$pid = (int)$u['plan_id'];
$proId = proPlanId($pdo);
$freeId = freePlanId($pdo);
$plan_expires = $u['plan_expires_at'] ?? null;
if ($plan_expires && strtotime($plan_expires) < time() && $proId > 0 && $pid === $proId) {
    $pid = $freeId;
    $pdo->prepare("UPDATE users SET plan_id = ?, plan_updated_at = NOW() WHERE id = ?")->execute([$freeId, $_SESSION['user_id']]);
    clearPremiumSelections($pdo, $_SESSION['user_id']);
}

// Account lock: paid Pro (unexpired) or active trial keeps access open
$trial_ends_at = $u['trial_ends_at'] ?? null;
$_SESSION['trial_ends_at'] = $trial_ends_at;
$isProNow = ($proId > 0 && $pid === $proId);
$account_locked = ($u['role'] !== 'admin')
    && !$isProNow
    && !($trial_ends_at && strtotime($trial_ends_at) > time());
$_SESSION['account_locked'] = $account_locked;

if ($account_locked) {
    $exempt = ['paywall.php', 'pricing.php', 'upgrade-receipt.php', 'upgrade-success.php', 'needhelp.php', 'logout.php', 'paystack-initialize.php'];
    if (!in_array(basename($_SERVER['SCRIPT_NAME']), $exempt, true)) {
        header('Location: paywall.php');
        exit;
    }
}

$_SESSION['full_name'] = $u['full_name'];
$_SESSION['business_name'] = $u['business_name'];
$_SESSION['business_abbr'] = $u['business_abbr'] ?? generateAbbr($u['business_name']);
$_SESSION['email'] = $u['email'];
$_SESSION['avatar_initials'] = getAvatarInitials($u['full_name']);
$_SESSION['business_phone'] = $u['business_phone'] ?? '';
$_SESSION['business_address'] = $u['business_address'] ?? '';
$_SESSION['bank_name'] = $u['bank_name'] ?? '';
$_SESSION['account_name'] = $u['account_name'] ?? '';
$_SESSION['account_number'] = $u['account_number'] ?? '';
$_SESSION['invoice_default_notes'] = $u['invoice_default_notes'] ?? '';
$_SESSION['receipt_default_notes'] = $u['receipt_default_notes'] ?? '';
$_SESSION['phone'] = $u['phone'] ?? '';
$_SESSION['currency'] = $u['currency'] ?? 'NGN';
$_SESSION['logo_path'] = $u['logo_path'] ?? '';
$_SESSION['role'] = $u['role'] ?? 'user';
$_SESSION['plan_id'] = $pid;
$_SESSION['plan_name'] = $isProNow ? 'Pro' : 'Free';
$_SESSION['is_pro'] = $isProNow;
$_SESSION['selected_template_id'] = $u['selected_template_id'] ?? null;
$_SESSION['selected_receipt_template_id'] = $u['selected_receipt_template_id'] ?? null;

// Invalidate sessions after password change (pw_epoch must match password_updated_at)
$currentEpoch = (string)($u['password_updated_at'] ?? '');
if (!isset($_SESSION['pw_epoch'])) {
    $_SESSION['pw_epoch'] = $currentEpoch;
} elseif ((string)$_SESSION['pw_epoch'] !== $currentEpoch) {
    $_SESSION = [];
    session_destroy();
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    header('Location: ' . $base . '/login.php');
    exit;
}

// Track last active time (throttled to one write per 5 minutes)
if (empty($u['last_active']) || strtotime($u['last_active']) < time() - 300) {
    $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
}
