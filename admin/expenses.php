<?php
$pageTitle = 'Expenses';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/header.php';
$cur = $settings['currency'] ?? 'Rs';
?>
    <div class="topbar">
      <div>
        <div class="page-title">Expenses</div>
        <div class="page-subtitle">Shop rent, godam rent, and other running costs</div>
      </div>
      <div class="topbar-right">
        <button class="btn btn-primary btn-sm" onclick="openAddExpense()">+ Add Expense</button>
      </div>
    </div>
    <div class="content-area">
      <div class="grid-3 mb-20">
        <div class="stat-card red"><div class="stat-icon">🧾</div><div class="stat-value" id="stat-range-total"><?= $cur ?> 0</div><div class="stat-label">Total (selected range)</div></div>
        <div class="stat-card brand"><div class="stat-icon">📅</div><div class="stat-value" id="stat-month-total"><?= $cur ?> 0</div><div class="stat-label">This Month's Expenses</div></div>
        <div class="stat-card blue"><div class="stat-icon">📋</div><div class="stat-value" id="stat-count">0</div><div class="stat-label">Entries (selected range)</div></div>
      </div>

      <div class="card">
        <form id="filter-form" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px" onsubmit="return false">
          <input class="form-control" style="width:150px" type="date" id="f-date-from">
          <input class="form-control" style="width:150px" type="date" id="f-date-to">
          <select class="form-control" style="width:180px" id="f-category">
            <option value="">All Categories</option>
            <option value="Shop Rent">Shop Rent</option>
            <option value="Godam Rent">Godam / Warehouse Rent</option>
            <option value="Electricity">Electricity</option>
            <option value="Salary">Salary / Wages</option>
            <option value="Maintenance">Maintenance</option>
            <option value="Other">Other</option>
          </select>
          <button class="btn btn-secondary" onclick="loadExpenses()">Filter</button>
        </form>

        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Actions</th></tr></thead>
            <tbody id="exp-tbody"><tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text3)">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

<div class="modal-overlay" id="exp-modal">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-header"><span class="modal-title" id="exp-modal-title">Add Expense</span><button class="modal-close" onclick="closeModal('exp-modal')">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="ef-id">
      <div class="form-group">
        <label class="form-label">Category</label>
        <select class="form-control" id="ef-category">
          <option value="Shop Rent">Shop Rent</option>
          <option value="Godam Rent">Godam / Warehouse Rent</option>
          <option value="Electricity">Electricity</option>
          <option value="Salary">Salary / Wages</option>
          <option value="Maintenance">Maintenance</option>
          <option value="Other">Other</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Description (optional)</label><input class="form-control" id="ef-desc" placeholder="e.g. July shop rent"></div>
      <div class="grid-2">
        <div class="form-group"><label class="form-label">Amount (<?= $cur ?>)</label><input class="form-control" type="number" id="ef-amount" min="1" placeholder="0"></div>
        <div class="form-group"><label class="form-label">Date</label><input class="form-control" type="date" id="ef-date"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('exp-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveExpense()" id="exp-save-btn">Save Expense</button>
    </div>
  </div>
</div>

<script>
const CUR = '<?= $cur ?>';

function firstOfMonth() {
  const d = new Date(); d.setDate(1);
  return d.toISOString().slice(0,10);
}

document.getElementById('f-date-from').value = firstOfMonth();
document.getElementById('f-date-to').value = new Date().toISOString().slice(0,10);

async function loadExpenses() {
  const dateFrom = document.getElementById('f-date-from').value;
  const dateTo = document.getElementById('f-date-to').value;
  const category = document.getElementById('f-category').value;
  const tbody = document.getElementById('exp-tbody');
  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text3)">Loading…</td></tr>';
  try {
    let url = apiUrl('api/expenses.php') + '?date_from=' + dateFrom + '&date_to=' + dateTo;
    if (category) url += '&category=' + encodeURIComponent(category);
    const res = await fetch(url);
    const data = await res.json();
    if (!data.success) { toast(data.error || 'Error', 'error'); return; }

    document.getElementById('stat-range-total').textContent = CUR + ' ' + Math.round(data.total);
    document.getElementById('stat-month-total').textContent = CUR + ' ' + Math.round(data.this_month_total);
    document.getElementById('stat-count').textContent = data.expenses.length;

    if (!data.expenses.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text3)">No expenses recorded for this range</td></tr>';
      return;
    }
    tbody.innerHTML = data.expenses.map(e => `
      <tr>
        <td class="text-sm">${new Date(e.expense_date).toLocaleDateString()}</td>
        <td><span class="badge badge-neutral">${escHtml(e.category)}</span></td>
        <td>${escHtml(e.description || '-')}</td>
        <td class="font-mono font-bold" style="color:var(--red)">${CUR} ${Math.round(e.amount)}</td>
        <td style="display:flex;gap:6px">
          <button class="btn btn-secondary btn-sm" onclick='openEditExpense(${JSON.stringify(e)})'>Edit</button>
          <button class="btn btn-danger btn-sm" onclick="deleteExpense(${e.id})">Delete</button>
        </td>
      </tr>
    `).join('');
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--red)">Network error</td></tr>';
  }
}

function openAddExpense() {
  document.getElementById('exp-modal-title').textContent = 'Add Expense';
  document.getElementById('ef-id').value = '';
  document.getElementById('ef-category').value = 'Shop Rent';
  document.getElementById('ef-desc').value = '';
  document.getElementById('ef-amount').value = '';
  document.getElementById('ef-date').value = new Date().toISOString().slice(0,10);
  openModal('exp-modal');
}

function openEditExpense(e) {
  document.getElementById('exp-modal-title').textContent = 'Edit Expense';
  document.getElementById('ef-id').value = e.id;
  document.getElementById('ef-category').value = e.category;
  document.getElementById('ef-desc').value = e.description || '';
  document.getElementById('ef-amount').value = e.amount;
  document.getElementById('ef-date').value = e.expense_date;
  openModal('exp-modal');
}

async function saveExpense() {
  const id = document.getElementById('ef-id').value;
  const category = document.getElementById('ef-category').value;
  const description = document.getElementById('ef-desc').value.trim();
  const amount = parseFloat(document.getElementById('ef-amount').value);
  const expense_date = document.getElementById('ef-date').value;

  if (!amount || amount <= 0 || !expense_date) { toast('Enter a valid amount and date', 'error'); return; }

  const btn = document.getElementById('exp-save-btn');
  btn.disabled = true;
  try {
    const payload = { category, description, amount, expense_date };
    let res;
    if (id) {
      payload.id = parseInt(id);
      res = await fetch(apiUrl('api/expenses.php'), { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    } else {
      res = await fetch(apiUrl('api/expenses.php'), { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    }
    const data = await res.json();
    if (data.success) {
      toast('Expense saved', 'success');
      closeModal('exp-modal');
      loadExpenses();
    } else {
      toast(data.error || 'Error', 'error');
    }
  } catch (e) { toast('Network error', 'error'); }
  btn.disabled = false;
}

async function deleteExpense(id) {
  if (!confirm('Delete this expense?')) return;
  const res = await fetch(apiUrl('api/expenses.php') + '?id=' + id, { method: 'DELETE' });
  const data = await res.json();
  if (data.success) { toast('Deleted', 'success'); loadExpenses(); }
  else toast(data.error || 'Error', 'error');
}

function escHtml(str) {
  if (!str) return '';
  const d = document.createElement('div'); d.textContent = str; return d.innerHTML;
}

document.addEventListener('DOMContentLoaded', loadExpenses);
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
