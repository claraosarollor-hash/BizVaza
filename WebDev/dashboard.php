<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$first_name = explode(' ', $full_name)[0];
$avatar_initials = $_SESSION['avatar_initials'];

// Pro plan promo line driven by admin-editable plan features
$proPlan = getProPlan($pdo);
$proFeatures = $proPlan ? (json_decode($proPlan['features'], true) ?: []) : [];
$proTemplateFeature = '';
foreach ($proFeatures as $f) {
    if (stripos($f, 'template') !== false) { $proTemplateFeature = $f; break; }
}
$proTemplateText = $proTemplateFeature ?: 'professionally designed invoice and receipt templates';

// Dashboard stats — compute invoice status from payments (partial payments count only what was received)
$stmt = $pdo->prepare("
  SELECT
    i.amount,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0) as paid_amount
  FROM invoices i
  WHERE i.user_id = ?
");
$stmt->execute([$user_id]);
$all_invoices = $stmt->fetchAll();

$total_invoices = count($all_invoices);
$paid_amount = 0;
$pending_amount = 0;
foreach ($all_invoices as $inv) {
    $amt = floatval($inv['amount']);
    $paid = min($amt, floatval($inv['paid_amount']));
    $paid_amount += $paid;
    $pending_amount += ($amt - $paid);
}
$total_amount = $paid_amount + $pending_amount;

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE user_id = ? AND status = 'paid' AND (invoice_number IS NULL OR invoice_number = '')");
$stmt->execute([$user_id]);
$standalone_paid = floatval($stmt->fetchColumn());
$paid_amount += $standalone_paid;
$total_amount += $standalone_paid;

// SVG donut chart values
$circ = 2 * M_PI * 36;
if ($total_amount > 0) {
    $paid_len   = ($paid_amount / $total_amount) * $circ;
    $pending_len = ($pending_amount / $total_amount) * $circ;
    $start_offset = $circ * 0.25;
    $paid_offset    = $start_offset;
    $pending_offset = $start_offset - $paid_len;
} else {
    $paid_len = $pending_len = 0;
    $paid_offset = $pending_offset = 0;
}
$paid_pct    = $total_amount > 0 ? round(($paid_amount / $total_amount) * 100) : 0;
$pending_pct = $total_amount > 0 ? 100 - $paid_pct : 0;

// Recent invoices with computed status (paid only when fully covered)
$stmt = $pdo->prepare("
  SELECT i.*,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0) as paid_amount
  FROM invoices i
  WHERE i.user_id = ?
  ORDER BY i.created_at DESC LIMIT 10
");
$stmt->execute([$user_id]);
$recent_invoices = $stmt->fetchAll();
foreach ($recent_invoices as &$inv) {
    $inv['computed_status'] = (floatval($inv['paid_amount']) >= floatval($inv['amount']) && floatval($inv['amount']) > 0) ? 'paid' : (floatval($inv['paid_amount']) > 0 ? 'partial' : 'pending');
}
unset($inv);

// Recent receipts
$stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$recent_receipts = $stmt->fetchAll();

