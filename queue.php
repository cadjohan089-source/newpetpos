<?php
$pageTitle = 'Order Queue';
require_once __DIR__ . '/includes/auth.php';
requireStorePage();
require_once __DIR__ . '/includes/header.php';
$cur = $settings['currency'] ?? 'Rs';
?>
    <div class="topbar">
      <div>
        <div class="page-title">🍳 Order Queue</div>
        <div class="page-subtitle">Kitchen orders waiting to be billed</div>
      </div>
      <div class="topbar-right">
        <button class="btn btn-secondary btn-sm" onclick="loadQueues('converted')">📦 Converted</button>
        <button class="btn btn-primary btn-sm" onclick="loadQueues('pending')" id="btn-show-pending">🟡 Pending</button>
        <a href="<?= baseUrl('index.php') ?>" class="btn btn-secondary btn-sm">+ New Bill</a>
      </div>
    </div>

    <div class="content-area">
      <!-- STATS -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px" id="queue-stats">
        <div class="stat-card brand"><div class="stat-icon">🍳</div><div class="stat-value" id="stat-pending">-</div><div class="stat-label">Pending Orders</div></div>
        <div class="stat-card green"><div class="stat-icon">✅</div><div class="stat-value" id="stat-converted">-</div><div class="stat-label">Converted Today</div></div>
        <div class="stat-card blue"><div class="stat-icon">📋</div><div class="stat-value" id="stat-total">-</div><div class="stat-label">Total Today</div></div>
      </div>

      <!-- QUEUE CARDS -->
      <div id="queue-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
        <div style="text-align:center;padding:40px;color:var(--text3);grid-column:1/-1">Loading orders…</div>
      </div>
    </div>

