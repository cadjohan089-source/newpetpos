<?php
/**
 * Installation checker — visit /check.php to diagnose issues.
 */
require_once __DIR__ . '/includes/db.php';

$checks = [];

$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$checks[] = [$phpOk, 'PHP Version: ' . PHP_VERSION . ($phpOk ? ' ✓' : ' ✗ (need 7.4+)')];

$sqliteOk = extension_loaded('pdo_sqlite');
$checks[] = [$sqliteOk, 'PDO SQLite: ' . ($sqliteOk ? 'Available ✓' : 'MISSING ✗')];

$dataDir  = __DIR__ . '/data';
$dirOk    = is_dir($dataDir) || mkdir($dataDir, 0755, true);
$writeOk  = $dirOk && is_writable($dataDir);
$checks[] = [$writeOk, 'data/ folder writable: ' . ($writeOk ? 'Yes ✓' : 'NO ✗')];

$includesOk = file_exists(__DIR__ . '/includes/auth.php') && file_exists(__DIR__ . '/includes/db.php');
$checks[] = [$includesOk, 'includes/ folder: ' . ($includesOk ? 'OK ✓' : 'MISSING ✗ — upload includes/auth.php and includes/db.php')];

$dbOk = false; $dbMsg = ''; $extra = [];
if ($sqliteOk && $writeOk && $includesOk) {
    try {
        $db = getDB();
        $stores = (int)$db->query("SELECT COUNT(*) FROM stores")->fetchColumn();
        $users  = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $prods  = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $schema = $db->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn() ?: 'unknown';
        $dbOk = true;
        $dbMsg = "Connected OK — $stores store(s), $users user(s), $prods product(s), schema v$schema ✓";
        $extra[] = 'Admin login: admin / admin123 (super admin)';
        $extra[] = 'Cashier login: cashier / cash123';
        if ($prods === 0) $extra[] = 'Tip: Select a store from the sidebar, then add products under Admin → Products.';
    } catch (Exception $e) {
        $dbMsg = $e->getMessage();
    }
}
$checks[] = [$dbOk, 'Database: ' . ($dbOk ? $dbMsg : 'FAILED ✗ ' . $dbMsg)];

$allOk = !in_array(false, array_column($checks, 0));
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Install Check — Multi-Store POS</title>
<style>
body{font-family:sans-serif;max-width:640px;margin:60px auto;padding:0 20px;background:#f5f6fa}
h1{font-size:22px;margin-bottom:6px}
.check{padding:12px 16px;border-radius:8px;margin-bottom:8px;font-size:14px;font-weight:500}
.ok{background:#edfaf4;color:#0f5c35}.fail{background:#fff5f5;color:#c53030}
.tip{background:#eff6ff;color:#1e40af;font-size:13px;padding:10px 14px;border-radius:8px;margin:8px 0}
.go{display:inline-block;margin-top:16px;padding:12px 28px;background:#e85d26;color:#fff;border-radius:8px;text-decoration:none;font-weight:700}
</style>
</head>
<body>
<h1>Multi-Store POS — Install Check</h1>
<?php foreach ($checks as [$ok, $msg]): ?>
<div class="check <?= $ok ? 'ok' : 'fail' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endforeach; ?>
<?php foreach ($extra as $tip): ?><div class="tip"><?= htmlspecialchars($tip) ?></div><?php endforeach; ?>
<?php if ($allOk): ?>
<div class="check ok">Everything looks good!</div>
<a class="go" href="<?= baseUrl('login.php') ?>">Go to Login →</a>
<?php else: ?>
<div class="check fail">Fix the issues above. If database failed, delete <code>data/restaurant.db</code> and reload this page to reset.</div>
<?php endif; ?>
</body>
</html>
