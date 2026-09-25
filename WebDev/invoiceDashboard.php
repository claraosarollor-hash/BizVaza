<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
$activePage = 'invoices';
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$business_abbr = $_SESSION['business_abbr'] ?? 'BIZ';
$avatar_initials = $_SESSION['avatar_initials'];

// Handle delete invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    validate_csrf();
    $delete_id = intval($_POST['delete_id']);
    $stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ? AND user_id = ?");
    $stmt->execute([$delete_id, $user_id]);
    $del_inv = $stmt->fetch();
    if ($del_inv) {
        $inv_num = $del_inv['invoice_number'];
        $stmt = $pdo->prepare("SELECT id FROM payments WHERE invoice_number = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$inv_num, $user_id]);
        if ($stmt->fetch()) {
            $_SESSION['flash_error'] = 'Cannot delete an invoice that already has a receipt.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM activity_log WHERE reference_type = 'invoice' AND reference_id = ? AND user_id = ?");
            $stmt->execute([$delete_id, $user_id]);
            $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ? AND user_id = ?");
            $stmt->execute([$delete_id, $user_id]);
            logActivity($pdo, $user_id, 'invoice_deleted', "Invoice {$inv_num} deleted", 'invoice', $delete_id);
        }
    }
    header('Location: invoiceDashboard.php');
    exit;
}

// Handle edit invoice - load existing data
$edit_inv = null;
$edit_items = [];
$edit_mode = false;
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND user_id = ?");
    $stmt->execute([$edit_id, $user_id]);
    $edit_inv = $stmt->fetch();
    if ($edit_inv) {
        $stmt = $pdo->prepare("SELECT id FROM payments WHERE invoice_number = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$edit_inv['invoice_number'], $user_id]);
        if ($stmt->fetch()) {
            $_SESSION['flash_error'] = 'Cannot edit an invoice that already has a receipt.';
            header('Location: invoiceDashboard.php');
            exit;
        }
        $edit_mode = true;
        $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $stmt->execute([$edit_id]);
        $edit_items = $stmt->fetchAll();
    } else {
        header('Location: invoiceDashboard.php');
        exit;
    }
}

