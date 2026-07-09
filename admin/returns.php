<?php
$pageTitle = 'Returns';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireStorePage();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$storeId = currentStoreId();
$cur = $settings['currency'] ?? 'Rs';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = ['r.store_id = ?'];
$params = [$storeId];
if ($search) {
    $where[] = "(r.return_no LIKE ? OR b.bill_no LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($dateFrom) { $where[] = "DATE(r.created_at) >= ?"; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = "DATE(r.created_at) <= ?"; $params[] = $dateTo; }
$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("SELECT COUNT(*) FROM returns r LEFT JOIN bills b ON r.bill_id = b.id WHERE $whereStr");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pages = ceil($total / $limit);

$stmt = $db->prepare("SELECT r.*, b.bill_no, u.name as created_by_name FROM returns r LEFT JOIN bills b ON r.bill_id = b.id LEFT JOIN users u ON r.created_by = u.id WHERE $whereStr ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$returns = $stmt->fetchAll();

// Summary
$todayRefund = $db->prepare("SELECT COALESCE(SUM(total_refund),0) FROM returns WHERE store_id=? AND DATE(created_at)=DATE('now')");
$todayRefund->execute([$storeId]); $todayRefund = $todayRefund->fetchColumn();
$todayCount = $db->prepare("SELECT COUNT(*) FROM returns WHERE store_id=? AND DATE(created_at)=DATE('now')");
$todayCount->execute([$storeId]); $todayCount = $todayCount->fetchColumn();
$allRefund = $db->prepare("SELECT COALESCE(SUM(total_refund),0) FROM returns WHERE store_id=?");
$allRefund->execute([$storeId]); $allRefund = $allRefund->fetchColumn();
?>
    <div class="topbar">
      <div>
        <div class="page-title">Returns</div>
        <div class="page-subtitle">Manage product returns and refunds</div>
      </div>
    </div>
    <div class="content-area">
      <div class="grid-3 mb-20">
        <div class="stat-card amber"><div class="stat-icon">↩️</div><div class="stat-value"><?= $todayCount ?></div><div class="stat-label">Returns Today</div></div>
        <div class="stat-card red"><div class="stat-icon">💰</div><div class="stat-value"><?= $cur ?> <?= number_format($todayRefund, 0) ?></div><div class="stat-label">Refunded Today</div></div>
        <div class="stat-card blue"><div class="stat-icon">📊</div><div class="stat-value"><?= $cur ?> <?= number_format($allRefund, 0) ?></div><div class="stat-label">Total Refunded</div></div>
      </div>

      <div class="card">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
          <input class="form-control" style="width:220px" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search return# or bill#…">
          <input class="form-control" style="width:150px" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" title="From date">
          <input class="form-control" style="width:150px" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" title="To date">
          <button class="btn btn-secondary" type="submit">Filter</button>
          <?php if ($search || $dateFrom || $dateTo): ?><a href="<?= baseUrl('admin/returns.php') ?>" class="btn btn-ghost">Clear</a><?php endif; ?>
        </form>

        <div class="table-wrap">
          <table class="data-table">
            <thead><tr>
              <th>Return #</th><th>Bill #</th><th>Reason</th><th>Refund</th><th>Processed By</th><th>Date & Time</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if (empty($returns)): ?>
              <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No returns found</td></tr>
            <?php else: ?>
              <?php foreach ($returns as $r): ?>
              <tr>
                <td><span class="font-mono font-bold"><?= htmlspecialchars($r['return_no']) ?></span></td>
                <td class="font-mono"><?= htmlspecialchars($r['bill_no'] ?? '—') ?></td>
                <td class="text-sm"><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
                <td class="font-mono font-bold" style="color:var(--red)"><?= $cur ?> <?= number_format($r['total_refund'], 0) ?></td>
                <td class="text-sm"><?= htmlspecialchars($r['created_by_name'] ?? '—') ?></td>
                <td class="text-sm text-muted"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></td>
                <td>
                  <button class="btn btn-secondary btn-sm" onclick="viewReturnDetails(<?= (int)$r['id'] ?>)">View</button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
        <div style="display:flex;justify-content:center;gap:6px;margin-top:16px">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
          <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"
             class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

<!-- RETURN DETAIL MODAL -->
<div class="modal-overlay" id="return-detail-modal">
  <div class="modal-box" style="max-width:560px">
    <div class="modal-header"><span class="modal-title" id="return-detail-title">Return Details</span><button class="modal-close" onclick="closeModal('return-detail-modal')">×</button></div>
    <div class="modal-body" id="return-detail-body">
      <p style="text-align:center;color:var(--text3)">Loading…</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('return-detail-modal')">Close</button>
    </div>
  </div>
</div>

<script>
async function viewReturnDetails(returnId) {
  document.getElementById('return-detail-title').textContent = 'Return Details';
  document.getElementById('return-detail-body').innerHTML = '<p style="text-align:center;color:var(--text3)">Loading…</p>';
  openModal('return-detail-modal');

  try {
    const res = await fetch(apiUrl('api/returns.php') + '?id=' + returnId);
    const data = await res.json();
    if (!data.success) { toast(data.error || 'Error', 'error'); closeModal('return-detail-modal'); return; }

    const r = data.return;
    const cur = '<?= $cur ?>';

    let html = '<div style="background:var(--surface2);padding:12px;border-radius:var(--radius);margin-bottom:14px;font-size:13px">';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Return #</span><span class="font-mono font-bold">' + escHtml(r.return_no) + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Bill #</span><span class="font-mono">' + escHtml(r.bill_no || '—') + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Reason</span><span>' + escHtml(r.reason || '—') + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Processed By</span><span>' + escHtml(r.created_by_name || '—') + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between"><span class="text-muted">Date</span><span>' + escHtml(r.created_at || '') + '</span></div>';
    html += '</div>';

    // Bill summary
    const bSub = r.bill_subtotal || 0;
    const bDisc = r.bill_discount || 0;
    const bTax = r.bill_tax || 0;
    const bTotal = r.bill_total || 0;
    if (bSub > 0) {
      html += '<div style="background:var(--surface2);padding:10px;border-radius:var(--radius);margin-bottom:14px;font-size:12px">';
      html += '<div style="font-weight:600;margin-bottom:6px">Original Bill</div>';
      html += '<div style="display:flex;justify-content:space-between;margin-bottom:2px"><span class="text-muted">Subtotal</span><span class="font-mono">' + cur + ' ' + Math.round(bSub) + '</span></div>';
      if (bDisc > 0) html += '<div style="display:flex;justify-content:space-between;margin-bottom:2px"><span class="text-muted">Discount</span><span class="font-mono" style="color:var(--red)">- ' + cur + ' ' + Math.round(bDisc) + '</span></div>';
      if (bTax > 0) html += '<div style="display:flex;justify-content:space-between;margin-bottom:2px"><span class="text-muted">Tax</span><span class="font-mono">+ ' + cur + ' ' + Math.round(bTax) + '</span></div>';
      html += '<div style="display:flex;justify-content:space-between;font-weight:700"><span>Paid</span><span class="font-mono">' + cur + ' ' + Math.round(bTotal) + '</span></div>';
      html += '</div>';
    }

    html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    html += '<thead><tr style="border-bottom:2px solid var(--border)"><th style="text-align:left;padding:6px 4px">Product</th><th style="text-align:center;padding:6px 4px">Qty</th><th style="text-align:right;padding:6px 4px">Subtotal</th><th style="text-align:right;padding:6px 4px">Disc</th><th style="text-align:right;padding:6px 4px">Tax</th><th style="text-align:right;padding:6px 4px">Refund</th></tr></thead><tbody>';

    (r.items || []).forEach(it => {
      const refundAmt = it.refund_amount || (it.subtotal - (it.discount_share||0) + (it.tax_share||0));
      html += '<tr style="border-bottom:1px solid var(--border)">';
      html += '<td style="padding:8px 4px;font-weight:600">' + escHtml(it.product_name) + '</td>';
      html += '<td style="text-align:center;padding:8px 4px">' + it.quantity + '</td>';
      html += '<td style="text-align:right;padding:8px 4px">' + cur + ' ' + Math.round(it.subtotal) + '</td>';
      html += '<td style="text-align:right;padding:8px 4px;color:var(--red)">- ' + cur + ' ' + Math.round(it.discount_share || 0) + '</td>';
      html += '<td style="text-align:right;padding:8px 4px;color:var(--green)">+ ' + cur + ' ' + Math.round(it.tax_share || 0) + '</td>';
      html += '<td style="text-align:right;padding:8px 4px;font-weight:700;color:var(--red)">' + cur + ' ' + Math.round(refundAmt) + '</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    html += '<div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:2px solid var(--border);font-weight:700;font-size:15px">';
    html += '<span>Total Refund</span><span style="color:var(--red)">' + cur + ' ' + Math.round(r.total_refund) + '</span></div>';

    document.getElementById('return-detail-body').innerHTML = html;
  } catch (e) {
    toast('Network error', 'error');
    closeModal('return-detail-modal');
  }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
