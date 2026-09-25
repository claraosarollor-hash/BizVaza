<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$business_abbr = $_SESSION['business_abbr'] ?? 'BIZ';
$avatar_initials = $_SESSION['avatar_initials'];

// Date range filter
$from_date = $_GET['from'] ?? '';
$to_date = $_GET['to'] ?? '';

$date_where_inv = '';
$date_where_pay = '';
$date_params_inv = [];
$date_params_pay = [];
if ($from_date !== '') {
    $date_where_inv .= " AND i.created_at >= ?";
    $date_params_inv[] = $from_date;
    $date_where_pay .= " AND p.created_at >= ?";
    $date_params_pay[] = $from_date;
}
if ($to_date !== '') {
    $date_where_inv .= " AND i.created_at <= ?";
    $date_params_inv[] = $to_date;
    $date_where_pay .= " AND p.created_at <= ?";
    $date_params_pay[] = $to_date;
}

$has_date_filter = ($from_date !== '' || $to_date !== '');

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/includes/csv_export.php';
    csv_send('bizvaza-monthly-report');

    $sql_inv = "SELECT DATE_FORMAT(i.created_at, '%Y-%m') as month_key, DATE_FORMAT(i.created_at, '%M %Y') as month_label, COALESCE(SUM(i.amount),0) as invoiced FROM invoices i WHERE i.user_id = ?{$date_where_inv} GROUP BY month_key, month_label ORDER BY month_key DESC";
    $stmt = $pdo->prepare($sql_inv);
    $stmt->execute(array_merge([$user_id], $date_params_inv));
    $inv_rows = [];
    while ($r = $stmt->fetch()) { $inv_rows[$r['month_key']] = $r; }

    $sql_pay = "SELECT DATE_FORMAT(p.created_at, '%Y-%m') as month_key, COALESCE(SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END),0) as collected FROM payments p WHERE p.user_id = ?{$date_where_pay} GROUP BY month_key ORDER BY month_key DESC";
    $stmt = $pdo->prepare($sql_pay);
    $stmt->execute(array_merge([$user_id], $date_params_pay));
    $pay_rows = [];
    while ($r = $stmt->fetch()) { $pay_rows[$r['month_key']] = $r; }

    $all_keys = array_unique(array_merge(array_keys($inv_rows), array_keys($pay_rows)));
    rsort($all_keys);

    // Individual paid receipts for the detail section.
    $sql_detail = "SELECT receipt_number, customer_name, invoice_number, payment_date, amount FROM payments WHERE user_id = ? AND status = 'paid'{$date_where_pay} ORDER BY payment_date ASC, id ASC";
    $stmt = $pdo->prepare($sql_detail);
    $stmt->execute(array_merge([$user_id], $date_params_pay));
    $detail_rows = $stmt->fetchAll();

    $out = fopen('php://output', 'w');
    csv_title_block($out, 'BizVaza — Monthly Revenue Report', $from_date, $to_date);

    csv_section($out, 'Monthly Overview');
    fputcsv($out, ['Month', 'Invoiced (₦)', 'Collected (₦)', 'Outstanding (₦)', 'Collection Rate (%)']);
    $tot_inv = 0; $tot_col = 0;
    foreach ($all_keys as $mk) {
        $inv = $inv_rows[$mk]['invoiced'] ?? 0;
        $col = $pay_rows[$mk]['collected'] ?? 0;
        $tot_inv += floatval($inv);
        $tot_col += floatval($col);
        $outst = $inv - $col;
        $rate = $inv > 0 ? min(100, round(($col / $inv) * 100, 1)) : 0;
        fputcsv($out, csv_row([$inv_rows[$mk]['month_label'] ?? $mk, number_format($inv, 2), number_format($col, 2), number_format(max(0, $outst), 2), $rate . '%']));
    }
    $tot_rate = $tot_inv > 0 ? min(100, round(($tot_col / $tot_inv) * 100, 1)) : 0;
    fputcsv($out, ['TOTAL', number_format($tot_inv, 2), number_format($tot_col, 2), number_format(max(0, $tot_inv - $tot_col), 2), $tot_rate . '%']);

    csv_section($out, 'Receipt Detail');
    fputcsv($out, ['Receipt #', 'Customer', 'Invoice #', 'Date', 'Amount (₦)']);
    foreach ($detail_rows as $r) {
        fputcsv($out, csv_row([
            $r['receipt_number'],
            $r['customer_name'],
            $r['invoice_number'],
            $r['payment_date'] && $r['payment_date'] !== '0000-00-00' ? date('Y-m-d', strtotime($r['payment_date'])) : '',
            number_format(floatval($r['amount']), 2)
        ]));
    }
    fputcsv($out, ['TOTAL', '', '', '', number_format(array_sum(array_map(function ($r) { return floatval($r['amount']); }, $detail_rows)), 2)]);

    fclose($out);
    exit;
}

