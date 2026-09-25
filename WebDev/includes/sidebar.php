<?php if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; } ?><div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <div class="logo-icon">
      <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M11 2L4 11h7l-2 7 9-10h-7l2-8z" fill="#fff" />
      </svg>
    </div>
    <div class="logo-text">
      <span class="brand">BizVaza</span>
    </div>
  </div>
  <nav class="nav">
    <a href="index.php" class="nav-item<?= $activePage === 'home' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Home
    </a>
    <a href="dashboard.php" class="nav-item<?= $activePage === 'dashboard' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="14" y="14" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /></svg>
      Dashboard
    </a>
    <a href="invoiceDashboard.php" class="nav-item<?= $activePage === 'invoices' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /><polyline points="10 9 9 9 8 9" /></svg>
      Invoices
    </a>
    <a href="receipts.php" class="nav-item<?= $activePage === 'receipts' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" /><rect x="9" y="3" width="6" height="4" rx="1" /><path d="M9 12h6M9 16h4" /></svg>
      Receipts
    </a>
    <a href="customers.php" class="nav-item<?= $activePage === 'customers' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg>
      Customers
    </a>
    <a href="items-services.php" class="nav-item<?= $activePage === 'items' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" /></svg>
      Items / Services
    </a>
    <a href="payments.php" class="nav-item<?= $activePage === 'payments' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" /><line x1="1" y1="10" x2="23" y2="10" /></svg>
      Payments
    </a>
    <a href="recurring.php" class="nav-item<?= $activePage === 'recurring' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Recurring
    </a>
    <a href="report.php" class="nav-item<?= $activePage === 'report' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10" /><line x1="12" y1="20" x2="12" y2="4" /><line x1="6" y1="20" x2="6" y2="14" /></svg>
      Reports
    </a>
    <a href="activity.php" class="nav-item<?= $activePage === 'activity' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12" /></svg>
      Activity Log
    </a>
    <a href="settings.php" class="nav-item<?= $activePage === 'settings' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3" /><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14" /><path d="M15.54 8.46a5 5 0 0 1 0 7.07M8.46 8.46a5 5 0 0 0 0 7.07" /></svg>
      Settings
    </a>
    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <a href="admin/index.php" class="nav-item" style="margin-top:6px;border-top:1px solid rgba(255,255,255,.08);padding-top:12px">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Admin Panel
    </a>
    <?php endif; ?>
  </nav>
  <button class="theme-toggle sidebar-theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Toggle dark mode">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path class="theme-icon-moon" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    <span id="themeLabel" class="theme-label" style="font-size:12px;font-weight:500;color:#8da0c0">Dark Mode</span>
  </button>
  <div class="sidebar-meta" style="background:<?= isset($_SESSION['is_pro']) && $_SESSION['is_pro'] ? '#0b2d1a' : '#1a1a0b' ?>">
    <?php if (isset($_SESSION['is_pro']) && $_SESSION['is_pro']): ?>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
    <div style="flex:1"><span style="font-size:12px;font-weight:600;color:#6ee7b7">Pro Plan</span>
    <?php if (!empty($_SESSION['plan_expires_at'])): ?>
    <span style="font-size:10px;color:#6ee7b7;display:block;opacity:.7">Expires <?= date('M j', strtotime($_SESSION['plan_expires_at'])) ?></span>
    <?php endif; ?>
    </div>
    <?php else: ?>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    <div style="flex:1">
    <span style="font-size:12px;font-weight:600;color:#fbbf24">Free Plan</span>
    <?php if (!empty($_SESSION['trial_ends_at'])): $tdLeft = strtotime($_SESSION['trial_ends_at']); $daysLeft = (int)ceil(($tdLeft - time()) / 86400); if ($daysLeft > 0): ?>
    <span style="font-size:10px;color:#fbbf24;display:block;opacity:.75">Trial ends <?= date('M j', $tdLeft) ?> (<?= $daysLeft ?>d left)</span>
    <?php endif; endif; ?>
    </div>
    <a href="pricing.php" style="font-size:11px;font-weight:600;color:#fff;background:#b45309;padding:3px 8px;border-radius:4px;text-decoration:none">Upgrade</a>
    <?php endif; ?>
  </div>
  <a href="needhelp.php" style="text-decoration:none;color:inherit;display:block">
  <div class="nav-help<?= $activePage === 'help' ? ' active' : '' ?>">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7a8fa6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
    <div class="nav-help-text">
      <span class="ht">Need Help?</span><span class="hs">Visit our help center</span>
    </div>
    <svg class="arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7a8fa6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
  </div>
  </a>
</aside>

