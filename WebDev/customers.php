<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$avatar_initials = $_SESSION['avatar_initials'];

$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 10;
$search = trim($_GET['q'] ?? '');

$balanceSubquery = "(SELECT COALESCE(SUM(GREATEST(0, i.amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0))), 0) FROM invoices i WHERE i.user_id = c.user_id AND (i.customer_name = c.name OR i.customer_name LIKE CONCAT(c.name, '%') OR c.name LIKE CONCAT(i.customer_name, '%')))";

if ($search !== '') {
    $like = "%{$search}%";
    $stmt = $pdo->prepare("SELECT c.*, {$balanceSubquery} as calculated_balance FROM customers c WHERE c.user_id = ? AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.company LIKE ?) ORDER BY c.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$user_id, $like, $like, $like, $like, $perPage, ($page - 1) * $perPage]);
    $customers = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE c.user_id = ? AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.company LIKE ?)");
    $stmt->execute([$user_id, $like, $like, $like, $like]);
} else {
    $stmt = $pdo->prepare("SELECT c.*, {$balanceSubquery} as calculated_balance FROM customers c WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$user_id, $perPage, ($page - 1) * $perPage]);
    $customers = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE c.user_id = ?");
    $stmt->execute([$user_id]);
}
$totalCustomers = $stmt->fetchColumn();
$totalPages = max(1, ceil($totalCustomers / $perPage));

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM customers WHERE user_id = ?");
$stmt->execute([$user_id]);
$cust_stats_total = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0, i.amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_number = i.invoice_number AND p.status = 'paid' AND p.user_id = i.user_id), 0))), 0) FROM invoices i WHERE i.user_id = ?");
$stmt->execute([$user_id]);
$total_outstanding = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE user_id = ? AND last_active >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
$stmt->execute([$user_id]);
$active_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM activity_log WHERE user_id = ? AND reference_type = 'customer' ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$timeline = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validate_csrf();

    if ($_POST['action'] === 'add_customer') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $billing_address = trim($_POST['billing_address'] ?? '');
        if ($name !== '') {
            $stmt = $pdo->prepare("INSERT INTO customers (user_id, name, email, phone, company, billing_address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $name, $email, $phone, $company, $billing_address]);
            logActivity($pdo, $user_id, 'customer_added', "Customer {$name} added" . ($company ? " to {$company}" : ''), 'customer', $pdo->lastInsertId());
            $_SESSION['flash_success'] = "Customer '{$name}' added successfully.";
        }
        header('Location: customers.php');
        exit;
    }

    if ($_POST['action'] === 'edit_customer') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $billing_address = trim($_POST['billing_address'] ?? '');
        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare("UPDATE customers SET name=?, email=?, phone=?, company=?, billing_address=?, last_active=NOW() WHERE id=? AND user_id=?");
            $stmt->execute([$name, $email, $phone, $company, $billing_address, $id, $user_id]);
            logActivity($pdo, $user_id, 'customer_updated', "Customer {$name} updated", 'customer', $id);
            $_SESSION['flash_success'] = "Customer '{$name}' updated successfully.";
        }
        header('Location: customers.php');
        exit;
    }

    if ($_POST['action'] === 'delete_customer') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT name FROM customers WHERE id=? AND user_id=?");
            $stmt->execute([$id, $user_id]);
            $c = $stmt->fetch();
            $name = $c ? $c['name'] : 'Unknown';
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id=? AND user_id=?");
            $stmt->execute([$id, $user_id]);
            logActivity($pdo, $user_id, 'customer_deleted', "Customer {$name} deleted", 'customer', $id);
            $_SESSION['flash_success'] = "Customer '{$name}' deleted successfully.";
        }
        header('Location: customers.php');
        exit;
    }

    if ($_POST['action'] === 'bulk_delete_customers') {
        $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
        if (count($ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, name FROM customers WHERE id IN ($placeholders) AND user_id = ?");
            $params = array_merge($ids, [$user_id]);
            $stmt->execute($params);
            $to_delete = $stmt->fetchAll();
            foreach ($to_delete as $c) {
                logActivity($pdo, $user_id, 'customer_deleted', "Customer {$c['name']} deleted (bulk)", 'customer', $c['id']);
            }
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id IN ($placeholders) AND user_id = ?");
            $stmt->execute($params);
            $_SESSION['flash_success'] = count($to_delete) . ' customer(s) deleted successfully.';
        }
        header('Location: customers.php');
        exit;
    }
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$activePage = 'customers';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BizVaza – Customers</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/styles.css">
<style>
.app-container{display:flex;flex:1;width:100%}
.left-column{flex:1;display:flex;flex-direction:column;gap:20px;min-width:0}
.right-column{width:340px;flex-shrink:0;display:flex;flex-direction:column;gap:20px;position:sticky;top:94px}
.toolbar-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;width:auto}
.view-all-link{font-size:12px;color:var(--accent);font-weight:500;cursor:pointer}
.filter-btn{padding:7px 10px;background:var(--bg-card);border:1px solid var(--border);border-radius:7px;color:var(--text-secondary);display:flex;justify-content:center;align-items:center;cursor:pointer}
th:first-child,td:first-child{padding-left:16px;width:40px}
.customer-name-td{color:var(--accent);font-weight:500;cursor:pointer}
.text-muted-td{color:var(--text-muted)}
.font-bold-td{font-weight:700;color:var(--text)}
.action-dots{text-align:center;color:#c0c8d4;cursor:pointer;font-weight:bold;letter-spacing:1px}
.timeline-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px}
.timeline-card h3{font-size:14px;font-weight:600;color:var(--text);margin-bottom:16px}
.timeline-list{position:relative;display:flex;flex-direction:column;gap:14px}
.timeline-list::before{content:'';position:absolute;top:0;bottom:0;left:14px;width:2px;background:var(--border)}
.timeline-item{display:flex;align-items:start;gap:12px;position:relative;cursor:pointer}
.timeline-badge{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;z-index:2;border:2px solid #fff}
.timeline-badge.orange{background:var(--accent-bg);color:var(--accent)}
.timeline-desc{font-size:12px;font-weight:500;color:var(--text-secondary);line-height:1.4}
@media(max-width:1200px){.metrics-grid{gap:12px}.card-metric{padding:14px}.content-body{padding:20px;gap:16px}}
@media(max-width:820px){.content-body{flex-direction:column;width:100%}.left-column{width:100%}.right-column{width:100%;position:static}}
@media(max-width:768px){.content-body{padding:16px}.metrics-grid{grid-template-columns:1fr;gap:12px}.search-wrapper{width:100%}}
@media(max-width:600px){.detail-grid{grid-template-columns:1fr!important}#detailReceipts{overflow-x:auto!important}}
</style>
</head>
<body class="dashboard-body">
<div class="app-container">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-wrapper">

<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Customers</h2>
<p>Manage and audit system clients.</p>
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

<main class="content-body">

<div class="left-column">

<div class="metrics-grid">
<div class="card-metric">
<div class="metric-icon-box system-navy">
<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
</div>
<div>
<p class="metric-label">Total Customers</p>
<p class="metric-value"><?= $cust_stats_total ?></p>
</div>
</div>
<div class="card-metric">
<div class="metric-icon-box green">
<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
</div>
<div>
<p class="metric-label">Active Customers</p>
<p class="metric-value"><?= $active_count ?></p>
</div>
</div>
<div class="card-metric">
<div class="metric-icon-box orange">₦</div>
<div>
<p class="metric-label">Outstanding</p>
<p class="metric-value">₦<?= number_format($total_outstanding, 2) ?></p>
</div>
</div>
</div>

<div class="data-table-container">
<div class="table-toolbar">
<h3>Customers Matrix</h3>
<div class="toolbar-actions">
<button class="btn-primary" onclick="openModal('customerModal')" style="padding:9px 16px;font-size:13px;display:flex;align-items:center;gap:7px"><span>+</span> New Customer</button>
<a href="#" class="view-all-link">View All</a>
<form method="GET" style="display:flex;gap:8px;align-items:center">
<div class="search-wrapper">
<span class="search-icon">
<svg style="width:16px;height:16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
</span>
<input type="text" name="q" placeholder="Search" class="search-input" value="<?= htmlspecialchars($search) ?>">
</div>
<?php if ($search !== ''): ?>
<a href="customers.php" style="font-size:12px;color:var(--accent);font-weight:500;white-space:nowrap;text-decoration:none">Clear</a>
<?php endif; ?>
</form>
<button class="filter-btn">
<svg style="width:16px;height:16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 13.293A1 1 0 013 12.586V4z"/></svg>
</button>
<button class="btn-danger" id="bulkDeleteBtn" onclick="confirmBulkDelete()" style="display:none;background:var(--error);color:#fff;border:none;border-radius:7px;padding:7px 12px;font-size:12px;font-weight:500;display:none;align-items:center;gap:6px;cursor:pointer;font-family:'Inter',sans-serif;white-space:nowrap">
<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
Delete Selected (<span id="selectedCount">0</span>)
</button>
</div>
</div>

<div class="table-responsive">
<table>
<thead>
<tr>
<th><input type="checkbox" id="selectAll"></th>
    <th>Customer Name</th>
    <th>Email Address</th>
    <th>Phone</th>
    <th>Company</th>
    <th>Billing Address</th>
    <th>Balance Due</th>
<th>Last Active</th>
<th style="text-align:center;">Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($customers as $c): ?>
<tr>
<td><input type="checkbox" class="row-checkbox" data-id="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['name']) ?>" data-balance="<?= $c['calculated_balance'] ?>" data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>" onchange="updateBulkDeleteBtn()"></td>
<td class="customer-name-td" onclick="openDetailModal(<?= $c['id'] ?>)"><?= htmlspecialchars($c['name']) ?></td>
<td class="text-muted-td"><?= htmlspecialchars($c['email']) ?></td>
<td class="text-muted-td"><?= htmlspecialchars($c['phone']) ?></td>
<td><?= htmlspecialchars($c['company']) ?></td>
<td class="text-muted-td"><?= htmlspecialchars($c['billing_address'] ?? '') ?></td>
<td class="font-bold-td">₦<?= number_format($c['calculated_balance'], 2) ?></td>
<td class="text-muted-td"><?= $c['last_active'] ? date('M d, Y', strtotime($c['last_active'])) : 'N/A' ?></td>
<td>
<button class="btn-icon" title="Edit" onclick="openEditModal(<?= $c['id'] ?>)">
<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
</button>
<button class="btn-icon del" title="Delete" onclick="confirmDelete(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
</button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
<?php if ($page > 1): ?>
<a href="?p=<?= $page-1 ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="page-btn">&laquo; Prev</a>
<?php endif; ?>
<?php for ($i = 1; $i <= $totalPages; $i++): ?>
<a href="?p=<?= $i ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
<?php endfor; ?>
<?php if ($page < $totalPages): ?>
<a href="?p=<?= $page+1 ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="page-btn">Next &raquo;</a>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>

<div class="right-column">
<button class="btn-whatsapp" onclick="sendWhatsAppUpdate()">
<svg style="width:18px;height:18px;fill:currentColor;" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946C.06 5.348 5.397 0 11.93 0c3.166.001 6.141 1.233 8.377 3.469 2.235 2.237 3.463 5.213 3.462 8.381-.003 6.583-5.339 11.931-11.87 11.931a11.85 11.85 0 01-5.697-1.465L0 24zm6.59-4.846c1.6.95 3.188 1.449 4.725 1.45 5.389 0 9.773-4.393 9.776-9.799.002-2.617-1.015-5.079-2.863-6.93C16.44 2.023 13.987 1 11.926 1c-5.392 0-9.778 4.393-9.778 9.801a9.73 9.73 0 001.488 5.104l-.991 3.616 3.704-.967z"/></svg>
Send Payment Reminder
</button>

<div class="timeline-card">
<h3>Recent Actions</h3>
<div class="timeline-list">
<?php if (count($timeline) > 0): ?>
<?php foreach ($timeline as $tl): ?>
<div class="timeline-item">
<div class="timeline-badge orange">
<svg style="width:14px;height:14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
</div>
<div>
<p class="timeline-desc"><?= htmlspecialchars($tl['description']) ?></p>
<p style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= timeAgo($tl['created_at']) ?></p>
</div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="timeline-item">
<div style="font-size:12px;color:var(--text-muted)">No recent customer activity.</div>
</div>
<?php endif; ?>
</div>
</div>
</div>

</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</div>
</div>
<div class="modal-overlay" id="customerModal">
<form class="modal-box" method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" value="add_customer">
<h2>New Customer</h2>
<p class="desc">Add a new customer to your directory.</p>
<div class="form-row"><label>Full Name *</label><input type="text" name="name" required data-voice></div>
<div class="form-row"><label>Email</label><input type="email" name="email" data-voice></div>
<div class="form-row"><label>Phone</label><input type="text" name="phone" data-voice></div>
<div class="form-row"><label>Company</label><input type="text" name="company" data-voice></div>
<div class="form-row"><label>Billing Address</label><input type="text" name="billing_address" data-voice></div>
<div class="modal-actions"><button type="button" class="btn-cancel" onclick="closeModal('customerModal')">Cancel</button><button type="submit" class="btn-save">Add Customer</button></div>
</form>
</div>

<div class="modal-overlay" id="editModal">
<form class="modal-box" method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" value="edit_customer">
<input type="hidden" name="id" id="edit_id">
<h2>Edit Customer</h2>
<p class="desc">Update customer information.</p>
<div class="form-row"><label>Full Name *</label><input type="text" name="name" id="edit_name" required data-voice></div>
<div class="form-row"><label>Email</label><input type="email" name="email" id="edit_email" data-voice></div>
<div class="form-row"><label>Phone</label><input type="text" name="phone" id="edit_phone" data-voice></div>
<div class="form-row"><label>Company</label><input type="text" name="company" id="edit_company" data-voice></div>
<div class="form-row"><label>Billing Address</label><input type="text" name="billing_address" id="edit_billing_address" data-voice></div>
<div class="modal-actions"><button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn-save">Save Changes</button></div>
</form>
</div>

<div class="modal-overlay" id="detailModal">
<div class="modal-box" style="width:600px;max-width:90vw">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
<h2 id="detailName" style="font-size:17px;font-weight:700">Customer Details</h2>
<button onclick="closeModal('detailModal')" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted);line-height:1">&times;</button>
</div>
<div class="detail-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;padding:16px;background:#f8fafc;border-radius:8px">
<div><span style="font-size:11px;color:var(--text-muted)">Email</span><p id="detailEmail" style="font-size:13px;font-weight:500;margin-top:2px">—</p></div>
<div><span style="font-size:11px;color:var(--text-muted)">Phone</span><p id="detailPhone" style="font-size:13px;font-weight:500;margin-top:2px">—</p></div>
<div><span style="font-size:11px;color:var(--text-muted)">Company</span><p id="detailCompany" style="font-size:13px;font-weight:500;margin-top:2px">—</p></div>
<div><span style="font-size:11px;color:var(--text-muted)">Billing Address</span><p id="detailBillingAddress" style="font-size:13px;font-weight:500;margin-top:2px">—</p></div>
<div><span style="font-size:11px;color:var(--text-muted)">Balance Due</span><p id="detailBalance" style="font-size:13px;font-weight:600;color:var(--error);margin-top:2px">—</p></div>
</div>
<h3 style="font-size:14px;font-weight:600;margin-bottom:10px">Receipts</h3>
<div id="detailReceipts" style="max-height:320px;overflow-y:auto;overflow-x:auto;-webkit-overflow-scrolling:touch">
<p style="font-size:12px;color:var(--text-muted)">Select a customer to view receipts.</p>
</div>
</div>
</div>

<div class="toast" id="toastEl"></div>

<div class="modal-overlay" id="reminderModal">
<div class="modal-box" style="text-align:center">
<h2 id="reminderModalTitle" style="font-size:16px;font-weight:600;color:var(--text);margin-bottom:8px">No Outstanding Balance</h2>
<p class="desc" id="reminderModalMsg" style="font-size:13px;color:var(--text-muted);margin-bottom:16px">None of the selected customers have an outstanding balance.</p>
<div class="modal-actions" style="justify-content:center">
<button type="button" class="btn-cancel" onclick="closeModal('reminderModal')" style="padding:8px 28px">OK</button>
</div>
</div>
</div>

<form method="POST" id="deleteForm" style="display:none">
<?= csrf_field() ?>
<input type="hidden" name="action" value="delete_customer">
<input type="hidden" name="id" id="delete_id">
</form>
<form method="POST" id="bulkDeleteForm" style="display:none">
<?= csrf_field() ?>
<input type="hidden" name="action" value="bulk_delete_customers">
</form>

<script>
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
document.querySelectorAll('.modal-overlay').forEach(function(m){m.addEventListener('click',function(e){if(e.target===this)closeModal(this.id)})});

function toggleUserMenu(e) {
    e.stopPropagation();
    document.getElementById('userMenu').classList.toggle('show');
}
document.addEventListener('click', function() {
    var m = document.getElementById('userMenu');
    if (m) m.classList.remove('show');
});

function openEditModal(id) {
    fetch('ajax/customer-detail.php?id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { showToast(data.error, 'error'); return; }
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_name').value = data.name || '';
            document.getElementById('edit_email').value = data.email || '';
            document.getElementById('edit_phone').value = data.phone || '';
            document.getElementById('edit_company').value = data.company || '';
            document.getElementById('edit_billing_address').value = data.billing_address || '';
            openModal('editModal');
        })
        .catch(function() {
            showToast('Failed to load customer data.', 'error');
        });
}

