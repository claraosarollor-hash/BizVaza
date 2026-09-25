<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$business_abbr = $_SESSION['business_abbr'] ?? 'BIZ';
$avatar_initials = $_SESSION['avatar_initials'];

// Flash messages
$flash_msg = '';
$flash_type = '';
if (isset($_SESSION['flash_msg'])) {
    $flash_msg = $_SESSION['flash_msg'];
    $flash_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Date range filter
$from_date = $_GET['from'] ?? '';
$to_date = $_GET['to'] ?? '';

// Build date filter SQL fragment
$date_where = '';
$date_params = [];
if ($from_date !== '') {
    $date_where .= " AND COALESCE(NULLIF(payment_date,'0000-00-00'), created_at) >= ?";
    $date_params[] = $from_date;
}
if ($to_date !== '') {
    $date_where .= " AND COALESCE(NULLIF(payment_date,'0000-00-00'), created_at) <= ?";
    $date_params[] = $to_date;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/includes/csv_export.php';
    csv_send('bizvaza-payments');

    $sql = "SELECT receipt_number, customer_name, invoice_number, amount, balance, payment_date, status, created_at FROM payments WHERE user_id = ?{$date_where} ORDER BY payment_date ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$user_id], $date_params));
    $rows = $stmt->fetchAll();

    $out = fopen('php://output', 'w');
    csv_title_block($out, 'BizVaza — Payments Report', $from_date, $to_date);

    csv_section($out, 'Payments');
    fputcsv($out, ['Receipt Number', 'Customer', 'Invoice #', 'Amount (₦)', 'Balance (₦)', 'Payment Date', 'Status']);
    $tot_amount = 0; $tot_balance = 0;
    foreach ($rows as $r) {
        $d = $r['payment_date'];
        if (!$d || $d === '0000-00-00') $d = $r['created_at'];
        $tot_amount += floatval($r['amount']);
        $tot_balance += floatval($r['balance']);
        fputcsv($out, csv_row([
            $r['receipt_number'],
            $r['customer_name'],
            $r['invoice_number'],
            number_format($r['amount'], 2),
            number_format($r['balance'], 2),
            $d ? date('Y-m-d', strtotime($d)) : '',
            $r['status']
        ]));
    }
    fputcsv($out, ['TOTAL', '', '', number_format($tot_amount, 2), number_format($tot_balance, 2), '', '']);

    fclose($out);
    exit;
}

// Print / PDF view
if (isset($_GET['print'])) {
    require_once __DIR__ . '/includes/csv_export.php';

    $sql = "SELECT receipt_number, customer_name, invoice_number, amount, balance, payment_date, status, created_at FROM payments WHERE user_id = ?{$date_where} ORDER BY payment_date ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$user_id], $date_params));
    $print_rows = $stmt->fetchAll();
    $p_cnt = count($print_rows);
    $p_amount = 0; $p_balance = 0;
    foreach ($print_rows as $r) { $p_amount += floatval($r['amount']); $p_balance += floatval($r['balance']); }

    $css_v = @filemtime(__DIR__ . '/assets/css/report-print.css') ?: '';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Payments Report</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/report-print.css?v=<?= $css_v ?>">
</head>
<body>
<div class="rp-sheet">
    <div class="rp-brandbar">
        <div class="rp-brand">
            <div class="rp-logo"><?php if (!empty($_SESSION['logo_path'])): ?><img src="<?= htmlspecialchars($_SESSION['logo_path']) ?>" alt=""/><?php else: ?><?= strtoupper(substr($_SESSION['business_name'] ?? 'B', 0, 1)) ?><?php endif; ?></div>
            <div class="rp-company"><?= htmlspecialchars($_SESSION['business_name'] ?? 'BizVaza') ?></div>
        </div>
        <div class="rp-doctype"><h1>PAYMENTS REPORT</h1></div>
    </div>
    <div class="rp-meta"><span>Generated: <?= date('M j, Y g:i A') ?></span><span>Period: <?= htmlspecialchars(report_period_text($from_date, $to_date)) ?></span></div>

    <div class="rp-section"><h2>Summary</h2></div>
    <div class="rp-stats">
        <div class="rp-stat"><div class="lbl">Payments</div><div class="val"><?= $p_cnt ?></div></div>
        <div class="rp-stat"><div class="lbl">Total Amount</div><div class="val">₦<?= number_format($p_amount, 2) ?></div></div>
        <div class="rp-stat"><div class="lbl">Total Balance Due</div><div class="val">₦<?= number_format($p_balance, 2) ?></div></div>
    </div>

    <div class="rp-section"><h2>Payments</h2>
        <table class="rp-table">
            <thead><tr><th>#</th><th>Receipt #</th><th>Customer</th><th>Invoice #</th><th class="rp-num">Amount (₦)</th><th class="rp-num">Balance (₦)</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>
            <?php $i = 1; foreach ($print_rows as $r): $d = $r['payment_date']; if (!$d || $d === '0000-00-00') { $d = $r['created_at']; } ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($r['receipt_number']) ?></td>
                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                <td><?= htmlspecialchars($r['invoice_number']) ?></td>
                <td class="rp-num"><?= number_format(floatval($r['amount']), 2) ?></td>
                <td class="rp-num"><?= number_format(floatval($r['balance']), 2) ?></td>
                <td><?= $d ? date('M j, Y', strtotime($d)) : '--' ?></td>
                <td><?= ucfirst(htmlspecialchars($r['status'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($p_cnt === 0): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--text-muted)">No payments for this period</td></tr>
            <?php else: ?>
            <tr class="rp-total"><td colspan="4">TOTAL</td><td class="rp-num"><?= number_format($p_amount, 2) ?></td><td class="rp-num"><?= number_format($p_balance, 2) ?></td><td colspan="2"></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="rp-foot"><span>BizVaza · Payments Report</span><span>Generated <?= date('M j, Y') ?></span></div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
</body>
</html><?php
    exit;
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 3;

// Auto-generate next receipt number (monotonic, never reused)
$next_receipt = nextDocNumber($pdo, $user_id, 'receipt', $business_abbr);

// Fetch all payments with date filter
$sql = "SELECT * FROM payments WHERE user_id = ?{$date_where} ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$user_id], $date_params));
$payments = $stmt->fetchAll();

// Group payments by month
$months = [];
foreach ($payments as $p) {
    $displayDate = $p['payment_date'];
    if (!$displayDate || $displayDate === '0000-00-00') {
        $displayDate = $p['created_at'];
    }
    $monthKey = date('Y-m', strtotime($displayDate));
    if (!isset($months[$monthKey])) {
        $months[$monthKey] = [
            'label' => date('F Y', strtotime($displayDate)),
            'total_amount' => 0,
            'total_count' => 0,
            'payments' => []
        ];
    }
    $months[$monthKey]['total_amount'] += $p['amount'];
    $months[$monthKey]['total_count']++;
    $months[$monthKey]['payments'][] = $p;
}
krsort($months);

$total_months = count($months);
$total_pages = max(1, ceil($total_months / $per_page));
$page = min($page, $total_pages);
$page_offset = ($page - 1) * $per_page;
$visible_months = array_slice($months, $page_offset, $per_page, true);

// Stats (respect date filter)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total_received FROM payments WHERE user_id = ? AND status = 'paid'{$date_where}");
$stmt->execute(array_merge([$user_id], $date_params));
$total_received = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'processing'{$date_where}");
$stmt->execute(array_merge([$user_id], $date_params));
$total_pending = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ?{$date_where}");
$stmt->execute(array_merge([$user_id], $date_params));
$payments_count = $stmt->fetchColumn();

// Month-over-month delta for received amount (ignores date filter, always current vs prev month)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE user_id = ? AND status = 'paid' AND MONTH(COALESCE(NULLIF(payment_date,'0000-00-00'), created_at)) = MONTH(CURRENT_DATE) AND YEAR(COALESCE(NULLIF(payment_date,'0000-00-00'), created_at)) = YEAR(CURRENT_DATE)");
$stmt->execute([$user_id]);
$cur_month_paid = floatval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE user_id = ? AND status = 'paid' AND MONTH(COALESCE(NULLIF(payment_date,'0000-00-00'), created_at)) = MONTH(CURRENT_DATE - INTERVAL 1 MONTH) AND YEAR(COALESCE(NULLIF(payment_date,'0000-00-00'), created_at)) = YEAR(CURRENT_DATE - INTERVAL 1 MONTH)");
$stmt->execute([$user_id]);
$prev_month_paid = floatval($stmt->fetchColumn());

$pay_delta = 0;
$pay_delta_pct = 0;
if ($prev_month_paid > 0) {
    $pay_delta = $cur_month_paid - $prev_month_paid;
    $pay_delta_pct = round(($pay_delta / $prev_month_paid) * 100, 1);
} elseif ($cur_month_paid > 0) {
    $pay_delta_pct = 100;
}

// Build query string for links (preserve filters, exclude page)
$link_params = $_GET;
unset($link_params['page'], $link_params['export']);
$base_qs = http_build_query($link_params);
$has_date_filter = ($from_date !== '' || $to_date !== '');

// Handle Record Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    validate_csrf();
    $receipt = trim($_POST['receipt_number'] ?? '');
    $customer = trim($_POST['customer_name'] ?? '');
    $invoice = trim($_POST['invoice_number'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $balance = round(max(0, floatval($_POST['balance'] ?? 0)), 2);
    $pdate = $_POST['payment_date'] ?? date('Y-m-d');
    $status = $_POST['status'] ?? 'paid';
    if ($pdate === '') { $pdate = date('Y-m-d'); }
    $block_msg = '';
    if ($receipt !== '' && $customer !== '') {
        if ($invoice !== '') {
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
        if ($block_msg === '') {
            $stmt = $pdo->prepare("SELECT id FROM payments WHERE receipt_number = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$receipt, $user_id]);
            if ($stmt->fetch()) {
                $block_msg = "Receipt number {$receipt} is already in use. Use the suggested receipt number or a different one.";
            }
        }
        if ($block_msg === '') {
            consumeDocNumber($pdo, $user_id, 'receipt', $business_abbr);
            try {
                $stmt = $pdo->prepare("INSERT INTO payments (user_id, receipt_number, customer_name, invoice_number, amount, balance, payment_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $receipt, $customer, $invoice, $amount, $balance, $pdate, $status]);
                logActivity($pdo, $user_id, 'payment_recorded', "Payment of ₦" . number_format($amount, 2) . " received from {$customer}", 'payment', $pdo->lastInsertId());
                $_SESSION['flash_msg'] = "Payment of ₦" . number_format($amount, 2) . " from {$customer} recorded successfully.";
                $_SESSION['flash_type'] = 'success';
            } catch (PDOException $e) {
                error_log('BizVaza record_payment failed: ' . $e->getMessage());
                $_SESSION['flash_msg'] = "That receipt number or invoice reference is already in use. Please use a different one.";
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            $_SESSION['flash_msg'] = $block_msg;
            $_SESSION['flash_type'] = 'error';
        }
    } else {
        $_SESSION['flash_msg'] = "Receipt number and customer name are required.";
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: payments.php');
    exit;
}

$activePage = 'payments';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BizVaza – Payments</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css">
<style>
.app{display:flex;min-height:100vh}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.sub-crumb{font-size:12px;color:var(--text-muted);margin-top:1px;display:flex;align-items:center;gap:6px}
.bell-wrap{position:relative;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;border-radius:50%;transition:background .12s}
.bell-wrap:hover{background:#f1f5f9}
.btn-create .divider-v{width:1px;height:14px;background:rgba(255,255,255,.35);margin:0 2px}
.page-area{flex:1;padding:24px 28px;display:flex;flex-direction:column;gap:20px}
.page-header h2{font-size:20px;font-weight:700;color:var(--text)}
.stats-row{display:flex;gap:16px;flex-wrap:wrap}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;flex:1;min-width:180px}
.stat-card .stat-label{font-size:12px;color:var(--text-muted);font-weight:500;margin-bottom:4px}
.stat-card .stat-value{font-size:22px;font-weight:700;color:var(--text)}
.stat-card .stat-delta{font-size:11px;margin-top:4px;display:flex;align-items:center;gap:4px}
.stat-card .stat-delta.up{color:var(--success)}
.stat-card .stat-delta.down{color:var(--error)}
.stat-card .stat-delta.neutral{color:var(--text-muted)}
.content-row{flex:1}
.table-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.table-toolbar{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border);gap:12px;flex-wrap:wrap}
.toolbar-left{display:flex;align-items:center;gap:10px}
.toolbar-title{font-size:14px;font-weight:700;color:var(--text)}
.toolbar-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.total-badge{font-size:12px;color:var(--text-muted);background:#f1f4f9;padding:4px 11px;border-radius:20px;white-space:nowrap}
.total-badge strong{color:var(--text)}
.search-wrap{position:relative;display:flex;align-items:center}
.search-wrap svg{position:absolute;left:10px;pointer-events:none}
.search-input{padding:7px 10px 7px 30px;border:1px solid var(--border);border-radius:8px;font-size:12px;outline:none;width:180px;font-family:inherit;background:var(--bg-card-alt);transition:border-color .13s,background .13s}
.search-input:focus{border-color:var(--accent);background:var(--bg-card)}
.filter-group{display:flex;gap:4px}
.filter-btn{padding:6px 12px;border:1px solid var(--border);border-radius:6px;background:var(--bg-card);color:#4a5568;font-size:11.5px;font-weight:500;cursor:pointer;transition:all .13s;font-family:inherit}
.filter-btn:hover{border-color:var(--accent);color:var(--accent)}
.filter-btn.active{background:var(--accent);border-color:var(--accent);color:#fff}
.date-range{display:flex;align-items:center;gap:6px}
.date-input{padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:11.5px;font-family:inherit;outline:none;background:var(--bg-card-alt);width:130px;color:var(--text)}
.date-input:focus{border-color:var(--accent);background:var(--bg-card)}
.date-range .sep{font-size:11px;color:var(--text-muted)}
.btn-export{background:var(--bg-card);border:1px solid var(--border);border-radius:6px;padding:6px 12px;font-size:11.5px;font-weight:500;color:#4a5568;cursor:pointer;transition:all .13s;font-family:inherit;display:flex;align-items:center;gap:5px;text-decoration:none}
.btn-export:hover{border-color:var(--accent);color:var(--accent)}
.filter-active{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--accent);background:var(--accent-bg);padding:4px 10px;border-radius:12px;font-weight:500}
.filter-active a{color:var(--accent);text-decoration:none;margin-left:2px;font-weight:700}
.filter-active a:hover{text-decoration:underline}
.receipt-link{color:var(--accent);font-weight:500;text-decoration:none}
.receipt-link:hover{text-decoration:underline}
.amount-text{font-size:12.5px;color:var(--text);font-weight:500}
.date-text{font-size:12px;color:#4a5568;white-space:nowrap}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600}
.status-pill.paid{background:var(--success-bg);color:var(--success)}
.status-pill.processing{background:#fef3c7;color:#d97706}
.status-pill.failed{background:var(--error-bg);color:var(--error)}
.status-dot{width:5px;height:5px;border-radius:50%;display:inline-block}
.status-pill.paid .status-dot{background:var(--success)}
.status-pill.processing .status-dot{background:#d97706}
.status-pill.failed .status-dot{background:var(--error)}

.month-header td{background:#f8fafc;padding:8px 16px;border-bottom:1px solid var(--border)}
.month-header:hover{background:#f8fafc}
.month-label{font-size:13px;font-weight:700;color:var(--text)}
.month-total{font-size:11.5px;color:var(--text-muted);margin-left:12px}
.empty-state{text-align:center;padding:48px 16px;color:var(--text-muted);font-size:13px}
@media(max-width:900px){.stats-row{flex-direction:column}}
@media(max-width:600px){.table-toolbar{flex-direction:column;align-items:flex-start}.toolbar-right{width:100%;justify-content:flex-start}}
</style>
</head>
<body class="dashboard-body">
<div class="app">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">

<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Payments</h2>
<p>Track and manage all payment records</p>
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

<div class="page-area">

<?php if ($flash_msg): ?>
<div class="flash-toast <?= $flash_type ?>" id="flashToast"><?= htmlspecialchars($flash_msg) ?></div>
<?php endif; ?>

<div class="stats-row">
<div class="stat-card">
<div class="stat-label">Total Received</div>
<div class="stat-value">₦<?= number_format($total_received) ?></div>
<div class="stat-delta <?= $pay_delta_pct > 0 ? 'up' : ($pay_delta_pct < 0 ? 'down' : 'neutral') ?>">
<?php if ($pay_delta_pct > 0): ?>
<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="18 15 12 9 6 15"/></svg>
<?php elseif ($pay_delta_pct < 0): ?>
<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
<?php else: ?>
<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
<?php endif; ?>
<?= $pay_delta_pct ?>% vs last month
</div>
</div>
<div class="stat-card">
<div class="stat-label">Payments Count</div>
<div class="stat-value"><?= $payments_count ?></div>
</div>
<div class="stat-card">
<div class="stat-label">Pending</div>
<div class="stat-value"><?= $total_pending ?></div>
</div>
</div>

<div class="content-row">
<div class="table-card">
<div class="table-toolbar">
<div class="toolbar-left">
<span class="toolbar-title">All Payments</span>
</div>
<div class="toolbar-right">
<button class="btn-create" onclick="openModal('paymentModal', null)">
<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
Record Payment
<span class="divider-v"></span>
<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
</button>
<span class="total-badge">Total <strong><?= count($payments) ?></strong></span>
<div class="date-range">
<input class="date-input" type="date" id="fromDate" value="<?= htmlspecialchars($from_date) ?>" onchange="applyDateFilter()">
<span class="sep">–</span>
<input class="date-input" type="date" id="toDate" value="<?= htmlspecialchars($to_date) ?>" onchange="applyDateFilter()">
<?php if ($has_date_filter): ?>
<span class="filter-active">Filtered <a href="payments.php?<?= $base_qs ?>">&times;</a></span>
<?php endif; ?>
</div>
<a class="btn-export" href="?print=1<?= $base_qs !== '' ? '&' . $base_qs : '' ?>">
<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
Export PDF
</a>
<a class="btn-export" href="?export=csv<?= $base_qs !== '' ? '&' . $base_qs : '' ?>">
<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
Export
</a>
<div class="search-wrap">
<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#aab4c0" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
<input class="search-input" type="text" id="paymentSearch" placeholder="Search customer or receipt..." oninput="filterPayments()"/>
</div>
<div class="filter-group">
<button class="filter-btn active" onclick="setStatus('all',this)">All</button>
<button class="filter-btn" onclick="setStatus('paid',this)">Paid</button>
<button class="filter-btn" onclick="setStatus('processing',this)">Processing</button>
<button class="filter-btn" onclick="setStatus('failed',this)">Failed</button>
</div>
</div>
</div>

<div class="table-responsive">
<table>
<thead>
<tr>
<th>Receipt Number</th>
<th>Customer</th>
<th>Invoice #</th>
<th>Amount (₦)</th>
<th>Balance (₦)</th>
<th>Date</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php if (count($payments) > 0): ?>
<?php foreach ($visible_months as $monthKey => $month): ?>
<tr class="month-header">
<td colspan="7">
<span class="month-label"><?= $month['label'] ?></span>
<span class="month-total"><?= $month['total_count'] ?> payment<?= $month['total_count'] !== 1 ? 's' : '' ?> · ₦<?= number_format($month['total_amount']) ?></span>
</td>
</tr>
<?php foreach ($month['payments'] as $p):
$displayDate = $p['payment_date'];
if (!$displayDate || $displayDate === '0000-00-00') {
    $displayDate = $p['created_at'];
}
?>
<tr data-search="<?= htmlspecialchars(strtolower($p['receipt_number'] . ' ' . $p['customer_name'] . ' ' . $p['invoice_number'])) ?>" data-status="<?= $p['status'] ?>">
<td><a href="view-receipt.php?id=<?= $p['id'] ?>" class="receipt-link"><?= htmlspecialchars($p['receipt_number']) ?></a></td>
<td><?= htmlspecialchars($p['customer_name']) ?></td>
<td><?= htmlspecialchars($p['invoice_number']) ?></td>
<td class="amount-text">₦<?= number_format($p['amount'], 2) ?></td>
<td class="amount-text" style="<?= floatval($p['balance']) > 0 ? 'color:var(--accent)' : 'color:#cbd5e1' ?>">₦<?= number_format(floatval($p['balance']), 2) ?></td>
<td class="date-text"><?= date('M d, Y', strtotime($displayDate)) ?></td>
<td><span class="status-pill <?= $p['status'] ?>"><span class="status-dot"></span><?= ucfirst($p['status']) ?></span></td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" class="empty-state">No payments recorded yet. Click "Record Payment" to add one.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($total_pages > 1): ?>
<div class="pagination-bar">
<div class="page-info">Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong> · Showing <strong><?= count($visible_months) ?></strong> month<?= count($visible_months) !== 1 ? 's' : '' ?></div>
<div class="pagination-links">
<?php if ($page > 1): ?>
<a href="?page=<?= $page - 1 ?><?= $base_qs !== '' ? '&' . $base_qs : '' ?>">&laquo; Prev</a>
<?php endif; ?>
<?php
$start_page = max(1, $page - 2);
$end_page = min($total_pages, $page + 2);
if ($start_page > 1) echo '<span class="dots">...</span>';
for ($i = $start_page; $i <= $end_page; $i++):
?>
<?php if ($i === $page): ?>
<span class="current"><?= $i ?></span>
<?php else: ?>
<a href="?page=<?= $i ?><?= $base_qs !== '' ? '&' . $base_qs : '' ?>"><?= $i ?></a>
<?php endif; ?>
<?php endfor; ?>
<?php if ($end_page < $total_pages) echo '<span class="dots">...</span>'; ?>
<?php if ($page < $total_pages): ?>
<a href="?page=<?= $page + 1 ?><?= $base_qs !== '' ? '&' . $base_qs : '' ?>">Next &raquo;</a>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
</div>
</div>

</div>

<div class="modal-overlay" id="paymentModal">
<form class="modal-box" method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" value="record_payment">
<h2 id="paymentModalTitle">Record Payment</h2>
<p class="desc" id="paymentModalDesc">Log a payment received from a customer.</p>
<div class="form-row"><label>Receipt Number *</label><input type="text" name="receipt_number" id="paymentFormReceipt" value="<?= htmlspecialchars($next_receipt) ?>" required></div>
<div class="form-row"><label>Customer Name *</label><input type="text" name="customer_name" id="paymentFormCustomer" required></div>
<div class="form-row"><label>Invoice Number</label><input type="text" name="invoice_number" id="paymentFormInvoice"></div>
<div class="form-row"><label>Amount (₦) *</label><input type="number" name="amount" id="paymentFormAmount" step="0.01" value="0" required></div>
<div class="form-row"><label>Balance Due (₦)</label><input type="number" name="balance" id="paymentFormBalance" step="0.01" min="0" value="0"></div>
<div class="form-row"><label>Payment Date</label><input type="date" name="payment_date" id="paymentFormDate" value="<?= date('Y-m-d') ?>"></div>
<div class="form-row"><label>Status</label><select name="status" id="paymentFormStatus"><option value="paid">Paid</option><option value="processing">Processing</option><option value="failed">Failed</option></select></div>
<div class="modal-actions"><button type="button" class="btn-cancel" onclick="closeModal('paymentModal')">Cancel</button><button type="submit" class="btn-save" id="paymentFormSubmit">Record Payment</button></div>
</form>
</div>

<script src="assets/js/app.js"></script>
<script>
function openModal(id) {
    if (id === 'paymentModal') {
        document.getElementById('paymentFormReceipt').value = '<?= htmlspecialchars($next_receipt) ?>';
        document.getElementById('paymentFormCustomer').value = '';
        document.getElementById('paymentFormInvoice').value = '';
        document.getElementById('paymentFormAmount').value = '0';
        document.getElementById('paymentFormBalance').value = '0';
        document.getElementById('paymentFormDate').value = '<?= date('Y-m-d') ?>';
        document.getElementById('paymentFormStatus').value = 'paid';
    }
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

function filterPayments() {
    var q = document.getElementById('paymentSearch').value.toLowerCase();
    var status = document.getElementById('statusFilter') ? document.getElementById('statusFilter').value : 'all';
    var rows = document.querySelectorAll('tbody tr');
    var currentMonthRow = null;
    var monthHasVisible = false;
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        if (row.classList.contains('month-header')) {
            if (currentMonthRow && !monthHasVisible) {
                currentMonthRow.style.display = 'none';
            }
            currentMonthRow = row;
            monthHasVisible = false;
            continue;
        }
        var searchText = row.getAttribute('data-search') || '';
        var rowStatus = row.getAttribute('data-status') || '';
        var matchSearch = q === '' || searchText.indexOf(q) !== -1;
        var matchStatus = status === 'all' || rowStatus === status;
        var visible = matchSearch && matchStatus;
        row.style.display = visible ? '' : 'none';
        if (visible) monthHasVisible = true;
    }
    if (currentMonthRow && !monthHasVisible) {
        currentMonthRow.style.display = 'none';
    }
}

function setStatus(status, btn) {
    var parent = btn.parentNode;
    var btns = parent.querySelectorAll('.filter-btn');
    for (var i = 0; i < btns.length; i++) {
        btns[i].classList.remove('active');
    }
    btn.classList.add('active');
    var el = document.getElementById('statusFilter');
    if (!el) {
        el = document.createElement('input');
        el.type = 'hidden';
        el.id = 'statusFilter';
        document.body.appendChild(el);
    }
    el.value = status;
    filterPayments();
}

function applyDateFilter() {
    var from = document.getElementById('fromDate').value;
    var to = document.getElementById('toDate').value;
    var params = [];
    if (from) params.push('from=' + from);
    if (to) params.push('to=' + to);
    window.location.href = 'payments.php' + (params.length ? '?' + params.join('&') : '');
}

var flashEl = document.getElementById('flashToast');
if (flashEl) {
    setTimeout(function() { flashEl.classList.add('hide'); }, 3500);
    setTimeout(function() { if (flashEl.parentNode) flashEl.parentNode.removeChild(flashEl); }, 4000);
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</div>
</body>
</html>
