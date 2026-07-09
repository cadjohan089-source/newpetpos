<?php
$pageTitle = 'Categories';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$sessionId = currentStoreId();

// Super admin can view all or filter by store
$storeId = (int)($_GET['store_id'] ?? ($sessionId ?: 0));
$allStores = getAllStores(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Always redirect back to the current VIEW filter (not the entity's own store)
    $redirectStoreId = $storeId; // preserve the user's current view filter

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $storeIds = $_POST['store_ids'] ?? []; // multi-store support
        if (!is_array($storeIds)) $storeIds = [$storeIds];
        if (empty($storeIds)) $storeIds = $storeId ? [$storeId] : array_map(function($s) { return (int)$s['id']; }, $allStores);
        if ($name) {
            $count = 0;
            foreach ($storeIds as $sid) {
                $sid = (int)$sid;
                if ($sid) {
                    $check = $db->prepare("SELECT id FROM categories WHERE store_id = ? AND LOWER(name) = LOWER(?)");
                    $check->execute([$sid, $name]);
                    if ($check->fetch()) {
                        $count++;
                    } else {
                        try {
                            $db->prepare("INSERT INTO categories (store_id, name) VALUES (?,?)")->execute([$sid, $name]);
                            $count++;
                        } catch(\Exception $e) {}
                    }
                }
            }
            if ($count > 0) {
                $_SESSION['msg'] = "Category available in {$count} store(s)";
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $count = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id=?");
        $count->execute([$id]); $cnt = $count->fetchColumn();
        if ($cnt == 0) $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
        else $_SESSION['err'] = 'Cannot delete — category has products.';
    } elseif ($action === 'rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $editStoreIds = $_POST['edit_store_ids'] ?? [];
        if (!is_array($editStoreIds)) $editStoreIds = [$editStoreIds];
        $editStoreIds = array_map('intval', array_filter($editStoreIds));
        if ($id && $name) {
            // Update name on original category
            $db->prepare("UPDATE categories SET name=? WHERE id=?")->execute([$name, $id]);
            // Get original category's store
            $origStmt = $db->prepare("SELECT store_id FROM categories WHERE id=?");
            $origStmt->execute([$id]);
            $origStoreId = (int)($origStmt->fetchColumn() ?: 0);
            // Create category in any newly selected stores
            if (!empty($editStoreIds)) {
                $added = 0;
                foreach ($editStoreIds as $sid) {
                    if ($sid == $origStoreId) continue; // skip original store
                    // Check if category already exists in this store
                    $check = $db->prepare("SELECT id FROM categories WHERE store_id = ? AND LOWER(name) = LOWER(?)");
                    $check->execute([$sid, $name]);
                    if (!$check->fetch()) {
                        try {
                            $db->prepare("INSERT INTO categories (store_id, name) VALUES (?,?)")->execute([$sid, $name]);
                            $added++;
                        } catch(Exception $e) {}
                    }
                }
                if ($added > 0) {
                    $_SESSION['msg'] = "Category updated and added to {$added} more store(s)";
                }
            }
        }
    }
    header('Location: ' . baseUrl('admin/categories.php') . '?store_id=' . $redirectStoreId); exit;
}

if ($storeId) {
    $cats = $db->prepare("SELECT c.*, s.name as store_name, (SELECT COUNT(*) FROM products WHERE category_id=c.id) as prod_count FROM categories c LEFT JOIN stores s ON c.store_id = s.id WHERE c.store_id=? ORDER BY c.sort_order, c.name");
    $cats->execute([$storeId]);
    $cats = $cats->fetchAll();
} else {
    $cats = $db->query("SELECT c.*, s.name as store_name, (SELECT COUNT(*) FROM products WHERE category_id=c.id) as prod_count FROM categories c LEFT JOIN stores s ON c.store_id = s.id ORDER BY s.name, c.sort_order, c.name")->fetchAll();
}
?>
    <div class="topbar">
      <div><div class="page-title">Categories</div><div class="page-subtitle">Super Admin — Manage all categories</div></div>
      <div class="topbar-right" style="display:flex;gap:8px;align-items:center">
        <select class="form-control" id="store-filter" style="font-size:13px;padding:7px 10px" onchange="location.href='<?= baseUrl('admin/categories.php') ?>?store_id='+this.value">
          <option value="0">All Stores</option>
          <?php foreach ($allStores as $st): ?>
          <option value="<?= $st['id'] ?>" <?= $storeId==$st['id']?'selected':'' ?>><?= htmlspecialchars($st['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="content-area">
      <?php if (isset($_SESSION['err'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['err']) ?></div>
      <?php unset($_SESSION['err']); endif; ?>
      <?php if (isset($_SESSION['msg'])): ?>
      <div class="alert alert-success"><?= htmlspecialchars($_SESSION['msg']) ?></div>
      <?php unset($_SESSION['msg']); endif; ?>
      <div class="grid-2">
        <div class="card">
          <div class="card-title mb-16">Add Category</div>
          <form method="POST">
            <input type="hidden" name="action" value="add">
            <?php if ($storeId): ?>
            <input type="hidden" name="store_ids" value="<?= $storeId ?>">
            <?php else: ?>
            <div class="form-group"><label class="form-label">Assign to Stores *</label>
              <div style="display:flex;flex-wrap:wrap;gap:8px">
              <?php foreach ($allStores as $st): ?>
              <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer">
                <input type="checkbox" name="store_ids[]" value="<?= $st['id'] ?>" checked> <?= htmlspecialchars($st['name']) ?>
              </label>
              <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label">Category Name</label><input class="form-control" name="name" placeholder="e.g. Desserts" required></div>
            <button class="btn btn-primary" type="submit">Add Category</button>
          </form>
        </div>
        <div class="card">
          <div class="card-title mb-16">All Categories (<?= count($cats) ?>)</div>
          <?php if (empty($cats)): ?>
          <p class="text-muted text-sm">No categories yet.</p>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><?php if(!$storeId):?><th>Store</th><?php endif;?><th>Name</th><th>Products</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach ($cats as $c): ?>
              <tr>
                <?php if(!$storeId):?><td class="text-sm"><?= htmlspecialchars($c['store_name']) ?></td><?php endif; ?>
                <td class="font-bold"><?= htmlspecialchars($c['name']) ?></td>
                <td><span class="badge badge-neutral"><?= $c['prod_count'] ?></span></td>
                <td style="display:flex;gap:6px">
                  <button class="btn btn-secondary btn-sm" onclick="renameModal(<?= $c['id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>', <?= $c['store_id'] ?>)">Rename</button>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this category?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="store_id" value="<?= $c['store_id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

<div class="modal-overlay" id="rename-modal">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-header"><span class="modal-title">Edit Category</span><button class="modal-close" onclick="closeModal('rename-modal')">×</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="action" value="rename">
      <input type="hidden" id="rename-id" name="id">
      <input type="hidden" id="rename-store-id" name="store_id">
      <div class="form-group"><label class="form-label">Category Name</label><input class="form-control" id="rename-name" name="name" required></div>
      <?php if (!$storeId): ?>
      <div class="form-group"><label class="form-label">Assign to Stores</label>
        <div id="rename-store-checkboxes" style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($allStores as $st): ?>
        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer">
          <input type="checkbox" class="rename-store-check" name="edit_store_ids[]" value="<?= $st['id'] ?>"> <?= htmlspecialchars($st['name']) ?>
        </label>
        <?php endforeach; ?>
        </div>
        <p style="font-size:11px;color:var(--text3);margin-top:4px;">Checked stores will get this category. Original store is always updated.</p>
      </div>
      <?php endif; ?>
      <div class="modal-footer" style="padding:0;border:none;margin-top:12px">
        <button type="button" class="btn btn-secondary" onclick="closeModal('rename-modal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<script>
// Build a map: category name -> array of store_ids that have this category
const catNameStoreMap = {};
<?php foreach ($cats as $c): ?>
(function() {
  var key = <?= json_encode($c['name']) ?>;
  if (!catNameStoreMap[key]) catNameStoreMap[key] = [];
  catNameStoreMap[key].push(<?= (int)$c['store_id'] ?>);
})();
<?php endforeach; ?>

function renameModal(id, name, storeId) {
  document.getElementById('rename-id').value = id;
  document.getElementById('rename-name').value = name;
  document.getElementById('rename-store-id').value = storeId;
  // Pre-check ALL stores that have this category (by name match)
  const storeIdsForCat = catNameStoreMap[name] || [storeId];
  document.querySelectorAll('.rename-store-check').forEach(cb => {
    cb.checked = storeIdsForCat.includes(parseInt(cb.value));
  });
  openModal('rename-modal');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>