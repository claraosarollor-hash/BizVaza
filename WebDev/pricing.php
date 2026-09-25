<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/paystack.php';

$is_logged_in = isset($_SESSION['user_id']);
$proPlanId = proPlanId($pdo);
$current_plan_id = $is_logged_in ? (int)($_SESSION['plan_id'] ?? 0) : 0;
$is_pro = $proPlanId > 0 && $current_plan_id === $proPlanId;

// Check for upgrade success
$upgraded = isset($_GET['upgraded']);
$error = $_GET['error'] ?? '';

$stmt = $pdo->query("SELECT * FROM plans WHERE is_active = 1 ORDER BY price");
$plans = $stmt->fetchAll();

$proPlan = getProPlan($pdo);
$proPrice = $proPlan ? (float)$proPlan['price'] : 1999.00;
$proPriceText = $proPrice > 0 ? '₦' . number_format($proPrice) : 'Free';
$proAmountKobo = (int)round($proPrice * 100);

$bankName = '';
$acctName = '';
$acctNum = '';
$stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'payment_%'");
while ($r = $stmt->fetch()) {
    if ($r['key'] === 'payment_bank_name') $bankName = $r['value'];
    if ($r['key'] === 'payment_account_name') $acctName = $r['value'];
    if ($r['key'] === 'payment_account_number') $acctNum = $r['value'];
}
$bankOn = bank_transfer_enabled($pdo);

// Get Paystack public key
$paystackConf = paystack_settings($pdo);
$paystackKey = $paystackConf['public'];
$paystackConfigured = paystack_configured($pdo);
$paystackMode = $paystackConf['mode'];

