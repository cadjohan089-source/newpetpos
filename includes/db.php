<?php
define('DB_FILE',   __DIR__ . '/../data/restaurant.db');
define('DB_SEEDED_KEY', 'database_seeded');
define('DB_SEEDED_VERSION', '2.2');
define('SCHEMA_VERSION', '2.5');
define('ROOT_PATH', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));

if (PHP_OS_FAMILY === 'Windows') {
    $sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php_sessions';
    if (!is_dir($sessDir)) @mkdir($sessDir, 0755, true);
    if (is_dir($sessDir) && is_writable($sessDir)) session_save_path($sessDir);
}

function baseUrl($path = '') {
    static $base = null;
    if ($base === null) {
        $docRoot  = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ROOT_PATH) ?: ROOT_PATH);
        $projRoot = str_replace('\\', '/', ROOT_PATH);
        $base     = rtrim(substr($projRoot, strlen($docRoot)), '/');
    }
    return $base . '/' . ltrim($path, '/');
}

function getDB() {
    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            die('<h2>Cannot create data/ folder.</h2><p>Please create the <code>data/</code> folder manually inside the project and make it writable.</p>');
        }
    }
    if (!is_writable($dir)) {
        die('<h2>data/ folder is not writable.</h2><p>Please give write permission to the <code>data/</code> folder.</p>');
    }
    try {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_TIMEOUT, 30);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=5000');

        createTables($db);
        ensureSchema($db);
        runMigrations($db);
        seedIfNeeded($db);

        return $db;
    } catch (Exception $e) {
        error_log('Database error: ' . $e->getMessage());
        die('<h2>Database Error</h2><p>' . htmlspecialchars($e->getMessage()) . '</p>'
            . '<p>Try visiting <a href="' . htmlspecialchars(baseUrl('check.php')) . '">check.php</a> for diagnostics, '
            . 'or delete <code>data/restaurant.db</code> to reset (this removes all data).</p>');
    }
}

