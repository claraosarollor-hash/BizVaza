<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }
require_once __DIR__ . '/notifications.php';
$notifs = getNotifications($pdo, $user_id, 5);
$unreadCount = getUnreadCount($pdo, $user_id);
?>
<meta name="csrf-token" content="<?= csrf_token() ?>">
<div class="notif-overlay" id="notifOverlay" onclick="closeNotif()"></div>
<div class="notif-panel" id="notifPanel">
<div class="notif-header">
<span>Notifications</span>
<a href="notifications.php" style="font-size:11px;font-weight:600;color:var(--accent);text-decoration:none">View all</a>
</div>
<?php if (count($notifs) > 0): ?>
<?php foreach ($notifs as $n): ?>
<div class="notif-item">
<div class="notif-title"><?= htmlspecialchars($n['title']) ?><?php if (!$n['is_read']): ?><span class="notif-dot"></span><?php endif; ?></div>
<div class="notif-text"><?= htmlspecialchars(mb_strlen($n['message']) > 140 ? mb_substr($n['message'], 0, 140) . '…' : $n['message']) ?></div>
<div class="notif-time"><?= timeAgo($n['created_at']) ?></div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="notif-empty">No notifications yet</div>
<?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var dot = document.querySelector('.bell-dot');
    if (dot) dot.style.display = <?= $unreadCount > 0 ? "'block'" : "'none'" ?>;
});
</script>
<style>
.notif-panel{position:fixed;top:64px;right:80px;width:340px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 32px var(--modal-shadow);z-index:1000;display:none;max-height:400px;overflow-y:auto}
.notif-panel.show{display:block}
.notif-header{padding:14px 16px;font-size:14px;font-weight:700;color:var(--text);border-bottom:1px solid var(--border-light);display:flex;align-items:center;justify-content:space-between}
.notif-item{padding:12px 16px;border-bottom:1px solid var(--border-light);transition:background .12s}
.notif-item:hover{background:var(--bg-hover)}
.notif-item:last-child{border-bottom:none}
.notif-title{font-size:13px;font-weight:600;color:var(--text);line-height:1.4;display:flex;align-items:center;gap:6px}
.notif-dot{width:6px;height:6px;background:var(--error);border-radius:50%;flex-shrink:0}
.notif-text{font-size:12px;color:var(--text-secondary);line-height:1.45;margin-top:3px}
.notif-time{font-size:11px;color:var(--text-muted);margin-top:4px}
.notif-empty{padding:32px 16px;text-align:center;font-size:13px;color:var(--text-muted)}
.bell-dot{position:absolute;top:6px;right:6px;width:7px;height:7px;background:var(--error);border-radius:50%;border:1.5px solid var(--topbar-bg)}
.notif-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:999;display:none}
.notif-overlay.show{display:block}
</style>
