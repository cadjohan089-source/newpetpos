<?php
$pageTitle = 'Customers & Credit';
require_once __DIR__ . '/includes/auth.php';
requireStorePage();
require_once __DIR__ . '/includes/header.php';
$cur = $settings['currency'] ?? 'Rs';
$sort = ($_GET['sort'] ?? 'due') === 'name' ? 'name' : 'due';
?>
    <div class="topbar">
      <div>
        <div class="page-title">Customers & Credit</div>
        <div class="page-subtitle">Track khata / udhaar — who owes what</div>
      </div>
      <div class="topbar-right">
        <a href="<?= baseUrl('index.php') ?>" class="btn btn-primary btn-sm">+ New Bill</a>
      </div>
    </div>
    <div class="content-area">
      <div class="grid-3 mb-20">
        <div class="stat-card red"><div class="stat-icon">💳</div><div class="stat-value" id="stat-total-credit"><?= $cur ?> 0</div><div class="stat-label">Total Outstanding Credit</div></div>
        <div class="stat-card brand"><div class="stat-icon">👥</div><div class="stat-value" id="stat-customer-count">0</div><div class="stat-label">Customers with Credit</div></div>
        <div class="stat-card blue"><div class="stat-icon">📇</div><div class="stat-value" id="stat-all-customers">0</div><div class="stat-label">Total Customers</div></div>
      </div>

      <div class="card">
        <div class="flex-between mb-16" style="flex-wrap:wrap;gap:10px">
          <input class="form-control" style="max-width:260px" id="cust-search" placeholder="Search customer name or phone…" oninput="renderCustomers()">
          <div style="display:flex;gap:6px">
            <button class="btn btn-sm <?= $sort==='due'?'btn-primary':'btn-secondary' ?>" onclick="setSort('due')">Sort by Credit Due</button>
            <button class="btn btn-sm <?= $sort==='name'?'btn-primary':'btn-secondary' ?>" onclick="setSort('name')">Sort by Name (A–Z)</button>
          </div>
        </div>

        <div class="flex-between mb-16" style="flex-wrap:wrap;gap:10px;background:var(--surface2);padding:10px;border-radius:var(--radius)">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <label class="text-sm text-muted" style="font-weight:600">Date range:</label>
            <input class="form-control" style="width:150px" type="date" id="date-from" title="Start date">
            <span class="text-muted">to</span>
            <input class="form-control" style="width:150px" type="date" id="date-to" title="End date">
            <button class="btn btn-secondary btn-sm" onclick="applyDateFilter()">Filter</button>
            <button class="btn btn-ghost btn-sm" onclick="quickThisMonth()">This Month</button>
            <button class="btn btn-ghost btn-sm" id="btn-clear-date" style="display:none" onclick="clearDateFilter()">Clear</button>
          </div>
          <div class="text-sm text-muted" id="date-filter-status"></div>
        </div>

        <div class="table-wrap">
          <table class="data-table">
            <thead><tr>
              <th>Customer</th><th>Phone</th><th id="th-billed">Total Billed</th><th id="th-paid">Total Paid</th><th>Credit Due</th><th id="th-last">Last Purchase</th><th>Actions</th>
            </tr></thead>
            <tbody id="cust-tbody">
              <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

<!-- RECORD PAYMENT MODAL -->
<div class="modal-overlay" id="pay-modal">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-header"><span class="modal-title" id="pay-modal-title">Record Payment</span><button class="modal-close" onclick="closeModal('pay-modal')">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="pay-customer-id">
      <div style="background:var(--surface2);padding:10px;border-radius:var(--radius);margin-bottom:14px;font-size:13px">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Total Billed</span><span class="font-mono" id="pay-billed">-</span></div>
        <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span class="text-muted">Already Paid</span><span class="font-mono" id="pay-paid">-</span></div>
        <div style="display:flex;justify-content:space-between;font-weight:700;color:var(--red)"><span>Currently Due</span><span class="font-mono" id="pay-due">-</span></div>
      </div>
      <div class="form-group"><label class="form-label">Amount Receiving Now (<?= $cur ?>)</label><input class="form-control" type="number" id="pay-amount" min="1" placeholder="e.g. 100"></div>
      <div class="form-group"><label class="form-label">Note (optional)</label><input class="form-control" id="pay-note" placeholder="e.g. Paid in cash"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('pay-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitPayment()" id="btn-submit-payment">Save Payment</button>
    </div>
  </div>
</div>

<!-- CUSTOMER HISTORY MODAL -->
<div class="modal-overlay" id="history-modal">
  <div class="modal-box" style="max-width:560px">
    <div class="modal-header"><span class="modal-title" id="history-modal-title">Customer History</span><button class="modal-close" onclick="closeModal('history-modal')">×</button></div>
    <div class="modal-body" id="history-modal-body"><p style="text-align:center;color:var(--text3)">Loading…</p></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('history-modal')">Close</button>
    </div>
  </div>
</div>

<script>
const CUR = '<?= $cur ?>';
let allCustomers = [];
let currentSort = '<?= $sort ?>';
let dateFrom = '';
let dateTo = '';

function setSort(s) {
  currentSort = s;
  const url = new URL(window.location);
  url.searchParams.set('sort', s);
  history.replaceState(null, '', url);
  loadCustomers();
}

function applyDateFilter() {
  dateFrom = document.getElementById('date-from').value || '';
  dateTo = document.getElementById('date-to').value || '';
  if (dateFrom && dateTo && dateFrom > dateTo) {
    toast('Start date must be before end date', 'error');
    return;
  }
  loadCustomers();
}

function quickThisMonth() {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), 1);
  const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
  const fmt = d => d.toISOString().slice(0, 10);
  document.getElementById('date-from').value = fmt(start);
  document.getElementById('date-to').value = fmt(end);
  applyDateFilter();
}

