<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$proPlan = getProPlan($pdo);
$proPrice = $proPlan ? (float)$proPlan['price'] : 1999.00;
$proPriceText = $proPrice > 0 ? '₦' . number_format($proPrice, 2) : 'Free';
$bankOn = bank_transfer_enabled($pdo);

$activePage = '';
$user_id = $_SESSION['user_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? '';

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['proof'])) {
    validate_csrf();
    $user_id = $_SESSION['user_id'];

    if (!$bankOn) {
        $flash = 'Bank transfer payments are currently disabled. Please check back later.';
        $flashType = 'error';
    } else {
    $pendingStmt = $pdo->prepare("SELECT id FROM subscription_payments WHERE user_id = ? AND method = 'bank_transfer' AND status = 'pending' LIMIT 1");
    $pendingStmt->execute([$user_id]);
    if ($pendingStmt->fetch()) {
        $flash = 'You already have a pending upgrade request.';
        $flashType = 'error';
    } else {
    $file = $_FILES['proof'];
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])
        ? (string)$finfo->file($file['tmp_name']) : '';
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $flash = 'Please select a file to upload.';
        $flashType = 'error';
    } elseif (!in_array($ext, $allowedExt, true) || !in_array($detectedMime, $allowedMime, true)) {
        $flash = 'Only JPG, PNG, GIF, WebP, or PDF files are allowed.';
        $flashType = 'error';
    } elseif ($detectedMime !== 'application/pdf' && @getimagesize($file['tmp_name']) === false) {
        $flash = 'That image file appears to be invalid.';
        $flashType = 'error';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $flash = 'File must be under 5MB.';
        $flashType = 'error';
    } else {
        $uploadDir = __DIR__ . '/uploads/upgrade-proofs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'proof_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filepath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $reference = 'BF-BANK-' . $user_id . '-' . time();
            $stmt = $pdo->prepare("INSERT INTO subscription_payments (user_id, reference, amount, method, status, proof_path) VALUES (?, ?, ?, 'bank_transfer', 'pending', ?)");
            $stmt->execute([$user_id, $reference, $proPrice, 'upgrade-proofs/' . $filename]);

            logActivity($pdo, $user_id, 'upgrade_request_submitted', 'Submitted bank transfer proof for Pro upgrade', 'subscription', null);

            $flash = 'Payment proof submitted! We will review and activate your Pro plan within 24 hours.';
            $flashType = 'success';
        } else {
            $flash = 'Failed to upload file. Please try again.';
            $flashType = 'error';
        }
    }
    }
    }
}

// Check if user already has pending request
$stmt = $pdo->prepare("SELECT * FROM subscription_payments WHERE user_id = ? AND method = 'bank_transfer' AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$pendingRequest = $stmt->fetch();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Upload Payment Proof</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
.receipt-stage{flex:1;display:flex;flex-direction:column;align-items:center;padding:40px 24px;background:#f8fafc}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;margin-bottom:32px}
.back-link:hover{color:var(--text)}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:32px;max-width:520px;width:100%}
.card h1{font-size:20px;font-weight:700;color:var(--text);margin-bottom:4px}
.card .subtitle{font-size:13px;color:var(--text-muted);margin-bottom:24px}
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px}
.form-group input[type=file]{width:100%;padding:12px;border:2px dashed var(--border-input);border-radius:8px;font-size:13px;font-family:'Inter',sans-serif;background:var(--bg-card-alt);cursor:pointer}
.form-group input[type=file]:hover{border-color:var(--accent);background:var(--accent-bg)}
.form-group .hint{font-size:11px;color:var(--text-muted);margin-top:4px}
.btn{display:inline-flex;align-items:center;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;width:100%;justify-content:center}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-hover)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.flash{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:20px}
.flash.success{background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success)}
.flash.error{background:var(--error-bg);border:1px solid rgba(239,68,68,.35);color:var(--error)}
.pending-box{background:var(--accent-bg);border:1px solid #fed7aa;border-radius:8px;padding:14px;margin-top:16px}
.pending-box h4{font-size:13px;font-weight:600;color:var(--accent);margin-bottom:4px}
.pending-box p{font-size:12px;color:#92400e}
.bank-details{background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:16px;margin:16px 0}
.bank-details h3{font-size:14px;font-weight:700;color:var(--text);margin-bottom:10px}
.bank-row{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:13px;margin-bottom:4px}
.bank-row .lbl{font-weight:600;color:var(--text)}
.bank-row .val{color:#334155}
.bank-row .val.big{font-weight:700;font-size:15px;color:var(--text)}
</style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-wrapper">
<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Upgrade to Pro</h2>
<p>Bank transfer &mdash; upload your payment proof.</p>
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
<div class="receipt-stage">

<a href="pricing.php" class="back-link">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
Back to Pricing
</a>

<div class="card">
<h1>Upload Payment Proof</h1>
<p class="subtitle">Submit your bank transfer receipt for verification.</p>

<?php if ($flash): ?>
<div class="flash <?= $flashType ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php
$bankName = '';
$acctName = '';
$acctNum = '';
$stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'payment_%'");
while ($r = $stmt->fetch()) {
    if ($r['key'] === 'payment_bank_name') $bankName = $r['value'];
    if ($r['key'] === 'payment_account_name') $acctName = $r['value'];
    if ($r['key'] === 'payment_account_number') $acctNum = $r['value'];
}
?>

<?php if (!$bankOn): ?>
<?php if ($pendingRequest): ?>
<div class="pending-box">
<h4><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending Review</h4>
<p>Your payment proof submitted on <?= date('M j, Y g:i A', strtotime($pendingRequest['created_at'])) ?> is being reviewed. You'll be upgraded within 24 hours.</p>
</div>
<?php else: ?>
<div class="pending-box" style="background:var(--error-bg);border-color:rgba(239,68,68,.35)">
<p style="color:var(--error)">Bank transfer payments are currently offline. Please check back later.</p>
</div>
<?php endif; ?>
<?php else: ?>

<div class="bank-details">
<h3>Pay <?= $proPriceText ?> to:</h3>
<div class="bank-row">
<span class="lbl">Bank:</span><span class="val"><?= htmlspecialchars($bankName) ?></span>
<span class="lbl">Account Name:</span><span class="val"><?= htmlspecialchars($acctName) ?></span>
<span class="lbl">Account Number:</span><span class="val big"><?= htmlspecialchars($acctNum) ?></span>
</div>
<p style="font-size:11px;color:var(--text-muted);margin-top:8px">Transfer exactly <?= $proPriceText ?> and upload the receipt/screenshot below.</p>
</div>

<?php if ($pendingRequest): ?>
<div class="pending-box">
<h4><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending Review</h4>
<p>Your payment proof submitted on <?= date('M j, Y g:i A', strtotime($pendingRequest['created_at'])) ?> is being reviewed. You'll be upgraded within 24 hours.</p>
</div>
<?php else: ?>

<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<div class="form-group">
<label>Payment Receipt / Screenshot</label>
<input type="file" name="proof" accept="image/*,application/pdf" required>
<p class="hint">Upload a screenshot or PDF of your bank transfer confirmation. Max 5MB.</p>
</div>
<button type="submit" class="btn btn-primary">Submit for Review</button>
</form>

<?php endif; ?>
<?php endif; ?>
</div>
</div>
</div>
<?php require __DIR__ . '/includes/notification.php'; ?>
<script src="assets/js/app.js"></script>
</body>
</html>
