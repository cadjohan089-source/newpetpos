<?php
$pageTitle = 'Products';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$sessionId = currentStoreId();

// Super admin can view all or filter by store
$storeId = (int)($_GET['store_id'] ?? ($sessionId ?: 0));
$catFilter = (int)($_GET['category_id'] ?? 0);
$allStores = getAllStores(true);

if ($storeId) {
    $categories = $db->prepare("SELECT * FROM categories WHERE store_id = ? ORDER BY sort_order, name");
    $categories->execute([$storeId]);
    $categories = $categories->fetchAll();

    $prodSql = "SELECT p.*, c.name as cat_name, s.name as store_name FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN stores s ON p.store_id = s.id WHERE p.store_id = ?";
    $prodParams = [$storeId];
    if ($catFilter) {
        $prodSql .= " AND p.category_id = ?";
        $prodParams[] = $catFilter;
    }
    $prodSql .= " ORDER BY c.sort_order, p.name";
    $products = $db->prepare($prodSql);
    $products->execute($prodParams);
    $products = $products->fetchAll();
} else {
    $categories = $db->query("SELECT c.*, s.name as store_name FROM categories c LEFT JOIN stores s ON c.store_id = s.id ORDER BY s.name, c.sort_order, c.name")->fetchAll();

    $prodSql = "SELECT p.*, c.name as cat_name, s.name as store_name FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN stores s ON p.store_id = s.id WHERE 1=1";
    $prodParams = [];
    if ($catFilter) {
        $prodSql .= " AND p.category_id = ?";
        $prodParams[] = $catFilter;
    }
    $prodSql .= " ORDER BY s.name, c.sort_order, p.name";
    $products = $db->prepare($prodSql);
    $products->execute($prodParams);
    $products = $products->fetchAll();
}
$cur = $settings['currency'] ?? 'Rs';
?>
    <div class="topbar">
      <div><div class="page-title">Products</div><div class="page-subtitle">Super Admin — Manage all products & stock</div></div>
      <div class="topbar-right" style="display:flex;gap:8px;align-items:center">
        <select class="form-control" id="store-filter" style="font-size:13px;padding:7px 10px" onchange="updateCatFilter()">
          <option value="0">All Stores</option>
          <?php foreach ($allStores as $st): ?>
          <option value="<?= $st['id'] ?>" <?= $storeId==$st['id']?'selected':'' ?>><?= htmlspecialchars($st['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-control" id="cat-filter" style="font-size:13px;padding:7px 10px" onchange="applyFilters()">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $catFilter==$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" onclick="openAddModal()">+ Add Product</button>
      </div>
    </div>
    <div class="content-area">
      <div class="card">
        <div class="flex-between mb-16">
          <input class="form-control" style="max-width:260px" id="prod-search" placeholder="Search products..." oninput="filterTable(this.value)">
          <span class="text-sm text-muted"><?= count($products) ?> products</span>
        </div>
        <div class="table-wrap">
          <table class="data-table" id="prod-table">
            <thead><tr><th>#</th><?php if(!$storeId):?><th>Store</th><?php endif;?><th>Name</th><th>Barcode</th><th>Category</th><th>Buy Price</th><th>Sell Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
            <tr data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>">
              <td class="text-muted text-sm font-mono"><?= $p['id'] ?></td>
              <?php if(!$storeId):?><td><?= htmlspecialchars($p['store_name'] ?? '') ?></td><?php endif; ?>
              <td class="font-bold"><?= htmlspecialchars($p['name']) ?></td>
              <td class="font-mono text-sm text-muted"><?= htmlspecialchars($p['barcode'] ?: '-') ?></td>
              <td><span class="badge badge-neutral"><?= htmlspecialchars($p['cat_name']) ?></span></td>
              <td class="font-mono"><?= $cur ?> <?= number_format($p['buy_price'], 0) ?></td>
              <td class="font-mono font-bold"><?= $cur ?> <?= number_format($p['price'], 0) ?></td>
              <td><span class="badge <?= $p['stock_qty'] > 0 ? 'badge-success' : 'badge-danger' ?>"><?= (int)$p['stock_qty'] ?></span></td>
              <td><span class="badge <?= $p['available'] ? 'badge-success' : 'badge-danger' ?>"><?= $p['available'] ? 'Active' : 'Off' ?></span></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($p) ?>)'>Edit</button>
                <button class="btn btn-secondary btn-sm" onclick='openStockModal(<?= json_encode($p) ?>)'>+ Stock</button>
                <button class="btn btn-secondary btn-sm" onclick="toggleProduct(<?= $p['id'] ?>, <?= $p['available'] ?>)"><?= $p['available'] ? 'Disable' : 'Enable' ?></button>
                <button class="btn btn-danger btn-sm" onclick="deleteProduct(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">Delete</button>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($products)): ?><tr><td colspan="<?= $storeId ? 9 : 10 ?>" style="text-align:center;padding:20px;color:var(--text3)">No products found</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