function clearDateFilter() {
  dateFrom = ''; dateTo = '';
  document.getElementById('date-from').value = '';
  document.getElementById('date-to').value = '';
  loadCustomers();
}

async function loadCustomers() {
  document.getElementById('cust-tbody').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">Loading…</td></tr>';
  try {
    let url = apiUrl('api/customers.php') + '?sort=' + currentSort;
    if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
    const res = await fetch(url);
    const data = await res.json();
    if (!data.success) { toast(data.error || 'Error loading customers', 'error'); return; }
    allCustomers = data.customers || [];
    document.getElementById('stat-total-credit').textContent = CUR + ' ' + Math.round(data.total_credit || 0);
    document.getElementById('stat-customer-count').textContent = allCustomers.filter(c => c.due > 0).length;
    document.getElementById('stat-all-customers').textContent = allCustomers.length;

    const isFiltered = !!(dateFrom || dateTo);
    document.getElementById('btn-clear-date').style.display = isFiltered ? '' : 'none';
    const rangeLabel = dateFrom && dateTo ? (dateFrom + ' → ' + dateTo)
      : dateFrom ? ('from ' + dateFrom)
      : dateTo ? ('up to ' + dateTo) : '';
    document.getElementById('date-filter-status').textContent = isFiltered
      ? ('Showing activity ' + rangeLabel + ' — customers with no activity in this range are hidden')
      : '';
    document.getElementById('th-billed').textContent = isFiltered ? 'Billed (period)' : 'Total Billed';
    document.getElementById('th-paid').textContent = isFiltered ? 'Paid (period)' : 'Total Paid';
    document.getElementById('th-last').textContent = isFiltered ? 'Last Purchase (period)' : 'Last Purchase';

    renderCustomers();
  } catch (e) {
    document.getElementById('cust-tbody').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--red)">Network error</td></tr>';
  }
}

function renderCustomers() {
  const q = (document.getElementById('cust-search').value || '').toLowerCase();
  const list = allCustomers.filter(c => !q || c.name.toLowerCase().includes(q) || (c.phone || '').includes(q));
  const tbody = document.getElementById('cust-tbody');
  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No customers found</td></tr>';
    return;
  }
  tbody.innerHTML = list.map(c => `
    <tr>
      <td class="font-bold">${escHtml(c.name)}</td>
      <td class="text-muted">${escHtml(c.phone || '-')}</td>
      <td class="font-mono">${CUR} ${Math.round(c.billed)}</td>
      <td class="font-mono">${CUR} ${Math.round(c.paid)}</td>
      <td class="font-mono font-bold" style="color:${c.due > 0 ? 'var(--red)' : 'var(--green)'}">${CUR} ${Math.round(c.due)}</td>
      <td class="text-sm text-muted">${c.last_bill_at ? new Date(c.last_bill_at).toLocaleDateString() : '-'}</td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn btn-secondary btn-sm" onclick="viewHistory(${c.id})">📜 History</button>
        ${c.due > 0 ? `<button class="btn btn-primary btn-sm" onclick="openPayModal(${c.id}, '${escJs(c.name)}', ${c.billed}, ${c.paid}, ${c.due})">💰 Record Payment</button>` : ''}
      </td>
    </tr>
  `).join('');
}

