<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$business_name = $_SESSION['business_name'];
$email = $_SESSION['email'];
$avatar_initials = $_SESSION['avatar_initials'];
$first_name = explode(' ', $full_name)[0];

// Pro plan promo line driven by admin-editable plan features
$proPlan = getProPlan($pdo);
$proFeatures = $proPlan ? (json_decode($proPlan['features'], true) ?: []) : [];
$proTemplateFeature = '';
foreach ($proFeatures as $f) {
    if (stripos($f, 'template') !== false) { $proTemplateFeature = $f; break; }
}
$proTemplateText = $proTemplateFeature ?: 'professionally designed invoice and receipt templates';

$activePage = 'settings';

// Fetch user data from DB first (needed by POST handlers below)
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();

$upload_dir = __DIR__ . '/uploads/logos';

// Handle save business profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_profile') {
    validate_csrf();
    $full_name = trim($_POST['full_name'] ?? $full_name);
    $business_name = trim($_POST['business_name'] ?? $business_name);
    $email = trim($_POST['business_email'] ?? $email);
    $business_phone = trim($_POST['business_phone'] ?? '');
    $business_address = trim($_POST['business_address'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $business_abbr = strtoupper(trim($_POST['business_abbr'] ?? ''));
    $invoice_default_notes = trim($_POST['invoice_default_notes'] ?? '');
    $receipt_default_notes = trim($_POST['receipt_default_notes'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $currency = strtoupper(trim($_POST['currency'] ?? 'NGN'));

    $logo_path = $user_data['logo_path'] ?? '';

    // Logo upload
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)$finfo->file($_FILES['logo']['tmp_name']);
        $imgOk = @getimagesize($_FILES['logo']['tmp_name']) !== false;
        if (in_array($ext, $allowed, true) && in_array($detectedMime, $allowedMime, true) && $imgOk && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = 'user_' . $user_id . '.' . $ext;
            $dest = $upload_dir . '/' . $filename;
            // Remove old logo
            if ($logo_path && file_exists(__DIR__ . '/' . $logo_path)) unlink(__DIR__ . '/' . $logo_path);
            move_uploaded_file($_FILES['logo']['tmp_name'], $dest);
            $logo_path = 'uploads/logos/' . $filename;
        }
    }

    // Logo removal
    if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1' && $logo_path) {
        if (file_exists(__DIR__ . '/' . $logo_path)) unlink(__DIR__ . '/' . $logo_path);
        $logo_path = '';
    }

    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, business_name = ?, business_abbr = ?, email = ?, business_phone = ?, business_address = ?, bank_name = ?, account_name = ?, account_number = ?, invoice_default_notes = ?, receipt_default_notes = ?, phone = ?, currency = ?, logo_path = ? WHERE id = ?");
    $stmt->execute([$full_name, $business_name, $business_abbr, $email, $business_phone, $business_address, $bank_name, $account_name, $account_number, $invoice_default_notes, $receipt_default_notes, $phone, $currency, $logo_path, $user_id]);

    // Refresh session
    $_SESSION['full_name'] = $full_name;
    $_SESSION['business_name'] = $business_name;
    $_SESSION['business_abbr'] = $business_abbr;
    $_SESSION['email'] = $email;
    $_SESSION['business_phone'] = $business_phone;
    $_SESSION['business_address'] = $business_address;
    $_SESSION['bank_name'] = $bank_name;
    $_SESSION['account_name'] = $account_name;
    $_SESSION['account_number'] = $account_number;
    $_SESSION['invoice_default_notes'] = $invoice_default_notes;
    $_SESSION['receipt_default_notes'] = $receipt_default_notes;
    $_SESSION['phone'] = $phone;
    $_SESSION['currency'] = $currency;
    $_SESSION['logo_path'] = $logo_path;
    $_SESSION['avatar_initials'] = getAvatarInitials($full_name);

    logActivity($pdo, $user_id, 'profile_updated', 'Business profile updated' . ($logo_path !== ($user_data['logo_path'] ?? '') ? ' (logo changed)' : ''));

    $_SESSION['flash_success'] = 'Business info saved successfully.';
    header('Location: settings.php');
    exit;
}

// Handle change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    validate_csrf();
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $hashed = $stmt->fetchColumn();

    if (!password_verify($current_password, $hashed)) {
        $_SESSION['flash_error'] = 'Current password is incorrect.';
    } elseif (strlen($new_password) < 8) {
        $_SESSION['flash_error'] = 'New password must be at least 8 characters.';
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['flash_error'] = 'New passwords do not match.';
    } else {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, password_updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_hash, $user_id]);
        $_SESSION['pw_epoch'] = date('Y-m-d H:i:s');
        logActivity($pdo, $user_id, 'password_changed', 'Your account password was changed.');
        $_SESSION['flash_success'] = 'Password changed successfully.';
    }
    header('Location: settings.php');
    exit;
}

