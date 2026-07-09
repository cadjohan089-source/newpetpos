<?php
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

// Read input once
$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true) ?: [];

// Super admin can operate on any store via store_id parameter
$storeId = currentStoreId();
if (!$storeId) {
    // Super admin without store context — use store_id from request data
    if ($method === 'DELETE') {
        $storeId = 0; // Super admin can delete from any store
    } else {
        $storeId = (int)($inputData['store_id'] ?? 0);
    }
}

if (!$storeId && $method !== 'DELETE') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No store selected']);
    exit;
}

try {
    if ($method === 'POST') {
        $data = $inputData;

        // Handle category auto-creation request
        if (isset($data['action']) && $data['action'] === 'create_category') {
            $catStoreId = (int)($data['store_id'] ?? 0);
            $catName = trim($data['name'] ?? '');
            if (!$catStoreId || !$catName) {
                echo json_encode(['success' => false, 'error' => 'Store and name required']);
                exit;
            }
            // Check if category already exists
            $existing = $db->prepare("SELECT id FROM categories WHERE store_id = ? AND LOWER(name) = LOWER(?)");
            $existing->execute([$catStoreId, $catName]);
            $existingRow = $existing->fetch();
            if ($existingRow) {
                echo json_encode(['success' => true, 'id' => (int)$existingRow['id']]);
                exit;
            }
            $stmt = $db->prepare("INSERT INTO categories (store_id, name) VALUES (?,?)");
            $stmt->execute([$catStoreId, $catName]);
            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
            exit;
        }

        $name = trim($data['name'] ?? '');
        $barcode = trim($data['barcode'] ?? '');
        $price = (float)($data['price'] ?? 0);
        $buyPrice = (float)($data['buy_price'] ?? 0);
        $stockQty = (int)($data['stock_qty'] ?? 0);
        $catId = (int)($data['category_id'] ?? 0);
        $reqStoreId = (int)($data['store_id'] ?? $storeId);

        if (!$name || $price < 0 || !$catId || !$reqStoreId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid data — store, name, price, and category required']);
            exit;
        }

        $checkStmt = $db->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) AND category_id = ? AND store_id = ?");
        $checkStmt->execute([$name, $catId, $reqStoreId]);

        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => "Product '{$name}' already exists in this category"]);
            exit;
        }

        if ($barcode !== '') {
            $bcCheck = $db->prepare("SELECT id FROM products WHERE barcode = ? AND store_id = ?");
            $bcCheck->execute([$barcode, $reqStoreId]);
            if ($bcCheck->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => "Barcode '{$barcode}' is already used by another product in this store"]);
                exit;
            }
        }

        $stmt = $db->prepare("INSERT INTO products (store_id, name, barcode, price, buy_price, stock_qty, category_id, available) VALUES (?,?,?,?,?,?,?,1)");
        $stmt->execute([$reqStoreId, $name, $barcode, $price, $buyPrice, $stockQty, $catId]);

        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    }
    else if ($method === 'PUT') {
        $data = $inputData;
        $id = (int)($data['id'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }

        // Super admin can update any product
        $currentStmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $currentStmt->execute([$id]);
        $current = $currentStmt->fetch();

        if (!$current) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Product not found']);
            exit;
        }

        // Use the product's own store_id for uniqueness check
        $productStoreId = (int)($data['store_id'] ?? $current['store_id']);

        if (isset($data['name']) || isset($data['category_id'])) {
            $newName = $data['name'] ?? $current['name'];
            $newCatId = $data['category_id'] ?? $current['category_id'];

            $checkStmt = $db->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) AND category_id = ? AND store_id = ? AND id != ?");
            $checkStmt->execute([$newName, $newCatId, $productStoreId, $id]);

            if ($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => "Another product named '{$newName}' already exists in this category"]);
                exit;
            }
        }

        if (isset($data['barcode']) && trim($data['barcode']) !== '') {
            $newBarcode = trim($data['barcode']);
            $bcCheck = $db->prepare("SELECT id FROM products WHERE barcode = ? AND store_id = ? AND id != ?");
            $bcCheck->execute([$newBarcode, $productStoreId, $id]);
            if ($bcCheck->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => "Barcode '{$newBarcode}' is already used by another product in this store"]);
                exit;
            }
        }

        $fields = [];
        $params = [];

        foreach (['name','barcode','price','buy_price','stock_qty','category_id','available','store_id'] as $field) {
            if (!isset($data[$field])) continue;
            if ($field === 'name') { $fields[] = 'name=?'; $params[] = trim($data['name']); }
            elseif ($field === 'barcode') { $fields[] = 'barcode=?'; $params[] = trim($data['barcode']); }
            elseif ($field === 'price') { $fields[] = 'price=?'; $params[] = (float)$data['price']; }
            elseif ($field === 'buy_price') { $fields[] = 'buy_price=?'; $params[] = (float)$data['buy_price']; }
            elseif ($field === 'stock_qty') { $fields[] = 'stock_qty=?'; $params[] = (int)$data['stock_qty']; }
            elseif ($field === 'category_id') { $fields[] = 'category_id=?'; $params[] = (int)$data['category_id']; }
            elseif ($field === 'available') { $fields[] = 'available=?'; $params[] = (int)$data['available'] ? 1 : 0; }
            elseif ($field === 'store_id') { $fields[] = 'store_id=?'; $params[] = (int)$data['store_id']; }
        }

        if (!$fields) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nothing to update']);
            exit;
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE products SET " . implode(',', $fields) . " WHERE id=?");
        $stmt->execute($params);

        echo json_encode(['success' => true]);
    }
    else if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }

        $checkStmt = $db->prepare("SELECT COUNT(*) FROM bill_items WHERE product_id = ?");
        $checkStmt->execute([$id]);
        $usageCount = $checkStmt->fetchColumn();

        if ($usageCount > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => "Cannot delete: used in {$usageCount} bill(s). Disable instead."]);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM products WHERE id=?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
    }
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error occurred']);
}