<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
$activePage = 'reviews';

if (!adminCan('reviews')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$filter = $_GET['status'] ?? '';
$where = '';
$params = [];
if ($filter && in_array($filter, ['pending', 'approved', 'rejected'])) {
    $where = "WHERE r.status = ?";
    $params = [$filter];
}

$stmt = $pdo->prepare("SELECT r.*, u.full_name, u.business_name, u.email FROM reviews r JOIN users u ON r.user_id = u.id $where ORDER BY r.created_at DESC");
$stmt->execute($params);
$reviews = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['review_id'])) {
    validate_csrf();
    $rid = intval($_POST['review_id']);
    $action = $_POST['action'];
    if ($action === 'approve') {
        $pdo->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?")->execute([$rid]);
        $_SESSION['flash'] = 'Review approved.';
    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?")->execute([$rid]);
        $_SESSION['flash'] = 'Review rejected.';
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$rid]);
        $_SESSION['flash'] = 'Review deleted.';
    }
    logActivity($pdo, $_SESSION['user_id'], 'admin_review_' . $action, "Admin {$action}d review #{$rid}");
    header('Location: reviews.php' . ($filter ? '?status=' . urlencode($filter) : ''));
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza – Review Management</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.page-area{flex:1;padding:24px 28px;display:flex;flex-direction:column;gap:20px}
.page-header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.page-header h1{font-size:20px;font-weight:800;color:var(--text)}
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.filter-bar a{display:inline-block;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;color:var(--text-light);background:var(--bg-card-alt);transition:.1s}
.filter-bar a.active{color:#fff;background:var(--accent)}
.flash{padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;background:var(--success-bg);color:#065f46;border:1px solid #a7f3d0;margin-bottom:4px}
.reviews-table{width:100%;border-collapse:collapse;font-size:13px}
.reviews-table th{text-align:left;padding:10px 12px;background:var(--table-header);color:var(--text-light);font-weight:600;border-bottom:2px solid var(--table-border);white-space:nowrap}
.reviews-table td{padding:10px 12px;border-bottom:1px solid var(--table-border);vertical-align:top}
.reviews-table tr:hover td{background:var(--bg-hover)}
.stars{display:flex;gap:2px}
.stars svg{width:14px;height:14px}
.review-text{max-width:320px;line-height:1.5;color:var(--text-secondary);word-wrap:break-word}
.status-badge{padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.status-badge.pending{background:var(--accent-bg);color:var(--accent)}
.status-badge.approved{background:var(--success-bg);color:var(--success)}
.status-badge.rejected{background:var(--error-bg);color:var(--error)}
.actions{display:flex;gap:4px;flex-wrap:wrap}
.actions form{display:inline}
.actions button{padding:5px 10px;border:none;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.1s}
.btn-approve{background:var(--success-bg);color:#065f46}
.btn-approve:hover{background:var(--success-bg)}
.btn-reject{background:var(--error-bg);color:#991b1b}
.btn-reject:hover{background:var(--error-bg)}
.btn-delete{background:var(--bg-card-alt);color:var(--text-light)}
.btn-delete:hover{background:var(--btn-hover)}
@media(max-width:768px){.page-area{padding:16px}.reviews-table{font-size:12px}.reviews-table th,.reviews-table td{padding:8px 6px}.review-text{max-width:180px}}
@media(max-width:480px){.reviews-table{font-size:11px}.review-text{max-width:120px}.actions button{padding:4px 7px;font-size:10px}}
</style>
</head>
<body class="dashboard-body">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar">
<div>
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<h2>Review Management</h2>
<p>Approve or moderate user reviews</p>
</div>
<div style="font-size:12px;color:var(--text-light)"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
</div>
<div class="page-area">

<div class="page-header">
<h1>Reviews</h1>
<div class="filter-bar">
<a href="reviews.php" class="<?= !$filter ? 'active' : '' ?>">All</a>
<a href="reviews.php?status=pending" class="<?= $filter === 'pending' ? 'active' : '' ?>">Pending</a>
<a href="reviews.php?status=approved" class="<?= $filter === 'approved' ? 'active' : '' ?>">Approved</a>
<a href="reviews.php?status=rejected" class="<?= $filter === 'rejected' ? 'active' : '' ?>">Rejected</a>
</div>
</div>

<?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<?php if (empty($reviews)): ?>
<p style="color:var(--text-muted);font-size:14px;padding:40px 0;text-align:center">No reviews found.</p>
<?php else: ?>
<table class="reviews-table">
<thead>
<tr>
<th>ID</th>
<th>User</th>
<th>Business</th>
<th>Rating</th>
<th>Review</th>
<th>Status</th>
<th>Date</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($reviews as $r): ?>
<tr>
<td><?= $r['id'] ?></td>
<td><?= htmlspecialchars($r['full_name']) ?><br><span style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['email']) ?></span></td>
<td><?= htmlspecialchars($r['business_name'] ?: '—') ?></td>
<td><div class="stars"><?php for ($i = 1; $i <= 5; $i++): ?><svg viewBox="0 0 24 24" fill="<?= $i <= (int)$r['rating'] ? 'var(--accent)' : 'none' ?>" stroke="var(--accent)" stroke-width="1.6"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg><?php endfor; ?></div></td>
<td class="review-text"><?= htmlspecialchars($r['review_text']) ?></td>
<td><span class="status-badge <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
<td><span style="white-space:nowrap"><?= date('M j, Y', strtotime($r['created_at'])) ?></span></td>
<td class="actions">
<?php if ($r['status'] === 'pending'): ?>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="review_id" value="<?= $r['id'] ?>"><button type="submit" class="btn-approve">Approve</button></form>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="review_id" value="<?= $r['id'] ?>"><button type="submit" class="btn-reject">Reject</button></form>
<?php elseif ($r['status'] === 'approved'): ?>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="review_id" value="<?= $r['id'] ?>"><button type="submit" class="btn-reject">Reject</button></form>
<?php elseif ($r['status'] === 'rejected'): ?>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="review_id" value="<?= $r['id'] ?>"><button type="submit" class="btn-approve">Approve</button></form>
<?php endif; ?>
<form method="POST" onsubmit="return confirm('Delete this review?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="review_id" value="<?= $r['id'] ?>"><button type="submit" class="btn-delete">Delete</button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>
