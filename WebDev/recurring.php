<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/recurring-runner.php';
$activePage = 'recurring';
$user_id = $_SESSION['user_id'];
$avatar_initials = $_SESSION['avatar_initials'];

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['rec_action'] ?? '';
    $rid = intval($_POST['rec_id'] ?? 0);

    if ($action === 'toggle' && $rid > 0) {
        $stmt = $pdo->prepare("SELECT id, status FROM recurring_invoices WHERE id = ? AND user_id = ?");
        $stmt->execute([$rid, $user_id]);
        $rec = $stmt->fetch();
        if ($rec) {
            $newStatus = $rec['status'] === 'active' ? 'paused' : 'active';
            $stmt = $pdo->prepare("UPDATE recurring_invoices SET status = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$newStatus, $rid, $user_id]);
            logActivity($pdo, $user_id, 'recurring_updated', "Recurring invoice #{$rid} " . ($newStatus === 'active' ? 'resumed' : 'paused'), 'recurring', $rid);
            $_SESSION['flash'] = 'Recurring invoice ' . ($newStatus === 'active' ? 'resumed' : 'paused') . '.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: recurring.php');
        exit;
    }

    if ($action === 'delete' && $rid > 0) {
        $stmt = $pdo->prepare("DELETE FROM recurring_invoices WHERE id = ? AND user_id = ?");
        $stmt->execute([$rid, $user_id]);
        if ($stmt->rowCount() > 0) {
            logActivity($pdo, $user_id, 'recurring_deleted', "Recurring invoice #{$rid} deleted", 'recurring', $rid);
            $_SESSION['flash'] = 'Recurring invoice deleted.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: recurring.php');
        exit;
    }

    if ($action === 'run' && $rid > 0) {
        $result = generateRecurringById($pdo, $user_id, $rid);
        if ($result['ok']) {
            $_SESSION['flash'] = 'Invoice ' . $result['invoice'] . ' generated.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash'] = $result['error'];
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: recurring.php');
        exit;
    }

    if ($action === 'run_all') {
        $result = generateRecurringInvoices($pdo, $user_id, 20);
        $n = count($result['generated']);
        $_SESSION['flash'] = $n > 0 ? $n . ' invoice(s) generated.' : 'No recurring invoices are due yet.';
        $_SESSION['flash_type'] = $n > 0 ? 'success' : 'info';
        header('Location: recurring.php');
        exit;
    }
}

// Fetch recurring schedules
$stmt = $pdo->prepare("SELECT * FROM recurring_invoices WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$recurrings = $stmt->fetchAll();

$activeCount = 0;
foreach ($recurrings as $r) { if ($r['status'] === 'active') $activeCount++; }

$freqLabels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_token() ?>">
<title>BizVaza – Recurring Invoices</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
.rec-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.rec-stat{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px}
.rec-stat .lbl{font-size:11px;color:var(--text-muted);letter-spacing:.3px;display:block;margin-bottom:6px}
.rec-stat .val{font-size:22px;font-weight:700;color:var(--text)}
.rec-content{margin-top:20px}
.inv-table{width:100%;border-collapse:collapse}
.inv-table thead th{font-size:11px;color:var(--text-muted);font-weight:500;text-align:left;padding:8px 10px;background:var(--table-header);border-bottom:1px solid var(--table-border);white-space:nowrap}
.inv-table tbody td{padding:10px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--border-light)}
.inv-table tbody tr:last-child td{border-bottom:none}
.inv-table tbody tr:hover{background:var(--table-hover)}
@media(max-width:768px){.rec-grid{grid-template-columns:1fr}}
</style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Recurring Invoices</h2>
<p>Automatically generate invoices on a schedule</p>
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

<div class="invoice-content">