// Handle save template selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_template') {
    validate_csrf();
    if (!isPro()) {
        clearPremiumSelections($pdo, $user_id);
        $_SESSION['selected_template_id'] = null;
        $_SESSION['selected_receipt_template_id'] = null;
        $_SESSION['flash_error'] = 'Premium templates are only available on the Pro plan.';
        header('Location: settings.php');
        exit;
    }
    $selected_template_id = intval($_POST['selected_template_id'] ?? 0);
    $selected_receipt_template_id = intval($_POST['selected_receipt_template_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE users SET selected_template_id = ?, selected_receipt_template_id = ? WHERE id = ?");
    $stmt->execute([$selected_template_id ?: null, $selected_receipt_template_id ?: null, $user_id]);
    $_SESSION['selected_template_id'] = $selected_template_id ?: null;
    $_SESSION['selected_receipt_template_id'] = $selected_receipt_template_id ?: null;
    logActivity($pdo, $user_id, 'template_saved', 'Default invoice/receipt template selection saved.');
    $_SESSION['flash_success'] = 'Templates updated successfully.';
    header('Location: settings.php');
    exit;
}

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    validate_csrf();
    $rating = intval($_POST['rating'] ?? 0);
    $review_text = trim($_POST['review_text'] ?? '');
    if ($rating < 1 || $rating > 5) {
        $_SESSION['flash_error'] = 'Please select a rating between 1 and 5.';
    } elseif (!$review_text) {
        $_SESSION['flash_error'] = 'Please write your review.';
    } else {
        $stmt = $pdo->prepare("SELECT id, status FROM reviews WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $existing = $stmt->fetch();
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE reviews SET rating = ?, review_text = ?, status = 'pending' WHERE id = ?");
            $stmt->execute([$rating, $review_text, $existing['id']]);
            $_SESSION['flash_success'] = 'Review updated and submitted for approval.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO reviews (user_id, rating, review_text) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $rating, $review_text]);
            $_SESSION['flash_success'] = 'Review submitted for approval.';
        }
        logActivity($pdo, $user_id, 'review_submitted', "Submitted a {$rating}-star review");
    }
    header('Location: settings.php');
    exit;
}

// Handle delete review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_review') {
    validate_csrf();
    $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ? AND user_id = ?");
    $stmt->execute([intval($_POST['review_id'] ?? 0), $user_id]);
    if ($stmt->rowCount() > 0) {
        logActivity($pdo, $user_id, 'review_deleted', 'You deleted your review.');
    }
    $_SESSION['flash_success'] = 'Review deleted.';
    header('Location: settings.php');
    exit;
}

// Reload fresh data from DB
if ($user_data) {
    $full_name = $user_data['full_name'];
    $business_name = $user_data['business_name'];
    $email = $user_data['email'];
    $business_phone = $user_data['business_phone'] ?? '';
    $business_address = $user_data['business_address'] ?? '';
    $bank_name = $user_data['bank_name'] ?? '';
    $account_name = $user_data['account_name'] ?? '';
    $account_number = $user_data['account_number'] ?? '';
    $business_abbr = $user_data['business_abbr'] ?? '';
    $invoice_default_notes = $user_data['invoice_default_notes'] ?? '';
    $receipt_default_notes = $user_data['receipt_default_notes'] ?? '';
    $phone = $user_data['phone'] ?? '';
    $currency = $user_data['currency'] ?? 'NGN';
    $logo_path = $user_data['logo_path'] ?? '';
}
$first_name = explode(' ', $full_name)[0];

// Load plan info
$proPlanId = proPlanId($pdo);
$plan_id = (int)($user_data['plan_id'] ?? 0);
$plan_name = ($proPlanId > 0 && $plan_id === $proPlanId) ? 'Pro' : 'Free';
$is_pro = ($proPlanId > 0 && $plan_id === $proPlanId);

// Load templates
$invoice_templates = getTemplates($pdo, 'invoice');
$receipt_templates = getTemplates($pdo, 'receipt');
$selected_template_id = $user_data['selected_template_id'] ?? null;
$selected_receipt_template_id = $user_data['selected_receipt_template_id'] ?? null;

