<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user_id = $_SESSION['user_id'];
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid customer ID']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, phone, company, billing_address, last_active, created_at FROM customers WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Customer not found']);
    exit;
}

$name = $customer['name'];
$stmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0, i.amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0))), 0) FROM invoices i WHERE i.user_id = ? AND (i.customer_name = ? OR i.customer_name LIKE CONCAT(?, '%') OR ? LIKE CONCAT(i.customer_name, '%'))");
$stmt->execute([$user_id, $name, $name, $name]);
$customer['calculated_balance'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM payments WHERE customer_name = ? AND user_id = ? ORDER BY created_at DESC");
$stmt->execute([$name, $user_id]);
$customer['receipts'] = $stmt->fetchAll();

header('Content-Type: application/json');
echo json_encode($customer);
