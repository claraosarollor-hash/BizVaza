<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$business_name = $_SESSION['business_name'];
$avatar_initials = $_SESSION['avatar_initials'];
$email = $_SESSION['email'];
$activePage = 'invoices';

// Load invoice if id provided
$inv_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$computed_status = 'pending';
$inv = null;
$items = [];
$has_payment = null;
$fully_paid = false;
if ($inv_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND user_id = ?");
    $stmt->execute([$inv_id, $user_id]);
    $inv = $stmt->fetch();
    if ($inv) {
        $stmt = $pdo->prepare("SELECT id FROM payments WHERE invoice_number = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$inv['invoice_number'], $user_id]);
        $has_payment = $stmt->fetch();
        $totals = invoicePaymentTotals($pdo, $user_id, $inv['invoice_number']);
        $paid_sum = $totals['paid_sum'];
        $fully_paid = ($totals['amount'] > 0 && $paid_sum >= $totals['amount']);
        if ($fully_paid) {
            $computed_status = 'paid';
        } elseif ($paid_sum > 0) {
            $computed_status = 'partial';
        }
        // Load line items
        $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $stmt->execute([$inv_id]);
        $items = $stmt->fetchAll();
    }
}
$item_count = count($items);
// Derive a type-aware label from the line items (product vs service, pluralized).
$inv_product_count = 0;
$inv_service_count = 0;
foreach ($items as $it2) {
    if (($it2['type'] ?? 'product') === 'service') {
        $inv_service_count++;
    } else {
        $inv_product_count++;
    }
}
if ($inv_product_count > 0 && $inv_service_count > 0) {
    $inv_item_type_word = 'Products & Services';
} elseif ($inv_product_count > 0) {
    $inv_item_type_word = $inv_product_count > 1 ? 'Products' : 'Product';
} elseif ($inv_service_count > 0) {
    $inv_item_type_word = $inv_service_count > 1 ? 'Services' : 'Service';
} else {
    $inv_item_type_word = 'Item';
}
// Compute subtotal from items and derive discount
$subtotal = 0;
foreach ($items as $item) { $subtotal += floatval($item['amount']); }
$inv_amount = floatval($inv['amount'] ?? 0);
$discount_amt = $subtotal > 0 ? $subtotal - $inv_amount : 0;
if ($discount_amt < 0) $discount_amt = 0;
function fmtDate($d) { return $d ? date('F j, Y', strtotime($d)) : '--'; }

// Load template
$template_class = '';
if ($inv && $inv['template_id'] && isPro()) {
    $stmt = $pdo->prepare("SELECT theme_class FROM templates WHERE id = ?");
    $stmt->execute([$inv['template_id']]);
    $template_class = $stmt->fetchColumn();
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BizVaza Invoice - Preview</title>
    <link rel="stylesheet" href="create-invoice.css?v=<?= @filemtime(__DIR__ . '/create-invoice.css') ?>">
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-wrapper">
<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Invoice Preview</h2>
<p>Create and preview invoices</p>
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

    <main class="main-content">
        <a href="invoiceDashboard.php" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--text-muted);font-size:13px;font-weight:500;margin-bottom:16px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Invoices
        </a>
        <div class="preview-card">

            <div class="sheet-scroll">

            <div class="invoice-printable-sheet<?= $template_class ? ' ' . $template_class : '' ?>">
                
                <div class="invoice-meta-header">
                    <div class="brand-block">
                        <div class="brand-identity">
                            <div class="brand-logo-icon">
                                <?php if (!empty($_SESSION['logo_path'])): ?>
                                <img src="<?= htmlspecialchars($_SESSION['logo_path']) ?>" alt="Logo">
                                <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-4 6h-4v2h4v2h-4v2h4v2H9V7h6v2z"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="brand-details">
                                <h1 class="company-name"><?= htmlspecialchars($business_name ?: 'BizVaza') ?></h1>
                                <p class="company-address"><?= nl2br(htmlspecialchars($_SESSION['business_address'] ?? '254 AdaGeorge Road, Rumuochita,<br>Portharcourt, Nigeria.')) ?></p>
                                <p class="company-contact"><?= htmlspecialchars($_SESSION['business_phone'] ?? '+234 812 345 6789') ?></p>
                                <p class="company-contact"><?= htmlspecialchars($email) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="document-status-block">
                        <span class="status-badge <?= $computed_status ?>"><?= ucfirst($computed_status) ?></span>
                        <h2 class="document-type-title">INVOICE</h2>
                        <p class="invoice-id-number"><?= htmlspecialchars($inv['invoice_number'] ?? 'INV-000') ?></p>
                        
                        <div class="date-summary-table">
                            <div class="date-row"><span class="date-label">Issue Date:</span><span class="date-val"><?= fmtDate($inv['issue_date'] ?? '') ?></span></div>
                            <div class="date-row"><span class="date-label">Due Date:</span><span class="date-val"><?= fmtDate($inv['due_date'] ?? '') ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="bill-to-section">
                    <h3>Bill To:</h3>
                    <p class="client-name"><?= htmlspecialchars($inv['customer_name'] ?? '') ?></p>
                    <p class="client-meta"><?= htmlspecialchars($inv['customer_phone'] ?? '') ?></p>
                    <p class="client-meta"><?= htmlspecialchars($inv['customer_email'] ?? '') ?></p>
                    <p class="client-meta"><?= htmlspecialchars($inv['billing_address'] ?? '') ?></p>
                </div>

                <div class="receipt-table">
                    <div class="table-header-row">
                        <div class="col-idx">#</div>
                        <div class="col-desc"><?= htmlspecialchars($inv_item_type_word) ?> Description</div>
                        <div class="col-qty">Qty</div>
                        <div class="col-price">Unit Price (₦)</div>
                        <div class="col-total">Amount (₦)</div>
                    </div>

                    <?php if ($item_count > 0): ?>
                    <?php $idx = 1; foreach ($items as $item): ?>
                    <div class="table-data-row">
                        <div class="col-idx"><?= $idx++ ?></div>
                        <div class="col-desc highlight-text"><?= htmlspecialchars($item['name']) ?> <span style="font-size:10px;color:var(--text-muted);font-weight:400;background:#f1f5f9;padding:1px 6px;border-radius:4px;margin-left:4px"><?= htmlspecialchars($item['type'] ?? 'product') ?></span></div>
                        <div class="col-qty"><?= intval($item['quantity']) ?></div>
                        <div class="col-price"><?= number_format(floatval($item['unit_price']), 2) ?></div>
                        <div class="col-total"><?= number_format(floatval($item['amount']), 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="table-data-row">
                        <div class="col-idx" colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">No line items</div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="financials-summary-container">
                    <div class="financials-inner-box">
                        <div class="summary-line-row">
                            <span class="lbl">Subtotal</span>
                            <span class="val bold-dark">₦<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="summary-line-row">
                            <span class="lbl">Discount (<?= htmlspecialchars($inv['discount_type'] ?? 'Percentage') ?>)</span>
                            <span class="val bold-dark">₦<?= number_format($discount_amt, 2) ?></span>
                        </div>
                        <div class="summary-line-row total-row-divider">
                            <span class="lbl-total">Total Amount</span>
                            <span class="val-total">₦<?= number_format($inv_amount, 2) ?></span>
                        </div>
                    </div>
                </div>

                <div class="invoice-bottom-details-grid">
                    <div class="details-pane-block">
                        <h4>Notes</h4>
                        <p class="pane-content-text"><?= nl2br(htmlspecialchars($inv['notes'] ?? '')) ?></p>
                    </div>
                    <div class="details-pane-block">
                        <h4>Payment Details</h4>
                        <table class="banking-details-table">
                            <tr><td>Bank Name</td><td class="bold-dark"><?= htmlspecialchars($_SESSION['bank_name'] ?? 'First Bank of Nigeria') ?></td></tr>
                            <tr><td>Account Name</td><td class="bold-dark"><?= htmlspecialchars($_SESSION['account_name'] ?: ($business_name ?: 'BizVaza')) ?></td></tr>
                            <tr><td>Account Number</td><td class="bold-dark"><?= htmlspecialchars($_SESSION['account_number'] ?? '0123456789') ?></td></tr>
                        </table>
                    </div>
                </div>

                <div style="text-align:center;margin-top:32px;padding-top:16px;border-top:1px solid var(--border);font-size:11px;color:var(--text-muted)">Powered by BizVaza</div>
            </div>

            </div>

            <footer class="distribution-footer-row">
<?php if ($has_payment && !$fully_paid): ?>
    <a href="view-receipt.php?id=<?= $has_payment['id'] ?>" class="btn-receipt-action" style="background:var(--success)">
        <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        View Receipt
    </a>
    <a href="receipts.php?invoice_id=<?= $inv_id ?>" class="btn-receipt-action">
        <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Generate Receipt
    </a>
<?php elseif ($has_payment): ?>
    <a href="view-receipt.php?id=<?= $has_payment['id'] ?>" class="btn-receipt-action" style="background:var(--success)">
        <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        View Receipt
    </a>
<?php else: ?>
    <a href="receipts.php?invoice_id=<?= $inv_id ?>" class="btn-receipt-action">
        <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Generate Receipt
    </a>
<?php endif; ?>
    <div class="download-wrap">
        <button class="btn-utility-action" onclick="toggleDownloadMenu(event)">
            <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Download
            <svg class="btn-icon download-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9l6 6 6-6"/></svg>
        </button>
        <div class="download-menu">
            <button class="download-menu-item" onclick="downloadPdf(event)">
                <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                Download as PDF
            </button>
            <button class="download-menu-item" onclick="downloadImage(event)">
                <svg class="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Download as Image
            </button>
        </div>
    </div>
    <button class="btn-whatsapp-action" data-phone="<?= htmlspecialchars($inv['customer_phone'] ?? '', ENT_QUOTES) ?>" onclick="shareViaWhatsApp(this)">
        <svg class="btn-icon" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946C.06 5.348 5.397.01 12.008.01c3.202.001 6.212 1.246 8.477 3.513 2.266 2.268 3.507 5.28 3.505 8.484-.004 6.657-5.34 11.997-11.953 11.997-2.005-.001-3.973-.502-5.717-1.456L0 24zm6.59-4.846c1.6.95 3.188 1.449 4.825 1.451 5.436 0 9.86-4.37 9.864-9.799.002-2.63-1.023-5.101-2.885-6.966C16.528 1.975 14.066.95 11.453.95c-5.44 0-9.866 4.372-9.87 9.802 0 1.698.449 3.355 1.3 4.828l-.999 3.647 3.764-.973zm11.367-7.36c-.333-.167-1.974-.974-2.279-1.084-.303-.11-.525-.167-.745.167-.221.334-.856 1.084-1.05 1.306-.193.222-.388.247-.722.081-1.981-.983-3.216-1.646-4.51-3.847-.34-.585.34-.543.975-1.809.111-.222.056-.417-.028-.585-.084-.167-.745-1.793-1.022-2.459-.27-.649-.546-.562-.745-.572l-.638-.012c-.221 0-.582.083-.887.417-.306.334-1.166 1.141-1.166 2.784 0 1.643 1.192 3.226 1.359 3.449.166.222 2.347 3.585 5.687 5.021.794.342 1.415.547 1.899.702.798.254 1.525.218 2.1.132.641-.096 1.974-.807 2.251-1.587.278-.779.278-1.447.194-1.587-.084-.14-.306-.222-.64-.389z"/></svg>
        Send via WhatsApp
    </button>
</footer>

        </div>
    </main>
    <script src="create-invoice.js?v=<?= @filemtime(__DIR__ . '/create-invoice.js') ?>"></script>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</div>
<?php require __DIR__ . '/includes/notification.php'; ?>
<script src="assets/js/app.js"></script>
</body>
</html>
