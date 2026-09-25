<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/paystack.php';

$reference = trim((string)($_GET['reference'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));

if ($reference === '') {
    header('Location: pricing.php');
    exit;
}

$conf = paystack_settings($pdo);
if (!paystack_configured($pdo)) {
    header('Location: pricing.php?error=gateway_not_configured');
    exit;
}

// Explicit failure signals from the popup/callback.
if ($status !== '' && $status !== 'success') {
    header('Location: pricing.php?error=payment_cancelled');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

// Must have a matching local pending/approved record for this user + reference.
$stmt = $pdo->prepare("SELECT * FROM subscription_payments WHERE reference = ? AND method = 'paystack' AND user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$reference, $user_id]);
$row = $stmt->fetch();

if (!$row) {
    header('Location: pricing.php?error=no_payment_found');
    exit;
}

$proPlan = getProPlan($pdo);
if (!$proPlan) {
    header('Location: pricing.php?error=verification_failed');
    exit;
}

// Idempotency: already confirmed -> refresh session, do NOT re-extend.
if ($row['status'] === 'approved') {
    $_SESSION['plan_id'] = (int)$proPlan['id'];
    $_SESSION['plan_name'] = $proPlan['name'];
    $_SESSION['is_pro'] = true;
    header('Location: dashboard.php?upgraded=1');
    exit;
}

// Server-side verification.
$result = paystack_api('GET', 'transaction/verify/' . rawurlencode($reference), $conf['secret']);
if ($result['errno'] !== 0 || $result['code'] !== 200 || !is_array($result['data'])) {
    header('Location: pricing.php?error=verification_failed');
    exit;
}
$data = $result['data'];
if (($data['status'] ?? false) !== true || ($data['data']['status'] ?? '') !== 'success') {
    header('Location: pricing.php?error=verification_failed');
    exit;
}
$trx = $data['data'];

// Reconcile: currency, amount, ownership, and declared plan must all match.
$paidNaira = round((float)($trx['amount'] ?? 0) / 100, 2);
$expected = round((float)$row['amount'], 2);
$metaUserId = isset($trx['metadata']['user_id']) ? (int)$trx['metadata']['user_id'] : 0;
$metaPlanId = isset($trx['metadata']['plan_id']) ? (int)$trx['metadata']['plan_id'] : 0;

$ok = strtoupper((string)($trx['currency'] ?? '')) === 'NGN'
    && abs($paidNaira - $expected) <= 0.01
    && $metaUserId === (int)$row['user_id'] && $metaUserId === $user_id
    && ($metaPlanId === 0 || $metaPlanId === (int)$proPlan['id']);

if (!$ok) {
    $pdo->prepare("UPDATE subscription_payments SET status = 'rejected', admin_note = 'Amount/currency mismatch on verification', verified_at = NOW() WHERE id = ?")
        ->execute([$row['id']]);
    header('Location: pricing.php?error=verification_failed');
    exit;
}

// Approve + activate (30-day Pro period renewed from now).
$expiry = date('Y-m-d H:i:s', strtotime('+' . PRO_PLAN_DAYS . ' days'));
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE subscription_payments SET status = 'approved', verified_at = NOW() WHERE id = ?")->execute([$row['id']]);
    $pdo->prepare("UPDATE users SET plan_id = ?, plan_updated_at = NOW(), plan_expires_at = ? WHERE id = ?")
        ->execute([(int)$proPlan['id'], $expiry, $user_id]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: pricing.php?error=verification_failed');
    exit;
}

$_SESSION['plan_id'] = (int)$proPlan['id'];
$_SESSION['plan_name'] = $proPlan['name'];
$_SESSION['is_pro'] = true;

logActivity($pdo, $user_id, 'subscription_upgraded', "Upgraded to Pro plan via Paystack (₦" . number_format($paidNaira) . ") — expires " . date('M j, Y', strtotime($expiry)), 'subscription', null);

header('Location: dashboard.php?upgraded=1');
exit;