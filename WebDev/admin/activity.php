<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'activity';

if (!adminCan('activity')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$actionFilter = $_GET['action'] ?? '';
$userId = intval($_GET['user_id'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($actionFilter) { $where[] = "a.action_type = ?"; $params[] = $actionFilter; }
if ($userId) { $where[] = "a.user_id = ?"; $params[] = $userId; }
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if ($params) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log a $whereClause");
    $countStmt->execute($params);
} else {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM activity_log a");
}
$totalItems = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalItems / $perPage));

$stmt = $pdo->prepare("SELECT a.*, u.full_name as user_name, u.email as user_email FROM activity_log a LEFT JOIN users u ON a.user_id = u.id $whereClause ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$activities = $stmt->fetchAll();

$actionTypes = $pdo->query("SELECT DISTINCT action_type FROM activity_log ORDER BY action_type")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, full_name, email FROM users ORDER BY full_name")->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Activity</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--topbar-bg);border-bottom:1px solid var(--border);padding:16px 28px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.topbar h2{font-size:18px;font-weight:600;color:var(--text)}
.topbar p{font-size:12px;color:var(--text-muted);margin-top:2px}
.content{padding:28px 24px 0;flex:1}
.filter-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:16px}
.filter-row select,.filter-row input{padding:7px 10px;border:1px solid var(--border-input);border-radius:6px;font-size:12px;font-family:'Inter',sans-serif;outline:none;background:var(--bg-card)}
.filter-row select:focus,.filter-row input:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;padding:7px 14px;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border-input);background:var(--bg-card);color:var(--text);text-decoration:none;font-family:'Inter',sans-serif}
.btn:hover{background:var(--btn-hover)}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-hover)}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 16px;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.card-head h3{font-size:14px;font-weight:600;color:var(--text)}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;color:var(--text-muted);font-weight:500;text-align:left;padding:8px 10px;background:var(--table-header);border-bottom:1px solid var(--table-border);white-space:nowrap}
tbody td{padding:10px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--table-border)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--bg-hover)}
.pagination{display:flex;gap:6px;align-items:center;margin-top:12px}
.pagination a{padding:6px 12px;border:1px solid var(--border-input);border-radius:6px;text-decoration:none;color:var(--text);font-size:12px}
.pagination a.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.pagination a:hover:not(.active){background:var(--btn-hover)}
.user-link{color:var(--accent);text-decoration:none;font-weight:500}
.user-link:hover{text-decoration:underline}

</style>
</head>
<body class="dashboard-body">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar"><div><button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><h2>Activity Log</h2><p><?= $totalItems ?> total entries</p></div></div>
<div class="content">

<form class="filter-row" method="get">
<select name="action">
<option value="">All Actions</option>
<?php foreach ($actionTypes as $at): ?>
<option value="<?= htmlspecialchars($at) ?>"<?= $actionFilter === $at ? ' selected' : '' ?>><?= htmlspecialchars($at) ?></option>
<?php endforeach; ?>
</select>
<select name="user_id">
<option value="">All Users</option>
<?php foreach ($users as $u): ?>
<option value="<?= $u['id'] ?>"<?= $userId === $u['id'] ? ' selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
<?php endforeach; ?>
</select>
<button class="btn btn-primary" type="submit">Filter</button>
<?php if ($actionFilter || $userId): ?><a href="activity.php" class="btn">Clear</a><?php endif; ?>
</form>

<div class="card">
<div class="card-head"><h3>System Activity</h3></div>
<div class="table-responsive">
<table>
<thead><tr><th>User</th><th>Action</th><th>Description</th><th>Date</th></tr></thead>
<tbody>
<?php if (count($activities) > 0): ?>
<?php foreach ($activities as $a): ?>
<tr>
<td><a href="user-view.php?id=<?= $a['user_id'] ?>" class="user-link"><?= htmlspecialchars($a['user_name'] ?? 'Unknown') ?></a></td>
<td><span style="font-weight:600"><?= htmlspecialchars(str_replace('_', ' ', $a['action_type'])) ?></span></td>
<td><?= htmlspecialchars($a['description']) ?></td>
<td><?= date('M j, Y g:i A', strtotime($a['created_at'])) ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--text-muted)">No activity found</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
<?php for ($i = 1; $i <= $totalPages; $i++): ?>
<a href="?page=<?= $i ?>&action=<?= urlencode($actionFilter) ?>&user_id=<?= $userId ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
<?php endfor; ?>
</div>
<?php endif; ?>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>