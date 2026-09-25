<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }

// Fixed application URL — override with APP_URL env at deploy. Never trust HTTP_HOST.
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost/WebDev', '/'));

$sessionLifetime = 7 * 86400;
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => str_starts_with(APP_URL, 'https://'),
]);
session_start();
date_default_timezone_set('Africa/Lagos');
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (str_starts_with(APP_URL, 'https://')) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
}

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'bizflow');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('SITE_URL', APP_URL);
// Empty means "resolve from settings table at runtime" (see cron.php). Default is intentionally empty.
define('RECURRING_CRON_KEY', getenv('RECURRING_CRON_KEY') ?: '');
define('FREE_MONTHLY_INVOICE_LIMIT', 20);
define('TRIAL_MONTHS', 1);
define('PRO_PLAN_DAYS', 30);

require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/notifications.php';

function timeAgo($ts) {
    $diff = time() - strtotime($ts);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('M d', strtotime($ts));
}

function generateAbbr($business_name) {
    $name = trim($business_name);
    if ($name === '') return 'BIZ';
    $first = explode(' ', $name)[0];
    $abbr = strtoupper(substr($first, 0, 3));
    return $abbr ?: 'BIZ';
}

function getAvatarInitials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') $initials .= strtoupper($p[0]);
    }
    return substr($initials, 0, 2) ?: 'U';
}

function isPro() {
    return isset($_SESSION['is_pro']) && $_SESSION['is_pro'] === true;
}

function planIdBySlug(PDO $pdo, string $slug): ?int {
    $stmt = $pdo->prepare("SELECT id FROM plans WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();
    return ($id !== false && $id !== null && $id !== '') ? (int)$id : null;
}

function proPlanId(PDO $pdo): int {
    return planIdBySlug($pdo, 'pro') ?? 0;
}

function freePlanId(PDO $pdo): int {
    return planIdBySlug($pdo, 'free') ?? 1;
}

function userIsProById(PDO $pdo, int $userId): bool {
    $proId = proPlanId($pdo);
    if ($proId <= 0) return false;
    $stmt = $pdo->prepare("SELECT plan_id, plan_expires_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) return false;
    if ((int)$u['plan_id'] !== $proId) return false;
    if (!empty($u['plan_expires_at']) && strtotime($u['plan_expires_at']) < time()) return false;
    return true;
}

function canAccessTemplates() {
    return isPro();
}

function getTemplates($pdo, $type = null) {
    if ($type) {
        $stmt = $pdo->prepare("SELECT * FROM templates WHERE is_active = 1 AND type = ? ORDER BY sort_order");
        $stmt->execute([$type]);
    } else {
        $stmt = $pdo->query("SELECT * FROM templates WHERE is_active = 1 ORDER BY sort_order");
    }
    return $stmt->fetchAll();
}

function getUserTemplate($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT t.* FROM templates t JOIN users u ON u.selected_template_id = t.id WHERE u.id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function invoiceCountThisMonth($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND created_at >= ?");
    $stmt->execute([$userId, date('Y-m-01 00:00:00')]);
    return (int)$stmt->fetchColumn();
}

function invoiceLimitReached($pdo, $userId) {
    if (userIsProById($pdo, $userId)) return false;
    return invoiceCountThisMonth($pdo, $userId) >= FREE_MONTHLY_INVOICE_LIMIT;
}

function getTemplatesForUser($pdo, $userId, $type = null) {
    if (!canAccessTemplates()) return [];
    return getTemplates($pdo, $type);
}

function clearPremiumSelections($pdo, $userId) {
    $stmt = $pdo->prepare("UPDATE users SET selected_template_id = NULL, selected_receipt_template_id = NULL WHERE id = ?");
    $stmt->execute([$userId]);
}

function minify_html($buffer) {
    if ($buffer === '') return '';
    $buffer = preg_replace('/<!--(.|\s)*?-->/', '', $buffer);
    $themeInit = '<script>try{if(localStorage.getItem("bizvaza_theme")==="dark"){document.documentElement.setAttribute("data-theme","dark");}}catch(_e){}</script>';
    $buffer = preg_replace('/<head(\s[^>]*)?>/i', '<head$1>' . $themeInit, $buffer, 1);
    $buffer = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $buffer);
    $buffer = preg_replace('/\s+/', ' ', $buffer);
    $buffer = preg_replace('/> </', '><', $buffer);
    return trim($buffer);
}
ob_start('minify_html');
register_shutdown_function(function() {
    while (ob_get_level()) ob_end_flush();
});

function site_url() {
    return SITE_URL;
}

function csrf_token() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals(csrf_token(), $token)) {
            http_response_code(403);
            die('Invalid CSRF token.');
        }
    }
}
