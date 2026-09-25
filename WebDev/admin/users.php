<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'users';

if (!adminCan('users')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];
if ($search) {
    $where = "WHERE u.full_name LIKE ? OR u.business_name LIKE ? OR u.email LIKE ?";
    $s = "%$search%";
    $params = [$s, $s, $s];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalUsers / $perPage));

$stmt = $pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM invoices WHERE user_id = u.id) as invoice_count, (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = u.id AND status = 'paid') as revenue FROM users u $where ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    validate_csrf();
    $uid = intval($_POST['user_id']);
    $action = $_POST['action'];
    $stmt2 = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt2->execute([$uid]);
    $target = $stmt2->fetch();
    if ($target) {
        if ($action === 'toggle_suspend') {
            $new = $target['suspended'] ? 0 : 1;
            $pdo->prepare("UPDATE users SET suspended = ? WHERE id = ?")->execute([$new, $uid]);
            logActivity($pdo, $_SESSION['user_id'], 'admin_' . ($new ? 'suspend' : 'unsuspend'), "Admin " . ($new ? 'suspended' : 'unsuspended') . " {$target['full_name']}", 'user', $uid);
            addNotification($pdo, $uid, $new ? 'Account Suspended' : 'Account Reactivated', $new
                ? "Your account has been suspended by an administrator. Contact support if you believe this is a mistake."
                : "Your account has been reactivated. Welcome back!");
            $_SESSION['flash'] = $new ? 'User suspended.' : 'User activated.';
        }
        if ($action === 'toggle_plan') {
            $proPid = proPlanId($pdo);
            $freePid = freePlanId($pdo);
            $isProNow = ($proPid > 0 && (int)($target['plan_id'] ?? 0) === $proPid);
            $new_plan = $isProNow ? $freePid : ($proPid > 0 ? $proPid : $freePid);
            $pdo->prepare("UPDATE users SET plan_id = ?, plan_updated_at = NOW(), plan_expires_at = NULL WHERE id = ?")->execute([$new_plan, $uid]);
            if ($new_plan !== ($proPid > 0 ? $proPid : -1)) clearPremiumSelections($pdo, $uid);
            logActivity($pdo, $_SESSION['user_id'], 'admin_plan_change', "Admin set {$target['full_name']} to " . ($isProNow ? 'Free' : 'Pro'), 'user', $uid);
            addNotification($pdo, $uid, 'Plan Changed', "Your plan has been changed to " . ($isProNow ? 'Free' : 'Pro') . " by an administrator.");
            $_SESSION['flash'] = "{$target['full_name']} is now " . ($isProNow ? 'Free.' : 'Pro.');
        }
    }
    header('Location: users.php' . ($search ? '?search=' . urlencode($search) : ''));
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Users</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.content{padding:28px 24px 0;flex:1}
.search-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.search-row input{flex:1;max-width:400px;padding:9px 14px;border:1px solid var(--border-input);border-radius:8px;font-size:13px;font-family:'Inter',sans-serif;outline:none;background:var(--bg-card)}
.search-row input:focus{border-color:var(--accent)}
.b-suspended{background:var(--error-bg);color:var(--error);border:1px solid rgba(239,68,68,.35)}
.b-admin{background:rgba(99,102,241,.12);color:#4338ca}
.b-free{background:var(--accent-bg);color:var(--accent)}
.pagination{display:flex;gap:6px;align-items:center;margin-top:12px}
.pagination a{padding:6px 12px;border:1px solid var(--border-input);border-radius:6px;text-decoration:none;color:var(--text);font-size:12px}
.pagination a.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.pagination a:hover:not(.active){background:var(--btn-hover)}
.user-cell{display:flex;align-items:center;gap:8px}
.avatar-init{width:28px;height:28px;border-radius:50%;background:var(--accent-bg);display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--accent);flex-shrink:0}
</style>
</head>
<body class="dashboard-body">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar">
<div><button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><h2>Users</h2><p><?= $totalUsers ?> total</p></div>
</div>
<div class="content">
<form class="search-row" method="get">
<input type="text" name="search" placeholder="Search by name, business, or email..." value="<?= htmlspecialchars($search) ?>"/>
<button class="btn btn-primary" type="submit">Search</button>
<?php if ($search): ?><a href="users.php" class="btn">Clear</a><?php endif; ?>
</form>

<?php if ($flash): ?><div style="background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card">
<div class="card-head"><h3>All Users</h3></div>
<div class="table-responsive">
<table>
<thead><tr><th>User</th><th>Business</th><th>Email</th><th>Plan</th><th>Invoices</th><th>Revenue</th><th>Role</th><th>Joined</th><th></th></tr></thead>
<tbody>
<?php if (count($users) > 0): ?>
<?php foreach ($users as $u): ?>
<tr>
<td><div class="user-cell"><span class="avatar-init"><?= htmlspecialchars(getAvatarInitials($u['full_name'])) ?></span> <?= htmlspecialchars($u['full_name']) ?></div></td>
<td><?= htmlspecialchars($u['business_name']) ?></td>
<td><?= htmlspecialchars($u['email']) ?></td>
            <td><?php $uProPid = proPlanId($pdo); if (!empty($u['suspended'])): ?><span class="badge b-suspended">Suspended</span><?php else: ?><span class="badge <?= $uProPid > 0 && (int)($u['plan_id'] ?? 0) === $uProPid ? 'b-pro' : 'b-free' ?>"><?= $uProPid > 0 && (int)($u['plan_id'] ?? 0) === $uProPid ? 'Pro' : 'Free' ?></span><?php endif; ?></td>
<td><?= $u['invoice_count'] ?></td>
<td>₦<?= number_format($u['revenue'], 2) ?></td>
<td><?= $u['role'] === 'admin' ? '<span class="badge b-admin">Admin</span>' : 'User' ?></td>
<td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
<td style="white-space:nowrap">
<a href="user-view.php?id=<?= $u['id'] ?>" class="btn" style="padding:4px 10px;font-size:11px">View</a>
<?php if ($u['role'] !== 'admin'): ?>
<form method="post" style="display:inline">
<?= csrf_field() ?>
<input type="hidden" name="action" value="toggle_plan">
<input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button class="btn" style="padding:4px 10px;font-size:11px;<?= ($uProPid > 0 && (int)($u['plan_id'] ?? 0) === $uProPid ? 'color:var(--accent)' : 'color:var(--success)') ?>"><?= ($uProPid > 0 && (int)($u['plan_id'] ?? 0) === $uProPid ? 'Make Free' : 'Make Pro') ?></button>
</form>
<form method="post" style="display:inline">
<?= csrf_field() ?>
<input type="hidden" name="action" value="toggle_suspend">
<input type="hidden" name="user_id" value="<?= $u['id'] ?>">
<button class="btn" style="padding:4px 10px;font-size:11px;color:<?= !empty($u['suspended']) ? 'var(--success)' : 'var(--error)' ?>"><?= !empty($u['suspended']) ? 'Activate' : 'Suspend' ?></button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--text-muted)">No users found</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
<?php for ($i = 1; $i <= $totalPages; $i++): ?>
<a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
<?php endfor; ?>
</div>
<?php endif; ?>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>