<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/paystack.php';

$activePage = '';
$user_id = $_SESSION['user_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? '';

$bankName = ''; $acctName = ''; $acctNum = '';
$stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'payment_%'");
while ($r = $stmt->fetch()) {
    if ($r['key'] === 'payment_bank_name') $bankName = $r['value'];
    if ($r['key'] === 'payment_account_name') $acctName = $r['value'];
    if ($r['key'] === 'payment_account_number') $acctNum = $r['value'];
}
$bankOn = bank_transfer_enabled($pdo);

$paystackConf = paystack_settings($pdo);
$paystackKey = $paystackConf['public'];
$paystackConfigured = paystack_configured($pdo);
$paystackMode = $paystackConf['mode'];

$proPlan = getProPlan($pdo);
$proPrice = $proPlan ? (float)$proPlan['price'] : 1999.00;
$proPriceText = $proPrice > 0 ? '₦' . number_format($proPrice) : 'Free';
$proAmountKobo = (int)round($proPrice * 100);
$proFeatures = $proPlan ? (json_decode($proPlan['features'], true) ?: []) : [];
if (!$proFeatures) {
    $proFeatures = ['Unlimited invoices & receipts', 'Premium templates (6 invoice + 3 receipt)', 'Account details on invoices', 'Priority support'];
}

$trialEnds = $_SESSION['trial_ends_at'] ?? null;
$endedDate = $trialEnds ? date('F j, Y', strtotime($trialEnds)) : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="<?= csrf_token() ?>">
<title>BizVaza — Upgrade to Continue</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
.paywall-stage{flex:1;display:flex;align-items:center;justify-content:center;padding:24px;background:#0d2141}
.card{background:var(--bg-card);border-radius:20px;max-width:560px;width:100%;padding:40px 36px;box-shadow:0 20px 60px rgba(0,0,0,.35)}
.lock-icon{width:52px;height:52px;border-radius:14px;background:var(--accent-bg);display:flex;align-items:center;justify-content:center;margin-bottom:20px}
.lock-icon svg{width:26px;height:26px}
.card h1{font-size:24px;font-weight:800;color:var(--text);margin-bottom:6px}
.card .sub{font-size:14px;color:var(--text-muted);margin-bottom:24px;line-height:1.5}
.perk{display:flex;gap:10px;align-items:flex-start;margin-bottom:10px}
.perk svg{width:16px;height:16px;flex-shrink:0;margin-top:2px}
.perk span{font-size:13px;color:#334155}
.price-box{background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:16px;display:flex;justify-content:space-between;align-items:center;margin:20px 0 8px}
.price-box .pl{font-size:14px;font-weight:600;color:var(--text)}
.price-box .pl small{display:block;font-weight:400;color:var(--text-muted);font-size:12px}
.price-box .amt{font-size:22px;font-weight:800;color:var(--accent)}
.pay-btn{display:block;width:100%;padding:14px;border:none;border-radius:10px;background:var(--accent);color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;margin-top:12px;text-align:center}
.pay-btn:hover{background:var(--accent-hover)}
.pay-btn:disabled{opacity:.5;cursor:not-allowed}
.bank-btn{display:block;width:100%;padding:14px;border:1px solid var(--border-input);border-radius:10px;background:var(--bg-card);color:var(--text);font-size:15px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;margin-top:12px;text-align:center;text-decoration:none}
.bank-btn:hover{background:var(--bg-card-alt)}
.bank-details{background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:14px;margin-top:12px;font-size:13px;display:none}
.bank-details .row{display:grid;grid-template-columns:auto 1fr;gap:4px 14px}
.bank-details .lbl{font-weight:600;color:var(--text)}
.bank-details .val{color:#334155}
.bank-details .val.big{font-weight:700;font-size:15px;color:var(--text)}
.hint{font-size:12px;color:var(--text-muted);margin-top:10px;text-align:center}
.err-msg{font-size:12px;color:var(--error);margin-top:8px;display:none;text-align:center}
.logout{display:block;text-align:center;margin-top:20px;font-size:13px;color:var(--text-muted);text-decoration:none}
.logout:hover{color:var(--text)}
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
<p>Unlock unlimited invoices &amp; premium templates.</p>
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
<div class="paywall-stage">
<div class="card">
<div class="lock-icon">
<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="11" width="18" height="11" rx="2" stroke="var(--accent)" stroke-width="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="var(--accent)" stroke-width="2"/></svg>
</div>
<h1>Your free trial has ended</h1>
<p class="sub"><?= $endedDate ? "Your free trial ended on $endedDate. " : '' ?>Upgrade to Pro to keep using BizVaza — unlock unlimited invoices, premium templates, and more.</p>

<?php foreach ($proFeatures as $feature): ?>
<div class="perk"><svg viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span><?= htmlspecialchars($feature) ?></span></div>
<?php endforeach; ?>

<div class="price-box">
<div class="pl">Pro Plan (30 days)<small>Monthly subscription</small></div>
<div class="amt"><?= $proPriceText ?></div>
</div>

<?php if (!$paystackConfigured && !$bankOn): ?>
<p class="err-msg" style="display:block">Payments are temporarily unavailable. Please check back later.</p>
<?php else: ?>

<?php if ($paystackConfigured): ?>
<button class="pay-btn" id="payBtn" onclick="payWithPaystack()">Pay <?= $proPriceText ?> with Paystack</button>
<p class="err-msg" id="payError"></p>
<?php if ($bankOn): ?>
<div style="display:flex;align-items:center;gap:10px;margin:16px 0 4px"><div style="flex:1;height:1px;background:var(--border)"></div><span style="font-size:11px;color:var(--text-muted)">OR</span><div style="flex:1;height:1px;background:var(--border)"></div></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($bankOn): ?>
<button class="bank-btn" onclick="toggleBank()">Pay by Bank Transfer</button>
<div class="bank-details" id="bankDetails">
<div class="row">
<span class="lbl">Bank:</span><span class="val"><?= htmlspecialchars($bankName) ?></span>
<span class="lbl">Account Name:</span><span class="val"><?= htmlspecialchars($acctName) ?></span>
<span class="lbl">Account Number:</span><span class="val big"><?= htmlspecialchars($acctNum) ?></span>
<span class="lbl">Amount:</span><span class="val big"><?= $proPriceText ?></span>
</div>
<p style="font-size:11px;color:var(--text-muted);margin-top:8px">Transfer exactly <?= $proPriceText ?>, then upload your receipt for verification.</p>
<a href="upgrade-receipt.php" style="display:block;width:100%;padding:11px;border:none;border-radius:8px;background:var(--success);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;text-align:center;margin-top:12px">Upload Payment Proof</a>
</div>
<?php endif; ?>
<?php endif; ?>

<p class="hint">Questions? <a href="needhelp.php" style="color:var(--accent);font-weight:600">Contact support</a></p>
<a href="logout.php" class="logout">Log out</a>
</div>
</div>

<?php if ($paystackConfigured): ?>
<script src="https://js.paystack.co/v1/inline.js"></script>
<?php endif; ?>
<script>
var paystackKey = '<?= htmlspecialchars($paystackKey) ?>';
var planPriceText = '<?= htmlspecialchars($proPriceText, ENT_QUOTES) ?>';
var planAmountKobo = <?= $proAmountKobo ?>;
function toggleBank() {
    var d = document.getElementById('bankDetails');
    d.style.display = d.style.display === 'block' ? 'none' : 'block';
}
<?php if ($paystackConfigured): ?>
function payWithPaystack() {
    var btn = document.getElementById('payBtn');
    var errEl = document.getElementById('payError');
    btn.disabled = true;
    btn.textContent = 'Initializing...';
    errEl.style.display = 'none';

    fetch('ajax/paystack-initialize.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content')
        },
        body: 'plan_id=2'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var handler = PaystackPop.setup({
                key: paystackKey,
                email: '<?= htmlspecialchars($_SESSION["email"] ?? "", ENT_QUOTES) ?>',
                amount: planAmountKobo,
                currency: 'NGN',
                ref: data.reference,
                metadata: {plan_id: 2},
                callback: function(response) {
                    window.location.href = 'upgrade-success.php?reference=' + response.reference + '&status=success';
                },
                onClose: function() {
                    btn.disabled = false;
                    btn.textContent = 'Pay ' + planPriceText + ' with Paystack';
                }
            });
            handler.openIframe();
            btn.disabled = false;
            btn.textContent = 'Pay ' + planPriceText + ' with Paystack';
        } else {
            errEl.textContent = data.error || 'Initialization failed. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Pay ' + planPriceText + ' with Paystack';
        }
    })
    .catch(function() {
        errEl.textContent = 'Network error. Please check your connection.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Pay ' + planPriceText + ' with Paystack';
    });
}
<?php endif; ?>
</script>
</div>
<?php require __DIR__ . '/includes/notification.php'; ?>
<script src="assets/js/app.js"></script>
</body>
</html>
