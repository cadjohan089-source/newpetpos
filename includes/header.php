<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$user = currentUser();
$storeId = currentStoreId();

if (!$storeId && !isSuperAdmin()) {
    header('Location: ' . baseUrl('login.php?err=nostore'));
    exit;
}

if ($storeId) {
    $settings = getStoreSettings($storeId);
    $currentStore = getStore($storeId);
} else {
    $settings = getSettings();
    $currentStore = null;
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$B = function($p='') { return baseUrl($p); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $pageTitle ?? 'POS' ?> — <?= htmlspecialchars($settings['restaurant_name'] ?? 'POS') ?></title>
<link rel="stylesheet" href="<?= baseUrl('assets/css/style.css') ?>">
<script>
window.RES_SETTINGS = <?= json_encode($settings) ?>;
window.API_BASE = '<?= baseUrl('') ?>';
window.CURRENT_STORE_ID = <?= $storeId ? (int)$storeId : 'null' ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.6/qz-tray.js"></script>
<script src="<?= baseUrl('assets/js/qz-print.js') ?>"></script>
</head>
<body data-tax="<?= (float)($settings['tax_rate'] ?? 5) ?>">
<div class="app-shell">

  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-mark">
        <?php if ($currentStore && !empty($currentStore['logo'])): ?>
        <img src="<?= baseUrl($currentStore['logo']) ?>" alt="" style="width:40px;height:40px;border-radius:8px;object-fit:contain">
        <?php else: ?>
        <img src="<?= baseUrl('assets/default-logo.png') ?>" alt="" style="width:40px;height:40px;border-radius:8px;object-fit:contain">
        <?php endif; ?>
        <div>
          <div class="logo-text"><?= htmlspecialchars($settings['restaurant_name'] ?? 'POS') ?></div>
          <div class="logo-sub"><?= $storeId ? htmlspecialchars($currentStore['name'] ?? 'Store #' . $storeId) : 'Super Admin' ?></div>
        </div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php if ($storeId): ?>
      <div class="nav-section-label">Counter</div>
      <a href="<?= $B('index.php') ?>" class="nav-item <?= $currentPage==='index'?'active':'' ?>">
        <span class="nav-icon">🛒</span> POS Counter
      </a>
      <a href="<?= $B('bills.php') ?>" class="nav-item <?= $currentPage==='bills'?'active':'' ?>">
        <span class="nav-icon">📋</span> Bill History
      </a>
      <a href="<?= $B('customers.php') ?>" class="nav-item <?= $currentPage==='customers'?'active':'' ?>">
        <span class="nav-icon">💳</span> Customers / Credit
      </a>
      <a href="<?= $B('queue.php') ?>" class="nav-item <?= $currentPage==='queue'?'active':'' ?>" id="nav-queue">
        <span class="nav-icon">🍳</span> Order Queue
        <span id="nav-queue-badge" style="margin-left:auto;background:var(--brand);color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700;display:none"></span>
      </a>
      <a href="<?= $B('admin/reports.php') ?>" class="nav-item <?= $currentPage==='reports'?'active':'' ?>">
        <span class="nav-icon">📉</span> Reports
      </a>
      <a href="<?= $B('admin/returns.php') ?>" class="nav-item <?= $currentPage==='returns'?'active':'' ?>">
        <span class="nav-icon">↩️</span> Returns
      </a>
      <?php endif; ?>
      <?php if (isAdmin()): ?>
      <div class="nav-section-label">Admin</div>
      <?php if (isSuperAdmin()): ?>
      <a href="<?= $B('admin/stores.php') ?>" class="nav-item <?= $currentPage==='stores'?'active':'' ?>">
        <span class="nav-icon">🏪</span> Stores
      </a>
      <?php endif; ?>
      <?php if ($storeId): ?>
      <a href="<?= $B('admin/dashboard.php') ?>" class="nav-item <?= $currentPage==='dashboard'?'active':'' ?>">
        <span class="nav-icon">📊</span> Dashboard
      </a>
      <?php endif; ?>
      <?php if (isSuperAdmin()): ?>
      <a href="<?= $B('admin/products.php') ?>" class="nav-item <?= $currentPage==='products'?'active':'' ?>">
        <span class="nav-icon">🍱</span> Products
      </a>
      <a href="<?= $B('admin/categories.php') ?>" class="nav-item <?= $currentPage==='categories'?'active':'' ?>">
        <span class="nav-icon">🏷️</span> Categories
      </a>
      <a href="<?= $B('admin/users.php') ?>" class="nav-item <?= $currentPage==='users'?'active':'' ?>">
        <span class="nav-icon">👤</span> Users
      </a>
      <a href="<?= $B('admin/reports.php') ?>" class="nav-item <?= $currentPage==='reports'?'active':'' ?>">
        <span class="nav-icon">📉</span> Reports
      </a>
      <a href="<?= $B('admin/stock-audit.php') ?>" class="nav-item <?= $currentPage==='stock-audit'?'active':'' ?>">
        <span class="nav-icon">📦</span> Stock Audit
      </a>
      <?php endif; ?>
      <?php if ($storeId): ?>
      <a href="<?= $B('admin/bills-admin.php') ?>" class="nav-item <?= $currentPage==='bills-admin'?'active':'' ?>">
        <span class="nav-icon">✏️</span> Manage Bills
      </a>
      <a href="<?= $B('admin/expenses.php') ?>" class="nav-item <?= $currentPage==='expenses'?'active':'' ?>">
        <span class="nav-icon">🧾</span> Expenses
      </a>
      <?php endif; ?>
      <?php if ($storeId): ?>
      <a href="<?= $B('admin/settings.php') ?>" class="nav-item <?= $currentPage==='settings'?'active':'' ?>">
        <span class="nav-icon">⚙️</span> Settings
      </a>
      <?php endif; ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <?php if (isSuperAdmin()): ?>
      <div style="padding:8px 12px;margin-bottom:8px">
        <form method="POST" action="<?= $B('admin/switch-store.php') ?>" style="display:flex;gap:6px;align-items:center">
          <select name="store_id" class="form-control" style="font-size:11px;padding:4px 8px;flex:1" onchange="this.form.submit()">
            <option value="0" <?= !$storeId ? 'selected' : '' ?>>— Super Admin —</option>
            <?php foreach (getAllStores(true) as $st): ?>
            <option value="<?= $st['id'] ?>" <?= $storeId == $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars($st['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php endif; ?>
      <div class="user-card">
        <div class="user-avatar"><?= strtoupper(substr($user['name'] ?: $user['username'], 0, 2)) ?></div>
        <div>
          <div class="user-name"><?= htmlspecialchars($user['name'] ?: $user['username']) ?></div>
          <div class="user-role"><?= htmlspecialchars(str_replace('_', ' ', $user['role'])) ?></div>
        </div>
        <a href="<?= $B('logout.php') ?>" class="logout-btn" title="Logout">↩</a>
      </div>
    </div>
  </aside>

  <div class="main">