// Recent customers
$stmt = $pdo->prepare("SELECT * FROM customers WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$recent_customers = $stmt->fetchAll();

// Recent activity
$stmt = $pdo->prepare("SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$activities = $stmt->fetchAll();

// Customer & item counts
$stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE user_id = ?");
$stmt->execute([$user_id]);
$customer_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE user_id = ?");
$stmt->execute([$user_id]);
$item_count = $stmt->fetchColumn();

// Month-over-month deltas (last 30 days vs 30-60 days ago)
$stmt = $pdo->prepare("SELECT
  SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as curr,
  SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as prev
FROM invoices WHERE user_id = ?");
$stmt->execute([$user_id]);
$inv_delta = $stmt->fetch();
$inv_curr = intval($inv_delta['curr']);
$inv_prev = intval($inv_delta['prev']);
$inv_pct = $inv_prev > 0 ? round((($inv_curr - $inv_prev) / $inv_prev) * 100) : ($inv_curr > 0 ? 100 : 0);

$stmt = $pdo->prepare("SELECT
  SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as curr,
  SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as prev
FROM invoices WHERE user_id = ?");
$stmt->execute([$user_id]);
$amt_delta = $stmt->fetch();
$amt_curr = floatval($amt_delta['curr']);
$amt_prev = floatval($amt_delta['prev']);
$amt_pct = $amt_prev > 0 ? round((($amt_curr - $amt_prev) / $amt_prev) * 100) : ($amt_curr > 0 ? 100 : 0);

$stmt = $pdo->prepare("SELECT
  SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as curr,
  SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as prev
FROM payments WHERE user_id = ? AND status = 'paid'");
$stmt->execute([$user_id]);
$paid_delta = $stmt->fetch();
$paid_curr = floatval($paid_delta['curr']);
$paid_prev = floatval($paid_delta['prev']);
$paid_pct_chg = $paid_prev > 0 ? round((($paid_curr - $paid_prev) / $paid_prev) * 100) : ($paid_curr > 0 ? 100 : 0);

// Total receipts count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_receipts = intval($stmt->fetchColumn());

// Receipts month-over-month delta
$stmt = $pdo->prepare("SELECT
  SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as curr,
  SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as prev
FROM payments WHERE user_id = ?");
$stmt->execute([$user_id]);
$receipt_delta = $stmt->fetch();
$receipt_curr = intval($receipt_delta['curr']);
$receipt_prev = intval($receipt_delta['prev']);
$receipt_pct = $receipt_prev > 0 ? round((($receipt_curr - $receipt_prev) / $receipt_prev) * 100) : ($receipt_curr > 0 ? 100 : 0);

// Last 6 months trend for charts
$monthKeys = [];
for ($m = 5; $m >= 0; $m--) { $monthKeys[] = date('Y-m', strtotime("first day of -$m months")); }
$monthLabels = array_map(function($k) { return date('M', strtotime($k . '-01')); }, $monthKeys);
$revByMonth = array_fill(0, 6, 0);
$invByMonth = array_fill(0, 6, 0);

$stmt = $pdo->prepare("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) total FROM payments WHERE user_id = ? AND status = 'paid' AND payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01') GROUP BY ym");
$stmt->execute([$user_id]);
$revMap = [];
foreach ($stmt->fetchAll() as $r) { $revMap[$r['ym']] = floatval($r['total']); }

$stmt = $pdo->prepare("SELECT DATE_FORMAT(issue_date,'%Y-%m') ym, COUNT(*) c FROM invoices WHERE user_id = ? AND issue_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01') GROUP BY ym");
$stmt->execute([$user_id]);
$invMap = [];
foreach ($stmt->fetchAll() as $r) { $invMap[$r['ym']] = intval($r['c']); }

foreach ($monthKeys as $i => $k) {
    $revByMonth[$i] = $revMap[$k] ?? 0;
    $invByMonth[$i] = $invMap[$k] ?? 0;
}

// Pending amount delta: invoices in each window that are not fully paid
$stmt = $pdo->prepare("SELECT
  SUM(CASE WHEN i.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN i.amount ELSE 0 END) as curr,
  SUM(CASE WHEN i.created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND i.created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN i.amount ELSE 0 END) as prev
FROM invoices i
WHERE i.user_id = ?
  AND COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0) < i.amount");
$stmt->execute([$user_id]);
$pend_delta = $stmt->fetch();
$pend_curr = floatval($pend_delta['curr']);
$pend_prev = floatval($pend_delta['prev']);
$pend_pct = $pend_prev > 0 ? round((($pend_curr - $pend_prev) / $pend_prev) * 100) : ($pend_curr > 0 ? 100 : 0);

// Recurring invoices widget data
$stmt = $pdo->prepare("SELECT COUNT(*) FROM recurring_invoices WHERE user_id = ? AND status = 'active'");
$stmt->execute([$user_id]);
$recurring_active = intval($stmt->fetchColumn());
$stmt = $pdo->prepare("SELECT MIN(next_run_date) FROM recurring_invoices WHERE user_id = ? AND status = 'active'");
$stmt->execute([$user_id]);
$recurring_next = $stmt->fetchColumn();

function deltaHtml($pct) {
    if ($pct > 0) return '<span class="stat-delta up">+' . $pct . '% from last month</span>';
    if ($pct < 0) return '<span class="stat-delta dn">' . $pct . '% from last month</span>';
    return '<span class="stat-delta">0% from last month</span>';
}

$activePage = 'dashboard';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza – Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.upgrade-banner{display:flex;align-items:center;gap:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:16px}
.upgrade-banner div{flex:1;font-size:13px;color:#92400e}
.upgrade-banner div strong{color:var(--accent)}
.upgrade-banner a{padding:7px 14px;background:var(--accent);color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;white-space:nowrap}
.upgrade-banner a:hover{background:var(--accent-hover)}
.stat-val.sm{font-size:20px}
.lower{display:flex;gap:20px;align-items:flex-start}
.left-col{flex:1;min-width:0;display:flex;flex-direction:column;gap:20px}
.right-col{width:380px;flex-shrink:0;display:flex;flex-direction:column;gap:20px;position:sticky;top:28px}
.view-all{font-size:12px;color:var(--accent);font-weight:500;cursor:pointer}
.inv-link{color:var(--accent);font-weight:500;cursor:pointer}
.dots{background:none;border:none;color:#c0c8d4;font-size:18px;cursor:pointer;letter-spacing:1px}
.qa-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);cursor:pointer}
.qa-item:last-child{border-bottom:none}
.qa-ic{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.qa-text{flex:1}
.qa-text span{display:block;font-size:13px;font-weight:500;color:var(--text)}
.qa-text small{font-size:11px;color:var(--text-muted)}
.qa-arr{flex-shrink:0;color:#c0c8d4}
.donut-wrap{padding:4px 0}
.donut-row{display:flex;align-items:center;gap:14px}
.legend-item{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.ldot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.ll{font-size:12px;font-weight:500;color:var(--text)}
.lp{font-size:13px;font-weight:700;color:var(--text)}
.la{font-size:11px;color:var(--text-muted)}
.donut-total{display:flex;justify-content:space-between;margin-top:16px;padding-top:12px;border-top:1px solid var(--border);font-size:13px;color:var(--text)}
.act-body p{font-size:12px;color:var(--text);font-weight:500;line-height:1.4}
.act-body small{font-size:11px;color:var(--text-muted)}
.act-time{font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0}
.empty-activity{padding:20px 0;text-align:center;font-size:13px;color:var(--text-muted)}
@media(max-width:1100px){.stat-grid{grid-template-columns:repeat(2,1fr)}.lower{flex-direction:column;align-items:stretch}.left-col{width:100%}.right-col{width:100%;position:static}}@media(max-width:700px){.stat-grid{grid-template-columns:1fr}}
</style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
<div class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Welcome back, <?= htmlspecialchars($first_name) ?>! <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle"><path d="M18 11V6a2 2 0 0 0-4 0v5"/><path d="M14 10V4a2 2 0 0 0-4 0v2"/><path d="M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/><path d="M5 5.5l-3-2"/><path d="M4.5 9.5l-3.2-.5"/></svg></h2>
<p>Here's what's happening with your business.</p>
</div>
</div>
<div class="invoice-topbar-right">
<div class="icon-btn" id="bellBtn" onclick="toggleNotif(event)" style="position:relative">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
<span class="bell-dot"></span>
</div>
<?php require __DIR__ . '/includes/user-menu.php'; ?>
</div>
</div>

<div class="content">

<?php if (!isset($_SESSION['is_pro']) || !$_SESSION['is_pro']): ?>
<?php $bannerTrial = $_SESSION['trial_ends_at'] ?? null; $bannerTd = $bannerTrial ? strtotime($bannerTrial) : 0; ?>
<div class="upgrade-banner">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
<div>
<strong>Unlock premium templates</strong> — Get access to <?= htmlspecialchars($proTemplateText) ?>.
<?php if ($bannerTd > time()): ?>
<p style="margin-top:4px;font-size:12px;color:var(--accent)">Your free trial ends <?= date('M j, Y', $bannerTd) ?> (<?= (int)ceil(($bannerTd - time()) / 86400) ?>d left) — upgrade before it ends to keep unlimited access.</p>
<?php endif; ?>
</div>
<a href="pricing.php">Upgrade to Pro <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><polyline points="9 18 15 12 9 6"/></svg></a>
</div>
<?php endif; ?>

<div class="stat-grid">
<div class="stat-card">
<div class="stat-icon si-blue">
<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
</div>
<div>
<span class="stat-label">Total Invoices</span>
<div class="stat-val"><?= $total_invoices ?></div>
<?= deltaHtml($inv_pct) ?>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-green">
<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
</div>
<div>
<span class="stat-label">Total Amount</span>
<div class="stat-val sm">₦<?= number_format($total_amount, 2) ?></div>
<?= deltaHtml($amt_pct) ?>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-purple">
<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
</div>
<div>
<span class="stat-label">Paid Amount</span>
<div class="stat-val sm">₦<?= number_format($paid_amount, 2) ?></div>
<?= deltaHtml($paid_pct_chg) ?>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-green">
<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
</div>
<div>
<span class="stat-label">Total Receipts</span>
<div class="stat-val"><?= $total_receipts ?></div>
<?= deltaHtml($receipt_pct) ?>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-blue">
<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
</div>
<div>
<span class="stat-label">Customers</span>
<div class="stat-val"><?= $customer_count ?></div>
<span class="stat-delta">Registered clients</span>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-green">
<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
</div>
<div>
<span class="stat-label">Items / Services</span>
<div class="stat-val"><?= $item_count ?></div>
<span class="stat-delta">In your catalog</span>
</div>
</div>
</div>

<div class="lower">
<div class="left-col">
<div class="card">
<div class="card-head"><h3>Recent Invoices</h3><a class="view-all" href="invoiceDashboard.php">View All</a></div>
<div class="table-responsive">
<table>
<thead><tr><th>Invoice #</th><th>Customer</th><th>Date</th><th>Due Date</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($recent_invoices as $inv): ?>
<tr>
<td><a href="create-invoice.php?id=<?= $inv['id'] ?>" class="inv-link"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
<td><?= htmlspecialchars($inv['customer_name']) ?></td>
<td><?php $idate = $inv['issue_date']; echo $idate !== '0000-00-00' ? date('M d, Y', strtotime($idate)) : date('M d, Y', strtotime($inv['created_at'])); ?></td>
<td><?php $ddate = $inv['due_date']; echo $ddate !== '0000-00-00' ? date('M d, Y', strtotime($ddate)) : '-'; ?></td>
<td>₦<?= number_format($inv['amount'], 2) ?></td>
<td><?php if ($inv['computed_status'] === 'partial'): ?><span class="badge b-partial">Partially Paid (₦<?= number_format($inv['paid_amount'], 2) ?> of ₦<?= number_format($inv['amount'], 2) ?>)</span><?php else: ?><span class="badge b-<?= $inv['computed_status'] ?>"><?= ucfirst($inv['computed_status']) ?></span><?php endif; ?></td>
<td><button class="dots">···</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Revenue Trend</h3><span class="view-all">Last 6 months</span></div>
<div style="height:230px;position:relative"><canvas id="revenueChart"></canvas></div>
</div>

<div class="card">
<div class="card-head"><h3>Recent Receipts</h3><a class="view-all" href="receipts.php">View All</a></div>
<div class="table-responsive">
<table>
<thead><tr><th>Receipt #</th><th>Customer</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($recent_receipts as $rcpt): ?>
<tr>
<td><a href="view-receipt.php?id=<?= $rcpt['id'] ?>" class="inv-link"><?= htmlspecialchars($rcpt['receipt_number']) ?></a></td>
<td><?= htmlspecialchars($rcpt['customer_name']) ?></td>
<td>₦<?= number_format($rcpt['amount'], 2) ?></td>
<td><?php $pdate = $rcpt['payment_date']; echo (!empty($pdate) && $pdate !== '0000-00-00') ? date('M d, Y', strtotime($pdate)) : date('M d, Y', strtotime($rcpt['created_at'])); ?></td>
<td><span class="badge b-<?= $rcpt['status'] === 'paid' ? 'paid' : 'pending' ?>"><?= ucfirst($rcpt['status']) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Recent Customers</h3><a class="view-all" href="customers.php">View All</a></div>
<div>
<?php foreach ($recent_customers as $cust): 
$initials = strtoupper(substr($cust['name'], 0, 1) . (strpos($cust['name'], ' ') !== false ? substr($cust['name'], strpos($cust['name'], ' ') + 1, 1) : ''));
?>
<div class="qa-item" onclick="location.href='customers.php'" style="cursor:pointer">
<div class="qa-ic" style="background:rgba(2,132,199,.12);color:#2563eb;font-size:12px;font-weight:700"><?= htmlspecialchars($initials) ?></div>
<div class="qa-text"><span><?= htmlspecialchars($cust['name']) ?></span><small><?= htmlspecialchars($cust['email'] ?? '-') ?></small></div>
<svg class="qa-arr" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#c0c8d4" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
</div>
<?php endforeach; ?>
</div>
</div>
</div>

<div class="right-col">
<div class="card">
<div class="card-head"><h3>Quick Actions</h3></div>
<div>
<div class="qa-item" onclick="location.href='invoiceDashboard.php'" style="cursor:pointer">
<div class="qa-ic" style="background:var(--accent-bg)">
<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
</div>
<div class="qa-text"><span>Create Invoice</span><small>Generate a new invoice</small></div>
<svg class="qa-arr" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#c0c8d4" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
</div>
<div class="qa-item" onclick="location.href='receipts.php'" style="cursor:pointer">
<div class="qa-ic" style="background:var(--success-bg)">
<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
</div>
<div class="qa-text"><span>Create Receipt</span><small>Generate a new receipt</small></div>
<svg class="qa-arr" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#c0c8d4" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
</div>
<div class="qa-item" onclick="location.href='customers.php'" style="cursor:pointer">
<div class="qa-ic" style="background:rgba(2,132,199,.12)">
<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
</div>
<div class="qa-text"><span>Add Customer</span><small>Add a new client</small></div>
<svg class="qa-arr" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#c0c8d4" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
</div>
<div class="qa-item" onclick="location.href='items-services.php'" style="cursor:pointer">
<div class="qa-ic" style="background:var(--purple-bg)">
<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
</div>
<div class="qa-text"><span>Add Item</span><small>Add a product or service</small></div>
<svg class="qa-arr" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#c0c8d4" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
</div>
<div class="qa-item" onclick="location.href='recurring.php'" style="cursor:pointer">
<div class="qa-ic" style="background:var(--accent-bg)">
<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
</div>
<div class="qa-text"><span>Recurring</span><small>Manage auto invoices</small></div>
<svg class="qa-arr" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#c0c8d4" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
</div>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Invoice Status</h3></div>
<div class="donut-wrap">
<div class="donut-row">
<div style="width:110px;height:110px;position:relative;flex-shrink:0"><canvas id="invoiceStatusChart"></canvas></div>
<div style="flex:1">
<div class="legend-item"><div class="ldot" style="background:var(--success)"></div><div><div class="ll">Paid</div><div style="display:flex;align-items:baseline;gap:5px"><span class="lp"><?= $paid_pct ?>%</span><span class="la">(₦<?= number_format($paid_amount, 2) ?>)</span></div></div></div>
<div class="legend-item" style="margin-bottom:0"><div class="ldot" style="background:#f97316"></div><div><div class="ll">Pending</div><div style="display:flex;align-items:baseline;gap:5px"><span class="lp"><?= $pending_pct ?>%</span><span class="la">(₦<?= number_format($pending_amount, 2) ?>)</span></div></div></div>
</div>
</div>
<div class="donut-total"><span>Total</span><strong>₦<?= number_format($total_amount, 2) ?></strong></div>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Recurring Invoices</h3><a class="view-all" href="recurring.php">Manage</a></div>
<div class="qa-item" onclick="location.href='recurring.php'" style="cursor:pointer;border-bottom:none">
<div class="qa-ic" style="background:var(--accent-bg)">
<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
</div>
<div class="qa-text">
<span><?= $recurring_active ?> active schedule<?= $recurring_active == 1 ? '' : 's' ?></span>
<small><?= $recurring_next ? 'Next auto-run ' . date('M j, Y', strtotime($recurring_next)) : 'No active schedules' ?></small>
</div>
<svg class="qa-arr" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#c0c8d4" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Recent Activity</h3><a class="view-all" href="activity.php">View All</a></div>
<div>
<?php if (count($activities) > 0): ?>
<?php
$act_icons = [
    'invoice_created' => ['bg' => '#fff7ed', 'color' => '#b45309', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%color%" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'],
    'payment_recorded' => ['bg' => '#d1fae5', 'color' => '#059669', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%color%" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'],
    'customer_added' => ['bg' => '#dbeafe', 'color' => '#2563eb', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%color%" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>'],
    'item_added' => ['bg' => '#ede9fe', 'color' => '#7c3aed', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%color%" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>'],
];
?>
<?php foreach ($activities as $act): ?>
<?php $icon = $act_icons[$act['action_type']] ?? $act_icons['invoice_created']; ?>
<div class="act-item">
<div class="act-ic" style="background:<?= $icon['bg'] ?>"><?= str_replace('%color%', $icon['color'], $icon['svg']) ?></div>
<div class="act-body" style="flex:1"><p><?= htmlspecialchars($act['description']) ?></p></div>
<div class="act-time"><?= timeAgo($act['created_at']) ?></div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="empty-activity">No recent activity yet — your actions will appear here.</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
</div>
<script>
var revenueLabels = <?= json_encode($monthLabels) ?>;
var revenueData = <?= json_encode(array_map(function($v){ return round($v, 2); }, $revByMonth)) ?>;
var invoiceCountData = <?= json_encode($invByMonth) ?>;
var statusData = <?= json_encode([round($paid_amount, 2), round($pending_amount, 2)]) ?>;
function chartColors() {
    var cs = getComputedStyle(document.documentElement);
    function v(n) { return (cs.getPropertyValue(n) || '').trim() || '#8da0c0'; }
    return { text: v('--text'), muted: v('--text-muted'), grid: v('--border') };
}
var userCharts = {};
function renderUserCharts() {
    var tc = chartColors();
    if (userCharts.revenue) userCharts.revenue.destroy();
    if (userCharts.status) userCharts.status.destroy();
    userCharts.revenue = new Chart(document.getElementById('revenueChart'), {
        type: 'line',
        data: {
            labels: revenueLabels,
            datasets: [
                { label: 'Revenue', data: revenueData, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.12)', fill: true, tension: .4, pointRadius: 3, pointBackgroundColor: '#059669', borderWidth: 2 },
                { label: 'Invoices', data: invoiceCountData, borderColor: '#b45309', backgroundColor: 'rgba(180,83,9,.10)', fill: true, tension: .4, pointRadius: 3, pointBackgroundColor: '#b45309', borderWidth: 2, yAxisID: 'y1' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: tc.text, usePointStyle: true, pointStyleWidth: 8, boxHeight: 6 } },
                tooltip: { callbacks: { label: function(c) { return ' ' + c.dataset.label + ': ' + (c.dataset.yAxisID === 'y1' ? c.raw : '₦' + Number(c.raw).toLocaleString()); } } }
            },
            scales: {
                x: { ticks: { color: tc.muted }, grid: { color: tc.grid } },
                y: { beginAtZero: true, ticks: { color: tc.muted, callback: function(v) { return '₦' + v; } }, grid: { color: tc.grid } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { color: tc.muted } }
            }
        }
    });
    var statusTotal = statusData[0] + statusData[1];
    userCharts.status = new Chart(document.getElementById('invoiceStatusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Paid', 'Pending'],
            datasets: [{ data: statusData, backgroundColor: ['#059669', '#f97316'], borderWidth: 0, hoverOffset: 4 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(c) { var p = statusTotal > 0 ? Math.round(c.raw / statusTotal * 100) : 0; return ' ' + c.label + ': ₦' + Number(c.raw).toLocaleString() + ' (' + p + '%)'; } } }
            }
        }
    });
}
if (window.Chart) { renderUserCharts(); document.addEventListener('bizvazaThemeChange', renderUserCharts); }
</script>
<script src="assets/js/app.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</body>
</html>
