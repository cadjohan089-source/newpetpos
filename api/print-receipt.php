<?php
/**
 * api/print-receipt.php
 *
 * Builds ESC/POS bytes for a customer receipt and returns them as base64.
 * Frontend (QZ Tray) sends these bytes directly to the local thermal printer.
 *
 * Usage:  GET api/print-receipt.php?bill_no=SG-260507-0005
 * Response: { success: true, base64: "...", printer: "BC-80POS", drawer_kick: false }
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\EscposImage;

header('Content-Type: application/json');

$billNo = $_GET['bill_no'] ?? '';
if (!$billNo) jsonError('bill_no required');

$db = getDB();
$storeId = currentStoreId();
if (!$storeId) jsonError('No store selected', 403);

$stmt = $db->prepare("SELECT * FROM bills WHERE bill_no = ? AND store_id = ?");
$stmt->execute([$billNo, $storeId]);
$bill = $stmt->fetch();
if (!$bill) jsonError('Bill not found', 404);

$s = getStoreSettings($bill['store_id']);

$stmt2 = $db->prepare("SELECT * FROM bill_items WHERE bill_id = ?");
$stmt2->execute([$bill['id']]);
$items = $stmt2->fetchAll();

// Build ESC/POS bytes into a DummyPrintConnector
$connector = new DummyPrintConnector();
$printer   = new Printer($connector);

try {
    $cur         = $s['currency'] ?? 'Rs';
    $paperWidth  = (int)($s['printer_paper_width'] ?? 80);
    $charsPerLine = ($paperWidth === 58) ? 32 : 48; // standard ESC/POS column counts
    $drawerKick  = ($s['printer_drawer_kick'] ?? '0') === '1';
    $paymentMethod = $bill['payment_method'] ?? 'Cash';

    // ── HEADER ──
    $printer->initialize();
    $printer->setJustification(Printer::JUSTIFY_CENTER);

    // Restaurant name — big bold
    $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH | Printer::MODE_EMPHASIZED);
    $printer->text(($s['restaurant_name'] ?? 'Restaurant') . "\n");
    $printer->selectPrintMode(); // reset

    // Address & phone
    if (!empty($s['restaurant_address'])) {
        $printer->text($s['restaurant_address'] . "\n");
    }
    if (!empty($s['restaurant_phone'])) {
        $printer->text("Tel: " . $s['restaurant_phone'] . "\n");
    }

    $printer->feed(1);
    $printer->text(str_repeat('-', $charsPerLine) . "\n");

    // ── BILL META ──
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $createdAt = $bill['created_at'] ?? date('Y-m-d H:i:s');
    $printer->text(twoCol("Bill #", $bill['bill_no'], $charsPerLine) . "\n");
    $printer->text(twoCol("Date", date('d M Y, h:i A', strtotime($createdAt)), $charsPerLine) . "\n");
    $printer->text(twoCol("Customer", $bill['customer_name'] ?: 'Walk-in', $charsPerLine) . "\n");
    $printer->text(twoCol("Payment", $paymentMethod, $charsPerLine) . "\n");

    $printer->text(str_repeat('-', $charsPerLine) . "\n");

    // ── ITEMS ──
    foreach ($items as $it) {
        $name  = $it['product_name'] ?? 'Item';
        $qty   = (int)($it['quantity'] ?? 1);
        $total = (float)($it['subtotal'] ?? 0);

        $left  = $name . ' x ' . $qty;
        $right = $cur . ' ' . number_format($total, 0);

        // If left+right fits one line, do it; else wrap name on its own line
        if (mb_strlen($left) + mb_strlen($right) + 1 <= $charsPerLine) {
            $printer->text(twoCol($left, $right, $charsPerLine) . "\n");
        } else {
            // Wrap name; price on next line right-aligned
            foreach (wordWrapLines($left, $charsPerLine) as $line) {
                $printer->text($line . "\n");
            }
            $printer->text(twoCol("", $right, $charsPerLine) . "\n");
        }
    }

    $printer->text(str_repeat('-', $charsPerLine) . "\n");

    // ── TOTALS ──
    $sub  = (float)($bill['subtotal'] ?? 0);
    $tax  = (float)($bill['tax_amount'] ?? 0);
    $disc = (float)($bill['discount'] ?? 0);
    $tot  = (float)($bill['total'] ?? 0);

    $printer->text(twoCol("Subtotal", $cur . ' ' . number_format($sub, 0), $charsPerLine) . "\n");
    $printer->text(twoCol("Tax",      $cur . ' ' . number_format($tax, 0), $charsPerLine) . "\n");
    if ($disc > 0) {
        $printer->text(twoCol("Discount", "- " . $cur . ' ' . number_format($disc, 0), $charsPerLine) . "\n");
    }

    $printer->text(str_repeat('=', $charsPerLine) . "\n");

    // TOTAL — bold and double-height
    $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
    $printer->text(twoCol("TOTAL", $cur . ' ' . number_format($tot, 0), $charsPerLine) . "\n");
    $printer->selectPrintMode();

    $printer->text(str_repeat('=', $charsPerLine) . "\n");

    // ── FOOTER ──
    $printer->feed(1);
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    if (!empty($s['receipt_footer'])) {
        $printer->text($s['receipt_footer'] . "\n");
    }
    $printer->feed(2);

    // Drawer kick (only on cash sales)
    if ($drawerKick && stripos($paymentMethod, 'cash') !== false) {
        $printer->pulse();
    }

    // Cut paper
    $printer->cut();

    // Get the raw ESC/POS bytes
    $data = $connector->getData();
    $printer->close();

    jsonSuccess([
        'base64'       => base64_encode($data),
        'printer'      => $s['printer_name'] ?? 'BC-80POS',
        'paper_width'  => $paperWidth,
        'bill_no'      => $billNo,
        'drawer_kick'  => $drawerKick,
        'size_bytes'   => strlen($data),
    ]);

} catch (Exception $e) {
    @$printer->close();
    jsonError('Receipt build failed: ' . $e->getMessage(), 500);
}

// ─────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────

/** Two-column row: left text + right text, padded with spaces to fill $width chars. */
function twoCol($left, $right, $width) {
    $left  = (string)$left;
    $right = (string)$right;
    $lLen = mb_strlen($left);
    $rLen = mb_strlen($right);
    $space = $width - $lLen - $rLen;
    if ($space < 1) $space = 1;
    return $left . str_repeat(' ', $space) . $right;
}

/** Word-wrap a string into an array of lines that each fit within $width. */
function wordWrapLines($text, $width) {
    return explode("\n", wordwrap($text, $width, "\n", true));
}
