<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'revenue';

if (!adminCan('revenue')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$where = "WHERE p.status = 'paid'";
$params = [];
if ($from) { $where .= " AND p.payment_date >= ?"; $params[] = $from; }
if ($to) { $where .= " AND p.payment_date <= ?"; $params[] = $to; }

$totalRevenue = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments p $where");
$totalRevenue->execute($params);
$totalRevenue = $totalRevenue->fetchColumn();

$monthly = $pdo->prepare("SELECT DATE_FORMAT(p.payment_date, '%Y-%m') as month, COUNT(*) as invoice_count, COALESCE(SUM(p.amount), 0) as total FROM payments p $where GROUP BY month ORDER BY month DESC LIMIT 24");
$monthly->execute($params);
$monthly = $monthly->fetchAll();

if (isset($_GET['export'])) {
    require_once __DIR__ . '/../includes/csv_export.php';
    csv_send('bizvaza-revenue');

    // Full dataset for the export (the on-page table shows only the latest 24 months).
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(p.payment_date, '%M %Y') AS month_label, COUNT(*) AS receipt_count, COALESCE(SUM(p.amount), 0) AS total FROM payments p $where GROUP BY month_label, DATE_FORMAT(p.payment_date, '%Y-%m') ORDER BY MIN(p.payment_date) ASC");
    $stmt->execute($params);
    $export_monthly = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0), COUNT(DISTINCT DATE_FORMAT(payment_date, '%Y-%m')) FROM payments p $where");
    $stmt->execute($params);
    [$rcpt_count, $sum_total, $months_covered] = $stmt->fetch(PDO::FETCH_NUM);
    $avg_month = $months_covered > 0 ? round(floatval($sum_total) / intval($months_covered), 2) : 0;

    $stmt = $pdo->prepare("SELECT p.receipt_number, u.business_name, p.customer_name, p.invoice_number, p.payment_date, p.amount FROM payments p JOIN users u ON u.id = p.user_id $where ORDER BY p.payment_date ASC, p.id ASC");
    $stmt->execute($params);
    $export_receipts = $stmt->fetchAll();

    $out = fopen('php://output', 'w');
    csv_title_block($out, 'BizVaza — Admin Revenue Report', $from, $to);

    csv_section($out, 'Summary');
    fputcsv($out, ['Total Revenue (Paid Receipts)', '₦' . number_format(floatval($sum_total), 2)]);
    fputcsv($out, ['Receipts Paid', intval($rcpt_count)]);
    fputcsv($out, ['Months Covered', intval($months_covered)]);
    fputcsv($out, ['Average per Month', '₦' . number_format($avg_month, 2)]);

    csv_section($out, 'Monthly Breakdown');
    fputcsv($out, ['Month', 'Receipts Paid', 'Amount (₦)']);
    foreach ($export_monthly as $m) {
        fputcsv($out, [$m['month_label'], $m['receipt_count'], number_format(floatval($m['total']), 2)]);
    }
    fputcsv($out, ['TOTAL', intval($rcpt_count), number_format(floatval($sum_total), 2)]);

    csv_section($out, 'Receipt Detail');
    fputcsv($out, ['Receipt #', 'Business', 'Customer', 'Invoice #', 'Date', 'Amount (₦)']);
    foreach ($export_receipts as $r) {
        fputcsv($out, csv_row([
            $r['receipt_number'],
            $r['business_name'],
            $r['customer_name'],
            $r['invoice_number'],
            $r['payment_date'] ? date('Y-m-d', strtotime($r['payment_date'])) : '',
            number_format(floatval($r['amount']), 2)
        ]));
    }
    fputcsv($out, ['TOTAL', '', '', '', '', number_format(floatval($sum_total), 2)]);

    fclose($out);
    exit;
}

// Print / PDF view
if (isset($_GET['print'])) {
    require_once __DIR__ . '/../includes/csv_export.php';

    $stmt = $pdo->prepare("SELECT DATE_FORMAT(p.payment_date, '%M %Y') AS month_label, COUNT(*) AS receipt_count, COALESCE(SUM(p.amount), 0) AS total FROM payments p $where GROUP BY month_label, DATE_FORMAT(p.payment_date, '%Y-%m') ORDER BY MIN(p.payment_date) ASC");
    $stmt->execute($params);
    $print_monthly = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0), COUNT(DISTINCT DATE_FORMAT(payment_date, '%Y-%m')) FROM payments p $where");
    $stmt->execute($params);
    [$p_cnt, $p_total, $p_months] = $stmt->fetch(PDO::FETCH_NUM);
    $p_avg = $p_months > 0 ? round(floatval($p_total) / intval($p_months), 2) : 0;

    $stmt = $pdo->prepare("SELECT p.receipt_number, u.business_name, p.customer_name, p.invoice_number, p.payment_date, p.amount FROM payments p JOIN users u ON u.id = p.user_id $where ORDER BY p.payment_date ASC, p.id ASC");
    $stmt->execute($params);
    $print_receipts = $stmt->fetchAll();

    $css_v = @filemtime(__DIR__ . '/../assets/css/report-print.css') ?: '';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Revenue Report</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/report-print.css?v=<?= $css_v ?>">