function openDetailModal(id) {
    openModal('detailModal');
    document.getElementById('detailReceipts').innerHTML = '<p style="font-size:12px;color:var(--text-muted)">Loading...</p>';
    fetch('ajax/customer-detail.php?id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            console.log('Customer detail:', data);
            if (data.error) {
                document.getElementById('detailReceipts').innerHTML = '<p style="font-size:12px;color:var(--error)">' + data.error + '</p>';
                return;
            }
            document.getElementById('detailName').textContent = data.name;
            document.getElementById('detailEmail').textContent = data.email || '\u2014';
            document.getElementById('detailPhone').textContent = data.phone || '\u2014';
            document.getElementById('detailCompany').textContent = data.company || '\u2014';
            document.getElementById('detailBillingAddress').textContent = data.billing_address || '\u2014';
            document.getElementById('detailBalance').textContent = '\u20A6' + parseFloat(data.calculated_balance).toLocaleString(undefined, {minimumFractionDigits: 2});
            var html = '';
            if (data.receipts.length === 0) {
                html = '<p style="font-size:12px;color:var(--text-muted)">No receipts generated for this customer.</p>';
            } else {
                html = '<table style="width:100%;border-collapse:collapse;font-size:12px">' +
                    '<thead><tr style="background:var(--bg-card-alt);border-bottom:1px solid var(--border)">' +
                    '<th style="padding:7px 8px;text-align:left;color:var(--text-muted);font-weight:500">Receipt #</th>' +
                    '<th style="padding:7px 8px;text-align:left;color:var(--text-muted);font-weight:500">Invoice</th>' +
                    '<th style="padding:7px 8px;text-align:right;color:var(--text-muted);font-weight:500">Amount</th>' +
                    '<th style="padding:7px 8px;text-align:center;color:var(--text-muted);font-weight:500">Date</th>' +
                    '<th style="padding:7px 8px;text-align:center;color:var(--text-muted);font-weight:500">Status</th>' +
                    '<th style="padding:7px 8px;text-align:center;color:var(--text-muted);font-weight:500"></th>' +
                    '</tr></thead><tbody>';
                for (var i = 0; i < data.receipts.length; i++) {
                    var r = data.receipts[i];
                    var statusClass = r.status === 'paid' ? 'background:var(--success-bg);color:var(--success)' : r.status === 'processing' ? 'background:#fef3c7;color:#d97706' : 'background:var(--error-bg);color:var(--error)';
                    html += '<tr style="border-bottom:1px solid #f0f0f0">' +
                        '<td style="padding:7px 8px;font-weight:500">' + r.receipt_number + '</td>' +
                        '<td style="padding:7px 8px;color:var(--text-muted)">' + (r.invoice_number || '\u2014') + '</td>' +
                        '<td style="padding:7px 8px;text-align:right;font-weight:500">\u20A6' + parseFloat(r.amount).toLocaleString(undefined, {minimumFractionDigits: 2}) + '</td>' +
                        '<td style="padding:7px 8px;text-align:center;color:var(--text-muted)">' + (r.payment_date || '\u2014') + '</td>' +
                        '<td style="padding:7px 8px;text-align:center"><span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:500;' + statusClass + '">' + r.status + '</span></td>' +
                        '<td style="padding:7px 8px;text-align:center"><a href="view-receipt.php?id=' + r.id + '" style="color:var(--accent);font-weight:500;text-decoration:none">View</a></td>' +
                        '</tr>';
                }
                html += '</tbody></table>';
            }
            document.getElementById('detailReceipts').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('detailReceipts').innerHTML = '<p style="font-size:12px;color:var(--error)">Failed to load customer details.</p>';
        });
}