<div class="modal-overlay" id="prod-modal">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="prod-modal-title">Add Product</span>
      <button class="modal-close" onclick="closeModal('prod-modal')">×</button>
    </div>
    <div class="modal-body">
      <div id="modal-error" class="alert alert-danger" style="display:none;margin-bottom:15px;"></div>
      <input type="hidden" id="pf-id">
      <input type="hidden" id="pf-original-name">
      <div class="form-group"><label class="form-label">Assign to Stores *</label>
        <?php if ($storeId): ?>
        <div style="display:flex;align-items:center;gap:4px;font-size:13px;padding:6px 0">
          <input type="checkbox" class="pf-store-check" value="<?= $storeId ?>" checked disabled>
          <span><?= htmlspecialchars(($allStores[array_search($storeId, array_column($allStores, 'id'))] ?? ['name'=>''])['name']) ?></span>
          <input type="hidden" class="pf-store-hidden" value="<?= $storeId ?>">
        </div>
        <?php else: ?>
        <div id="pf-store-checkboxes" style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($allStores as $st): ?>
        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer">
          <input type="checkbox" class="pf-store-check" value="<?= $st['id'] ?>" checked> <?= htmlspecialchars($st['name']) ?>
        </label>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="form-group"><label class="form-label">Product Name *</label><input class="form-control" id="pf-name" placeholder="e.g. Chicken Burger"></div>
      <div class="form-group"><label class="form-label">Barcode (optional — scan or type)</label><input class="form-control" id="pf-barcode" placeholder="Scan barcode or leave blank"></div>
      <div class="grid-2">
        <div class="form-group"><label class="form-label">Buy Price (<?= $cur ?>) *</label><input class="form-control" type="number" id="pf-buy-price" placeholder="0" min="0" step="1"></div>
        <div class="form-group"><label class="form-label">Sell Price (<?= $cur ?>) *</label><input class="form-control" type="number" id="pf-price" placeholder="0" min="0" step="1"></div>
      </div>
      <div class="grid-2">
        <div class="form-group"><label class="form-label">Stock Quantity *</label><input class="form-control" type="number" id="pf-stock" placeholder="0" min="0" step="1" value="0"></div>
        <div class="form-group"><label class="form-label">Category *</label>
          <select class="form-control" id="pf-cat"></select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('prod-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveProduct()" id="save-btn">Save Product</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="stock-modal">
  <div class="modal-box" style="max-width:400px">
    <div class="modal-header"><span class="modal-title" id="stock-modal-title">Add Stock</span><button class="modal-close" onclick="closeModal('stock-modal')">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="st-product-id">
      <input type="hidden" id="st-store-id">
      <div class="form-group"><label class="form-label">Date</label><input class="form-control" type="date" id="st-date" value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group"><label class="form-label">Quantity to Add</label><input class="form-control" type="number" id="st-qty" min="1" step="1" placeholder="e.g. 50"></div>
      <div class="form-group"><label class="form-label">Notes (optional)</label><input class="form-control" id="st-notes" placeholder="Supplier, batch, etc."></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('stock-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveStock()">Add Stock</button>
    </div>
  </div>
</div>

<script>
const baseUrl = '<?= baseUrl('admin/products.php') ?>';

function applyFilters() {
  const storeId = document.getElementById('store-filter').value;
  const catId = document.getElementById('cat-filter').value;
  let url = baseUrl + '?store_id=' + storeId;
  if (catId && catId !== '0') url += '&category_id=' + catId;
  location.href = url;
}

function updateCatFilter() {
  const storeId = document.getElementById('store-filter').value;
  // Reset category filter when store changes
  location.href = baseUrl + '?store_id=' + storeId;
}

// All categories across all stores (for multi-store product creation)
const allStoreCats = <?= json_encode($db->query("SELECT c.*, s.name as store_name FROM categories c LEFT JOIN stores s ON c.store_id = s.id ORDER BY s.name, c.sort_order, c.name")->fetchAll()) ?>;
const allStores = <?= json_encode(array_map(function($s) { return ['id'=>(int)$s['id'],'name'=>$s['name']]; }, $allStores)) ?>;
const currentStoreFilter = <?= $storeId ?: 0 ?>;

// Build a map: product name -> array of {id, store_id} for sibling lookup
const productNameStoreMap = {};
<?php foreach ($products as $p): ?>
(function() {
  var key = <?= json_encode($p['name']) ?>;
  if (!productNameStoreMap[key]) productNameStoreMap[key] = [];
  productNameStoreMap[key].push({ id: <?= (int)$p['id'] ?>, store_id: <?= (int)$p['store_id'] ?> });
})();
<?php endforeach; ?>

function filterTable(q) {
  document.querySelectorAll('#prod-table tbody tr').forEach(tr => {
    tr.style.display = !q || tr.dataset.name.includes(q.toLowerCase()) ? '' : 'none';
  });
}

function getSelectedStoreIds() {
  if (currentStoreFilter) {
    const hidden = document.querySelector('.pf-store-hidden');
    return hidden ? [parseInt(hidden.value)] : [currentStoreFilter];
  }
  return Array.from(document.querySelectorAll('.pf-store-check:checked')).map(cb => parseInt(cb.value));
}

function loadCategoriesForStores() {
  const catSelect = document.getElementById('pf-cat');
  catSelect.innerHTML = '';
  const storeIds = getSelectedStoreIds();
  if (!storeIds.length) {
    catSelect.innerHTML = '<option value="">— Select stores first —</option>';
    return;
  }
  allStores.forEach(s => {
    if (!storeIds.includes(s.id)) return;
    const group = document.createElement('optgroup');
    group.label = s.name;
    allStoreCats.filter(c => c.store_id == s.id).forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name;
      opt.dataset.storeId = c.store_id;
      group.appendChild(opt);
    });
    if (group.children.length) catSelect.appendChild(group);
  });
  if (!catSelect.children.length) {
    catSelect.innerHTML = '<option value="">— No categories in selected stores —</option>';
  }
}