// Print / PDF view
if (isset($_GET['print'])) {
    require_once __DIR__ . '/includes/csv_export.php';

    $sql_inv = "SELECT DATE_FORMAT(i.created_at, '%Y-%m') as month_key, DATE_FORMAT(i.created_at, '%M %Y') as month_label, COALESCE(SUM(i.amount),0) as invoiced FROM invoices i WHERE i.user_id = ?{$date_where_inv} GROUP BY month_key, month_label ORDER BY month_key DESC";
    $stmt = $pdo->prepare($sql_inv);
    $stmt->execute(array_merge([$user_id], $date_params_inv));
    $inv_rows = [];
    while ($r = $stmt->fetch()) { $inv_rows[$r['month_key']] = $r; }

    $sql_pay = "SELECT DATE_FORMAT(p.created_at, '%Y-%m') as month_key, COALESCE(SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END),0) as collected FROM payments p WHERE p.user_id = ?{$date_where_pay} GROUP BY month_key ORDER BY month_key DESC";
    $stmt = $pdo->prepare($sql_pay);
    $stmt->execute(array_merge([$user_id], $date_params_pay));
    $pay_rows = [];
    while ($r = $stmt->fetch()) { $pay_rows[$r['month_key']] = $r; }

    $all_keys = array_unique(array_merge(array_keys($inv_rows), array_keys($pay_rows)));
    ksort($all_keys);

    $sql_detail = "SELECT receipt_number, customer_name, invoice_number, payment_date, amount FROM payments WHERE user_id = ? AND status = 'paid'{$date_where_pay} ORDER BY payment_date ASC, id ASC";
    $stmt = $pdo->prepare($sql_detail);
    $stmt->execute(array_merge([$user_id], $date_params_pay));
    $detail_rows = $stmt->fetchAll();

    $css_v = @filemtime(__DIR__ . '/assets/css/report-print.css') ?: '';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Monthly Revenue Report</title>
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
        <div class="rp-doctype"><h1>MONTHLY REVENUE REPORT</h1></div>
    </div>
    <div class="rp-meta"><span>Generated: <?= date('M j, Y g:i A') ?></span><span>Period: <?= htmlspecialchars(report_period_text($from_date, $to_date)) ?></span></div>

    <div class="rp-section"><h2>Monthly Overview</h2>
        <table class="rp-table">
            <thead><tr><th>Month</th><th class="rp-num">Invoiced (₦)</th><th class="rp-num">Collected (₦)</th><th class="rp-num">Outstanding (₦)</th><th class="rp-num">Collection Rate</th></tr></thead>
            <tbody>
            <?php $tot_inv = 0; $tot_col = 0; ?>
            <?php foreach ($all_keys as $mk): $inv = floatval($inv_rows[$mk]['invoiced'] ?? 0); $col = floatval($pay_rows[$mk]['collected'] ?? 0); $tot_inv += $inv; $tot_col += $col; $outst = $inv - $col; $rate = $inv > 0 ? min(100, round(($col / $inv) * 100, 1)) : 0; ?>
            <tr><td><?= htmlspecialchars($inv_rows[$mk]['month_label'] ?? $mk) ?></td><td class="rp-num"><?= number_format($inv, 2) ?></td><td class="rp-num"><?= number_format($col, 2) ?></td><td class="rp-num"><?= number_format(max(0, $outst), 2) ?></td><td class="rp-num"><?= $rate ?>%</td></tr>
            <?php endforeach; ?>
            <?php if (count($all_keys) === 0): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted)">No data for this period</td></tr>
            <?php endif; ?>
            <?php if (count($all_keys) > 0): $tot_rate = $tot_inv > 0 ? min(100, round(($tot_col / $tot_inv) * 100, 1)) : 0; ?>
            <tr class="rp-total"><td>TOTAL</td><td class="rp-num"><?= number_format($tot_inv, 2) ?></td><td class="rp-num"><?= number_format($tot_col, 2) ?></td><td class="rp-num"><?= number_format(max(0, $tot_inv - $tot_col), 2) ?></td><td class="rp-num"><?= $tot_rate ?>%</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="rp-section"><h2>Receipt Detail</h2>
        <table class="rp-table">
            <thead><tr><th>#</th><th>Receipt #</th><th>Customer</th><th>Invoice #</th><th>Date</th><th class="rp-num">Amount (₦)</th></tr></thead>
            <tbody>
            <?php $i = 1; $det_total = 0; foreach ($detail_rows as $r): $det_total += floatval($r['amount']); ?>
            <tr><td><?= $i++ ?></td><td><?= htmlspecialchars($r['receipt_number']) ?></td><td><?= htmlspecialchars($r['customer_name']) ?></td><td><?= htmlspecialchars($r['invoice_number']) ?></td><td><?= $r['payment_date'] && $r['payment_date'] !== '0000-00-00' ? date('M j, Y', strtotime($r['payment_date'])) : '--' ?></td><td class="rp-num"><?= number_format(floatval($r['amount']), 2) ?></td></tr>
            <?php endforeach; ?>
            <?php if (count($detail_rows) === 0): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--text-muted)">No paid receipts for this period</td></tr>
            <?php endif; ?>
            <?php if (count($detail_rows) > 0): ?>
            <tr class="rp-total"><td colspan="5">TOTAL</td><td class="rp-num"><?= number_format($det_total, 2) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="rp-foot"><span>BizVaza · Monthly Revenue Report</span><span>Generated <?= date('M j, Y') ?></span></div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
