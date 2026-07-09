<?php
$pageTitle = 'Bill History';
require_once __DIR__ . '/includes/auth.php';
requireStorePage();
require_once __DIR__ . '/includes/header.php';
$db = getDB();
$storeId = currentStoreId();
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = ['b.store_id = ?'];
$params = [$storeId];
if ($search) {
    $where[] = "(b.bill_no LIKE ? OR b.customer_name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($dateFrom) { $where[] = "DATE(b.created_at) >= ?"; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = "DATE(b.created_at) <= ?"; $params[] = $dateTo; }
$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("SELECT COUNT(*) FROM bills b WHERE $whereStr");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pages = ceil($total / $limit);

$stmt = $db->prepare("SELECT b.*, (SELECT COUNT(*) FROM bill_items WHERE bill_id=b.id) as item_count FROM bills b WHERE $whereStr ORDER BY b.created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$bills = $stmt->fetchAll();

$todaySub = $db->prepare("SELECT COALESCE(SUM(total),0) FROM bills WHERE store_id=? AND DATE(created_at)=DATE('now')");
$todaySub->execute([$storeId]); $todaySub = $todaySub->fetchColumn();
$todayRefund = $db->prepare("SELECT COALESCE(SUM(total_refund),0) FROM returns WHERE store_id=? AND DATE(created_at)=DATE('now')");
$todayRefund->execute([$storeId]); $todaySub = $todaySub - $todayRefund->fetchColumn();
$todayCount = $db->prepare("SELECT COUNT(*) FROM bills WHERE store_id=? AND DATE(created_at)=DATE('now')");
$todayCount->execute([$storeId]); $todayCount = $todayCount->fetchColumn();
$allTime = $db->prepare("SELECT COALESCE(SUM(total),0) FROM bills WHERE store_id=?");
$allTime->execute([$storeId]); $allTime = $allTime->fetchColumn();
$allRefund = $db->prepare("SELECT COALESCE(SUM(total_refund),0) FROM returns WHERE store_id=?");
$allRefund->execute([$storeId]); $allTime = $allTime - $allRefund->fetchColumn();
$cur = $settings['currency'] ?? 'Rs';
?>
    <div class="topbar">
      <div>
        <div class="page-title">Bill History</div>
        <div class="page-subtitle">All saved bills and receipts</div>
      </div>
      <div class="topbar-right">
        <a href="<?= baseUrl('index.php') ?>" class="btn btn-primary btn-sm">+ New Bill</a>
      </div>
    </div>
    <div class="content-area">
      <div class="grid-3 mb-20">
        <div class="stat-card brand"><div class="stat-icon">🧾</div><div class="stat-value"><?= $todayCount ?></div><div class="stat-label">Bills Today</div></div>
        <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-value"><?= $cur ?> <?= number_format($todaySub, 0) ?></div><div class="stat-label">Revenue Today</div></div>
        <div class="stat-card blue"><div class="stat-icon">📈</div><div class="stat-value"><?= $cur ?> <?= number_format($allTime, 0) ?></div><div class="stat-label">All-time Revenue</div></div>
      </div>

      <div class="card">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
          <input class="form-control" style="width:220px" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search bill# or customer…">
          <input class="form-control" style="width:150px" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" title="From date">
          <input class="form-control" style="width:150px" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" title="To date">
          <button class="btn btn-secondary" type="submit">Filter</button>
          <?php if ($search || $dateFrom || $dateTo): ?><a href="<?= baseUrl('bills.php') ?>" class="btn btn-ghost">Clear</a><?php endif; ?>
        </form>

        <div class="table-wrap">
          <table class="data-table">
            <thead><tr>
              <th>Bill #</th><th>Customer</th><th>Table</th><th>Items</th>
              <th>Total</th><th>Payment</th><th>Status</th><th>Date & Time</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if (empty($bills)): ?>
              <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text3)">No bills found</td></tr>
            <?php else: ?>
              <?php foreach ($bills as $b): ?>
              <tr>
                <td><span class="font-mono font-bold"><?= htmlspecialchars($b['bill_no']) ?></span></td>
                <td><?= htmlspecialchars($b['customer_name']) ?></td>
                <td><?= htmlspecialchars($b['table_no']) ?></td>
                <td><span class="badge badge-neutral"><?= $b['item_count'] ?> items</span></td>
                <td class="font-mono font-bold"><?= $cur ?> <?= number_format($b['total'], 0) ?></td>
                <?php $pstatus = $b['payment_status'] ?? 'paid'; $due = (float)($b['due_amount'] ?? 0); ?>
                <td>
                  <?php if ($pstatus === 'paid' || $due <= 0.009): ?>
                  <span class="badge <?= $b['payment_method'] === 'Cash' ? 'badge-success' : 'badge-info' ?>"><?= htmlspecialchars($b['payment_method']) ?></span>
                  <?php else: ?>
                  <span class="badge badge-danger">Credit</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($pstatus === 'paid' || $due <= 0.009): ?>
                  <span class="badge badge-success">Paid</span>
                  <?php else: ?>
                  <span class="badge <?= $pstatus === 'partial' ? 'badge-warning' : 'badge-danger' ?>"><?= $pstatus === 'partial' ? 'Partial' : 'Credit' ?></span>
                  <div class="text-sm" style="color:var(--red);margin-top:2px">Due: <?= $cur ?> <?= number_format($due, 0) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-sm text-muted"><?= date('d M Y, h:i A', strtotime($b['created_at'])) ?></td>
                <td>
                  <button class="btn btn-secondary btn-sm" onclick="showReceipt('<?= htmlspecialchars($b['bill_no']) ?>')">🖨️ View</button>
                  <button class="btn btn-danger btn-sm" onclick="openReturnModal(<?= (int)$b['id'] ?>, '<?= htmlspecialchars($b['bill_no'], ENT_QUOTES) ?>')">↩ Return</button>
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

<!-- RETURN MODAL -->
<div class="modal-overlay" id="return-modal">
  <div class="modal-box" style="max-width:560px">
    <div class="modal-header"><span class="modal-title" id="return-modal-title">Return Items</span><button class="modal-close" onclick="closeModal('return-modal')">×</button></div>
    <div class="modal-body" id="return-modal-body">
      <p style="text-align:center;color:var(--text3)">Loading…</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('return-modal')">Cancel</button>
      <button class="btn btn-danger" id="btn-submit-return" onclick="submitReturn()">Process Return</button>
    </div>
  </div>
</div>

<script>
let returnBillId = null;
let returnBillTotals = { subtotal: 0, tax: 0, discount: 0, total: 0 };

async function openReturnModal(billId, billNo) {
  returnBillId = billId;
  document.getElementById('return-modal-title').textContent = 'Return Items — ' + billNo;
  document.getElementById('return-modal-body').innerHTML = '<p style="text-align:center;color:var(--text3)">Loading…</p>';
  openModal('return-modal');

  try {
    const res = await fetch(apiUrl('api/returns.php') + '?bill_id=' + billId);
    const data = await res.json();
    if (!data.success) { toast(data.error || 'Error', 'error'); closeModal('return-modal'); return; }

    const bill = data.bill;
    const items = data.items;
    const cur = '<?= $cur ?>';

    // Store bill totals for proportional calculation
    returnBillTotals = {
      subtotal: data.bill_subtotal || bill.subtotal || 0,
      tax: data.bill_tax || bill.tax_amount || 0,
      discount: data.bill_discount || bill.discount || 0,
      total: data.bill_total || bill.total || 0
    };

    let html = '<div style="background:var(--surface2);padding:10px;border-radius:var(--radius);margin-bottom:12px;font-size:13px">';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Bill</span><span class="font-mono font-bold">' + escHtml(bill.bill_no || '') + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Subtotal</span><span class="font-mono">' + cur + ' ' + Math.round(returnBillTotals.subtotal) + '</span></div>';
    if (returnBillTotals.discount > 0) html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Discount</span><span class="font-mono" style="color:var(--red)">- ' + cur + ' ' + Math.round(returnBillTotals.discount) + '</span></div>';
    if (returnBillTotals.tax > 0) html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Tax</span><span class="font-mono">+ ' + cur + ' ' + Math.round(returnBillTotals.tax) + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;font-weight:700"><span>Paid</span><span class="font-mono">' + cur + ' ' + Math.round(returnBillTotals.total) + '</span></div>';
    html += '</div>';

    html += '<div class="form-group" style="margin-bottom:12px"><label class="form-label">Reason for return</label><input class="form-control" id="return-reason" placeholder="e.g. Defective, wrong item…"></div>';

    html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    html += '<thead><tr style="border-bottom:2px solid var(--border)"><th style="text-align:left;padding:6px 4px">Product</th><th style="text-align:center;padding:6px 4px">Sold</th><th style="text-align:center;padding:6px 4px">Returned</th><th style="text-align:center;padding:6px 4px">Return Qty</th><th style="text-align:right;padding:6px 4px">Disc</th><th style="text-align:right;padding:6px 4px">Refund</th></tr></thead><tbody>';

    let hasReturnable = false;
    items.forEach(it => {
      const retQty = it.returnable_qty;
      if (retQty > 0) hasReturnable = true;
      html += '<tr style="border-bottom:1px solid var(--border)">';
      html += '<td style="padding:8px 4px">' + escHtml(it.product_name) + '<div class="text-sm text-muted">' + cur + ' ' + Math.round(it.price) + ' each</div></td>';
      html += '<td style="text-align:center;padding:8px 4px">' + it.quantity + '</td>';
      html += '<td style="text-align:center;padding:8px 4px">' + it.returned_qty + '</td>';
      html += '<td style="text-align:center;padding:8px 4px">';
      if (retQty > 0) {
        html += '<input type="number" class="form-control" id="ret-qty-' + it.id + '" data-bill-item-id="' + it.id + '" data-price="' + it.price + '" data-max="' + retQty + '" value="0" min="0" max="' + retQty + '" style="width:70px;text-align:center;padding:4px" onchange="updateReturnTotal()" oninput="updateReturnTotal()">';
      } else {
        html += '<span class="text-muted">—</span>';
      }
      html += '</td>';
      html += '<td style="text-align:right;padding:8px 4px;color:var(--red);font-size:12px" id="ret-disc-' + it.id + '">0</td>';
      html += '<td style="text-align:right;padding:8px 4px;font-weight:600" id="ret-sub-' + it.id + '">0</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    html += '<div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:2px solid var(--border);font-weight:700;font-size:15px">';
    html += '<span>Total Refund</span><span id="return-total-refund">' + cur + ' 0</span></div>';
    if (returnBillTotals.discount > 0) {
      html += '<div style="display:flex;justify-content:space-between;margin-top:6px;font-size:13px;color:var(--text3)">';
      html += '<span>Discount Returned</span><span id="return-total-disc" style="color:var(--red)">' + cur + ' 0</span></div>';
    }

    if (!hasReturnable) {
      html = '<p style="text-align:center;padding:20px;color:var(--text3)">All items from this bill have already been returned.</p>';
      document.getElementById('btn-submit-return').style.display = 'none';
    } else {
      document.getElementById('btn-submit-return').style.display = '';
    }

    document.getElementById('return-modal-body').innerHTML = html;
  } catch (e) {
    toast('Network error', 'error');
    closeModal('return-modal');
  }
}

function updateReturnTotal() {
  const cur = '<?= $cur ?>';
  const bst = returnBillTotals.subtotal;
  const bTax = returnBillTotals.tax;
  const bDisc = returnBillTotals.discount;
  let totalRefund = 0;
  let totalDiscReturned = 0;
  document.querySelectorAll('[id^="ret-qty-"]').forEach(input => {
    const max = parseInt(input.dataset.max);
    let val = parseInt(input.value) || 0;
    if (val > max) { val = max; input.value = val; }
    if (val < 0) { val = 0; input.value = 0; }
    const price = parseFloat(input.dataset.price);
    const itemSub = price * val;
    // Proportional discount and tax
    let discShare = 0, taxShare = 0;
    if (bst > 0 && val > 0) {
      const ratio = itemSub / bst;
      discShare = bDisc * ratio;
      taxShare = bTax * ratio;
    }
    const refund = itemSub + taxShare - discShare;
    const subEl = document.getElementById('ret-sub-' + input.dataset.billItemId);
    if (subEl) subEl.textContent = cur + ' ' + Math.round(refund);
    const discEl = document.getElementById('ret-disc-' + input.dataset.billItemId);
    if (discEl) discEl.textContent = discShare > 0 ? '-' + cur + ' ' + Math.round(discShare) : '0';
    totalRefund += refund;
    totalDiscReturned += discShare;
  });
  document.getElementById('return-total-refund').textContent = cur + ' ' + Math.round(totalRefund);
  const discTotalEl = document.getElementById('return-total-disc');
  if (discTotalEl) discTotalEl.textContent = '-' + cur + ' ' + Math.round(totalDiscReturned);
}

async function submitReturn() {
  const items = [];
  document.querySelectorAll('[id^="ret-qty-"]').forEach(input => {
    const qty = parseInt(input.value) || 0;
    if (qty > 0) {
      items.push({ bill_item_id: parseInt(input.dataset.billItemId), quantity: qty });
    }
  });
  if (items.length === 0) { toast('Select items to return', 'error'); return; }

  const reason = document.getElementById('return-reason')?.value?.trim() || '';
  const btn = document.getElementById('btn-submit-return');
  btn.disabled = true; btn.textContent = 'Processing…';

  try {
    const res = await fetch(apiUrl('api/returns.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ bill_id: returnBillId, items, reason })
    });
    const data = await res.json();
    if (data.success) {
      toast('Return processed — ' + data.return_no + ' (Refund: <?= $cur ?> ' + Math.round(data.total_refund) + ')', 'success');
      closeModal('return-modal');
      setTimeout(() => location.reload(), 800);
    } else {
      toast(data.error || 'Error processing return', 'error');
    }
  } catch (e) {
    toast('Network error', 'error');
  }
  btn.disabled = false; btn.textContent = 'Process Return';
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
