<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$business_abbr = $_SESSION['business_abbr'] ?? 'BIZ';
$avatar_initials = $_SESSION['avatar_initials'];
$business_name = $_SESSION['business_name'];

// Auto-generate next receipt number (monotonic, never reused)
$next_receipt = nextDocNumber($pdo, $user_id, 'receipt', $business_abbr);

// Prefill from invoice
$prefill_customer = '';
$prefill_invoice = '';
$prefill_amount = '';
$prefill_total = '';
$prefill_items = [];
if (isset($_GET['invoice_id'])) {
    $inv_id = intval($_GET['invoice_id']);
    $stmt = $pdo->prepare("SELECT invoice_number, customer_name, amount FROM invoices WHERE id = ? AND user_id = ?");
    $stmt->execute([$inv_id, $user_id]);
    $inv = $stmt->fetch();
    if ($inv) {
        $totals = invoicePaymentTotals($pdo, $user_id, $inv['invoice_number']);
        if ($totals['amount'] > 0 && $totals['paid_sum'] >= $totals['amount']) {
            $stmt = $pdo->prepare("SELECT id FROM payments WHERE invoice_number = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$inv['invoice_number'], $user_id]);
            $latest = $stmt->fetch();
            header('Location: view-receipt.php?id=' . ($latest ? $latest['id'] : 0));
            exit;
        }
        $prefill_customer = htmlspecialchars($inv['customer_name'], ENT_QUOTES);
        $prefill_invoice = htmlspecialchars($inv['invoice_number'], ENT_QUOTES);
        $prefill_amount = htmlspecialchars(number_format($totals['remaining'], 2, '.', ''), ENT_QUOTES);
        $prefill_total = htmlspecialchars(number_format($inv['amount'], 2, '.', ''), ENT_QUOTES);
        $stmt = $pdo->prepare("SELECT name, description, quantity, unit_price, amount FROM invoice_items WHERE invoice_id = ?");
        $stmt->execute([$inv_id]);
        $prefill_items = $stmt->fetchAll();
    }
}

// Prefill for balance settlement (standalone receipts only)
$prefill_linked_receipt = '';
$prefill_hint = '';
$settle_src_id = 0;
if (isset($_GET['from_receipt'])) {
    $src_id = intval($_GET['from_receipt']);
    $stmt = $pdo->prepare("SELECT id, receipt_number, customer_name, invoice_number FROM payments WHERE id = ? AND user_id = ?");
    $stmt->execute([$src_id, $user_id]);
    $src = $stmt->fetch();
    if ($src && $src['invoice_number'] === '') {
        $chain = receiptChainOutstanding($pdo, $user_id, $src['receipt_number']);
        if ($chain['found']) {
            $settle_src_id = intval($src['id']);
            $prefill_customer = htmlspecialchars($src['customer_name'], ENT_QUOTES);
            $prefill_linked_receipt = htmlspecialchars($src['receipt_number'], ENT_QUOTES);
            $prefill_total = number_format($chain['total'], 2, '.', '');
            $prefill_amount = number_format($chain['outstanding'], 2, '.', '');
            $prefill_hint = 'Settles ' . htmlspecialchars($src['receipt_number'], ENT_QUOTES)
                . ' · Paid so far ₦' . number_format($chain['paid_so_far'], 2)
                . ' · Outstanding ₦' . number_format($chain['outstanding'], 2);
        }
    }
}

// Edit mode removed: receipts are immutable once issued

$catalog_items = [];
$stmt = $pdo->prepare("SELECT name FROM items WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$catalog_items = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle Record Receipt form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validate_csrf();

if ($_POST['action'] === 'record_receipt') {
    $receipt = trim($_POST['receipt_number'] ?? '');
    $customer = trim($_POST['customer_name'] ?? '');
    $invoice = trim($_POST['invoice_number'] ?? '');
    $linked_receipt = trim($_POST['linked_receipt'] ?? '');

    // Parse line items (standalone receipts only)
    $item_names = $_POST['item_name'] ?? [];
    $item_descs = $_POST['item_description'] ?? [];
    $item_types = $_POST['item_type'] ?? [];
    $item_qtys = $_POST['item_qty'] ?? [];
    $item_prices = $_POST['item_price'] ?? [];
    $parsed_items = [];
    $items_total = 0;
    foreach ($item_names as $i => $name) {
        $name = trim($name);
        if ($name === '') continue;
        $type = (trim($item_types[$i] ?? 'product') === 'service') ? 'service' : 'product';
        $qty = max(0, intval($item_qtys[$i] ?? 1));
        if ($type === 'service') { $qty = 1; }
        $price = max(0, floatval(str_replace(',', '', $item_prices[$i] ?? '0')));
        $line_amt = round($qty * $price * 100) / 100;
        $items_total += $line_amt;
        $parsed_items[] = ['name' => $name, 'desc' => trim($item_descs[$i] ?? ''), 'type' => $type, 'qty' => $qty, 'price' => $price, 'amount' => $line_amt];
    }
    $items_total = round($items_total * 100) / 100;

    $amount = round(max(0, floatval(str_replace(',', '', $_POST['amount'] ?? '0'))), 2);
    $balance = round(max(0, floatval(str_replace(',', '', $_POST['balance'] ?? '0'))), 2);
    $pdate = $_POST['payment_date'] ?? date('Y-m-d');
    $status = $_POST['status'] ?? 'paid';
    $notes = trim($_POST['notes'] ?? '');

    $rcpt_template_id = null;
    if (isPro()) {
        $rcpt_template_id = !empty($_SESSION['selected_receipt_template_id']) ? intval($_SESSION['selected_receipt_template_id']) : null;
        if (!$rcpt_template_id && $invoice !== '') {
            $stmt = $pdo->prepare("SELECT template_id FROM invoices WHERE invoice_number = ? AND user_id = ?");
            $stmt->execute([$invoice, $user_id]);
            $inv_tid = $stmt->fetchColumn();
            if ($inv_tid) $rcpt_template_id = (int)$inv_tid;
        }
    }

    // Receipts are immutable once issued — reject any edit attempt
    if (intval($_POST['edit_id'] ?? 0) > 0) {
        $_SESSION['flash_error'] = 'Receipts cannot be modified after creation.';
        header('Location: receipts.php');
        exit;
    }
    if ($receipt !== '' && $customer !== '') {
        $block_msg = '';
        if ($invoice !== '') {
            if ($amount <= 0) {
                $block_msg = 'Amount received must be greater than zero.';
            }
            if ($block_msg === '') {
                $stmt = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ? AND user_id = ?");
                $stmt->execute([$invoice, $user_id]);
                if (!$stmt->fetch()) {
                    $block_msg = "Invoice number {$invoice} was not found. Leave the invoice field empty to record a standalone receipt.";
                } else {
                    $totals = invoicePaymentTotals($pdo, $user_id, $invoice);
                    if ($totals['amount'] > 0 && $totals['paid_sum'] >= $totals['amount']) {
                        $block_msg = "Invoice {$invoice} has been fully paid; no more receipts can be generated for it.";
                    } elseif ($totals['amount'] > 0 && $totals['paid_sum'] + $amount > $totals['amount']) {
                        $block_msg = "This receipt would exceed the outstanding balance of ₦" . number_format($totals['remaining'], 2) . " on invoice {$invoice}.";
                    } else {
                        // Snapshot of what remains on the invoice right after this receipt is issued.
                        $balance = round(max(0, $totals['remaining'] - $amount), 2);
                    }
                }
            }
        } elseif ($linked_receipt !== '') {
            // Settlement receipt against a standalone original (balance collection).
            if ($amount <= 0) {
                $block_msg = 'Amount received must be greater than zero.';
            }
            if ($block_msg === '') {
                $stmt = $pdo->prepare("SELECT id FROM payments WHERE receipt_number = ? AND user_id = ? AND invoice_number = '' LIMIT 1");
                $stmt->execute([$linked_receipt, $user_id]);
                $src = $stmt->fetch();
                if (!$src) {
                    $block_msg = "Original receipt {$linked_receipt} was not found or cannot be settled.";
                } else {
                    $chain = receiptChainOutstanding($pdo, $user_id, $linked_receipt);
                    if ($chain['settled']) {
                        $block_msg = "Receipt {$linked_receipt} has already been fully settled.";
                    } elseif ($amount > $chain['outstanding']) {
                        $block_msg = "Amount received cannot exceed the outstanding ₦" . number_format($chain['outstanding'], 2) . " on receipt {$linked_receipt}.";
                    } else {
                        // Balance due after this settlement is recorded.
                        $balance = round(max(0, $chain['outstanding'] - $amount), 2);
                        $settle_src_id = intval($src['id']);
                    }
                }
            }
        } elseif (count($parsed_items) > 0) {
            // Standalone receipt with line items: total comes from the items.
            if ($items_total <= 0) {
                $block_msg = 'Add at least one item with a valid price before generating the receipt.';
            } elseif ($amount <= 0) {
                $block_msg = 'Amount received must be greater than zero.';
            } elseif ($amount > $items_total) {
                $block_msg = "Amount received cannot exceed the items/services total of ₦" . number_format($items_total, 2) . ".";
            } else {
                // Balance due is derived: total − received.
                $balance = round(max(0, $items_total - $amount), 2);
            }
        } else {
            // Standalone without items (deposit-style): amount and balance entered manually.
            if ($amount <= 0) {
                $block_msg = 'Amount received must be greater than zero.';
            }
        }
        if ($block_msg === '') {
            $stmt = $pdo->prepare("SELECT id FROM payments WHERE receipt_number = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$receipt, $user_id]);
            if ($stmt->fetch()) {
                $block_msg = "Receipt number {$receipt} is already in use. Use the suggested receipt number.";
            }
        }
        if ($block_msg === '') {
            $pdo->beginTransaction();
            try {
                consumeDocNumber($pdo, $user_id, 'receipt', $business_abbr);
                $stmt = $pdo->prepare("INSERT INTO payments (user_id, receipt_number, customer_name, invoice_number, linked_receipt, amount, balance, template_id, payment_date, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $receipt, $customer, $invoice, $linked_receipt, $amount, $balance, $rcpt_template_id, $pdate, $status, $notes]);
                $receipt_id = $pdo->lastInsertId();
                if ($settle_src_id > 0) {
                    // Settlement receipts carry copies of the original line items.
                    $citems = $pdo->prepare("SELECT name, type, description, quantity, unit_price, amount FROM receipt_items WHERE receipt_id = ? ORDER BY id");
                    $citems->execute([$settle_src_id]);
                    $ritem = $pdo->prepare("INSERT INTO receipt_items (receipt_id, name, type, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($citems->fetchAll(PDO::FETCH_ASSOC) as $ci) {
                        $ritem->execute([$receipt_id, $ci['name'], $ci['type'], $ci['description'], $ci['quantity'], $ci['unit_price'], $ci['amount']]);
                    }
                } elseif ($invoice === '' && count($parsed_items) > 0) {
                    $ritem = $pdo->prepare("INSERT INTO receipt_items (receipt_id, name, type, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($parsed_items as $it) {
                        $ritem->execute([$receipt_id, $it['name'], $it['type'], $it['desc'], $it['qty'], $it['price'], $it['amount']]);
                    }
                }
                logActivity($pdo, $user_id, 'payment_recorded', "Receipt {$receipt} — ₦" . number_format($amount, 2) . " from {$customer}", 'payment', $receipt_id);
                $pdo->commit();
                header('Location: view-receipt.php?id=' . $receipt_id);
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('BizVaza record_receipt failed: ' . $e->getMessage());
                $_SESSION['flash_error'] = 'That receipt number or invoice reference is already in use. Please use a different one.';
            }
        } else {
            $_SESSION['flash_error'] = $block_msg;
        }
    } else {
        $_SESSION['flash_error'] = 'Receipt number and customer name are required.';
    }
    header('Location: receipts.php');
    exit;
}
}

// Fetch receipts
$stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$receipts = $stmt->fetchAll();

// Resolve invoice-linked receipts (with a balance snapshot) to live invoice data so
// they can offer "Receive Balance" links; one batched query, falls back to plain
// text when the referenced invoice no longer exists or is already fully paid.
$linked_invoices = [];
foreach ($receipts as $r) {
    if ($r['invoice_number'] !== '' && floatval($r['balance']) > 0) {
        $linked_invoices[$r['invoice_number']] = null;
    }
}
if (count($linked_invoices) > 0) {
    $ph = implode(',', array_fill(0, count($linked_invoices), '?'));
    $stmt = $pdo->prepare("SELECT i.id, i.invoice_number, GREATEST(0, i.amount - COALESCE((SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.user_id = i.user_id AND p.status = 'paid'), 0)) AS outstanding FROM invoices i WHERE i.user_id = ? AND i.invoice_number IN ($ph)");
    $stmt->execute(array_merge([$user_id], array_keys($linked_invoices)));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $linked_invoices[$row['invoice_number']] = ['id' => intval($row['id']), 'outstanding' => floatval($row['outstanding'])];
    }
}

$activePage = 'receipts';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BizVaza – Receipts</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css?v=<?= @filemtime(__DIR__ . '/assets/css/styles.css') ?>">
<style>
.main-content{flex:1;display:flex;flex-direction:column;min-width:0}
.content{padding:0;flex:1;max-width:900px;margin:0 auto;width:100%}
.receipts-list-section{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 16px}
.receipts-list-section h2{font-size:16px;font-weight:600;color:var(--text);margin-bottom:14px}
.rcp-table{width:100%;border-collapse:collapse}
.rcp-table thead th{font-size:11px;color:var(--text-muted);font-weight:500;text-align:left;padding:8px 10px;background:var(--bg-card-alt);border-bottom:1px solid var(--border);white-space:nowrap}
.rcp-table tbody td{padding:10px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--border)}
.rcp-table tbody tr:last-child td{border-bottom:none}
.rcp-table tbody tr:hover{background:var(--bg-card-alt)}
.empty-receipts{padding:32px 16px;text-align:center;font-size:13px;color:var(--text-muted)}
.btn-generate{display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;margin-top:16px;font-family:'Inter',sans-serif}
.btn-generate:hover{background:var(--accent-hover)}
.btn-submit{width:100%;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:11px 24px;font-size:15px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;margin-top:8px}
.btn-submit:hover{background:var(--accent-hover)}
.rcp-edit-link{color:var(--accent);font-weight:500;text-decoration:none}
.form-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:28px;max-width:700px;width:100%}
.form-card h2{font-size:16px;font-weight:600;color:var(--text);margin-bottom:18px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.rcp-items-header{display:grid;grid-template-columns:1fr 1fr 90px 56px 110px 110px 32px;gap:8px;margin-bottom:6px}
.rcp-item-row{display:grid;grid-template-columns:1fr 1fr 90px 56px 110px 110px 32px;gap:8px;margin-bottom:6px}
.rcp-mobile-label{display:none}
@media(max-width:768px){
    .content{padding:0 16px;max-width:100%}
    .grid-2{grid-template-columns:1fr}
    .form-card{padding:16px;max-width:100%}
    .receipts-header{flex-direction:column!important;gap:12px;align-items:stretch}
    .receipts-header .btn-generate{width:100%;justify-content:center}
    .rcp-items-header{display:none}
    .rcp-item-row{grid-template-columns:1fr;gap:10px;padding:14px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px;background:var(--bg-card-alt)}
    .rcp-mobile-label{display:block;font-size:11px;font-weight:600;color:var(--text-muted);margin-top:2px}
    .rcp-item-row input,.rcp-item-row select{width:100%!important;box-sizing:border-box}
    .rcp-item-row input[type="number"]{text-align:left}
    .rcp-del-row{width:100%;padding:8px;text-align:center;font-size:12px;font-weight:500;color:var(--error);border:1px solid rgba(239,68,68,.35)!important;background:var(--error-bg)!important;border-radius:6px}
    #rcptItemsEditor .table-responsive>div{min-width:unset!important}
}
@media(max-width:480px){.form-card{padding:14px}.rcp-item-row{padding:12px;gap:8px}}
</style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">

<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Receipts</h2>
<p>Manage generated receipts</p>
</div>
</div>
<div class="invoice-topbar-right">
<button class="icon-btn" id="bellBtn" onclick="toggleNotif(event)" style="position:relative">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
<span class="bell-dot"></span>
</button>
<?php require __DIR__ . '/includes/user-menu.php'; ?>
</div>
</header>

<div class="content">

<?php if (isset($_SESSION['flash_success'])): ?>
<div class="flash-success"><?= htmlspecialchars($_SESSION['flash_success']) ?><?php unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_error'])): ?>
<div class="flash-error"><?= htmlspecialchars($_SESSION['flash_error']) ?><?php unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>

<div class="receipts-list-section">
<div class="receipts-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
<h2 style="margin:0">All Receipts</h2>
<button class="btn-generate" onclick="showForm()" style="margin:0">+ Generate Receipt</button>
</div>

<div class="filter-bar">
<div class="filter-group">
<label>Date From</label>
<input type="date" id="rcpFilterDateFrom" onchange="applyRcpFilters()">
</div>
<div class="filter-group">
<label>Date To</label>
<input type="date" id="rcpFilterDateTo" onchange="applyRcpFilters()">
</div>
<div class="filter-group">
<label>Customer</label>
<input type="text" id="rcpFilterCustomer" placeholder="Search customer..." oninput="applyRcpFilters()">
</div>
<button class="btn-clear" onclick="clearRcpFilters()">Clear</button>
</div>

<?php if (count($receipts) > 0): ?>
<div class="table-responsive">
<table class="rcp-table">
<thead>
<tr>
<th>Receipt #</th>
<th>Customer</th>
<th>Invoice #</th>
<th>Amount (₦)</th>
<th>Balance (₦)</th>
<th>Date</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php foreach ($receipts as $r): ?>
<tr>
<td><a href="view-receipt.php?id=<?= $r['id'] ?>" style="color:var(--accent);font-weight:500;text-decoration:none"><?= htmlspecialchars($r['receipt_number']) ?></a></td>
<td class="rcp-customer"><?= htmlspecialchars($r['customer_name']) ?></td>
<td><?= htmlspecialchars($r['invoice_number']) ?></td>
<td>₦<?= number_format($r['amount'], 2) ?></td>
<?php if ($r['invoice_number'] !== '' && isset($linked_invoices[$r['invoice_number']]) && $linked_invoices[$r['invoice_number']]['outstanding'] > 0): ?>
<td><a href="receipts.php?invoice_id=<?= $linked_invoices[$r['invoice_number']]['id'] ?>" style="color:var(--accent);font-weight:600;text-decoration:none">₦<?= number_format($linked_invoices[$r['invoice_number']]['outstanding'], 2) ?> due · Receive</a></td>
<?php elseif ($r['invoice_number'] === '' && floatval($r['balance']) > 0): ?>
<td><a href="receipts.php?from_receipt=<?= $r['id'] ?>" style="color:var(--accent);font-weight:600;text-decoration:none">₦<?= number_format(floatval($r['balance']), 2) ?> due · Receive</a></td>
<?php else: ?>
<td style="<?= floatval($r['balance']) > 0 ? 'color:var(--accent);font-weight:500' : 'color:#cbd5e1' ?>">₦<?= number_format(floatval($r['balance']), 2) ?></td>
<?php endif; ?>
<td class="rcp-date" data-date="<?= htmlspecialchars($r['payment_date']) ?>"><?= $r['payment_date'] ? date('M d, Y', strtotime($r['payment_date'])) : 'N/A' ?></td>
<td><span class="badge b-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-receipts">No receipts yet. Generate your first receipt below.</div>
<?php endif; ?>

</div>

<div class="form-wrapper <?= ((isset($_GET['invoice_id']) && intval($_GET['invoice_id']) > 0) || (isset($_GET['from_receipt']) && intval($_GET['from_receipt']) > 0)) ? 'show' : '' ?>" id="formWrapper">
<div class="form-header">
<h2>Generate Receipt</h2>
<button class="btn-cancel-form" type="button" onclick="hideForm()">Cancel</button>
</div>
<form method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" value="record_receipt">
<input type="hidden" name="edit_id" value="0">
<input type="hidden" name="linked_receipt" value="<?= $prefill_linked_receipt ?>">
<div class="form-card">
<div class="grid-2">
<div class="form-group">
<label>Receipt Number</label>
<input type="text" name="receipt_number" value="<?= htmlspecialchars($next_receipt) ?>" required>
</div>
<div class="form-group">
<label>Payment Date</label>
<input type="date" name="payment_date" value="<?= date('Y-m-d') ?>">
</div>
</div>
<div class="grid-2">
<div class="form-group">
<label>Customer Name *</label>
<input type="text" name="customer_name" value="<?= $prefill_customer ?>" required>
</div>
<div class="form-group">
<label>Invoice Number</label>
<input type="text" name="invoice_number" value="<?= $prefill_invoice ?>">
</div>
</div>
<div class="grid-2">
<div class="form-group">
<label>Amount Received (₦) *</label>
<input type="number" name="amount" id="rcptReceivedInput" step="0.01" value="<?= $prefill_amount ?: '0' ?>" required>
</div>
<div class="form-group">
<label>Status</label>
<select name="status">
<option value="paid">Paid</option>
<option value="processing">Processing</option>
<option value="failed">Failed</option>
</select>
</div>
</div>
<div class="grid-2">
<div class="form-group">
<label>Total Amount (₦)</label>
<input type="text" id="rcptTotalInput" value="<?= $prefill_total !== '' ? $prefill_total : '0.00' ?>" readonly tabindex="-1">
<span id="rcptTotalHint" style="font-size:11px;color:var(--text-muted);display:block;margin-top:4px"><?= htmlspecialchars($prefill_hint) ?></span>
</div>
<div class="form-group" id="rcptBalanceWrap">
<label>Balance Due (₦)</label>
<input type="number" name="balance" id="rcptBalanceInput" step="0.01" min="0" value="0">
<span style="font-size:11px;color:var(--text-muted);display:block;margin-top:4px">Auto-calculated from total and amount received.</span>
</div>
</div>
<div id="rcptItemsEditor" style="margin-top:6px">
<label style="font-size:13px;font-weight:600;color:var(--text);display:block;margin-bottom:8px">Items / Services</label>
<div class="table-responsive">
<div>
<div class="rcp-items-header">
<span style="font-size:11px;color:var(--text-muted);font-weight:500">Item</span>
<span style="font-size:11px;color:var(--text-muted);font-weight:500">Description</span>
<span style="font-size:11px;color:var(--text-muted);font-weight:500">Type</span>
<span style="font-size:11px;color:var(--text-muted);font-weight:500;text-align:center">Qty</span>
<span style="font-size:11px;color:var(--text-muted);font-weight:500;text-align:right">Unit Price (₦)</span>
<span style="font-size:11px;color:var(--text-muted);font-weight:500;text-align:right">Amount (₦)</span>
<span></span>
</div>
<div id="rcptItemsBody" style="margin-bottom:8px">
<?php
$editor_rows = [];
if (count($editor_rows) === 0) {
    $editor_rows = [['name' => '', 'description' => '', 'type' => 'product', 'quantity' => 1, 'unit_price' => null, 'amount' => 0]];
}
foreach ($editor_rows as $er):
    $er_type = ($er['type'] ?? 'product') === 'service' ? 'service' : 'product';
    $er_price = $er['unit_price'] !== null && $er['unit_price'] !== '' ? rtrim(rtrim(number_format(floatval($er['unit_price']), 2, '.', ''), '0'), '.') : '';
?>
<div class="rcp-item-row">
<span class="rcp-mobile-label">Item / Service</span>
<input type="text" name="item_name[]" placeholder="Item name" list="rcpt-items-list" data-voice value="<?= htmlspecialchars($er['name']) ?>" style="padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:var(--text);outline:none;width:100%;box-sizing:border-box">
<span class="rcp-mobile-label">Description</span>
<input type="text" name="item_description[]" placeholder="Description" data-voice value="<?= htmlspecialchars($er['description'] ?? '') ?>" style="padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:var(--text);outline:none;width:100%;box-sizing:border-box">
<span class="rcp-mobile-label">Type</span>
<select name="item_type[]" class="rcp-item-type" style="padding:8px 4px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:var(--text);outline:none;width:100%;box-sizing:border-box;background:var(--bg-card)">
<option value="product" <?= $er_type === 'product' ? 'selected' : '' ?>>Product</option>
<option value="service" <?= $er_type === 'service' ? 'selected' : '' ?>>Service</option>
</select>
<span class="rcp-mobile-label">Qty</span>
<input type="number" name="item_qty[]" class="rcp-qty-input" value="<?= max(1, intval($er['quantity'] ?? 1)) ?>" min="1" <?= $er_type === 'service' ? 'disabled' : '' ?> style="padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:var(--text);outline:none;text-align:center;width:100%;box-sizing:border-box;opacity:<?= $er_type === 'service' ? '0.5' : '1' ?>">
<span class="rcp-mobile-label">Unit Price (₦)</span>
<input type="text" name="item_price[]" class="rcp-price-input" placeholder="0.00" value="<?= htmlspecialchars($er_price) ?>" style="padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:var(--text);outline:none;text-align:right;width:100%;box-sizing:border-box">
<span class="rcp-mobile-label">Amount (auto-calc)</span>
<input type="text" name="item_amount[]" class="rcp-amount-input" value="<?= number_format(floatval($er['amount'] ?? 0), 2) ?>" readonly style="padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:var(--text-muted);outline:none;text-align:right;background:var(--bg-card-alt);width:100%;box-sizing:border-box">
<button type="button" class="rcp-del-row" title="Delete Item" style="border:none;background:none;color:#c0c8d4;font-size:18px;cursor:pointer;line-height:1;padding:8px 0">×</button>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
<button type="button" id="rcpAddItem" style="display:inline-flex;align-items:center;gap:6px;background:var(--accent-bg);color:var(--accent);border:1px solid #fed7aa;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif">+ Add Item</button>
<datalist id="rcpt-items-list">
<?php foreach ($catalog_items as $ci): ?>
<option value="<?= htmlspecialchars($ci) ?>">
<?php endforeach; ?>
</datalist>
</div>
<div class="form-group">
<label>Notes (Optional)</label>
<textarea name="notes" rows="3" style="resize:none;width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:'Inter',sans-serif;color:var(--text);outline:none" placeholder="Additional notes..."><?= htmlspecialchars($_SESSION['receipt_default_notes'] ?? '') ?></textarea>
</div>

<?php if (count($prefill_items) > 0): ?>
<div class="table-responsive">
<div style="margin-top:12px;margin-bottom:12px">
<table style="width:100%;border-collapse:collapse;font-size:12px">
<thead>
<tr style="background:var(--bg-card-alt);border-bottom:1px solid var(--border)">
<th style="padding:6px 8px;text-align:left;color:var(--text-muted);font-weight:500">Item</th>
<th style="padding:6px 8px;text-align:center;color:var(--text-muted);font-weight:500">Qty</th>
<th style="padding:6px 8px;text-align:right;color:var(--text-muted);font-weight:500">Amount (₦)</th>
</tr>
</thead>
<tbody>
<?php foreach ($prefill_items as $pi): ?>
<tr style="border-bottom:1px solid #f1f5f9">
<td style="padding:6px 8px;color:var(--text)"><?= htmlspecialchars($pi['name']) ?></td>
<td style="padding:6px 8px;text-align:center;color:var(--text-muted)"><?= intval($pi['quantity']) ?></td>
<td style="padding:6px 8px;text-align:right;color:var(--text);font-weight:500"><?= number_format(floatval($pi['amount']), 2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<button type="submit" class="btn-submit">Generate Receipt</button>
</div>
</form>
</div>

</div>

<script src="assets/js/app.js"></script>
<script src="receipts.js"></script>
<script>
function applyRcpFilters() {
    var from = document.getElementById('rcpFilterDateFrom');
    var to = document.getElementById('rcpFilterDateTo');
    var cust = document.getElementById('rcpFilterCustomer');
    if (!from || !to || !cust) return;
    var fv = from.value, tv = to.value, cv = cust.value.toLowerCase();
    document.querySelectorAll('.rcp-table tbody tr').forEach(function(row) {
        var date = row.querySelector('.rcp-date');
        var customer = row.querySelector('.rcp-customer');
        var show = true;
        if (date) {
            var d = date.getAttribute('data-date') || date.textContent.trim();
            if (fv && d < fv) show = false;
            if (tv && d > tv) show = false;
        }
        if (customer && cv && !customer.textContent.toLowerCase().includes(cv)) show = false;
        row.style.display = show ? '' : 'none';
    });
}
function clearRcpFilters() {
    var from = document.getElementById('rcpFilterDateFrom');
    var to = document.getElementById('rcpFilterDateTo');
    var cust = document.getElementById('rcpFilterCustomer');
    if (from) from.value = '';
    if (to) to.value = '';
    if (cust) cust.value = '';
    applyRcpFilters();
}
</script>
<script src="assets/js/speech-input.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</body>
</html>
