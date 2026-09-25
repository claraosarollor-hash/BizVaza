<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('BizVaza DB connection failed: ' . $e->getMessage());
    die('A database error occurred. Please try again later.');
}

// Schema migrations live in sql/migrate.php (CLI-only). Run: php sql/migrate.php
// after deploy; web requests only need a connection.

function syncDocCounter(PDO $pdo, int $user_id, string $type): void {
    $stmt = $pdo->prepare("INSERT IGNORE INTO number_sequences (user_id, doc_type, last_value) VALUES (?, ?, 0)");
    $stmt->execute([$user_id, $type]);
    $numCol = $type === 'invoice' ? 'invoice_number' : 'receipt_number';
    $table = $type === 'invoice' ? 'invoices' : 'payments';
    $stmt = $pdo->prepare("UPDATE number_sequences SET last_value = GREATEST(last_value, COALESCE((SELECT MAX(CAST(SUBSTRING_INDEX($numCol,'-',-1) AS UNSIGNED)) FROM $table WHERE user_id = ?), 0)) WHERE user_id = ? AND doc_type = ?");
    $stmt->execute([$user_id, $user_id, $type]);
}

function formatDocNumber(string $type, string $abbr, int $seq): string {
    $prefix = $type === 'invoice' ? 'INV' : 'RCP';
    return $prefix . '-' . $abbr . '-' . date('Y') . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

// Suggested next number (peek only, does not consume).
function nextDocNumber(PDO $pdo, int $user_id, string $type, string $abbr): string {
    syncDocCounter($pdo, $user_id, $type);
    $stmt = $pdo->prepare("SELECT last_value FROM number_sequences WHERE user_id = ? AND doc_type = ?");
    $stmt->execute([$user_id, $type]);
    return formatDocNumber($type, $abbr, intval($stmt->fetchColumn()) + 1);
}

// Reserve and return the next number atomically (never reused after deletion).
function consumeDocNumber(PDO $pdo, int $user_id, string $type, string $abbr): string {
    syncDocCounter($pdo, $user_id, $type);
    $stmt = $pdo->prepare("UPDATE number_sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE user_id = ? AND doc_type = ?");
    $stmt->execute([$user_id, $type]);
    return formatDocNumber($type, $abbr, intval($pdo->lastInsertId()));
}

// Invoice amount and cumulative paid total (status = 'paid' only) for the current user.
function invoicePaymentTotals(PDO $pdo, int $user_id, string $invoice_number): array {
    $stmt = $pdo->prepare("SELECT amount FROM invoices WHERE invoice_number = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$invoice_number, $user_id]);
    $amount = floatval($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.invoice_number = ? AND p.user_id = ? AND p.status = 'paid'");
    $stmt->execute([$invoice_number, $user_id]);
    $paid = floatval($stmt->fetchColumn());
    return ['amount' => $amount, 'paid_sum' => $paid, 'remaining' => max(0, $amount - $paid)];
}

// Standalone receipt chain: items total vs cumulative paid (original + settlements).
// Settlements are receipts whose linked_receipt points at the original receipt number.
function receiptChainOutstanding(PDO $pdo, int $user_id, string $receipt_number): array {
    $stmt = $pdo->prepare("SELECT id, amount, invoice_number FROM payments WHERE receipt_number = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$receipt_number, $user_id]);
    $orig = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$orig || $orig['invoice_number'] !== '') {
        return ['found' => false, 'total' => 0.0, 'paid_so_far' => 0.0, 'outstanding' => 0.0, 'settled' => true];
    }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM receipt_items WHERE receipt_id = ?");
    $stmt->execute([$orig['id']]);
    $total = round(floatval($stmt->fetchColumn()), 2);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = ? AND status = 'paid' AND (receipt_number = ? OR linked_receipt = ?)");
    $stmt->execute([$user_id, $receipt_number, $receipt_number]);
    $paid = round(floatval($stmt->fetchColumn()), 2);
    $outstanding = round(max(0, $total - $paid), 2);
    return ['found' => true, 'total' => $total, 'paid_so_far' => $paid, 'outstanding' => $outstanding, 'settled' => $outstanding <= 0];
}

function getProPlan(PDO $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE slug = 'pro' LIMIT 1");
    $stmt->execute();
    $pro = $stmt->fetch();
    return $pro ?: null;
}

// Platform bank-transfer payments (settings toggle, default ON while absent).
function bank_transfer_enabled(PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'bank_transfer_enabled' LIMIT 1");
    $stmt->execute();
    $v = $stmt->fetchColumn();
    if ($v === false) return true;
    return $v === '1';
}
