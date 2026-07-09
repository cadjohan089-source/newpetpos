<?php
require_once __DIR__ . '/db.php';

function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('RPOSSESS');
        session_start();
    }
}

function isLoggedIn() { startSession(); return isset($_SESSION['user_id']); }

function isSuperAdmin() {
    startSession();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

function isAdmin() {
    startSession();
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin'], true);
}

function isStoreAdmin() { return isAdmin(); }

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . baseUrl('login.php'));
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . baseUrl('index.php?err=access'));
        exit;
    }
}

function requireSuperAdmin() {
    requireLogin();
    if (!isSuperAdmin()) {
        header('Location: ' . baseUrl('index.php?err=access'));
        exit;
    }
}

function currentStoreId() {
    startSession();
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
}

function setCurrentStore($storeId) {
    startSession();
    $_SESSION['store_id'] = (int)$storeId;
}

function requireStoreContext() {
    requireLogin();
    if (isSuperAdmin() && !currentStoreId()) {
        return null;
    }
    $storeId = currentStoreId();
    if (!$storeId) {
        header('Location: ' . baseUrl('login.php?err=nostore'));
        exit;
    }
    return $storeId;
}

function effectiveStoreId() {
    startSession();
    if (isSuperAdmin() && isset($_GET['store_id']) && (int)$_GET['store_id'] > 0) {
        return (int)$_GET['store_id'];
    }
    return currentStoreId();
}

function userCanAccessStore($userId, $storeId) {
    if (isSuperAdmin()) return true;
    $db = getDB();
    $st = $db->prepare("SELECT store_id, role FROM users WHERE id = ?");
    $st->execute([(int)$userId]);
    $u = $st->fetch();
    if (!$u) return false;
    if ($u['role'] === 'super_admin') return true;
    return (int)$u['store_id'] === (int)$storeId;
}

function login($username, $password) {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        startSession();
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['name']     = $user['name'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['store_id'] = $user['store_id'] ? (int)$user['store_id'] : null;
        return true;
    }
    return false;
}

function requireStorePage() {
    requireLogin();
    if (!currentStoreId()) {
        header('Location: ' . baseUrl(isSuperAdmin() ? 'admin/stores.php' : 'login.php?err=nostore'));
        exit;
    }
}

function loginRedirectUrl() {
    if (isSuperAdmin()) {
        return baseUrl('admin/stores.php');
    }
    if (currentStoreId()) {
        return baseUrl('index.php');
    }
    return baseUrl('login.php?err=nostore');
}

function logout() {
    startSession();
    session_destroy();
    header('Location: ' . baseUrl('login.php'));
    exit;
}

function currentUser() {
    startSession();
    return [
        'id'       => $_SESSION['user_id']  ?? null,
        'username' => $_SESSION['username'] ?? '',
        'name'     => $_SESSION['name']     ?? '',
        'role'     => $_SESSION['role']     ?? '',
        'store_id' => $_SESSION['store_id'] ?? null,
    ];
}

function generateBillNo($storeId = null) {
    $storeId = $storeId ?: currentStoreId();
    if (!$storeId) return 'BILL-' . date('ymd-His');
    $settings = getStoreSettings($storeId);
    $prefix = $settings['bill_prefix'] ?? 'ST';
    $db  = getDB();
    if (tableHasColumn($db, 'bills', 'store_id')) {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bills WHERE store_id = ?");
        $stmt->execute([(int)$storeId]);
    } else {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM bills");
    }
    $row = $stmt->fetch();
    $num = ($row['cnt'] ?? 0) + 1;
    return $prefix . '-' . date('ymd') . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

function generateQueueNo($storeId = null) {
    $storeId = $storeId ?: currentStoreId();
    if (!$storeId) return 'Q-' . date('ymd-His');
    $db = getDB();
    if (tableHasColumn($db, 'queue_orders', 'store_id')) {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM queue_orders WHERE store_id = ? AND DATE(created_at)=DATE('now')");
        $stmt->execute([(int)$storeId]);
    } else {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM queue_orders WHERE DATE(created_at)=DATE('now')");
    }
    $row = $stmt->fetch();
    $num = ($row['cnt'] ?? 0) + 1;
    return 'Q-' . date('ymd') . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function jsonError($msg, $code = 400) { jsonResponse(['success' => false, 'error' => $msg], $code); }
function jsonSuccess($data = [])      { jsonResponse(array_merge(['success' => true], $data)); }
