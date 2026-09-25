<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/mailer.php';
$activePage = 'notify';

if (!adminCan('notify')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$error = '';
$title_value = '';
$message_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $title_value = trim($_POST['title'] ?? '');
    $message_value = trim($_POST['message'] ?? '');
    if ($title_value === '') {
        $error = 'Please enter a title.';
    } elseif (mb_strlen($title_value) > 255) {
        $error = 'Title must be 255 characters or less.';
    } elseif ($message_value === '') {
        $error = 'Please enter a message.';
    } elseif (mb_strlen($message_value) > 2000) {
        $error = 'Message must be 2000 characters or less.';
    } else {
        @set_time_limit(0);
        $stmt = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'user'");
        $recipients = $stmt->fetchAll();
        $inserted = 0;
        $sent = 0;
        $failed = 0;
        foreach ($recipients as $r) {
            addNotification($pdo, (int)$r['id'], $title_value, $message_value);
            $inserted++;
            if (sendBroadcastEmail($pdo, $r['email'], $r['full_name'], $title_value, $message_value)) {
                $sent++;
            } else {
                $failed++;
            }
        }
        $summary = "Broadcast '" . $title_value . "' sent to " . $inserted . ' users (' . $sent . ' emails delivered, ' . $failed . ' failed)';
        if (strlen($summary) > 255) { $summary = substr($summary, 0, 252) . '...'; }
        logActivity($pdo, $_SESSION['user_id'], 'admin_broadcast', $summary);
        $_SESSION['flash'] = 'Broadcast sent to ' . $inserted . ' user' . ($inserted !== 1 ? 's' : '') . '. ' . $sent . ' email' . ($sent !== 1 ? 's' : '') . ' delivered, ' . $failed . ' failed.';
        header('Location: notify.php');
        exit;
    }
}

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'");
$recipient_count = intval($stmt->fetchColumn());

$stmt = $pdo->query("SELECT a.*, u.full_name FROM activity_log a JOIN users u ON a.user_id = u.id WHERE a.action_type = 'admin_broadcast' ORDER BY a.created_at DESC LIMIT 20");
$history = $stmt->fetchAll();
$history_items = [];
foreach ($history as $h) {
    $item = ['created_at' => $h['created_at'], 'by' => $h['full_name'], 'subject' => $h['description'], 'recipients' => '', 'emails' => ''];
    if (preg_match("/^Broadcast '(.*)' sent to (\d+) users \((\d+) emails delivered, (\d+) failed\)$/", $h['description'], $m)) {
        $item['subject'] = $m[1];
        $item['recipients'] = (int)$m[2];
        $item['emails'] = $m[3] . ' sent, ' . $m[4] . ' failed';
    }
    $history_items[] = $item;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza – Send Notifications</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.page-area{flex:1;padding:24px 28px;display:flex;flex-direction:column;gap:20px}
.page-header h1{font-size:20px;font-weight:800;color:var(--text);margin:0 0 4px}
.page-header p{font-size:13px;color:var(--text-muted);margin:0}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px}
.card h2{font-size:14px;font-weight:700;color:var(--text);margin:0 0 4px}
.card .hint{font-size:12px;color:var(--text-muted);margin:0 0 16px}
.flash{padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;background:var(--success-bg);color:#065f46;border:1px solid #a7f3d0;margin-bottom:4px}
.error{padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;background:var(--error-bg);color:#991b1b;border:1px solid rgba(239,68,68,.35);margin-bottom:4px}
.form-field{margin-bottom:16px}
.form-field label{display:block;font-size:12px;font-weight:600;color:var(--text-light);margin-bottom:6px}
.form-field input[type=text],.form-field textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--border-input);border-radius:8px;font-size:13px;font-family:inherit;color:var(--text);outline:none}
.form-field input[type=text]:focus,.form-field textarea:focus{border-color:var(--accent)}
.form-field textarea{min-height:140px;resize:vertical;line-height:1.5}
.recipient-note{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-light);background:var(--bg-card-alt);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:16px}
.recipient-note svg{flex-shrink:0}
.recipient-note strong{color:var(--text)}
.btn-send{padding:10px 22px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer}
.btn-send:hover{background:var(--accent-hover)}
.history-table{width:100%;border-collapse:collapse;font-size:13px}
.history-table th{text-align:left;padding:10px 12px;background:var(--table-header);color:var(--text-light);font-weight:600;border-bottom:2px solid var(--table-border);white-space:nowrap}
.history-table td{padding:10px 12px;border-bottom:1px solid var(--table-border);vertical-align:top}
.history-table tr:hover td{background:var(--bg-hover)}
.hist-subject{max-width:260px;word-wrap:break-word;color:var(--text);font-weight:500}
.hist-time{white-space:nowrap;color:var(--text-muted);font-size:12px}
@media(max-width:768px){.page-area{padding:16px}.history-table{font-size:12px}.history-table th,.history-table td{padding:8px 6px}}
</style>
</head>
<body class="dashboard-body">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar">
<div>
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<h2>Notifications</h2>
<p>Send announcements to all users</p>
</div>
<div style="font-size:12px;color:var(--text-light)"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
</div>
<div class="page-area">

<div class="page-header">
<h1>Broadcast Notification</h1>
<p>Compose a message that will be delivered to every user as an in-app notification and an email.</p>
</div>

<?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
<h2>Compose</h2>
<p class="hint">All <?= $recipient_count ?> user account<?= $recipient_count !== 1 ? 's' : '' ?> will receive this.</p>
<div class="recipient-note">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
<strong><?= $recipient_count ?> recipient<?= $recipient_count !== 1 ? 's' : '' ?></strong> (all users)
</div>
<form method="POST" onsubmit="return confirm('Send this broadcast to all <?= $recipient_count ?> user<?= $recipient_count !== 1 ? 's' : '' ?>?')">
<?= csrf_field() ?>
<div class="form-field">
<label for="title">Subject / Title</label>
<input type="text" id="title" name="title" maxlength="255" placeholder="e.g. Scheduled maintenance tonight at 10pm" value="<?= htmlspecialchars($title_value) ?>" required>
</div>
<div class="form-field">
<label for="message">Message</label>
<textarea id="message" name="message" maxlength="2000" placeholder="Write the announcement your users will see…" required><?= htmlspecialchars($message_value) ?></textarea>
</div>
<button type="submit" class="btn-send">Send Broadcast</button>
</form>
</div>

<div class="card">
<h2>Send History</h2>
<p class="hint">Your most recent broadcasts</p>
<?php if (empty($history_items)): ?>
<p style="color:var(--text-muted);font-size:14px;padding:24px 0;text-align:center">No broadcasts sent yet.</p>
<?php else: ?>
<table class="history-table">
<thead>
<tr>
<th>Subject</th>
<th>Recipients</th>
<th>Emails</th>
<th>Sent by</th>
<th>Date</th>
</tr>
</thead>
<tbody>
<?php foreach ($history_items as $hi): ?>
<tr>
<td class="hist-subject"><?= htmlspecialchars($hi['subject']) ?></td>
<td><?= $hi['recipients'] !== '' ? $hi['recipients'] : '—' ?></td>
<td><?= $hi['emails'] !== '' ? $hi['emails'] : '—' ?></td>
<td><?= htmlspecialchars($hi['by']) ?></td>
<td class="hist-time"><?= date('M j, Y g:ia', strtotime($hi['created_at'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>
