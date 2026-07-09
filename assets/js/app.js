/* Restaurant POS — Main JS */
const POS = {
  cart: {},
  payMethod: 'Cash',
  discount: 0,
  taxRate: parseFloat(document.body.dataset.tax || 5),
  paymentStatus: 'paid', // 'paid' or 'credit'
  paidNow: 0,

  init() { this.bindEvents(); this.renderCart(); },

  setPaymentStatus(status) {
    this.paymentStatus = status;
    document.getElementById('pstatus-paid').classList.toggle('active', status === 'paid');
    document.getElementById('pstatus-credit').classList.toggle('active', status === 'credit');
    const fields = document.getElementById('credit-fields');
    if (fields) fields.style.display = status === 'credit' ? 'block' : 'none';
    if (status === 'paid') {
      this.paidNow = 0;
      const input = document.getElementById('paid-now-input');
      if (input) input.value = '';
    }
    this.renderSummary();
  },

  bindEvents() {
    document.querySelectorAll('.cat-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this.filterProducts(btn.dataset.cat);
      });
    });
    const se = document.getElementById('product-search');
    if (se) {
      se.addEventListener('input', e => this.searchProducts(e.target.value));
      se.addEventListener('keydown', e => this.handleBarcodeKey(e));
    }
    const de = document.getElementById('discount-input');
    if (de) de.addEventListener('input', e => { this.discount = parseFloat(e.target.value) || 0; this.renderSummary(); });
    const pn = document.getElementById('paid-now-input');
    if (pn) pn.addEventListener('input', e => {
      this.paidNow = parseFloat(e.target.value) || 0;
      this.renderSummary();
    });
    document.querySelectorAll('.pay-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this.payMethod = btn.dataset.method;
      });
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'F2') { e.preventDefault(); document.getElementById('product-search')?.focus(); }
    });
  },
 
  // Handles barcode scanners: they type digits fast then send Enter.
  // On Enter, look for an exact barcode match among the products on screen;
  // if found, add it straight to the cart. Otherwise leave normal text search alone.
  handleBarcodeKey(e) {
    if (e.key !== 'Enter') return;
    const val = e.target.value.trim();
    if (!val) return;
    const card = document.querySelector(`.product-card[data-barcode="${CSS.escape(val)}"]`);
    if (card && card.dataset.barcode) {
      e.preventDefault();
      if (card.classList.contains('out-of-stock')) {
        toast('Scanned product is out of stock', 'error');
      } else {
        this.addFromCard(card);
        toast('Added: ' + card.dataset.label, 'success', 1200);
      }
      e.target.value = '';
      this.searchProducts('');
      e.target.focus();
    }
  },

  // Called from product card onclick — reads data attributes (avoids quote issues)
  addFromCard(el) {
    const id    = el.dataset.id;
    const name  = el.dataset.label;
    const price = parseFloat(el.dataset.price);
    const stock = parseInt(el.dataset.stock || '0', 10);
    this.addItem(id, name, price, stock);
  },

  addItem(id, name, price, stock) {
    if (stock !== undefined && stock <= 0) {
      toast('Product is out of stock', 'error');
      return;
    }
    const currentQty = this.cart[id] ? this.cart[id].qty : 0;
    if (stock !== undefined && currentQty >= stock) {
      toast('Out of stock — only ' + stock + ' available', 'error');
      return;
    }
    if (this.cart[id]) this.cart[id].qty++;
    else this.cart[id] = { id, name, price, qty: 1, stock };
    this.renderCart(); this.flash(id);
  },

  changeQty(id, delta) {
    if (!this.cart[id]) return;
    const newQty = this.cart[id].qty + delta;
    if (delta > 0 && this.cart[id].stock !== undefined && newQty > this.cart[id].stock) {
      toast('Cannot exceed stock (' + this.cart[id].stock + ')', 'error');
      return;
    }
    this.cart[id].qty = newQty;
    if (this.cart[id].qty <= 0) delete this.cart[id];
    this.renderCart();
  },

  removeItem(id) { delete this.cart[id]; this.renderCart(); },

  clearCart() {
    if (!Object.keys(this.cart).length) return;
    if (!confirm('Clear all items from cart?')) return;
    this.cart = {}; this.discount = 0;
    const di = document.getElementById('discount-input');
    if (di) di.value = '';
    document.getElementById('cust-name').value = '';
    document.getElementById('table-no').value = '';
    const cp = document.getElementById('cust-phone');
    if (cp) cp.value = '';
    this.setPaymentStatus('paid');
    this.renderCart();
  },

  flash(id) {
    const card = document.querySelector(`.product-card[data-id="${id}"]`);
    if (!card) return;
    card.style.borderColor = 'var(--brand)';
    card.style.background  = 'var(--brand-light)';
    setTimeout(() => { card.style.borderColor = ''; card.style.background = ''; }, 300);
  },

  getSubtotal() { return Object.values(this.cart).reduce((s, i) => s + i.price * i.qty, 0); },
  getTax(sub)   { return Math.round(sub * this.taxRate / 100); },
  getTotal(sub, tax) { return sub + tax - this.discount; },

  renderCart() {
    const wrap = document.getElementById('cart-items-wrap');
    const keys = Object.keys(this.cart);
    if (!keys.length) {
      wrap.innerHTML = `<div class="empty-cart"><div class="empty-cart-icon">🛒</div><p>Cart is empty</p><p class="text-sm text-muted" style="margin-top:4px">Tap products to add</p></div>`;
    } else {
      wrap.innerHTML = keys.map(k => {
        const it = this.cart[k], line = it.price * it.qty;
        return `<div class="cart-item" data-id="${k}">
          <div class="ci-info">
            <div class="ci-name">${escHtml(it.name)}</div>
            <div class="ci-unit font-mono">Rs ${it.price.toFixed(0)}</div>
          </div>
          <div class="ci-qty-ctrl">
            <button class="qty-btn" onclick="POS.changeQty(${k},-1)">−</button>
            <span class="qty-val">${it.qty}</span>
            <button class="qty-btn" onclick="POS.changeQty(${k},1)">+</button>
          </div>
          <div class="ci-total">Rs ${line.toFixed(0)}</div>
          <button class="ci-remove" onclick="POS.removeItem(${k})" title="Remove">×</button>
        </div>`;
      }).join('');
    }
    this.renderSummary();
    const badge = document.getElementById('cart-badge');
    if (badge) { badge.textContent = keys.length || ''; badge.style.display = keys.length ? 'inline' : 'none'; }
  },

  renderSummary() {
    const sub = this.getSubtotal(), tax = this.getTax(sub), total = Math.max(0, this.getTotal(sub, tax));
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('summary-sub',   'Rs ' + sub.toFixed(0));
    set('summary-tax',   'Rs ' + tax.toFixed(0));
    set('summary-disc',  'Rs ' + (this.discount || 0).toFixed(0));
    set('summary-total', 'Rs ' + total.toFixed(0));
    if (this.paymentStatus === 'credit') {
      const due = Math.max(0, total - (this.paidNow || 0));
      set('summary-due', 'Rs ' + due.toFixed(0));
    }
    const footer = document.getElementById('cart-footer');
    if (footer) footer.style.display = Object.keys(this.cart).length ? 'block' : 'none';
  },

  filterProducts(cat) {
    document.querySelectorAll('.product-card').forEach(card => {
      card.style.display = (cat === 'all' || card.dataset.cat === cat) ? '' : 'none';
    });
  },

  searchProducts(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.product-card').forEach(card => {
      card.style.display = (!q || card.dataset.name.includes(q)) ? '' : 'none';
    });
    if (q) {
      document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
      document.querySelector('.cat-tab[data-cat="all"]')?.classList.add('active');
    }
  },

 getCartPayload() {
  const sub = this.getSubtotal();
  const tax = this.getTax(sub);
  const total = Math.max(0, this.getTotal(sub, tax));

  // Ensure all items have proper structure with both field naming conventions
  const items = Object.values(this.cart).map(item => ({
    // For database (what API expects)
    id: parseInt(item.id),
    product_id: parseInt(item.id),
    name: item.name,
    product_name: item.name,
    price: parseFloat(item.price),
    qty: parseInt(item.qty),
    quantity: parseInt(item.qty),
    subtotal: parseFloat(item.price * item.qty)
  }));

  // Full payment by default; if "Credit / Partial" is selected, only what was
  // actually received now counts as paid — the rest becomes the customer's due.
  const paidAmount = this.paymentStatus === 'credit'
    ? Math.min(total, Math.max(0, this.paidNow || 0))
    : total;

  return {
    customer_name: document.getElementById('cust-name').value.trim() || 'Walk-in',
    customer_phone: (document.getElementById('cust-phone')?.value || '').trim(),
    table_no: document.getElementById('table-no').value.trim() || '-',
    items: items,
    subtotal: parseFloat(sub),
    tax_amount: parseFloat(tax),
    discount: parseFloat(this.discount || 0),
    total: parseFloat(total),
    paid_amount: parseFloat(paidAmount),
    payment_method: this.payMethod,
  };
},

  async saveBill() {
    if (!Object.keys(this.cart).length) { toast('Add items to cart first', 'error'); return; }
    if (this.paymentStatus === 'credit') {
      const name = document.getElementById('cust-name').value.trim();
      if (!name) { toast('Enter a customer name to track this credit sale', 'error'); return; }
    }
    const btn = document.getElementById('btn-save-bill');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      const res  = await fetch(apiUrl('api/bills.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(this.getCartPayload()),
      });
      const data = await res.json();
      if (data.success) {
        const dueMsg = data.due_amount > 0 ? (' — Credit — Due: Rs ' + Math.round(data.due_amount)) : '';
        toast('Bill saved — ' + data.bill_no + dueMsg, 'success');
        this.cart = {}; this.discount = 0;
        document.getElementById('discount-input').value = '';
        document.getElementById('cust-name').value  = '';
        document.getElementById('table-no').value   = '';
        const cp = document.getElementById('cust-phone');
        if (cp) cp.value = '';
        this.setPaymentStatus('paid');
        this.renderCart();

        // Show receipt modal (existing behavior)
        setTimeout(() => showReceipt(data.bill_no), 400);

        // Auto-print via QZ Tray if enabled and available
        const autoPrint = (window.RES_SETTINGS && window.RES_SETTINGS.printer_auto_print === '1');
        if (autoPrint && window.QZPrint) {
          window.QZPrint.isAvailable().then(ok => {
            if (ok) {
              window.QZPrint.printReceipt(data.bill_no)
                .then(() => toast('🖨️ Printed', 'success', 1500))
                .catch(err => console.warn('[QZ Tray] auto-print failed:', err.message));
            }
            // If QZ Tray not available, user can still click Print in the modal (browser fallback)
          });
        }
      } else {
        toast(data.error || 'Failed to save', 'error');
      }
    } catch (e) { toast('Network error: ' + e.message, 'error'); }
    btn.disabled = false; btn.textContent = '✓ Save Bill';
  },

  printPreview() {
    if (!Object.keys(this.cart).length) { toast('Cart is empty', 'error'); return; }
    const payload  = this.getCartPayload();
    const dueAmount = Math.max(0, payload.total - payload.paid_amount);
    const paymentStatus = dueAmount <= 0.009 ? 'paid' : (payload.paid_amount > 0.009 ? 'partial' : 'unpaid');
    const html = buildReceiptHTML({
      ...payload,
      bill_no: 'PREVIEW',
      created_at: new Date().toLocaleString(),
      payment_status: paymentStatus,
      due_amount: dueAmount,
    });
    openReceiptModal(html, null, true);
  },

  async sendToQueue() {
    if (!Object.keys(this.cart).length) { toast('Add items to cart first', 'error'); return; }
    const btn = document.getElementById('btn-queue-bill');
    btn.disabled = true; btn.textContent = '🍳 Sending…';
    try {
      const items = Object.values(this.cart).map(it => ({
        product_id: parseInt(it.id),
        name:       it.name,
        qty:        it.qty,
        quantity:   it.qty,
        price:      it.price,
      }));
      const res  = await fetch(apiUrl('api/queue.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          table_no: document.getElementById('table-no').value.trim() || '-',
          note:     document.getElementById('cust-name').value.trim() || '',
          items,
        }),
      });
      const data = await res.json();
      if (data.success) {
        toast('Sent to kitchen — ' + data.queue_no, 'success');
        this.cart = {}; this.discount = 0;
        const di = document.getElementById('discount-input');
        if (di) di.value = '';
        document.getElementById('cust-name').value  = '';
        document.getElementById('table-no').value   = '';
        this.renderCart();
        updateQueueBadge();
      } else {
        toast(data.error || 'Failed to queue', 'error');
      }
    } catch (e) { toast('Network error: ' + e.message, 'error'); }
    btn.disabled = false; btn.textContent = '🍳 Order in Queue';
  }
};

