<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'team';

if (!adminCan('team')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$isOwnerActor = ($GLOBALS['admin_pages'] ?? null) === null;
$adminPageOptions = [
    'dashboard' => 'Dashboard',
    'users' => 'Users',
    'team' => 'Team',
    'notify' => 'Notifications',
    'plans' => 'Plans',
    'settings' => 'Settings',
    'help' => 'Help Requests',
    'activity' => 'Activity Log',
    'revenue' => 'Revenue',
    'upgrade_requests' => 'Upgrade Requests',
    'reviews' => 'Reviews',
];
$sensitiveKeys = ['users', 'settings', 'revenue', 'plans', 'upgrade_requests'];
$grantableOptions = $isOwnerActor
    ? $adminPageOptions
    : array_diff_key($adminPageOptions, array_flip($sensitiveKeys));

$editId = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$editMember = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$editId]);
    $editMember = $stmt->fetch();
    if ($editMember && (empty($editMember['admin_pages']) || $editMember['id'] === intval($_SESSION['user_id']))) {
        $editMember = null;
    }
}

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$errors = $_SESSION['form_errors'] ?? []; unset($_SESSION['form_errors']);
$old = $_SESSION['form_old'] ?? []; unset($_SESSION['form_old']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validate_csrf();
    $action = $_POST['action'];
    $me = intval($_SESSION['user_id']);

    if ($action === 'add') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $errs = [];
        if ($full_name === '' || $email === '' || $phone === '' || $password === '') {
            $errs[] = 'Please fill in all fields.';
        } elseif (strlen($password) < 8) {
            $errs[] = 'Password must be at least 8 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Please enter a valid email address.';
        }
        if (!$errs) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
            $stmt->execute([$email, $phone]);
            if ($stmt->fetch()) {
                $errs[] = 'An account with that email or phone already exists.';
            }
        }
        if ($errs) {
            $_SESSION['form_errors'] = $errs;
            $_SESSION['form_old'] = ['full_name' => $full_name, 'email' => $email, 'phone' => $phone];
            header('Location: team.php');
            exit;
        }
        $pages = array_values(array_intersect(
            array_keys(array_intersect_key(array_flip($_POST['pages'] ?? []), $adminPageOptions)),
            array_keys($grantableOptions)
        ));
        if (!in_array('dashboard', $pages, true)) {
            array_unshift($pages, 'dashboard');
        }
        $pagesJson = json_encode($pages);
        $stmt = $pdo->prepare("INSERT INTO users (full_name, business_name, business_abbr, email, phone, password, role, admin_pages) VALUES (?, 'BizVaza', 'BF', ?, ?, ?, 'admin', ?)");
        $stmt->execute([$full_name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $pagesJson]);
        logActivity($pdo, $me, 'admin_team_add', "Added team member {$full_name} ({$email})", 'user', $pdo->lastInsertId());
        $_SESSION['flash'] = 'Team member added.';
        header('Location: team.php');
        exit;
    }

    if ($action === 'update_permissions' && isset($_POST['member_id'])) {
        $uid = intval($_POST['member_id']);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
        $stmt->execute([$uid]);
        $target = $stmt->fetch();
        if ($target && !empty($target['admin_pages']) && $target['id'] !== $me) {
            $pages = array_values(array_intersect(
                array_keys(array_intersect_key(array_flip($_POST['pages'] ?? []), $adminPageOptions)),
                array_keys($grantableOptions)
            ));
            if (!in_array('dashboard', $pages, true)) {
                array_unshift($pages, 'dashboard');
            }
            $pdo->prepare("UPDATE users SET admin_pages = ? WHERE id = ?")->execute([json_encode($pages), $uid]);
            logActivity($pdo, $me, 'admin_team_permissions', "Updated permissions for {$target['full_name']}", 'user', $uid);
            addNotification($pdo, $uid, 'Permissions Updated', "Your admin permissions have been updated by an administrator.");
            $_SESSION['flash'] = 'Permissions updated.';
        }
        header('Location: team.php');
        exit;
    }

    if (($action === 'toggle_suspend' || $action === 'revoke') && isset($_POST['member_id'])) {
        $uid = intval($_POST['member_id']);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
        $stmt->execute([$uid]);
        $target = $stmt->fetch();
        if ($isOwnerActor && $target && !empty($target['admin_pages']) && $target['id'] !== $me) {
            if ($action === 'toggle_suspend') {
                $new = $target['suspended'] ? 0 : 1;
                $pdo->prepare("UPDATE users SET suspended = ? WHERE id = ?")->execute([$new, $uid]);
                logActivity($pdo, $me, 'admin_' . ($new ? 'suspend' : 'unsuspend'), "Admin " . ($new ? 'suspended' : 'unsuspended') . " team member {$target['full_name']}", 'user', $uid);
                addNotification($pdo, $uid, $new ? 'Account Suspended' : 'Account Reactivated', $new
                    ? "Your admin access has been suspended by an administrator."
                    : "Your admin access has been reactivated.");
                $_SESSION['flash'] = $new ? 'Team member suspended.' : 'Team member activated.';
            } else {
                $pdo->prepare("UPDATE users SET role = 'user', suspended = 1, admin_pages = NULL WHERE id = ?")->execute([$uid]);
                logActivity($pdo, $me, 'admin_team_remove', "Revoked admin access for {$target['full_name']}", 'user', $uid);
                addNotification($pdo, $uid, 'Access Revoked', "Your admin access has been revoked by an administrator.");
                $_SESSION['flash'] = 'Admin access revoked.';
            }
        }
        header('Location: team.php');
        exit;
    }

    header('Location: team.php');
    exit;
}

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'admin' ORDER BY (admin_pages IS NULL) DESC, created_at ASC");
$team = $stmt->fetchAll();
$me = intval($_SESSION['user_id']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Team</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.content{padding:28px 24px 0;flex:1}
.b-suspended{background:var(--error-bg);color:var(--error);border:1px solid rgba(239,68,68,.35)}
.b-admin{background:rgba(99,102,241,.12);color:#4338ca}
.b-owner{background:var(--success-bg);color:var(--success)}
.b-page{background:var(--accent-bg);color:var(--accent);margin:2px 2px 0 0}
.user-cell{display:flex;align-items:center;gap:8px}
.avatar-init{width:28px;height:28px;border-radius:50%;background:var(--accent-bg);display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--accent);flex-shrink:0}
.page-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin:8px 0 4px}
.page-grid label{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text);cursor:pointer}
.page-grid input{accent-color:var(--accent)}
.err-box{background:var(--error-bg);border:1px solid rgba(239,68,68,.35);color:var(--error);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
</style>
</head>
<body class="dashboard-body">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar">
<div><button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><h2>Team</h2><p><?= count($team) ?> member<?= count($team) === 1 ? '' : 's' ?></p></div>
</div>
<div class="content">

<?php if ($flash): ?><div style="background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="err-box"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if ($editMember): ?>
<div class="card" style="margin-bottom:20px">
<div class="card-head"><h3>Edit Permissions — <?= htmlspecialchars($editMember['full_name']) ?></h3><a href="team.php" class="btn" style="padding:4px 10px;font-size:11px">Cancel</a></div>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="update_permissions">
<input type="hidden" name="member_id" value="<?= $editMember['id'] ?>">
<div class="page-grid">
<?php $cur = json_decode($editMember['admin_pages'], true) ?: []; foreach ($adminPageOptions as $key => $label): if (!isset($grantableOptions[$key])) continue; ?>
<label><input type="checkbox" name="pages[]" value="<?= $key ?>"<?= in_array($key, $cur, true) ? ' checked' : '' ?><?= $key === 'dashboard' ? ' disabled' : '' ?>> <?= htmlspecialchars($label) ?></label>
<?php endforeach; ?>
</div>
<input type="hidden" name="pages[]" value="dashboard">
<div style="margin-top:12px"><button class="btn btn-primary" type="submit">Save Permissions</button></div>
</form>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px">
<div class="card-head"><h3>Add Team Member</h3></div>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="add">
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:4px">
<div class="form-group">
<label>Full Name</label>
<input type="text" name="full_name" placeholder="Jane Doe" required value="<?= htmlspecialchars($old['full_name'] ?? '') ?>"/>
</div>
<div class="form-group">
<label>Email</label>
<input type="email" name="email" placeholder="jane@bizvaza.com" required value="<?= htmlspecialchars($old['email'] ?? '') ?>"/>
</div>
<div class="form-group">
<label>Phone</label>
<input type="tel" name="phone" placeholder="08000000000" required value="<?= htmlspecialchars($old['phone'] ?? '') ?>"/>
</div>
<div class="form-group">
<label>Password</label>
            <input type="password" name="password" placeholder="Min 8 characters" required minlength="8"/>
</div>
</div>
<div style="font-size:13px;font-weight:600;color:var(--text);margin:6px 0 2px">Page access</div>
<div class="page-grid">
<?php foreach ($adminPageOptions as $key => $label): if (!isset($grantableOptions[$key])) continue; ?>
<label><input type="checkbox" name="pages[]" value="<?= $key ?>"<?= $key === 'dashboard' ? ' checked disabled' : '' ?>> <?= htmlspecialchars($label) ?></label>
<?php endforeach; ?>
</div>
<input type="hidden" name="pages[]" value="dashboard">
<div style="margin-top:14px"><button class="btn btn-primary" type="submit">Add Member</button></div>
</form>
</div>

<div class="card">
<div class="card-head"><h3>Team Members</h3></div>
<div class="table-responsive">
<table>
<thead><tr><th>Member</th><th>Email</th><th>Access</th><th>Last Active</th><th>Joined</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php if (count($team) > 0): ?>
<?php foreach ($team as $m): $isOwner = empty($m['admin_pages']); $isMe = intval($m['id']) === $me; $pages = $isOwner ? array_keys($adminPageOptions) : (json_decode($m['admin_pages'], true) ?: []); ?>
<tr>
<td><div class="user-cell"><span class="avatar-init"><?= htmlspecialchars(getAvatarInitials($m['full_name'])) ?></span> <?= htmlspecialchars($m['full_name']) ?><?= $isMe ? ' <span class="badge b-admin">You</span>' : '' ?></div></td>
<td><?= htmlspecialchars($m['email']) ?></td>
<td><?php if ($isOwner): ?><span class="badge b-owner">Owner</span><?php else: ?><?php foreach (array_slice($pages, 0, 4) as $p): ?><span class="badge b-page"><?= htmlspecialchars($adminPageOptions[$p] ?? $p) ?></span><?php endforeach; ?><?php if (count($pages) > 4): ?><span class="badge b-page">+<?= count($pages) - 4 ?></span><?php endif; ?><?php endif; ?></td>
<td><?= $m['last_active'] ? date('M j, Y g:i A', strtotime($m['last_active'])) : '—' ?></td>
<td><?= date('M j, Y', strtotime($m['created_at'])) ?></td>
<td><?php if (!empty($m['suspended'])): ?><span class="badge b-suspended">Suspended</span><?php else: ?><span class="badge" style="background:var(--success-bg);color:var(--success)">Active</span><?php endif; ?></td>
<td style="white-space:nowrap">
<a href="user-view.php?id=<?= $m['id'] ?>" class="btn" style="padding:4px 10px;font-size:11px">View</a>
<?php if (!$isOwner && !$isMe): ?>
<a href="team.php?edit=<?= $m['id'] ?>" class="btn" style="padding:4px 10px;font-size:11px">Edit Access</a>
<form method="post" style="display:inline">
<?= csrf_field() ?>
<input type="hidden" name="action" value="toggle_suspend">
<input type="hidden" name="member_id" value="<?= $m['id'] ?>">
<button class="btn" style="padding:4px 10px;font-size:11px;color:<?= !empty($m['suspended']) ? 'var(--success)' : 'var(--error)' ?>"><?= !empty($m['suspended']) ? 'Activate' : 'Suspend' ?></button>
</form>
<form method="post" style="display:inline" onsubmit="return confirm('Revoke admin access for <?= htmlspecialchars(addslashes($m['full_name'])) ?>?')">
<?= csrf_field() ?>
<input type="hidden" name="action" value="revoke">
<input type="hidden" name="member_id" value="<?= $m['id'] ?>">
<button class="btn" style="padding:4px 10px;font-size:11px;color:var(--error)">Revoke</button>
</form>
<?php elseif ($isOwner): ?>
<span style="font-size:11px;color:var(--text-muted)">Protected</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted)">No team members yet</td></tr>
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
