/**
 * qz-print.js
 *
 * Browser-side bridge to QZ Tray for thermal printing.
 * Loads after qz-tray.js is loaded from the CDN.
 *
 * Public API:
 *   window.QZPrint.isAvailable()          → boolean (true if QZ Tray is reachable)
 *   window.QZPrint.printReceipt(billNo)   → Promise — prints customer receipt
 *   window.QZPrint.printKitchen(queueId)  → Promise — prints kitchen slip
 *
 * If QZ Tray is not installed or not running, isAvailable() returns false
 * and callers should fall back to the existing browser-print flow.
 */

(function() {
  'use strict';

  const QZP = {
    _connected: false,
    _connecting: null,

    /**
     * Try to connect to QZ Tray. Returns a Promise<boolean>.
     * Subsequent calls return the cached connection.
     */
    async _connect() {
      if (typeof qz === 'undefined') {
        console.warn('[QZ Tray] qz-tray.js library not loaded');
        return false; // QZ Tray library not loaded
      }
      if (qz.websocket.isActive()) {
        this._connected = true;
        return true;
      }
      if (this._connecting) return this._connecting;

      // Force explicit port 8181 and skip the port-loop and localhost.qz.io fallback
      this._connecting = qz.websocket.connect({
        host: ['localhost'],          // Don't try localhost.qz.io
        usingSecure: true,            // wss:// not ws://
        port: { secure: [8181], insecure: [8182] },  // Match QZ Tray 2.2.6 actual ports
        keepAlive: 60,
        retries: 1,
        delay: 1
      })
      .then(() => {
        console.log('[QZ Tray] WebSocket connected on port 8181');
        this._connected = true;
        return true;
      })
      .catch((err) => {
        console.warn('[QZ Tray] connection failed:', err && err.message ? err.message : err);
        this._connected = false;
        return false;
      })
      .finally(() => {
        this._connecting = null;
      });

      return this._connecting;
    },

    /** Quick check: is QZ Tray reachable right now? */
    async isAvailable() {
      try {
        return await this._connect();
      } catch (e) {
        return false;
      }
    },

    /**
     * Send raw base64-encoded ESC/POS bytes to a specific printer.
     */
    async _sendBytes(printerName, base64Bytes) {
      const config = qz.configs.create(printerName, {
        encoding: 'UTF-8'
      });

      const data = [{
        type: 'raw',
        format: 'base64',
        data: base64Bytes
      }];

      return qz.print(config, data);
    },

    /**
     * Print customer receipt for a given bill_no.
     * Fetches ESC/POS bytes from server, then sends to local printer.
     */
    async printReceipt(billNo) {
      const ok = await this._connect();
      if (!ok) throw new Error('QZ Tray not available');

      const url = (window.API_BASE || '') + 'api/print-receipt.php?bill_no=' + encodeURIComponent(billNo);
      const res = await fetch(url, { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Failed to build receipt');

      return this._sendBytes(data.printer, data.base64);
    },

    /**
     * Print kitchen slip for a given queue_id.
     */
    async printKitchen(queueId) {
      const ok = await this._connect();
      if (!ok) throw new Error('QZ Tray not available');

      const url = (window.API_BASE || '') + 'api/print-kitchen.php?queue_id=' + encodeURIComponent(queueId);
      const res = await fetch(url, { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Failed to build kitchen slip');

      return this._sendBytes(data.printer, data.base64);
    },

    /**
     * Optional: list installed printers on the cashier PC (for debugging).
     */
    async listPrinters() {
      const ok = await this._connect();
      if (!ok) throw new Error('QZ Tray not available');
      return qz.printers.find();
    }
  };

  // ──────────────────────────────────────────────────────────────
  // QZ Tray security setup
  //
  // QZ Tray requires either:
  //   (a) a signed certificate (paid license), OR
  //   (b) running QZ Tray in "Allow Unsigned" / community mode —
  //       the user clicks Allow once per session.
  //
  // For community mode, we set a dummy promise resolver so QZ Tray
  // doesn't throw when the signing callback is missing.
  // ──────────────────────────────────────────────────────────────
  if (typeof qz !== 'undefined') {
    // No certificate — community/unsigned mode
    qz.security.setCertificatePromise(function(resolve, reject) {
      resolve();  // empty cert
    });
    qz.security.setSignaturePromise(function(toSign) {
      return function(resolve, reject) {
        resolve();  // empty signature
      };
    });
  }

  window.QZPrint = QZP;

  // Auto-attempt connection on page load so isAvailable() is fast
  document.addEventListener('DOMContentLoaded', function() {
    QZP._connect().then(function(ok) {
      if (ok) {
        console.log('[QZ Tray] connected — direct printing enabled');
      } else {
        console.log('[QZ Tray] not available — will use browser print fallback');
      }
    });
  });

})();