</body>
</html><?php
    exit;
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 6;

// Stats (date-filtered)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(i.amount),0) FROM invoices i WHERE i.user_id = ?{$date_where_inv}");
$stmt->execute(array_merge([$user_id], $date_params_inv));
$total_revenue = floatval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.user_id = ? AND p.status = 'paid'{$date_where_pay}");
$stmt->execute(array_merge([$user_id], $date_params_pay));
$total_collected = floatval($stmt->fetchColumn());

$total_outstanding = max(0, $total_revenue - $total_collected);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i WHERE i.user_id = ?{$date_where_inv}");
$stmt->execute(array_merge([$user_id], $date_params_inv));
$invoice_count = intval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i WHERE i.user_id = ?{$date_where_inv} AND COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0) >= i.amount");
$stmt->execute(array_merge([$user_id], $date_params_inv));
$paid_invoices = intval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i WHERE i.user_id = ?{$date_where_inv} AND COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0) > 0 AND COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0) < i.amount");
$stmt->execute(array_merge([$user_id], $date_params_inv));
$partial_invoices = intval($stmt->fetchColumn());
$pending_invoices = $invoice_count - $paid_invoices - $partial_invoices;

// Monthly data (merge invoices + payments by month)
$sql_inv = "SELECT DATE_FORMAT(i.created_at, '%Y-%m') as month_key, DATE_FORMAT(i.created_at, '%M %Y') as month_label, COALESCE(SUM(i.amount),0) as invoiced, COUNT(*) as invoice_count FROM invoices i WHERE i.user_id = ?{$date_where_inv} GROUP BY month_key, month_label ORDER BY month_key DESC";
$stmt = $pdo->prepare($sql_inv);
$stmt->execute(array_merge([$user_id], $date_params_inv));
$inv_months = [];
while ($r = $stmt->fetch()) { $inv_months[$r['month_key']] = $r; }

$sql_pay = "SELECT DATE_FORMAT(p.created_at, '%Y-%m') as month_key, COALESCE(SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END),0) as collected, COUNT(CASE WHEN p.status='paid' THEN 1 END) as paid_count FROM payments p WHERE p.user_id = ?{$date_where_pay} GROUP BY month_key ORDER BY month_key DESC";
$stmt = $pdo->prepare($sql_pay);
$stmt->execute(array_merge([$user_id], $date_params_pay));
$pay_months = [];
while ($r = $stmt->fetch()) { $pay_months[$r['month_key']] = $r; }

$all_keys = array_unique(array_merge(array_keys($inv_months), array_keys($pay_months)));
rsort($all_keys);

