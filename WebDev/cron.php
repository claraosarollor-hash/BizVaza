<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/recurring-runner.php';

header('Content-Type: application/json');

// Resolve cron key: env first, then settings table. The shipped default is NEVER valid.
$cronKey = RECURRING_CRON_KEY;
if ($cronKey === '') {
    try {
        $stmt = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'recurring_cron_key' LIMIT 1");
        $cronKey = (string)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cronKey = '';
    }
}
$provided = '';
if (isset($_SERVER['HTTP_X_CRON_KEY']) && is_string($_SERVER['HTTP_X_CRON_KEY'])) {
    $provided = $_SERVER['HTTP_X_CRON_KEY'];
} elseif (isset($_GET['key']) && is_string($_GET['key'])) {
    $provided = $_GET['key'];
}
$isKey = $cronKey !== ''
    && $cronKey !== 'bizflow-cron-key-change-me'
    && $cronKey !== 'bizvaza-cron-key-change-me'
    && $provided !== ''
    && hash_equals($cronKey, $provided);
$isUser = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;

$limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 20;

if ($isKey) {
    $lock = $pdo->query("SELECT GET_LOCK('bizvaza_cron', 0)")->fetchColumn();
    if ((int)$lock !== 1) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Cron already running']);
        exit;
    }
    try {
        $result = generateRecurringInvoices($pdo, null, $limit);
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('bizvaza_cron')"); } catch (Throwable $e) {}
    }
} elseif ($isUser) {
    $result = generateRecurringInvoices($pdo, (int)$_SESSION['user_id'], $limit);
} else {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Pass ?key= or log in.']);
    exit;
}

echo json_encode([
    'ok' => (count($result['errors']) === 0),
    'generated' => count($result['generated']),
    'invoices' => $result['generated'],
    'errors' => $result['errors']
]);