// ── RECEIPT ──
async function showReceipt(billNo) {
  try {
    const res  = await fetch(apiUrl('api/bills.php') + '?bill_no=' + encodeURIComponent(billNo));
    const data = await res.json();
    if (data.success) openReceiptModal(buildReceiptHTML(data.bill), data.bill);
    else toast('Could not load receipt', 'error');
  } catch (e) { toast('Network error', 'error'); }
}

// Builds the Payment row for a receipt. If the bill was sold on credit or
// only partially paid, show that clearly instead of just the tender type —
// a bill that's still owed shouldn't look like it was paid in Cash.
function buildPaymentRow(bill) {
  const status = bill.payment_status; // 'paid' | 'partial' | 'unpaid' | undefined
  if (status === 'unpaid') {
    return '<div class="receipt-row" style="color:#c00062"><span><b>Payment</b></span><span><b>Credit</b></span></div>';
  }
  if (status === 'partial') {
    return '<div class="receipt-row" style="color:#c00062"><span><b>Payment</b></span><span><b>Credit (Partial — ' + escHtml(bill.payment_method || 'Cash') + ')</b></span></div>';
  }
  return bill.payment_method
    ? '<div class="receipt-row"><span><b>Payment</b></span><span><b>' + escHtml(bill.payment_method) + '</b></span></div>'
    : '';
}