$months = [];
foreach ($all_keys as $mk) {
    $inv = $inv_months[$mk] ?? ['month_label' => date('F Y', strtotime($mk . '-01')), 'invoiced' => 0, 'invoice_count' => 0];
    $col = $pay_months[$mk]['collected'] ?? 0;
    $pc = $pay_months[$mk]['paid_count'] ?? 0;
    $invoiced = floatval($inv['invoiced']);
    $outst = max(0, $invoiced - $col);
    $rate = $invoiced > 0 ? min(100, round(($col / $invoiced) * 100, 1)) : 0;
    $months[$mk] = [
        'label' => $inv['month_label'],
        'invoiced' => $invoiced,
        'collected' => floatval($col),
        'outstanding' => $outst,
        'rate' => $rate,
        'invoice_count' => intval($inv['invoice_count']),
        'paid_count' => intval($pc)
    ];
}

$total_months = count($months);
$total_pages = max(1, ceil($total_months / $per_page));
$page = min($page, $total_pages);
$page_offset = ($page - 1) * $per_page;
$visible_months = array_slice($months, $page_offset, $per_page, true);

// MoM delta for revenue
$stmt = $pdo->prepare("SELECT COALESCE(SUM(i.amount),0) FROM invoices i WHERE i.user_id = ? AND MONTH(i.created_at) = MONTH(CURRENT_DATE) AND YEAR(i.created_at) = YEAR(CURRENT_DATE)");
$stmt->execute([$user_id]);
$cur_month_rev = floatval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COALESCE(SUM(i.amount),0) FROM invoices i WHERE i.user_id = ? AND MONTH(i.created_at) = MONTH(CURRENT_DATE - INTERVAL 1 MONTH) AND YEAR(i.created_at) = YEAR(CURRENT_DATE - INTERVAL 1 MONTH)");
$stmt->execute([$user_id]);
$prev_month_rev = floatval($stmt->fetchColumn());

$rev_delta_pct = 0;
if ($prev_month_rev > 0) {
    $rev_delta = $cur_month_rev - $prev_month_rev;
    $rev_delta_pct = round(($rev_delta / $prev_month_rev) * 100, 1);
} elseif ($cur_month_rev > 0) {
    $rev_delta_pct = 100;
}

// MoM delta for collected
$stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.user_id = ? AND p.status = 'paid' AND MONTH(p.created_at) = MONTH(CURRENT_DATE) AND YEAR(p.created_at) = YEAR(CURRENT_DATE)");
$stmt->execute([$user_id]);
$cur_month_col = floatval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.user_id = ? AND p.status = 'paid' AND MONTH(p.created_at) = MONTH(CURRENT_DATE - INTERVAL 1 MONTH) AND YEAR(p.created_at) = YEAR(CURRENT_DATE - INTERVAL 1 MONTH)");
$stmt->execute([$user_id]);
$prev_month_col = floatval($stmt->fetchColumn());

$col_delta_pct = 0;
if ($prev_month_col > 0) {
    $col_delta = $cur_month_col - $prev_month_col;
    $col_delta_pct = round(($col_delta / $prev_month_col) * 100, 1);
} elseif ($cur_month_col > 0) {
    $col_delta_pct = 100;
}

// SVG donut chart values
$donut_circ = 2 * M_PI * 36;
if ($total_revenue > 0) {
    $donut_collected_len = min($donut_circ, ($total_collected / $total_revenue) * $donut_circ);
    $donut_outstanding_len = $donut_circ - $donut_collected_len;
    $donut_start_offset = $donut_circ * 0.25;
    $donut_collected_offset = $donut_start_offset;
    $donut_outstanding_offset = $donut_start_offset - $donut_collected_len;
} else {
    $donut_collected_len = $donut_outstanding_len = 0;
    $donut_collected_offset = $donut_outstanding_offset = 0;
}
$donut_collected_pct = $total_revenue > 0 ? min(100, round(($total_collected / $total_revenue) * 100)) : 0;
$donut_outstanding_pct = $total_revenue > 0 ? 100 - $donut_collected_pct : 0;

// Top customers (unfiltered)
$stmt = $pdo->prepare("SELECT p.customer_name, COALESCE(SUM(p.amount),0) as total_paid, COUNT(*) as payment_count FROM payments p WHERE p.user_id = ? AND p.status = 'paid' GROUP BY p.customer_name ORDER BY total_paid DESC LIMIT 10");
$stmt->execute([$user_id]);
$top_customers = $stmt->fetchAll();

// Recent payments (unfiltered)
$stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$recent_payments = $stmt->fetchAll();