function escJs(s) { return String(s || '').replace(/'/g, "\\'"); }

function openPayModal(id, name, billed, paid, due) {
  document.getElementById('pay-modal-title').textContent = 'Record Payment — ' + name;
  document.getElementById('pay-customer-id').value = id;
  document.getElementById('pay-billed').textContent = CUR + ' ' + Math.round(billed);
  document.getElementById('pay-paid').textContent = CUR + ' ' + Math.round(paid);
  document.getElementById('pay-due').textContent = CUR + ' ' + Math.round(due);
  document.getElementById('pay-amount').value = '';
  document.getElementById('pay-amount').max = due;
  document.getElementById('pay-note').value = '';
  openModal('pay-modal');
}

async function submitPayment() {
  const customer_id = parseInt(document.getElementById('pay-customer-id').value);
  const amount = parseFloat(document.getElementById('pay-amount').value);
  const note = document.getElementById('pay-note').value.trim();
  if (!amount || amount <= 0) { toast('Enter a valid amount', 'error'); return; }
  const btn = document.getElementById('btn-submit-payment');
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    const res = await fetch(apiUrl('api/customers.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ customer_id, amount, note })
    });
    const data = await res.json();
    if (data.success) {
      toast('Payment recorded', 'success');
      closeModal('pay-modal');
      loadCustomers();
    } else {
      toast(data.error || 'Error', 'error');
    }
  } catch (e) { toast('Network error', 'error'); }
  btn.disabled = false; btn.textContent = 'Save Payment';
}

async function viewHistory(id) {
  document.getElementById('history-modal-body').innerHTML = '<p style="text-align:center;color:var(--text3)">Loading…</p>';
  openModal('history-modal');
  try {
    const res = await fetch(apiUrl('api/customers.php') + '?id=' + id);
    const data = await res.json();
    if (!data.success) { toast(data.error || 'Error', 'error'); closeModal('history-modal'); return; }
    const c = data.customer, bills = data.bills, payments = data.payments, bal = data.balance;
    document.getElementById('history-modal-title').textContent = c.name + (c.phone ? ' — ' + c.phone : '');

    let html = '<div style="background:var(--surface2);padding:10px;border-radius:var(--radius);margin-bottom:14px;font-size:13px">';
    html += '<div style="display:flex;justify-content:space-between"><span class="text-muted">Total Billed</span><span class="font-mono">' + CUR + ' ' + Math.round(bal.billed) + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between"><span class="text-muted">Total Paid</span><span class="font-mono">' + CUR + ' ' + Math.round(bal.paid) + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;font-weight:700;color:var(--red)"><span>Due Now</span><span class="font-mono">' + CUR + ' ' + Math.round(bal.due) + '</span></div>';
    html += '</div>';

    html += '<div style="font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;margin-bottom:6px">Bills</div>';
    if (bills.length) {
      html += '<table style="width:100%;font-size:13px;margin-bottom:14px;border-collapse:collapse">';
      bills.forEach(b => {
        const badge = b.payment_status === 'paid' ? 'var(--green)' : (b.payment_status === 'partial' ? 'var(--amber)' : 'var(--red)');
        html += '<tr style="border-bottom:1px solid var(--border)">' +
          '<td style="padding:6px 2px"><a href="javascript:void(0)" onclick="showReceipt(\'' + escJs(b.bill_no) + '\')" style="color:var(--brand);font-weight:700;text-decoration:underline;cursor:pointer">' + escHtml(b.bill_no) + '</a><div class="text-sm text-muted">' + new Date(b.created_at).toLocaleDateString() + '</div></td>' +
          '<td style="padding:6px 2px;text-align:right">' + CUR + ' ' + Math.round(b.total) + '</td>' +
          '<td style="padding:6px 2px;text-align:right;color:' + badge + ';font-weight:600;text-transform:capitalize">' + b.payment_status + '</td>' +
          '<td style="padding:6px 2px;text-align:right;color:var(--red)">' + (b.due_amount > 0 ? CUR + ' ' + Math.round(b.due_amount) : '-') + '</td>' +
          '</tr>';
      });
      html += '</table>';
    } else {
      html += '<p class="text-sm text-muted" style="margin-bottom:14px">No bills yet.</p>';
    }

    html += '<div style="font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;margin-bottom:6px">Payments Received</div>';
    if (payments.length) {
      html += '<table style="width:100%;font-size:13px;border-collapse:collapse">';
      payments.forEach(p => {
        html += '<tr style="border-bottom:1px solid var(--border)">' +
          '<td style="padding:6px 2px">' + new Date(p.created_at).toLocaleString() + (p.note ? '<div class="text-sm text-muted">' + escHtml(p.note) + '</div>' : '') + '</td>' +
          '<td style="padding:6px 2px;text-align:right;color:var(--green);font-weight:600">+' + CUR + ' ' + Math.round(p.amount) + '</td>' +
          '</tr>';
      });
      html += '</table>';
    } else {
      html += '<p class="text-sm text-muted">No standalone payments recorded yet.</p>';
    }

    document.getElementById('history-modal-body').innerHTML = html;
  } catch (e) {
    toast('Network error', 'error');
    closeModal('history-modal');
  }
}

function escHtml(str) {
  if (!str) return '';
  const d = document.createElement('div'); d.textContent = str; return d.innerHTML;
}

document.addEventListener('DOMContentLoaded', loadCustomers);
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
