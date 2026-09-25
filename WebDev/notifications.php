<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$avatar_initials = $_SESSION['avatar_initials'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all'])) {
    validate_csrf();
    markAllRead($pdo, $user_id);
    header('Location: notifications.php');
    exit;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_items = intval($stmt->fetchColumn());

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;
$total_pages = max(1, ceil($total_items / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
$stmt->bindValue(2, $per_page, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$notifs = $stmt->fetchAll();

$unreadCount = getUnreadCount($pdo, $user_id);
$activePage = 'notifications';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza – Notifications</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.page-area{flex:1;padding:24px 28px;display:flex;flex-direction:column;gap:16px}
.page-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.page-header h1{font-size:18px;font-weight:800;color:var(--text)}
.page-sub{font-size:12px;color:var(--text-muted)}
.notif-list{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.notif-item{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)}
.notif-item:last-child{border-bottom:none}
.notif-item.unread{background:#fffaf3}
.notif-ic{width:30px;height:30px;border-radius:8px;background:var(--accent-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.notif-body{flex:1;min-width:0}
.notif-title{font-size:13px;font-weight:600;color:var(--text);line-height:1.4}
.notif-item.unread .notif-title{color:var(--accent)}
.notif-msg{font-size:13px;color:#334155;line-height:1.5;margin-top:3px;word-wrap:break-word;white-space:pre-line}
.notif-time{font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;margin-left:auto}
.empty-state{padding:48px 16px;text-align:center;font-size:13px;color:var(--text-muted)}
.btn-markall{padding:7px 14px;border:none;border-radius:6px;background:#0d2141;color:#fff;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer}
.btn-markall:hover{background:#1a3562}
.btn-markall:disabled{background:#cbd5e1;cursor:not-allowed}
.pagination-bar{display:flex;align-items:center;justify-content:space-between;font-size:12px;color:var(--text-secondary)}
.pagination-links a{display:inline-block;padding:5px 10px;border:1px solid var(--border);border-radius:6px;color:var(--text);text-decoration:none;margin-left:4px}
.pagination-links a:hover{background:#f8fafc}
.pagination-links .current{display:inline-block;padding:5px 10px;border-radius:6px;background:#0d2141;color:#fff;margin-left:4px;font-weight:600}
@media(max-width:768px){.page-area{padding:16px}.notif-time{display:none}}
</style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Notifications</h2>
<p>Messages and announcements from BizVaza</p>
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

<div class="page-header">
<div>
<h1>Inbox</h1>
<div class="page-sub"><?= $total_items ?> notification<?= $total_items !== 1 ? 's' : '' ?><?= $unreadCount > 0 ? ' &middot; ' . $unreadCount . ' unread' : '' ?></div>
</div>
<?php if ($unreadCount > 0): ?>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="mark_all" value="1"><button type="submit" class="btn-markall">Mark all as read</button></form>
<?php else: ?>
<button type="button" class="btn-markall" disabled>All caught up</button>
<?php endif; ?>
</div>

<div class="notif-list">
<?php if (count($notifs) > 0): ?>
<?php foreach ($notifs as $n): ?>
<div class="notif-item<?= $n['is_read'] ? '' : ' unread' ?>">
<div class="notif-ic">
<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
</div>
<div class="notif-body">
<div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
<div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
</div>
<div class="notif-time"><?= date('M j, Y \a\t g:ia', strtotime($n['created_at'])) ?></div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="empty-state">No notifications yet — announcements from BizVaza will appear here.</div>
<?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="pagination-bar">
<div class="page-info">Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></div>
<div class="pagination-links">
<?php if ($page > 1): ?>
<a href="?page=<?= $page - 1 ?>">&laquo; Prev</a>
<?php endif; ?>
<?php $s = max(1, $page - 2); $e = min($total_pages, $page + 2);
if ($s > 1) echo '<span class="dots">...</span>';
for ($i = $s; $i <= $e; $i++): ?>
<?php if ($i === $page): ?><span class="current"><?= $i ?></span>
<?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a>
<?php endif; ?>
<?php endfor;
if ($e < $total_pages) echo '<span class="dots">...</span>'; ?>
<?php if ($page < $total_pages): ?>
<a href="?page=<?= $page + 1 ?>">Next &raquo;</a>
<?php endif; ?>
</div>
</div>
<?php endif; ?>

</div>

<script src="assets/js/app.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</body>
</html>