// Fetch user reviews
$stmt = $pdo->prepare("SELECT * FROM reviews WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$user_reviews = $stmt->fetchAll();
$latest_review = $user_reviews[0] ?? null;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza – Dashboard Settings</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.page-area{flex:1;padding:24px 28px;display:flex;flex-direction:column;gap:20px}
.flash.error{background:var(--error-bg);border-color:rgba(239,68,68,.35);color:var(--error)}
.settings-grid{display:grid;gap:16px;max-width:700px}
.s-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px 20px 22px;display:flex;flex-direction:column}
.s-card-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;gap:8px}
.s-card-header-left{display:flex;align-items:center;gap:12px}
.s-card-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.s-card-title{font-size:15px;font-weight:700;color:var(--text);line-height:1.25}
.s-plan-badge{font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;white-space:nowrap}
.s-plan-badge.Free{background:var(--accent-bg);color:var(--accent)}
.s-plan-badge.Pro{background:#ecfdf5;color:var(--success)}
.s-plan-badge.pending{background:#fffbeb;color:#d97706}
.s-plan-badge.approved{background:#ecfdf5;color:var(--success)}
.s-plan-badge.rejected{background:var(--error-bg);color:var(--error)}
.star-rating{display:flex;gap:4px;cursor:pointer}
.star-rating .star{transition:fill .15s}
.star-rating .star.empty:hover{fill:#fde68a}
.star-rating .star.filled{fill:#b45309}
.divider{height:1px;background:var(--bg);margin:14px 0}
.field-label{font-size:12px;font-weight:500;color:#3a4a5c;margin-bottom:5px;display:block}
.field-group{margin-bottom:13px}
.field-group:last-of-type{margin-bottom:0}
input[type=text]{width:100%;padding:8px 11px;border:1px solid var(--border-input);border-radius:7px;font-size:13px;color:var(--text);background:var(--bg-card);outline:none;transition:border .15s;font-family:'Inter',sans-serif}
input[type=text]:focus{border-color:var(--accent)}
input[type=text]::placeholder{color:var(--text-muted)}
textarea{width:100%;padding:8px 11px;border:1px solid var(--border-input);border-radius:7px;font-size:13px;font-family:'Inter',sans-serif;resize:none;outline:none}
textarea:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 16px;border-radius:7px;font-size:13px;font-weight:500;cursor:pointer;width:100%;border:1px solid var(--border-input);background:var(--bg-card);color:var(--text);transition:background .13s;font-family:'Inter',sans-serif}
.btn:hover{background:var(--bg-card-alt)}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-hover)}
.settings-select{width:100%;padding:8px 11px;border:1px solid var(--border-input);border-radius:7px;font-size:13px;color:var(--text);background:var(--bg-card);outline:none;font-family:'Inter',sans-serif;cursor:pointer}
.settings-select:focus{border-color:var(--accent)}
input[type=password]{width:100%;padding:8px 11px;border:1px solid var(--border-input);border-radius:7px;font-size:13px;color:var(--text);background:var(--bg-card);outline:none;transition:border .15s;font-family:'Inter',sans-serif}
input[type=password]:focus{border-color:var(--accent)}
.logo-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.logo-input{font-size:12px;font-family:'Inter',sans-serif;color:var(--text)}
.logo-preview-wrap{display:flex;align-items:center;gap:10px}
.logo-preview{width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--border)}
.logo-remove-label{font-size:11.5px;color:var(--error);cursor:pointer;display:flex;align-items:center;gap:4px}

.pw-wrap{position:relative}
.pw-wrap input{padding-right:36px!important}
.pw-toggle{position:absolute;right:2px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;padding:5px;display:flex;align-items:center;justify-content:center;border-radius:5px;z-index:2}
.pw-toggle:hover{background:rgba(0,0,0,.04)}
.pw-toggle svg{width:16px;height:16px;stroke:#8da0c0;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.s-plan-badge{font-size:11px;font-weight:700;padding:4px 10px;border-radius:100px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.s-plan-badge.free{background:var(--accent-bg);color:var(--accent);border:1px solid #fde68a}
.s-plan-badge.pro{background:var(--success-bg);color:var(--success);border:1px solid #a7f3d0}
.template-preview{margin:2px 0 10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;overflow:auto;max-height:420px}
.preview-sheet{background:#fff;padding:14px;max-width:520px;margin:10px auto;border-radius:6px;box-shadow:0 1px 6px rgba(0,0,0,.06);font-family:'Plus Jakarta Sans','Inter',sans-serif}
.invoice-meta-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:10px}
.brand-block{flex:1}
.brand-identity{display:flex;gap:10px;align-items:flex-start}
.brand-logo-icon{width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#0d2141;color:#fff}
.brand-logo-icon svg{width:14px;height:14px}
.brand-details{flex:1}
.company-name{font-size:13px;font-weight:800;color:#0f172a;margin-bottom:1px}
.company-address{font-size:10px;color:#64748b;line-height:1.3}
.company-contact{font-size:10px;color:#64748b}
.document-status-block{text-align:right;flex-shrink:0}
.status-badge{display:inline-block;font-size:9px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:.3px}
.status-badge.pending{background:#fff7ed;color:#b45309;border:1px solid #fde68a}
.status-badge.paid{background:#d1fae5;color:#059669;border:1px solid #a7f3d0}
.document-type-title{font-size:16px;font-weight:800;color:#0f172a;margin-bottom:2px}
.invoice-id-number{font-size:11px;color:#64748b}
.date-summary-table{margin-top:4px}
.date-row{display:flex;justify-content:flex-end;gap:10px;font-size:11px}
.date-label{color:#64748b;font-weight:500}
.date-val{color:#1e293b;font-weight:600}
.bill-to-section{margin-bottom:14px}
.bill-to-section h3{font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.client-name{font-size:13px;font-weight:700;color:#0f172a;margin-bottom:2px}
.client-meta{font-size:11px;color:#475569;line-height:1.3}
.receipt-table{display:flex;flex-direction:column;margin-bottom:14px}
.table-header-row,.table-data-row{display:grid;grid-template-columns:22px 3.4fr 30px 1.3fr 1.3fr;gap:8px;align-items:center;padding:6px 10px}
.table-header-row{background:#0b1120;border-radius:4px;color:#fff}
.table-header-row div{font-size:10px;font-weight:600}
.table-data-row{border-bottom:1px solid #f1f5f9;color:#475569;font-size:11px}
.col-idx{text-align:left}
.col-desc{text-align:left}
.col-qty{text-align:center}
.col-price{text-align:right}
.col-total{text-align:right}
.highlight-text{color:#0f172a;font-weight:600}
.financials-summary-container{display:flex;justify-content:flex-end;margin-bottom:18px}
.financials-inner-box{width:100%;max-width:240px;display:flex;flex-direction:column;gap:8px}
.summary-line-row{display:flex;justify-content:space-between;align-items:center;font-size:11px}
.lbl{color:#475569;font-weight:500}
.bold-dark{color:#0f172a;font-weight:700}
.total-row-divider{border-top:1px dashed #e2e8f0;padding-top:8px;margin-top:2px}
.lbl-total{color:#b45309;font-weight:700}
.val-total{color:#b45309;font-weight:800;font-size:16px}
.invoice-bottom-details-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;background:#f8fafc;border-radius:8px;padding:12px;border:1px solid #f1f5f9}
.details-pane-block h4{font-size:11px;font-weight:700;color:#b45309;margin-bottom:4px}
.pane-content-text{font-size:10px;color:#475569;line-height:1.4}
.banking-details-table{width:100%;border-collapse:collapse;font-size:10px}
.banking-details-table td{padding:2px 0;vertical-align:top;color:#475569}
.banking-details-table td:first-child{width:70px;font-weight:500}
.d-modern .brand-logo-icon{background:#0d2141;color:#fff}
.d-modern .document-type-title{color:#0d2141}
.d-classic .brand-logo-icon{background:#166534;color:#fff}
.d-classic .document-type-title{color:#166534}
.d-classic .preview-sheet{border:1px solid #cbd5e1}
.d-minimal .brand-logo-icon{background:#475569;border-radius:50%;color:#fff}
.d-minimal .document-type-title{color:#475569;font-weight:500}
.d-minimal .table-header-row{color:#94a3b8}
.d-professional .brand-logo-icon{background:#0f766e;color:#fff}
.d-professional .document-type-title{color:#14b8a6}
.d-professional .preview-sheet{border-color:#0f172a;background:#fcfdff}
.d-creative .brand-logo-icon{background:#7c3aed;color:#fff}
.d-creative .document-type-title{color:#7c3aed}
.d-creative .preview-sheet{border-top:3px solid #7c3aed}
.d-creative .table-header-row{color:#7c3aed}
.d-bold{--bold-text:rgba(255,255,255,.85);--bold-muted:rgba(255,255,255,.5);--bold-dim:rgba(255,255,255,.35)}
.d-bold .brand-logo-icon{background:#dc2626;color:#fff}
.d-bold .document-type-title{color:var(--bold-muted)}
.d-bold .preview-sheet{background:#0f172a;border-color:#0f172a}
.d-bold .company-name{color:#fff}
.d-bold .company-address,.d-bold .company-contact{color:var(--bold-muted)}
.d-bold .invoice-id-number{color:var(--bold-dim)}
.d-bold .date-label{color:var(--bold-muted)}
.d-bold .date-val{color:var(--bold-text)}
.d-bold .bill-to-section h3{color:var(--bold-dim)}
.d-bold .client-name{color:#fff}
.d-bold .client-meta{color:var(--bold-muted)}
.d-bold .table-header-row{color:var(--bold-dim)}
.d-bold .table-data-row{color:var(--bold-text);border-bottom-color:rgba(255,255,255,.06)}
.d-bold .summary-line-row,.d-bold .lbl{color:var(--bold-muted)}
.d-bold .bold-dark{color:var(--bold-text)!important}
.d-bold .lbl-total{color:#f87171}
.d-bold .val-total{color:#f87171}
.d-bold .invoice-bottom-details-grid{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.1)}
.d-bold .details-pane-block h4{color:#f87171}
.d-bold .pane-content-text{color:var(--bold-muted)}
.d-bold .banking-details-table td{color:var(--bold-muted)}
.d-bold .date-row{color:var(--bold-muted)}
.d-receipt-standard .brand-logo-icon{background:#2563eb;color:#fff}
.d-receipt-standard .document-type-title{color:#2563eb}
.d-receipt-simple .brand-logo-icon{background:#059669;border-radius:50%;color:#fff}
.d-receipt-simple .document-type-title{color:#059669;font-weight:500}
.d-receipt-simple .table-header-row{color:#6ee7b7}
.d-receipt-detailed .brand-logo-icon{background:#e11d48;color:#fff}
.d-receipt-detailed .document-type-title{color:#e11d48}
.d-receipt-detailed .preview-sheet{border-top:3px solid #e11d48}
.d-receipt-detailed .table-header-row{color:#e11d48}
@media(max-width:480px){.preview-sheet{max-width:100%!important;padding:10px!important;margin:6px auto!important}}
</style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">

<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Settings</h2>
<p>Configure your account preferences</p>
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

<?php if (isset($_SESSION['flash_success'])): ?>
<div class="flash"><?= htmlspecialchars($_SESSION['flash_success']) ?><?php unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_error'])): ?>
<div class="flash error"><?= htmlspecialchars($_SESSION['flash_error']) ?><?php unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>

<div class="settings-grid">

<div class="s-card">
<div class="s-card-header">
<div class="s-card-header-left">
<div class="s-card-icon" style="background:var(--accent-bg)">
<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="1"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="12"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="16" y2="16"/></svg>
</div>
<div class="s-card-title">Business<br>Profile</div>
</div>
</div>
<form method="POST" enctype="multipart/form-data">
<?= csrf_field() ?>
<input type="hidden" name="action" value="save_profile">
<div class="field-group">
<label class="field-label">Business Name</label>
<input type="text" name="business_name" value="<?= htmlspecialchars($business_name) ?>" data-voice/>
</div>
<div class="field-group">
<label class="field-label">Abbreviation</label>
<input type="text" name="business_abbr" value="<?= htmlspecialchars($business_abbr) ?>" maxlength="5" placeholder="e.g. BIZ" style="text-transform:uppercase"/>
<p style="font-size:11px;color:var(--text-muted);margin-top:3px">Used in invoice &amp; receipt numbers (e.g. INV-BIZ-2026-001)</p>
</div>
<div class="field-group">
<label class="field-label">Business Email</label>
<input type="text" name="business_email" value="<?= htmlspecialchars($email) ?>"/>
</div>
<div class="field-group">
<label class="field-label">Full Name</label>
<input type="text" name="full_name" value="<?= htmlspecialchars($full_name) ?>"/>
</div>
<div class="field-group">
<label class="field-label">Phone</label>
<input type="text" name="business_phone" value="<?= htmlspecialchars($business_phone ?? '') ?>"/>
</div>
<div class="field-group">
<label class="field-label">Business Address</label>
<textarea name="business_address" rows="2" data-voice><?= htmlspecialchars($business_address ?? '') ?></textarea>
</div>
<div class="field-group">
<label class="field-label">Personal Phone</label>
<input type="text" name="phone" value="<?= htmlspecialchars($phone ?? '') ?>" placeholder="e.g. 08012345678"/>
</div>
<div class="field-group">
<label class="field-label">Currency</label>
<select name="currency" class="settings-select">
<option value="NGN"<?= $currency === 'NGN' ? ' selected' : '' ?>>NGN (₦) - Nigerian Naira</option>
<option value="USD"<?= $currency === 'USD' ? ' selected' : '' ?>>USD ($) - US Dollar</option>
<option value="EUR"<?= $currency === 'EUR' ? ' selected' : '' ?>>EUR (€) - Euro</option>
<option value="GBP"<?= $currency === 'GBP' ? ' selected' : '' ?>>GBP (£) - British Pound</option>
</select>
</div>
<div class="field-group">
<label class="field-label">Business Logo</label>
<div class="logo-row">
<input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" class="logo-input">
<?php if ($logo_path): ?>
<div class="logo-preview-wrap"><img src="<?= htmlspecialchars($logo_path) ?>" class="logo-preview"><label class="logo-remove-label"><input type="checkbox" name="remove_logo" value="1" onchange="this.closest('.logo-preview-wrap').style.display=this.checked?'none':''"> Remove logo</label></div>
<?php endif; ?>
</div>
<p style="font-size:11px;color:var(--text-muted);margin-top:3px">Accepted: PNG, JPG, GIF, WebP. Max 2MB.</p>
</div>
<div class="divider"></div>
<p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:10px">Default Notes</p>
<div class="field-group">
<label class="field-label">Default Invoice Notes</label>
<textarea name="invoice_default_notes" rows="3" data-voice data-voice-append><?= htmlspecialchars($invoice_default_notes ?? '') ?></textarea>
</div>
<div class="field-group" style="margin-bottom:18px">
<label class="field-label">Default Receipt Notes</label>
<textarea name="receipt_default_notes" rows="3" data-voice data-voice-append><?= htmlspecialchars($receipt_default_notes ?? '') ?></textarea>
</div>
<div class="divider"></div>
<p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:10px">Bank Details</p>
<div class="field-group">
<label class="field-label">Bank Name</label>
<input type="text" name="bank_name" value="<?= htmlspecialchars($bank_name ?? '') ?>" placeholder="e.g. First Bank of Nigeria" data-voice/>
</div>
<div class="field-group">
<label class="field-label">Account Name</label>
<input type="text" name="account_name" value="<?= htmlspecialchars($account_name ?? '') ?>" placeholder="e.g. BizVaza HQ" data-voice/>
</div>
<div class="field-group" style="margin-bottom:18px">
<label class="field-label">Account Number</label>
<input type="text" name="account_number" value="<?= htmlspecialchars($account_number ?? '') ?>" placeholder="e.g. 0123456789"/>
</div>
<button class="btn btn-primary" type="submit">Save Business Info</button>
</form>
</div>

<div class="s-card">
<div class="s-card-header">
<div class="s-card-header-left">
<div class="s-card-icon" style="background:var(--accent-bg)">
<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
</div>
<div class="s-card-title">Change<br>Password</div>
</div>
</div>
<form method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" value="change_password">
<div class="field-group">
<label class="field-label">Current Password</label>
<div class="pw-wrap">
<input type="password" name="current_password" required style="padding-right:36px"/>
<button type="button" class="pw-toggle" onclick="togglePw(this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div>
</div>
<div class="field-group">
<label class="field-label">New Password</label>
<div class="pw-wrap">
            <input type="password" name="new_password" required minlength="8" style="padding-right:36px"/>
<button type="button" class="pw-toggle" onclick="togglePw(this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div>
</div>
<div class="field-group" style="margin-bottom:18px">
<label class="field-label">Confirm New Password</label>
<div class="pw-wrap">
            <input type="password" name="confirm_password" required minlength="8" style="padding-right:36px"/>
<button type="button" class="pw-toggle" onclick="togglePw(this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div>
</div>
<button class="btn btn-primary" type="submit">Update Password</button>
</form>
 </div>

<div class="s-card">
<div class="s-card-header">
<div class="s-card-header-left">
<div class="s-card-icon" style="background:<?= $is_pro ? '#ecfdf5' : 'var(--accent-bg)' ?>">
<?php if ($is_pro): ?>
<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
<?php else: ?>
<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
<?php endif; ?>
</div>
<div class="s-card-title">Plan &<br>Templates</div>
</div>
<div class="s-plan-badge <?= $is_pro ? 'pro' : 'free' ?>"><?= htmlspecialchars($plan_name) ?></div>
</div>

<?php if ($is_pro): ?>
<form method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" value="save_template">

<div class="field-group">
<label class="field-label">Invoice Template</label>
<select name="selected_template_id" class="settings-select" id="invTemplateSelect" onchange="renderInvoicePreview(this.value)">
<option value="">— Default Layout —</option>
<?php foreach ($invoice_templates as $t): ?>
<option value="<?= $t['id'] ?>"<?= $selected_template_id == $t['id'] ? ' selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
<?php endforeach; ?>
</select>
</div>

<div id="invoicePreview" class="template-preview"></div>

<div class="divider"></div>

<div class="field-group">
<label class="field-label">Receipt Template</label>
<select name="selected_receipt_template_id" class="settings-select" id="recTemplateSelect" onchange="renderReceiptPreview(this.value)">
<option value="">— Default Layout —</option>
<?php foreach ($receipt_templates as $t): ?>
<option value="<?= $t['id'] ?>"<?= $selected_receipt_template_id == $t['id'] ? ' selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
<?php endforeach; ?>
</select>
</div>

<div id="receiptPreview" class="template-preview"></div>

<button class="btn btn-primary" type="submit" style="margin-top:18px">Save Templates</button>
</form>
<script>
var sampleData = {
    company: 'Your Company',
    address: '254 AdaGeorge Road, Rumuochita,<br>Portharcourt, Nigeria.',
    phone: '+234 812 345 6789',
    email: 'hello@yourcompany.com',
    client: 'Sample Client',
    clientPhone: '+234 801 234 5678',
    clientEmail: 'client@example.com',
    clientAddress: '12 Main Street, Lagos, Nigeria.',
    items: [
        { name: 'Brand Design', type: 'service', qty: 1, price: 200000, total: 200000 },
        { name: 'Web Development', type: 'service', qty: 1, price: 350000, total: 350000 },
        { name: 'SEO Package', type: 'service', qty: 1, price: 75000, total: 75000 }
    ],
    subtotal: 625000,
    discount: 0,
    total: 625000,
    bank: 'First Bank of Nigeria',
    acctName: 'Your Company',
    acctNum: '0123456789',
    notes: 'Payment is due within 30 days. Thank you for your business.'
};
function renderInvoicePreview(themeClass) {
    var c = document.getElementById('invoicePreview');
    var cn = themeClass || '';
    var rows = '';
    for (var i = 0; i < sampleData.items.length; i++) {
        var it = sampleData.items[i];
        rows += '<div class="table-data-row"><div class="col-idx">' + (i+1) + '</div><div class="col-desc highlight-text">' + it.name + ' <span style="font-size:10px;color:#64748b;font-weight:400;background:#f1f5f9;padding:1px 6px;border-radius:4px;margin-left:4px">' + it.type + '</span></div><div class="col-qty">' + it.qty + '</div><div class="col-price">' + it.price.toLocaleString() + '.00</div><div class="col-total">' + it.total.toLocaleString() + '.00</div></div>';
    }
    c.innerHTML = '<div class="preview-sheet' + (cn ? ' ' + cn : '') + '"><div class="invoice-meta-header"><div class="brand-block"><div class="brand-identity"><div class="brand-logo-icon"><svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-4 6h-4v2h4v2h-4v2h4v2H9V7h6v2z"/></svg></div><div class="brand-details"><h1 class="company-name" style="font-size:13px">' + sampleData.company + '</h1><p class="company-address" style="font-size:10px">' + sampleData.address + '</p><p class="company-contact" style="font-size:10px">' + sampleData.phone + '</p></div></div></div><div class="document-status-block"><span class="status-badge pending" style="font-size:9px;padding:2px 8px">Pending</span><h2 class="document-type-title" style="font-size:16px">INVOICE</h2><p class="invoice-id-number" style="font-size:11px">INV-001</p><div class="date-summary-table"><div class="date-row" style="font-size:11px"><span class="date-label">Issue Date:</span><span class="date-val">Jul 8, 2026</span></div><div class="date-row" style="font-size:11px"><span class="date-label">Due Date:</span><span class="date-val">Aug 7, 2026</span></div></div></div></div><div class="bill-to-section"><h3 style="font-size:11px">Bill To:</h3><p class="client-name" style="font-size:13px">' + sampleData.client + '</p><p class="client-meta" style="font-size:11px">' + sampleData.clientPhone + '</p><p class="client-meta" style="font-size:11px">' + sampleData.clientEmail + '</p></div><div class="receipt-table"><div class="table-header-row" style="font-size:10px;padding:6px 10px"><div class="col-idx">#</div><div class="col-desc">Item Description</div><div class="col-qty">Qty</div><div class="col-price">Unit Price</div><div class="col-total">Amount</div></div>' + rows + '</div><div class="financials-summary-container"><div class="financials-inner-box"><div class="summary-line-row" style="font-size:11px"><span class="lbl">Subtotal</span><span class="val bold-dark">' + sampleData.subtotal.toLocaleString() + '.00</span></div><div class="summary-line-row total-row-divider" style="font-size:11px"><span class="lbl-total">Total Amount</span><span class="val-total" style="font-size:16px">' + sampleData.total.toLocaleString() + '.00</span></div></div></div><div class="invoice-bottom-details-grid" style="padding:12px"><div class="details-pane-block"><h4 style="font-size:11px">Notes</h4><p class="pane-content-text" style="font-size:10px">' + sampleData.notes + '</p></div><div class="details-pane-block"><h4 style="font-size:11px">Payment Details</h4><table class="banking-details-table"><tr><td style="font-size:10px">Bank Name</td><td class="bold-dark" style="font-size:10px">' + sampleData.bank + '</td></tr><tr><td style="font-size:10px">Account Name</td><td class="bold-dark" style="font-size:10px">' + sampleData.acctName + '</td></tr><tr><td style="font-size:10px">Account Number</td><td class="bold-dark" style="font-size:10px">' + sampleData.acctNum + '</td></tr></table></div></div><div style="text-align:center;margin-top:16px;padding-top:8px;border-top:1px solid #e5e7eb;font-size:9px;color:#94a3b8">Powered by BizVaza</div></div>';
}
function renderReceiptPreview(themeClass) {
    var c = document.getElementById('receiptPreview');
    var cn = themeClass || '';
    var rows = '';
    for (var i = 0; i < sampleData.items.length; i++) {
        var it = sampleData.items[i];
        rows += '<div class="table-data-row"><div class="col-idx">' + (i+1) + '</div><div class="col-desc highlight-text">' + it.name + '</div><div class="col-qty">' + it.qty + '</div><div class="col-price">' + it.price.toLocaleString() + '.00</div><div class="col-total">' + it.total.toLocaleString() + '.00</div></div>';
    }
    c.innerHTML = '<div class="preview-sheet' + (cn ? ' ' + cn : '') + '"><div class="invoice-meta-header"><div class="brand-block"><div class="brand-identity"><div class="brand-logo-icon"><svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-4 6h-4v2h4v2h-4v2h4v2H9V7h6v2z"/></svg></div><div class="brand-details"><h1 class="company-name" style="font-size:13px">' + sampleData.company + '</h1><p class="company-address" style="font-size:10px">' + sampleData.address + '</p><p class="company-contact" style="font-size:10px">' + sampleData.phone + '</p></div></div></div><div class="document-status-block"><span class="status-badge paid" style="font-size:9px;padding:2px 8px">Paid</span><h2 class="document-type-title" style="font-size:16px">RECEIPT</h2><p class="invoice-id-number" style="font-size:11px">RCT-001</p><div class="date-summary-table"><div class="date-row" style="font-size:11px"><span class="date-label">Payment Date:</span><span class="date-val">Jul 8, 2026</span></div></div></div></div><div class="bill-to-section"><h3 style="font-size:11px">Received From:</h3><p class="client-name" style="font-size:13px">' + sampleData.client + '</p><p class="client-meta" style="font-size:11px">' + sampleData.clientEmail + '</p><p class="client-meta" style="font-size:11px">' + sampleData.clientPhone + '</p></div><div class="receipt-table"><div class="table-header-row" style="font-size:10px;padding:6px 10px"><div class="col-idx">#</div><div class="col-desc">Item Description</div><div class="col-qty">Qty</div><div class="col-price">Rate</div><div class="col-total">Amount</div></div>' + rows + '</div><div class="financials-summary-container"><div class="financials-inner-box"><div class="summary-line-row total-row-divider" style="font-size:11px"><span class="lbl-total">Total Received</span><span class="val-total" style="font-size:16px">' + sampleData.total.toLocaleString() + '.00</span></div></div></div><div style="text-align:center;margin-top:16px;padding-top:8px;border-top:1px solid #e5e7eb;font-size:9px;color:#94a3b8">Powered by BizVaza</div></div>';
}
window.addEventListener('DOMContentLoaded', function() {
    renderInvoicePreview(document.getElementById('invTemplateSelect').value);
    renderReceiptPreview(document.getElementById('recTemplateSelect').value);
});
</script>
<?php else: ?>
<div style="display:flex;align-items:center;gap:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px">
<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
<div>
<p style="font-weight:600;color:#92400e;font-size:13px">Unlock Premium Templates</p>
<p style="font-size:12px;color:var(--accent);margin-top:2px">Upgrade to Pro to access <?= htmlspecialchars($proTemplateText) ?>.</p>
<a href="pricing.php" style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:7px 14px;background:var(--accent);color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none">View Plans <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></a>
</div>
</div>
<?php endif; ?>
</div>

<!-- Review Card -->
<div class="s-card">
<div class="s-card-header">
<div class="s-card-header-left">
<div class="s-card-icon" style="background:var(--accent-bg)">
<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20l-7 4 2-8.5L1 10l8.6-.7L12 2l2.4 7.3L23 10l-6 5.5 2 8.5z"/></svg>
</div>
<div class="s-card-title">Leave a Review</div>
</div>
<?php if ($latest_review): ?>
<span class="s-plan-badge <?= $latest_review['status'] ?>"><?= ucfirst($latest_review['status']) ?></span>
<?php endif; ?>
</div>

<form method="POST" onsubmit="return validateReviewForm()">
<?= csrf_field() ?>
<input type="hidden" name="action" value="submit_review">
<input type="hidden" name="rating" id="reviewRating" value="<?= $latest_review ? (int)$latest_review['rating'] : 5 ?>">

<div class="field-group">
<label class="field-label">Rating</label>
<div class="star-rating" id="starRating">
<?php for ($i = 1; $i <= 5; $i++): ?>
<svg class="star <?= ($latest_review && $i <= (int)$latest_review['rating']) ? 'filled' : 'empty' ?>" data-value="<?= $i ?>" width="28" height="28" viewBox="0 0 24 24" fill="<?= ($latest_review && $i <= (int)$latest_review['rating']) ? '#b45309' : 'none' ?>" stroke="var(--accent)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
<?php endfor; ?>
</div>
</div>

<div class="field-group">
<label class="field-label">Your Review</label>
<textarea name="review_text" id="reviewText" rows="4" style="width:100%;padding:8px 11px;border:1px solid var(--border-input);border-radius:7px;font-size:13px;color:var(--text);outline:none;font-family:'Inter',sans-serif;resize:vertical" placeholder="Tell others what you think about BizVaza…"><?= $latest_review ? htmlspecialchars($latest_review['review_text']) : '' ?></textarea>
</div>

<button class="btn btn-primary" type="submit"><?= $latest_review ? 'Update Review' : 'Submit Review' ?></button>
</form>

<?php if ($latest_review && $latest_review['status'] !== 'approved'): ?>
<form method="POST" style="margin-top:8px" onsubmit="return confirm('Delete this review?')">
<?= csrf_field() ?>
<input type="hidden" name="action" value="delete_review">
<input type="hidden" name="review_id" value="<?= $latest_review['id'] ?>">
<button type="submit" style="background:none;border:none;color:var(--error);font-size:12px;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:underline">Delete Review</button>
</form>
<?php endif; ?>
</div>

</div>
</div>
<script src="assets/js/app.js"></script>
<script src="assets/js/speech-input.js"></script>
<script>
var stars = document.querySelectorAll('.star-rating .star');
var ratingInput = document.getElementById('reviewRating');
stars.forEach(function(s) {
    s.addEventListener('mouseover', function() {
        var val = parseInt(this.getAttribute('data-value'));
        stars.forEach(function(st, i) {
            st.setAttribute('fill', i < val ? '#b45309' : 'none');
            st.classList.toggle('filled', i < val);
        });
    });
    s.addEventListener('mouseout', function() {
        var val = parseInt(ratingInput.value);
        stars.forEach(function(st, i) {
            st.setAttribute('fill', i < val ? '#b45309' : 'none');
            st.classList.toggle('filled', i < val);
        });
    });
    s.addEventListener('click', function() {
        ratingInput.value = this.getAttribute('data-value');
        var val = parseInt(ratingInput.value);
        stars.forEach(function(st, i) {
            st.setAttribute('fill', i < val ? '#b45309' : 'none');
            st.classList.toggle('filled', i < val);
        });
    });
});
function validateReviewForm() {
    var text = document.getElementById('reviewText').value.trim();
    if (!text) { alert('Please write your review.'); return false; }
    return true;
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>

</div>
</body>
</html>
