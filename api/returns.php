<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$storeId = currentStoreId();
$user = currentUser();

if ($method === 'GET') {
    // List returns for this store
    if (!$storeId) jsonError('No store selected', 403);

    $returnId = (int)($_GET['id'] ?? 0);
    if ($returnId) {
        // Get single return with items
        $stmt = $db->prepare("SELECT r.*, b.bill_no, b.subtotal as bill_subtotal, b.tax_amount as bill_tax, b.discount as bill_discount, b.total as bill_total, u.name as created_by_name FROM returns r LEFT JOIN bills b ON r.bill_id = b.id LEFT JOIN users u ON r.created_by = u.id WHERE r.id = ? AND r.store_id = ?");
        $stmt->execute([$returnId, $storeId]);
        $return = $stmt->fetch();
        if (!$return) jsonError('Return not found', 404);

        $stmt2 = $db->prepare("SELECT ri.* FROM return_items ri WHERE ri.return_id = ?");
        $stmt2->execute([$returnId]);
        $return['items'] = $stmt2->fetchAll();
        jsonSuccess(['return' => $return]);
    }

    // Get bill items for return form
    $billId = (int)($_GET['bill_id'] ?? 0);
    if ($billId) {
        $stmt = $db->prepare("SELECT * FROM bills WHERE id = ? AND store_id = ?");
        $stmt->execute([$billId, $storeId]);
        $bill = $stmt->fetch();
        if (!$bill) jsonError('Bill not found', 404);

        // Get bill items with already-returned quantities
        $stmt2 = $db->prepare("SELECT bi.*, COALESCE(sr.returned_qty, 0) as returned_qty FROM bill_items bi LEFT JOIN (SELECT bill_item_id, SUM(quantity) as returned_qty FROM return_items ri JOIN returns r ON ri.return_id = r.id WHERE r.bill_id = ? GROUP BY bill_item_id) sr ON sr.bill_item_id = bi.id WHERE bi.bill_id = ?");
        $stmt2->execute([$billId, $billId]);
        $items = $stmt2->fetchAll();

        // Calculate returnable qty for each item
        foreach ($items as &$item) {
            $item['returnable_qty'] = max(0, (int)$item['quantity'] - (int)$item['returned_qty']);
        }
        unset($item);

        // Send bill totals so frontend can calculate proportional refund
        jsonSuccess([
            'bill' => $bill,
            'items' => $items,
            'bill_subtotal' => (float)$bill['subtotal'],
            'bill_tax' => (float)$bill['tax_amount'],
            'bill_discount' => (float)$bill['discount'],
            'bill_total' => (float)$bill['total'],
        ]);
    }

    // List all returns
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['q'] ?? '');
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    $where = ['r.store_id = ?'];
    $params = [$storeId];
    if ($search) {
        $where[] = "(r.return_no LIKE ? OR b.bill_no LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    if ($dateFrom) { $where[] = "DATE(r.created_at) >= ?"; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = "DATE(r.created_at) <= ?"; $params[] = $dateTo; }
    $whereStr = implode(' AND ', $where);

    $total = $db->prepare("SELECT COUNT(*) FROM returns r LEFT JOIN bills b ON r.bill_id = b.id WHERE $whereStr");
    $total->execute($params);
    $total = $total->fetchColumn();

    $stmt = $db->prepare("SELECT r.*, b.bill_no, u.name as created_by_name FROM returns r LEFT JOIN bills b ON r.bill_id = b.id LEFT JOIN users u ON r.created_by = u.id WHERE $whereStr ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $returns = $stmt->fetchAll();

    jsonSuccess(['returns' => $returns, 'total' => (int)$total, 'page' => $page, 'pages' => ceil($total / $limit)]);
}

if ($method === 'POST') {
    // Create a return
    if (!$storeId) jsonError('No store selected', 403);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) jsonError('Invalid JSON');

    $billId = (int)($data['bill_id'] ?? 0);
    if (!$billId) jsonError('Bill ID required');

    $items = $data['items'] ?? [];
    if (empty($items)) jsonError('No items to return');

    $reason = trim($data['reason'] ?? '');

    // Verify bill exists and belongs to this store
    $stmt = $db->prepare("SELECT * FROM bills WHERE id = ? AND store_id = ?");
    $stmt->execute([$billId, $storeId]);
    $bill = $stmt->fetch();
    if (!$bill) jsonError('Bill not found', 404);

    $billSubtotal = (float)$bill['subtotal'];
    $billTax = (float)$bill['tax_amount'];
    $billDiscount = (float)$bill['discount'];
    $billTotal = (float)$bill['total'];

    $db->beginTransaction();
    try {
        // Generate return number
        $prefix = 'RT';
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM returns WHERE store_id = ?");
        $cntStmt->execute([$storeId]);
        $cnt = (int)$cntStmt->fetchColumn() + 1;
        $returnNo = $prefix . '-' . date('ymd') . '-' . str_pad($cnt, 4, '0', STR_PAD_LEFT);

        $totalRefund = 0;

        // Validate items first
        foreach ($items as $it) {
            $billItemId = (int)($it['bill_item_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            if ($qty <= 0) continue;

            $biStmt = $db->prepare("SELECT bi.*, COALESCE(sr.returned_qty, 0) as returned_qty FROM bill_items bi LEFT JOIN (SELECT bill_item_id, SUM(quantity) as returned_qty FROM return_items ri JOIN returns r ON ri.return_id = r.id WHERE r.bill_id = ? GROUP BY bill_item_id) sr ON sr.bill_item_id = bi.id WHERE bi.id = ? AND bi.bill_id = ?");
            $biStmt->execute([$billId, $billItemId, $billId]);
            $bi = $biStmt->fetch();
            if (!$bi) throw new Exception("Bill item not found");

            $returnableQty = (int)$bi['quantity'] - (int)$bi['returned_qty'];
            if ($qty > $returnableQty) {
                throw new Exception("Cannot return " . $qty . " of " . $bi['product_name'] . " — only " . $returnableQty . " returnable");
            }
        }

        // Create return record (total_refund will be updated after calculating items)
        $insReturn = $db->prepare("INSERT INTO returns (store_id, bill_id, return_no, reason, total_refund, status, created_by) VALUES (?,?,?,?,?,?,?)");
        $insReturn->execute([$storeId, $billId, $returnNo, $reason, 0, 'completed', $user['id']]);
        $returnId = $db->lastInsertId();

        // Create return items with proportional discount/tax
        $insItem = $db->prepare("INSERT INTO return_items (return_id, bill_item_id, product_id, product_name, price, quantity, subtotal, discount_share, tax_share, refund_amount) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $updateStock = $db->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ? AND store_id = ?");

        foreach ($items as $it) {
            $billItemId = (int)($it['bill_item_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            if ($qty <= 0) continue;

            $biStmt = $db->prepare("SELECT * FROM bill_items WHERE id = ? AND bill_id = ?");
            $biStmt->execute([$billItemId, $billId]);
            $bi = $biStmt->fetch();
            if (!$bi) continue;

            $itemSubtotal = $bi['price'] * $qty;  // Pre-discount amount

            // Calculate proportional discount and tax for this return item
            // Proportion = (item return subtotal) / (bill subtotal)
            // If bill subtotal is 0, fallback to no discount/tax
            $discountShare = 0;
            $taxShare = 0;
            if ($billSubtotal > 0) {
                $ratio = $itemSubtotal / $billSubtotal;
                $discountShare = round($billDiscount * $ratio, 2);
                $taxShare = round($billTax * $ratio, 2);
            }

            // Effective refund = item subtotal + proportional tax - proportional discount
            $refundAmount = $itemSubtotal + $taxShare - $discountShare;

            $insItem->execute([$returnId, $billItemId, $bi['product_id'], $bi['product_name'], $bi['price'], $qty, $itemSubtotal, $discountShare, $taxShare, $refundAmount]);

            $totalRefund += $refundAmount;

            // Restore stock
            if ($bi['product_id']) {
                $updateStock->execute([$qty, $bi['product_id'], $storeId]);
            }
        }

        // Update return record with actual total refund
        $db->prepare("UPDATE returns SET total_refund = ? WHERE id = ?")->execute([round($totalRefund, 2), $returnId]);

        $db->commit();
        jsonSuccess(['return_no' => $returnNo, 'return_id' => $returnId, 'total_refund' => round($totalRefund, 2)]);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError($e->getMessage(), 400);
    }
}

jsonError('Method not allowed', 405);