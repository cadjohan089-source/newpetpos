<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$storeId = currentStoreId();
if (!$storeId) jsonError('No store selected', 403);

if ($method === 'GET') {
    $billNo = $_GET['bill_no'] ?? '';
    if (!$billNo) jsonError('Bill number required');

    $stmt = $db->prepare("SELECT * FROM bills WHERE bill_no = ? AND store_id = ?");
    $stmt->execute([$billNo, $storeId]);
    $bill = $stmt->fetch();

    if (!$bill) jsonError('Bill not found', 404);

    $stmt2 = $db->prepare("SELECT * FROM bill_items WHERE bill_id = ?");
    $stmt2->execute([$bill['id']]);
    $items = $stmt2->fetchAll();

    $bill['items'] = array_map(function($item) {
        return [
            'id' => $item['product_id'],
            'name' => $item['product_name'],
            'price' => (float)$item['price'],
            'quantity' => (int)$item['quantity'],
            'qty' => (int)$item['quantity'],
            'subtotal' => (float)$item['subtotal']
        ];
    }, $items);

    $bill['created_at'] = date('d M Y, h:i A', strtotime($bill['created_at']));
    $bill['paid_amount'] = (float)($bill['paid_amount'] ?? $bill['total']);
    $bill['due_amount'] = (float)($bill['due_amount'] ?? 0);
    $bill['payment_status'] = $bill['payment_status'] ?? 'paid';
    jsonSuccess(['bill' => $bill]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) jsonError('Invalid JSON');

    $items = $data['items'] ?? [];
    if (empty($items)) jsonError('No items in cart');

    $db->beginTransaction();
    try {
        foreach ($items as $it) {
            $productId = (int)($it['product_id'] ?? $it['id'] ?? 0);
            $qty = (int)($it['quantity'] ?? $it['qty'] ?? 1);
            if (!$productId) continue;

            $st = $db->prepare("SELECT stock_qty, name FROM products WHERE id = ? AND store_id = ? AND available = 1");
            $st->execute([$productId, $storeId]);
            $prod = $st->fetch();
            if (!$prod) throw new Exception("Product not available");
            if ((int)$prod['stock_qty'] < $qty) {
                throw new Exception("Insufficient stock for " . $prod['name'] . " (available: " . $prod['stock_qty'] . ")");
            }
        }

        $billNo = generateBillNo($storeId);

        $customerName = trim($data['customer_name'] ?? '') ?: 'Walk-in';
        $customerPhone = trim($data['customer_phone'] ?? '');
        $total = (float)($data['total'] ?? 0);

        // paid_amount: if not supplied, assume fully paid (backwards compatible with old clients)
        $paidAmount = isset($data['paid_amount']) ? (float)$data['paid_amount'] : $total;
        if ($paidAmount < 0) $paidAmount = 0;
        if ($paidAmount > $total) $paidAmount = $total;
        $dueAmount = round($total - $paidAmount, 2);

        $paymentStatus = 'paid';
        if ($dueAmount > 0.009) {
            $paymentStatus = $paidAmount > 0.009 ? 'partial' : 'unpaid';
        }

        // Only link a customer record when there is something to track (a real name and/or an outstanding balance)
        $customerId = null;
        if ($customerName !== 'Walk-in' && ($customerPhone !== '' || $dueAmount > 0.009)) {
            $customerId = findOrCreateCustomer($storeId, $customerName, $customerPhone);
        }

        $stmt = $db->prepare("INSERT INTO bills (store_id, bill_no, customer_name, customer_id, table_no, subtotal, tax_amount, discount, total, paid_amount, due_amount, payment_status, payment_method) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $storeId,
            $billNo,
            $customerName,
            $customerId,
            $data['table_no'] ?? '-',
            $data['subtotal'] ?? 0,
            $data['tax_amount'] ?? 0,
            $data['discount'] ?? 0,
            $total,
            $paidAmount,
            $dueAmount,
            $paymentStatus,
            $data['payment_method'] ?? 'Cash',
        ]);

        $billId = $db->lastInsertId();
        $si = $db->prepare("INSERT INTO bill_items (bill_id, product_id, product_name, price, quantity, subtotal) VALUES (?,?,?,?,?,?)");
        $stockUpdate = $db->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND store_id = ?");

        foreach ($items as $it) {
            $productId = $it['product_id'] ?? $it['id'] ?? null;
            $productName = $it['name'] ?? $it['product_name'] ?? 'Unknown';
            $price = $it['price'] ?? 0;
            $quantity = (int)($it['quantity'] ?? $it['qty'] ?? 1);
            $subtotal = $price * $quantity;

            $si->execute([$billId, $productId, $productName, $price, $quantity, $subtotal]);

            if ($productId) {
                $stockUpdate->execute([$quantity, $productId, $storeId]);
            }
        }

        $db->commit();
        jsonSuccess(['bill_no' => $billNo, 'bill_id' => $billId, 'due_amount' => $dueAmount, 'payment_status' => $paymentStatus]);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError($e->getMessage(), 400);
    }
}

if ($method === 'PUT') {
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    $billId = $data['id'] ?? null;
    if (!$billId) jsonError('Bill ID required');

    $stmt = $db->prepare("UPDATE bills SET customer_name=?, table_no=?, notes=? WHERE id=? AND store_id=?");
    $stmt->execute([
        $data['customer_name'] ?? 'Walk-in',
        $data['table_no'] ?? '-',
        $data['notes'] ?? '',
        $billId,
        $storeId
    ]);
    jsonSuccess();
}

if ($method === 'DELETE') {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) jsonError('ID required');

    $db->prepare("DELETE FROM bills WHERE id=? AND store_id=?")->execute([$id, $storeId]);
    jsonSuccess();
}