function createTables($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY, value TEXT
        );
        CREATE TABLE IF NOT EXISTS stores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT DEFAULT '',
            address TEXT DEFAULT '',
            receipt_footer TEXT DEFAULT 'Thank you!',
            bill_prefix TEXT DEFAULT 'ST',
            tax_rate REAL DEFAULT 5,
            currency TEXT DEFAULT 'Rs',
            logo TEXT DEFAULT '',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS store_settings (
            store_id INTEGER NOT NULL,
            key TEXT NOT NULL,
            value TEXT,
            PRIMARY KEY (store_id, key),
            FOREIGN KEY(store_id) REFERENCES stores(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL DEFAULT 1,
            name TEXT NOT NULL,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id),
            UNIQUE(store_id, name)
        );
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL DEFAULT 1,
            name TEXT NOT NULL,
            price REAL NOT NULL,
            buy_price REAL DEFAULT 0,
            stock_qty INTEGER DEFAULT 0,
            category_id INTEGER,
            available INTEGER DEFAULT 1,
            barcode TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(category_id) REFERENCES categories(id),
            FOREIGN KEY(store_id) REFERENCES stores(id),
            UNIQUE(store_id, name, category_id)
        );
        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            phone TEXT DEFAULT '',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id)
        );
        CREATE TABLE IF NOT EXISTS customer_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            customer_id INTEGER NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            note TEXT DEFAULT '',
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id),
            FOREIGN KEY(customer_id) REFERENCES customers(id),
            FOREIGN KEY(created_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            category TEXT NOT NULL DEFAULT 'Other',
            description TEXT DEFAULT '',
            amount REAL NOT NULL DEFAULT 0,
            expense_date DATE NOT NULL,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id),
            FOREIGN KEY(created_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS bills (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL DEFAULT 1,
            bill_no TEXT NOT NULL,
            customer_name TEXT DEFAULT 'Walk-in',
            customer_id INTEGER,
            table_no TEXT DEFAULT '-',
            subtotal REAL NOT NULL,
            tax_amount REAL NOT NULL,
            discount REAL DEFAULT 0,
            total REAL NOT NULL,
            paid_amount REAL NOT NULL DEFAULT 0,
            due_amount REAL NOT NULL DEFAULT 0,
            payment_status TEXT DEFAULT 'paid',
            payment_method TEXT DEFAULT 'Cash',
            status TEXT DEFAULT 'paid',
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id),
            FOREIGN KEY(customer_id) REFERENCES customers(id),
            UNIQUE(store_id, bill_no)
        );
        CREATE TABLE IF NOT EXISTS bill_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bill_id INTEGER NOT NULL,
            product_id INTEGER,
            product_name TEXT NOT NULL,
            price REAL NOT NULL,
            quantity INTEGER NOT NULL,
            subtotal REAL NOT NULL,
            FOREIGN KEY(bill_id) REFERENCES bills(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS queue_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL DEFAULT 1,
            queue_no TEXT NOT NULL,
            table_no TEXT DEFAULT '-',
            note TEXT DEFAULT '',
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id),
            UNIQUE(store_id, queue_no)
        );
        CREATE TABLE IF NOT EXISTS queue_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue_id INTEGER NOT NULL,
            product_id INTEGER,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            price REAL NOT NULL DEFAULT 0,
            FOREIGN KEY(queue_id) REFERENCES queue_orders(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'cashier',
            name TEXT,
            store_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id)
        );
        CREATE TABLE IF NOT EXISTS stock_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            log_date DATE NOT NULL,
            notes TEXT DEFAULT '',
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id),
            FOREIGN KEY(product_id) REFERENCES products(id),
            FOREIGN KEY(created_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS returns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            bill_id INTEGER NOT NULL,
            return_no TEXT NOT NULL,
            reason TEXT DEFAULT '',
            total_refund REAL NOT NULL DEFAULT 0,
            status TEXT DEFAULT 'completed',
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id),
            FOREIGN KEY(bill_id) REFERENCES bills(id),
            FOREIGN KEY(created_by) REFERENCES users(id),
            UNIQUE(store_id, return_no)
        );
        CREATE TABLE IF NOT EXISTS return_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            return_id INTEGER NOT NULL,
            bill_item_id INTEGER NOT NULL,
            product_id INTEGER,
            product_name TEXT NOT NULL,
            price REAL NOT NULL,
            quantity INTEGER NOT NULL,
            subtotal REAL NOT NULL,
            discount_share REAL NOT NULL DEFAULT 0,
            tax_share REAL NOT NULL DEFAULT 0,
            refund_amount REAL NOT NULL DEFAULT 0,
            FOREIGN KEY(return_id) REFERENCES returns(id) ON DELETE CASCADE,
            FOREIGN KEY(bill_item_id) REFERENCES bill_items(id)
        );
    ");
}

function tableHasColumn($db, $table, $column) {
    $cols = $db->query("PRAGMA table_info($table)")->fetchAll();
    foreach ($cols as $c) {
        if ($c['name'] === $column) return true;
    }
    return false;
}

function tableExists($db, $table) {
    $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
    $st->execute([$table]);
    return (bool)$st->fetch();
}

function ensureDefaultStore($db) {
    if (!tableExists($db, 'stores')) return;
    $count = (int)$db->query("SELECT COUNT(*) FROM stores")->fetchColumn();
    if ($count > 0) return;

    $s = getSettingsRaw($db);
    $db->prepare("INSERT INTO stores (id, name, phone, address, receipt_footer, bill_prefix, tax_rate, currency) VALUES (1,?,?,?,?,?,?,?)")
       ->execute([
           $s['restaurant_name'] ?? 'Main Store',
           $s['restaurant_phone'] ?? '',
           $s['restaurant_address'] ?? '',
           $s['receipt_footer'] ?? 'Thank you!',
           $s['bill_prefix'] ?? 'SG',
           (float)($s['tax_rate'] ?? 5),
           $s['currency'] ?? 'Rs',
       ]);

    $ins = $db->prepare("INSERT OR IGNORE INTO store_settings (store_id, key, value) VALUES (1, ?, ?)");
    foreach (['printer_name'=>'BC-80POS','kitchen_printer_name'=>'BC-80POS','printer_paper_width'=>'80','printer_drawer_kick'=>'0','printer_auto_print'=>'1'] as $k => $v) {
        $ins->execute([$k, $s[$k] ?? $v]);
    }
}