// Pro plan expiry
$expiry = null;
$daysLeft = 0;
if ($is_pro && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT plan_expires_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $expiry = $stmt->fetchColumn();
    if ($expiry) {
        $daysLeft = max(0, (int)((strtotime($expiry) - time()) / 86400));
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="<?= csrf_token() ?>">
<title>BizVaza — Pricing</title>
<link rel="stylesheet" href="assets/css/styles.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#f8fafc;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:40px 24px}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;margin-bottom:32px}
.back-link:hover{color:var(--text)}
.pricing-header{text-align:center;margin-bottom:36px}
.pricing-header h1{font-size:28px;font-weight:800;color:var(--text)}
.pricing-header p{font-size:14px;color:var(--text-muted);margin-top:6px}
.plans-grid{display:flex;gap:20px;max-width:700px;width:100%;align-items:stretch}
.plan-card{flex:1;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:28px 24px;display:flex;flex-direction:column;position:relative}
.plan-card.popular{border-color:var(--accent);box-shadow:0 4px 16px rgba(180,83,9,.1)}
.plan-badge{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:var(--accent);color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;padding:4px 12px;border-radius:100px;letter-spacing:.5px}
.plan-name{font-size:18px;font-weight:700;color:var(--text)}
.plan-price{font-size:36px;font-weight:800;color:var(--text);margin:12px 0 4px}
.plan-price span{font-size:14px;font-weight:500;color:var(--text-muted)}
.plan-desc{font-size:13px;color:var(--text-muted);margin-bottom:16px}
.plan-features{list-style:none;padding:0;flex:1}
.plan-features li{display:flex;align-items:center;gap:8px;padding:7px 0;font-size:13px;color:#334155;border-bottom:1px solid #f1f5f9}
.plan-features li:last-child{border-bottom:none}
.plan-features li svg{flex-shrink:0}
.plan-btn{display:block;text-align:center;padding:11px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;margin-top:20px;transition:background .15s;cursor:pointer;border:none;font-family:'Inter',sans-serif;width:100%}
.plan-btn.free{background:#f1f5f9;color:var(--text-secondary);border:1px solid #e2e8f0}
.plan-btn.pro{background:var(--accent);color:#fff}
.plan-btn.pro:hover{background:var(--accent-hover)}
.plan-btn.current{background:var(--success-bg);color:var(--success);border:1px solid #a7f3d0;cursor:default}
.plan-btn.login{background:#f1f5f9;color:var(--text-secondary);border:1px solid #e2e8f0}
.flash{background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success);padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:20px;max-width:700px;width:100%}
.flash.error{background:var(--error-bg);border-color:rgba(239,68,68,.35);color:var(--error)}
.pro-status{max-width:700px;width:100%;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between}
.pro-status .left{display:flex;align-items:center;gap:12px}
.pro-status .badge{display:inline-block;padding:4px 12px;border-radius:100px;font-size:12px;font-weight:700}
.pro-status .badge.active{background:var(--success-bg);color:var(--success)}
.pro-status .badge.expiring{background:#fef3c7;color:#d97706}
.pro-status .info h3{font-size:14px;font-weight:700;color:var(--text)}
.pro-status .info p{font-size:12px;color:var(--text-muted)}
.btn-renew{display:inline-flex;align-items:center;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid #b45309;background:var(--bg-card);color:var(--accent);text-decoration:none;font-family:'Inter',sans-serif}
.btn-renew:hover{background:var(--accent-bg)}
@media(max-width:600px){.plans-grid{flex-direction:column}.pro-status{flex-direction:column;gap:12px;text-align:center}}
</style>
</head>
<body>

<a href="<?= $is_logged_in ? 'dashboard.php' : 'index.html' ?>" class="back-link">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
<?= $is_logged_in ? 'Back to Dashboard' : 'Back to Home' ?>
</a>

<div class="pricing-header">
<h1>Choose Your Plan</h1>
<p>Start free, upgrade when you need more.</p>
</div>

<?php if ($upgraded): ?>
<div class="flash"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> You are now on the Pro plan! Enjoy premium templates for the next 30 days.</div>
<?php endif; ?>

<?php if ($error === 'verification_failed'): ?>
<div class="flash error">Payment verification failed. Please try again or contact support.</div>
<?php endif; ?>

<?php if ($is_pro): ?>
<div class="pro-status">
<div class="left">
<span class="badge <?= $daysLeft <= 5 ? 'expiring' : 'active' ?>"><?= $daysLeft <= 5 ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Expiring Soon' : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Pro Active' ?></span>
<div class="info">
<h3>Pro Plan — <?= $daysLeft ?> days remaining</h3>
<p><?= $expiry ? 'Expires ' . date('M j, Y', strtotime($expiry)) : 'No expiry set' ?></p>
</div>
</div>
<a href="settings.php" class="btn-renew">Manage Plan</a>
</div>
<?php endif; ?>

<div class="plans-grid">
<?php foreach ($plans as $p): $is_popular = $p['badge'] === 'Popular'; ?>
<div class="plan-card<?= $is_popular ? ' popular' : '' ?>">
<?php if ($is_popular): ?><div class="plan-badge"><?= htmlspecialchars($p['badge']) ?></div><?php endif; ?>
<div class="plan-name"><?= htmlspecialchars($p['name']) ?></div>
<div class="plan-price"><?= $p['price'] > 0 ? '₦' . number_format($p['price']) : 'Free' ?><span><?= $p['price'] > 0 ? '/month' : '' ?></span></div>
<div class="plan-desc">
<?= $p['name'] === 'Free' ? 'For individuals and small businesses just getting started.' : 'For growing businesses that need professional templates.' ?>
</div>
<ul class="plan-features">
<?php $features = json_decode($p['features'], true) ?? []; foreach ($features as $f): ?>
<li>
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
<?= htmlspecialchars($f) ?>
</li>
<?php endforeach; ?>
</ul>
<?php if ($p['name'] === 'Free'): ?>
<?php if ($is_logged_in && !$is_pro): ?>
<a href="#" class="plan-btn free" style="cursor:default;opacity:.6">Your Current Plan</a>
<?php else: ?>
<a href="register.php" class="plan-btn free">Get Started Free</a>
<?php endif; ?>
<?php else: ?>
<?php if ($is_pro): ?>
<div class="plan-btn current">Your Current Plan</div>
<?php elseif ($is_logged_in): ?>
<button type="button" class="plan-btn pro" onclick="openUpgradeModal()">Upgrade to Pro</button>
<?php else: ?>
<a href="login.php" class="plan-btn login">Login to Upgrade</a>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php if (!$is_pro && $is_logged_in): ?>
<div style="max-width:700px;width:100%;margin-top:24px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px 24px">
<p style="font-size:13px;color:var(--text-muted)">Need help? <a href="needhelp.php" style="color:var(--accent);font-weight:600">Contact us</a> for assistance with your upgrade.</p>
</div>
<?php endif; ?>

<?php if ($is_logged_in && !$is_pro): ?>
<!-- Upgrade Modal -->
<div id="upgradeModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center" onclick="if(event.target===this)closeUpgradeModal()">
<div style="background:var(--bg-card);border-radius:16px;padding:32px;max-width:480px;width:90%;max-height:90vh;overflow:auto">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
<h2 style="font-size:18px;font-weight:700;color:var(--text)">Upgrade to Pro</h2>
<button onclick="closeUpgradeModal()" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--text-muted)">&times;</button>
</div>

<?php if (!$paystackConfigured && !$bankOn): ?>
<!-- All payment methods offline -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px">
</div>
<div id="panel-paystack" style="display:block">
<p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">Payments are temporarily unavailable. Please check back later.</p>
</div>
<?php else: ?>
<!-- Tabs -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px">
<?php if ($paystackConfigured): ?>
<button onclick="switchTab('paystack')" id="tab-paystack" style="flex:1;padding:10px;border:none;background:none;font-size:13px;font-weight:600;color:var(--accent);border-bottom:2px solid #b45309;margin-bottom:-2px;cursor:pointer;font-family:'Inter',sans-serif"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Pay with Card</button>
<?php endif; ?>
<?php if ($bankOn): ?>
<button onclick="switchTab('bank')" id="tab-bank" style="flex:1;padding:10px;border:none;background:none;font-size:13px;font-weight:600;color:var(--accent);border-bottom:2px solid #b45309;margin-bottom:-2px;cursor:pointer;font-family:'Inter',sans-serif"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><path d="M4 10v11"/><path d="M20 10v11"/><path d="M8 14v3"/><path d="M12 14v3"/><path d="M16 14v3"/></svg> Bank Transfer</button>
<?php endif; ?>
</div>

<?php if ($paystackConfigured): ?>
<!-- Paystack Tab -->
<div id="panel-paystack" style="display:block">
<p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">Pay securely via Paystack. Supports card, bank transfer, and USSD.</p>
<div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px">
<div style="display:flex;justify-content:space-between;align-items:center">
<span style="font-size:14px;font-weight:600;color:var(--text)">Pro Plan (30 days)</span>
<span style="font-size:18px;font-weight:800;color:var(--accent)"><?= $proPriceText ?></span>
</div>
</div>
<button id="payBtn" type="button" onclick="payWithPaystack()" style="width:100%;padding:12px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif">Pay <?= $proPriceText ?> with Paystack</button>
<p id="payError" style="font-size:12px;color:var(--error);margin-top:8px;display:none"></p>
</div>
<?php elseif ($bankOn): ?>
<div id="panel-paystack" style="display:none">
<p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">Online card payment is not yet configured. Please use the bank transfer option or try again later.</p>
</div>
<?php endif; ?>

<?php if ($bankOn): ?>
<!-- Bank Transfer Tab -->
<div id="panel-bank" style="display:none">
<p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">Make a bank transfer and upload your payment proof for manual verification.</p>
<div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px">
<div style="display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:13px">
<span style="font-weight:600;color:var(--text)">Bank:</span><span style="color:#334155"><?= htmlspecialchars($bankName) ?></span>
<span style="font-weight:600;color:var(--text)">Account Name:</span><span style="color:#334155"><?= htmlspecialchars($acctName) ?></span>
<span style="font-weight:600;color:var(--text)">Account Number:</span><span style="font-weight:700;color:var(--text);font-size:15px"><?= htmlspecialchars($acctNum) ?></span>
<span style="font-weight:600;color:var(--text)">Amount:</span><span style="font-weight:700;color:var(--accent);font-size:15px"><?= $proPriceText ?></span>
</div>
</div>
<a href="upgrade-receipt.php" style="display:block;width:100%;padding:12px;border:none;border-radius:8px;background:var(--success);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;text-align:center">Upload Payment Proof</a>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>

<?php if ($paystackConfigured): ?>
<script src="https://js.paystack.co/v1/inline.js"></script>
<?php endif; ?>
<script>
var paystackKey = '<?= htmlspecialchars($paystackKey) ?>';
<?php if ($paystackConfigured): ?>
var paystackConfigured = true;
<?php else: ?>
var paystackConfigured = false;
<?php endif; ?>
var planPriceText = '<?= htmlspecialchars($proPriceText, ENT_QUOTES) ?>';
var planAmountKobo = <?= $proAmountKobo ?>;

function openUpgradeModal() {
    document.getElementById('upgradeModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeUpgradeModal() {
    document.getElementById('upgradeModal').style.display = 'none';
    document.body.style.overflow = '';
}
function switchTab(tab) {
<?php if ($paystackConfigured): ?>
    document.getElementById('tab-paystack').style.color = tab === 'paystack' ? '#b45309' : '#64748b';
    document.getElementById('tab-paystack').style.borderBottomColor = tab === 'paystack' ? '#b45309' : 'transparent';
<?php endif; ?>
<?php if ($bankOn): ?>
    document.getElementById('tab-bank').style.color = tab === 'paystack' ? '#b45309' : '#64748b';
    document.getElementById('tab-bank').style.borderBottomColor = tab === 'paystack' ? '#b45309' : 'transparent';
<?php endif; ?>
<?php if ($paystackConfigured): ?>
    document.getElementById('panel-paystack').style.display = tab === 'paystack' ? 'block' : 'none';
<?php endif; ?>
<?php if ($bankOn): ?>
    document.getElementById('panel-bank').style.display = tab === 'bank' ? 'block' : 'none';
<?php endif; ?>
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
<?php endif; ?>

</body>
</html>