function buildReceiptHTML(bill) {
  const S = window.RES_SETTINGS || {};
  const cur = S.currency || 'Rs';
  
  // Get items - handle different possible structures
  let items = [];
  if (bill.items && Array.isArray(bill.items)) {
    items = bill.items;
  } else if (bill.bill_items && Array.isArray(bill.bill_items)) {
    items = bill.bill_items;
  }
  
  // Build item rows with flexible field names
  const rows = items.map(it => {
    // Handle different field name possibilities
    const name = it.product_name || it.name || 'Unknown';
    const quantity = it.quantity || it.qty || 1;
    const price = it.price || 0;
    const total = it.subtotal || (price * quantity) || 0;
    
   return `<div class="receipt-item-row">
  <span><b>${escHtml(name)} × ${quantity}</b></span>
  <span>${cur} ${Math.round(total)}</span>
</div>`;
  }).join('');
  
  // If no items, show placeholder
  const itemsHtml = rows || '<div class="receipt-item-row">No items</div>';
  
  return `
    <div class="receipt-header">
      <h2>${escHtml(S.restaurant_name || 'Restaurant')}</h2>
     ${S.restaurant_address ? `<p><strong>${escHtml(S.restaurant_address)}</strong></p>` : ''}
${S.restaurant_phone ? `<p><strong>📞 ${escHtml(S.restaurant_phone)}</strong></p>` : ''}
    </div>
    <hr class="receipt-divider">
    <div class="receipt-row"><span><b>Bill #</b></span><span><b>${escHtml(bill.bill_no || '')}</b></span></div>
    <div class="receipt-row"><span><b>Date</b></span><span><b>${escHtml(bill.created_at || '')}</b></span></div>
<div class="receipt-row"><span><b>Customer</b></span><span><b>${escHtml(bill.customer_name || 'Walk-in')}</b></span></div>
${buildPaymentRow(bill)}
    <hr class="receipt-divider">
    ${itemsHtml}
    <hr class="receipt-divider">
    <div class="receipt-row"><span><b>Subtotal</b></span><span><b>${cur} ${Math.round(bill.subtotal || 0)}</b></span></div>
    <div class="receipt-row"><span><b>Tax</b></span><span><b>${cur} ${Math.round(bill.tax_amount || 0)}</b></span></div>
    ${bill.discount > 0 ? `<div class="receipt-row"><span><b>Discount</b></span><span><b>− ${cur} ${Math.round(bill.discount)}</b></span></div>` : ''}
    <hr class="receipt-divider">
    <div class="receipt-total"><span>TOTAL</span><span>${cur} ${Math.round(bill.total || 0)}</span></div>
    ${bill.payment_status && bill.payment_status !== 'paid' ? `<div class="receipt-row" style="color:#c00062"><span><b>Amount Due</b></span><span><b>${cur} ${Math.round(bill.due_amount || 0)}</b></span></div>` : ''}
    <div class="receipt-footer"><p><b>${escHtml(S.receipt_footer || 'Thank you!')}</b></p></div>`;
}