function ensureSchema($db) {
    if (!tableExists($db, 'stores')) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS stores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                phone TEXT DEFAULT '',
                address TEXT DEFAULT '',
                receipt_footer TEXT DEFAULT 'Thank you!',
                bill_prefix TEXT DEFAULT 'ST',
                tax_rate REAL DEFAULT 5,
                currency TEXT DEFAULT 'Rs',
                logo TEXT DEFAULT '',
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS store_settings (
                store_id INTEGER NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                PRIMARY KEY (store_id, key)
            );
            CREATE TABLE IF NOT EXISTS stock_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                quantity INTEGER NOT NULL,
                log_date DATE NOT NULL,
                notes TEXT DEFAULT '',
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    foreach (['categories','products','bills','queue_orders'] as $t) {
        if (tableExists($db, $t) && !tableHasColumn($db, $t, 'store_id')) {
            $db->exec("ALTER TABLE $t ADD COLUMN store_id INTEGER DEFAULT 1");
        }
    }
    if (tableExists($db, 'users') && !tableHasColumn($db, 'users', 'store_id')) {
        $db->exec("ALTER TABLE users ADD COLUMN store_id INTEGER");
    }
    if (tableExists($db, 'products')) {
        if (!tableHasColumn($db, 'products', 'buy_price')) {
            $db->exec("ALTER TABLE products ADD COLUMN buy_price REAL DEFAULT 0");
        }
        if (!tableHasColumn($db, 'products', 'stock_qty')) {
            $db->exec("ALTER TABLE products ADD COLUMN stock_qty INTEGER DEFAULT 100");
        }
        // Only fix NULL values (new column), never reset 0 to 100
        $db->exec("UPDATE products SET stock_qty = 100 WHERE stock_qty IS NULL");
    }

    ensureDefaultStore($db);

    if (tableExists($db, 'users')) {
        $db->exec("UPDATE users SET role = 'super_admin', store_id = NULL WHERE username = 'admin' AND role IN ('admin', 'super_admin')");
        $db->exec("UPDATE users SET store_id = 1 WHERE store_id IS NULL AND role NOT IN ('super_admin')");
    }
    if (tableExists($db, 'categories')) {
        $db->exec("UPDATE categories SET store_id = 1 WHERE store_id IS NULL");
    }
    if (tableExists($db, 'products')) {
        $db->exec("UPDATE products SET store_id = 1 WHERE store_id IS NULL");
    }
    if (tableExists($db, 'bills')) {
        $db->exec("UPDATE bills SET store_id = 1 WHERE store_id IS NULL");
    }
    if (tableExists($db, 'queue_orders')) {
        $db->exec("UPDATE queue_orders SET store_id = 1 WHERE store_id IS NULL");
    }
}