function confirmDelete(id, name) {
    if (confirm('Delete customer "' + name + '"? This action cannot be undone.')) {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

document.getElementById('selectAll').addEventListener('change', function() {
    var checks = document.querySelectorAll('.row-checkbox');
    for (var i = 0; i < checks.length; i++) {
        checks[i].checked = this.checked;
    }
    updateBulkDeleteBtn();
});

function updateBulkDeleteBtn() {
    var checks = document.querySelectorAll('.row-checkbox:checked');
    var btn = document.getElementById('bulkDeleteBtn');
    var count = document.getElementById('selectedCount');
    if (checks.length > 0) {
        btn.style.display = 'inline-flex';
        count.textContent = checks.length;
    } else {
        btn.style.display = 'none';
    }
}

function confirmBulkDelete() {
    var checks = document.querySelectorAll('.row-checkbox:checked');
    if (checks.length === 0) return;
    var names = [];
    checks.forEach(function(c) { names.push(c.dataset.name); });
    if (!confirm('Delete ' + names.length + ' customer(s):\n' + names.join(', ') + '\n\nThis cannot be undone.')) return;
    var form = document.getElementById('bulkDeleteForm');
    checks.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'ids[]';
        inp.value = c.dataset.id;
        form.appendChild(inp);
    });
    form.submit();
}

