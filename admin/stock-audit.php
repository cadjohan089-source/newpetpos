<?php
$pageTitle = 'Stock Audit';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$allStores = getAllStores(true);

// Filters
$storeFilter = (int)($_GET['store_id'] ?? 0);
$period = $_GET['period'] ?? 'thismonth';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$productName = trim($_GET['product_name'] ?? '');

// Resolve dates from period
if ($period === 'thismonth') {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-d');
} elseif ($period === 'lastmonth') {
    $dateFrom = date('Y-m-01', strtotime('-1 month'));
    $dateTo = date('Y-m-t', strtotime('-1 month'));
} elseif ($period === 'thisyear') {
    $dateFrom = date('Y-01-01');
    $dateTo = date('Y-m-d');
} elseif ($period === 'lastyear') {
    $dateFrom = date('Y-01-01', strtotime('-1 year'));
    $dateTo = date('Y-12-31', strtotime('-1 year'));
} elseif ($period === '30days') {
    $dateFrom = date('Y-m-d', strtotime('-29 days'));
    $dateTo = date('Y-m-d');
}
if (!$dateFrom) $dateFrom = date('Y-m-01');
if (!$dateTo) $dateTo = date('Y-m-d');

// Get currency
$cur = 'Rs';
if ($storeFilter) {
    $cur = getStoreSettings($storeFilter)['currency'] ?? 'Rs';
}

// Build store filter
$storeWhere = '';
$storeParams = [];
if ($storeFilter) {
    $storeWhere = 'AND p.store_id = ?';
    $storeParams[] = $storeFilter;
}

// Build product name filter
$nameWhere = '';
$nameParams = [];
if ($productName) {
    $nameWhere = 'AND p.name LIKE ?';
    $nameParams[] = '%' . $productName . '%';
}

