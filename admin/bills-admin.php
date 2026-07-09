<?php
$pageTitle = 'Manage Bills';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requireStorePage();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$storeId = currentStoreId();
$cur = $settings['currency'] ?? 'Rs';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20; $offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');
$where = 'b.store_id = ?'; $params = [$storeId];
if ($search) { $where .= " AND (b.bill_no LIKE ? OR b.customer_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$total = $db->prepare("SELECT COUNT(*) FROM bills b WHERE $where");
$total->execute($params); $total = $total->fetchColumn();
$stmt = $db->prepare("SELECT b.*, (SELECT COUNT(*) FROM bill_items WHERE bill_id=b.id) as ic FROM bills b WHERE $where ORDER BY b.created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params); $bills = $stmt->fetchAll();
$pages = ceil($total / $limit);
?>
    <div class="topbar">
      <div><div class="page-title">Manage Bills</div><div class="page-subtitle">Edit, delete or reprint any bill</div></div>
    </div>
    <div class="content-area">
      <div class="card">
        <form method="GET" style="display:flex;gap:10px;margin-bottom:16px">
          <input class="form-control" style="max-width:260px" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search bill# or customer…">
          <button class="btn btn-secondary" type="submit">Search</button>
          <?php if ($search): ?><a href="?" class="btn btn-ghost">Clear</a><?php endif; ?>
        </form>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Bill #</th><th>Customer</th><th>Table</th><th>Items</th><th>Subtotal</th><th>Tax</th><th>Disc</th><th>Total</th><th>Payment</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($bills as $b): ?>
            <tr>
              <td class="font-mono font-bold"><?= htmlspecialchars($b['bill_no']) ?></td>
              <td><?= htmlspecialchars($b['customer_name']) ?></td>
              <td><?= htmlspecialchars($b['table_no']) ?></td>
              <td class="text-sm"><?= $b['ic'] ?></td>
              <td class="font-mono"><?= number_format($b['subtotal'], 0) ?></td>
              <td class="font-mono"><?= number_format($b['tax_amount'], 0) ?></td>
              <td class="font-mono"><?= number_format($b['discount'], 0) ?></td>
              <td class="font-mono font-bold"><?= $cur ?> <?= number_format($b['total'], 0) ?></td>
              <td><span class="badge <?= $b['payment_method']==='Cash'?'badge-success':'badge-info' ?>"><?= $b['payment_method'] ?></span></td>
              <td class="text-sm text-muted"><?= date('d M Y', strtotime($b['created_at'])) ?></td>
              <td style="display:flex;gap:5px;flex-wrap:wrap">
                <button class="btn btn-secondary btn-sm" onclick='openEditBill(<?= json_encode($b) ?>)'>Edit</button>
                <button class="btn btn-secondary btn-sm" onclick="showReceipt('<?= $b['bill_no'] ?>')">Print</button>
                <button class="btn btn-danger btn-sm" onclick="openReturnModal(<?= (int)$b['id'] ?>, '<?= htmlspecialchars($b['bill_no'], ENT_QUOTES) ?>')">↩ Return</button>
                <button class="btn btn-danger btn-sm" onclick="deleteBill(<?= $b['id'] ?>, '<?= htmlspecialchars($b['bill_no'], ENT_QUOTES) ?>')">Delete</button>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($bills)): ?><tr><td colspan="11" style="text-align:center;padding:24px;color:var(--text3)">No bills found</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($pages > 1): ?>
        <div style="display:flex;justify-content:center;gap:6px;margin-top:16px">
          <?php for ($i=1; $i<=$pages; $i++): ?>
          <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>" class="btn btn-sm <?= $i===$page?'btn-primary':'btn-secondary' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

<!-- EDIT BILL MODAL -->
<div class="modal-overlay" id="edit-bill-modal">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-header"><span class="modal-title" id="edit-bill-title">Edit Bill</span><button class="modal-close" onclick="closeModal('edit-bill-modal')">×</button></div>
    <div class="modal-body" id="edit-bill-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('edit-bill-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveEditBill()">Save Changes</button>
    </div>
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
let editBillId = null;
function openEditBill(b) {
  editBillId = b.id;
  document.getElementById('edit-bill-title').textContent = 'Edit — ' + b.bill_no;
  document.getElementById('edit-bill-body').innerHTML = `
    <div class="grid-2">
      <div class="form-group"><label class="form-label">Customer Name</label><input class="form-control" id="eb-cust" value="${escHtml(b.customer_name)}"></div>
      <div class="form-group"><label class="form-label">Table #</label><input class="form-control" id="eb-table" value="${escHtml(b.table_no)}"></div>
    </div>
    <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control" id="eb-notes" rows="2">${escHtml(b.notes||'')}</textarea></div>
    <div style="background:var(--surface2);padding:12px;border-radius:var(--radius);font-size:13px;margin-top:4px">
      <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Subtotal</span><span class="font-mono">${b.subtotal}</span></div>
      <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Tax</span><span class="font-mono">${b.tax_amount}</span></div>
      <div style="display:flex;justify-content:space-between;font-weight:700"><span>Total</span><span class="font-mono">${b.total}</span></div>
    </div>
  `;
  openModal('edit-bill-modal');
}
async function saveEditBill() {
  const payload = {
    id: editBillId,
    customer_name: document.getElementById('eb-cust').value,
    table_no: document.getElementById('eb-table').value,
    notes: document.getElementById('eb-notes').value,
  };
  const res = await fetch(apiUrl('api/bills.php'), { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  const data = await res.json();
  if (data.success) { toast('Bill updated', 'success'); closeModal('edit-bill-modal'); setTimeout(() => location.reload(), 600); }
  else toast(data.error || 'Error', 'error');
}
async function deleteBill(id, billNo) {
  if (!confirm('Permanently delete bill ' + billNo + '? This cannot be undone.')) return;
  const res = await fetch(apiUrl('api/bills.php') + '?id=' + id, { method: 'DELETE' });
  const data = await res.json();
  if (data.success) { toast('Bill deleted', 'success'); setTimeout(() => location.reload(), 500); }
  else toast(data.error || 'Error', 'error');
}

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
    html += '<thead><tr style="border-bottom:2px solid var(--border)"><th style="text-align:left;padding:6px 4px">Product</th><th style="text-align:center;padding:6px 4px">Sold</th><th style="text-align:center;padding:6px 4px">Returned</th><th style="text-align:center;padding:6px 4px">Return Qty</th><th style="text-align:right;padding:6px 4px">Refund</th></tr></thead><tbody>';

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
      html += '<td style="text-align:right;padding:8px 4px;font-weight:600" id="ret-sub-' + it.id + '">0</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    html += '<div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:2px solid var(--border);font-weight:700;font-size:15px">';
    html += '<span>Total Refund</span><span id="return-total-refund">' + cur + ' 0</span></div>';

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
    totalRefund += refund;
  });
  document.getElementById('return-total-refund').textContent = cur + ' ' + Math.round(totalRefund);
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
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