function openReceiptModal(html, bill, isPreview = false) {
  document.getElementById('receipt-modal-body').innerHTML =
    `<div class="receipt-paper print-area">${html}</div>`;
  document.getElementById('receipt-modal').classList.add('open');

  // Store bill_no on the modal so the print button can use it for QZ Tray
  const modal = document.getElementById('receipt-modal');
  modal.dataset.billNo = (bill && bill.bill_no) ? bill.bill_no : '';
  modal.dataset.isPreview = isPreview ? '1' : '0';

 document.getElementById('btn-print-receipt').onclick = async () => {
  const billNo = modal.dataset.billNo;
  const isPrev = modal.dataset.isPreview === '1';

  // Try QZ Tray first (instant thermal print) — only for real saved bills, not previews
  if (!isPrev && billNo && window.QZPrint) {
    const ok = await window.QZPrint.isAvailable();
    if (ok) {
      try {
        await window.QZPrint.printReceipt(billNo);
        toast('🖨️ Printed', 'success', 1500);
        return;
      } catch (err) {
        console.warn('[QZ Tray] print failed, falling back to browser print:', err.message);
        toast('QZ Tray print failed — using browser print', 'info');
        // fall through to browser-print fallback below
      }
    }
  }

  // ── Fallback: browser print via hidden iframe ──
  const receipt = document.querySelector('.receipt-paper');
  if (!receipt) return;

  // Create hidden iframe
  const iframe = document.createElement('iframe');
  iframe.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;border:none;visibility:hidden;';
  document.body.appendChild(iframe);

  const doc = iframe.contentDocument || iframe.contentWindow.document;
  const S = window.RES_SETTINGS || {};

  doc.open();
  doc.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Receipt</title>
<style>
  @page {
    size: 80mm auto;     /* thermal roll width, height auto-fits content */
    margin: 0;
  }
  * {
    box-sizing: border-box;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  html, body {
    margin: 0;
    padding: 0;
    background: white;
    color: #000;
    width: 80mm;
  }
  body {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    line-height: 1.45;
    padding: 3mm 3mm 4mm 3mm;
  }
  .receipt-header {
    text-align: center;
    margin-bottom: 6px;
  }
  .receipt-header h2 {
    font-size: 18px;
    font-weight: bold;
    margin: 0 0 3px 0;
  }
  .receipt-header p {
    font-size: 12px;
    margin: 1px 0;
  }
  hr {
    border: none;
    border-top: 1px dashed #000;
    margin: 5px 0;
  }
  .receipt-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 2px 0;
    font-size: 13px;
  }
  .receipt-row span:first-child {
    flex: 1;
    padding-right: 4px;
  }
  .receipt-row span:last-child {
    text-align: right;
    white-space: nowrap;
  }
  .receipt-item-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin: 3px 0;
    font-size: 13px;
  }
  .receipt-item-row span:first-child {
    flex: 1;
    padding-right: 6px;
    word-break: break-word;
  }
  .receipt-item-row span:last-child {
    white-space: nowrap;
    text-align: right;
    font-weight: 600;
  }
  .receipt-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: bold;
    font-size: 16px;
    margin: 4px 0 2px 0;
  }
  .receipt-footer {
    text-align: center;
    margin-top: 8px;
    font-size: 12px;
    color: #000;
  }

  /* Page-break behavior for multi-page receipts:
     - Keep header glued to the first content rows (no break right after it)
     - Keep each row together (don't split a single row across pages)
     - Do NOT put page-break-inside: avoid on the whole receipt — that
       pushes the entire receipt to page 2 and orphans the header on
       page 1 when content is taller than one page. */
  .receipt-header {
    page-break-after: avoid;
    break-after: avoid;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .receipt-row,
  .receipt-item-row,
  .receipt-total {
    page-break-inside: avoid;
    break-inside: avoid;
  }
  hr {
    page-break-after: avoid;
    break-after: avoid;
  }

  /* Fallback: if the user prints to A4/Letter instead of an 80mm roll,
     keep the receipt readable and pinned to the top-left instead of
     centered on a huge blank page. */
  @media print and (min-width: 150mm) {
    @page { size: A4; margin: 5mm; }
    html, body { width: auto; }
    body {
      font-size: 14px;
      max-width: 80mm;
    }
    .receipt-header h2 { font-size: 18px; }
    .receipt-total { font-size: 16px; }
  }
</style>
</head>
<body>${receipt.innerHTML}</body>
</html>`);
  doc.close();

  iframe.onload = () => {
    setTimeout(() => {
      iframe.contentWindow.focus();
      iframe.contentWindow.print();
      setTimeout(() => {
        if (document.body.contains(iframe)) {
          document.body.removeChild(iframe);
        }
      }, 2000);
    }, 400);
  };
};
}

// ── MODALS ──
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
});

// ── TOAST ──
function toast(msg, type = 'info', duration = 3200) {
  let wrap = document.getElementById('toast-wrap');
  if (!wrap) { wrap = document.createElement('div'); wrap.id = 'toast-wrap'; wrap.className = 'toast-wrap'; document.body.appendChild(wrap); }
  const el   = document.createElement('div');
  el.className = 'toast ' + type;
  const icons = { success:'✓', error:'✕', info:'ℹ' };
  el.innerHTML = `<span>${icons[type]||''}</span> ${escHtml(msg)}`;
  wrap.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, duration);
}

// ── UTILS ──
function escHtml(str) {
  const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML;
}

// ── QUEUE BADGE ──
async function updateQueueBadge() {
  try {
    const res  = await fetch(apiUrl('api/queue.php') + '?status=pending');
    const data = await res.json();
    const cnt  = data.count || 0;
    // topbar badge
    const tb = document.getElementById('queue-badge');
    if (tb) { tb.textContent = cnt; tb.style.display = cnt > 0 ? 'inline' : 'none'; }
    // sidebar badge
    const nb = document.getElementById('nav-queue-badge');
    if (nb) { nb.textContent = cnt; nb.style.display = cnt > 0 ? 'inline' : 'none'; }
  } catch(e) {}
}

// ── INIT ──
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('cart-items-wrap')) POS.init();
  // Poll queue count every 20 seconds on POS page
  updateQueueBadge();
  setInterval(updateQueueBadge, 20000);
});