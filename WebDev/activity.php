<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$avatar_initials = $_SESSION['avatar_initials'];

// Filters
$action_filter = $_GET['action'] ?? '';
$from_date = $_GET['from'] ?? '';
$to_date = $_GET['to'] ?? '';

$where = "user_id = ?";
$params = [$user_id];

if ($action_filter !== '') {
    $where .= " AND action_type = ?";
    $params[] = $action_filter;
}
if ($from_date !== '') {
    $where .= " AND created_at >= ?";
    $params[] = $from_date;
}
if ($to_date !== '') {
    $where .= " AND created_at <= ?";
    $params[] = $to_date . ' 23:59:59';
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE $where");
$stmt->execute($params);
$total_items = intval($stmt->fetchColumn());

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 30;
$total_pages = max(1, ceil($total_items / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT * FROM activity_log WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$per_page, $offset]));
$activities = $stmt->fetchAll();

$activePage = 'activity';

$link_params = $_GET;
unset($link_params['page']);
$base_qs = http_build_query($link_params);

$act_icons = [
    'invoice_created' => ['bg' => '#fff7ed', 'color' => '#b45309', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%color%" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'],
    'payment_recorded' => ['bg' => '#d1fae5', 'color' => '#059669', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%color%" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'],
    'customer_added' => ['bg' => '#dbeafe', 'color' => '#2563eb', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%color%" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>'],
    'item_added' => ['bg' => '#ede9fe', 'color' => '#7c3aed', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="%color%" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza – Activity Log</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.page-area{flex:1;padding:24px 28px;display:flex;flex-direction:column;gap:16px}
.filter-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.filter-left{display:flex;align-items:center;gap:10px}
.filter-title{font-size:14px;font-weight:700;color:var(--text)}
.filter-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.filter-select{padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:11.5px;font-family:inherit;outline:none;background:var(--bg-card-alt);color:var(--text);cursor:pointer}
.filter-select:focus{border-color:var(--accent);background:var(--bg-card)}
.date-input{padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:11.5px;font-family:inherit;outline:none;background:var(--bg-card-alt);width:130px;color:var(--text)}
.date-input:focus{border-color:var(--accent);background:var(--bg-card)}
.filter-active{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--accent);background:var(--accent-bg);padding:4px 10px;border-radius:12px;font-weight:500}
.filter-active a{color:var(--accent);text-decoration:none;margin-left:2px;font-weight:700}
.filter-active a:hover{text-decoration:underline}
.act-list{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.act-item{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)}
.act-item:last-child{border-bottom:none}
.act-item:hover{background:var(--bg-card-alt)}
.act-ic{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.act-body p{font-size:13px;color:var(--text);font-weight:500;line-height:1.4}
.act-time{font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;margin-left:auto}
.empty-state{padding:48px 16px;text-align:center;font-size:13px;color:var(--text-muted)}
</style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Activity Log</h2>
<p>All actions across your account</p>
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

<div class="filter-bar">
<div class="filter-left">
<span class="filter-title">Activity Log</span>
<span style="font-size:11.5px;color:var(--text-muted)"><?= $total_items ?> record<?= $total_items !== 1 ? 's' : '' ?></span>
</div>
<div class="filter-right">
<select class="filter-select" onchange="applyFilter()">
<option value="">All Actions</option>
<option value="invoice_created"<?= $action_filter === 'invoice_created' ? ' selected' : '' ?>>Invoice Created</option>
<option value="payment_recorded"<?= $action_filter === 'payment_recorded' ? ' selected' : '' ?>>Payment Recorded</option>
<option value="customer_added"<?= $action_filter === 'customer_added' ? ' selected' : '' ?>>Customer Added</option>
<option value="item_added"<?= $action_filter === 'item_added' ? ' selected' : '' ?>>Item Added</option>
</select>
<input class="date-input" type="date" id="fromDate" value="<?= htmlspecialchars($from_date) ?>" onchange="applyFilter()">
<input class="date-input" type="date" id="toDate" value="<?= htmlspecialchars($to_date) ?>" onchange="applyFilter()">
<?php if ($action_filter !== '' || $from_date !== '' || $to_date !== ''): ?>
<span class="filter-active">Filtered <a href="activity.php">&times;</a></span>
<?php endif; ?>
</div>
</div>

<div class="act-list">
<?php if (count($activities) > 0): ?>
<?php foreach ($activities as $act): ?>
<?php $icon = $act_icons[$act['action_type']] ?? $act_icons['invoice_created']; ?>
<div class="act-item">
<div class="act-ic" style="background:<?= $icon['bg'] ?>"><?= str_replace('%color%', $icon['color'], $icon['svg']) ?></div>
<div class="act-body" style="flex:1"><p><?= htmlspecialchars($act['description']) ?></p></div>
<div class="act-time"><?= timeAgo($act['created_at']) ?></div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="empty-state">No activity yet — your actions will appear here.</div>
<?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="pagination-bar">
<div class="page-info">Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></div>
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

<script src="assets/js/app.js"></script>
<script>
function applyFilter() {
    var action = document.querySelector('.filter-select').value;
    var from = document.getElementById('fromDate').value;
    var to = document.getElementById('toDate').value;
    var params = [];
    if (action) params.push('action=' + encodeURIComponent(action));
    if (from) params.push('from=' + from);
    if (to) params.push('to=' + to);
    window.location.href = 'activity.php' + (params.length ? '?' + params.join('&') : '');
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</body>
</html>