<!-- EDIT QUEUE MODAL -->
<div class="modal-overlay" id="edit-queue-modal">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-hd">
      <h3 class="modal-title" id="eq-title">Edit Queue Order</h3>
      <button class="modal-close" onclick="closeModal('edit-queue-modal')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="eq-id">
      <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        <div class="form-group" style="margin:0"><label class="form-label">Table #</label><input class="form-control" id="eq-table"></div>
        <div class="form-group" style="margin:0"><label class="form-label">Note</label><input class="form-control" id="eq-note" placeholder="e.g. no spice"></div>
      </div>
      <div style="font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Items</div>
      <div id="eq-items-wrap"></div>
      <button class="btn btn-ghost btn-sm" onclick="addQueueItemRow()" style="margin-top:8px">+ Add Item</button>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('edit-queue-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveQueueEdit()">Save Changes</button>
    </div>
  </div>
</div>

<!-- CONVERT TO BILL MODAL -->
<div class="modal-overlay" id="convert-modal">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-hd">
      <h3 class="modal-title" id="conv-title">Convert to Bill</h3>
      <button class="modal-close" onclick="closeModal('convert-modal')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="conv-qid">
      <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        <div class="form-group" style="margin:0"><label class="form-label">Customer Name</label><input class="form-control" id="conv-cust" placeholder="Walk-in"></div>
        <div class="form-group" style="margin:0"><label class="form-label">Discount (<?= $cur ?>)</label><input class="form-control" type="number" id="conv-disc" value="0" min="0"></div>
      </div>
      <div style="font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Items &amp; Prices</div>
      <div id="conv-items-wrap"></div>
      <div style="background:var(--surface2);border-radius:var(--radius);padding:12px;margin-top:12px;font-size:13px">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px"><span style="color:var(--text2);font-weight:800;">Subtotal</span><span id="conv-sub" class="font-mono">-</span></div>
        <div style="display:flex;justify-content:space-between;margin-bottom:5px"><span style="color:var(--text2)">Tax (<?= (float)($settings['tax_rate'] ?? 5) ?>%)</span><span id="conv-tax" class="font-mono">-</span></div>
        <div style="display:flex;justify-content:space-between;margin-bottom:5px;color:var(--red)"><span>Discount</span><span id="conv-disc-display" class="font-mono">-</span></div>
        <hr style="border:none;border-top:1px dashed var(--border2);margin:8px 0">
        <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:700"><span>Total</span><span id="conv-total" class="font-mono" style="color:var(--green)">-</span></div>
      </div>
      <div style="font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin:12px 0 8px">Payment Method</div>
      <div class="pay-method">
        <button class="pay-btn active" data-method="Cash">💵 Cash</button>
        <button class="pay-btn" data-method="Card">💳 Card</button>
        <button class="pay-btn" data-method="JazzCash">📱 JazzCash</button>
        <button class="pay-btn" data-method="EasyPaisa">📲 EasyPaisa</button>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('convert-modal')">Cancel</button>
      <button class="btn btn-success" onclick="doConvertToBill()">✓ Generate Bill</button>
    </div>
  </div>
</div>

<!-- KITCHEN PRINT AREA -->
<div id="kitchen-print-area" style="display:none"></div>

<script>
// ── QUEUE PAGE JS ──
let currentStatus = "pending";
let convPayMethod = "Cash";

// Toast notification function
function toast(message, type) {
    if (typeof type === "undefined") type = "info";
    const toastContainer = document.getElementById("toast-container") || (function() {
        const container = document.createElement("div");
        container.id = "toast-container";
        container.style.cssText = "position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px";
        document.body.appendChild(container);
        return container;
    })();
    
    const toast = document.createElement("div");
    let bgColor = "var(--blue)";
    if (type === "success") bgColor = "var(--green)";
    if (type === "error") bgColor = "var(--red)";
    toast.style.cssText = "background:" + bgColor + ";color:white;padding:12px 20px;border-radius:8px;font-size:14px;box-shadow:0 2px 8px rgba(0,0,0,0.2);animation:slideIn 0.3s ease";
    toast.textContent = message;
    toastContainer.appendChild(toast);
    setTimeout(function() {
        toast.style.animation = "slideOut 0.3s ease";
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

// Set up payment method buttons
var payBtns = document.querySelectorAll(".pay-btn");
for (var b = 0; b < payBtns.length; b++) {
    payBtns[b].addEventListener("click", function() {
        var allBtns = document.querySelectorAll(".pay-btn");
        for (var i = 0; i < allBtns.length; i++) {
            allBtns[i].classList.remove("active");
        }
        this.classList.add("active");
        convPayMethod = this.dataset.method;
    });
}

// Set up discount input listener
var discInput = document.getElementById("conv-disc");
if (discInput) {
    discInput.addEventListener("input", updateConvTotals);
}

async function loadQueues(status) {
    if (typeof status === "undefined") status = "pending";
    currentStatus = status;
    var pendingBtn = document.getElementById("btn-show-pending");
    if (pendingBtn) {
        pendingBtn.className = status === "pending" ? "btn btn-primary btn-sm" : "btn btn-secondary btn-sm";
    }
    var list = document.getElementById("queue-list");
    if (!list) return;
    list.innerHTML = "<div style=\"text-align:center;padding:40px;color:var(--text3);grid-column:1/-1\">Loading…</div>";
    try {
        var pRes = await fetch(apiUrl("api/queue.php") + "?status=pending");
        var cRes = await fetch(apiUrl("api/queue.php") + "?status=converted");
        var pData = await pRes.json();
        var cData = await cRes.json();
        var pending = pData.queues || [];
        var converted = cData.queues || [];
        // Stats
        var todayDate = new Date().toISOString().slice(0,10);
        var todayConv = [];
        for (var i = 0; i < converted.length; i++) {
            if (converted[i].created_at && converted[i].created_at.startsWith(todayDate)) {
                todayConv.push(converted[i]);
            }
        }
        document.getElementById("stat-pending").textContent = pending.length;
        document.getElementById("stat-converted").textContent = todayConv.length;
        document.getElementById("stat-total").textContent = pending.length + todayConv.length;
        // Render cards
        var queues = status === "pending" ? pending : converted;
        if (!queues.length) {
            list.innerHTML = "<div style=\"text-align:center;padding:60px;color:var(--text3);grid-column:1/-1\">" +
                "<div style=\"font-size:48px;margin-bottom:12px\">" + (status === "pending" ? "🍳" : "📦") + "</div>" +
                "<p>" + (status === "pending" ? "No pending orders right now" : "No converted orders today") + "</p></div>";
            return;
        }
        var html = "";
        for (var q = 0; q < queues.length; q++) {
            html += renderQueueCard(queues[q], status);
        }
        list.innerHTML = html;
    } catch(e) {
        list.innerHTML = "<div style=\"color:var(--red);padding:20px;grid-column:1/-1\">Error: " + e.message + "</div>";
    }
}

function renderQueueCard(q, status) {
    var itemsHtml = "";
    var items = q.items || [];
    for (var i = 0; i < items.length; i++) {
        itemsHtml += "<div style=\"display:flex;justify-content:space-between;padding:5px 0; font-weight:800;border-bottom:1px solid var(--border);font-size:13px\">" +
            "<span style=\"font-weight:600\">" + escHtml(items[i].product_name) + "</span>" +
            "<span class=\"badge badge-neutral\" style=\"font-family:var(--mono)\">× " + items[i].quantity + "</span></div>";
    }
    var age = Math.floor((Date.now() - new Date(q.created_at).getTime()) / 60000);
    var ageColor = age > 30 ? "var(--red)" : (age > 15 ? "var(--amber)" : "var(--green)");
    
    // Escape the JSON for the printKitchenSlip function
    var qJson = JSON.stringify(q).replace(/"/g, '&quot;');
    
    var actions = "";
    if (status === "pending") {
        actions = '<button class="btn btn-secondary btn-sm" onclick="openEditQueue(' + q.id + ')">✏️ Edit</button>' +
            '<button class="btn btn-ghost btn-sm" onclick="printKitchenSlip(' + qJson + ')">🖨️ Kitchen</button>' +
            '<button class="btn btn-success btn-sm" onclick="openConvertModal(' + q.id + ')">💰 Bill</button>' +
            '<button class="btn btn-danger btn-sm" onclick="deleteQueue(' + q.id + ',\'' + escHtml(q.queue_no) + '\')">🗑️</button>';
    } else {
        actions = '<button class="btn btn-ghost btn-sm" onclick="printKitchenSlip(' + qJson + ')">🖨️ Kitchen</button>';
    }
    
    return '<div class="card" style="margin:0;border-left:4px solid ' + (status==="pending"?"var(--amber)":"var(--green)") + '">' +
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">' +
        '<div>' +
        '<div style="font-size:16px;font-weight:800;color:var(--text);font-family:var(--mono)">' + escHtml(q.queue_no) + '</div>' +
        '<div style="font-size:12px;color:var(--text3);margin-top:2px">Table: <b>' + escHtml(q.table_no||"-") + '</b>' + (q.note?(" · " + escHtml(q.note)):"") + '</div>' +
        '</div>' +
        '<div style="text-align:right">' +
        '<span class="badge ' + (status==="pending"?"badge-warning":"badge-success") + '">' + status + '</span>' +
        '<div style="font-size:11px;color:' + ageColor + ';margin-top:4px;font-weight:600">' + age + 'm ago</div>' +
        '</div>' +
        '</div>' +
        '<div style="margin-bottom:12px">' + (itemsHtml || "<div style=\"color:var(--text3);font-size:13px\">No items</div>") + '</div>' +
        '<div style="display:flex;gap:6px;flex-wrap:wrap">' + actions + '</div>' +
        '</div>';
}

// ── EDIT QUEUE ──
async function openEditQueue(id) {
    var res = await fetch(apiUrl("api/queue.php") + "?id=" + id);
    var data = await res.json();
    if (!data.success) {
        toast("Load failed", "error");
        return;
    }
    var q = data.queue;
    document.getElementById("eq-id").value = q.id;
    document.getElementById("eq-table").value = q.table_no || "";
    document.getElementById("eq-note").value = q.note || "";
    document.getElementById("eq-title").textContent = "Edit — " + q.queue_no;
    renderEditItems(q.items || []);
    openModal("edit-queue-modal");
}

function renderEditItems(items) {
    var wrap = document.getElementById("eq-items-wrap");
    var html = "";
    for (var i = 0; i < items.length; i++) {
        html += '<div class="eq-item-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px">' +
            '<input class="form-control" style="flex:1" placeholder="Item name" value="' + escHtml(items[i].product_name) + '" data-field="name">' +
            '<input class="form-control" style="width:70px" type="number" min="1" placeholder="Qty" value="' + items[i].quantity + '" data-field="qty">' +
            '<input type="hidden" data-field="id" value="' + (items[i].product_id || "") + '">' +
            '<button onclick="this.closest(\'.eq-item-row\').remove()" style="background:none;border:none;color:var(--red);font-size:18px;cursor:pointer;padding:4px">×</button>' +
            '</div>';
    }
    wrap.innerHTML = html;
}

function addQueueItemRow() {
    var wrap = document.getElementById("eq-items-wrap");
    var row = document.createElement("div");
    row.className = "eq-item-row";
    row.style = "display:flex;gap:8px;align-items:center;margin-bottom:8px";
    row.innerHTML = '<input class="form-control" style="flex:1" placeholder="Item name" data-field="name">' +
        '<input class="form-control" style="width:70px" type="number" min="1" placeholder="Qty" value="1" data-field="qty">' +
        '<input type="hidden" data-field="id" value="">' +
        '<button onclick="this.closest(\'.eq-item-row\').remove()" style="background:none;border:none;color:var(--red);font-size:18px;cursor:pointer;padding:4px">×</button>';
    wrap.appendChild(row);
}

async function saveQueueEdit() {
    var id = document.getElementById("eq-id").value;
    var table = document.getElementById("eq-table").value;
    var note = document.getElementById("eq-note").value;
    var rows = document.querySelectorAll(".eq-item-row");
    var items = [];
    for (var r = 0; r < rows.length; r++) {
        var name = rows[r].querySelector("[data-field=name]").value.trim();
        var qty = parseInt(rows[r].querySelector("[data-field=qty]").value) || 1;
        var pid = rows[r].querySelector("[data-field=id]").value;
        if (name) items.push({ name: name, qty: qty, quantity: qty, product_id: pid || null });
    }
    if (!items.length) {
        toast("Add at least one item", "error");
        return;
    }
    var res = await fetch(apiUrl("api/queue.php"), {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: parseInt(id), table_no: table, note: note, items: items })
    });
    var data = await res.json();
    if (data.success) {
        toast("Queue updated", "success");
        closeModal("edit-queue-modal");
        loadQueues(currentStatus);
    } else {
        toast(data.error || "Error", "error");
    }
}

// ── DELETE QUEUE ──
async function deleteQueue(id, qno) {
    if (!confirm("Delete queue " + qno + "?")) return;
    var res = await fetch(apiUrl("api/queue.php") + "?id=" + id, { method: "DELETE" });
    var data = await res.json();
    if (data.success) {
        toast("Deleted", "success");
        loadQueues(currentStatus);
    } else {
        toast(data.error || "Error", "error");
    }
}

// ── CONVERT TO BILL ──
var convQueueItems = [];
async function openConvertModal(id) {
    var res = await fetch(apiUrl("api/queue.php") + "?id=" + id);
    var data = await res.json();
    if (!data.success) {
        toast("Load failed", "error");
        return;
    }
    var q = data.queue;
    convQueueItems = q.items || [];
    document.getElementById("conv-qid").value = q.id;
    document.getElementById("conv-title").textContent = "Convert to Bill — " + q.queue_no;
    document.getElementById("conv-cust").value = "";
    document.getElementById("conv-disc").value = "0";
    // Render items with price inputs
    var wrap = document.getElementById("conv-items-wrap");
    var convHtml = "";
    for (var i = 0; i < convQueueItems.length; i++) {
        convHtml += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;padding:8px;background:var(--surface2);border-radius:var(--radius-sm)">' +
            '<div style="flex:1"><div style="font-size:13px;font-weight:600">' + escHtml(convQueueItems[i].product_name) + '</div><div style="font-size:11px;color:var(--text3)">Qty: ' + convQueueItems[i].quantity + '</div></div>' +
            '<div style="display:flex;align-items:center;gap:6px">' +
            '<span style="font-size:12px;color:var(--text3)">Rs</span>' +
            '<input class="form-control" style="width:90px;font-family:var(--mono)" type="number" id="conv-price-' + i + '" placeholder="Price" value="' + (convQueueItems[i].price > 0 ? convQueueItems[i].price : "") + '" data-qi="' + convQueueItems[i].id + '" data-idx="' + i + '" min="0" oninput="updateConvTotals()">' +
            '</div></div>';
    }
    wrap.innerHTML = convHtml;
    updateConvTotals();
    openModal("convert-modal");
}

function updateConvTotals() {
    var taxRate = 5;
    if (typeof document.body.dataset.tax !== "undefined") taxRate = parseFloat(document.body.dataset.tax);
    var sub = 0;
    for (var i = 0; i < convQueueItems.length; i++) {
        var priceInput = document.getElementById("conv-price-" + i);
        var price = priceInput ? (parseFloat(priceInput.value) || 0) : 0;
        sub += price * convQueueItems[i].quantity;
    }
    var tax = Math.round(sub * taxRate / 100);
    var disc = parseFloat(document.getElementById("conv-disc").value) || 0;
    var total = Math.max(0, sub + tax - disc);
    var cur = "Rs";
    if (typeof window.RES_SETTINGS !== "undefined" && window.RES_SETTINGS.currency) cur = window.RES_SETTINGS.currency;
    document.getElementById("conv-sub").textContent = cur + " " + sub.toFixed(0);
    document.getElementById("conv-tax").textContent = cur + " " + tax.toFixed(0);
    document.getElementById("conv-disc-display").textContent = cur + " " + disc.toFixed(0);
    document.getElementById("conv-total").textContent = cur + " " + total.toFixed(0);
}

async function doConvertToBill() {
    var id = document.getElementById("conv-qid").value;
    var cust = document.getElementById("conv-cust").value.trim() || "Walk-in";
    var disc = parseFloat(document.getElementById("conv-disc").value) || 0;
    var itemsPayload = [];
    var hasPrice = false;
    for (var i = 0; i < convQueueItems.length; i++) {
        var priceInput = document.getElementById("conv-price-" + i);
        var price = priceInput ? (parseFloat(priceInput.value) || 0) : 0;
        itemsPayload.push({ queue_item_id: convQueueItems[i].id, price: price });
        if (price > 0) hasPrice = true;
    }
    if (!hasPrice) {
        toast("Enter price for at least one item", "error");
        return;
    }
    var res = await fetch(apiUrl("api/queue.php"), {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: parseInt(id), convert_to_bill: true, customer_name: cust, discount: disc, payment_method: convPayMethod, items: itemsPayload })
    });
    var data = await res.json();
    if (data.success) {
        toast("Bill created — " + data.bill_no, "success");
        closeModal("convert-modal");
        loadQueues(currentStatus);
        setTimeout(function() { showReceipt(data.bill_no); }, 500);

        // Auto-print via QZ Tray if enabled and available
        var autoPrint = (window.RES_SETTINGS && window.RES_SETTINGS.printer_auto_print === '1');
        if (autoPrint && window.QZPrint) {
            window.QZPrint.isAvailable().then(function(ok) {
                if (ok) {
                    window.QZPrint.printReceipt(data.bill_no)
                        .then(function() { toast('🖨️ Printed', 'success', 1500); })
                        .catch(function(err) { console.warn('[QZ Tray] auto-print failed:', err.message); });
                }
            });
        }
    } else {
        toast(data.error || "Error", "error");
    }
}

// ── KITCHEN SLIP PRINT ──
async function printKitchenSlip(q) {
    // Try QZ Tray first (instant thermal print via local printer)
    if (window.QZPrint && q.id) {
        try {
            const ok = await window.QZPrint.isAvailable();
            if (ok) {
                await window.QZPrint.printKitchen(q.id);
                toast('🖨️ Kitchen slip printed', 'success', 1500);
                return;
            }
        } catch (err) {
            console.warn('[QZ Tray] kitchen print failed, falling back:', err.message);
            toast('QZ Tray print failed — using browser print', 'info');
        }
    }

    // ── Fallback: browser print window ──
    var itemsHtml = "";
    var items = q.items || [];
    for (var i = 0; i < items.length; i++) {
        itemsHtml += '<tr><td style="padding:4px 6px;font-size:14px;font-weight:bold">' + escHtml(items[i].product_name) + '</td><td style="padding:4px 6px;font-size:16px;font-weight:900;text-align:right">× ' + items[i].quantity + '</td></tr>';
    }
    
    var html = '<!DOCTYPE html><html><head><title>Kitchen Order</title>' +
        '<style>' +
        'body { margin: 0; padding: 0; font-family: monospace; }' +
        '.kitchen-slip { width: 80mm; margin: 0 auto; padding: 4mm; }' +
        '.header { text-align: center; border-bottom: 1px dashed #000; margin-bottom: 10px; }' +
        '.title { font-size: 14pt; font-weight: 800; }' +
        '.order-no { font-size: 16pt; font-weight: bold; margin: 5px 0; }' +
        '.items-table { width: 100%; margin: 10px 0; }' +
        '.items-table td { padding: 4px 0; }' +
        '.items-table td:last-child { text-align: right; }' +
        '.footer { text-align: center; border-top: 1px dashed #000; margin-top: 10px; padding-top: 5px; }' +
        '@media print { body { margin: 0; padding: 0; } }' +
        '</style></head><body>' +
        '<div class="kitchen-slip">' +
        '<div class="header">' +
        '<div class="title">🍳 KITCHEN ORDER</div>' +
        '<div class="order-no">' + escHtml(q.queue_no) + '</div>' +
        '<div>Table: <strong>' + escHtml(q.table_no || "-") + '</strong></div>' +
        (q.note ? '<div>Note: <strong>' + escHtml(q.note) + '</strong></div>' : '') +
        '<div class="small">' + new Date(q.created_at).toLocaleString() + '</div>' +
        '</div>' +
        '<table class="items-table">' + itemsHtml + '</table>' +
        '<div class="footer">' + (q.items || []).length + ' items total</div>' +
        '</div>' +
        '</body></html>';
    
    var printWindow = window.open('', '_blank');
    if (!printWindow) {
        toast('Popup blocked. Please allow popups for this site or install QZ Tray.', 'error');
        return;
    }
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.print();
    printWindow.close();
}


// ── RECEIPT FUNCTIONS ──
function buildReceiptHTML(bill) {
    var cur = "Rs";
    if (typeof window.RES_SETTINGS !== "undefined" && window.RES_SETTINGS.currency) cur = window.RES_SETTINGS.currency;
    var itemsHtml = "";
    var items = bill.items || [];
    for (var i = 0; i < items.length; i++) {
        itemsHtml += '<tr><td style="padding:4px 0;font-weight:bold;">' + escHtml(items[i].product_name) + ' x ' + items[i].quantity + '</td><td style="text-align:right">' + cur + ' ' + (items[i].price * items[i].quantity).toFixed(0) + '</td></tr>';
    }
    return '<div style="font-family:monospace;max-width:80mm;margin:0 auto;padding:10px">' +
        '<div style="text-align:center;border-bottom:2px dashed #000;padding-bottom:10px;margin-bottom:10px">' +
        '<div style="font-size:18px;font-weight:900">RESTAURANT BILL</div>' +
        '<div style="font-size:20px;font-weight:bold;margin-top:5px">' + escHtml(bill.bill_no) + '</div>' +
        '<div style="font-size:11px;margin-top:5px">' + new Date(bill.created_at).toLocaleString() + '</div></div>' +
        '<div style="margin-bottom:10px">' +
        '<div><strong>Customer:</strong> ' + escHtml(bill.customer_name || "Walk-in") + '</div>' +
        '<div><strong>Payment:</strong> ' + escHtml(bill.payment_method || "Cash") + '</div></div>' +
        '<table style="width:100%;border-collapse:collapse;margin-bottom:10px">' + itemsHtml + '</table>' +
        '<div style="border-top:1px dashed #000;padding-top:8px">' +
        '<div style="display:flex;justify-content:space-between"><span>Subtotal:</span><span>' + cur + ' ' + (bill.subtotal || 0).toFixed(0) + '</span></div>' +
        '<div style="display:flex;justify-content:space-between"><span>Tax (' + (bill.tax_rate || 5) + '%):</span><span>' + cur + ' ' + (bill.tax || 0).toFixed(0) + '</span></div>' +
        (bill.discount > 0 ? '<div style="display:flex;justify-content:space-between;color:red"><span>Discount:</span><span>' + cur + ' ' + bill.discount.toFixed(0) + '</span></div>' : "") +
        '<div style="display:flex;justify-content:space-between;font-size:16px;font-weight:bold;margin-top:5px;padding-top:5px;border-top:2px solid #000">' +
        '<span>TOTAL:</span><span>' + cur + ' ' + (bill.total || 0).toFixed(0) + '</span></div></div>' +
        '<div style="text-align:center;margin-top:15px;font-size:10px;border-top:1px dashed #000;padding-top:8px">Thank you! Visit again 😊</div></div>';
}

function openReceiptModal(html, bill, isPreview = false) {
    var modal = document.getElementById('receipt-modal');
    document.getElementById('receipt-modal-body').innerHTML =
        `<div class="receipt-paper">${html}</div>`;
    modal.classList.add('open');

    // Store bill_no for QZ Tray printing
    modal.dataset.billNo = (bill && bill.bill_no) ? bill.bill_no : '';
    modal.dataset.isPreview = isPreview ? '1' : '0';

    // Update print button handler
    var printBtn = document.getElementById('btn-print-receipt');
    if (printBtn) {
        // Remove old event listeners by replacing the button
        var newPrintBtn = printBtn.cloneNode(true);
        printBtn.parentNode.replaceChild(newPrintBtn, printBtn);
        newPrintBtn.onclick = function() {
            printReceipt();
        };
    }
}
// ── RECEIPT PRINT (Updated to use separate window) ──
async function printReceipt() {
    // Try QZ Tray first if we have a saved bill_no
    var modal = document.getElementById('receipt-modal');
    var billNo = modal && modal.dataset ? modal.dataset.billNo : '';

    if (billNo && window.QZPrint) {
        try {
            var ok = await window.QZPrint.isAvailable();
            if (ok) {
                await window.QZPrint.printReceipt(billNo);
                toast('🖨️ Printed', 'success', 1500);
                return;
            }
        } catch (err) {
            console.warn('[QZ Tray] receipt print failed, falling back:', err.message);
            toast('QZ Tray print failed — using browser print', 'info');
        }
    }

    // ── Fallback: browser print window ──
    var receiptHtml = document.getElementById('receipt-modal-body').innerHTML;
    if (!receiptHtml) {
        toast('No receipt to print', 'error');
        return;
    }
    
    var html = '<!DOCTYPE html><html><head><title>Receipt</title>' +
        '<style>' +
        'body { margin: 0; padding: 8mm; font-family: monospace; background: white; }' +
        '.receipt-paper { max-width: 80mm; margin: 0 auto; font-weight: bold; }' +
        '.receipt-header { text-align: center; margin-bottom: 15px; font-weight: bold; }' +
        '.receipt-header h2 { margin: 0; font-size: 16pt; font-weight: bold; }' +
        '.receipt-header p { margin: 3px 0; font-size: 10pt; font-weight: bold; }' +
        '.receipt-divider { border-top: 1px dashed #000; margin: 8px 0; font-weight: bold; }' +
        '.receipt-row { display: flex; justify-content: space-between; margin: 5px 0; font-weight: bold; }' +
        '.receipt-item-row { display: flex; justify-content: space-between; margin: 4px 0; font-weight: bold; }' +
        '.receipt-total { display: flex; justify-content: space-between; font-weight: bold; font-size: 12pt; margin: 10px 0; }' +
        '.receipt-footer { text-align: center; margin-top: 15px; font-size: 9pt; font-weight: bold; }' +
        '@media print { body { margin: 0; padding: 0; } .no-print { display: none; } }' +
        '</style></head><body>' +
        '<div class="receipt-paper">' + receiptHtml + '</div>' +
        '</body></html>';
    
    var printWindow = window.open('', '_blank');
    if (!printWindow) {
        toast('Popup blocked. Please allow popups for this site or install QZ Tray.', 'error');
        return;
    }
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.print();
    printWindow.close();
}

async function showReceipt(billNo) {
    try {
        var res = await fetch(apiUrl("api/bills.php") + "?bill_no=" + encodeURIComponent(billNo));
        var data = await res.json();
        if (data.success) {
            openReceiptModal(buildReceiptHTML(data.bill), data.bill);
        } else {
            toast("Could not load receipt", "error");
        }
    } catch(e) {
        toast("Network error", "error");
    }
}

function escHtml(str) {
    if (!str) return "";
    var d = document.createElement("div");
    d.textContent = str;
    return d.innerHTML;
}

function openModal(modalId) {
    document.getElementById(modalId).style.display = "flex";
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = "none";
}

// Auto-refresh pending every 30 seconds
setInterval(function() {
    if (currentStatus === "pending") loadQueues("pending");
}, 30000);

// Add CSS animations
var style = document.createElement("style");
style.textContent = "@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } } " +
    "@keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }";
document.head.appendChild(style);

// Wait for DOM to be fully loaded before calling loadQueues
document.addEventListener("DOMContentLoaded", function() {
    loadQueues("pending");
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>