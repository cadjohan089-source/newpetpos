<?php
$pageTitle = 'Stores';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Handle logo upload — returns relative path or empty string
    function handleLogoUpload($storeId) {
        if (empty($_FILES['logo']['tmp_name']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            return '';
        }
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png','jpg','jpeg','gif','webp','svg'])) return '';
        $dir = __DIR__ . '/../assets/logos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'store-' . $storeId . '-' . time() . '.' . $ext;
        $path = $dir . $filename;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $path)) {
            // Remove old logo file if exists
            global $db;
            $old = $db->prepare("SELECT logo FROM stores WHERE id = ?");
            $old->execute([$storeId]);
            $oldLogo = $old->fetchColumn();
            if ($oldLogo && file_exists(__DIR__ . '/../' . $oldLogo)) {
                @unlink(__DIR__ . '/../' . $oldLogo);
            }
            return 'assets/logos/' . $filename;
        }
        return '';
    }

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $receiptFooter = trim($_POST['receipt_footer'] ?? 'Thank you!');
        $billPrefix = trim($_POST['bill_prefix'] ?? 'ST') ?: 'ST';
        $taxRate = (float)($_POST['tax_rate'] ?? 5);
        $currency = trim($_POST['currency'] ?? 'Rs') ?: 'Rs';
        $adminName = trim($_POST['admin_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($name && $username && $password && $adminName) {
            try {
                $db->beginTransaction();
                $db->prepare("INSERT INTO stores (name, phone, address, receipt_footer, bill_prefix, tax_rate, currency) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$name, $phone, $address, $receiptFooter, strtoupper(substr($billPrefix, 0, 5)), $taxRate, $currency]);
                $storeId = (int)$db->lastInsertId();

                $ss = $db->prepare("INSERT INTO store_settings (store_id, key, value) VALUES (?, ?, ?)");
                foreach (['printer_name'=>'BC-80POS','kitchen_printer_name'=>'BC-80POS','printer_paper_width'=>'80','printer_drawer_kick'=>'0','printer_auto_print'=>'1'] as $k => $v) {
                    $ss->execute([$storeId, $k, $v]);
                }

                $db->prepare("INSERT INTO users (username, password, role, name, store_id) VALUES (?,?,?,?,?)")
                   ->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', $adminName, $storeId]);

                $catStmt = $db->prepare("INSERT OR IGNORE INTO categories (store_id, name, sort_order) VALUES (?, ?, ?)");
                foreach (['Food', 'Fast Food', 'Drinks', 'Snacks', 'Desserts'] as $i => $catName) {
                    $catStmt->execute([$storeId, $catName, $i]);
                }

                $db->commit();
                $msg = 'success:Store "' . $name . '" created with admin login.';
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $msg = 'error:' . (strpos($e->getMessage(), 'UNIQUE') !== false ? 'Username already exists.' : 'Failed to create store.');
            }
        } else {
            $msg = 'error:All required fields must be filled.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE stores SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
        $msg = 'success:Store status updated.';
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $logoPath = handleLogoUpload($id);
            $updateSql = "UPDATE stores SET name=?, phone=?, address=?, receipt_footer=?, bill_prefix=?, tax_rate=?, currency=?";
            $updateParams = [
                trim($_POST['name'] ?? ''),
                trim($_POST['phone'] ?? ''),
                trim($_POST['address'] ?? ''),
                trim($_POST['receipt_footer'] ?? ''),
                strtoupper(trim($_POST['bill_prefix'] ?? 'ST')),
                (float)($_POST['tax_rate'] ?? 5),
                trim($_POST['currency'] ?? 'Rs'),
            ];
            if ($logoPath) {
                $updateSql .= ", logo=?";
                $updateParams[] = $logoPath;
            }
            $updateSql .= " WHERE id=?";
            $updateParams[] = $id;
            $db->prepare($updateSql)->execute($updateParams);
            $msg = 'success:Store updated.';
        }
    } elseif ($action === 'upload_logo') {
        // Separate logo-only upload for existing stores
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $logoPath = handleLogoUpload($id);
            if ($logoPath) {
                $db->prepare("UPDATE stores SET logo=? WHERE id=?")->execute([$logoPath, $id]);
                $msg = 'success:Logo uploaded.';
            } else {
                $msg = 'error:Logo upload failed. Use PNG, JPG, GIF, WebP or SVG.';
            }
        }
    }
    header('Location: ' . baseUrl('admin/stores.php') . ($msg ? '?msg=' . urlencode($msg) : ''));
    exit;
}

