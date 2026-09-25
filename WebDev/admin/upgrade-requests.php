<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'upgrade_requests';

if (!adminCan('upgrade_requests')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$flash = '';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    validate_csrf();
    $id = intval($_POST['id']);
    $action = $_POST['action'];

    if ($action === 'approve') {
        $stmt = $pdo->prepare("SELECT sp.*, u.full_name FROM subscription_payments sp JOIN users u ON sp.user_id = u.id WHERE sp.id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch();

        if ($req && $req['status'] === 'pending') {
            // Approve - activate Pro
            $proId = proPlanId($pdo);
            if ($proId <= 0) {
                $flash = 'Pro plan is not configured.';
            } else {
            $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
            $pdo->prepare("UPDATE users SET plan_id = ?, plan_updated_at = NOW(), plan_expires_at = ? WHERE id = ?")->execute([$proId, $expiry, $req['user_id']]);
            $pdo->prepare("UPDATE subscription_payments SET status = 'approved', verified_by = ?, verified_at = NOW(), admin_note = 'Approved by admin' WHERE id = ?")->execute([$_SESSION['user_id'], $id]);

            logActivity($pdo, $_SESSION['user_id'], 'admin_upgrade_approved', "Admin approved Pro upgrade for {$req['full_name']}", 'subscription', $id);
            addNotification($pdo, $req['user_id'], 'Upgrade Approved', "Your Pro upgrade was approved! Pro features are active until " . date('M j, Y', strtotime($expiry)) . ".");
            $flash = "Approved! {$req['full_name']} is now on Pro.";
            }
        }
    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("SELECT sp.*, u.full_name FROM subscription_payments sp JOIN users u ON sp.user_id = u.id WHERE sp.id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch();

        if ($req && $req['status'] === 'pending') {
            $reason = $_GET['reason'] ?? 'Payment not verified';
            $pdo->prepare("UPDATE subscription_payments SET status = 'rejected', verified_by = ?, verified_at = NOW(), admin_note = ? WHERE id = ?")->execute([$_SESSION['user_id'], $reason, $id]);
            logActivity($pdo, $_SESSION['user_id'], 'admin_upgrade_rejected', "Admin rejected Pro upgrade for {$req['full_name']}: {$reason}", 'subscription', $id);
            addNotification($pdo, $req['user_id'], 'Upgrade Rejected', "Your Pro upgrade request was rejected: {$reason}. If you believe this is a mistake, please contact support.");
            $flash = "Rejected upgrade request for {$req['full_name']}.";
        }
    }
    header('Location: upgrade-requests.php' . ($flash ? '?flash=' . urlencode($flash) : ''));
    exit;
}

$flash = $_GET['flash'] ?? '';

$requests = $pdo->query("SELECT sp.*, u.full_name, u.email, u.business_name FROM subscription_payments sp JOIN users u ON sp.user_id = u.id ORDER BY sp.created_at DESC")->fetchAll();
$pendingCount = 0;
foreach ($requests as $r) { if ($r['status'] === 'pending') $pendingCount++; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Upgrade Requests</title>
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
.flash{background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
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
.b-approved{background:var(--success-bg);color:var(--success)}
.b-rejected{background:var(--error-bg);color:var(--error)}
.b-paystack{background:var(--purple-bg);color:var(--purple)}
.b-bank{background:rgba(2,132,199,.12);color:#0369a1}
.btn{display:inline-flex;align-items:center;padding:5px 12px;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer;border:1px solid var(--border-input);background:var(--bg-card);color:var(--text);text-decoration:none;font-family:'Inter',sans-serif}
.btn:hover{background:var(--btn-hover)}
.btn-approve{background:var(--success);color:#fff;border-color:var(--success)}
.btn-approve:hover{background:#047857}
.btn-reject{background:var(--error);color:#fff;border-color:var(--error)}
.btn-reject:hover{background:#b91c1c}
.proof-link{color:var(--accent);text-decoration:none;font-weight:500}
.proof-link:hover{text-decoration:underline}
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;display:flex;align-items:center;gap:12px}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.si-orange{background:var(--accent-bg)}
.si-green{background:var(--success-bg)}
.si-purple{background:var(--purple-bg)}
.stat-label{font-size:11px;color:var(--text-muted);display:block}
.stat-val{font-size:20px;font-weight:700;color:var(--text)}
@media(max-width:700px){.stat-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar">
<div><button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><h2>Upgrade Requests</h2><p>Manage Pro upgrade payments</p></div>
</div>
<div class="content">
<?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="stat-grid">
<div class="stat-card">
<div class="stat-icon si-orange">
<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
</div>
<div><span class="stat-label">Pending</span><div class="stat-val"><?= $pendingCount ?></div></div>
</div>
<div class="stat-card">
<div class="stat-icon si-green">
<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
</div>
<div><span class="stat-label">Total Requests</span><div class="stat-val"><?= count($requests) ?></div></div>
</div>
<div class="stat-card">
<div class="stat-icon si-purple">
<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--purple)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
</div>
<div><span class="stat-label">Revenue</span><div class="stat-val">₦<?= number_format(array_sum(array_map(function($r) { return $r['status'] === 'approved' ? $r['amount'] : 0; }, $requests)), 0) ?></div></div>
</div>
</div>

<div class="card">
<div class="card-head"><h3>All Requests</h3></div>
<div class="table-responsive">
<table>
<thead><tr><th>User</th><th>Amount</th><th>Method</th><th>Status</th><th>Proof</th><th>Date</th><th></th></tr></thead>
<tbody>
<?php if (count($requests) > 0): ?>
<?php foreach ($requests as $r): ?>
<tr>
<td>
<div style="font-weight:600"><?= htmlspecialchars($r['full_name']) ?></div>
<div style="font-size:11px;color:var(--text-light)"><?= htmlspecialchars($r['email']) ?></div>
</td>
<td style="font-weight:600">₦<?= number_format($r['amount'], 2) ?></td>
<td><span class="badge <?= $r['method'] === 'paystack' ? 'b-paystack' : 'b-bank' ?>"><?= $r['method'] === 'paystack' ? 'Paystack' : 'Bank Transfer' ?></span></td>
<td><span class="badge b-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
<td>
<?php if ($r['proof_path']): ?>
<a href="../uploads/<?= htmlspecialchars($r['proof_path']) ?>" target="_blank" class="proof-link">View Proof</a>
<?php elseif ($r['paystack_reference']): ?>
<span style="font-size:11px;color:var(--text-light)"><?= htmlspecialchars($r['paystack_reference']) ?></span>
<?php else: ?>
<span style="color:var(--text-muted)">—</span>
<?php endif; ?>
</td>
<td><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></td>
<td style="white-space:nowrap">
<?php if ($r['status'] === 'pending'): ?>
<form method="POST" style="display:inline">
<?= csrf_field() ?>
<input type="hidden" name="id" value="<?= $r['id'] ?>">
<button type="submit" name="action" value="approve" class="btn btn-approve" onclick="return confirm('Approve this upgrade?')">Approve</button>
</form>
<form method="POST" style="display:inline">
<?= csrf_field() ?>
<input type="hidden" name="id" value="<?= $r['id'] ?>">
<button type="submit" name="action" value="reject" class="btn btn-reject" onclick="return confirm('Reject this upgrade?')">Reject</button>
</form>
<?php else: ?>
<span style="font-size:11px;color:var(--text-light)"><?= htmlspecialchars($r['admin_note'] ?? '—') ?></span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted)">No upgrade requests yet</td></tr>
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
