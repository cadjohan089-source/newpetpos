<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requireStorePage();
require_once __DIR__ . '/../includes/header.php';
$storeId = currentStoreId();
$db = getDB();
$msg = '';
$msgType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear data action
    if (isset($_POST['clear_data'])) {
        requireSuperAdmin();
        $tablesToClear = ['bills','bill_items','categories','products','returns','return_items','queue_orders','queue_items','stock_logs'];
        foreach ($tablesToClear as $t) {
            $db->exec("DELETE FROM $t");
        }
        // Reset auto-increment counters
        foreach ($tablesToClear as $t) {
            try { $db->exec("DELETE FROM sqlite_sequence WHERE name='$t'"); } catch(Exception $e) {}
        }
        $msg = 'All transaction data cleared! Users and stores kept intact.';
        $msgType = 'success';
    } elseif (isset($_POST['clear_store_data'])) {
        $tablesToClear = ['bills','bill_items','categories','products','returns','return_items','queue_orders','queue_items','stock_logs'];
        foreach ($tablesToClear as $t) {
            // Only clear data belonging to this store (tables that have store_id)
            $cols = $db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (in_array('store_id', $cols)) {
                $db->prepare("DELETE FROM $t WHERE store_id=?")->execute([$storeId]);
            } elseif ($t === 'bill_items') {
                $db->prepare("DELETE FROM bill_items WHERE bill_id IN (SELECT id FROM bills WHERE store_id=?)")->execute([$storeId]);
            } elseif ($t === 'return_items') {
                $db->prepare("DELETE FROM return_items WHERE return_id IN (SELECT id FROM returns WHERE store_id=?)")->execute([$storeId]);
            } elseif ($t === 'queue_items') {
                $db->prepare("DELETE FROM queue_items WHERE order_id IN (SELECT id FROM queue_orders WHERE store_id=?)")->execute([$storeId]);
            }
        }
        $msg = 'Your store data cleared! Users and stores kept intact.';
        $msgType = 'success';
    } else {
        // Normal settings save
        saveStoreSettings($storeId, [
            'name' => trim($_POST['restaurant_name'] ?? ''),
            'phone' => trim($_POST['restaurant_phone'] ?? ''),
            'address' => trim($_POST['restaurant_address'] ?? ''),
            'receipt_footer' => trim($_POST['receipt_footer'] ?? ''),
            'bill_prefix' => trim($_POST['bill_prefix'] ?? 'ST'),
            'tax_rate' => (float)($_POST['tax_rate'] ?? 5),
            'currency' => trim($_POST['currency'] ?? 'Rs'),
            'printer_name' => trim($_POST['printer_name'] ?? ''),
            'kitchen_printer_name' => trim($_POST['kitchen_printer_name'] ?? ''),
            'printer_paper_width' => trim($_POST['printer_paper_width'] ?? '80'),
            'printer_drawer_kick' => trim($_POST['printer_drawer_kick'] ?? '0'),
            'printer_auto_print' => trim($_POST['printer_auto_print'] ?? '1'),
        ]);
        $msg = 'Store settings saved successfully!';
        $msgType = 'success';
        $settings = getStoreSettings($storeId);
    }
}
$s = $settings;
?>
    <div class="topbar">
      <div><div class="page-title">Store Settings</div><div class="page-subtitle"><?= htmlspecialchars($s['restaurant_name'] ?? '') ?></div></div>
    </div>
    <div class="content-area">
      <?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <form method="POST" style="max-width:600px">
        <div class="card mb-20">
          <div class="card-title mb-16">Store Info</div>
          <div class="form-group"><label class="form-label">Store Name</label><input class="form-control" name="restaurant_name" value="<?= htmlspecialchars($s['restaurant_name'] ?? '') ?>" required></div>
          <div class="form-group"><label class="form-label">Address</label><input class="form-control" name="restaurant_address" value="<?= htmlspecialchars($s['restaurant_address'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">Phone</label><input class="form-control" name="restaurant_phone" value="<?= htmlspecialchars($s['restaurant_phone'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">Receipt End Message</label><textarea class="form-control" name="receipt_footer" rows="2"><?= htmlspecialchars($s['receipt_footer'] ?? '') ?></textarea></div>
        </div>
        <div class="card mb-20">
          <div class="card-title mb-16">Billing Configuration</div>
          <div class="grid-2">
            <div class="form-group"><label class="form-label">Currency</label><input class="form-control" name="currency" value="<?= htmlspecialchars($s['currency'] ?? 'Rs') ?>"></div>
            <div class="form-group"><label class="form-label">Tax Rate (%)</label><input class="form-control" type="number" name="tax_rate" value="<?= htmlspecialchars($s['tax_rate'] ?? '5') ?>" step="0.5"></div>
          </div>
          <div class="form-group"><label class="form-label">Bill Prefix</label><input class="form-control" name="bill_prefix" value="<?= htmlspecialchars($s['bill_prefix'] ?? 'ST') ?>" maxlength="5"></div>
        </div>
        <div class="card mb-20">
          <div class="card-title mb-16">Thermal Printer (QZ Tray)</div>
          <div class="grid-2">
            <div class="form-group"><label class="form-label">Receipt Printer</label><input class="form-control" name="printer_name" value="<?= htmlspecialchars($s['printer_name'] ?? 'BC-80POS') ?>"></div>
            <div class="form-group"><label class="form-label">Kitchen Printer</label><input class="form-control" name="kitchen_printer_name" value="<?= htmlspecialchars($s['kitchen_printer_name'] ?? 'BC-80POS') ?>"></div>
          </div>
          <div class="grid-2">
            <div class="form-group"><label class="form-label">Paper Width</label>
              <select class="form-control" name="printer_paper_width">
                <option value="80" <?= ($s['printer_paper_width'] ?? '80') === '80' ? 'selected' : '' ?>>80 mm</option>
                <option value="58" <?= ($s['printer_paper_width'] ?? '80') === '58' ? 'selected' : '' ?>>58 mm</option>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Auto-print</label>
              <select class="form-control" name="printer_auto_print">
                <option value="1" <?= ($s['printer_auto_print'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
                <option value="0" <?= ($s['printer_auto_print'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
              </select>
            </div>
          </div>
          <div class="form-group"><label class="form-label">Cash Drawer</label>
            <select class="form-control" name="printer_drawer_kick">
              <option value="0" <?= ($s['printer_drawer_kick'] ?? '0') === '0' ? 'selected' : '' ?>>Off</option>
              <option value="1" <?= ($s['printer_drawer_kick'] ?? '0') === '1' ? 'selected' : '' ?>>On</option>
            </select>
          </div>
        </div>
        <button class="btn btn-primary btn-lg" type="submit">Save Settings</button>
      </form>

      <div class="card mb-20" style="margin-top:20px;border:2px solid var(--red)">
        <div class="card-title mb-16" style="color:var(--red)">⚠️ Clear Data</div>
        <p style="font-size:13px;color:var(--text3);margin-bottom:16px">This will permanently delete all bills, products, categories, returns, queue orders, and stock logs. <strong>Users and stores will NOT be affected.</strong></p>
        <?php if (isSuperAdmin()): ?>
        <form method="POST" onsubmit="return confirm('Are you sure? This will clear ALL data from ALL stores! Users and stores will remain.')">
          <input type="hidden" name="clear_data" value="1">
          <button class="btn btn-lg" type="submit" style="background:var(--red);color:#fff;border:none">🗑️ Clear ALL Data (All Stores)</button>
        </form>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('Are you sure? This will clear your store\'s data only! Users and stores will remain.')">
          <input type="hidden" name="clear_store_data" value="1">
          <button class="btn btn-lg" type="submit" style="background:var(--amber);color:#fff;border:none;margin-top:8px">🗑️ Clear My Store Data</button>
        </form>
      </div>
    </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
