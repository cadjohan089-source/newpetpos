<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$storeId = currentStoreId();
if (!$storeId) jsonError('No store selected', 403);

if ($method === 'GET') {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $category = trim($_GET['category'] ?? '');

    $where = ['store_id = ?', 'expense_date >= ?', 'expense_date <= ?'];
    $params = [$storeId, $dateFrom, $dateTo];
    if ($category) { $where[] = 'category = ?'; $params[] = $category; }
    $whereSql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT * FROM expenses WHERE $whereSql ORDER BY expense_date DESC, id DESC");
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();

    $totalStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE $whereSql");
    $totalStmt->execute($params);
    $total = (float)$totalStmt->fetchColumn();

    $monthStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE store_id = ? AND strftime('%Y-%m', expense_date) = strftime('%Y-%m','now')");
    $monthStmt->execute([$storeId]);
    $thisMonth = (float)$monthStmt->fetchColumn();

    jsonSuccess(['expenses' => $expenses, 'total' => $total, 'this_month_total' => $thisMonth]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $category = trim($data['category'] ?? 'Other') ?: 'Other';
    $description = trim($data['description'] ?? '');
    $amount = (float)($data['amount'] ?? 0);
    $date = $data['expense_date'] ?? date('Y-m-d');

    if ($amount <= 0) jsonError('Enter a valid amount');

    $user = currentUser();
    $db->prepare("INSERT INTO expenses (store_id, category, description, amount, expense_date, created_by) VALUES (?,?,?,?,?,?)")
       ->execute([$storeId, $category, $description, $amount, $date, $user['id']]);

    jsonSuccess(['id' => $db->lastInsertId()]);
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('ID required');

    $category = trim($data['category'] ?? 'Other') ?: 'Other';
    $description = trim($data['description'] ?? '');
    $amount = (float)($data['amount'] ?? 0);
    $date = $data['expense_date'] ?? date('Y-m-d');
    if ($amount <= 0) jsonError('Enter a valid amount');

    $stmt = $db->prepare("UPDATE expenses SET category=?, description=?, amount=?, expense_date=? WHERE id=? AND store_id=?");
    $stmt->execute([$category, $description, $amount, $date, $id, $storeId]);
    jsonSuccess();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('ID required');
    $db->prepare("DELETE FROM expenses WHERE id=? AND store_id=?")->execute([$id, $storeId]);
    jsonSuccess();
}

jsonError('Method not allowed', 405);
