<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user_id = intval($_SESSION['user_id'] ?? 0);
$number = trim($_GET['number'] ?? '');

if ($user_id <= 0 || $number === '') {
    echo json_encode(['found' => false]);
    exit;
}

$stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE invoice_number = ? AND user_id = ? LIMIT 1");
$stmt->execute([$number, $user_id]);
$inv = $stmt->fetch();

if (!$inv) {
    echo json_encode(['found' => false]);
    exit;
}

$totals = invoicePaymentTotals($pdo, $user_id, $inv['invoice_number']);

echo json_encode([
    'found' => true,
    'invoice_number' => $inv['invoice_number'],
    'invoice_total' => $totals['amount'],
    'paid' => $totals['paid_sum'],
    'outstanding' => $totals['remaining'],
]);
