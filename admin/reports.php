<?php
$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$allStores = getAllStores(true);
$userStoreId = currentStoreId();
$isSA = isSuperAdmin();
$isCashier = !isAdmin();

// Store filter logic
$storeFilter = (int)($_GET['store_id'] ?? 0);
if (!$isSA) {
    $storeFilter = $userStoreId ?: 0;
}

// Date filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-6 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$period = $_GET['period'] ?? '';

if ($period === '30days') { $dateFrom = date('Y-m-d', strtotime('-29 days')); $dateTo = date('Y-m-d'); }
elseif ($period === 'thismonth') { $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d'); }
elseif ($period === 'lastmonth') { $dateFrom = date('Y-m-01', strtotime('-1 month')); $dateTo = date('Y-m-t', strtotime('-1 month')); }
elseif ($period === '7days') { $dateFrom = date('Y-m-d', strtotime('-6 days')); $dateTo = date('Y-m-d'); }

// Product name filter
$productName = trim($_GET['product_name'] ?? '');

// Category filter
$catFilter = (int)($_GET['category_id'] ?? 0);

// Build WHERE clause with parameterized queries
$whereParts = ["DATE(b.created_at) >= ?", "DATE(b.created_at) <= ?"];
$params = [$dateFrom, $dateTo];

if ($storeFilter) {
    $whereParts[] = "b.store_id = ?";
    $params[] = $storeFilter;
} elseif (!$isSA && $userStoreId) {
    $whereParts[] = "b.store_id = ?";
    $params[] = $userStoreId;
}

$whereSql = implode(' AND ', $whereParts);

// Build returns WHERE clause (same date/store filters)
$returnWhereParts = ["DATE(r.created_at) >= ?", "DATE(r.created_at) <= ?"];
$returnParams = [$dateFrom, $dateTo];
if ($storeFilter) {
    $returnWhereParts[] = "r.store_id = ?";
    $returnParams[] = $storeFilter;
} elseif (!$isSA && $userStoreId) {
    $returnWhereParts[] = "r.store_id = ?";
    $returnParams[] = $userStoreId;
}
$returnWhereSql = implode(' AND ', $returnWhereParts);

// Total refunds for the period
$returnRefundSql = "SELECT COALESCE(SUM(r.total_refund),0) FROM returns r WHERE $returnWhereSql";
$returnRefundParams = $returnParams;
if ($catFilter) {
    // Filter returns by category: only count returns that have items in the selected category
    $returnRefundSql = "SELECT COALESCE(SUM(ri.refund_amount),0) FROM return_items ri JOIN returns r ON ri.return_id = r.id LEFT JOIN products p ON ri.product_id = p.id WHERE $returnWhereSql AND p.category_id = ?";
    $returnRefundParams = array_merge($returnParams, [$catFilter]);
}
$returnRefundStmt = $db->prepare($returnRefundSql);
$returnRefundStmt->execute($returnRefundParams);
$totalRefunds = (float)$returnRefundStmt->fetchColumn();

// Total cost of returned items (buy_price * returned quantity)
$returnCostSql = "SELECT COALESCE(SUM(ri.quantity * COALESCE(p.buy_price, 0)), 0) FROM return_items ri JOIN returns r ON ri.return_id = r.id LEFT JOIN products p ON ri.product_id = p.id WHERE $returnWhereSql";
$returnCostParams = $returnParams;
if ($catFilter) {
    $returnCostSql .= " AND p.category_id = ?";
    $returnCostParams[] = $catFilter;
}
$returnCostStmt = $db->prepare($returnCostSql);
$returnCostStmt->execute($returnCostParams);
$totalReturnCost = (float)$returnCostStmt->fetchColumn();

// Total discount returned (discount_share from return_items)
$returnDiscSql = "SELECT COALESCE(SUM(ri.discount_share), 0) FROM return_items ri JOIN returns r ON ri.return_id = r.id LEFT JOIN products p ON ri.product_id = p.id WHERE $returnWhereSql";
$returnDiscParams = $returnParams;
if ($catFilter) {
    $returnDiscSql .= " AND p.category_id = ?";
    $returnDiscParams[] = $catFilter;
}
$returnDiscStmt = $db->prepare($returnDiscSql);
$returnDiscStmt->execute($returnDiscParams);
$totalReturnedDiscount = (float)$returnDiscStmt->fetchColumn();

// Currency
$cur = 'Rs';
if ($storeFilter) {
    $cur = getStoreSettings($storeFilter)['currency'] ?? 'Rs';
} elseif ($userStoreId) {
    $cur = getStoreSettings($userStoreId)['currency'] ?? 'Rs';
}

// Summary stats
if ($catFilter) {
    // Category-filtered summary: based on bill_items joined with products
    $summaryStmt = $db->prepare("
        SELECT COUNT(DISTINCT b.id) as bills,
               COALESCE(SUM(bi.subtotal),0) as gross_revenue,
               COALESCE(SUM(CASE WHEN b.subtotal > 0 THEN bi.subtotal * b.discount / b.subtotal ELSE 0 END),0) as discount,
               COALESCE(SUM(bi.subtotal - CASE WHEN b.subtotal > 0 THEN bi.subtotal * b.discount / b.subtotal ELSE 0 END),0) as revenue,
               COALESCE(AVG(b.total),0) as avg_bill
        FROM bill_items bi
        JOIN bills b ON bi.bill_id = b.id
        LEFT JOIN products p ON bi.product_id = p.id
        WHERE $whereSql AND p.category_id = ?
    ");
    $summaryParams = array_merge($params, [$catFilter]);
    $summaryStmt->execute($summaryParams);
} else {
    $summaryStmt = $db->prepare("
        SELECT COUNT(*) as bills,
               COALESCE(SUM(b.subtotal),0) as gross_revenue,
               COALESCE(SUM(b.discount),0) as discount,
               COALESCE(SUM(b.total),0) as revenue,
               COALESCE(AVG(b.total),0) as avg_bill
        FROM bills b WHERE $whereSql
    ");
    $summaryStmt->execute($params);
}
$summary = $summaryStmt->fetch();
$totalDiscount = (float)($summary['discount'] ?? 0);
$summary['revenue'] = (float)$summary['revenue'] - $totalRefunds;
$summary['avg_bill'] = $summary['bills'] > 0 ? round($summary['revenue'] / $summary['bills'], 0) : 0;

// Profit data
$profitWhereParts = $whereParts;
$profitParams = $params;
if ($catFilter) {
    $profitWhereParts[] = "p.category_id = ?";
    $profitParams[] = $catFilter;
}
$profitWhereSql = implode(' AND ', $profitWhereParts);
$profitStmt = $db->prepare("
    SELECT COALESCE(SUM(bi.subtotal - CASE WHEN b.subtotal > 0 THEN bi.subtotal * b.discount / b.subtotal ELSE 0 END),0) as revenue,
           COALESCE(SUM(bi.quantity * COALESCE(p.buy_price, 0)),0) as cost
    FROM bill_items bi
    JOIN bills b ON bi.bill_id = b.id
    LEFT JOIN products p ON bi.product_id = p.id
    WHERE $profitWhereSql
");
$profitStmt->execute($profitParams);
$profitData = $profitStmt->fetch();
$totalProfit = $summary['revenue'] - (($profitData['cost'] ?? 0) - $totalReturnCost);

// Expenses for the selected date range
$expWhereParts = ["expense_date >= ?", "expense_date <= ?"];
$expParams = [$dateFrom, $dateTo];
if ($storeFilter) { $expWhereParts[] = "store_id = ?"; $expParams[] = $storeFilter; }
elseif (!$isSA && $userStoreId) { $expWhereParts[] = "store_id = ?"; $expParams[] = $userStoreId; }
$expStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE " . implode(' AND ', $expWhereParts));
$expStmt->execute($expParams);
$totalExpenses = (float)$expStmt->fetchColumn();

// Expenses for the current calendar month (independent of the date filter above)
$expMonthWhereParts = ["strftime('%Y-%m', expense_date) = strftime('%Y-%m','now')"];
$expMonthParams = [];
if ($storeFilter) { $expMonthWhereParts[] = "store_id = ?"; $expMonthParams[] = $storeFilter; }
elseif (!$isSA && $userStoreId) { $expMonthWhereParts[] = "store_id = ?"; $expMonthParams[] = $userStoreId; }
$expMonthStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE " . implode(' AND ', $expMonthWhereParts));
$expMonthStmt->execute($expMonthParams);
$expensesThisMonth = (float)$expMonthStmt->fetchColumn();

$netProfitAfterExpenses = $totalProfit - $totalExpenses;

// Outstanding customer credit (all-time, not limited to the date range — it's a current balance).
// Must match the ledger logic used on customers.php: billed minus paid-on-bills minus any later
// standalone payments recorded against the customer (customer_payments), not just bills.due_amount,
// since a due_amount recorded at sale time doesn't update when a later payment is made.
$creditWhereParts = ["b.customer_id IS NOT NULL"];
$creditParams = [];
if ($storeFilter) { $creditWhereParts[] = "b.store_id = ?"; $creditParams[] = $storeFilter; }
elseif (!$isSA && $userStoreId) { $creditWhereParts[] = "b.store_id = ?"; $creditParams[] = $userStoreId; }
$creditStmt = $db->prepare("SELECT COALESCE(SUM(b.total),0) as billed, COALESCE(SUM(b.paid_amount),0) as paid FROM bills b WHERE " . implode(' AND ', $creditWhereParts));
$creditStmt->execute($creditParams);
$creditRow = $creditStmt->fetch();

$extraPaidWhereParts = ["1=1"];
$extraPaidParams = [];
if ($storeFilter) { $extraPaidWhereParts[] = "store_id = ?"; $extraPaidParams[] = $storeFilter; }
elseif (!$isSA && $userStoreId) { $extraPaidWhereParts[] = "store_id = ?"; $extraPaidParams[] = $userStoreId; }
$extraPaidStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE " . implode(' AND ', $extraPaidWhereParts));
$extraPaidStmt->execute($extraPaidParams);
$extraPaid = (float)$extraPaidStmt->fetchColumn();

$totalOutstandingCredit = max(0, (float)$creditRow['billed'] - (float)$creditRow['paid'] - $extraPaid);

// Product report — with product name filter
$productWhereParts = $whereParts;
$productParams = $params;
if ($productName) {
    $productWhereParts[] = "bi.product_name LIKE ?";
    $productParams[] = '%' . $productName . '%';
}
if ($catFilter) {
    $productWhereParts[] = "p.category_id = ?";
    $productParams[] = $catFilter;
}
$productWhereSql = implode(' AND ', $productWhereParts);

$productReportStmt = $db->prepare("
    SELECT s.name as store_name, bi.product_name, c.name as category_name,
           SUM(bi.quantity) - COALESCE(ret.ret_qty, 0) as qty_sold,
           SUM(bi.subtotal) as gross_revenue,
           SUM(CASE WHEN b.subtotal > 0 THEN bi.subtotal * b.discount / b.subtotal ELSE 0 END) as item_discount,
           SUM(bi.subtotal - CASE WHEN b.subtotal > 0 THEN bi.subtotal * b.discount / b.subtotal ELSE 0 END) - COALESCE(ret.ret_revenue, 0) as revenue,
           SUM(bi.quantity * COALESCE(p.buy_price, 0)) - COALESCE(ret.ret_cost, 0) as cost,
           (SUM(bi.subtotal - CASE WHEN b.subtotal > 0 THEN bi.subtotal * b.discount / b.subtotal ELSE 0 END) - COALESCE(ret.ret_revenue, 0)) - (SUM(bi.quantity * COALESCE(p.buy_price, 0)) - COALESCE(ret.ret_cost, 0)) as profit
    FROM bill_items bi
    JOIN bills b ON bi.bill_id = b.id
    LEFT JOIN products p ON bi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stores s ON b.store_id = s.id
    LEFT JOIN (
        SELECT ri.product_id, b2.store_id,
               SUM(ri.quantity) as ret_qty,
               SUM(ri.refund_amount) as ret_revenue,
               SUM(ri.quantity * COALESCE(p2.buy_price, 0)) as ret_cost
        FROM return_items ri
        JOIN returns r2 ON ri.return_id = r2.id
        JOIN bills b2 ON r2.bill_id = b2.id
        LEFT JOIN products p2 ON ri.product_id = p2.id
        WHERE DATE(r2.created_at) >= ? AND DATE(r2.created_at) <= ?
        GROUP BY ri.product_id, b2.store_id
    ) ret ON ret.product_id = bi.product_id AND ret.store_id = b.store_id
    WHERE $productWhereSql
    GROUP BY b.store_id, bi.product_name, c.name
    ORDER BY revenue DESC
");
// Extra params for the returns subquery
$productReportExtraParams = [$dateFrom, $dateTo];
$productReportStmt->execute(array_merge($productReportExtraParams, $productParams));
$productReport = $productReportStmt->fetchAll();

// Store summary (only for super admin viewing all stores)
$storeSummary = [];
if ($isSA) {
    $storeSummaryStmt = $db->prepare("
        SELECT s.id, s.name as store_name, s.currency,
               COALESCE(bs.bills, 0) as bills,
               COALESCE(bs.revenue, 0) as revenue,
               COALESCE(rs.refunds, 0) as refunds,
               COALESCE(pc.cost, 0) as cost,
               COALESCE(rc.return_cost, 0) as return_cost
        FROM stores s
        LEFT JOIN (
            SELECT store_id, COUNT(*) as bills, COALESCE(SUM(total),0) as revenue
            FROM bills WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?
            GROUP BY store_id
        ) bs ON bs.store_id = s.id
        LEFT JOIN (
            SELECT store_id, COALESCE(SUM(total_refund),0) as refunds
            FROM returns WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?
            GROUP BY store_id
        ) rs ON rs.store_id = s.id
        LEFT JOIN (
            SELECT b.store_id, SUM(bi.quantity * COALESCE(p.buy_price,0)) as cost
            FROM bill_items bi
            JOIN bills b ON bi.bill_id = b.id
            LEFT JOIN products p ON bi.product_id = p.id
            WHERE DATE(b.created_at) >= ? AND DATE(b.created_at) <= ?
            GROUP BY b.store_id
        ) pc ON pc.store_id = s.id
        LEFT JOIN (
            SELECT r.store_id, SUM(ri.quantity * COALESCE(p.buy_price,0)) as return_cost
            FROM return_items ri
            JOIN returns r ON ri.return_id = r.id
            LEFT JOIN products p ON ri.product_id = p.id
            WHERE DATE(r.created_at) >= ? AND DATE(r.created_at) <= ?
            GROUP BY r.store_id
        ) rc ON rc.store_id = s.id
        ORDER BY revenue DESC
    ");
    $storeSummaryStmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo]);
    $storeSummary = $storeSummaryStmt->fetchAll();
}

// Daily breakdown — subtract returns
if ($catFilter) {
    $byDayStmt = $db->prepare("SELECT DATE(b.created_at) as day, COUNT(DISTINCT b.id) as bills, COALESCE(SUM(bi.subtotal - CASE WHEN b.subtotal > 0 THEN bi.subtotal * b.discount / b.subtotal ELSE 0 END),0) as revenue FROM bill_items bi JOIN bills b ON bi.bill_id = b.id LEFT JOIN products p ON bi.product_id = p.id WHERE $whereSql AND p.category_id = ? GROUP BY DATE(b.created_at) ORDER BY day");
    $byDayStmt->execute(array_merge($params, [$catFilter]));
} else {
    $byDayStmt = $db->prepare("SELECT DATE(b.created_at) as day, COUNT(*) as bills, COALESCE(SUM(b.total),0) as revenue FROM bills b WHERE $whereSql GROUP BY DATE(b.created_at) ORDER BY day");
    $byDayStmt->execute($params);
}
$byDay = $byDayStmt->fetchAll();

if ($catFilter) {
    $byDayReturns = $db->prepare("SELECT DATE(r.created_at) as day, COALESCE(SUM(ri.refund_amount),0) as refund FROM return_items ri JOIN returns r ON ri.return_id = r.id LEFT JOIN products p ON ri.product_id = p.id WHERE $returnWhereSql AND p.category_id = ? GROUP BY DATE(r.created_at)");
    $byDayReturns->execute(array_merge($returnParams, [$catFilter]));
} else {
    $byDayReturns = $db->prepare("SELECT DATE(r.created_at) as day, COALESCE(SUM(r.total_refund),0) as refund FROM returns r WHERE $returnWhereSql GROUP BY DATE(r.created_at)");
    $byDayReturns->execute($returnParams);
}
$byDayReturnsData = $byDayReturns->fetchAll();
$refundDayMap = [];
foreach ($byDayReturnsData as $rd) { $refundDayMap[$rd['day']] = (float)$rd['refund']; }
foreach ($byDay as &$d) { $d['revenue'] = (float)$d['revenue'] - ($refundDayMap[$d['day']] ?? 0); }
unset($d);

// Payment method breakdown
if ($catFilter) {
    $byPaymentStmt = $db->prepare("SELECT b.payment_method, COUNT(DISTINCT b.id) as bills, COALESCE(SUM(bi.subtotal - CASE WHEN b.subtotal > 0 THEN bi.subtotal * b.discount / b.subtotal ELSE 0 END),0) as revenue FROM bill_items bi JOIN bills b ON bi.bill_id = b.id LEFT JOIN products p ON bi.product_id = p.id WHERE $whereSql AND p.category_id = ? GROUP BY b.payment_method");
    $byPaymentStmt->execute(array_merge($params, [$catFilter]));
} else {
    $byPaymentStmt = $db->prepare("SELECT b.payment_method, COUNT(*) as bills, COALESCE(SUM(b.total),0) as revenue FROM bills b WHERE $whereSql GROUP BY b.payment_method");
    $byPaymentStmt->execute($params);
}
$byPayment = $byPaymentStmt->fetchAll();

// Product names for dropdown filter — super admin sees all, others see their store's products
$storeProductNames = [];
if ($isSA) {
    $pnStmt = $db->query("SELECT DISTINCT name FROM products ORDER BY name");
    $storeProductNames = $pnStmt->fetchAll(PDO::FETCH_COLUMN);
} elseif ($storeFilter) {
    $pnStmt = $db->prepare("SELECT DISTINCT name FROM products WHERE store_id = ? ORDER BY name");
    $pnStmt->execute([$storeFilter]);
    $storeProductNames = $pnStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Categories for dropdown filter
$filterCategories = [];
if ($isSA) {
    $filterCategories = $db->query("SELECT c.*, s.name as store_name FROM categories c LEFT JOIN stores s ON c.store_id = s.id ORDER BY s.name, c.name")->fetchAll();
} elseif ($storeFilter) {
    $catStmt = $db->prepare("SELECT * FROM categories WHERE store_id = ? ORDER BY name");
    $catStmt->execute([$storeFilter]);
    $filterCategories = $catStmt->fetchAll();
}

$label = date('d M Y', strtotime($dateFrom)) . ' — ' . date('d M Y', strtotime($dateTo));
if ($storeFilter) {
    $storeInfo = getStore($storeFilter);
    $label = ($storeInfo['name'] ?? 'Store') . ' · ' . $label;
}

$storeName = '';
if ($storeFilter) {
    $si = getStore($storeFilter);
    $storeName = $si['name'] ?? '';
} elseif ($isSA) {
    $storeName = 'All Stores';
} else {
    $storeName = $settings['restaurant_name'] ?? 'My Store';
}
?>
    <div class="topbar" style="height:auto;min-height:56px;flex-wrap:wrap;padding:12px 24px;gap:10px">
      <div><div class="page-title">Reports & Analytics</div><div class="page-subtitle"><?= htmlspecialchars($label) ?></div></div>
      <div class="topbar-right no-print" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
        <button class="btn btn-secondary btn-sm" onclick="printReport()">Print Report</button>
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?php if ($isSA): ?>
          <select class="form-control" name="store_id" style="font-size:13px;padding:7px 10px;width:auto">
            <option value="0" <?= !$storeFilter?'selected':'' ?>>All Stores</option>
            <?php foreach ($allStores as $st): ?>
            <option value="<?= $st['id'] ?>" <?= $storeFilter==$st['id']?'selected':'' ?>><?= htmlspecialchars($st['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="font-size:13px;padding:7px 10px;width:auto">
          <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="font-size:13px;padding:7px 10px;width:auto">
          <select class="form-control" name="product_name" style="font-size:13px;padding:7px 10px;width:auto">
            <option value="">All Products</option>
            <?php foreach ($storeProductNames as $pn): ?>
            <option value="<?= htmlspecialchars($pn) ?>" <?= $productName==$pn?'selected':'' ?>><?= htmlspecialchars($pn) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="form-control" name="category_id" style="font-size:13px;padding:7px 10px;width:auto" onchange="this.form.submit()">
            <option value="0">All Categories</option>
            <?php foreach ($filterCategories as $fc): ?>
            <option value="<?= $fc['id'] ?>" <?= $catFilter==$fc['id']?'selected':'' ?>><?= htmlspecialchars($fc['name']) ?><?= isset($fc['store_name']) && $isSA ? ' ('.htmlspecialchars($fc['store_name']).')' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <select class="form-control" name="period" onchange="if(this.value){this.form.date_from.value='';this.form.date_to.value='';}this.form.submit()" style="font-size:13px;padding:7px 10px;width:auto">
            <option value="">Custom Range</option>
            <option value="7days" <?= $period==='7days'?'selected':'' ?>>Last 7 Days</option>
            <option value="30days" <?= $period==='30days'?'selected':'' ?>>Last 30 Days</option>
            <option value="thismonth" <?= $period==='thismonth'?'selected':'' ?>>This Month</option>
            <option value="lastmonth" <?= $period==='lastmonth'?'selected':'' ?>>Last Month</option>
          </select>
          <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        </form>
      </div>
    </div>
    <div class="content-area" id="report-content">

      <!-- PRINT HEADER — visible only when printing -->
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
            <h2 style="margin:0 0 2px;font-size:18px"><?= htmlspecialchars($storeName) ?></h2>
            <p style="margin:0;font-size:12px;color:#555">Sales Report: <?= htmlspecialchars($label) ?><?php if ($productName): ?> — Product: <?= htmlspecialchars($productName) ?><?php endif; ?></p>
            <p style="margin:0;font-size:10px;color:#888">Generated: <?= date('d M Y, h:i A') ?></p>
          </div>
        </div>
        <hr style="border:none;border-top:2px solid #333;margin:0 0 16px">
      </div>

      <div class="grid-5 mb-20">
        <div class="stat-card brand"><div class="stat-icon">🧾</div><div class="stat-value"><?= (int)$summary['bills'] ?></div><div class="stat-label">Total Bills</div></div>
        <div class="stat-card" style="border-top:3px solid #6c757d"><div class="stat-icon">💵</div><div class="stat-value"><?= $cur ?> <?= number_format($summary['gross_revenue'] ?? 0, 0) ?></div><div class="stat-label">Gross Sales</div></div>
        <div class="stat-card red"><div class="stat-icon">🏷️</div><div class="stat-value"><?= $cur ?> <?= number_format($totalDiscount - $totalReturnedDiscount, 0) ?></div><div class="stat-label">Net Discount<?php if ($totalReturnedDiscount > 0): ?><div style="font-size:11px;color:var(--text3);margin-top:2px">Given: <?= $cur ?> <?= number_format($totalDiscount, 0) ?> · Returned: <?= $cur ?> <?= number_format($totalReturnedDiscount, 0) ?></div><?php endif; ?></div></div>
        <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-value"><?= $cur ?> <?= number_format($summary['revenue'], 0) ?></div><div class="stat-label">Net Sales</div></div>
        <div class="stat-card" style="border-top:3px solid #e74c3c"><div class="stat-icon">↩️</div><div class="stat-value"><?= $cur ?> <?= number_format($totalRefunds, 0) ?></div><div class="stat-label">Returns</div></div>
      </div>
      <?php if (!$isCashier): ?>
      <div class="grid-5 mb-20">
        <div class="stat-card blue"><div class="stat-icon">📈</div><div class="stat-value"><?= $cur ?> <?= number_format($totalProfit, 0) ?></div><div class="stat-label">Gross Profit (before expenses)</div></div>
        <div class="stat-card" style="border-top:3px solid #d97706"><div class="stat-icon">🧾</div><div class="stat-value"><?= $cur ?> <?= number_format($totalExpenses, 0) ?></div><div class="stat-label">Expenses (selected range)</div></div>
        <div class="stat-card green"><div class="stat-icon">✅</div><div class="stat-value"><?= $cur ?> <?= number_format($netProfitAfterExpenses, 0) ?></div><div class="stat-label">Net Profit (after expenses)</div></div>
        <div class="stat-card" style="border-top:3px solid #d97706"><div class="stat-icon">📅</div><div class="stat-value"><?= $cur ?> <?= number_format($expensesThisMonth, 0) ?></div><div class="stat-label">Expenses This Month</div></div>
        <div class="stat-card red"><div class="stat-icon">💳</div><div class="stat-value"><?= $cur ?> <?= number_format($totalOutstandingCredit, 0) ?></div><div class="stat-label">Outstanding Customer Credit</div></div>
      </div>
      <div class="mb-20" style="text-align:right">
        <a href="<?= baseUrl('admin/expenses.php') ?>" class="btn btn-secondary btn-sm no-print">🧾 Manage Expenses →</a>
        <a href="<?= baseUrl('customers.php') ?>" class="btn btn-secondary btn-sm no-print">💳 Manage Credit →</a>
      </div>
      <?php else: ?>
      <div class="grid-4 mb-20">
        <div class="stat-card blue" style="opacity:.5"><div class="stat-icon">📈</div><div class="stat-value">—</div><div class="stat-label">Net Profit</div></div>
      </div>
      <?php endif; ?>

      <?php if ($isSA && !$storeFilter): ?>
      <div class="card mb-20">
        <div class="card-header"><span class="card-title">Sales & Profit by Store</span></div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Store</th><th>Bills</th><th>Sales</th><th>Returns</th><th>Net Sales</th><th>Cost</th><th>Profit</th></tr></thead>
            <tbody>
            <?php foreach ($storeSummary as $ss):
              $netRev = (float)$ss['revenue'] - (float)($ss['refunds'] ?? 0);
              $netCost = (float)$ss['cost'] - (float)($ss['return_cost'] ?? 0);
              $profit = $netRev - $netCost;
              $c = $ss['currency'] ?? 'Rs';
            ?>
            <tr>
              <td class="font-bold"><?= htmlspecialchars($ss['store_name']) ?></td>
              <td><?= (int)$ss['bills'] ?></td>
              <td class="font-mono"><?= $c ?> <?= number_format($ss['revenue'], 0) ?></td>
              <td class="font-mono" style="color:var(--red)">-<?= $c ?> <?= number_format($ss['refunds'] ?? 0, 0) ?></td>
              <td class="font-mono font-bold"><?= $c ?> <?= number_format($netRev, 0) ?></td>
              <td class="font-mono text-muted"><?= $c ?> <?= number_format($netCost, 0) ?></td>
              <td class="font-mono font-bold" style="color:var(--green)"><?= $c ?> <?= number_format($profit, 0) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($storeSummary)): ?><tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text3)">No store data for this period</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <div class="card mb-20">
        <div class="card-header"><span class="card-title">Product Report (Sales<?php if (!$isCashier): ?> & Profit<?php endif; ?>)<?php if ($productName): ?> — Product: <?= htmlspecialchars($productName) ?><?php endif; ?><?php if ($catFilter): ?> — Category: <?= htmlspecialchars(array_values(array_filter($filterCategories, fn($c) => $c['id'] == $catFilter))[0]['name'] ?? '') ?><?php endif; ?></span></div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><?php if(!$storeFilter):?><th>Store</th><?php endif;?><th>Category</th><th>Product</th><th>Qty Sold</th><th>Revenue</th><th>Discount</th><?php if (!$isCashier): ?><th>Cost</th><th>Profit</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($productReport as $pr): ?>
            <tr>
              <?php if(!$storeFilter):?><td><?= htmlspecialchars($pr['store_name']) ?></td><?php endif; ?>
              <td><span class="badge badge-neutral"><?= htmlspecialchars($pr['category_name'] ?? 'Uncategorized') ?></span></td>
              <td class="font-bold"><?= htmlspecialchars($pr['product_name']) ?></td>
              <td><?= (int)$pr['qty_sold'] ?></td>
              <td class="font-mono"><?= $cur ?> <?= number_format($pr['revenue'], 0) ?></td>
              <td class="font-mono" style="color:var(--red)"><?= $pr['item_discount'] > 0 ? '-'.$cur.' '.number_format($pr['item_discount'], 0) : '-' ?></td>
              <?php if (!$isCashier): ?>
              <td class="font-mono text-muted"><?= $cur ?> <?= number_format($pr['cost'], 0) ?></td>
              <td class="font-mono font-bold" style="color:var(--green)"><?= $cur ?> <?= number_format($pr['profit'], 0) ?></td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($productReport)): ?><tr><td colspan="<?= $isCashier ? ($storeFilter ? 5 : 6) : ($storeFilter ? 7 : 8) ?>" style="text-align:center;padding:20px;color:var(--text3)">No sales data for this period</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="grid-2 mb-20">
        <div class="card">
          <div class="card-header"><span class="card-title">Daily Revenue</span></div>
          <?php if (!empty($byDay)): ?><canvas id="daily-chart" height="200"></canvas><?php else: ?><p class="text-muted text-sm">No data.</p><?php endif; ?>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title">Payment Methods</span></div>
          <?php if (!empty($byPayment)): ?><canvas id="pay-chart" height="200"></canvas><?php else: ?><p class="text-muted text-sm">No data.</p><?php endif; ?>
        </div>
      </div>

      <!-- Print-only tables for charts (visible only when printing) -->
      <div class="print-only-tables" style="display:none">
        <?php if (!empty($byDay)): ?>
        <div class="card mb-16" style="margin-bottom:12px">
          <div class="card-header"><span class="card-title">Daily Revenue</span></div>
          <table class="data-table">
            <thead><tr><th>Date</th><th>Bills</th><th>Revenue</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($byDay) as $d): ?>
            <tr>
              <td><?= date('D, d M Y', strtotime($d['day'])) ?></td>
              <td><?= $d['bills'] ?></td>
              <td class="font-mono font-bold"><?= $cur ?> <?= number_format($d['revenue'], 0) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <?php if (!empty($byPayment)): ?>
        <div class="card mb-16" style="margin-bottom:12px">
          <div class="card-header"><span class="card-title">Payment Methods</span></div>
          <table class="data-table">
            <thead><tr><th>Method</th><th>Bills</th><th>Revenue</th></tr></thead>
            <tbody>
            <?php foreach ($byPayment as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['payment_method']) ?></td>
              <td><?= $p['bills'] ?></td>
              <td class="font-mono font-bold"><?= $cur ?> <?= number_format($p['revenue'], 0) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header"><span class="card-title">Daily Breakdown</span></div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Bills</th><th>Revenue</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($byDay) as $d): ?>
            <tr>
              <td><?= date('l, d M Y', strtotime($d['day'])) ?></td>
              <td><?= $d['bills'] ?></td>
              <td class="font-mono font-bold"><?= $cur ?> <?= number_format($d['revenue'], 0) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($byDay)): ?><tr><td colspan="3" style="text-align:center;padding:20px;color:var(--text3)">No bills for this period</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

<style>
/* Report print styles — overrides the receipt-only thermal print CSS */
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
    font-size: 11px !important;
    color: #000 !important;
  }

  /* Make everything visible — override receipt print hiding */
  body * { visibility: visible !important; }

  /* Hide non-report elements */
  .sidebar, .no-print, .topbar-right, .topbar .no-print,
  .modal-overlay, .toast-wrap, #toast-wrap,
  .stat-icon, .stat-card::before,
  canvas, .print-only-tables { display: none !important; }

  /* Show print-only tables (chart data as tables) */
  .print-only-tables { display: block !important; }

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
  .grid-5 { display: grid !important; grid-template-columns: repeat(5, 1fr) !important; gap: 8px !important; }
  .grid-4 { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 8px !important; }
  .grid-2 { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 10px !important; }
  .stat-card { padding: 8px !important; border: 1px solid #ccc !important; box-shadow: none !important; background: #fff !important; }
  .stat-value { font-size: 16px !important; color: #000 !important; }
  .stat-label { font-size: 9px !important; color: #555 !important; }
  .card { box-shadow: none !important; border: 1px solid #ccc !important; break-inside: avoid; margin-bottom: 10px !important; background: #fff !important; }
  .card-header { padding: 6px 10px !important; }
  .card-title { font-size: 13px !important; }
  .table-wrap { overflow: visible !important; border: none !important; }
  .data-table { font-size: 10px !important; }
  .data-table th { font-size: 9px !important; padding: 5px 6px !important; background: #f0f0f0 !important; color: #000 !important; }
  .data-table td { padding: 5px 6px !important; color: #000 !important; }
  .badge { border: 1px solid #999 !important; background: transparent !important; color: #000 !important; }
  .mb-20 { margin-bottom: 12px !important; }

  /* Page break control */
  .card { page-break-inside: avoid; }
  table { page-break-inside: auto; }
  tr { page-break-inside: avoid; }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const byDay = <?= json_encode($byDay) ?>;
const byPayment = <?= json_encode($byPayment) ?>;
if (byDay.length) new Chart(document.getElementById('daily-chart'), {
  type: 'line', data: { labels: byDay.map(d => d.day), datasets: [{ label: 'Revenue', data: byDay.map(d => parseFloat(d.revenue)), borderColor: '#e85d26', tension: 0.4, fill: true, backgroundColor: 'rgba(232,93,38,.08)' }] },
  options: { plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true} } }
});
<?php if (!empty($byPayment)): ?>
new Chart(document.getElementById('pay-chart'), {
  type: 'doughnut', data: { labels: byPayment.map(p=>p.payment_method), datasets: [{ data: byPayment.map(p=>parseFloat(p.revenue)), backgroundColor: ['#e85d26','#2563eb','#1a9e5c','#d97706'] }] },
  options: { cutout:'70%' }
});
<?php endif; ?>

function printReport() {
  window.print();
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>