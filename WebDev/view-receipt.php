<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$activePage = 'receipts';
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$business_name = $_SESSION['business_name'];
$avatar_initials = $_SESSION['avatar_initials'];
$email = $_SESSION['email'];

$receipt_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$receipt = null;
if ($receipt_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND user_id = ?");
    $stmt->execute([$receipt_id, $user_id]);
    $receipt = $stmt->fetch();
}
if (!$receipt) {
    header('Location: receipts.php');
    exit;
}

$items = [];
$invoice = null;
if ($receipt['invoice_number']) {
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ? AND user_id = ?");
    $stmt->execute([$receipt['invoice_number'], $user_id]);
    $invoice = $stmt->fetch();
    if ($invoice) {
        $stmt = $pdo->prepare("SELECT ii.* FROM invoice_items ii WHERE ii.invoice_id = ?");
        $stmt->execute([$invoice['id']]);
        $items = $stmt->fetchAll();
    }
}
if (!$invoice) {
    $stmt = $pdo->prepare("SELECT * FROM receipt_items WHERE receipt_id = ? ORDER BY id");
    $stmt->execute([$receipt_id]);
    $items = $stmt->fetchAll();
}

// Mark Balance as Received: finalize this receipt as fully settled without
// creating a follow-up receipt. Standalone receipts are brought up to the
// items total; invoice-linked receipts are bumped up to the invoice's full
// live remaining total. Guarded by CSRF + confirmation on the button.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_balance_received') {
    validate_csrf();
    $finalize_amount = floatval($receipt['amount']);
    $outstanding = 0.0;

    if ($receipt['invoice_number'] === '' || $receipt['invoice_number'] === null) {
        $rcpt_items_total = 0.0;
        foreach ($items as $it) { $rcpt_items_total += floatval($it['amount']); }
        if ($rcpt_items_total > 0) {
            $outstanding = max(0.0, $rcpt_items_total - floatval($receipt['amount']));
            $finalize_amount = $rcpt_items_total;
        } else {
            $outstanding = floatval($receipt['balance']);
        }
    } elseif ($invoice) {
        $inv_live = invoicePaymentTotals($pdo, $user_id, $receipt['invoice_number']);
        $outstanding = $inv_live['remaining'];
        $finalize_amount = round($inv_live['paid_sum'] + $outstanding, 2);
    }

    if ($outstanding > 0.0) {
        $marker = 'Balance fully received on ' . date('F j, Y') . ' - marked as settled without a follow-up receipt.';
        $existing_notes = trim((string)($receipt['notes'] ?? ''));
        $new_notes = $existing_notes === '' ? $marker : $existing_notes . "\r\n" . $marker;
        $stmt = $pdo->prepare("UPDATE payments SET amount = ?, balance = 0, notes = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$finalize_amount, $new_notes, $receipt_id, $user_id]);
        logActivity($pdo, $user_id, 'payment_recorded', "Receipt {$receipt['receipt_number']} finalized - full balance of ₦" . number_format($finalize_amount, 2) . ' marked as received.', 'payment', $receipt_id);
        $_SESSION['flash_success'] = "Receipt {$receipt['receipt_number']} is now fully settled.";
        header('Location: view-receipt.php?id=' . $receipt_id);
        exit;
    }
    $_SESSION['flash_error'] = 'This receipt has no outstanding balance to settle.';
    header('Location: view-receipt.php?id=' . $receipt_id);
    exit;
}

// Derive a type-aware label from the line items (product vs service, pluralized).
$product_count = 0;
$service_count = 0;
foreach ($items as $it_item) {
    if (($it_item['type'] ?? 'product') === 'service') {
        $service_count++;
    } else {
        $product_count++;
    }
}
if ($product_count > 0 && $service_count > 0) {
    $item_type_word = 'Products & Services';
} elseif ($product_count > 0) {
    $item_type_word = $product_count > 1 ? 'Products' : 'Product';
} elseif ($service_count > 0) {
    $item_type_word = $service_count > 1 ? 'Services' : 'Service';
} else {
    $item_type_word = 'Item';
}

