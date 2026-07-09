<?php
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($data['product_id'] ?? 0);
    $quantity = (int)($data['quantity'] ?? 0);
    $logDate = trim($data['log_date'] ?? date('Y-m-d'));
    $notes = trim($data['notes'] ?? '');
    $storeId = (int)($data['store_id'] ?? 0);

    if (!$productId || $quantity <= 0) jsonError('Product and positive quantity required');
    if (!$storeId) jsonError('Store ID required');

    $st = $db->prepare("SELECT id FROM products WHERE id = ? AND store_id = ?");
    $st->execute([$productId, $storeId]);
    if (!$st->fetch()) jsonError('Product not found in this store', 404);

    addStockLog($storeId, $productId, $quantity, $logDate, $notes, currentUser()['id']);
    jsonSuccess(['message' => 'Stock updated']);
}

if ($method === 'GET') {
    $productId = (int)($_GET['product_id'] ?? 0);
    $storeId = (int)($_GET['store_id'] ?? currentStoreId() ?? 0);
    if (!$productId) jsonError('product_id required');
    if (!$storeId) jsonError('store_id required');

    $st = $db->prepare("SELECT sl.*, u.name as user_name FROM stock_logs sl LEFT JOIN users u ON sl.created_by = u.id WHERE sl.product_id = ? AND sl.store_id = ? ORDER BY sl.log_date DESC, sl.id DESC LIMIT 50");
    $st->execute([$productId, $storeId]);
    jsonSuccess(['logs' => $st->fetchAll()]);
}

jsonError('Method not allowed', 405);