function runMigrations($db) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'schema_version'");
    $stmt->execute();
    $ver = $stmt->fetchColumn() ?: '1.0';

    if (version_compare($ver, '2.0', '<')) {
        $db->beginTransaction();
        try {
            ensureDefaultStore($db);

            $s = getSettingsRaw($db);
            $ins = $db->prepare("INSERT OR IGNORE INTO store_settings (store_id, key, value) VALUES (1, ?, ?)");
            foreach (['printer_name','kitchen_printer_name','printer_paper_width','printer_drawer_kick','printer_auto_print'] as $k) {
                if (isset($s[$k])) $ins->execute([$k, $s[$k]]);
            }

            $db->exec("UPDATE users SET role = 'super_admin', store_id = NULL WHERE username = 'admin' AND role = 'admin'");
            $db->exec("UPDATE users SET store_id = 1 WHERE store_id IS NULL AND role != 'super_admin'");

            $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2.0')")->execute();
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Migration error: ' . $e->getMessage());
            throw $e;
        }
    }

    // Migration 2.1 — Add returns tables
    if (version_compare($ver, '2.1', '<')) {
        $db->exec("CREATE TABLE IF NOT EXISTS returns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            bill_id INTEGER NOT NULL,
            return_no TEXT NOT NULL,
            reason TEXT DEFAULT '',
            total_refund REAL NOT NULL DEFAULT 0,
            status TEXT DEFAULT 'completed',
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(store_id) REFERENCES stores(id),
            FOREIGN KEY(bill_id) REFERENCES bills(id),
            FOREIGN KEY(created_by) REFERENCES users(id),
            UNIQUE(store_id, return_no)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS return_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            return_id INTEGER NOT NULL,
            bill_item_id INTEGER NOT NULL,
            product_id INTEGER,
            product_name TEXT NOT NULL,
            price REAL NOT NULL,
            quantity INTEGER NOT NULL,
            subtotal REAL NOT NULL,
            discount_share REAL NOT NULL DEFAULT 0,
            tax_share REAL NOT NULL DEFAULT 0,
            refund_amount REAL NOT NULL DEFAULT 0,
            FOREIGN KEY(return_id) REFERENCES returns(id) ON DELETE CASCADE,
            FOREIGN KEY(bill_item_id) REFERENCES bill_items(id)
        )");
        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2.1')")->execute();
    }

    // Migration 2.2 — Add discount/tax/refund columns to return_items
    if (version_compare($ver, '2.2', '<')) {
        // Add new columns if they don't exist
        $cols = $db->query("PRAGMA table_info(return_items)")->fetchAll(PDO::FETCH_COLUMN, 2); // column names at index 1
        $colNames = [];
        foreach ($db->query("PRAGMA table_info(return_items)")->fetchAll() as $c) {
            $colNames[] = $c['name'];
        }
        if (!in_array('discount_share', $colNames)) {
            $db->exec("ALTER TABLE return_items ADD COLUMN discount_share REAL NOT NULL DEFAULT 0");
        }
        if (!in_array('tax_share', $colNames)) {
            $db->exec("ALTER TABLE return_items ADD COLUMN tax_share REAL NOT NULL DEFAULT 0");
        }
        if (!in_array('refund_amount', $colNames)) {
            $db->exec("ALTER TABLE return_items ADD COLUMN refund_amount REAL NOT NULL DEFAULT 0");
        }
        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2.2')")->execute();
    }

    // Migration 2.3 — Fix categories UNIQUE constraint: was UNIQUE(name), should be UNIQUE(store_id, name)
    if (version_compare($ver, '2.3', '<')) {
        // Check if the old incorrect UNIQUE(name) constraint exists
        $indexes = $db->query("PRAGMA index_list('categories')")->fetchAll();
        $hasOldConstraint = false;
        foreach ($indexes as $idx) {
            if ($idx['unique']) {
                $cols = $db->query("PRAGMA index_info('{$idx['name']}')")->fetchAll();
                $colNames = array_column($cols, 'name');
                // If there's a unique index on just 'name' (not store_id + name)
                if (count($colNames) === 1 && $colNames[0] === 'name') {
                    $hasOldConstraint = true;
                    break;
                }
            }
        }
        if ($hasOldConstraint) {
            try {
                // Drop any leftover temp table from a previous failed attempt
                $db->exec("DROP TABLE IF EXISTS categories_new");
                $db->exec("PRAGMA foreign_keys=OFF");
                $db->beginTransaction();
                // Create new table with correct constraint
                $db->exec("CREATE TABLE categories_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id INTEGER NOT NULL DEFAULT 1,
                    name TEXT NOT NULL,
                    sort_order INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(store_id) REFERENCES stores(id),
                    UNIQUE(store_id, name)
                )");
                // Copy existing data
                $db->exec("INSERT INTO categories_new (id, store_id, name, sort_order, created_at) SELECT id, store_id, name, sort_order, created_at FROM categories");
                // Drop old table and rename
                $db->exec("DROP TABLE categories");
                $db->exec("ALTER TABLE categories_new RENAME TO categories");
                $db->commit();
                $db->exec("PRAGMA foreign_keys=ON");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $db->exec("PRAGMA foreign_keys=ON");
                $db->exec("DROP TABLE IF EXISTS categories_new");
                // If database is locked, skip migration — it will retry on next request
                if (stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false) {
                    error_log('Migration 2.3 skipped (database locked), will retry on next request');
                    return; // don't update schema_version, retry next time
                }
                error_log('Migration 2.3 error: ' . $e->getMessage());
                throw $e;
            }
        }
        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2.3')")->execute();
    }

    // Migration 2.4 — Add logo column to stores
    if (version_compare($ver, '2.4', '<')) {
        $cols = [];
        foreach ($db->query("PRAGMA table_info(stores)")->fetchAll() as $c) {
            $cols[] = $c['name'];
        }
        if (!in_array('logo', $cols)) {
            $db->exec("ALTER TABLE stores ADD COLUMN logo TEXT DEFAULT ''");
        }
        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2.4')")->execute();
    }

    // Migration 2.5 — Customer credit system, expenses, product barcode
    if (version_compare($ver, '2.5', '<')) {
        // Barcode on products
        if (!tableHasColumn($db, 'products', 'barcode')) {
            $db->exec("ALTER TABLE products ADD COLUMN barcode TEXT DEFAULT ''");
        }

        // Credit columns on bills
        if (!tableHasColumn($db, 'bills', 'customer_id')) {
            $db->exec("ALTER TABLE bills ADD COLUMN customer_id INTEGER");
        }
        if (!tableHasColumn($db, 'bills', 'paid_amount')) {
            $db->exec("ALTER TABLE bills ADD COLUMN paid_amount REAL NOT NULL DEFAULT 0");
        }
        if (!tableHasColumn($db, 'bills', 'due_amount')) {
            $db->exec("ALTER TABLE bills ADD COLUMN due_amount REAL NOT NULL DEFAULT 0");
        }
        if (!tableHasColumn($db, 'bills', 'payment_status')) {
            $db->exec("ALTER TABLE bills ADD COLUMN payment_status TEXT DEFAULT 'paid'");
        }
        // Backfill existing bills as fully paid (they were created before credit tracking existed).
        // At migration time every pre-existing row still has the column defaults (paid_amount=0,
        // due_amount=0), which is indistinguishable from "just added the columns" — so this only
        // touches genuinely old rows and never touches a real unpaid/partial bill created after this point.
        $db->exec("UPDATE bills SET paid_amount = total, due_amount = 0, payment_status = 'paid' WHERE paid_amount = 0 AND due_amount = 0 AND total > 0");

        // New tables
        if (!tableExists($db, 'customers')) {
            $db->exec("CREATE TABLE customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                phone TEXT DEFAULT '',
                notes TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(store_id) REFERENCES stores(id)
            )");
        }
        if (!tableExists($db, 'customer_payments')) {
            $db->exec("CREATE TABLE customer_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                customer_id INTEGER NOT NULL,
                amount REAL NOT NULL DEFAULT 0,
                note TEXT DEFAULT '',
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(store_id) REFERENCES stores(id),
                FOREIGN KEY(customer_id) REFERENCES customers(id),
                FOREIGN KEY(created_by) REFERENCES users(id)
            )");
        }
        if (!tableExists($db, 'expenses')) {
            $db->exec("CREATE TABLE expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                category TEXT NOT NULL DEFAULT 'Other',
                description TEXT DEFAULT '',
                amount REAL NOT NULL DEFAULT 0,
                expense_date DATE NOT NULL,
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(store_id) REFERENCES stores(id),
                FOREIGN KEY(created_by) REFERENCES users(id)
            )");
        }

        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2.5')")->execute();
    }
}

