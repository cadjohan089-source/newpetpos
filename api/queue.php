<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$storeId = currentStoreId();
if (!$storeId) jsonError('No store selected', 403);

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $db->prepare("SELECT * FROM queue_orders WHERE id = ? AND store_id = ?");
        $stmt->execute([$id, $storeId]);
        $queue = $stmt->fetch();
        if (!$queue) jsonError('Queue not found', 404);
        $stmt2 = $db->prepare("SELECT * FROM queue_items WHERE queue_id = ? ORDER BY id");
        $stmt2->execute([$id]);
        $queue['items'] = $stmt2->fetchAll();
        jsonSuccess(['queue' => $queue]);
    }

    $status = $_GET['status'] ?? 'pending';
    $stmt = $db->prepare("SELECT * FROM queue_orders WHERE status = ? AND store_id = ? ORDER BY created_at ASC");
    $stmt->execute([$status, $storeId]);
    $queues = $stmt->fetchAll();
    foreach ($queues as &$q) {
        $stmt2 = $db->prepare("SELECT * FROM queue_items WHERE queue_id = ? ORDER BY id");
        $stmt2->execute([$q['id']]);
        $q['items'] = $stmt2->fetchAll();
    }
    jsonSuccess(['queues' => $queues, 'count' => count($queues)]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) jsonError('Invalid JSON');
    $items = $data['items'] ?? [];
    if (empty($items)) jsonError('No items');

    $db->beginTransaction();
    try {
        $queueNo = generateQueueNo($storeId);
        $stmt = $db->prepare("INSERT INTO queue_orders (store_id, queue_no, table_no, note, status) VALUES (?,?,?,?,'pending')");
        $stmt->execute([$storeId, $queueNo, $data['table_no'] ?? '-', $data['note'] ?? '']);
        $queueId = $db->lastInsertId();

        $si = $db->prepare("INSERT INTO queue_items (queue_id, product_id, product_name, quantity, price) VALUES (?,?,?,?,?)");
        foreach ($items as $it) {
            $si->execute([
                $queueId,
                $it['product_id'] ?? $it['id'] ?? null,
                $it['name'] ?? $it['product_name'] ?? 'Unknown',
                $it['qty'] ?? $it['quantity'] ?? 1,
                $it['price'] ?? 0,
            ]);
        }
        $db->commit();
        jsonSuccess(['queue_no' => $queueNo, 'queue_id' => $queueId]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('DB error: ' . $e->getMessage(), 500);
    }
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = $data['id'] ?? null;
    if (!$id) jsonError('Queue ID required');

    if (!empty($data['convert_to_bill'])) {
        $stmt = $db->prepare("SELECT * FROM queue_orders WHERE id=? AND store_id=?");
        $stmt->execute([$id, $storeId]);
        $queue = $stmt->fetch();
        if (!$queue) jsonError('Queue not found');

        $stmt2 = $db->prepare("SELECT * FROM queue_items WHERE queue_id=?");
        $stmt2->execute([$id]);
        $qItems = $stmt2->fetchAll();

        $billItems = [];
        $providedItems = $data['items'] ?? [];
        foreach ($qItems as $qi) {
            $price = 0;
            foreach ($providedItems as $pi) {
                if (($pi['queue_item_id'] ?? null) == $qi['id']) {
                    $price = (float)($pi['price'] ?? 0);
                    break;
                }
            }
            if (!$price) $price = (float)($qi['price'] ?? 0);

            $billItems[] = [
                'product_id' => $qi['product_id'],
                'name'       => $qi['product_name'],
                'price'      => $price,
                'qty'        => (int)$qi['quantity'],
                'quantity'   => (int)$qi['quantity'],
                'subtotal'   => $price * (int)$qi['quantity'],
            ];
        }

        foreach ($billItems as $bi) {
            if (!$bi['product_id']) continue;
            $st = $db->prepare("SELECT stock_qty, name FROM products WHERE id = ? AND store_id = ?");
            $st->execute([$bi['product_id'], $storeId]);
            $prod = $st->fetch();
            if ($prod && (int)$prod['stock_qty'] < $bi['quantity']) {
                jsonError("Insufficient stock for " . $prod['name'], 400);
            }
        }

        $storeSettings = getStoreSettings($storeId);
        $taxRate  = (float)($storeSettings['tax_rate'] ?? 5);
        $subtotal = array_sum(array_column($billItems, 'subtotal'));
        $tax      = round($subtotal * $taxRate / 100);
        $discount = (float)($data['discount'] ?? 0);
        $total    = max(0, $subtotal + $tax - $discount);

        $db->beginTransaction();
        try {
            $billNo = generateBillNo($storeId);
            $stmt3  = $db->prepare("INSERT INTO bills (store_id, bill_no, customer_name, table_no, subtotal, tax_amount, discount, total, payment_method) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt3->execute([
                $storeId, $billNo,
                $data['customer_name'] ?? 'Walk-in',
                $queue['table_no'],
                $subtotal, $tax, $discount, $total,
                $data['payment_method'] ?? 'Cash',
            ]);
            $billId = $db->lastInsertId();

            $si = $db->prepare("INSERT INTO bill_items (bill_id, product_id, product_name, price, quantity, subtotal) VALUES (?,?,?,?,?,?)");
            $stockUpdate = $db->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND store_id = ?");
            foreach ($billItems as $bi) {
                $si->execute([$billId, $bi['product_id'], $bi['name'], $bi['price'], $bi['quantity'], $bi['subtotal']]);
                if ($bi['product_id']) {
                    $stockUpdate->execute([$bi['quantity'], $bi['product_id'], $storeId]);
                }
            }

            $db->prepare("UPDATE queue_orders SET status='converted' WHERE id=?")->execute([$id]);
            $db->commit();
            jsonSuccess(['bill_no' => $billNo, 'bill_id' => $billId]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonError('Convert error: ' . $e->getMessage(), 500);
        }
    }

    $db->prepare("UPDATE queue_orders SET table_no=?, note=? WHERE id=? AND store_id=?")
       ->execute([$data['table_no'] ?? '-', $data['note'] ?? '', $id, $storeId]);

    if (!empty($data['items'])) {
        $db->prepare("DELETE FROM queue_items WHERE queue_id=?")->execute([$id]);
        $si = $db->prepare("INSERT INTO queue_items (queue_id, product_id, product_name, quantity, price) VALUES (?,?,?,?,?)");
        foreach ($data['items'] as $it) {
            $si->execute([
                $id,
                $it['product_id'] ?? $it['id'] ?? null,
                $it['name'] ?? $it['product_name'] ?? 'Unknown',
                $it['qty'] ?? $it['quantity'] ?? 1,
                $it['price'] ?? 0,
            ]);
        }
    }
    jsonSuccess();
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) jsonError('ID required');
    $db->prepare("DELETE FROM queue_orders WHERE id=? AND store_id=?")->execute([$id, $storeId]);
    jsonSuccess();
}
