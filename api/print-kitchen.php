<?php
/**
 * api/print-kitchen.php
 *
 * Builds ESC/POS bytes for a kitchen order slip and returns them as base64.
 * Frontend (QZ Tray) sends these bytes directly to the local thermal printer.
 *
 * Usage:  GET api/print-kitchen.php?queue_id=123
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;

header('Content-Type: application/json');

$queueId = $_GET['queue_id'] ?? '';
if (!$queueId) jsonError('queue_id required');

$db = getDB();
$storeId = currentStoreId();
if (!$storeId) jsonError('No store selected', 403);

$stmt = $db->prepare("SELECT * FROM queue_orders WHERE id = ? AND store_id = ?");
$stmt->execute([$queueId, $storeId]);
$queue = $stmt->fetch();
if (!$queue) jsonError('Queue not found', 404);

$s = getStoreSettings($queue['store_id']);

$stmt2 = $db->prepare("SELECT * FROM queue_items WHERE queue_id = ? ORDER BY id");
$stmt2->execute([$queueId]);
$items = $stmt2->fetchAll();
if (empty($items)) jsonError('No items in queue order');

$connector = new DummyPrintConnector();
$printer   = new Printer($connector);

try {
    $paperWidth  = (int)($s['printer_paper_width'] ?? 80);
    $charsPerLine = ($paperWidth === 58) ? 32 : 48;

    // ── HEADER ──
    $printer->initialize();
    $printer->setJustification(Printer::JUSTIFY_CENTER);

    $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH | Printer::MODE_EMPHASIZED);
    $printer->text("KITCHEN ORDER\n");
    $printer->selectPrintMode();

    $printer->feed(1);

    // Queue number — very large
    $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH | Printer::MODE_EMPHASIZED);
    $printer->text($queue['queue_no'] . "\n");
    $printer->selectPrintMode();

    $printer->feed(1);
    $printer->text(str_repeat('-', $charsPerLine) . "\n");

    // ── META ──
    $printer->setJustification(Printer::JUSTIFY_LEFT);

    $tableNo = $queue['table_no'] ?: '-';
    $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
    $printer->text("Table: " . $tableNo . "\n");
    $printer->selectPrintMode();

    if (!empty($queue['note'])) {
        $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
        $printer->text("NOTE: " . $queue['note'] . "\n");
        $printer->selectPrintMode();
    }

    $createdAt = $queue['created_at'] ?? date('Y-m-d H:i:s');
    $printer->text("Time: " . date('h:i A', strtotime($createdAt)) . "\n");

    $printer->text(str_repeat('-', $charsPerLine) . "\n");

    // ── ITEMS (large, bold for kitchen readability) ──
    foreach ($items as $it) {
        $name = $it['product_name'] ?? 'Item';
        $qty  = (int)($it['quantity'] ?? 1);

        $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text(twoColK("x" . $qty . " " . $name, "", $charsPerLine) . "\n");
        $printer->selectPrintMode();
    }

    $printer->text(str_repeat('-', $charsPerLine) . "\n");

    // ── FOOTER ──
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text(count($items) . " items total\n");
    $printer->feed(3);
    $printer->cut();

    $data = $connector->getData();
    $printer->close();

    jsonSuccess([
        'base64'      => base64_encode($data),
        'printer'     => $s['kitchen_printer_name'] ?? ($s['printer_name'] ?? 'BC-80POS'),
        'paper_width' => $paperWidth,
        'queue_no'    => $queue['queue_no'],
        'size_bytes'  => strlen($data),
    ]);

} catch (Exception $e) {
    @$printer->close();
    jsonError('Kitchen slip build failed: ' . $e->getMessage(), 500);
}

function twoColK($left, $right, $width) {
    $left  = (string)$left;
    $right = (string)$right;
    $lLen = mb_strlen($left);
    $rLen = mb_strlen($right);
    // Account for double-width mode — effective width is halved
    $effective = (int)floor($width / 2);
    $space = $effective - $lLen - $rLen;
    if ($space < 1) $space = 1;
    return $left . str_repeat(' ', $space) . $right;
}
