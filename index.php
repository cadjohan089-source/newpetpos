<?php
$pageTitle = 'POS Counter';
require_once __DIR__ . '/includes/auth.php';
requireStorePage();
require_once __DIR__ . '/includes/header.php';
$db = getDB();
$storeId = currentStoreId();
$categories = $db->prepare("SELECT * FROM categories WHERE store_id = ? ORDER BY sort_order, name");
$categories->execute([$storeId]);
$categories = $categories->fetchAll();
$products = $db->prepare("
    SELECT p.*, c.name as cat_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.store_id = ? AND p.available = 1
    ORDER BY c.sort_order, p.name
");
$products->execute([$storeId]);
$products = $products->fetchAll();
?>
    <div class="topbar">
      <div>
        <div class="page-title">POS Counter</div>
        <div class="page-subtitle">Add items, create &amp; print bills</div>
      </div>
      <div class="topbar-right">
        <span style="font-size:12px;color:var(--text3)">Press
          <kbd style="background:var(--surface2);border:1px solid var(--border);padding:1px 6px;border-radius:4px;font-family:var(--mono)">F2</kbd>
          to search</span>
        <a href="<?= baseUrl('queue.php') ?>" class="btn btn-secondary btn-sm">🍳 Order Queue <span id="queue-badge" style="background:var(--brand);color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;margin-left:4px;display:none"></span></a>
        <a href="<?= baseUrl('bills.php') ?>" class="btn btn-secondary btn-sm">📋 Bill History</a>
      </div>
    </div>

    <div class="pos-layout">
      <!-- LEFT: MENU -->
      <div class="pos-menu-area">
        <div class="search-box">
          <span class="search-icon">🔍</span>
          <input type="text" id="product-search" placeholder="Search products or scan barcode…" autocomplete="off">
        </div>
        <div class="cat-tabs">
          <button class="cat-tab active" data-cat="all">All</button>
          <?php foreach ($categories as $cat): ?>
          <button class="cat-tab" data-cat="<?= htmlspecialchars($cat['name']) ?>">
            <?= htmlspecialchars($cat['name']) ?>
          </button>
          <?php endforeach; ?>
        </div>

        <div class="product-grid" id="product-grid">
          <?php foreach ($products as $p):
            $isOOS = (int)$p['stock_qty'] <= 0;
          ?>
          <div class="product-card <?= $isOOS ? 'out-of-stock' : '' ?>"
               data-id="<?= (int)$p['id'] ?>"
               data-cat="<?= htmlspecialchars($p['cat_name'] ?? '') ?>"
               data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
               data-price="<?= (float)$p['price'] ?>"
               data-stock="<?= (int)$p['stock_qty'] ?>"
               data-label="<?= htmlspecialchars($p['name'], ENT_QUOTES | ENT_HTML5) ?>"
               data-barcode="<?= htmlspecialchars($p['barcode'] ?? '') ?>"
               <?= $isOOS ? '' : 'onclick="POS.addFromCard(this)"' ?>>
            <div class="pc-cat"><?= htmlspecialchars($p['cat_name'] ?? '') ?></div>
            <div class="pc-name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="pc-price">
              <?= htmlspecialchars($settings['currency'] ?? 'Rs') ?>
              <?= number_format($p['price'], 0) ?>
            </div>
            <div style="position:absolute;top:8px;right:8px">
              <?php if ($isOOS): ?>
              <span class="badge badge-danger" style="font-size:9px">Out of Stock</span>
              <?php else: ?>
              <span class="badge badge-info" style="font-size:9px">Stock: <?= (int)$p['stock_qty'] ?></span>
              <?php endif; ?>
            </div>
            <div class="pc-add-icon"><?= $isOOS ? '✗' : '+' ?></div>
          </div>
          <?php endforeach; ?>

          <?php if (empty($products)): ?>
          <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text3)">
            No products found.
            <a href="<?= baseUrl('admin/products.php') ?>">Add products →</a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT: CART -->
      <div class="pos-cart-area">
        <div class="cart-header">
          <h3>
            Current Order
            <button class="btn btn-ghost btn-sm" onclick="POS.clearCart()" style="font-size:11px">Clear</button>
          </h3>
          <div class="cart-fields">
            <input class="form-control" id="cust-name" placeholder="Customer name"
                   style="font-size:13px;padding:7px 10px">
            <input class="form-control" id="table-no" placeholder="Table #"
                   style="font-size:13px;padding:7px 10px">
          </div>
          <div style="margin-top:8px">
            <input class="form-control" id="cust-phone" placeholder="Phone (optional — needed to track credit/khata)"
                   style="font-size:13px;padding:7px 10px;width:100%">
          </div>
        </div>

        <div class="cart-items-wrap" id="cart-items-wrap"></div>

        <div id="cart-footer" style="display:none">
          <div class="cart-footer">
            <div class="summary-row">
              <span>Subtotal</span><span id="summary-sub">Rs 0</span>
            </div>
            <div class="summary-row">
              <span>Tax (<?= (float)($settings['tax_rate'] ?? 5) ?>%)</span>
              <span id="summary-tax">Rs 0</span>
            </div>
            <div class="discount-row">
              <label>Discount (Rs)</label>
              <input type="number" id="discount-input" placeholder="0" min="0">
            </div>
            <div class="summary-row" style="color:var(--red)">
              <span>Discount</span><span id="summary-disc">Rs 0</span>
            </div>
            <hr class="summary-divider">
            <div class="summary-total">
              <span>Total</span><span id="summary-total">Rs 0</span>
            </div>

            <div class="pay-method" style="margin-top:12px">
              <button class="pay-btn active" data-method="Cash">💵 Cash</button>
              <button class="pay-btn" data-method="Card">💳 Card</button>
              <button class="pay-btn" data-method="JazzCash">📱 JazzCash</button>
              <button class="pay-btn" data-method="EasyPaisa">📲 EasyPaisa</button>
            </div>

            <div style="margin-top:12px">
              <div class="pay-method" style="margin-bottom:8px">
                <button class="pay-btn active" data-pstatus="paid" id="pstatus-paid" onclick="POS.setPaymentStatus('paid')">✓ Full Payment</button>
                <button class="pay-btn" data-pstatus="credit" id="pstatus-credit" onclick="POS.setPaymentStatus('credit')">🕒 Credit / Partial</button>
              </div>
              <div id="credit-fields" style="display:none">
                <label class="discount-row" style="margin-bottom:4px">
                  <span>Amount Received Now</span>
                  <input type="number" id="paid-now-input" placeholder="0" min="0">
                </label>
                <div class="summary-row" style="color:var(--red)">
                  <span>Remaining Due</span><span id="summary-due">Rs 0</span>
                </div>
                <p class="text-sm text-muted" style="margin-top:2px">Requires a customer name + phone above so the credit is tracked correctly.</p>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px">
              <button class="btn btn-secondary" onclick="POS.printPreview()">🖨️ Preview</button>
              <button class="btn btn-primary" id="btn-save-bill" onclick="POS.saveBill()">✓ Save Bill</button>
            </div>
            <button class="btn btn-full" id="btn-queue-bill" onclick="POS.sendToQueue()" style="margin-top:8px;background:var(--surface2);border:1.5px solid var(--amber);color:var(--amber);font-weight:700;padding:10px;">🍳 Order in Queue</button>
          </div>
        </div>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