// Build query string for links
$link_params = $_GET;
unset($link_params['page'], $link_params['export']);
$base_qs = http_build_query($link_params);

$activePage = 'report';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BizVaza – Reports</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css">
<style>
.app{display:flex;min-height:100vh}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.bell-wrap{position:relative;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;border-radius:50%;transition:background .12s}
.bell-wrap:hover{background:#f1f5f9}
.page-area{flex:1;padding:24px 28px;display:flex;flex-direction:column;gap:20px}
.stats-row{display:flex;gap:16px;flex-wrap:wrap}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;flex:1;min-width:170px;display:block}
.stat-card .stat-label{font-size:11.5px;color:var(--text-muted);font-weight:500;margin-bottom:4px}
.stat-card .stat-value{font-size:20px;font-weight:700;color:var(--text)}
.stat-card .stat-sub{font-size:11.5px;color:var(--text-muted);margin-top:3px}
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
.date-range{display:flex;align-items:center;gap:6px}
.date-input{padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:11.5px;font-family:inherit;outline:none;background:var(--bg-card-alt);width:130px;color:var(--text)}
.date-input:focus{border-color:var(--accent);background:var(--bg-card)}
.date-range .sep{font-size:11px;color:var(--text-muted)}
.btn-export{background:var(--bg-card);border:1px solid var(--border);border-radius:6px;padding:6px 12px;font-size:11.5px;font-weight:500;color:#4a5568;cursor:pointer;transition:all .13s;font-family:inherit;display:flex;align-items:center;gap:5px;text-decoration:none}
.btn-export:hover{border-color:var(--accent);color:var(--accent)}
.filter-active{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--accent);background:var(--accent-bg);padding:4px 10px;border-radius:12px;font-weight:500}
.filter-active a{color:var(--accent);text-decoration:none;margin-left:2px;font-weight:700}
.filter-active a:hover{text-decoration:underline}
table{width:100%;border-collapse:collapse}
thead th{font-size:11.5px;color:var(--text-muted);font-weight:500;text-align:left;padding:10px 16px;background:var(--bg-card-alt);border-bottom:1px solid var(--border);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--bg-card-alt)}
tbody td{padding:12px 16px;font-size:12.5px;color:#2d3748;vertical-align:middle}
.month-header td{background:#f8fafc;padding:8px 16px;border-bottom:1px solid var(--border)}
.month-header:hover{background:#f8fafc}
.month-label{font-size:13px;font-weight:700;color:var(--text)}
.empty-state{text-align:center;padding:40px 16px;color:var(--text-muted);font-size:13px}
.rate-pill{display:inline-flex;align-items:center;padding:2px 10px;border-radius:20px;font-size:11.5px;font-weight:600}
.rate-pill.high{background:var(--success-bg);color:var(--success)}
.rate-pill.med{background:#fef3c7;color:#d97706}
.rate-pill.low{background:var(--error-bg);color:var(--error)}
.cust-rank{font-size:12px;color:var(--text-muted);font-weight:500}
.cust-name{font-size:12.5px;font-weight:500;color:var(--text)}
.bottom-row{display:flex;gap:20px}
.bottom-row .table-card{flex:1;min-width:0}
.receipt-link{color:var(--accent);font-weight:500;text-decoration:none}
.receipt-link:hover{text-decoration:underline}
.status-dot{width:5px;height:5px;border-radius:50%;display:inline-block;margin-right:5px}
.status-dot.paid{background:var(--success)}
.status-dot.processing{background:#d97706}
.status-dot.failed{background:var(--error)}
.status-label{font-size:12px}
.donut-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px}
.donut-head{font-size:14px;font-weight:700;color:var(--text);margin-bottom:14px}
.donut-row{display:flex;align-items:center;gap:20px}
.legend-item{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.ldot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.ll{font-size:12px;font-weight:500;color:var(--text)}
.lp{font-size:15px;font-weight:700;color:var(--text)}
.la{font-size:11px;color:var(--text-muted)}
.donut-total{display:flex;justify-content:space-between;margin-top:14px;padding-top:10px;border-top:1px solid var(--border);font-size:13px;color:var(--text)}
@media(max-width:900px){.stats-row{flex-direction:column}.bottom-row{flex-direction:column}}
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
<h2>Reports</h2>
<p>Business Performance Overview</p>
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

<div class="stats-row">
<div class="stat-card">
<div class="stat-label">Revenue</div>
<div class="stat-value">₦<?= number_format($total_revenue) ?></div>
<div class="stat-delta <?= $rev_delta_pct > 0 ? 'up' : ($rev_delta_pct < 0 ? 'down' : 'neutral') ?>">
<?php if ($rev_delta_pct > 0): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="18 15 12 9 6 15"/></svg>
<?php elseif ($rev_delta_pct < 0): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
<?php else: ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
<?php endif; ?>
<?= $rev_delta_pct ?>% vs last month
</div>
</div>
<div class="stat-card">
<div class="stat-label">Collected</div>
<div class="stat-value">₦<?= number_format($total_collected) ?></div>
<div class="stat-delta <?= $col_delta_pct > 0 ? 'up' : ($col_delta_pct < 0 ? 'down' : 'neutral') ?>">
<?php if ($col_delta_pct > 0): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="18 15 12 9 6 15"/></svg>
<?php elseif ($col_delta_pct < 0): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
<?php else: ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
<?php endif; ?>
<?= $col_delta_pct ?>% vs last month
</div>
</div>
<div class="stat-card">
<div class="stat-label">Invoices</div>
<div class="stat-value"><?= $invoice_count ?></div>
<div class="stat-sub"><?= $paid_invoices ?> paid · <?= $partial_invoices ?> partial · <?= $pending_invoices ?> pending</div>
</div>
<div class="stat-card">
<div class="stat-label">Outstanding</div>
<div class="stat-value">₦<?= number_format($total_outstanding) ?></div>
<?php $coll_rate = $total_revenue > 0 ? min(100, round(($total_collected / $total_revenue) * 100, 1)) : 0; ?>
<div class="stat-sub"><?= $coll_rate ?>% collection rate</div>
</div>
</div>

<div class="donut-card">
<div class="donut-head">Revenue Breakdown</div>
<div class="donut-row">
<svg width="120" height="120" viewBox="0 0 96 96" style="flex-shrink:0">
<circle cx="48" cy="48" r="36" fill="none" stroke="#f1f3f7" stroke-width="16"/>
<?php if ($donut_collected_len > 0): ?>
<circle cx="48" cy="48" r="36" fill="none" stroke="#059669" stroke-width="16" stroke-dasharray="<?= round($donut_collected_len, 1) ?> <?= round($donut_circ, 1) ?>" stroke-dashoffset="<?= round($donut_collected_offset, 1) ?>" transform="rotate(-90 48 48)"/>
<?php endif; ?>
<?php if ($donut_outstanding_len > 0): ?>
<circle cx="48" cy="48" r="36" fill="none" stroke="#f97316" stroke-width="16" stroke-dasharray="<?= round($donut_outstanding_len, 1) ?> <?= round($donut_circ, 1) ?>" stroke-dashoffset="<?= round($donut_outstanding_offset, 1) ?>" transform="rotate(-90 48 48)"/>
<?php endif; ?>
</svg>
<div style="flex:1">
<div class="legend-item"><div class="ldot" style="background:var(--success)"></div><div><div class="ll">Collected</div><div style="display:flex;align-items:baseline;gap:6px"><span class="lp"><?= $donut_collected_pct ?>%</span><span class="la">(₦<?= number_format($total_collected, 2) ?>)</span></div></div></div>
<div class="legend-item" style="margin-bottom:0"><div class="ldot" style="background:#f97316"></div><div><div class="ll">Outstanding</div><div style="display:flex;align-items:baseline;gap:6px"><span class="lp"><?= $donut_outstanding_pct ?>%</span><span class="la">(₦<?= number_format($total_outstanding, 2) ?>)</span></div></div></div>
</div>
</div>
<div class="donut-total"><span>Total Revenue</span><strong>₦<?= number_format($total_revenue, 2) ?></strong></div>
</div>

<div class="content-row">
<div class="table-card">
<div class="table-toolbar">
<div class="toolbar-left">
<span class="toolbar-title">Monthly Revenue</span>
</div>
<div class="toolbar-right">
<div class="date-range">
<input class="date-input" type="date" id="fromDate" value="<?= htmlspecialchars($from_date) ?>" onchange="applyDateFilter()">
<span class="sep">–</span>
<input class="date-input" type="date" id="toDate" value="<?= htmlspecialchars($to_date) ?>" onchange="applyDateFilter()">
<?php if ($has_date_filter): ?>
<span class="filter-active">Filtered <a href="report.php">&times;</a></span>
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
</div>
</div>

<div class="table-responsive">
<table>
<thead>
<tr>
<th>Month</th>
<th style="text-align:right">Invoiced (₦)</th>
<th style="text-align:right">Collected (₦)</th>
<th style="text-align:right">Outstanding (₦)</th>
<th style="text-align:right">Collection Rate</th>
</tr>
</thead>
<tbody>
<?php if (count($months) > 0): ?>
<?php foreach ($visible_months as $mk => $m): ?>
<tr>
<td><span class="month-label"><?= $m['label'] ?></span></td>
<td style="text-align:right">₦<?= number_format($m['invoiced']) ?></td>
<td style="text-align:right">₦<?= number_format($m['collected']) ?></td>
<td style="text-align:right">₦<?= number_format($m['outstanding']) ?></td>
<td style="text-align:right"><span class="rate-pill <?= $m['rate'] >= 70 ? 'high' : ($m['rate'] >= 30 ? 'med' : 'low') ?>"><?= $m['rate'] ?>%</span></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="5" class="empty-state">No data yet. Create invoices and record payments to see reports.</td></tr>
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
<?php $s = max(1, $page - 2); $e = min($total_pages, $page + 2);
if ($s > 1) echo '<span class="dots">...</span>';
for ($i = $s; $i <= $e; $i++): ?>
<?php if ($i === $page): ?><span class="current"><?= $i ?></span>
<?php else: ?><a href="?page=<?= $i ?><?= $base_qs !== '' ? '&' . $base_qs : '' ?>"><?= $i ?></a>
<?php endif; ?>
<?php endfor;
if ($e < $total_pages) echo '<span class="dots">...</span>'; ?>
<?php if ($page < $total_pages): ?>
<a href="?page=<?= $page + 1 ?><?= $base_qs !== '' ? '&' . $base_qs : '' ?>">Next &raquo;</a>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
</div>
</div>

<div class="bottom-row">
<div class="table-card">
<div class="table-toolbar">
<div class="toolbar-left">
<span class="toolbar-title">Top Customers</span>
</div>
</div>
<div class="table-responsive">
<table>
<thead>
<tr><th>#</th><th>Customer</th><th style="text-align:right">Total Paid</th><th style="text-align:right">Payments</th></tr>
</thead>
<tbody>
<?php if (count($top_customers) > 0): $rank = 1; ?>
<?php foreach ($top_customers as $tc): ?>
<tr>
<td><span class="cust-rank"><?= $rank++ ?></span></td>
<td><span class="cust-name"><?= htmlspecialchars($tc['customer_name']) ?></span></td>
<td style="text-align:right;font-weight:500">₦<?= number_format($tc['total_paid']) ?></td>
<td style="text-align:right"><?= $tc['payment_count'] ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="4" class="empty-state">No paid customers yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<div class="table-card">
<div class="table-toolbar">
<div class="toolbar-left">
<span class="toolbar-title">Recent Payments</span>
</div>
</div>
<div class="table-responsive">
<table>
<thead>
<tr><th>Receipt</th><th>Customer</th><th style="text-align:right">Amount</th><th>Status</th></tr>
</thead>
<tbody>
<?php if (count($recent_payments) > 0): ?>
<?php foreach ($recent_payments as $rp):
$rd = $rp['payment_date'];
if (!$rd || $rd === '0000-00-00') $rd = $rp['created_at'];
?>
<tr>
<td><a href="view-receipt.php?id=<?= $rp['id'] ?>" class="receipt-link"><?= htmlspecialchars($rp['receipt_number']) ?></a></td>
<td><?= htmlspecialchars($rp['customer_name']) ?></td>
<td style="text-align:right;font-weight:500">₦<?= number_format($rp['amount'], 2) ?></td>
<td><span class="status-dot <?= $rp['status'] ?>"></span><span class="status-label"><?= ucfirst($rp['status']) ?></span></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="4" class="empty-state">No payments recorded yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

</div>

<script src="assets/js/app.js"></script>
<script>
function applyDateFilter() {
    var from = document.getElementById('fromDate').value;
    var to = document.getElementById('toDate').value;
    var params = [];
    if (from) params.push('from=' + from);
    if (to) params.push('to=' + to);
    window.location.href = 'report.php' + (params.length ? '?' + params.join('&') : '');
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</div>
</body>
</html>