function seedIfNeeded($db) {
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([DB_SEEDED_KEY]);
        $seeded = $stmt->fetch();

        if ($seeded && $seeded['value'] === DB_SEEDED_VERSION) return;

        $db->beginTransaction();

        if ($seeded) {
            $newDefaults = [
                'printer_name'         => 'BC-80POS',
                'kitchen_printer_name' => 'BC-80POS',
                'printer_paper_width'  => '80',
                'printer_drawer_kick'  => '0',
                'printer_auto_print'   => '1',
            ];
            $ins = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
            foreach ($newDefaults as $k => $v) $ins->execute([$k, $v]);

            if ($seeded['value'] === '1.1') {
                $storeCount = (int)$db->query("SELECT COUNT(*) FROM stores")->fetchColumn();
                if ($storeCount === 0) {
                    $s = getSettingsRaw($db);
                    $db->prepare("INSERT INTO stores (id, name, phone, address, receipt_footer, bill_prefix, tax_rate, currency) VALUES (1,?,?,?,?,?,?,?)")
                       ->execute([
                           $s['restaurant_name'] ?? 'Main Store',
                           $s['restaurant_phone'] ?? '',
                           $s['restaurant_address'] ?? '',
                           $s['receipt_footer'] ?? 'Thank you!',
                           $s['bill_prefix'] ?? 'SG',
                           (float)($s['tax_rate'] ?? 5),
                           $s['currency'] ?? 'Rs',
                       ]);
                }
                $db->exec("UPDATE users SET role = 'super_admin', store_id = NULL WHERE username = 'admin' AND role = 'admin'");
            }

            $updateStmt = $db->prepare("UPDATE settings SET value = ? WHERE key = ?");
            $updateStmt->execute([DB_SEEDED_VERSION, DB_SEEDED_KEY]);
        } else {
            $defaults = [
                'restaurant_name'    => 'Spice Garden Restaurant',
                'restaurant_address' => 'Main Bazaar, Bahawalpur, Punjab',
                'restaurant_phone'   => '+92-300-0000000',
                'tax_rate'           => '5',
                'currency'           => 'Rs',
                'receipt_footer'     => 'Thank you for dining with us!',
                'bill_prefix'        => 'SG',
                'printer_name'         => 'BC-80POS',
                'kitchen_printer_name' => 'BC-80POS',
                'printer_paper_width'  => '80',
                'printer_drawer_kick'  => '0',
                'printer_auto_print'   => '1',
            ];
            $s = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
            foreach ($defaults as $k => $v) $s->execute([$k, $v]);
            $s->execute([DB_SEEDED_KEY, DB_SEEDED_VERSION]);
            $s->execute(['schema_version', SCHEMA_VERSION]);

            $storeCount = (int)$db->query("SELECT COUNT(*) FROM stores")->fetchColumn();
            if ($storeCount === 0) {
                $db->prepare("INSERT INTO stores (name, phone, address, receipt_footer, bill_prefix, tax_rate, currency) VALUES (?,?,?,?,?,?,?)")
                   ->execute([
                       'Spice Garden Restaurant',
                       '+92-300-0000000',
                       'Main Bazaar, Bahawalpur, Punjab',
                       'Thank you for dining with us!',
                       'SG', 5, 'Rs',
                   ]);
            }
            $storeId = (int)$db->query("SELECT id FROM stores ORDER BY id LIMIT 1")->fetchColumn();

            $ss = $db->prepare("INSERT OR IGNORE INTO store_settings (store_id, key, value) VALUES (?, ?, ?)");
            foreach (['printer_name'=>'BC-80POS','kitchen_printer_name'=>'BC-80POS','printer_paper_width'=>'80','printer_drawer_kick'=>'0','printer_auto_print'=>'1'] as $k => $v) {
                $ss->execute([$storeId, $k, $v]);
            }

            $userStmt = $db->prepare("INSERT OR IGNORE INTO users (username, password, role, name, store_id) VALUES (?, ?, ?, ?, ?)");
            $userStmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'super_admin', 'Super Administrator', null]);
            $userStmt->execute(['cashier', password_hash('cash123', PASSWORD_DEFAULT), 'cashier', 'Counter Staff', $storeId]);

            $cats = ['Food', 'Fast Food', 'Tandoor', 'Drinks', 'Snacks', 'Desserts'];
            $catStmt = $db->prepare("INSERT OR IGNORE INTO categories (store_id, name, sort_order) VALUES (?, ?, ?)");
            foreach ($cats as $i => $c) $catStmt->execute([$storeId, $c, $i]);

            $cm = [];
            foreach ($db->query("SELECT id, name FROM categories WHERE store_id = $storeId") as $r) {
                $cm[$r['name']] = $r['id'];
            }

            $productCount = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
            if ($productCount == 0) {
                $prods = [
                    ['Gajar Ka Halwa', 150, 80, 'Desserts'], ['Kheer', 120, 60, 'Desserts'],
                    ['Chicken Burger', 200, 100, 'Fast Food'], ['Chicken Biryani', 220, 50, 'Food'],
                    ['Chai (Tea)', 40, 200, 'Drinks'], ['Samosa', 25, 150, 'Snacks'],
                ];
                $prodStmt = $db->prepare("INSERT INTO products (store_id, name, price, buy_price, stock_qty, category_id) VALUES (?,?,?,?,?,?)");
                foreach ($prods as $p) {
                    $catId = $cm[$p[3]] ?? 1;
                    $prodStmt->execute([$storeId, $p[0], $p[1], $p[2], 50, $catId]);
                }
            }
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Seeding error: " . $e->getMessage());
        throw $e;
    }
}