// Re-load categories when store checkboxes change
document.addEventListener('change', function(e) {
  if (e.target.classList.contains('pf-store-check')) {
    loadCategoriesForStores();
  }
});

function openAddModal() {
  document.getElementById('prod-modal-title').textContent = 'Add Product';
  document.getElementById('pf-id').value = '';
  document.getElementById('pf-original-name').value = '';
  document.getElementById('pf-name').value = '';
  document.getElementById('pf-barcode').value = '';
  document.getElementById('pf-buy-price').value = '';
  document.getElementById('pf-price').value = '';
  document.getElementById('pf-stock').value = '0';
  if (!currentStoreFilter) {
    document.querySelectorAll('.pf-store-check').forEach(cb => {
      cb.checked = true;
      cb.disabled = false;
    });
  }
  loadCategoriesForStores();
  document.getElementById('modal-error').style.display = 'none';
  openModal('prod-modal');
}

function openEditModal(p) {
  document.getElementById('prod-modal-title').textContent = 'Edit Product';
  document.getElementById('pf-id').value = p.id;
  document.getElementById('pf-original-name').value = p.name;
  document.getElementById('pf-name').value = p.name;
  document.getElementById('pf-barcode').value = p.barcode || '';
  document.getElementById('pf-buy-price').value = p.buy_price;
  document.getElementById('pf-price').value = p.price;
  document.getElementById('pf-stock').value = p.stock_qty;
  // Edit: pre-check ALL stores that have this product (by name match)
  if (!currentStoreFilter) {
    const siblings = productNameStoreMap[p.name] || [];
    const siblingStoreIds = siblings.map(s => s.store_id);
    document.querySelectorAll('.pf-store-check').forEach(cb => {
      cb.checked = siblingStoreIds.includes(parseInt(cb.value));
      cb.disabled = false;
    });
  }
  loadCategoriesForStores();
  setTimeout(() => { document.getElementById('pf-cat').value = p.category_id; }, 50);
  document.getElementById('modal-error').style.display = 'none';
  openModal('prod-modal');
}

