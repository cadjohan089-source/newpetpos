<?php
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();
startSession();

$storeId = (int)($_POST['store_id'] ?? 0);
if ($storeId > 0) {
    $store = getStore($storeId);
    if ($store) {
        setCurrentStore($storeId);
    }
} else {
    unset($_SESSION['store_id']);
}

$redirect = $_POST['redirect'] ?? 'admin/stores.php';
header('Location: ' . baseUrl($redirect));
exit;
