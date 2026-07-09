<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$storeId = currentStoreId();
if (!$storeId) jsonError('No store selected', 403);

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if ($id) {
        // Single customer detail: bill history + payment history + balance
        $st = $db->prepare("SELECT * FROM customers WHERE id = ? AND store_id = ?");
        $st->execute([$id, $storeId]);
        $customer = $st->fetch();
        if (!$customer) jsonError('Customer not found', 404);

        $bst = $db->prepare("SELECT id, bill_no, total, paid_amount, due_amount, payment_status, created_at FROM bills WHERE customer_id = ? AND store_id = ? ORDER BY created_at DESC");
        $bst->execute([$id, $storeId]);
        $bills = $bst->fetchAll();

        $pst = $db->prepare("SELECT id, amount, note, created_at FROM customer_payments WHERE customer_id = ? AND store_id = ? ORDER BY created_at DESC");
        $pst->execute([$id, $storeId]);
        $payments = $pst->fetchAll();

        $balance = getCustomerBalance($id);

        jsonSuccess([
            'customer' => $customer,
            'bills' => $bills,
            'payments' => $payments,
            'balance' => $balance,
        ]);
    }

    // List all customers with aggregated balances
    $sort = $_GET['sort'] ?? 'due';
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to'] ?? '');

    // Date filter is applied inside the JOIN's ON clause (not WHERE) so that
    // customers with no bills in the selected range still appear in the list
    // (with zero billed/paid/due for that period) instead of disappearing.
    $joinCond = "b.customer_id = c.id";
    $joinParams = [];
    if ($dateFrom !== '') { $joinCond .= " AND DATE(b.created_at) >= ?"; $joinParams[] = $dateFrom; }
    if ($dateTo   !== '') { $joinCond .= " AND DATE(b.created_at) <= ?"; $joinParams[] = $dateTo; }

    // Extra standalone payments (customer_payments) aren't tied to a specific bill date,
    // so when a date range is active we only count payments made within that range too —
    // otherwise "Total Paid" for a filtered period could include unrelated payments.
    $payCond = "cp.customer_id = c.id";
    $payParams = [];
    if ($dateFrom !== '') { $payCond .= " AND DATE(cp.created_at) >= ?"; $payParams[] = $dateFrom; }
    if ($dateTo   !== '') { $payCond .= " AND DATE(cp.created_at) <= ?"; $payParams[] = $dateTo; }

    $sql = "
        SELECT c.id, c.name, c.phone, c.created_at,
               COALESCE(SUM(b.total), 0) as billed,
               COALESCE(SUM(b.paid_amount), 0) as paid_on_bills,
               (SELECT COALESCE(SUM(amount),0) FROM customer_payments cp WHERE $payCond) as extra_paid,
               MAX(b.created_at) as last_bill_at
        FROM customers c
        LEFT JOIN bills b ON $joinCond AND b.store_id = ?
        WHERE c.store_id = ?
        GROUP BY c.id
    ";
    $params = array_merge($payParams, $joinParams, [$storeId, $storeId]);
    $rows = $db->prepare($sql);
    $rows->execute($params);
    $customers = $rows->fetchAll();

    $totalCredit = 0;
    foreach ($customers as &$c) {
        $paid = (float)$c['paid_on_bills'] + (float)$c['extra_paid'];
        $due = max(0, (float)$c['billed'] - $paid);
        $c['billed'] = (float)$c['billed'];
        $c['paid'] = $paid;
        $c['due'] = $due;
        $totalCredit += $due;
        unset($c['paid_on_bills'], $c['extra_paid']);
    }
    unset($c);

    // When filtering by date, hide customers with zero activity in that window
    // so the list only shows customers who actually had bills/payments in range.
    if ($dateFrom !== '' || $dateTo !== '') {
        $customers = array_values(array_filter($customers, fn($c) => $c['billed'] > 0.009 || $c['paid'] > 0.009));
    }

    if ($sort === 'name') {
        usort($customers, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    } else {
        usort($customers, fn($a, $b) => $b['due'] <=> $a['due']);
    }

    jsonSuccess(['customers' => $customers, 'total_credit' => $totalCredit]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $customerId = (int)($data['customer_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0);
    $note = trim($data['note'] ?? '');

    if (!$customerId) jsonError('Customer required');
    if ($amount <= 0) jsonError('Enter a valid payment amount');

    $st = $db->prepare("SELECT id FROM customers WHERE id = ? AND store_id = ?");
    $st->execute([$customerId, $storeId]);
    if (!$st->fetch()) jsonError('Customer not found', 404);

    $balance = getCustomerBalance($customerId);
    if ($amount > $balance['due'] + 0.01) {
        jsonError('Payment (' . $amount . ') exceeds outstanding due (' . $balance['due'] . ')');
    }

    $user = currentUser();
    $db->prepare("INSERT INTO customer_payments (store_id, customer_id, amount, note, created_by) VALUES (?,?,?,?,?)")
       ->execute([$storeId, $customerId, $amount, $note, $user['id']]);

    $newBalance = getCustomerBalance($customerId);
    jsonSuccess(['balance' => $newBalance]);
}

jsonError('Method not allowed', 405);