</head>
<body>
<div class="rp-sheet">
    <div class="rp-brandbar">
        <div class="rp-brand"><div class="rp-logo">B</div><div class="rp-company">BizVaza</div></div>
        <div class="rp-doctype"><h1>REVENUE REPORT</h1></div>
    </div>
    <div class="rp-meta"><span>Generated: <?= date('M j, Y g:i A') ?></span><span>Period: <?= htmlspecialchars(report_period_text($from, $to)) ?></span></div>

    <div class="rp-section"><h2>Summary</h2></div>
    <div class="rp-stats">
        <div class="rp-stat"><div class="lbl">Total Revenue</div><div class="val">₦<?= number_format(floatval($p_total), 2) ?></div></div>
        <div class="rp-stat"><div class="lbl">Receipts Paid</div><div class="val"><?= intval($p_cnt) ?></div></div>
        <div class="rp-stat"><div class="lbl">Months Covered</div><div class="val"><?= intval($p_months) ?></div></div>
        <div class="rp-stat"><div class="lbl">Avg / Month</div><div class="val">₦<?= number_format($p_avg, 2) ?></div></div>
    </div>

    <div class="rp-section"><h2>Monthly Breakdown</h2>
        <table class="rp-table">
            <thead><tr><th>Month</th><th class="rp-num">Receipts Paid</th><th class="rp-num">Amount (₦)</th></tr></thead>
            <tbody>
            <?php foreach ($print_monthly as $m): ?>
            <tr><td><?= htmlspecialchars($m['month_label']) ?></td><td class="rp-num"><?= intval($m['receipt_count']) ?></td><td class="rp-num"><?= number_format(floatval($m['total']), 2) ?></td></tr>
            <?php endforeach; ?>
            <tr class="rp-total"><td>TOTAL</td><td class="rp-num"><?= intval($p_cnt) ?></td><td class="rp-num"><?= number_format(floatval($p_total), 2) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="rp-section"><h2>Receipt Detail</h2>
        <table class="rp-table">
            <thead><tr><th>#</th><th>Receipt #</th><th>Business</th><th>Customer</th><th>Invoice #</th><th>Date</th><th class="rp-num">Amount (₦)</th></tr></thead>
            <tbody>
            <?php $i = 1; foreach ($print_receipts as $r): ?>
            <tr><td><?= $i++ ?></td><td><?= htmlspecialchars($r['receipt_number']) ?></td><td><?= htmlspecialchars($r['business_name']) ?></td><td><?= htmlspecialchars($r['customer_name']) ?></td><td><?= htmlspecialchars($r['invoice_number']) ?></td><td><?= $r['payment_date'] ? date('M j, Y', strtotime($r['payment_date'])) : '--' ?></td><td class="rp-num"><?= number_format(floatval($r['amount']), 2) ?></td></tr>
            <?php endforeach; ?>
            <tr class="rp-total"><td colspan="6">TOTAL</td><td class="rp-num"><?= number_format(floatval($p_total), 2) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="rp-foot"><span>BizVaza · Admin Revenue Report</span><span>Generated <?= date('M j, Y') ?></span></div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
</body>
</html><?php
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Revenue</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);display:flex;min-height:100vh;font-size:13px;color:var(--text)}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--topbar-bg);border-bottom:1px solid var(--border);padding:16px 28px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.topbar h2{font-size:18px;font-weight:600;color:var(--text)}
.content{padding:28px 24px 0;flex:1;max-width:800px}
.stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:20px}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 16px;display:flex;align-items:center;gap:14px}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.si-blue{background:var(--accent-bg)}
.si-green{background:var(--success-bg)}
.stat-label{font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;letter-spacing:.3px}
.stat-val{font-size:22px;font-weight:700;color:var(--text);line-height:1.1}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 16px;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.card-head h3{font-size:14px;font-weight:600;color:var(--text)}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;color:var(--text-muted);font-weight:500;text-align:left;padding:8px 10px;background:var(--table-header);border-bottom:1px solid var(--table-border);white-space:nowrap}
tbody td{padding:10px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--table-border)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--bg-hover)}
.filter-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.filter-row label{font-size:11px;font-weight:500;color:var(--text-light);display:block;margin-bottom:2px}
.filter-row input{padding:7px 10px;border:1px solid var(--border-input);border-radius:6px;font-size:12px;font-family:'Inter',sans-serif;outline:none;background:var(--bg-card)}
.filter-row input:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;padding:7px 14px;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border-input);background:var(--bg-card);color:var(--text);text-decoration:none;font-family:'Inter',sans-serif}
.btn:hover{background:var(--btn-hover)}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-hover)}
@media(max-width:700px){.stat-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar"><div><button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><h2>Revenue</h2></div></div>
<div class="content">

<div class="stat-grid">
<div class="stat-card">
<div class="stat-icon si-blue">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
</div>
<div>
<span class="stat-label">Total Revenue (Paid Receipts)</span>
<div class="stat-val">₦<?= number_format($totalRevenue, 2) ?></div>
</div>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Filter &amp; Export</h3></div>
<form class="filter-row">
<label>From <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></label>
<label>To <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></label>
<button class="btn btn-primary" type="submit">Filter</button>
<?php if ($from || $to): ?><a href="revenue.php" class="btn">Clear</a><?php endif; ?>
<a href="?print=1&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn">Export PDF</a>
<a href="?export=1&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn">CSV Export</a>
</form>
</div>

<div class="card">
<div class="card-head"><h3>Monthly Breakdown</h3></div>
<div class="table-responsive">
<table>
<thead><tr><th>Month</th><th>Receipts Paid</th><th>Total Amount</th></tr></thead>
<tbody>
<?php if (count($monthly) > 0): ?>
<?php foreach ($monthly as $m): ?>
<tr>
<td><?= htmlspecialchars($m['month']) ?></td>
<td><?= $m['invoice_count'] ?></td>
<td>₦<?= number_format($m['total'], 2) ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="3" style="text-align:center;padding:24px;color:var(--text-muted)">No data</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>