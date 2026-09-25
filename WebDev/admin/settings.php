<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/paystack.php';
$activePage = 'settings';

if (!adminCan('settings')) { $_SESSION['flash'] = 'You do not have access to that page.'; header('Location: index.php'); exit; }

$flash = '';
$settings = [];
$stmt = $pdo->query("SELECT `key`, `value` FROM settings");
while ($row = $stmt->fetch()) { $settings[$row['key']] = $row['value']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    validate_csrf();
    $paystackMode = $_POST['paystack_mode'] ?? 'live';
    if (!in_array($paystackMode, ['test', 'live'], true)) { $paystackMode = 'live'; }

    // Validate key format + prefix vs chosen mode (skipped when left blank to keep).
    $keyErr = '';
    $pubIn = trim($_POST['paystack_public_key'] ?? '');
    $secIn = trim($_POST['paystack_secret_key'] ?? '');
    if ($pubIn !== '' && !paystack_key_valid($pubIn, 'public', $paystackMode)) {
        $keyErr = 'Public key is not a valid ' . strtoupper($paystackMode) . ' key (must start with pk_' . $paystackMode . '_).';
    } elseif ($secIn !== '' && !paystack_key_valid($secIn, 'secret', $paystackMode)) {
        $keyErr = 'Secret key is not a valid ' . strtoupper($paystackMode) . ' key (must start with sk_' . $paystackMode . '_).';
    } elseif ($pubIn === '' && ($settings['paystack_public_key'] ?? '') !== '' && strpos($settings['paystack_public_key'], 'pk_' . $paystackMode . '_') !== 0) {
        $keyErr = 'Existing public key does not match ' . strtoupper($paystackMode) . ' mode.';
    } elseif ($secIn === '' && ($settings['paystack_secret_key'] ?? '') !== '' && strpos($settings['paystack_secret_key'], 'sk_' . $paystackMode . '_') !== 0) {
        $keyErr = 'Existing secret key does not match ' . strtoupper($paystackMode) . ' mode.';
    }

    // Save everything except the Paystack trio when validation fails,
    // so a bad key never clobbers a working pair.
    $saveKeys = ['payment_bank_name', 'payment_account_name', 'payment_account_number', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'mail_from', 'mail_from_name'];
    if ($keyErr === '') {
        array_push($saveKeys, 'paystack_public_key', 'paystack_secret_key', 'paystack_mode');
    }
    array_push($saveKeys, 'bank_transfer_enabled');
    foreach ($saveKeys as $k) {
        $v = $k === 'paystack_mode' ? $paystackMode
            : ($k === 'bank_transfer_enabled' ? (isset($_POST['bank_transfer_enabled']) ? '1' : '0')
            : trim($_POST[$k] ?? ''));
        if (($k === 'paystack_secret_key' || $k === 'smtp_pass') && $v === '') continue;
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$k, $v]);
        $settings[$k] = $v;
    }
    logActivity($pdo, $_SESSION['user_id'], 'admin_settings_updated', 'Platform settings updated.');
    $flash = $keyErr !== '' ? $keyErr : 'Settings saved.';
    if ($keyErr === '' && isset($_POST['do_test_smtp'])) {
        require_once __DIR__ . '/../includes/mailer.php';
        $to = $_SESSION['email'] ?? ($settings['smtp_user'] ?? '');
        if ($to === '') {
            $flash .= ' Cannot send test: no recipient email on the admin account.';
        } else {
            $err = '';
            $ok = sendMail($pdo, $to, 'BizVaza — SMTP Test', '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px"><h2 style="color:#0f172a;margin:0 0 8px">SMTP test successful</h2><p style="color:#475569;font-size:14px;line-height:1.6">Your BizVaza SMTP settings are working and this email was sent through them.</p></div>', "BizVaza SMTP test\n\nYour BizVaza SMTP settings are working and this email was sent through them.", $err);
            $flash = $ok
                ? 'Settings saved. Test email sent to ' . $to . '.'
                : 'Settings saved. Test email FAILED: ' . ($err !== '' ? $err : 'unknown error. Check the server log.');
        }
    }
}

// Current Paystack status for the banner.
$paystackConf = paystack_settings($pdo);
$paystackOn = paystack_configured($pdo);
$paystackModeSel = in_array($settings['paystack_mode'] ?? '', ['test', 'live'], true) ? $settings['paystack_mode'] : $paystackConf['mode'];
$bankOn = bank_transfer_enabled($pdo);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza — Admin Settings</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);display:flex;min-height:100vh;font-size:13px;color:var(--text)}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--topbar-bg);border-bottom:1px solid var(--border);padding:16px 28px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.topbar h2{font-size:18px;font-weight:600;color:var(--text)}
.content{padding:28px 24px 0;flex:1;max-width:600px}
.flash{background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success);padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px 16px;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.card-head h3{font-size:14px;font-weight:600;color:var(--text)}
label{display:block;font-size:12px;font-weight:500;color:var(--text-light);margin-bottom:4px;margin-top:10px}
label:first-of-type{margin-top:0}
input,select{width:100%;padding:9px 12px;border:1px solid var(--border-input);border-radius:7px;font-size:13px;font-family:'Inter',sans-serif;outline:none;background:var(--bg-card)}
input:focus,select:focus{border-color:var(--accent)}
select{cursor:pointer}
.btn{display:inline-flex;align-items:center;padding:9px 16px;border-radius:7px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid var(--border-input);background:var(--bg-card);color:var(--text);font-family:'Inter',sans-serif;margin-top:14px}
.btn:hover{background:var(--btn-hover)}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-hover)}
.hint{font-size:11px;color:var(--text-light);margin-top:2px}

