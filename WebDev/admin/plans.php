<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'plans';

if (!adminCan('plans')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    validate_csrf();
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name']);
    $slug = trim($_POST['slug']);
    $price = floatval($_POST['price']);
    $badge = trim($_POST['badge']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $features = trim($_POST['features']);
    json_decode($features);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $flash = 'Invalid features JSON.';
    } elseif ($id > 0) {
        $pdo->prepare("UPDATE plans SET name=?, slug=?, price=?, badge=?, is_active=?, features=? WHERE id=?")->execute([$name, $slug, $price, $badge, $is_active, $features, $id]);
        logActivity($pdo, $_SESSION['user_id'], 'admin_plan_updated', "Updated plan {$name} (₦" . number_format($price, 2) . ")", 'plan', $id);
        $flash = 'Plan updated.';
    } else {
        $pdo->prepare("INSERT INTO plans (name, slug, price, badge, is_active, features) VALUES (?,?,?,?,?,?)")->execute([$name, $slug, $price, $badge, $is_active, $features]);
        logActivity($pdo, $_SESSION['user_id'], 'admin_plan_created', "Created plan {$name} (₦" . number_format($price, 2) . ")", 'plan', $pdo->lastInsertId());
        $flash = 'Plan created.';
    }
}
$editPlan = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    validate_csrf();
    $delId = intval($_POST['delete']);
    $inUse = $pdo->prepare("SELECT COUNT(*) FROM users WHERE plan_id = ?");
    $inUse->execute([$delId]);
    if ((int)$inUse->fetchColumn() > 0) {
        $flash = 'Cannot delete a plan that users are currently on.';
    } else {
        $nameStmt = $pdo->prepare("SELECT name FROM plans WHERE id = ?");
        $nameStmt->execute([$delId]);
        $delName = $nameStmt->fetchColumn();
        $pdo->prepare("DELETE FROM plans WHERE id = ?")->execute([$delId]);
        logActivity($pdo, $_SESSION['user_id'], 'admin_plan_deleted', "Deleted plan " . ($delName ?: "#{$delId}"), 'plan', $delId);
        $flash = 'Plan deleted.';
    }
}
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->execute([intval($_GET['edit'])]);
    $editPlan = $stmt->fetch();
}
$plans = $pdo->query("SELECT * FROM plans ORDER BY price")->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Plans</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);display:flex;min-height:100vh;font-size:13px;color:var(--text)}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--topbar-bg);border-bottom:1px solid var(--border);padding:16px 28px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.topbar h2{font-size:18px;font-weight:600;color:var(--text)}
.content{padding:28px 24px 0;flex:1;max-width:800px}
.flash{background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
.flash.error{background:var(--error-bg);border-color:rgba(239,68,68,.35);color:var(--error)}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 16px;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.card-head h3{font-size:14px;font-weight:600;color:var(--text)}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;color:var(--text-muted);font-weight:500;text-align:left;padding:8px 10px;background:var(--table-header);border-bottom:1px solid var(--table-border);white-space:nowrap}
tbody td{padding:10px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--table-border)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--bg-hover)}
.btn{display:inline-flex;align-items:center;padding:7px 14px;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border-input);background:var(--bg-card);color:var(--text);text-decoration:none;font-family:'Inter',sans-serif}
.btn:hover{background:var(--btn-hover)}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-hover)}
.btn-danger{background:var(--error);color:#fff;border-color:var(--error)}
.btn-danger:hover{background:#b91c1c}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.form-grid .full{grid-column:1/-1}
.form-grid label{display:block;font-size:11px;font-weight:500;color:var(--text-light);margin-bottom:3px;margin-top:4px}
.form-grid input,.form-grid textarea{width:100%;padding:7px 10px;border:1px solid var(--border-input);border-radius:6px;font-size:12px;font-family:'Inter',sans-serif;outline:none}
.form-grid input:focus,.form-grid textarea:focus{border-color:var(--accent)}
.check-row{display:flex;align-items:center;gap:6px;margin-top:8px}
.check-row input{width:auto}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar"><div><button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><h2>Plans</h2></div></div>
<div class="content">
<?php if ($flash): ?><div class="flash<?= strpos($flash, 'Invalid') !== false || strpos($flash, 'Cannot') !== false ? ' error' : '' ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card">
<div class="card-head"><h3>All Plans</h3></div>
<div class="table-responsive">
<table>
<thead><tr><th>Name</th><th>Slug</th><th>Price</th><th>Badge</th><th>Active</th><th></th></tr></thead>
<tbody>
<?php foreach ($plans as $p): ?>
<tr>
<td><?= htmlspecialchars($p['name']) ?></td>
<td><?= htmlspecialchars($p['slug']) ?></td>
<td>₦<?= number_format($p['price'], 2) ?></td>
<td><?= htmlspecialchars($p['badge']) ?></td>
<td><?= $p['is_active'] ? 'Yes' : 'No' ?></td>
<td>
<a href="?edit=<?= $p['id'] ?>" class="btn">Edit</a>
<form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="delete" value="<?= $p['id'] ?>"><button type="submit" class="btn btn-danger" onclick="return confirm('Delete this plan?')">Delete</button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<div class="card">
<div class="card-head"><h3><?= $editPlan ? 'Edit Plan' : 'Add New Plan' ?></h3></div>
<form method="post">
<?= csrf_field() ?>
<?php if ($editPlan): ?><input type="hidden" name="id" value="<?= $editPlan['id'] ?>"><?php endif; ?>
<div class="form-grid">
<div><label>Name</label><input type="text" name="name" value="<?= htmlspecialchars($editPlan['name'] ?? '') ?>" required></div>
<div><label>Slug</label><input type="text" name="slug" value="<?= htmlspecialchars($editPlan['slug'] ?? '') ?>" required></div>
<div><label>Price (₦)</label><input type="number" step="0.01" name="price" value="<?= htmlspecialchars($editPlan['price'] ?? '0') ?>"></div>
<div><label>Badge</label><input type="text" name="badge" value="<?= htmlspecialchars($editPlan['badge'] ?? '') ?>" placeholder="e.g. Popular"></div>
<div class="full"><label>Features (JSON array)</label><textarea name="features" rows="3"><?= htmlspecialchars($editPlan['features'] ?? '[]') ?></textarea></div>
<div class="full check-row"><input type="checkbox" name="is_active" id="ia"<?= !isset($editPlan) || $editPlan['is_active'] ? ' checked' : '' ?>><label for="ia">Active</label></div>
<div class="full"><button class="btn btn-primary" type="submit" name="save"><?= $editPlan ? 'Update' : 'Create' ?> Plan</button>
<?php if ($editPlan): ?> <a href="plans.php" class="btn">Cancel</a><?php endif; ?>
</div>
</div>
</form>
</div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>