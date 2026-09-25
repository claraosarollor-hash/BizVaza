<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'dashboard';

$canRevenue = adminCan('revenue');
$canUsers = adminCan('users');
$canUpgrades = adminCan('upgrade_requests');

$totalUsers = $canUsers ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
$proPid = proPlanId($pdo);
$freePid = freePlanId($pdo);
$freeUsers = $canUsers
    ? $pdo->query("SELECT COUNT(*) FROM users WHERE plan_id = " . (int)$freePid . " OR plan_id IS NULL")->fetchColumn()
    : 0;
$proUsers = $canUsers && $proPid > 0
    ? $pdo->query("SELECT COUNT(*) FROM users WHERE plan_id = " . (int)$proPid)->fetchColumn()
    : 0;
$newThisWeek = $canUsers ? $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn() : 0;
$totalInvoices = $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$totalReceipts = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'paid'")->fetchColumn();
$totalRevenue = $canRevenue ? $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'")->fetchColumn() : 0;
$pendingUpgrades = $canUpgrades ? $pdo->query("SELECT COUNT(*) FROM subscription_payments WHERE status = 'pending'")->fetchColumn() : 0;
$teamCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$recentUsers = $canUsers ? $pdo->query("SELECT id, full_name, business_name, email, plan_id, created_at FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll() : [];

// Last 6 months platform trends
$monthKeys = [];
for ($m = 5; $m >= 0; $m--) { $monthKeys[] = date('Y-m', strtotime("first day of -$m months")); }
$monthLabels = array_map(function($k) { return date('M', strtotime($k . '-01')); }, $monthKeys);
$revByMonth = array_fill(0, 6, 0);
$usersByMonth = array_fill(0, 6, 0);

$revMap = [];
if ($canRevenue) {
    $stmt = $pdo->query("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) total FROM payments WHERE status = 'paid' AND payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01') GROUP BY ym");
    foreach ($stmt->fetchAll() as $r) { $revMap[$r['ym']] = floatval($r['total']); }
}

$userMap = [];
if ($canUsers) {
    $stmt = $pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c FROM users WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01') GROUP BY ym");
    foreach ($stmt->fetchAll() as $r) { $userMap[$r['ym']] = intval($r['c']); }
}

foreach ($monthKeys as $i => $k) {
    $revByMonth[$i] = $revMap[$k] ?? 0;
    $usersByMonth[$i] = $userMap[$k] ?? 0;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.content{padding:28px 24px 0;flex:1}
.avatar-init{width:28px;height:28px;border-radius:50%;background:var(--accent-bg);display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--accent);flex-shrink:0}
.user-cell{display:flex;align-items:center;gap:8px}
.plan-bar{display:flex;gap:0;border-radius:8px;overflow:hidden;height:20px;margin-top:8px;width:100%}
.plan-bar .seg{display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;color:#fff}
.plan-bar .seg.free{background:var(--accent-bg);color:var(--accent-hover)}
.plan-bar .seg.pro{background:var(--success)}
.b-free{background:var(--accent-bg);color:var(--accent)}
@media(max-width:1100px){.stat-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.chart-grid-2{grid-template-columns:1fr!important}}
@media(max-width:600px){.stat-grid{grid-template-columns:1fr}}
</style>
</head>
<body class="dashboard-body">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar">
<div>
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<h2>Admin Dashboard</h2>
<p>Overview of your BizVaza platform</p>
</div>
<div style="font-size:12px;color:var(--text-light)"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
</div>
<div class="content">
<div class="stat-grid">
<div class="stat-card">
<div class="stat-icon si-blue">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
</div>
<div>
<span class="stat-label">Total Users</span>
<div class="stat-val"><?= $totalUsers ?></div>
<div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $newThisWeek ?> new this week</div>
<div class="plan-bar">
<?php $total = max($totalUsers, 1); $freePct = round($freeUsers / $total * 100); $proPct = 100 - $freePct; ?>
<div class="seg free" style="width:<?= $freePct ?>%"><?= $freePct > 10 ? 'Free '.$freeUsers : '' ?></div>
<div class="seg pro" style="width:<?= $proPct ?>%"><?= $proPct > 10 ? 'Pro '.$proUsers : '' ?></div>
</div>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-green">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
</div>
<div>
<span class="stat-label">Free Users</span>
<div class="stat-val"><?= $freeUsers ?></div>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-purple">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--purple)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
</div>
<div>
<span class="stat-label">Pro Users</span>
<div class="stat-val"><?= $proUsers ?></div>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-green">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg>
</div>
<div>
<span class="stat-label">Team</span>
<div class="stat-val"><?= $teamCount ?></div>
<div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?php if (adminCan('team')): ?><a href="team.php" style="color:var(--accent);text-decoration:none">Manage &rarr;</a><?php else: ?>Admin staff<?php endif; ?></div>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-orange">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
</div>
<div>
<span class="stat-label">Total Invoices</span><div class="stat-val"><?= $totalInvoices ?></div>
</div>
</div>
<div class="stat-card">
<div class="stat-icon si-purple">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--purple)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" /><rect x="9" y="3" width="6" height="4" rx="1" /><path d="M9 12h6M9 16h4" /></svg>
</div>
<div>
<span class="stat-label">Total Receipts</span>
<div class="stat-val"><?= $totalReceipts ?></div>
</div>
</div>
<?php if ($canRevenue): ?>
<div class="stat-card">
<div class="stat-icon si-blue">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
</div>
<div>
<span class="stat-label">Total Revenue</span>
<div class="stat-val">₦<?= number_format($totalRevenue, 2) ?></div>
<div style="font-size:11px;color:var(--text-muted);margin-top:4px">From paid receipts</div>
</div>
</div>
<?php endif; ?>
<?php if ($pendingUpgrades > 0): ?>
<a href="upgrade-requests.php" style="text-decoration:none">
<div class="stat-card" style="border-color:rgba(180,83,9,.35);background:var(--accent-bg)">
<div class="stat-icon si-orange">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--error)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
</div>
<div>
<span class="stat-label">Pending Upgrades</span>
<div class="stat-val" style="color:var(--error)"><?= $pendingUpgrades ?></div>
<div style="font-size:11px;color:var(--accent);margin-top:4px">Review &amp; approve <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><polyline points="9 18 15 12 9 6"/></svg></div>
</div>
</div>
</a>
<?php endif; ?>
</div>