<?php if (isset($_SESSION['flash'])): ?>
<div style="background:<?= ($_SESSION['flash_type'] ?? 'success') === 'error' ? 'var(--error-bg);color:var(--error);border:1px solid rgba(239,68,68,.35)' : (($_SESSION['flash_type'] ?? 'success') === 'info' ? '#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe' : 'var(--success-bg);color:var(--success);border:1px solid #a7f3d0') ?>;padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px"><?= htmlspecialchars($_SESSION['flash']) ?></div>
<?php unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="rec-grid">
<div class="rec-stat">
<span class="lbl">Active Schedules</span>
<span class="val"><?= $activeCount ?></span>
</div>
<div class="rec-stat">
<span class="lbl">Total Schedules</span>
<span class="val"><?= count($recurrings) ?></span>
</div>
<div class="rec-stat">
<span class="lbl">Next Auto-Run</span>
<span class="val" style="font-size:15px;line-height:1.5">
<?php
$soonest = null;
foreach ($recurrings as $r) { if ($r['status'] === 'active') { $d = $r['next_run_date']; if ($soonest === null || $d < $soonest) $soonest = $d; } }
echo $soonest ? date('M j, Y', strtotime($soonest)) : '—';
?>
</span>
</div>
</div>

<div class="invoices-list-section">
<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px">
<h2 style="margin:0">All Recurring Invoices</h2>
<form method="POST" style="margin:0">
<?= csrf_field() ?>
<input type="hidden" name="rec_action" value="run_all">
<button type="submit" class="btn-create-invoice" style="margin:0">Run Due Now</button>
</form>
</div>
<p style="font-size:12px;color:var(--text-muted);margin:0 0 14px">Enabled when you check "Make this a recurring invoice" while creating an invoice. Set up a scheduled task to hit <code style="background:#f1f3f7;border-radius:4px;padding:1px 5px">cron.php?key=YOUR_KEY</code> for automatic generation.</p>

<?php if (count($recurrings) > 0): ?>
<div class="table-responsive">
<table class="inv-table">
<thead>
<tr><th>Title</th><th>Customer</th><th>Frequency</th><th>Amount</th><th>Next Run</th><th>Last Generated</th><th>Status</th><th>Action</th></tr>
</thead>
<tbody>
<?php foreach ($recurrings as $rec): ?>
<tr>
<td><?= htmlspecialchars($rec['title']) ?></td>
<td><?= htmlspecialchars($rec['customer_name']) ?></td>
<td><?= $freqLabels[$rec['frequency']] ?? ucfirst($rec['frequency']) ?><?= (int)$rec['interval_count'] > 1 ? ' x' . (int)$rec['interval_count'] : '' ?></td>
<td>₦<?= number_format($rec['amount'], 2) ?></td>
<td><?= date('M j, Y', strtotime($rec['next_run_date'])) ?></td>
<td><?= $rec['last_generated_at'] ? date('M j, Y', strtotime($rec['last_generated_at'])) : '—' ?></td>
<td><span class="badge <?= $rec['status'] === 'active' ? 'b-success' : 'b-partial' ?>"><?= ucfirst($rec['status']) ?></span></td>
<td style="display:flex;gap:6px;align-items:center">
<form method="POST" style="margin:0">
<?= csrf_field() ?>
<input type="hidden" name="rec_action" value="run">
<input type="hidden" name="rec_id" value="<?= $rec['id'] ?>">
<button type="submit" class="btn-icon" title="Generate invoice now"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg></button>
</form>
<form method="POST" style="margin:0">
<?= csrf_field() ?>
<input type="hidden" name="rec_action" value="toggle">
<input type="hidden" name="rec_id" value="<?= $rec['id'] ?>">
<button type="submit" class="btn-icon" title="<?= $rec['status'] === 'active' ? 'Pause' : 'Resume' ?>"><?= $rec['status'] === 'active' ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>' : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>' ?></button>
</form>
<form method="POST" style="margin:0" onsubmit="return confirm('Delete this recurring invoice schedule? Existing invoices are kept.')">
<?= csrf_field() ?>
<input type="hidden" name="rec_action" value="delete">
<input type="hidden" name="rec_id" value="<?= $rec['id'] ?>">
<button type="submit" class="btn-icon del" title="Delete"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div style="padding:32px 16px;text-align:center;font-size:13px;color:var(--text-muted)">No recurring invoices yet. Create an invoice and tick "Make this a recurring invoice" to start scheduling.</div>
<?php endif; ?>
</div>

</div>
<script src="assets/js/app.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</body>
</html>