function openStockModal(p) {
  document.getElementById('stock-modal-title').textContent = 'Add Stock — ' + p.name;
  document.getElementById('st-product-id').value = p.id;
  document.getElementById('st-store-id').value = p.store_id;
  document.getElementById('st-qty').value = '';
  document.getElementById('st-notes').value = '';
  document.getElementById('st-date').value = new Date().toISOString().slice(0,10);
  openModal('stock-modal');
}

async function saveProduct() {
  const id = document.getElementById('pf-id').value;
  const name = document.getElementById('pf-name').value.trim();
  const barcode = document.getElementById('pf-barcode').value.trim();
  const buy_price = parseFloat(document.getElementById('pf-buy-price').value);
  const price = parseFloat(document.getElementById('pf-price').value);
  const stock_qty = parseInt(document.getElementById('pf-stock').value) || 0;
  const category_id = parseInt(document.getElementById('pf-cat').value);
  const store_ids = getSelectedStoreIds();
  const saveBtn = document.getElementById('save-btn');

  if (!name || isNaN(price) || price < 0 || isNaN(buy_price) || !store_ids.length || !category_id) {
    showModalError('Please fill all fields correctly');
    return;
  }

  saveBtn.disabled = true;

  if (id) {
    // EDIT: update product in all selected stores (update existing + create new)
    const originalName = document.getElementById('pf-original-name').value;
    const siblings = productNameStoreMap[originalName] || [];
    const catEl = document.getElementById('pf-cat');
    const selectedCatOption = catEl.options[catEl.selectedIndex];
    const catName = selectedCatOption ? selectedCatOption.textContent : '';
    const catStoreId = selectedCatOption ? parseInt(selectedCatOption.dataset.storeId) : 0;

    let updated = 0, created = 0, errors = 0;
    for (const sid of store_ids) {
      // Find matching category for this store
      let finalCatId = category_id;
      if (sid !== catStoreId) {
        let matchCat = allStoreCats.find(c => c.store_id == sid && c.name === catName);
        if (!matchCat) {
          try {
            const catRes = await fetch(apiUrl('api/products.php'), {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify({ action: 'create_category', store_id: sid, name: catName })
            });
            const catData = await catRes.json();
            if (catData.success && catData.id) {
              matchCat = { id: catData.id, store_id: sid, name: catName };
              allStoreCats.push(matchCat);
            }
          } catch (e) {}
        }
        if (matchCat) finalCatId = matchCat.id;
      }
      // Find existing sibling product in this store
      const existingSibling = siblings.find(s => s.store_id === sid);
      if (existingSibling) {
        // UPDATE existing product in this store
        const payload = { id: existingSibling.id, name, barcode, price, buy_price, stock_qty, category_id: finalCatId, store_id: sid };
        try {
          const res = await fetch(apiUrl('api/products.php'), { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
          const data = await res.json();
          if (data.success) updated++; else errors++;
        } catch (e) { errors++; }
      } else {
        // CREATE new product in this store
        const payload = { name, barcode, price, buy_price, stock_qty, category_id: finalCatId, store_id: sid };
        try {
          const res = await fetch(apiUrl('api/products.php'), { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
          const data = await res.json();
          if (data.success) created++; else errors++;
        } catch (e) { errors++; }
      }
    }
    let msg = [];
    if (updated > 0) msg.push('updated in ' + updated + ' store(s)');
    if (created > 0) msg.push('added to ' + created + ' new store(s)');
    if (msg.length) toast('Product ' + msg.join(', '), 'success');
    if (errors > 0) showModalError(msg.length ? errors + ' store(s) had errors' : 'Failed to update product');
    if (updated > 0 || created > 0) setTimeout(() => location.reload(), 500);
    else saveBtn.disabled = false;
  } else {
    // ADD: create product in each selected store (auto-create category if missing)
    const catEl = document.getElementById('pf-cat');
    const selectedCatOption = catEl.options[catEl.selectedIndex];
    const catName = selectedCatOption ? selectedCatOption.textContent : '';
    const catStoreId = selectedCatOption ? parseInt(selectedCatOption.dataset.storeId) : 0;

    let created = 0, errors = 0;
    for (const sid of store_ids) {
      // Find matching category in this store by name
      let finalCatId = category_id;
      if (sid !== catStoreId) {
        let matchCat = allStoreCats.find(c => c.store_id == sid && c.name === catName);
        if (!matchCat) {
          // Auto-create the category in the target store
          try {
            const catRes = await fetch(apiUrl('api/products.php'), {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify({ action: 'create_category', store_id: sid, name: catName })
            });
            const catData = await catRes.json();
            if (catData.success && catData.id) {
              matchCat = { id: catData.id, store_id: sid, name: catName };
              allStoreCats.push(matchCat);
            }
          } catch (e) {}
        }
        if (matchCat) finalCatId = matchCat.id;
        else { errors++; continue; }
      }
      const payload = { name, barcode, price, buy_price, stock_qty, category_id: finalCatId, store_id: sid };
      try {
        const res = await fetch(apiUrl('api/products.php'), { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
        const data = await res.json();
        if (data.success) created++; else errors++;
      } catch (e) { errors++; }
    }
    if (created > 0) toast('Product added to ' + created + ' store(s)', 'success');
    if (errors > 0) showModalError(created > 0 ? errors + ' store(s) skipped' : 'Failed to add product');
    if (created > 0) setTimeout(() => location.reload(), 500);
    else saveBtn.disabled = false;
  }
}

async function saveStock() {
  const product_id = parseInt(document.getElementById('st-product-id').value);
  const store_id = parseInt(document.getElementById('st-store-id').value);
  const quantity = parseInt(document.getElementById('st-qty').value);
  const log_date = document.getElementById('st-date').value;
  const notes = document.getElementById('st-notes').value;
  if (!quantity || quantity <= 0) { toast('Enter a valid quantity', 'error'); return; }
  const res = await fetch(apiUrl('api/stock.php'), { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ product_id, quantity, log_date, notes, store_id }) });
  const data = await res.json();
  if (data.success) { toast('Stock added', 'success'); setTimeout(() => location.reload(), 400); }
  else toast(data.error || 'Error', 'error');
}

function showModalError(message) {
  const errorDiv = document.getElementById('modal-error');
  errorDiv.innerHTML = message;
  errorDiv.style.display = 'block';
}

async function toggleProduct(id, current) {
  const res = await fetch(apiUrl('api/products.php'), { method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id, available: current ? 0 : 1 }) });
  const data = await res.json();
  if (data.success) { toast('Updated', 'success'); setTimeout(() => location.reload(), 400); }
  else toast(data.error || 'Error', 'error');
}

async function deleteProduct(id, name) {
  if (!confirm('Delete "' + name + '"?')) return;
  const res = await fetch(apiUrl('api/products.php') + '?id=' + id, { method: 'DELETE' });
  const data = await res.json();
  if (data.success) { toast('Deleted', 'success'); setTimeout(() => location.reload(), 400); }
  else toast(data.error || 'Error', 'error');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>