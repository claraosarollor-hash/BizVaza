<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/paystack.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$conf = paystack_settings($pdo);
if ($conf['secret'] === '') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Gateway not configured']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Empty body']);
    exit;
}

// Signature verification: HMAC-SHA512 of the raw body with the secret key.
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$computed = hash_hmac('sha512', $raw, $conf['secret']);
if (!hash_equals($computed, $signature)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

header('Content-Type: application/json');

$event = $payload['event'] ?? '';
if ($event !== 'charge.success') {
    echo json_encode(['received' => true, 'ignored' => true]);
    exit;
}

$trx = $payload['data'] ?? [];
$reference = trim((string)($trx['reference'] ?? ''));
if ($reference === '') {
    echo json_encode(['received' => true, 'ignored' => true]);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM subscription_payments WHERE reference = ? AND method = 'paystack' ORDER BY id DESC LIMIT 1");
$stmt->execute([$reference]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['received' => true, 'unknown' => true]);
    exit;
}

// Idempotent: already approved -> nothing to do (check BEFORE any mutation).
if ($row['status'] === 'approved') {
    echo json_encode(['received' => true, 'ok' => true, 'duplicate' => true]);
    exit;
}

$proPlan = getProPlan($pdo);
if (!$proPlan) {
    http_response_code(500);
    echo json_encode(['error' => 'Pro plan missing']);
    exit;
}

// Reconcile the webhook payload exactly like the redirect verify path.
$paidNaira = round((float)($trx['amount'] ?? 0) / 100, 2);
$expected = round((float)$row['amount'], 2);
$metaUserId = (int)($trx['metadata']['user_id'] ?? 0);
$metaPlanId = (int)($trx['metadata']['plan_id'] ?? 0);

$ok = strtoupper((string)($trx['currency'] ?? '')) === 'NGN'
    && abs($paidNaira - $expected) <= 0.01
    && ($metaUserId === 0 || $metaUserId === (int)$row['user_id'])
    && ($metaPlanId === 0 || $metaPlanId === (int)$proPlan['id']);

if (!$ok) {
    $pdo->prepare("UPDATE subscription_payments SET status = 'rejected', admin_note = 'Webhook amount/currency mismatch', verified_at = NOW() WHERE id = ? AND status = 'pending'")
        ->execute([$row['id']]);
    echo json_encode(['received' => true, 'rejected' => 'mismatch']);
    exit;
}

$expiry = date('Y-m-d H:i:s', strtotime('+' . PRO_PLAN_DAYS . ' days'));
$pdo->beginTransaction();
try {
    $st = $pdo->prepare("UPDATE subscription_payments SET status = 'approved', verified_at = NOW(), admin_note = 'Auto-approved via webhook' WHERE id = ? AND status <> 'approved'");
    $st->execute([$row['id']]);
    if ($st->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['received' => true, 'ok' => true, 'duplicate' => true]);
        exit;
    }
    $pdo->prepare("UPDATE users SET plan_id = ?, plan_updated_at = NOW(), plan_expires_at = ? WHERE id = ?")
        ->execute([(int)$proPlan['id'], $expiry, (int)$row['user_id']]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Activation failed']);
    exit;
}

logActivity($pdo, (int)$row['user_id'], 'subscription_upgraded', "Upgraded to Pro plan via Paystack webhook (₦" . number_format($paidNaira) . ") — expires " . date('M j, Y', strtotime($expiry)), 'subscription', null);

echo json_encode(['received' => true, 'ok' => true]);