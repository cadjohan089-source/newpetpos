<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requireStorePage();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$storeId = currentStoreId();
$cur = $settings['currency'] ?? 'Rs';

$todayRev   = $db->prepare("SELECT COALESCE(SUM(total),0) FROM bills WHERE store_id=? AND DATE(created_at)=DATE('now')");
$todayRev->execute([$storeId]); $todayRev = $todayRev->fetchColumn();
$todayRefund = $db->prepare("SELECT COALESCE(SUM(total_refund),0) FROM returns WHERE store_id=? AND DATE(created_at)=DATE('now')");
$todayRefund->execute([$storeId]); $todayRefund = $todayRefund->fetchColumn();
$todayRev = $todayRev - $todayRefund;
$todayCount = $db->prepare("SELECT COUNT(*) FROM bills WHERE store_id=? AND DATE(created_at)=DATE('now')");
$todayCount->execute([$storeId]); $todayCount = $todayCount->fetchColumn();
$monthRev   = $db->prepare("SELECT COALESCE(SUM(total),0) FROM bills WHERE store_id=? AND strftime('%Y-%m',created_at)=strftime('%Y-%m','now')");
$monthRev->execute([$storeId]); $monthRev = $monthRev->fetchColumn();
$monthRefund = $db->prepare("SELECT COALESCE(SUM(total_refund),0) FROM returns WHERE store_id=? AND strftime('%Y-%m',created_at)=strftime('%Y-%m','now')");
$monthRefund->execute([$storeId]); $monthRefund = $monthRefund->fetchColumn();
$monthRev = $monthRev - $monthRefund;
$allTime    = $db->prepare("SELECT COALESCE(SUM(total),0) FROM bills WHERE store_id=?");
$allTime->execute([$storeId]); $allTime = $allTime->fetchColumn();
$allRefund  = $db->prepare("SELECT COALESCE(SUM(total_refund),0) FROM returns WHERE store_id=?");
$allRefund->execute([$storeId]); $allRefund = $allRefund->fetchColumn();
$allTime = $allTime - $allRefund;
$totalProducts = $db->prepare("SELECT COUNT(*) FROM products WHERE store_id=? AND available=1 AND stock_qty > 0");
$totalProducts->execute([$storeId]); $totalProducts = $totalProducts->fetchColumn();

$last7 = $db->prepare("SELECT DATE(b.created_at) as day, COALESCE(SUM(b.total),0) as revenue, COUNT(*) as bills FROM bills b WHERE b.store_id=? AND b.created_at >= DATE('now', '-6 days') GROUP BY DATE(b.created_at) ORDER BY day");
$last7->execute([$storeId]); $last7 = $last7->fetchAll();

// Subtract returns from last7 daily revenue
$last7Returns = $db->prepare("SELECT DATE(created_at) as day, COALESCE(SUM(total_refund),0) as refund FROM returns WHERE store_id=? AND created_at >= DATE('now', '-6 days') GROUP BY DATE(created_at)");
$last7Returns->execute([$storeId]); $last7Returns = $last7Returns->fetchAll();
$refundMap = [];
foreach ($last7Returns as $rr) { $refundMap[$rr['day']] = (float)$rr['refund']; }
foreach ($last7 as &$d) { $d['revenue'] = (float)$d['revenue'] - ($refundMap[$d['day']] ?? 0); }
unset($d);

$topProds = $db->prepare("SELECT bi.product_name, SUM(bi.quantity) as qty, SUM(bi.subtotal) as revenue FROM bill_items bi JOIN bills b ON bi.bill_id=b.id WHERE b.store_id=? GROUP BY bi.product_name ORDER BY qty DESC LIMIT 8");
$topProds->execute([$storeId]); $topProds = $topProds->fetchAll();

