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
      <span style="font-size:10px;font-weight:600;color:#b45309;text-transform:uppercase;letter-spacing:1px;margin-top:-2px">Admin</span>
    </div>
  </div>
  <nav class="nav">
    <a href="index.php" class="nav-item<?= $activePage === 'dashboard' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="14" y="14" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /></svg>
      Dashboard
    </a>
    <?php if (adminCan('users')): ?>
    <a href="users.php" class="nav-item<?= $activePage === 'users' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg>
      Users
    </a>
    <?php endif; ?>
    <?php if (adminCan('team')): ?>
    <a href="team.php" class="nav-item<?= $activePage === 'team' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg>
      Team
    </a>
    <?php endif; ?>
    <?php if (adminCan('notify')): ?>
    <a href="notify.php" class="nav-item<?= $activePage === 'notify' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" /></svg>
      Notifications
    </a>
    <?php endif; ?>
    <?php if (adminCan('plans')): ?>
    <a href="plans.php" class="nav-item<?= $activePage === 'plans' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></svg>
      Plans
    </a>
    <?php endif; ?>
    <?php if (adminCan('settings')): ?>
    <a href="settings.php" class="nav-item<?= $activePage === 'settings' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" /></svg>
      Settings
    </a>
    <?php endif; ?>
    <?php if (adminCan('help')): ?>
    <a href="help.php" class="nav-item<?= $activePage === 'help' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>
      Help Requests
    </a>
    <?php endif; ?>
    <?php if (adminCan('activity')): ?>
    <a href="activity.php" class="nav-item<?= $activePage === 'activity' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12" /></svg>
      Activity Log
    </a>
    <?php endif; ?>
    <?php if (adminCan('revenue')): ?>
    <a href="revenue.php" class="nav-item<?= $activePage === 'revenue' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23" /><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" /></svg>
      Revenue
    </a>
    <?php endif; ?>
    <?php if (adminCan('upgrade_requests')): ?>
    <a href="upgrade-requests.php" class="nav-item<?= $activePage === 'upgrade_requests' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
      Upgrade Requests
    </a>
    <?php endif; ?>
    <?php if (adminCan('reviews')): ?>
    <a href="reviews.php" class="nav-item<?= $activePage === 'reviews' ? ' active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8da0c0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20l-7 4 2-8.5L1 10l8.6-.7L12 2l2.4 7.3L23 10l-6 5.5 2 8.5z"/></svg>
      Reviews
    </a>
    <?php endif; ?>
  </nav>
  <button class="theme-toggle sidebar-theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Toggle dark mode">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path class="theme-icon-moon" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    <span id="themeLabel" class="theme-label" style="font-size:12px;font-weight:500;color:#8da0c0">Dark Mode</span>
  </button>
  <div class="sidebar-meta" style="background:#0b1e38">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></svg>
    <span style="font-size:12px;font-weight:600;color:#fdba74">Admin Access</span>
  </div>
  <div class="nav-help" onclick="window.location='logout.php'" style="cursor:pointer">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7a8fa6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><polyline points="16 17 21 12 16 7" /><line x1="21" y1="12" x2="9" y2="12" /></svg>
    <div class="nav-help-text"><span class="ht">Log Out</span><span class="hs"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></span></div>
    <span class="arr">&rarr;</span>
  </div>
  <a href="../index.html" class="nav-item nav-goback"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg> <span>Back to Main Site</span></a>
</aside>
<div class="toast" id="toastEl"></div>
<?php $sidebarFlash = $flash ?? ''; if ($sidebarFlash): ?><script>document.addEventListener('DOMContentLoaded',function(){showToast(<?= json_encode($sidebarFlash) ?>,<?= (strpos($sidebarFlash,'must')!==false||strpos($sidebarFlash,'not')!==false||strpos($sidebarFlash,'Invalid')!==false||strpos($sidebarFlash,'Cannot')!==false)?'error':'success' ?>)});</script><?php endif; ?>
