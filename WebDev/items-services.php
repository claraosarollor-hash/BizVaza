<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$avatar_initials = $_SESSION['avatar_initials'];

$stmt = $pdo->prepare("SELECT * FROM items WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$items = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_items = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE user_id = ? AND category = 'Product'");
$stmt->execute([$user_id]);
$total_products = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE user_id = ? AND category = 'Service'");
$stmt->execute([$user_id]);
$total_services = (int)$stmt->fetchColumn();

// Handle flash messages
$flash_msg = '';
$flash_type = '';
if (isset($_SESSION['flash_msg'])) {
    $flash_msg = $_SESSION['flash_msg'];
    $flash_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Handle New Item form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    validate_csrf();
    $name = trim($_POST['name'] ?? '');
    $category = $_POST['category'] ?? 'Service';
    $description = trim($_POST['description'] ?? '');
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $stock = trim($_POST['stock'] ?? 'N/A');
    if ($name !== '') {
        $stmt = $pdo->prepare("INSERT INTO items (user_id, name, category, description, unit_price, stock, last_updated) VALUES (?, ?, ?, ?, ?, ?, CURDATE())");
        $stmt->execute([$user_id, $name, $category, $description, $unit_price, $stock]);
        logActivity($pdo, $user_id, 'item_added', "Item {$name} added — ₦" . number_format($unit_price, 2), 'item', $pdo->lastInsertId());
        $_SESSION['flash_msg'] = "Item \"{$name}\" added successfully.";
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: items-services.php');
    exit;
}

// Handle Edit Item form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_item') {
    validate_csrf();
    $item_id = intval($_POST['item_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $category = $_POST['category'] ?? 'Service';
    $description = trim($_POST['description'] ?? '');
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $stock = trim($_POST['stock'] ?? 'N/A');
    if ($name !== '' && $item_id > 0) {
        $stmt = $pdo->prepare("UPDATE items SET name=?, category=?, description=?, unit_price=?, stock=?, last_updated=CURDATE() WHERE id=? AND user_id=?");
        $stmt->execute([$name, $category, $description, $unit_price, $stock, $item_id, $user_id]);
        logActivity($pdo, $user_id, 'item_updated', "Item {$name} updated — ₦" . number_format($unit_price, 2), 'item', $item_id);
        $_SESSION['flash_msg'] = "Item \"{$name}\" updated successfully.";
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: items-services.php');
    exit;
}

// Handle Delete Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
    validate_csrf();
    $item_id = intval($_POST['item_id'] ?? 0);
    if ($item_id > 0) {
        $stmt = $pdo->prepare("SELECT name FROM items WHERE id=? AND user_id=?");
        $stmt->execute([$item_id, $user_id]);
        $item = $stmt->fetch();
        $name = $item ? $item['name'] : 'Item';
        $stmt = $pdo->prepare("DELETE FROM items WHERE id=? AND user_id=?");
        $stmt->execute([$item_id, $user_id]);
        logActivity($pdo, $user_id, 'item_deleted', "Item {$name} deleted", 'item', $item_id);
        $_SESSION['flash_msg'] = "Item \"{$name}\" deleted successfully.";
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: items-services.php');
    exit;
}

$activePage = 'items';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza – Items / Services</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
.left-column{flex:1;display:flex;flex-direction:column;gap:20px;min-width:0}
.toolbar-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;width:auto}
.bell-wrap{position:relative;width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.bell-dot{position:absolute;top:6px;right:6px;width:7px;height:7px;background:#ef4444;border-radius:50%;border:1.5px solid #fff}
.avatar{width:30px;height:30px;border-radius:50%;background:var(--accent-bg);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--accent);flex-shrink:0}
th:first-child,td:first-child{padding-left:16px}
.item-name{font-size:12.5px;font-weight:500;color:var(--text);line-height:1.4}
.cat-tag{font-size:12px;color:#3a4a5c}
.desc-text{font-size:12px;color:#4a5568;line-height:1.4}
.price-text{font-size:12.5px;color:var(--text);font-weight:500}
.stock-text{font-size:12.5px;color:#3a4a5c}
.date-text-td{font-size:12px;color:#4a5568;white-space:nowrap}
.total-badge{font-size:12.5px;color:#6b7a90}
.total-badge strong{color:var(--text);font-weight:700}
.filter-group{display:flex;align-items:center;border:1px solid var(--border);border-radius:7px;overflow:hidden}
.filter-btn{padding:6px 14px;font-size:12.5px;font-weight:500;cursor:pointer;background:var(--bg-card);color:#3a4a5c;border:none;outline:none;transition:background .13s;font-family:'Inter',sans-serif}
.filter-btn:hover{background:var(--bg-card-alt)}
.filter-btn.active{background:var(--bg-card);color:var(--text);font-weight:600}
.filter-btn+.filter-btn{border-left:1px solid var(--border)}
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
<h2>Items / Services</h2>
<p>Manage your product and service catalog</p>
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

<?php if ($flash_msg): ?>
<div class="flash-toast <?= $flash_type ?>" id="flashToast"><?= htmlspecialchars($flash_msg) ?></div>
<?php endif; ?>

<div class="left-column">

<div class="metrics-grid">
<div class="card-metric">
<div class="metric-icon-box" style="background:var(--accent-bg);color:var(--accent)">
<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
</div>
<div>
<p class="metric-label">Total Items</p>
<p class="metric-value"><?= $total_items ?></p>
</div>
</div>
<div class="card-metric">
<div class="metric-icon-box" style="background:#ecfdf5;color:var(--success)">
<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 11V7a4 4 0 0 0-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
</div>
<div>
<p class="metric-label">Products</p>
<p class="metric-value"><?= $total_products ?></p>
</div>
</div>
<div class="card-metric">
<div class="metric-icon-box" style="background:#eff6ff;color:#2563eb">
<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
</div>
<div>
<p class="metric-label">Services</p>
<p class="metric-value"><?= $total_services ?></p>
</div>
</div>
</div>

<div class="data-table-container">
<div class="table-toolbar">
<h3>Items / Services Matrix</h3>
<div class="toolbar-actions">
<button class="btn-primary" onclick="openModal('itemModal', null)" style="padding:9px 16px;font-size:13px;display:flex;align-items:center;gap:7px"><span>+</span> New Item / Service</button>
<span class="total-badge">Total <strong><?= count($items) ?></strong></span>
<div class="search-wrapper">
<span class="search-icon">
<svg style="width:16px;height:16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
</span>
<input type="text" id="itemSearch" placeholder="Search items..." class="search-input" oninput="filterItems()">
</div>
<div class="filter-group">
<button class="filter-btn active" onclick="setCategory('all',this)">All</button>
<button class="filter-btn" onclick="setCategory('Service',this)">Service</button>
<button class="filter-btn" onclick="setCategory('Product',this)">Product</button>
</div>
</div>
</div>

<div class="table-responsive">
<table>
<thead>
<tr>
<th>Item / Service Name</th>
<th>Category</th>
<th>Description</th>
<th>Unit Price (N)</th>
<th>Current Stock</th>
<th>Last Updated</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php if (count($items) > 0): ?>
<?php foreach ($items as $item): ?>
<tr data-name="<?= htmlspecialchars(strtolower($item['name'])) ?>" data-category="<?= $item['category'] ?>">
<td><div class="item-name"><?= htmlspecialchars($item['name']) ?></div></td>
<td><span class="cat-tag"><?= $item['category'] ?></span></td>
<td><span class="desc-text"><?= htmlspecialchars($item['description']) ?></span></td>
<td><span class="price-text"><?= number_format($item['unit_price'], 2) ?></span></td>
<td><span class="stock-text"><?= htmlspecialchars($item['stock']) ?></span></td>
<td><span class="date-text-td"><?= $item['last_updated'] ? date('M d, Y', strtotime($item['last_updated'])) : 'N/A' ?></span></td>
<td>
<div class="action-btns">
<button class="icon-action" title="Edit" onclick="editItem(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>', '<?= $item['category'] ?>', '<?= htmlspecialchars(addslashes($item['description'])) ?>', <?= $item['unit_price'] ?>, '<?= htmlspecialchars(addslashes($item['stock'])) ?>')">
<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
</button>
<button class="icon-action del" title="Delete" onclick="confirmDelete(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>')">
<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
</button>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" style="text-align:center;padding:40px 16px;color:var(--text-muted);font-size:13px">No items or services yet. Click "+ New Item / Service" to add one.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

</div>

</div>

</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
</div>

<div class="modal-overlay" id="itemModal">
<form class="modal-box" method="POST">
<?= csrf_field() ?>
<input type="hidden" name="action" id="itemFormAction" value="add_item">
<input type="hidden" name="item_id" id="itemFormId" value="0">
<h2 id="itemModalTitle">New Item / Service</h2>
<p class="desc" id="itemModalDesc">Add a new product or service to your catalog.</p>
<div class="form-row"><label>Name *</label><input type="text" name="name" id="itemFormName" required data-voice></div>
<div class="form-row"><label>Category *</label><select name="category" id="itemFormCategory"><option value="Service">Service</option><option value="Product">Product</option></select></div>
<div class="form-row"><label>Description</label><input type="text" name="description" id="itemFormDescription" data-voice></div>
<div class="form-row"><label>Unit Price (₦) *</label><input type="number" name="unit_price" id="itemFormPrice" step="0.01" value="0" required></div>
<div class="form-row"><label>Stock</label><input type="text" name="stock" id="itemFormStock" value="N/A"></div>
<div class="modal-actions"><button type="button" class="btn-cancel" onclick="closeModal('itemModal')">Cancel</button><button type="submit" class="btn-save" id="itemFormSubmit">Add Item</button></div>
</form>
</div>

<div class="modal-overlay" id="deleteModal">
<div class="modal-box" style="width:380px;max-width:90vw">
<h2>Delete Item</h2>
<p class="desc" id="deleteDesc">Are you sure you want to delete this item? This action cannot be undone.</p>
<form method="POST" style="margin-top:0">
<?= csrf_field() ?>
<input type="hidden" name="action" value="delete_item">
<input type="hidden" name="item_id" id="deleteItemId" value="0">
<div class="modal-actions"><button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn-save" style="background:var(--error)">Delete</button></div>
</form>
</div>
</div>

<?php require __DIR__ . '/includes/notification.php'; ?>
<script src="assets/js/app.js"></script>
<script src="assets/js/speech-input.js"></script>
<script>
function openModal(id, data) {
    if (id === 'itemModal') {
        if (data) {
            document.getElementById('itemFormAction').value = 'edit_item';
            document.getElementById('itemFormId').value = data.id;
            document.getElementById('itemFormName').value = data.name;
            document.getElementById('itemFormCategory').value = data.category;
            document.getElementById('itemFormDescription').value = data.description;
            document.getElementById('itemFormPrice').value = data.price;
            document.getElementById('itemFormStock').value = data.stock;
            document.getElementById('itemModalTitle').textContent = 'Edit Item / Service';
            document.getElementById('itemModalDesc').textContent = 'Update the product or service details below.';
            document.getElementById('itemFormSubmit').textContent = 'Update Item';
        } else {
            document.getElementById('itemFormAction').value = 'add_item';
            document.getElementById('itemFormId').value = '0';
            document.getElementById('itemFormName').value = '';
            document.getElementById('itemFormCategory').value = 'Service';
            document.getElementById('itemFormDescription').value = '';
            document.getElementById('itemFormPrice').value = '0';
            document.getElementById('itemFormStock').value = 'N/A';
            document.getElementById('itemModalTitle').textContent = 'New Item / Service';
            document.getElementById('itemModalDesc').textContent = 'Add a new product or service to your catalog.';
            document.getElementById('itemFormSubmit').textContent = 'Add Item';
        }
    }
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

function editItem(id, name, category, description, price, stock) {
    openModal('itemModal', {
        id: id,
        name: name,
        category: category,
        description: description,
        price: price,
        stock: stock
    });
}

function confirmDelete(id, name) {
    document.getElementById('deleteItemId').value = id;
    document.getElementById('deleteDesc').textContent = 'Are you sure you want to delete "' + name + '"? This action cannot be undone.';
    document.getElementById('deleteModal').classList.add('show');
}

function filterItems() {
    var q = document.getElementById('itemSearch').value.toLowerCase();
    var cat = document.getElementById('activeCategory') ? document.getElementById('activeCategory').value : 'all';
    var rows = document.querySelectorAll('.data-table-container tbody tr');
    rows.forEach(function(r) {
        var name = r.getAttribute('data-name') || '';
        var rcat = r.getAttribute('data-category') || '';
        var match = name.indexOf(q) !== -1;
        if (cat !== 'all' && rcat !== cat) match = false;
        r.style.display = match ? '' : 'none';
    });
}

function setCategory(cat, btn) {
    document.querySelectorAll('.filter-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    var hid = document.getElementById('activeCategory');
    if (!hid) {
        hid = document.createElement('input');
        hid.type = 'hidden';
        hid.id = 'activeCategory';
        document.body.appendChild(hid);
    }
    hid.value = cat;
    filterItems();
}

// Auto-dismiss flash toast
var flashEl = document.getElementById('flashToast');
if (flashEl) {
    setTimeout(function() { flashEl.classList.add('hide'); }, 3500);
    setTimeout(function() { if (flashEl.parentNode) flashEl.parentNode.removeChild(flashEl); }, 4000);
}
</script>
</body>
</html>