$recentBills = $db->prepare("SELECT * FROM bills WHERE store_id=? ORDER BY created_at DESC LIMIT 8");
$recentBills->execute([$storeId]); $recentBills = $recentBills->fetchAll();
?>
    <div class="topbar">
      <div><div class="page-title">Dashboard</div><div class="page-subtitle">Sales overview — <?= date('l, d M Y') ?></div></div>
      <div class="topbar-right">
        <a href="<?= baseUrl('index.php') ?>" class="btn btn-primary btn-sm">+ New Bill</a>
      </div>
    </div>
    <div class="content-area">
      <div class="grid-4 mb-20">
        <div class="stat-card brand"><div class="stat-icon">🧾</div><div class="stat-value"><?= $todayCount ?></div><div class="stat-label">Bills Today</div></div>
        <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-value"><?= $cur ?> <?= number_format($todayRev, 0) ?></div><div class="stat-label">Revenue Today</div></div>
        <div class="stat-card blue"><div class="stat-icon">📅</div><div class="stat-value"><?= $cur ?> <?= number_format($monthRev, 0) ?></div><div class="stat-label">This Month</div></div>
        <div class="stat-card amber"><div class="stat-icon">🏆</div><div class="stat-value"><?= $cur ?> <?= number_format($allTime, 0) ?></div><div class="stat-label">All-time Revenue</div></div>
      </div>

      <div class="grid-2 mb-20">
        <div class="card">
          <div class="card-header"><span class="card-title">Revenue — Last 7 Days</span></div>
          <canvas id="revenue-chart" height="180"></canvas>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title">Top Selling Products</span></div>
          <?php if (empty($topProds)): ?>
          <p class="text-muted text-sm">No sales data yet.</p>
          <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach ($topProds as $i => $p): ?>
            <div style="display:flex;align-items:center;gap:10px">
              <span style="width:20px;height:20px;background:var(--brand);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0"><?= $i+1 ?></span>
              <span style="flex:1;font-size:13px;font-weight:500"><?= htmlspecialchars($p['product_name']) ?></span>
              <span class="badge badge-neutral font-mono"><?= $p['qty'] ?> sold</span>
              <span style="font-size:13px;font-weight:700;font-family:var(--mono)"><?= $cur ?> <?= number_format($p['revenue'], 0) ?></span>
            </div>
          <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <span class="card-title">Recent Bills</span>
          <a href="<?= baseUrl('bills.php') ?>" class="btn btn-ghost btn-sm">View all →</a>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Bill #</th><th>Customer</th><th>Table</th><th>Total</th><th>Payment</th><th>Time</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentBills as $b): ?>
            <tr>
              <td class="font-mono font-bold"><?= htmlspecialchars($b['bill_no']) ?></td>
              <td><?= htmlspecialchars($b['customer_name']) ?></td>
              <td><?= htmlspecialchars($b['table_no']) ?></td>
              <td class="font-mono font-bold"><?= $cur ?> <?= number_format($b['total'], 0) ?></td>
              <td><span class="badge <?= $b['payment_method']==='Cash'?'badge-success':'badge-info' ?>"><?= $b['payment_method'] ?></span></td>
              <td class="text-sm text-muted"><?= date('d M, h:i A', strtotime($b['created_at'])) ?></td>
              <td><button class="btn btn-secondary btn-sm" onclick="showReceipt('<?= $b['bill_no'] ?>')">View</button></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentBills)): ?><tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text3)">No bills yet</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const last7 = <?= json_encode($last7) ?>;
const allDays = [];
for (let i=6; i>=0; i--) {
  const d = new Date(); d.setDate(d.getDate()-i);
  allDays.push(d.toISOString().split('T')[0]);
}
const revenueMap = {};
last7.forEach(r => revenueMap[r.day] = parseFloat(r.revenue));
const labels = allDays.map(d => new Date(d+'T00:00:00').toLocaleDateString('en-PK',{weekday:'short',day:'numeric'}));
const data = allDays.map(d => revenueMap[d] || 0);
new Chart(document.getElementById('revenue-chart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: 'Revenue',
      data,
      backgroundColor: 'rgba(232,93,38,.15)',
      borderColor: '#e85d26',
      borderWidth: 2,
      borderRadius: 6,
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { callback: v => 'Rs '+v.toLocaleString() } },
      x: { grid: { display: false } }
    }
  }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