function getSettingsRaw($db) {
    $out = [];
    foreach ($db->query("SELECT key, value FROM settings") as $r) {
        $out[$r['key']] = $r['value'];
    }
    return $out;
}

function getSetting($key, $default = '') {
    $db = getDB();
    $st = $db->prepare("SELECT value FROM settings WHERE key = ?");
    $st->execute([$key]);
    $r = $st->fetch();
    return $r ? $r['value'] : $default;
}

function getSettings() {
    return getSettingsRaw(getDB());
}

function getStore($storeId) {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM stores WHERE id = ?");
    $st->execute([(int)$storeId]);
    return $st->fetch() ?: null;
}

function getAllStores($activeOnly = false) {
    $db = getDB();
    $sql = "SELECT * FROM stores" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY name";
    return $db->query($sql)->fetchAll();
}

function getStoreSettings($storeId) {
    $store = getStore($storeId);
    if (!$store) return [];

    $out = [
        'restaurant_name'    => $store['name'],
        'restaurant_address' => $store['address'],
        'restaurant_phone'   => $store['phone'],
        'receipt_footer'     => $store['receipt_footer'],
        'bill_prefix'        => $store['bill_prefix'],
        'tax_rate'           => (string)$store['tax_rate'],
        'currency'           => $store['currency'],
        'store_id'           => (string)$store['id'],
        'store_name'         => $store['name'],
    ];

    $db = getDB();
    $st = $db->prepare("SELECT key, value FROM store_settings WHERE store_id = ?");
    $st->execute([(int)$storeId]);
    foreach ($st->fetchAll() as $r) {
        $out[$r['key']] = $r['value'];
    }
    return $out;
}