</style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
<div class="topbar"><div><button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button><h2>Settings</h2></div></div>
<div class="content">
<?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="save_settings" value="1">
<div class="card">
<div class="card-head"><h3>Payment & Paystack Settings</h3></div>
<p style="font-size:12px;color:var(--text-light);margin-bottom:8px">Enter the account users transfer to for Pro upgrades — shown on the pricing, paywall, and payment-proof pages. Online card payments activate when both Paystack keys match the selected mode.</p>
<div style="background:var(--bg-card-alt);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:12.5px;margin-bottom:8px;font-weight:600;color:<?= $paystackOn ? 'var(--success)' : 'var(--text-muted)' ?>">
Online card payments: <?= $paystackOn ? 'ON (' . strtoupper($paystackModeSel) . ' mode)' : 'OFF — add matching keys below' ?>
</div>
<div style="background:var(--bg-card-alt);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:12.5px;margin-bottom:12px;font-weight:600;color:<?= $bankOn ? 'var(--success)' : 'var(--error)' ?>">
Bank transfer: <?= $bankOn ? 'ON — users can pay by transfer and upload proof' : 'OFF — users can only pay by card' ?>
</div>
<label style="display:flex;align-items:center;gap:8px;margin-top:0">
<input type="checkbox" name="bank_transfer_enabled" value="1" <?= $bankOn ? 'checked' : '' ?> style="width:auto">
Enable bank transfer payments
</label>
<div class="hint">Turn off anytime (e.g. while no receiving account is set up). Turns the Bank Transfer option off on the pricing and paywall pages and blocks proof uploads.</div>
<label>Bank Name</label>
<input type="text" name="payment_bank_name" value="<?= htmlspecialchars($settings['payment_bank_name'] ?? '') ?>" placeholder="e.g. GTBank">
<label>Account Name</label>
<input type="text" name="payment_account_name" value="<?= htmlspecialchars($settings['payment_account_name'] ?? '') ?>" placeholder="e.g. Your Business Ltd">
<label>Account Number</label>
<input type="text" name="payment_account_number" value="<?= htmlspecialchars($settings['payment_account_number'] ?? '') ?>" placeholder="0123456789" maxlength="10" inputmode="numeric">
<div class="hint">The receiving account for bank-transfer Pro payments. Users see these details on the pricing, paywall, and upload-proof pages.</div>
<label>Paystack Mode</label>
<select name="paystack_mode">
<option value="test" <?= $paystackModeSel === 'test' ? 'selected' : '' ?>>Test Mode (sandbox — use non-live cards)</option>
<option value="live" <?= $paystackModeSel === 'live' ? 'selected' : '' ?>>Live Mode (real payments)</option>
</select>
<div class="hint">Use Test Mode while developing. Switch to Live before real transactions.</div>
<label>Paystack Public Key</label>
<input type="text" name="paystack_public_key" value="<?= htmlspecialchars($settings['paystack_public_key'] ?? '') ?>" placeholder="pk_live_xxxxxxxxx">
<label>Paystack Secret Key</label>
<input type="password" name="paystack_secret_key" value="" placeholder="Leave blank to keep current" autocomplete="new-password">
<div class="hint">Keys must start with pk_<?= $paystackModeSel ?>_ / sk_<?= $paystackModeSel ?>_ to match the selected mode.</div>
<button class="btn btn-primary" type="submit">Save Settings</button>
</div>
<div class="card">
<div class="card-head"><h3>SMTP Email Settings</h3></div>
<p style="font-size:12px;color:var(--text-light);margin-bottom:8px">Used to send password reset emails and invoice emails (including auto-generated recurring invoices). For Gmail use <strong>smtp.gmail.com</strong>, port <strong>587</strong>, your Gmail address, and an App Password (Google: Security &gt; 2-Step Verification &gt; App passwords).</p>
<label>SMTP Host</label>
<input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
<label>SMTP Port</label>
<input type="text" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '') ?>" placeholder="587">
<label>SMTP Username</label>
<input type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>" placeholder="you@gmail.com">
<label>SMTP Password</label>
<input type="password" name="smtp_pass" value="" placeholder="App password" autocomplete="new-password">
<div class="hint">Leave blank to keep the current password. For Gmail you need an App Password (enable 2-Step Verification, then create one).</div>
<label>From Email</label>
<input type="text" name="mail_from" value="<?= htmlspecialchars($settings['mail_from'] ?? '') ?>" placeholder="no-reply@bizvaza.ng">
<label>From Name</label>
<input type="text" name="mail_from_name" value="<?= htmlspecialchars($settings['mail_from_name'] ?? '') ?>" placeholder="BizVaza">
<button class="btn btn-primary" type="submit" name="do_test_smtp" value="1" style="margin-right:8px">Save &amp; Send Test Email</button>
<button class="btn btn-primary" type="submit">Save Settings</button>
</div>
</form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>