$msg = $_GET['msg'] ?? '';
$stores = $db->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM users WHERE store_id = s.id) as user_count,
           (SELECT COUNT(*) FROM products WHERE store_id = s.id) as product_count,
           (SELECT COALESCE(SUM(total),0) FROM bills WHERE store_id = s.id) as total_sales
    FROM stores s ORDER BY s.name
")->fetchAll();
?>
    <div class="topbar">
      <div><div class="page-title">Stores</div><div class="page-subtitle">Create and manage all store locations</div></div>
    </div>
    <div class="content-area">
      <?php if ($msg): list($type, $text) = explode(':', $msg, 2); ?>
      <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?>"><?= htmlspecialchars($text) ?></div>
      <?php endif; ?>

      <div class="grid-2">
        <div class="card">
          <div class="card-title mb-16">Create New Store</div>
          <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group"><label class="form-label">Store Name *</label><input class="form-control" name="name" required placeholder="e.g. Downtown Branch"></div>
            <div class="grid-2">
              <div class="form-group"><label class="form-label">Phone</label><input class="form-control" name="phone" placeholder="+92-300-0000000"></div>
              <div class="form-group"><label class="form-label">Bill Prefix</label><input class="form-control" name="bill_prefix" value="ST" maxlength="5"></div>
            </div>
            <div class="form-group"><label class="form-label">Address</label><input class="form-control" name="address" placeholder="Street, City"></div>
            <div class="form-group"><label class="form-label">Receipt End Message</label><textarea class="form-control" name="receipt_footer" rows="2">Thank you for shopping with us!</textarea></div>
            <div class="grid-2">
              <div class="form-group"><label class="form-label">Tax Rate (%)</label><input class="form-control" type="number" name="tax_rate" value="5" min="0" step="0.5"></div>
              <div class="form-group"><label class="form-label">Currency</label><input class="form-control" name="currency" value="Rs"></div>
            </div>
            <hr style="margin:16px 0;border-color:var(--border)">
            <div class="card-title mb-12" style="font-size:14px">Store Admin Login</div>
            <div class="form-group"><label class="form-label">Admin Full Name *</label><input class="form-control" name="admin_name" required placeholder="Store Manager"></div>
            <div class="grid-2">
              <div class="form-group"><label class="form-label">Username *</label><input class="form-control" name="username" required placeholder="store1admin"></div>
              <div class="form-group"><label class="form-label">Password *</label><input class="form-control" type="password" name="password" required placeholder="Min 4 chars"></div>
            </div>
            <button class="btn btn-primary" type="submit">Create Store</button>
          </form>
        </div>

        <div class="card">
          <div class="card-title mb-16">All Stores (<?= count($stores) ?>)</div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Logo</th><th>Store</th><th>Phone</th><th>Users</th><th>Products</th><th>Sales</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach ($stores as $s): ?>
              <tr>
                <td style="text-align:center"><?php if (!empty($s['logo'])): ?><img src="<?= baseUrl($s['logo']) ?>" alt="" style="width:40px;height:40px;border-radius:6px;object-fit:contain"><?php else: ?><span style="font-size:20px;opacity:.3">🏪</span><?php endif; ?></td>
                <td><b><?= htmlspecialchars($s['name']) ?></b><br><span class="text-sm text-muted"><?= htmlspecialchars($s['address']) ?></span></td>
                <td class="text-sm"><?= htmlspecialchars($s['phone']) ?></td>
                <td><?= (int)$s['user_count'] ?></td>
                <td><?= (int)$s['product_count'] ?></td>
                <td class="font-mono"><?= htmlspecialchars($s['currency']) ?> <?= number_format($s['total_sales'], 0) ?></td>
                <td><span class="badge <?= $s['is_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td style="display:flex;gap:5px;flex-wrap:wrap">
                  <form method="POST" action="<?= baseUrl('admin/switch-store.php') ?>" style="display:inline">
                    <input type="hidden" name="store_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="redirect" value="index.php">
                    <button class="btn btn-primary btn-sm" type="submit">Open POS</button>
                  </form>
                  <button class="btn btn-secondary btn-sm" onclick='openEditStore(<?= json_encode($s) ?>)'>Edit</button>
                  <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-secondary btn-sm"><?= $s['is_active'] ? 'Disable' : 'Enable' ?></button></form>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