function saveStoreSettings($storeId, $data) {
    $db = getDB();
    $storeFields = ['name'=>'name','phone'=>'phone','address'=>'address','receipt_footer'=>'receipt_footer','bill_prefix'=>'bill_prefix','tax_rate'=>'tax_rate','currency'=>'currency'];
    $sets = [];
    $params = [];
    foreach ($storeFields as $key => $col) {
        if (isset($data[$key])) {
            $sets[] = "$col = ?";
            $params[] = $data[$key];
        }
    }
    if ($sets) {
        $params[] = (int)$storeId;
        $db->prepare("UPDATE stores SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    $printerKeys = ['printer_name','kitchen_printer_name','printer_paper_width','printer_drawer_kick','printer_auto_print'];
    $ins = $db->prepare("INSERT OR REPLACE INTO store_settings (store_id, key, value) VALUES (?, ?, ?)");
    foreach ($printerKeys as $k) {
        if (isset($data[$k])) $ins->execute([(int)$storeId, $k, $data[$k]]);
    }
}

/**
 * Find an existing customer by phone (preferred) or name within a store,
 * or create a new one. Returns the customer id, or null if no usable name given.
 */
function findOrCreateCustomer($storeId, $name, $phone = '') {
    $name = trim($name ?? '');
    $phone = trim($phone ?? '');
    if ($name === '' || strcasecmp($name, 'Walk-in') === 0) return null;

    $db = getDB();

    if ($phone !== '') {
        $st = $db->prepare("SELECT id FROM customers WHERE store_id = ? AND phone = ? LIMIT 1");
        $st->execute([(int)$storeId, $phone]);
        $row = $st->fetch();
        if ($row) {
            // Keep the name fresh in case it changed
            $db->prepare("UPDATE customers SET name = ? WHERE id = ?")->execute([$name, $row['id']]);
            return (int)$row['id'];
        }
    } else {
        $st = $db->prepare("SELECT id FROM customers WHERE store_id = ? AND LOWER(name) = LOWER(?) AND (phone = '' OR phone IS NULL) LIMIT 1");
        $st->execute([(int)$storeId, $name]);
        $row = $st->fetch();
        if ($row) return (int)$row['id'];
    }

    $db->prepare("INSERT INTO customers (store_id, name, phone) VALUES (?,?,?)")
       ->execute([(int)$storeId, $name, $phone]);
    return (int)$db->lastInsertId();
}

/**
 * Ledger-style balance for one customer: total billed vs total paid
 * (paid_amount recorded on bills + any later customer_payments).
 */
function getCustomerBalance($customerId) {
    $db = getDB();
    $st = $db->prepare("SELECT COALESCE(SUM(total),0) as billed, COALESCE(SUM(paid_amount),0) as paid_on_bills FROM bills WHERE customer_id = ?");
    $st->execute([(int)$customerId]);
    $row = $st->fetch();

    $st2 = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE customer_id = ?");
    $st2->execute([(int)$customerId]);
    $extraPaid = (float)$st2->fetchColumn();

    $billed = (float)($row['billed'] ?? 0);
    $paid = (float)($row['paid_on_bills'] ?? 0) + $extraPaid;
    return [
        'billed' => $billed,
        'paid'   => $paid,
        'due'    => max(0, $billed - $paid),
    ];
}

function addStockLog($storeId, $productId, $quantity, $logDate, $notes, $userId) {
    $db = getDB();
    $db->prepare("INSERT INTO stock_logs (store_id, product_id, quantity, log_date, notes, created_by) VALUES (?,?,?,?,?,?)")
       ->execute([(int)$storeId, (int)$productId, (int)$quantity, $logDate, $notes, $userId]);
    $db->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ? AND store_id = ?")
       ->execute([(int)$quantity, (int)$productId, (int)$storeId]);
}
?>
