<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$full_name = $_SESSION['full_name'];
$avatar_initials = $_SESSION['avatar_initials'];

$activePage = 'help';

// Handle callback request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_callback') {
    validate_csrf();
    $cb_name    = trim($_POST['cb_name']);
    $cb_phone   = trim($_POST['cb_phone']);
    $cb_date    = trim($_POST['cb_date'] ?? '');
    $cb_time    = trim($_POST['cb_time'] ?? '');
    $cb_message = trim($_POST['cb_message'] ?? '');
    $user_id    = $_SESSION['user_id'] ?? 0;
    if ($cb_name && $cb_phone) {
        $cb_date    = $cb_date ?: null;
        $cb_time    = $cb_time ?: null;
        $cb_message = $cb_message ?: null;
        $stmt = $pdo->prepare("INSERT INTO help_requests (user_id, name, phone, callback_date, callback_time, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $cb_name, $cb_phone, $cb_date, $cb_time, $cb_message]);
        logActivity($pdo, $user_id, 'callback_requested', "Requested a callback" . ($cb_date ? " for {$cb_date}" . ($cb_time ? " {$cb_time}" : '') : '') . '.');
        $redirect = "needhelp.php?msg=" . urlencode("Callback request submitted! We'll call you within 24 hours.");
    } else {
        $redirect = "needhelp.php?msg=" . urlencode("Please fill in all fields.");
    }
    header("Location: $redirect");
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>BizVaza — Help Center</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh}
.main{flex:1;display:flex;flex-direction:column}
.page-area{flex:1;padding:24px 28px;display:flex;flex-direction:column;gap:20px}
.two-col{display:grid;grid-template-columns:1fr 1.05fr;gap:28px;align-items:start}
.form-card{background:var(--bg-card);border-radius:14px;border:1px solid var(--border);padding:36px 32px 40px}
.form-card h3{font-size:22px;font-weight:700;color:var(--text);margin-bottom:28px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{margin-bottom:20px}
.form-group label{font-size:13px;font-weight:500;color:var(--text-secondary)}
.form-group input,.form-group textarea{width:100%;border:1px solid var(--border);border-radius:7px;padding:10px 13px;font-size:14px;font-family:'Inter',sans-serif;color:var(--text);background:var(--bg-card);outline:none;transition:border-color .15s}
.form-group input:focus,.form-group textarea:focus{border-color:var(--accent)}
.form-group textarea{height:140px;resize:none}
.btn-send{background:var(--accent);color:#fff;border:none;border-radius:8px;padding:11px 32px;font-size:14px;font-weight:600;cursor:pointer;margin-top:8px;font-family:'Inter',sans-serif}
.btn-send:hover{background:var(--accent)}
.right-panel{display:flex;flex-direction:column;gap:20px}
.support-card{background:#0d2141;border-radius:14px;padding:28px 28px 24px;color:#fff}
.support-card .card-eyebrow{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.support-card h3{font-size:20px;font-weight:700;color:#fff;margin-bottom:24px}
.support-options{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.support-option{display:flex;flex-direction:column;align-items:center;text-align:center}
.support-icon{width:56px;height:56px;margin-bottom:10px;display:flex;align-items:center;justify-content:center}
.support-icon svg{width:44px;height:44px;stroke:#fff;fill:none;stroke-width:1.6}
.support-option h4{font-size:13px;font-weight:700;color:#fff;margin-bottom:4px}
.support-option .sub-note{font-size:10px;color:var(--text-muted);margin-bottom:12px;line-height:1.4}
.btn-support{border:none;border-radius:7px;padding:8px 14px;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-block;font-family:'Inter',sans-serif;color:#fff;background:var(--accent)}
.btn-support:hover{background:var(--accent)}
.location-card{background:#0d2141;border-radius:14px;overflow:hidden;color:#fff}
.location-header{padding:22px 28px 16px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.location-header-text h3{font-size:18px;font-weight:700;color:#fff;margin-bottom:4px}
.location-header-text p{font-size:12px;color:var(--text-muted)}
.btn-maps{background:var(--bg-card);color:var(--text);border:none;border-radius:7px;padding:8px 14px;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0;text-decoration:none;display:inline-block;font-family:'Inter',sans-serif}
.btn-maps:hover{background:var(--bg-card-alt)}
.map-container{position:relative;width:100%;height:200px;background:#e8e4dc;overflow:hidden}
.map-bg{width:100%;height:100%;background:#dde8d8;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.map-roads{position:absolute;inset:0}
.map-label-location{position:absolute;bottom:28px;right:28px;font-size:20px;font-weight:700;color:#888;letter-spacing:-.3px}
.map-pin{position:absolute;top:50%;left:55%;transform:translate(-50%,-50%);display:flex;flex-direction:column;align-items:center;pointer-events:none}
.map-pin-head{width:22px;height:22px;background:var(--accent);border-radius:50% 50% 50% 0;transform:rotate(-45deg);margin-bottom:-2px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)}
.map-info-box{position:absolute;top:12px;left:12px;background:var(--bg-card);border-radius:8px;padding:10px 14px;box-shadow:0 2px 12px rgba(0,0,0,.18);max-width:180px}
.map-info-box .map-title{font-size:11px;font-weight:700;color:var(--text);margin-bottom:3px}
.map-info-box .map-addr{font-size:10px;color:#666;line-height:1.4}
@media(max-width:900px){.two-col{grid-template-columns:1fr}.support-options{grid-template-columns:1fr;gap:24px}}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}
.nav-help.active{background:var(--accent)}
.nav-help.active svg{stroke:#fff!important}
.nav-help.active .ht{color:#fff}
.nav-help.active .hs{color:rgba(255,255,255,.75)}
.faq-section{margin-top:4px}
.faq-title{font-size:22px;font-weight:800;color:var(--text);margin-bottom:16px}
.faq-item{border:1px solid var(--border);border-radius:10px;margin-bottom:8px;overflow:hidden;background:var(--bg-card)}
.faq-q{width:100%;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;border:none;background:var(--bg-card);font-size:13.5px;font-weight:600;color:var(--text);font-family:'Inter',sans-serif;text-align:left;transition:background .12s}
.faq-q:hover{background:var(--bg-card-alt)}
.faq-arrow{transition:transform .2s;color:var(--text-muted);flex-shrink:0}
.faq-item.open .faq-arrow{transform:rotate(180deg)}
.faq-a{padding:0 16px;max-height:0;overflow:hidden;transition:max-height .25s ease, padding .25s ease;font-size:13px;color:#555;line-height:1.65}
.faq-item.open .faq-a{max-height:300px;padding:0 16px 14px}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;display:none;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal-box{background:var(--bg-card);border-radius:12px;padding:24px;width:90%;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.15);position:relative}
.modal-box h3{font-size:16px;font-weight:700;color:var(--text);margin-bottom:4px}
.modal-box p.sub{font-size:12.5px;color:var(--text-muted);margin-bottom:16px}
.modal-close{position:absolute;top:12px;right:14px;border:none;background:none;font-size:22px;cursor:pointer;color:var(--text-muted);line-height:1;padding:4px}
.modal-close:hover{color:var(--text)}
.modal-box .field-group{margin-bottom:12px}
.modal-box .field-group label{display:block;font-size:12px;font-weight:500;color:#3a4a5c;margin-bottom:4px}
.modal-box .field-group input,.modal-box .field-group textarea{width:100%;padding:8px 11px;border:1px solid var(--border-input);border-radius:7px;font-size:13px;font-family:'Inter',sans-serif;outline:none}
.modal-box .field-group input:focus,.modal-box .field-group textarea:focus{border-color:var(--accent)}
.modal-box .field-group textarea{height:60px;resize:none}
.btn-primary{background:var(--accent);color:#fff;border:none;border-radius:8px;padding:11px 20px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif}
.btn-primary:hover{background:var(--accent)}
.toast{position:fixed;bottom:24px;right:24px;background:#0d2141;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:999;transform:translateY(100px);opacity:0;transition:all .3s}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:#15803d}
</style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">

<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Help Center</h2>
<p>Get support and assistance</p>
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

<h2 style="font-size:26px;font-weight:800;color:var(--text);margin-bottom:4px">Contact Us</h2>
<p style="font-size:13px;color:var(--text-muted);margin-bottom:4px">Get support and assistance from our team</p>

<div class="two-col">

<div class="form-card">
<h3>Send Us a Message</h3>
<form id="contact-form">
<div class="form-row">
<div class="form-group">
<label for="cf-name">Full Name</label>
<input type="text" id="cf-name" name="name" placeholder="Your name" required>
</div>
<div class="form-group">
<label for="cf-email">Email Address</label>
<input type="email" id="cf-email" name="email" placeholder="your@email.com" required>
</div>
</div>
<div class="form-group full">
<label for="cf-subject">Subject</label>
<input type="text" id="cf-subject" name="subject" placeholder="What's this about?" required>
</div>
<div class="form-group full">
<label for="cf-message">Message</label>
<textarea id="cf-message" name="message" placeholder="Tell us more…" required></textarea>
</div>
<button type="submit" class="btn-send">Send Message</button>
</form>
</div>

<div class="right-panel">

<div class="support-card">
<div class="card-eyebrow">BizVaza Support</div>
<h3>Support Options</h3>
<div class="support-options">
<div class="support-option">
<div class="support-icon">
<svg viewBox="0 0 44 44" fill="none" stroke="#fff" stroke-width="1.6"><rect x="4" y="10" width="36" height="26" rx="3"/><path d="M4 14l18 12 18-12"/></svg>
</div>
<h4>Email Support</h4>
<p class="sub-note">(Average Response: 2 hours)</p>
<a href="mailto:bizvaza@gmail.com" class="btn-support email-btn">Send an Email</a>
</div>
<div class="support-option">
<div class="support-icon">
<svg viewBox="0 0 44 44" fill="none" stroke="#fff" stroke-width="1.6"><path d="M38 28c0 3.866-4.03 7-9 7a15.6 15.6 0 01-4.76-.74L17 37l1.58-6.28A13.4 13.4 0 016 22c0-7.732 7.163-14 16-14s16 6.268 16 14z"/></svg>
</div>
<h4>Chat on WhatsApp</h4>
<p class="sub-note">(Available 24/7)</p>
<a href="https://wa.me/2347073759437" target="_blank" class="btn-support wa-btn">Start Live Chat</a>
</div>
<div class="support-option">
<div class="support-icon">
<svg viewBox="0 0 44 44" fill="none" stroke="#fff" stroke-width="1.6"><path d="M8 8h6l3 8-3.5 3.5a24 24 0 0011 11L28 27l8 3v6c0 2-1.5 3-3 3C15 38 6 29 6 11c0-1.5 1-3 2-3z"/></svg>
</div>
<h4>Request a Callback</h4>
<p class="sub-note">(Nigeria Business Hours)</p>
<button class="btn-support cb-btn" onclick="openCallbackModal()">Schedule Callback</button>
</div>
</div>
</div>

<div class="location-card">
<div class="location-header">
<div class="location-header-text">
<h3>Location Details</h3>
<p>BizVaza HQ, Port Harcourt, Rivers State, Nigeria</p>
</div>
<a href="https://www.google.com/maps/search/Port+Harcourt+Rivers+State+Nigeria" target="_blank" class="btn-maps">View on Google Maps</a>
</div>
<div class="map-container">
<div class="map-bg">
<svg class="map-roads" viewBox="0 0 500 200" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
<rect width="500" height="200" fill="#d4e8c8"/>
<ellipse cx="420" cy="160" rx="120" ry="70" fill="#b8d4e8" opacity="0.7"/>
<line x1="0" y1="80" x2="500" y2="80" stroke="#fff" stroke-width="5"/>
<line x1="0" y1="120" x2="500" y2="120" stroke="#fff" stroke-width="3"/>
<line x1="120" y1="0" x2="120" y2="200" stroke="#fff" stroke-width="5"/>
<line x1="260" y1="0" x2="260" y2="200" stroke="#fff" stroke-width="3"/>
<line x1="380" y1="0" x2="380" y2="200" stroke="#fff" stroke-width="3"/>
<line x1="0" y1="50" x2="500" y2="50" stroke="#fff" stroke-width="1.5" opacity="0.7"/>
<line x1="0" y1="150" x2="500" y2="150" stroke="#fff" stroke-width="1.5" opacity="0.7"/>
<line x1="60" y1="0" x2="60" y2="200" stroke="#fff" stroke-width="1.5" opacity="0.7"/>
<line x1="190" y1="0" x2="190" y2="200" stroke="#fff" stroke-width="1.5" opacity="0.7"/>
<line x1="320" y1="0" x2="320" y2="200" stroke="#fff" stroke-width="1.5" opacity="0.7"/>
<rect x="0" y="76" width="500" height="8" fill="#e8e0d0" opacity="0.3"/>
</svg>
<div class="map-pin"><div class="map-pin-head"></div></div>
<div class="map-label-location">Port Harcourt</div>
</div>
<div class="map-info-box">
<div class="map-title">Nigeria Office Location</div>
<div class="map-addr">BizVaza HQ, Port Harcourt, Rivers State, Nigeria</div>
</div>
</div>
</div>

</div>
</div>

<div class="faq-section">
<h2 class="faq-title">Frequently Asked Questions</h2>

<div class="faq-item">
<button class="faq-q" onclick="toggleFaq(this)">How do I create an invoice? <svg class="faq-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg></button>
<div class="faq-a">Go to <strong>Invoices</strong> in the sidebar, click <strong>Create Invoice</strong>, fill in customer details and items, set the issue date and due date, then click <strong>Save</strong>. The invoice will be available immediately.</div>
</div>

<div class="faq-item">
<button class="faq-q" onclick="toggleFaq(this)">How do I record a payment? <svg class="faq-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg></button>
<div class="faq-a">Navigate to <strong>Payments</strong>, click <strong>+ New Payment</strong>, enter the customer name, amount, and status. A receipt number is auto-generated. You can also create a payment directly from an invoice.</div>
</div>

<div class="faq-item">
<button class="faq-q" onclick="toggleFaq(this)">How do I generate a receipt? <svg class="faq-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg></button>
<div class="faq-a">After recording a payment, a receipt is automatically generated. Go to <strong>Receipts</strong> to view and print receipts. Each invoice can have only one receipt.</div>
</div>

<div class="faq-item">
<button class="faq-q" onclick="toggleFaq(this)">How do I add a customer? <svg class="faq-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg></button>
<div class="faq-a">Go to <strong>Customers</strong>, click <strong>+ New Customer</strong>, fill in their name, email, phone, and address, then click <strong>Save</strong>. You can edit or delete customers anytime.</div>
</div>

</div>

</div>

<div class="modal-overlay" id="callbackModal">
<div class="modal-box">
<button class="modal-close" onclick="closeCallbackModal()">&times;</button>
<h3>Request a Callback</h3>
<p class="sub">Leave your details and our team will call you within 24 hours.</p>
<form method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" value="request_callback">
<div class="field-group">
<label>Your Name</label>
<input type="text" name="cb_name" required>
</div>
<div class="field-group">
<label>Phone Number</label>
<input type="text" name="cb_phone" required placeholder="e.g. 08012345678">
</div>
<div class="field-group">
<label>Preferred Date</label>
<input type="date" name="cb_date">
</div>
<div class="field-group">
<label>Preferred Time</label>
<input type="time" name="cb_time">
</div>
<div class="field-group">
<label>Message (optional)</label>
<textarea name="cb_message" placeholder="Brief message…"></textarea>
</div>
<button class="btn btn-primary" type="submit" style="width:100%;margin-top:4px">Submit Request</button>
</form>
</div>
</div>

<div class="toast" id="toast-el"></div>

<script>
function toggleUserMenu(e) {
    e.stopPropagation();
    document.getElementById('userMenu').classList.toggle('show');
}
document.addEventListener('click', function() {
    document.getElementById('userMenu').classList.remove('show');
});
function toggleFaq(el){var item=el.parentElement;item.classList.toggle('open')}
function openCallbackModal(){document.getElementById('callbackModal').classList.add('show')}
function closeCallbackModal(){document.getElementById('callbackModal').classList.remove('show')}
document.getElementById('callbackModal').addEventListener('click',function(e){if(e.target===this)closeCallbackModal()});
function showToast(msg) {
  const t = document.getElementById('toast-el');
  t.textContent = msg;
  t.style.transform = 'translateY(0)';
  t.style.opacity = '1';
  setTimeout(() => {
    t.style.transform = 'translateY(100px)';
    t.style.opacity = '0';
  }, 2500);
}
const urlParams = new URLSearchParams(window.location.search);
const msg = urlParams.get('msg');
if (msg) { showToast(decodeURIComponent(msg)); }
document.getElementById('contact-form').addEventListener('submit',function(e){
  e.preventDefault();
  var n=document.getElementById('cf-name').value;
  var em=document.getElementById('cf-email').value;
  var s=document.getElementById('cf-subject').value;
  var m=document.getElementById('cf-message').value;
  window.location.href='mailto:bizvaza@gmail.com?subject='+encodeURIComponent(s)+'&body='+encodeURIComponent('From: '+n+' ('+em+')\n\n'+m);
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
<script src="assets/js/app.js"></script>
</html>