<div class="modal-overlay" id="store-modal">
  <div class="modal-box">
    <div class="modal-header"><span class="modal-title">Edit Store</span><button class="modal-close" onclick="closeModal('store-modal')">×</button></div>
    <form method="POST" enctype="multipart/form-data" class="modal-body">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="es-id">
      <div class="form-group" style="text-align:center;margin-bottom:16px">
        <img id="es-logo-preview" src="" alt="" style="max-width:120px;max-height:80px;border-radius:8px;display:none;margin-bottom:8px;border:1px solid var(--border)">
        <div id="es-logo-placeholder" style="width:80px;height:80px;border-radius:8px;border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;color:var(--text3);font-size:24px">🏪</div>
        <input type="file" name="logo" id="es-logo" accept="image/*" style="font-size:12px" onchange="previewLogo(this)">
        <div style="font-size:11px;color:var(--text3);margin-top:4px">PNG, JPG, GIF, WebP or SVG</div>
      </div>
      <div class="form-group"><label class="form-label">Store Name</label><input class="form-control" name="name" id="es-name" required></div>
      <div class="form-group"><label class="form-label">Phone</label><input class="form-control" name="phone" id="es-phone"></div>
      <div class="form-group"><label class="form-label">Address</label><input class="form-control" name="address" id="es-address"></div>
      <div class="form-group"><label class="form-label">Receipt End Message</label><textarea class="form-control" name="receipt_footer" id="es-footer" rows="2"></textarea></div>
      <div class="grid-2">
        <div class="form-group"><label class="form-label">Bill Prefix</label><input class="form-control" name="bill_prefix" id="es-prefix"></div>
        <div class="form-group"><label class="form-label">Tax Rate (%)</label><input class="form-control" type="number" name="tax_rate" id="es-tax" step="0.5"></div>
      </div>
      <div class="form-group"><label class="form-label">Currency</label><input class="form-control" name="currency" id="es-currency"></div>
      <div class="modal-footer" style="padding:0;border:none;margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-secondary" onclick="closeModal('store-modal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<script>
function previewLogo(input) {
  const preview = document.getElementById('es-logo-preview');
  const placeholder = document.getElementById('es-logo-placeholder');
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      preview.src = e.target.result;
      preview.style.display = 'block';
      placeholder.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  } else {
    preview.style.display = 'none';
    placeholder.style.display = 'flex';
  }
}
function openEditStore(s) {
  document.getElementById('es-id').value = s.id;
  document.getElementById('es-name').value = s.name;
  document.getElementById('es-phone').value = s.phone || '';
  document.getElementById('es-address').value = s.address || '';
  document.getElementById('es-footer').value = s.receipt_footer || '';
  document.getElementById('es-prefix').value = s.bill_prefix || 'ST';
  document.getElementById('es-tax').value = s.tax_rate || 5;
  document.getElementById('es-currency').value = s.currency || 'Rs';
  const preview = document.getElementById('es-logo-preview');
  const placeholder = document.getElementById('es-logo-placeholder');
  if (s.logo) {
    preview.src = window.API_BASE + s.logo;
    preview.style.display = 'block';
    placeholder.style.display = 'none';
  } else {
    preview.style.display = 'none';
    placeholder.style.display = 'flex';
  }
  document.getElementById('es-logo').value = '';
  openModal('store-modal');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
