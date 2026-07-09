<?php
$pageTitle = 'Users';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$stores = getAllStores(true);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['super_admin','admin','cashier']) ? $_POST['role'] : 'cashier';
        $storeId = ($role === 'super_admin') ? null : (int)($_POST['store_id'] ?? 0);
        if ($role !== 'super_admin' && !$storeId) {
            $msg = 'error:Please select a store for this user.';
        } elseif ($username && $pass && $name) {
            try {
                $db->prepare("INSERT INTO users (username,password,role,name,store_id) VALUES (?,?,?,?,?)")
                   ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $role, $name, $storeId ?: null]);
                $msg = 'success:User added successfully.';
            } catch(Exception $e) { $msg = 'error:Username already exists.'; }
        } else $msg = 'error:All fields required.';
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id != currentUser()['id']) $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    } elseif ($action === 'change_pass') {
        $id = (int)($_POST['id'] ?? 0);
        $pass = $_POST['password'] ?? '';
        if ($id && strlen($pass) >= 4) {
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            $msg = 'success:Password changed.';
        } else $msg = 'error:Password must be at least 4 characters.';
    } elseif ($action === 'assign_store') {
        $id = (int)($_POST['id'] ?? 0);
        $storeId = (int)($_POST['store_id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['admin','cashier']) ? $_POST['role'] : 'cashier';
        if ($id) {
            $db->prepare("UPDATE users SET store_id=?, role=? WHERE id=? AND role != 'super_admin'")
               ->execute([$storeId ?: null, $role, $id]);
            $msg = 'success:User store assignment updated.';
        }
    }
    header('Location: ' . baseUrl('admin/users.php') . ($msg ? '?msg='.urlencode($msg) : '')); exit;
}
$msg = $_GET['msg'] ?? '';
$users = $db->query("
    SELECT u.id, u.username, u.name, u.role, u.store_id, u.created_at, s.name as store_name
    FROM users u LEFT JOIN stores s ON u.store_id = s.id
    ORDER BY u.role, u.name
")->fetchAll();
?>
    <div class="topbar">
      <div><div class="page-title">Users</div><div class="page-subtitle">Manage access, roles & store assignments</div></div>
    </div>
    <div class="content-area">
      <?php if ($msg): list($type,$text) = explode(':', $msg, 2); ?>
      <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?>"><?= htmlspecialchars($text) ?></div>
      <?php endif; ?>
      <div class="grid-2">
        <div class="card">
          <div class="card-title mb-16">Add New User</div>
          <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group"><label class="form-label">Full Name</label><input class="form-control" name="name" required></div>
            <div class="form-group"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
            <div class="form-group"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required></div>
            <div class="form-group"><label class="form-label">Role</label>
              <select class="form-control" name="role" id="add-role" onchange="toggleStoreField()">
                <option value="cashier">Cashier (Counter only)</option>
                <option value="admin">Store Admin</option>
                <?php if (isSuperAdmin()): ?><option value="super_admin">Super Admin (All stores)</option><?php endif; ?>
              </select>
            </div>
            <div class="form-group" id="store-field">
              <label class="form-label">Assign to Store</label>
              <select class="form-control" name="store_id">
                <option value="">— Select Store —</option>
                <?php foreach ($stores as $st): ?>
                <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-primary" type="submit">Add User</button>
          </form>
        </div>
        <div class="card">
          <div class="card-title mb-16">All Users (<?= count($users) ?>)</div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Store</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach ($users as $u): $isMe = $u['id'] == currentUser()['id']; ?>
              <tr>
                <td><b><?= htmlspecialchars($u['name']) ?></b><?= $isMe ? ' <span class="badge badge-info" style="font-size:9px">You</span>' : '' ?></td>
                <td class="font-mono text-sm"><?= htmlspecialchars($u['username']) ?></td>
                <td><span class="badge <?= $u['role']==='super_admin'?'badge-warning':($u['role']==='admin'?'badge-info':'badge-neutral') ?>"><?= ucfirst(str_replace('_',' ',$u['role'])) ?></span></td>
                <td><?= $u['store_name'] ? htmlspecialchars($u['store_name']) : '<span class="text-muted">All Stores</span>' ?></td>
                <td style="display:flex;gap:5px;flex-wrap:wrap">
                  <button class="btn btn-secondary btn-sm" onclick="changePassModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>')">Pass</button>
                  <?php if (!$isMe && $u['role'] !== 'super_admin'): ?>
                  <button class="btn btn-secondary btn-sm" onclick='assignStoreModal(<?= json_encode($u) ?>)'>Assign</button>
                  <form method="POST" onsubmit="return confirm('Delete user?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-danger btn-sm">Del</button></form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

<div class="modal-overlay" id="pass-modal">
  <div class="modal-box" style="max-width:360px">
    <div class="modal-header"><span class="modal-title" id="pass-modal-title">Change Password</span><button class="modal-close" onclick="closeModal('pass-modal')">×</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="action" value="change_pass">
      <input type="hidden" id="cp-id" name="id">
      <div class="form-group"><label class="form-label">New Password</label><input class="form-control" type="password" name="password" required></div>
      <div class="modal-footer" style="padding:0;border:none;margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-secondary" onclick="closeModal('pass-modal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Change</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="assign-modal">
  <div class="modal-box" style="max-width:400px">
    <div class="modal-header"><span class="modal-title" id="assign-modal-title">Assign Store</span><button class="modal-close" onclick="closeModal('assign-modal')">×</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="action" value="assign_store">
      <input type="hidden" id="as-id" name="id">
      <div class="form-group"><label class="form-label">Store</label>
        <select class="form-control" name="store_id" id="as-store" required>
          <?php foreach ($stores as $st): ?>
          <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Role</label>
        <select class="form-control" name="role" id="as-role">
          <option value="cashier">Cashier</option>
          <option value="admin">Store Admin</option>
        </select>
      </div>
      <div class="modal-footer" style="padding:0;border:none;margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-secondary" onclick="closeModal('assign-modal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<script>
function toggleStoreField() {
  const role = document.getElementById('add-role').value;
  document.getElementById('store-field').style.display = role === 'super_admin' ? 'none' : 'block';
}
toggleStoreField();
function changePassModal(id, name) {
  document.getElementById('cp-id').value = id;
  document.getElementById('pass-modal-title').textContent = 'Change Password — ' + name;
  openModal('pass-modal');
}
function assignStoreModal(u) {
  document.getElementById('as-id').value = u.id;
  document.getElementById('as-store').value = u.store_id || '';
  document.getElementById('as-role').value = u.role;
  document.getElementById('assign-modal-title').textContent = 'Assign Store — ' + u.name;
  openModal('assign-modal');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