// Handle Create Invoice form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    validate_csrf();
    $inv_num = trim($_POST['invoice_number'] ?? '');
    $issue = !empty($_POST['issue_date']) ? $_POST['issue_date'] : date('Y-m-d');
    $due = $_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days'));
    $customer = trim($_POST['customer_name'] ?? '');

    // Parse line items and recompute totals server-side (never trust the client's total)
    $item_names = $_POST['item_name'] ?? [];
    $item_descs = $_POST['item_description'] ?? [];
    $item_types = $_POST['item_type'] ?? [];
    $item_qtys = $_POST['item_qty'] ?? [];
    $item_prices = $_POST['item_price'] ?? [];
    $parsed_items = [];
    $subtotal = 0;
    foreach ($item_names as $i => $name) {
        $name = trim($name);
        if ($name === '') continue;
        $qty = max(0, intval($item_qtys[$i] ?? 1));
        $price = max(0, floatval(str_replace(',', '', $item_prices[$i] ?? '0')));
        $line_amt = round($qty * $price * 100) / 100;
        $subtotal += $line_amt;
        $parsed_items[] = ['name' => $name, 'desc' => trim($item_descs[$i] ?? ''), 'type' => trim($item_types[$i] ?? 'product'), 'qty' => $qty, 'price' => $price, 'amount' => $line_amt];
    }
    $subtotal = round($subtotal * 100) / 100;

    $discount_type = trim($_POST['discount_type'] ?? 'Percentage');
    $discount_value = max(0, floatval($_POST['discount_value'] ?? '0'));
    $discount_amt = $discount_type === 'Percentage' ? $subtotal * ($discount_value / 100) : $discount_value;
    $discount_amt = min($subtotal, round($discount_amt * 100) / 100);
    $amount = round(($subtotal - $discount_amt) * 100) / 100;

    $cust_email = trim($_POST['customer_email'] ?? '');
    $cust_phone = trim($_POST['customer_phone'] ?? '');
    $billing_addr = trim($_POST['billing_address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $edit_id = intval($_POST['edit_id'] ?? 0);
    if ($inv_num !== '' && $customer !== '') {
        $dup_num = $edit_id > 0
            ? "SELECT id FROM invoices WHERE invoice_number = ? AND user_id = ? AND id <> ? LIMIT 1"
            : "SELECT id FROM invoices WHERE invoice_number = ? AND user_id = ? LIMIT 1";
        $stmt = $pdo->prepare($dup_num);
        if ($edit_id > 0) {
            $stmt->execute([$inv_num, $user_id, $edit_id]);
        } else {
            $stmt->execute([$inv_num, $user_id]);
        }
        if ($stmt->fetch()) {
            $_SESSION['flash_error'] = "Invoice number {$inv_num} is already in use. Use the suggested invoice number.";
            header('Location: invoiceDashboard.php');
            exit;
        }
        if ($edit_id > 0) {
            // UPDATE existing invoice
            $stmt = $pdo->prepare("SELECT id FROM payments WHERE invoice_number = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$inv_num, $user_id]);
            if ($stmt->fetch()) {
                $_SESSION['flash_error'] = 'Cannot edit an invoice that already has a receipt.';
                header('Location: invoiceDashboard.php');
                exit;
            }
            $template_id = (isPro() && !empty($_POST['template_id'])) ? intval($_POST['template_id']) : null;
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE invoices SET invoice_number=?, customer_name=?, customer_email=?, customer_phone=?, billing_address=?, issue_date=?, due_date=?, amount=?, notes=?, discount_type=?, discount_value=?, template_id=? WHERE id=? AND user_id=?");
                $stmt->execute([$inv_num, $customer, $cust_email, $cust_phone, $billing_addr, $issue, $due, $amount, $notes, $discount_type, $discount_value, $template_id, $edit_id, $user_id]);
                $inv_id = $edit_id;
                $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
                $stmt->execute([$edit_id]);
                if (count($parsed_items) > 0) {
                    $item_stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, type, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($parsed_items as $it) {
                        $item_stmt->execute([$inv_id, $it['name'], $it['type'], $it['desc'], $it['qty'], $it['price'], $it['amount']]);
                    }
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('BizVaza invoice update failed: ' . $e->getMessage());
                $_SESSION['flash_error'] = 'Could not save invoice. Please try again.';
                header('Location: invoiceDashboard.php');
                exit;
            }
        } else {
            // CREATE new invoice
            if (invoiceLimitReached($pdo, $user_id)) {
                $_SESSION['flash_error'] = 'Free plan is limited to ' . FREE_MONTHLY_INVOICE_LIMIT . ' invoices this month. Upgrade to Pro for unlimited invoices & receipts.';
                header('Location: invoiceDashboard.php');
                exit;
            }
            $pdo->beginTransaction();
            try {
                consumeDocNumber($pdo, $user_id, 'invoice', $business_abbr);
                $template_id = (isPro() && !empty($_POST['template_id'])) ? intval($_POST['template_id']) : null;
                $stmt = $pdo->prepare("INSERT INTO invoices (user_id, invoice_number, customer_name, customer_email, customer_phone, billing_address, issue_date, due_date, amount, notes, discount_type, discount_value, template_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$user_id, $inv_num, $customer, $cust_email, $cust_phone, $billing_addr, $issue, $due, $amount, $notes, $discount_type, $discount_value, $template_id]);
                $inv_id = $pdo->lastInsertId();
                if (count($parsed_items) > 0) {
                    $item_stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, type, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($parsed_items as $it) {
                        $item_stmt->execute([$inv_id, $it['name'], $it['type'], $it['desc'], $it['qty'], $it['price'], $it['amount']]);
                    }
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('BizVaza invoice create failed: ' . $e->getMessage());
                $_SESSION['flash_error'] = 'Could not save invoice. Please try again.';
                header('Location: invoiceDashboard.php');
                exit;
            }
        }

        // Create recurring schedule (new invoices only)
        if ($edit_id === 0 && !empty($_POST['recurring'])) {
            $rec_freq = in_array($_POST['recurring_frequency'] ?? 'monthly', ['daily', 'weekly', 'monthly', 'yearly']) ? $_POST['recurring_frequency'] : 'monthly';
            $rec_interval = max(1, intval($_POST['recurring_interval'] ?? 1));
            $rec_next = $_POST['recurring_next_run'] ?? '';
            if ($rec_next === '') $rec_next = date('Y-m-d', strtotime('+1 month'));
            $rec_next = ($rec_next < date('Y-m-d')) ? date('Y-m-d') : $rec_next;
            $rec_due_days = max(0, intval($_POST['recurring_due_days'] ?? 14));
            $rec_title = trim($_POST['recurring_title'] ?? '');
            if ($rec_title === '') $rec_title = 'Recurring invoice for ' . $customer;
            $items_json = json_encode($parsed_items, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("INSERT INTO recurring_invoices (user_id, title, customer_name, customer_email, customer_phone, billing_address, frequency, interval_count, next_run_date, issue_date, due_days, items_json, discount_type, discount_value, notes, template_id, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$user_id, $rec_title, $customer, $cust_email, $cust_phone, $billing_addr, $rec_freq, $rec_interval, $rec_next, $issue, $rec_due_days, $items_json, $discount_type, $discount_value, $notes, $template_id, $amount]);
            logActivity($pdo, $user_id, 'recurring_created', "Recurring invoice created for {$customer} — ₦" . number_format($amount, 2) . " every {$rec_interval} {$rec_freq}", 'recurring', $pdo->lastInsertId());
        }

        $action = $edit_id > 0 ? 'invoice_updated' : 'invoice_created';
        logActivity($pdo, $user_id, $action, "Invoice {$inv_num} " . ($edit_id > 0 ? 'updated' : 'created') . " for {$customer} — ₦" . number_format($amount, 2), 'invoice', $inv_id);
        if ($cust_email !== '') {
            require_once __DIR__ . '/includes/mailer.php';
            sendInvoiceEmail($pdo, $inv_num, $customer, $cust_email, $due, $amount, $_SESSION['business_name'] ?? 'BizVaza');
        }
        if ($edit_id > 0) {
            $_SESSION['flash_success'] = 'Invoice updated successfully.';
            header('Location: invoiceDashboard.php');
        } else {
            header('Location: create-invoice.php?id=' . $inv_id);
        }
        exit;
    } else {
        $_SESSION['flash_error'] = 'Invoice number or customer name is empty.';
        header('Location: invoiceDashboard.php');
        exit;
    }
}

// Fetch existing invoices with computed status (paid only when fully covered) and payment flag
$stmt = $pdo->prepare("
  SELECT i.*,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0) as paid_amount,
    (SELECT COUNT(*) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.user_id = i.user_id) as has_payment
  FROM invoices i
  WHERE i.user_id = ?
  ORDER BY i.created_at DESC
");
$stmt->execute([$user_id]);
$invoices = $stmt->fetchAll();
foreach ($invoices as &$inv) {
    $inv['computed_status'] = (floatval($inv['paid_amount']) >= floatval($inv['amount']) && floatval($inv['amount']) > 0) ? 'paid' : (floatval($inv['paid_amount']) > 0 ? 'partial' : 'pending');
}
unset($inv);

// Fetch customers for dropdown
$stmt = $pdo->prepare("SELECT id, name, email, phone, billing_address FROM customers WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$customers = $stmt->fetchAll();
$customer_json = json_encode(array_map(function($c) {
    return [
        'name' => $c['name'],
        'email' => $c['email'] ?? '',
        'phone' => $c['phone'] ?? '',
        'billing_address' => $c['billing_address'] ?? ''
    ];
}, $customers), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// Fetch items for auto-fill
$stmt = $pdo->prepare("SELECT id, name, description, category, unit_price FROM items WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$items_list = $stmt->fetchAll();
$items_json = json_encode(array_map(function($i) {
    return [
        'name' => $i['name'],
        'description' => $i['description'] ?? '',
        'category' => $i['category'] ?? 'Product',
        'unit_price' => $i['unit_price'] ?? 0
    ];
}, $items_list), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// Auto-generate next invoice number (monotonic, never reused)
$next_inv_num = nextDocNumber($pdo, $user_id, 'invoice', $business_abbr);

// Load templates for Pro users
$invoice_templates = [];
if (isset($_SESSION['is_pro']) && $_SESSION['is_pro']) {
    $invoice_templates = getTemplates($pdo, 'invoice');
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_token() ?>">
<title>BizVaza – Invoices</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css?v=<?= @filemtime(__DIR__ . '/assets/css/styles.css') ?>">
<link rel="stylesheet" href="invoiceDashboard.css"/>
<style>
.inv-table{width:100%;border-collapse:collapse}
.inv-table thead th{font-size:11px;color:var(--text-muted);font-weight:500;text-align:left;padding:8px 10px;background:var(--bg-card-alt);border-bottom:1px solid var(--border);white-space:nowrap}
.inv-table tbody td{padding:10px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--border)}
.inv-table tbody tr:last-child td{border-bottom:none}
.inv-table tbody tr:hover{background:var(--bg-card-alt)}
.inv-link{color:var(--accent);font-weight:500;text-decoration:none}
.inv-link:hover{text-decoration:underline}
.empty-invoices{padding:32px 16px;text-align:center;font-size:13px;color:var(--text-muted)}
.btn-create-invoice{display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;margin-top:16px;font-family:'Inter',sans-serif}
.btn-create-invoice:hover{background:var(--accent-hover)}
.btn-icon,.btn-delete-inv{background:none;border:none;cursor:pointer;padding:5px;color:#6b7a90;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;transition:color .13s,background .13s}
.btn-icon:hover,.btn-delete-inv:hover{color:var(--accent);background:var(--accent-bg)}
.btn-icon.del:hover,.btn-delete-inv.del:hover{color:#ef4444;background:var(--error-bg)}
</style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Invoices</h2>
<p>Manage and create invoices</p>
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

<div class="invoice-content">

<?php if (isset($_SESSION['flash_error'])): ?>
<div style="background:var(--error-bg);color:var(--error);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;border:1px solid rgba(239,68,68,.35)"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
<?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['flash_success'])): ?>
<div style="background:var(--success-bg);color:var(--success);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;border:1px solid #a7f3d0"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
<?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div class="invoices-list-section">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
<h2 style="margin:0">All Invoices</h2>
<button class="btn-create-invoice" onclick="showForm()" style="margin:0">+ Create Invoice</button>
</div>
<?php if (!isPro()): ?>
<p style="margin:0 0 12px;font-size:12px;color:var(--text-muted)"><?= invoiceCountThisMonth($pdo, $user_id) ?>/<?= FREE_MONTHLY_INVOICE_LIMIT ?> invoices created this month on the Free plan. <a href="pricing.php" style="color:var(--accent);text-decoration:none">Upgrade to Pro</a> for unlimited invoices &amp; receipts.</p>
<?php endif; ?>

<div class="filter-bar">
<div class="filter-group">
<label>Date From</label>
<input type="date" id="filterDateFrom" onchange="applyFilters()">
</div>
<div class="filter-group">
<label>Date To</label>
<input type="date" id="filterDateTo" onchange="applyFilters()">
</div>
<div class="filter-group">
<label>Customer</label>
<input type="text" id="filterCustomer" placeholder="Search customer..." oninput="applyFilters()">
</div>
<button class="btn-clear" onclick="clearFilters()">Clear</button>
</div>

<?php if (count($invoices) > 0): ?>
<div class="table-responsive">
<table class="inv-table">
<thead>
<tr><th>Invoice #</th><th>Customer</th><th>Issue Date</th><th>Due Date</th><th>Amount</th><th>Status</th><th>Action</th></tr>
</thead>
<tbody>
<?php foreach ($invoices as $inv): ?>
<tr>
<td><a href="create-invoice.php?id=<?= $inv['id'] ?>" class="inv-link"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
<td class="inv-customer"><?= htmlspecialchars($inv['customer_name']) ?></td>
<td class="inv-date" data-date="<?= $inv['issue_date'] ?>"><?= date('M d, Y', strtotime($inv['issue_date'])) ?></td>
<td><?= date('M d, Y', strtotime($inv['due_date'])) ?></td>
<td>₦<?= number_format($inv['amount'], 2) ?></td>
<td><?php if ($inv['computed_status'] === 'partial'): ?><span class="badge b-partial">Partially Paid (₦<?= number_format($inv['paid_amount'], 2) ?> of ₦<?= number_format($inv['amount'], 2) ?>)</span><?php else: ?><span class="badge b-<?= $inv['computed_status'] ?>"><?= ucfirst($inv['computed_status']) ?></span><?php endif; ?></td>
<td style="display:flex;gap:6px"><?php if (!$inv['has_payment']): ?><a href="?edit=<?= $inv['id'] ?>" class="btn-icon" title="Edit Invoice"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a><button class="btn-delete-inv del" onclick="return deleteInvoice('<?= htmlspecialchars($inv['invoice_number']) ?>', <?= $inv['id'] ?>)" title="Delete"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-invoices">No invoices yet.</div>
<?php endif; ?>

</div>

<div class="form-wrapper<?= $edit_mode ? ' show' : '' ?>" id="formWrapper">
<!-- CUSTOMERS:<?= count($customers) ?> ITEMS:<?= count($items_list) ?> -->
<div class="form-header">
<h2><?= $edit_mode ? 'Edit Invoice' : 'Create Invoice' ?></h2>
<button class="btn-cancel-form" type="button" onclick="hideForm()">Cancel</button>
</div>
<form method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" value="create_invoice">
<?php if ($edit_mode): ?><input type="hidden" name="edit_id" value="<?= $edit_id ?>"><?php endif; ?>
<div class="form-card">
<h2>Invoice Information</h2>
<div class="grid-3">
    <div class="form-group">
    <label>Invoice Number</label>
    <input type="text" name="invoice_number" value="<?= $edit_mode ? htmlspecialchars($edit_inv['invoice_number']) : $next_inv_num ?>">
    </div>
    <div class="form-group">
    <label>Issue Date</label>
    <input type="date" name="issue_date" value="<?= $edit_mode ? $edit_inv['issue_date'] : date('Y-m-d') ?>">
    </div>
    <div class="form-group">
    <label>Due Date</label>
    <input type="date" name="due_date" value="<?= $edit_mode ? $edit_inv['due_date'] : date('Y-m-d', strtotime('+14 days')) ?>">
    </div>
</div>

<?php if (!$edit_mode): ?>
<div class="form-group" style="margin:10px 0 4px">
<label style="display:flex;align-items:center;gap:8px;cursor:pointer;width:auto;margin:0;font-size:13px;font-weight:500;color:#3a4a5c">
<input type="checkbox" name="recurring" id="recurringToggle" style="width:15px;height:15px"> Make this a recurring invoice
</label>
</div>
<div id="recurringOptions" style="display:none;background:var(--bg-card-alt);border:1px dashed var(--border-input);border-radius:8px;padding:14px;margin-bottom:16px">
<div class="form-group">
<label>Recurring Title</label>
<input type="text" name="recurring_title" id="recurringTitle" placeholder="e.g. Monthly retainer — Acme Ltd" data-voice>
</div>
<div class="grid-3">
<div class="form-group">
<label>Frequency</label>
<select name="recurring_frequency" id="recurringFrequency">
<option value="daily">Daily</option>
<option value="weekly">Weekly</option>
<option value="monthly" selected>Monthly</option>
<option value="yearly">Yearly</option>
</select>
</div>
<div class="form-group">
<label>Every</label>
<input type="number" name="recurring_interval" id="recurringInterval" value="1" min="1" style="background:transparent">
</div>
<div class="form-group">
<label>Next Run Date</label>
<input type="date" name="recurring_next_run" id="recurringNextRun">
</div>
</div>
<div class="form-group" style="margin-bottom:0">
<label>Due Days After Issue</label>
<input type="number" name="recurring_due_days" value="14" min="0">
</div>
</div>
<?php endif; ?>

<div class="form-group">
<div class="form-group-header">
<label>Customer</label>
<a href="customers.php" class="action-link">+ New Customer</a>
</div>
    <input type="text" name="customer_name" list="customer-list" placeholder="Type to search customer..." required value="<?= $edit_mode ? htmlspecialchars($edit_inv['customer_name']) : '' ?>" oninput="fillCustomerFields(this)" onchange="fillCustomerFields(this)" data-voice>
<datalist id="customer-list">
<?php foreach ($customers as $c): ?>
<option value="<?= htmlspecialchars($c['name']) ?>">
<?php endforeach; ?>
</datalist>
</div>

<h2>Bill To</h2>
<div class="grid-2">
<div class="form-group">
<label>Email Address</label>
    <input type="email" name="customer_email" placeholder="customer@example.com" value="<?= $edit_mode ? htmlspecialchars($edit_inv['customer_email']) : '' ?>" data-voice>
</div>
<div class="form-group">
<label>Phone Number</label>
    <input type="text" name="customer_phone" placeholder="Phone number" value="<?= $edit_mode ? htmlspecialchars($edit_inv['customer_phone']) : '' ?>" data-voice>
</div>
</div>
<div class="form-group">
<label>Billing Address</label>
    <textarea name="billing_address" placeholder="Address" data-voice><?= $edit_mode ? htmlspecialchars($edit_inv['billing_address']) : '' ?></textarea>
</div>

<div class="items-section">
<h2>Items / Services</h2>
<datalist id="items-list">
<?php foreach ($items_list as $i): ?>
<option value="<?= htmlspecialchars($i['name']) ?>">
<?php endforeach; ?>
</datalist>
<div class="table-header">
<label>Item</label>
<label>Description</label>
<label>Type</label>
<label>Qty</label>
<label>Unit Price (₦)</label>
<label>Amount (₦)</label>
<label></label>
</div>

<div class="items-table">
<?php if ($edit_mode && count($edit_items) > 0): ?>
<?php foreach ($edit_items as $ei): ?>
<div class="table-row">
<input type="text" name="item_name[]" placeholder="Item Name" list="items-list" oninput="fillItemFields(this)" onchange="fillItemFields(this)" value="<?= htmlspecialchars($ei['name']) ?>" data-voice>
<input type="text" name="item_description[]" placeholder="Description" value="<?= htmlspecialchars($ei['description']) ?>" data-voice>
<select name="item_type[]" class="item-type">
<option value="product" <?= $ei['type']==='product'?'selected':'' ?>>Product</option>
<option value="service" <?= $ei['type']==='service'?'selected':'' ?>>Service</option>
</select>
<input type="number" name="item_qty[]" class="qty-input" value="<?= $ei['quantity'] ?>" min="1">
<input type="text" name="item_price[]" class="price-input" placeholder="0.00" value="<?= number_format($ei['unit_price'], 2) ?>">
<input type="text" name="item_amount[]" class="amount-input" value="<?= number_format($ei['amount'], 2) ?>" readonly>
<button class="btn-delete" type="button" title="Delete Item"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="table-row">
<input type="text" name="item_name[]" placeholder="Item Name" list="items-list" oninput="fillItemFields(this)" onchange="fillItemFields(this)" data-voice>
<input type="text" name="item_description[]" placeholder="Description" data-voice>
<select name="item_type[]" class="item-type">
<option value="product">Product</option>
<option value="service">Service</option>
</select>
<input type="number" name="item_qty[]" class="qty-input" value="1" min="1">
<input type="text" name="item_price[]" class="price-input" placeholder="0.00">
<input type="text" name="item_amount[]" class="amount-input" value="0.00" readonly>
<button class="btn-delete" type="button" title="Delete Item"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
</div>
<?php endif; ?>
</div>

<button class="btn-add" type="button">+ Add Item</button>
<button class="mic-action-btn" type="button" title="Speak to Text">
<svg xmlns="http://w3.org" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
<rect x="9" y="2" width="6" height="12" rx="3" fill="currentColor" stroke="none"/>
<rect x="9" y="2" width="6" height="12" rx="3"/>
<path d="M19 10v1a7 7 0 0 1-14 0v-1"/>
<line x1="12" y1="18" x2="12" y2="22"/>
<line x1="8" y1="22" x2="16" y2="22"/>
</svg>
</button>
</div>

<div class="bottom-layout-grid">
<div class="form-group">
<?php if (isset($_SESSION['is_pro']) && $_SESSION['is_pro']): ?>
<label>Template</label>
<select name="template_id" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:'Inter',sans-serif;outline:none;margin-bottom:12px">
<option value="">— Default —</option>
<?php foreach ($invoice_templates as $t): ?>
<option value="<?= $t['id'] ?>"<?= ($edit_mode && $edit_inv['template_id'] == $t['id']) || (!$edit_mode && isset($_SESSION['selected_template_id']) && $_SESSION['selected_template_id'] == $t['id']) ? ' selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
<label>Notes (Optional)</label>
<textarea name="notes" rows="3" style="resize:none;" data-voice data-voice-append><?= htmlspecialchars($edit_mode ? $edit_inv['notes'] : ($_SESSION['invoice_default_notes'] ?? 'Thank you for your business!')) ?></textarea>
</div>

<div class="summary-container">
<div class="summary-row">
<span class="summary-label">Subtotal</span>
<strong class="summary-value">₦250,000.00</strong>
<input type="hidden" name="total_amount" value="<?= $edit_mode ? $edit_inv['amount'] : '0' ?>">
</div>
<div class="summary-row">
<span class="summary-label">Discount</span>
<div class="discount-box">
<select name="discount_type"><?php $dt = $edit_mode ? $edit_inv['discount_type'] : 'Percentage'; ?><option value="Percentage" <?= $dt==='Percentage'?'selected':'' ?>>Percentage</option><option value="Fixed" <?= $dt==='Fixed'?'selected':'' ?>>Fixed</option></select>
<input type="number" name="discount_value" value="<?= $edit_mode ? htmlspecialchars($edit_inv['discount_value']) : '0' ?>">
</div>
<strong class="summary-value summary-value-discount">₦0.00</strong>
</div>
<div class="summary-row total">
<span>Total Amount</span>
<span class="total-price">₦250,000.00</span>
</div>
</div>
</div>
</div>
<button type="submit" class="generate-btn"><?= $edit_mode ? 'Update Invoice' : 'Generate' ?></button>
</form>
</div>

</div>
<script src="invoiceDashboard.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/speech-input.js"></script>
<script>
var customersData = <?= $customer_json ?>;
var customerIndex = {};
if (customersData && customersData.length) {
    customersData.forEach(function(c) { customerIndex[c.name.toLowerCase()] = c; });
}
function fillCustomerFields(sel) {
    var name = sel ? sel.value.trim() : '';
    var match = name ? customerIndex[name.toLowerCase()] : null;
    var emailInput = document.querySelector('input[name="customer_email"]');
    var phoneInput = document.querySelector('input[name="customer_phone"]');
    var addrInput = document.querySelector('textarea[name="billing_address"]');
    if (!emailInput || !phoneInput || !addrInput) return;
    if (match) {
        sel.value = match.name;
        emailInput.value = match.email;
        phoneInput.value = match.phone;
        addrInput.value = match.billing_address;
    } else if (name === '') {
        emailInput.value = '';
        phoneInput.value = '';
        addrInput.value = '';
    }
}
window.fillCustomerFields = fillCustomerFields;
var itemsData = <?= $items_json ?>;
var itemsIndex = {};
if (itemsData && itemsData.length) {
    itemsData.forEach(function(i) { itemsIndex[i.name.toLowerCase()] = i; });
}
function fillItemFields(inp) {
    var name = inp ? inp.value.trim() : '';
    var match = name ? itemsIndex[name.toLowerCase()] : null;
    if (!match) return;
    inp.value = match.name;
    var row = inp.closest('.table-row');
    if (!row) return;
    var descInput = row.querySelector('input[name="item_description[]"]');
    var typeSelect = row.querySelector('select[name="item_type[]"]');
    var priceInput = row.querySelector('.price-input');
    if (descInput) descInput.value = match.description;
    if (typeSelect && match.category) typeSelect.value = match.category.toLowerCase();
    if (priceInput) priceInput.value = parseFloat(match.unit_price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (typeof calculateInvoice === 'function') calculateInvoice();
}
window.fillItemFields = fillItemFields;

/* ===== RECURRING OPTIONS ===== */
function recurNextDate(freq, interval) {
    var d = new Date();
    if (freq === 'daily') d.setDate(d.getDate() + interval);
    else if (freq === 'weekly') d.setDate(d.getDate() + 7 * interval);
    else if (freq === 'yearly') d.setFullYear(d.getFullYear() + interval);
    else d.setMonth(d.getMonth() + interval);
    function pad(n) { return n < 10 ? '0' + n : n; }
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}
(function() {
    var toggle = document.getElementById('recurringToggle');
    var options = document.getElementById('recurringOptions');
    if (!toggle || !options) return;
    toggle.addEventListener('change', function() {
        options.style.display = this.checked ? 'block' : 'none';
        var next = document.getElementById('recurringNextRun');
        if (this.checked && !next.value) next.value = recurNextDate(document.getElementById('recurringFrequency').value, parseInt(document.getElementById('recurringInterval').value) || 1);
    });
    document.getElementById('recurringFrequency').addEventListener('change', function() {
        var next = document.getElementById('recurringNextRun');
        if (toggle.checked && !next.value) next.value = recurNextDate(this.value, parseInt(document.getElementById('recurringInterval').value) || 1);
    });
    document.getElementById('recurringInterval').addEventListener('input', function() {
        var next = document.getElementById('recurringNextRun');
        if (toggle.checked && !next.value) next.value = recurNextDate(document.getElementById('recurringFrequency').value, parseInt(this.value) || 1);
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</body>
</html>