<?php if ($canRevenue): ?>
<div class="card">
<div class="card-head"><h3>Revenue Trend</h3><span style="font-size:12px;color:var(--text-muted)">Last 6 months</span></div>
<div style="height:230px;position:relative"><canvas id="adminRevenueChart"></canvas></div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px" class="chart-grid-2">
<div class="card" style="margin-bottom:0">
<div class="card-head"><h3>New Users</h3></div>
<div style="height:200px;position:relative"><canvas id="adminUsersChart"></canvas></div>
</div>
<div class="card" style="margin-bottom:0">
<div class="card-head"><h3>Plan Breakdown</h3></div>
<div style="height:200px;position:relative"><canvas id="adminPlanChart"></canvas></div>
</div>
</div>

<?php if ($canUsers): ?>
<div class="card">
<div class="card-head"><h3>Recent Registrations</h3></div>
<div class="table-responsive"><table>
<thead><tr><th>User</th><th>Business</th><th>Email</th><th>Plan</th><th>Joined</th></tr></thead>
<tbody>
<?php if (count($recentUsers) > 0): ?>
<?php foreach ($recentUsers as $u): ?>
<tr>
<td><div class="user-cell"><span class="avatar-init"><?= htmlspecialchars(getAvatarInitials($u['full_name'])) ?></span> <?= htmlspecialchars($u['full_name']) ?></div></td>
<td><?= htmlspecialchars($u['business_name']) ?></td>
<td><?= htmlspecialchars($u['email']) ?></td>
<td><span class="badge <?= $proPid > 0 && (int)($u['plan_id'] ?? 0) === $proPid ? 'b-pro' : 'b-free' ?>"><?= $proPid > 0 && (int)($u['plan_id'] ?? 0) === $proPid ? 'Pro' : 'Free' ?></span></td>
<td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted)">No users yet</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script>
var adminRevenueLabels = <?= json_encode($monthLabels) ?>;
var adminRevenueData = <?= json_encode(array_map(function($v){ return round($v, 2); }, $revByMonth)) ?>;
var adminUsersData = <?= json_encode($usersByMonth) ?>;
var adminPlanData = <?= json_encode([$freeUsers, $proUsers]) ?>;
var adminCharts = {};
function adminChartColors() {
    var cs = getComputedStyle(document.documentElement);
    function v(n) { return (cs.getPropertyValue(n) || '').trim() || '#8da0c0'; }
    return { text: v('--text'), muted: v('--text-muted'), grid: v('--border') };
}
function renderAdminCharts() {
    var tc = adminChartColors();
    if (adminCharts.rev) adminCharts.rev.destroy();
    if (adminCharts.users) adminCharts.users.destroy();
    if (adminCharts.plan) adminCharts.plan.destroy();
    adminCharts.rev = new Chart(document.getElementById('adminRevenueChart'), {
        type: 'line',
        data: {
            labels: adminRevenueLabels,
            datasets: [{ label: 'Revenue', data: adminRevenueData, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.12)', fill: true, tension: .4, pointRadius: 3, pointBackgroundColor: '#059669', borderWidth: 2 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(c) { return ' ₦' + Number(c.raw).toLocaleString(); } } }
            },
            scales: {
                x: { ticks: { color: tc.muted }, grid: { color: tc.grid } },
                y: { beginAtZero: true, ticks: { color: tc.muted, callback: function(v) { return '₦' + v; } }, grid: { color: tc.grid } }
            }
        }
    });
    adminCharts.users = new Chart(document.getElementById('adminUsersChart'), {
        type: 'bar',
        data: {
            labels: adminRevenueLabels,
            datasets: [{ label: 'New Users', data: adminUsersData, backgroundColor: '#b45309', borderRadius: 4, maxBarThickness: 34 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(c) { return ' ' + c.raw + ' new'; } } }
            },
            scales: {
                x: { ticks: { color: tc.muted }, grid: { color: tc.grid } },
                y: { beginAtZero: true, ticks: { precision: 0, color: tc.muted }, grid: { color: tc.grid } }
            }
        }
    });
    adminCharts.plan = new Chart(document.getElementById('adminPlanChart'), {
        type: 'doughnut',
        data: {
            labels: ['Free', 'Pro'],
            datasets: [{ data: adminPlanData, backgroundColor: ['#f97316', '#7c3aed'], borderWidth: 0, hoverOffset: 4 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: {
                legend: { position: 'bottom', labels: { color: tc.text, usePointStyle: true, pointStyleWidth: 8, boxHeight: 6, padding: 14 } },
                tooltip: { callbacks: { label: function(c) { var t = adminPlanData[0] + adminPlanData[1], p = t > 0 ? Math.round(c.raw / t * 100) : 0; return ' ' + c.label + ': ' + c.raw + ' (' + p + '%)'; } } }
            }
        }
    });
}
if (window.Chart) { renderAdminCharts(); document.addEventListener('bizvazaThemeChange', renderAdminCharts); }
</script>
<script src="../assets/js/app.js"></script>
</body>
</html>