function sendWhatsAppUpdate() {
    var checks = document.querySelectorAll('.row-checkbox:checked');
    if (checks.length === 0) {
        showReminderPopup('No Customer Selected', 'Select at least one customer to send a reminder.');
        return;
    }
    var business = <?= json_encode($_SESSION['business_name']) ?>;
    var opened = 0;
    for (var i = 0; i < checks.length; i++) {
        var name = checks[i].dataset.name;
        var balance = parseFloat(checks[i].dataset.balance);
        if (!isFinite(balance) || balance <= 0) continue;
        var msg = 'Payment Reminder\n\n' +
            'Dear ' + name + ',\n\n' +
            'This is a reminder that you have an outstanding balance of \u20A6' +
            balance.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.\n\n' +
            'Please contact us to arrange payment.\n\n' +
            'Thank you,\n' + business;
        var clean = (checks[i].dataset.phone || '').replace(/[^\d]/g, '');
        var url = clean ? 'https://wa.me/' + clean + '?text=' + encodeURIComponent(msg) : 'https://api.whatsapp.com/send?text=' + encodeURIComponent(msg);
        window.open(url, '_blank');
        opened++;
    }
    if (opened === 0) {
        showReminderPopup('No Outstanding Balance', 'None of the selected customers have an outstanding balance, so there is nothing to remind them about.');
    }
}

function showReminderPopup(title, msg) {
    document.getElementById('reminderModalTitle').textContent = title;
    document.getElementById('reminderModalMsg').textContent = msg;
    openModal('reminderModal');
}

function showToast(msg, type) {
    var t = document.getElementById('toastEl');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast ' + (type || 'success') + ' show';
    setTimeout(function() { t.className = 'toast ' + (type || 'success'); }, 4000);
}

<?php if ($flash_success): ?>
showToast(<?= json_encode($flash_success) ?>, 'success');
<?php endif; ?>
<?php if ($flash_error): ?>
showToast(<?= json_encode($flash_error) ?>, 'error');
<?php endif; ?>
</script>
<?php require __DIR__ . '/includes/notification.php'; ?>
<script src="assets/js/app.js"></script>
<script src="assets/js/speech-input.js"></script>
</body>
</html>