// Load template: receipt preference first, then its linked invoice, Pro-only.
$template_class = '';
if (isPro()) {
    $tid = !empty($receipt['template_id']) ? (int)$receipt['template_id'] : (int)($invoice['template_id'] ?? 0);
    if ($tid) {
        $stmt = $pdo->prepare("SELECT theme_class FROM templates WHERE id = ?");
        $stmt->execute([$tid]);
        $template_class = $stmt->fetchColumn();
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BizVaza Receipt - <?= htmlspecialchars($receipt['receipt_number']) ?></title>
    <link rel="stylesheet" href="create-invoice.css?v=<?= @filemtime(__DIR__ . '/create-invoice.css') ?>">
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
    @media print {
        .sidebar, .sidebar-overlay, .invoice-topbar, .icon-btn, .avatar,
        .notif-panel, .notif-overlay, .back-link, .distribution-footer-row, footer {
            display: none !important;
        }
        .main-content, .content {
            padding: 0 !important;
            margin: 0 !important;
            max-width: 100% !important;
        }
    }
    </style>
</head>
<body class="dashboard-body">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">

<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Receipt</h2>
<p><?= htmlspecialchars($receipt['receipt_number']) ?></p>
</div>
</div>
<div class="invoice-topbar-right">
<button class="icon-btn" id="bellBtn" onclick="toggleNotif(event)" style="position:relative">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
<span class="bell-dot"></span>
</button>
<?php require __DIR__ . '/includes/user-menu.php'; ?>
</div>
</header>

<div class="content">

<?php if (isset($_SESSION['flash_success'])): ?>
<div class="flash-success"><?= htmlspecialchars($_SESSION['flash_success']) ?><?php unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_error'])): ?>
<div class="flash-error"><?= htmlspecialchars($_SESSION['flash_error']) ?><?php unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>

    <a href="receipts.php" class="back-link" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:#64748b;font-size:13px;font-weight:500;margin-bottom:16px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Back to Receipts
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
                                <p class="company-address"><?= nl2br(htmlspecialchars($_SESSION['business_address'] ?? '')) ?></p>
                                <p class="company-contact"><?= htmlspecialchars($_SESSION['business_phone'] ?? '') ?></p>
                                <p class="company-contact"><?= htmlspecialchars($email) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="document-status-block">
                        <span class="status-badge <?= $receipt['status'] ?>"><?= ucfirst($receipt['status']) ?></span>
                        <h2 class="document-type-title" style="color:#059669">RECEIPT</h2>
                        <p class="invoice-id-number" style="color:#059669"><?= htmlspecialchars($receipt['receipt_number']) ?></p>

                        <div class="date-summary-table">
                            <div class="date-row"><span class="date-label">Payment Date:</span><span class="date-val"><?= $receipt['payment_date'] ? date('F j, Y', strtotime($receipt['payment_date'])) : '--' ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="bill-to-section">
                    <h3>Received From:</h3>
                    <p class="client-name"><?= htmlspecialchars($receipt['customer_name']) ?></p>
                    <?php if ($invoice): ?>
                    <p class="client-meta"><?= htmlspecialchars($invoice['customer_email']) ?></p>
                    <p class="client-meta"><?= htmlspecialchars($invoice['customer_phone']) ?></p>
                    <?php endif; ?>
                    <?php if ($receipt['invoice_number']): ?>
                    <p class="client-meta">Invoice: <?= htmlspecialchars($receipt['invoice_number']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($receipt['linked_receipt'])): ?>
                    <p class="client-meta">Settles receipt: <?= htmlspecialchars($receipt['linked_receipt']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="receipt-table">
                    <div class="table-header-row">
                        <div class="col-idx">#</div>
                        <div class="col-desc"><?= htmlspecialchars($item_type_word) ?> Description</div>
                        <div class="col-qty">Qty</div>
                        <div class="col-price">Rate (₦)</div>
                        <div class="col-total">Amount (₦)</div>
                    </div>

<?php if (count($items) > 0): ?>
<?php $idx = 1; ?>
<?php foreach ($items as $it): ?>
                    <div class="table-data-row">
                        <div class="col-idx"><?= $idx++ ?></div>
                        <div class="col-desc highlight-text"><?= htmlspecialchars($it['name']) ?> <?php if ($it['description']): ?><span style="font-size:10px;color:#64748b;font-weight:400;background:#f1f5f9;padding:1px 6px;border-radius:4px;margin-left:4px"><?= htmlspecialchars($it['description']) ?></span><?php endif; ?></div>
                        <div class="col-qty"><?= intval($it['quantity']) ?></div>
                        <div class="col-price"><?= number_format(floatval($it['unit_price']), 2) ?></div>
                        <div class="col-total"><?= number_format(floatval($it['amount']), 2) ?></div>
                    </div>
<?php endforeach; ?>
<?php else: ?>
                    <div class="table-data-row">
                        <div class="col-idx">1</div>
                        <div class="col-desc highlight-text">Payment Receipt</div>
                        <div class="col-qty">1</div>
                        <div class="col-price"><?= number_format(floatval($receipt['amount']), 2) ?></div>
                        <div class="col-total"><?= number_format(floatval($receipt['amount']), 2) ?></div>
                    </div>
<?php endif; ?>
                </div>

                <div class="financials-summary-container">
                    <div class="financials-inner-box">
                        <?php if ($invoice): ?>
                        <div class="summary-line-row">
                            <span class="lbl-total">Invoice Total</span>
                            <span class="val-total">₦<?= number_format(floatval($invoice['amount']), 2) ?></span>
                        </div>
                        <?php elseif (count($items) > 0):
                            $rcpt_items_total = 0;
                            foreach ($items as $it) { $rcpt_items_total += floatval($it['amount']); } ?>
                        <div class="summary-line-row">
                            <span class="lbl-total"><?= htmlspecialchars($item_type_word) ?> Total</span>
                            <span class="val-total">₦<?= number_format($rcpt_items_total, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="summary-line-row total-row-divider">
                            <span class="lbl-total">Amount Received</span>
                            <span class="val-total">₦<?= number_format(floatval($receipt['amount']), 2) ?></span>
                        </div>
                        <?php $rcpt_balance = floatval($receipt['balance']); ?>
                        <?php if ($rcpt_balance > 0): ?>
                        <div class="summary-line-row total-row-divider">
                            <span class="lbl-total">Balance Due</span>
                            <span class="val-total" style="color:#dc2626">₦<?= number_format($rcpt_balance, 2) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                // Receive Balance entry point: standalone receipts settle via follow-up
                // receipts; invoice-linked receipts go through another receipt on the
                // invoice (live outstanding, not the stored snapshot).
                $rcpt_btn_url = '';
                $rcpt_btn_amount = 0.0;
                if ($receipt['invoice_number'] === '') {
                    if (floatval($receipt['balance']) > 0) {
                        $rcpt_btn_url = 'receipts.php?from_receipt=' . $receipt['id'];
                        $rcpt_btn_amount = floatval($receipt['balance']);
                    }
                } elseif ($invoice) {
                    $inv_live = invoicePaymentTotals($pdo, $user_id, $receipt['invoice_number']);
                    if ($inv_live['remaining'] > 0) {
                        $rcpt_btn_url = 'receipts.php?invoice_id=' . intval($invoice['id']);
                        $rcpt_btn_amount = $inv_live['remaining'];
                    }
                }
                ?>
                <?php if ($receipt['notes']): ?>
                <div class="invoice-bottom-details-grid" style="margin-top:24px">
                    <div class="details-pane-block">
                        <h4>Notes</h4>
                        <p class="pane-content-text"><?= nl2br(htmlspecialchars($receipt['notes'])) ?></p>
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
                <?php endif; ?>

                <div style="text-align:center;margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:11px;color:#94a3b8">Powered by BizVaza</div>

            </div>
            </div>

            <footer class="distribution-footer-row">
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
                <button class="btn-whatsapp-action" data-phone="<?= htmlspecialchars($invoice['customer_phone'] ?? '', ENT_QUOTES) ?>" onclick="shareViaWhatsApp(this)">
                    <svg class="btn-icon" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946C.06 5.348 5.397.01 12.008.01c3.202.001 6.212 1.246 8.477 3.513 2.266 2.268 3.507 5.28 3.505 8.484-.004 6.657-5.34 11.997-11.953 11.997-2.005-.001-3.973-.502-5.717-1.456L0 24zm6.59-4.846c1.6.95 3.188 1.449 4.825 1.451 5.436 0 9.86-4.37 9.864-9.799.002-2.63-1.023-5.101-2.885-6.966C16.528 1.975 14.066.95 11.453.95c-5.44 0-9.866 4.372-9.87 9.802 0 1.698.449 3.355 1.3 4.828l-.999 3.647 3.764-.973zm11.367-7.36c-.333-.167-1.974-.974-2.279-1.084-.303-.11-.525-.167-.745.167-.221.334-.856 1.084-1.05 1.306-.193.222-.388.247-.722.081-1.981-.983-3.216-1.646-4.51-3.847-.34-.585.34-.543.975-1.809.111-.222.056-.417-.028-.585-.084-.167-.745-1.793-1.022-2.459-.27-.649-.546-.562-.745-.572l-.638-.012c-.221 0-.582.083-.887.417-.306.334-1.166 1.141-1.166 2.784 0 1.643 1.192 3.226 1.359 3.449.166.222 2.347 3.585 5.687 5.021.794.342 1.415.547 1.899.702.798.254 1.525.218 2.1.132.641-.096 1.974-.807 2.251-1.587.278-.779.278-1.447.194-1.587-.084-.14-.306-.222-.64-.389z"/></svg>
                    Send via WhatsApp
                </button>
                <?php if ($rcpt_btn_url !== ''): ?>
                <a href="<?= $rcpt_btn_url ?>" class="btn-receipt-action" style="display:inline-flex;align-items:center;gap:6px">Receive Balance (₦<?= number_format($rcpt_btn_amount, 2) ?>)</a>
                <form method="POST" action="view-receipt.php?id=<?= $receipt_id ?>" class="mark-balance-form" onsubmit="return confirm('Mark the remaining balance as received WITHOUT creating a follow-up receipt? This cannot be undone.');">
                    <input type="hidden" name="action" value="mark_balance_received">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-receipt-action btn-receipt-muted">Mark Balance as Received</button>
                </form>
                <?php endif; ?>
            </footer>
            <script src="assets/js/app.js"></script>
        </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>

<script src="create-invoice.js?v=<?= @filemtime(__DIR__ . '/create-invoice.js') ?>"></script>
</body>
</html>
