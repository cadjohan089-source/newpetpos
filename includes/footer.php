  </div><!-- .main -->
</div><!-- .app-shell -->

<!-- RECEIPT MODAL -->
<div class="modal-overlay" id="receipt-modal">
  <div class="modal-box" style="max-width:400px">
    <div class="modal-header">
      <span class="modal-title">Bill Receipt</span>
      <button class="modal-close" onclick="closeModal('receipt-modal')">×</button>
    </div>
    <div class="modal-body" id="receipt-modal-body"></div>
    <div class="modal-footer no-print">
      <button class="btn btn-secondary" onclick="closeModal('receipt-modal')">Close</button>
      <button class="btn btn-primary" id="btn-print-receipt" onclick="window.print()">🖨️ Print Bill</button>
    </div>
  </div>
</div>

<div id="toast-wrap" class="toast-wrap"></div>
<script>
// Inject base URL so JS can hit the right API paths
window.BASE_URL = '<?= baseUrl() ?>';
function apiUrl(path) { return BASE_URL.replace(/\/$/, '') + '/' + path.replace(/^\//, ''); }
</script>
<script src="<?= baseUrl('assets/js/app.js') ?>"></script>
<?php if (isset($extraJS)) echo $extraJS; ?>
</body>
</html>