// Main query: current stock + period movements
$auditStmt = $db->prepare("
    SELECT
        p.id, p.store_id, p.name as product_name, p.price, p.buy_price,
        p.stock_qty as current_stock,
        s.name as store_name,
        c.name as category_name,
        CAST(COALESCE(sold.qty_sold, 0) AS INTEGER) as qty_sold,
        COALESCE(sold.revenue, 0) as revenue,
        CAST(COALESCE(ret.qty_returned, 0) AS INTEGER) as qty_returned,
        CAST(COALESCE(sl.qty_added, 0) AS INTEGER) as qty_added,
        (CAST(p.stock_qty AS INTEGER) + CAST(COALESCE(sold.qty_sold, 0) AS INTEGER) - CAST(COALESCE(ret.qty_returned, 0) AS INTEGER) - CAST(COALESCE(sl.qty_added, 0) AS INTEGER)) as opening_stock
    FROM products p
    LEFT JOIN stores s ON p.store_id = s.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN (
        SELECT bi.product_id, b.store_id,
               SUM(bi.quantity) as qty_sold,
               SUM(bi.subtotal) as revenue
        FROM bill_items bi
        JOIN bills b ON bi.bill_id = b.id
        WHERE DATE(b.created_at) >= ? AND DATE(b.created_at) <= ?
        GROUP BY bi.product_id, b.store_id
    ) sold ON sold.product_id = p.id AND sold.store_id = p.store_id
    LEFT JOIN (
        SELECT ri.product_id, r.store_id,
               SUM(ri.quantity) as qty_returned
        FROM return_items ri
        JOIN returns r ON ri.return_id = r.id
        WHERE DATE(r.created_at) >= ? AND DATE(r.created_at) <= ?
        GROUP BY ri.product_id, r.store_id
    ) ret ON ret.product_id = p.id AND ret.store_id = p.store_id
    LEFT JOIN (
        SELECT sl2.product_id, sl2.store_id,
               SUM(sl2.quantity) as qty_added
        FROM stock_logs sl2
        WHERE DATE(sl2.log_date) >= ? AND DATE(sl2.log_date) <= ?
        GROUP BY sl2.product_id, sl2.store_id
    ) sl ON sl.product_id = p.id AND sl.store_id = p.store_id
    WHERE 1=1 $storeWhere $nameWhere
    ORDER BY s.name, c.name, p.name
");
$params = array_merge(
    [$dateFrom, $dateTo],  // sold subquery
    [$dateFrom, $dateTo],  // return subquery
    [$dateFrom, $dateTo],  // stock_logs subquery
    $storeParams,
    $nameParams
);
$auditStmt->execute($params);
$audit = $auditStmt->fetchAll();

// Summary totals
$totalOpening = 0;
$totalAdded = 0;
$totalSold = 0;
$totalReturned = 0;
$totalCurrent = 0;
$totalStockValue = 0;
foreach ($audit as $row) {
    $totalOpening += (int)$row['opening_stock'];
    $totalAdded += (int)$row['qty_added'];
    $totalSold += (int)$row['qty_sold'];
    $totalReturned += (int)$row['qty_returned'];
    $totalCurrent += (int)$row['current_stock'];
    $totalStockValue += (int)$row['current_stock'] * (float)$row['buy_price'];
}

// Product name dropdown
$prodNames = $db->query("SELECT DISTINCT name FROM products ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$label = date('d M Y', strtotime($dateFrom)) . ' — ' . date('d M Y', strtotime($dateTo));
$storeLabel = $storeFilter ? (getStore($storeFilter)['name'] ?? 'Store') : 'All Stores';
?>
    <div class="topbar" style="height:auto;min-height:56px;flex-wrap:wrap;padding:12px 24px;gap:10px">
      <div><div class="page-title">Stock Audit Report</div><div class="page-subtitle"><?= htmlspecialchars($storeLabel) ?> &middot; <?= htmlspecialchars($label) ?></div></div>
      <div class="topbar-right no-print" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
        <button class="btn btn-secondary btn-sm" onclick="window.print()">🖨️ Print Report</button>
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <select class="form-control" name="store_id" style="font-size:13px;padding:7px 10px;width:auto">
            <option value="0" <?= !$storeFilter?'selected':'' ?>>All Stores</option>
            <?php foreach ($allStores as $st): ?>
            <option value="<?= $st['id'] ?>" <?= $storeFilter==$st['id']?'selected':'' ?>><?= htmlspecialchars($st['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="form-control" name="period" onchange="if(this.value){this.form.date_from.value='';this.form.date_to.value='';}this.form.submit()" style="font-size:13px;padding:7px 10px;width:auto">
            <option value="">Custom Range</option>
            <option value="thismonth" <?= $period==='thismonth'?'selected':'' ?>>This Month</option>
            <option value="lastmonth" <?= $period==='lastmonth'?'selected':'' ?>>Last Month</option>
            <option value="thisyear" <?= $period==='thisyear'?'selected':'' ?>>This Year</option>
            <option value="lastyear" <?= $period==='lastyear'?'selected':'' ?>>Last Year</option>
            <option value="30days" <?= $period==='30days'?'selected':'' ?>>Last 30 Days</option>
          </select>
          <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="font-size:13px;padding:7px 10px;width:auto">
          <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="font-size:13px;padding:7px 10px;width:auto">
          <select class="form-control" name="product_name" style="font-size:13px;padding:7px 10px;width:auto">
            <option value="">All Products</option>
            <?php foreach ($prodNames as $pn): ?>
            <option value="<?= htmlspecialchars($pn) ?>" <?= $productName===$pn?'selected':'' ?>><?= htmlspecialchars($pn) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        </form>
      </div>
    </div>

    <!-- PRINT HEADER -->
    <div class="print-header" style="display:none">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
        <?php
        $printLogo = baseUrl('assets/default-logo.png');
        if ($storeFilter) {
          $si = getStore($storeFilter);
          if (!empty($si['logo'])) $printLogo = baseUrl($si['logo']);
        } elseif ($currentStore && !empty($currentStore['logo'])) {
          $printLogo = baseUrl($currentStore['logo']);
        }
        ?>
        <img src="<?= $printLogo ?>" alt="" style="width:50px;height:50px;border-radius:8px;object-fit:contain">
        <div>
          <h2 style="margin:0 0 2px;font-size:18px">Stock Audit Report</h2>
          <p style="margin:0;font-size:12px;color:#555"><?= htmlspecialchars($storeLabel) ?> &middot; <?= htmlspecialchars($label) ?><?php if ($productName): ?> — Product: <?= htmlspecialchars($productName) ?><?php endif; ?></p>
          <p style="margin:0;font-size:10px;color:#888">Generated: <?= date('d M Y, h:i A') ?></p>
        </div>
      </div>
      <hr style="border:none;border-top:2px solid #333;margin:0 0 16px">
    </div>

    <div class="content-area" id="report-content">
      <!-- Summary cards -->
      <div class="grid-4 mb-20">
        <div class="stat-card brand"><div class="stat-icon">📦</div><div class="stat-value"><?= number_format($totalOpening) ?></div><div class="stat-label">Opening Stock</div></div>
        <div class="stat-card green"><div class="stat-icon">➕</div><div class="stat-value"><?= number_format($totalAdded) ?></div><div class="stat-label">Stock Added</div></div>
        <div class="stat-card red"><div class="stat-icon">🛒</div><div class="stat-value"><?= number_format($totalSold) ?></div><div class="stat-label">Qty Sold</div></div>
        <div class="stat-card blue"><div class="stat-icon">📊</div><div class="stat-value"><?= number_format($totalCurrent) ?></div><div class="stat-label">Current Stock</div></div>
      </div>

      <div class="grid-4 mb-20">
        <div class="stat-card"><div class="stat-label">Returned</div><div class="stat-value"><?= number_format($totalReturned) ?></div></div>
        <div class="stat-card"><div class="stat-label">Stock Value (Buy Price)</div><div class="stat-value"><?= $cur ?> <?= number_format($totalStockValue, 0) ?></div></div>
        <div class="stat-card"><div class="stat-label">Products Tracked</div><div class="stat-value"><?= count($audit) ?></div></div>
        <div class="stat-card"><div class="stat-label">Net Movement</div><div class="stat-value"><?= ($totalAdded + $totalReturned - $totalSold) >= 0 ? '+' : '' ?><?= number_format($totalAdded + $totalReturned - $totalSold) ?></div></div>
      </div>

      <!-- Main audit table -->
      <div class="card mb-20">
        <div class="card-header"><span class="card-title">Stock Movement by Product</span></div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <?php if (!$storeFilter): ?><th>Store</th><?php endif; ?>
                <th>Category</th>
                <th>Product</th>
                <th>Opening</th>
                <th>+ Added</th>
                <th>- Sold</th>
                <th>↩ Returned</th>
                <th>Current</th>
                <th>Buy Price</th>
                <th>Sell Price</th>
                <th>Stock Value</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $prevStore = '';
            foreach ($audit as $row):
              $stockVal = (int)$row['current_stock'] * (float)$row['buy_price'];
              $movement = (int)$row['qty_added'] + (int)$row['qty_returned'] - (int)$row['qty_sold'];
              // Show store group separator
              if ($row['store_name'] !== $prevStore && !$storeFilter):
                $prevStore = $row['store_name'];
            ?>
              <tr style="background:var(--surface2)"><td colspan="<?= $storeFilter ? 10 : 11 ?>" class="font-bold" style="padding:8px 12px;font-size:13px"><?= htmlspecialchars($row['store_name']) ?></td></tr>
            <?php endif; ?>
              <tr>
                <td><?= htmlspecialchars($row['category_name'] ?? '-') ?></td>
                <td class="font-bold"><?= htmlspecialchars($row['product_name']) ?></td>
                <td class="font-mono"><?= (int)$row['opening_stock'] ?></td>
                <td class="font-mono" style="color:var(--green)"><?= (int)$row['qty_added'] > 0 ? '+' . (int)$row['qty_added'] : '-' ?></td>
                <td class="font-mono" style="color:var(--red)"><?= (int)$row['qty_sold'] > 0 ? '-' . (int)$row['qty_sold'] : '-' ?></td>
                <td class="font-mono" style="color:var(--orange)"><?= (int)$row['qty_returned'] > 0 ? '+' . (int)$row['qty_returned'] : '-' ?></td>
                <td class="font-mono font-bold"><?= (int)$row['current_stock'] ?></td>
                <td class="font-mono text-muted"><?= $cur ?> <?= number_format($row['buy_price'], 0) ?></td>
                <td class="font-mono text-muted"><?= $cur ?> <?= number_format($row['price'], 0) ?></td>
                <td class="font-mono font-bold"><?= $cur ?> <?= number_format($stockVal, 0) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($audit)): ?>
              <tr><td colspan="<?= $storeFilter ? 10 : 11 ?>" style="text-align:center;padding:20px;color:var(--text3)">No products found</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($audit)): ?>
            <tfoot>
              <tr style="font-weight:700;border-top:2px solid var(--border)">
                <?php if (!$storeFilter): ?><td></td><?php endif; ?>
                <td colspan="2">TOTAL</td>
                <td class="font-mono"><?= number_format($totalOpening) ?></td>
                <td class="font-mono" style="color:var(--green)">+<?= number_format($totalAdded) ?></td>
                <td class="font-mono" style="color:var(--red)">-<?= number_format($totalSold) ?></td>
                <td class="font-mono" style="color:var(--orange)">+<?= number_format($totalReturned) ?></td>
                <td class="font-mono"><?= number_format($totalCurrent) ?></td>
                <td></td>
                <td></td>
                <td class="font-mono"><?= $cur ?> <?= number_format($totalStockValue, 0) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
    </div>

<style>
/* Stock Audit print styles — overrides the receipt-only thermal print CSS */
@media print {
  /* Force A4 paper size — override the 80mm thermal receipt size */
  @page {
    size: A4 portrait !important;
    margin: 15mm 12mm !important;
  }

  html, body {
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    font-size: 10px !important;
    color: #000 !important;
  }

  /* Make everything visible — override receipt print hiding */
  body * { visibility: visible !important; }

  /* Hide non-report elements */
  .sidebar, .no-print, .topbar-right, .topbar .no-print,
  .modal-overlay, .toast-wrap, #toast-wrap,
  .stat-icon, .stat-card::before,
  canvas { display: none !important; }

  .app-shell { display: block !important; height: auto !important; overflow: visible !important; }
  .main { margin: 0 !important; overflow: visible !important; height: auto !important; display: block !important; }
  .topbar {
    position: static !important; height: auto !important;
    padding: 0 0 10px !important; box-shadow: none !important;
    border-bottom: 2px solid #333 !important;
  }
  .content-area { overflow: visible !important; padding: 10px 0 !important; height: auto !important; }
  .print-header { display: block !important; }

  /* Stat cards in rows */
  .grid-4 { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 8px !important; }
  .stat-card { padding: 8px !important; border: 1px solid #ccc !important; box-shadow: none !important; background: #fff !important; }
  .stat-value { font-size: 16px !important; color: #000 !important; }
  .stat-label { font-size: 9px !important; color: #555 !important; }
  .card { box-shadow: none !important; border: 1px solid #ccc !important; break-inside: avoid; margin-bottom: 10px !important; background: #fff !important; }
  .card-header { padding: 6px 10px !important; }
  .card-title { font-size: 13px !important; }
  .table-wrap { overflow: visible !important; border: none !important; }
  .data-table { font-size: 9px !important; }
  .data-table th { font-size: 8px !important; padding: 4px 5px !important; background: #f0f0f0 !important; color: #000 !important; }
  .data-table td { padding: 4px 5px !important; color: #000 !important; }
  tfoot td { border-top: 2px solid #333 !important; }
  .badge { border: 1px solid #999 !important; background: transparent !important; color: #000 !important; }
  .mb-20 { margin-bottom: 10px !important; }

  /* Page break control */
  .card { page-break-inside: avoid; }
  table { page-break-inside: auto; }
  tr { page-break-inside: avoid; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
