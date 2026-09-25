<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/activity.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Refresh session data from DB so role/suspension changes take effect immediately
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$u = $stmt->fetch();

if (!$u || $u['role'] !== 'admin') {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if (!empty($u['suspended'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['full_name'] = $u['full_name'];
$_SESSION['business_name'] = $u['business_name'];
$_SESSION['email'] = $u['email'];
$_SESSION['avatar_initials'] = getAvatarInitials($u['full_name']);
$_SESSION['role'] = 'admin';

// Page-level permissions: owner (admin_pages NULL) has full access
$GLOBALS['admin_pages'] = !empty($u['admin_pages']) ? json_decode($u['admin_pages'], true) : null;

function adminCan($key) {
    $pages = $GLOBALS['admin_pages'] ?? null;
    return $pages === null || in_array($key, $pages, true);
}

// Invalidate sessions after password change (pw_epoch must match password_updated_at)
$currentEpoch = (string)($u['password_updated_at'] ?? '');
if (!isset($_SESSION['pw_epoch'])) {
    $_SESSION['pw_epoch'] = $currentEpoch;
} elseif ((string)$_SESSION['pw_epoch'] !== $currentEpoch) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

// Track admin last active (throttled to one write per 5 minutes)
if (empty($u['last_active']) || strtotime($u['last_active']) < time() - 300) {
    $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
}
