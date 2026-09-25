<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'help';

if (!adminCan('help')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status']) && isset($_POST['id'])) {
    validate_csrf();
    $id = intval($_POST['id']);
    $status = $_POST['status'];
    if (in_array($status, ['contacted', 'resolved'])) {
        $pdo->prepare("UPDATE help_requests SET status = ? WHERE id = ?")->execute([$status, $id]);
        logActivity($pdo, $_SESSION['user_id'], 'admin_help_' . $status, "Marked help request #{$id} as {$status}");
        $_SESSION['flash'] = 'Help request marked as ' . $status . '.';
    }
    header('Location: help.php');
    exit;
}

$stmt = $pdo->query("SELECT h.*, u.full_name as user_name FROM help_requests h LEFT JOIN users u ON h.user_id = u.id ORDER BY h.created_at DESC");
$requests = $stmt->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Help Requests</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);display:flex;min-height:100vh;font-size:13px;color:var(--text)}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--topbar-bg);border-bottom:1px solid var(--border);padding:16px 28px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.topbar h2{font-size:18px;font-weight:600;color:var(--text)}
.topbar p{font-size:12px;color:var(--text-muted);margin-top:2px}
.content{padding:28px 24px 0;flex:1}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 16px;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.card-head h3{font-size:14px;font-weight:600;color:var(--text)}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;color:var(--text-muted);font-weight:500;text-align:left;padding:8px 10px;background:var(--table-header);border-bottom:1px solid var(--table-border);white-space:nowrap}
tbody td{padding:10px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--table-border)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--bg-hover)}
.badge{display:inline-block;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:600}
.b-pending{background:var(--accent-bg);color:var(--accent)}
.b-contacted{background:rgba(2,132,199,.12);color:#0369a1}
.b-resolved{background:var(--success-bg);color:var(--success)}
.btn{display:inline-flex;padding:4px 10px;border-radius:5px;font-size:10px;font-weight:500;cursor:pointer;border:1px solid var(--border-input);background:var(--bg-card);color:var(--text);text-decoration:none;font-family:'Inter',sans-serif}
.btn:hover{background:var(--btn-hover)}
.msg-cell{max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-light)}

</style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar"><div><button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><h2>Help Requests</h2><p><?= count($requests) ?> total</p></div></div>
<div class="content">
<?php $flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']); if ($flash): ?><div style="background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card">
<div class="card-head"><h3>All Requests</h3></div>
<div class="table-responsive">
<table>
<thead><tr><th>Name</th><th>Phone</th><th>Message</th><th>Callback</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
<tbody>
<?php if (count($requests) > 0): ?>
<?php foreach ($requests as $r): ?>
<tr>
<td><?= htmlspecialchars($r['name']) ?><?= $r['user_name'] ? ' <span style="color:var(--text-light);font-size:10px">(' . htmlspecialchars($r['user_name']) . ')</span>' : '' ?></td>
<td><?= htmlspecialchars($r['phone']) ?></td>
<td class="msg-cell" title="<?= htmlspecialchars($r['message'] ?? '') ?>"><?= htmlspecialchars($r['message'] ?? '—') ?></td>
<td><?= $r['callback_date'] ? date('M j', strtotime($r['callback_date'])) . ($r['callback_time'] ? ' ' . substr($r['callback_time'], 0, 5) : '') : '—' ?></td>
<td><span class="badge b-<?= $r['status'] ?? 'pending' ?>"><?= $r['status'] ?? 'pending' ?></span></td>
<td><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></td>
<td>
<?php if (($r['status'] ?? 'pending') === 'pending'): ?>
<form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button type="submit" name="status" value="contacted" class="btn">Contacted</button></form>
<?php endif; ?>
<?php if (($r['status'] ?? 'pending') !== 'resolved'): ?>
<form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button type="submit" name="status" value="resolved" class="btn">Resolved</button></form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted)">No help requests</td></tr>
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