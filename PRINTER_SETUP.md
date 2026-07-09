# 🖨️ Thermal Printer Setup Guide

Complete guide to set up direct thermal printing for the **posale.axcelstudio.com** POS using **QZ Tray** and your **BC-80POS** thermal printer.

After setup, clicking **Print** in the POS will send receipts **directly** to the thermal printer — no browser print dialog, no paper size issues, no blank tail. The receipt prints and the printer auto-cuts.

---

## Architecture overview

```
[Cashier's browser]
       │
       │  receipt request
       ▼
[posale.axcelstudio.com PHP server]
       │
       │  builds ESC/POS bytes (mike42/escpos-php)
       │  returns base64
       ▼
[Cashier's browser]
       │
       │  passes bytes to QZ Tray (WebSocket on localhost)
       ▼
[QZ Tray on cashier PC]
       │
       │  raw bytes
       ▼
[BC-80POS thermal printer via USB]  →  prints + cuts
```

---

## Part 1 — One-time server setup (on posale.axcelstudio.com)

These steps install the `mike42/escpos-php` library on your hosting account. Done once.

### 1.1 Verify Composer is available

SSH into your hosting (or use the file manager / cPanel terminal):

```bash
composer --version
```

If Composer isn't installed, install it:

```bash
cd ~
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer  # or wherever you have write access
```

On most shared hosting (cPanel, etc.) Composer is preinstalled. If not, you can install dependencies on a local machine and upload the `vendor/` folder via FTP — that also works.

### 1.2 Install dependencies

Navigate to the POS project folder on the server and run:

```bash
cd /path/to/posale.axcelstudio.com
composer install --no-dev --optimize-autoloader
```

This creates a `vendor/` folder containing `mike42/escpos-php`. It's about 1 MB.

### 1.3 Verify the API endpoints

Visit these URLs in your browser (you need to be logged in to the POS first):

- `https://posale.axcelstudio.com/api/print-receipt.php?bill_no=YOUR_TEST_BILL_NO`
- `https://posale.axcelstudio.com/api/print-kitchen.php?queue_id=1`

You should see a JSON response with a `base64` field. That's the ESC/POS bytes ready to print.

### 1.4 Configure printer settings in admin panel

In the POS, go to **Admin → Settings**. The new "🖨️ Thermal Printer (QZ Tray)" section should appear. Configure:

| Field | Recommended value |
|---|---|
| Receipt Printer Name | `BC-80POS` (or whatever name your printer has in Windows) |
| Kitchen Printer Name | `BC-80POS` (same printer for now) |
| Paper Width | `80 mm (standard)` |
| Auto-print after bill save | `Yes` |
| Cash Drawer Kick | `Off` (no drawer connected) |

Save settings.

---

## Part 2 — One-time setup on each cashier PC

Repeat these steps on every PC that needs to print receipts.

### 2.1 Install QZ Tray

1. Go to **https://qz.io/download/** and download QZ Tray for Windows.
2. Run the installer with default options.
3. After install, QZ Tray runs in the system tray (look for the QZ icon near the clock). It auto-starts on Windows boot.

### 2.2 Verify the printer is installed

1. **Win + R** → `control printers` → Enter.
2. Confirm your printer is listed (e.g., **BC-80POS**).
3. Right-click the printer → **Set as default printer** (optional but convenient).
4. **Important**: note the *exact* printer name shown in Windows — it must match what you put in admin Settings → "Receipt Printer Name".

### 2.3 First-use authorization

The first time a cashier prints from posale.axcelstudio.com on this PC:

1. Cashier creates a bill and clicks **Print**.
2. QZ Tray pops up a dialog: **"posale.axcelstudio.com is requesting access to print"**. 
3. Cashier clicks **Allow**.
4. **Tick "Remember this decision"** so it doesn't ask again.

For an unsigned/community-mode setup, this prompt appears once per browser session. To remove it permanently, see "Part 4 — Removing the security prompt" below (paid license).

### 2.4 Test print

1. Create a small test bill in the POS.
2. Save it. With Auto-print enabled, the receipt should print **immediately** on the thermal printer.
3. Verify:
   - ✅ Restaurant name large and bold at the top
   - ✅ All items aligned in two columns (name left, price right)
   - ✅ Total in big bold text
   - ✅ Paper cuts automatically after the footer
   - ✅ No blank space, no extra paper feed

---

## Part 3 — Troubleshooting

### Nothing prints, no error in the POS

- Open browser DevTools (F12) → Console tab.
- Look for messages starting with `[QZ Tray]`.
- **"not available"** → QZ Tray isn't running on this PC. Check the system tray for the QZ icon. If missing, launch QZ Tray from the Start menu.
- **"auto-print failed"** → QZ Tray is running but rejected the print. Check the QZ Tray system tray icon → "Show Activity" → look at the error.

### "Printer not found" error

The printer name in admin Settings doesn't match the actual Windows printer name. Open Control Panel → Devices and Printers — copy the *exact* name (case-sensitive, including spaces) and paste it into Settings → Receipt Printer Name.

### Receipt prints but text is garbled / wrong characters

The printer isn't ESC/POS compatible (rare) or is in a different code page mode. Open Settings → reduce Paper Width to **58mm** if the printer is a 58mm model. If still garbled, contact AXCEL support — we'll adjust the character encoding in `api/print-receipt.php`.

### QZ Tray asks for permission every time

Either:
1. The cashier didn't tick "Remember this decision" — click Allow again and tick the box.
2. They're in private/incognito mode — switch to a normal browser window.
3. For permanent removal: buy a QZ Tray license (see Part 4).

### Printing works on one PC but not another

Each cashier PC needs its own QZ Tray install. Repeat Part 2 on the new PC.

### "QZ Tray print failed — using browser print" toast appears

This means the browser tried QZ Tray, it failed for some reason, and fell back to the normal browser print dialog. The receipt still prints, but you'll see the print dialog. Check QZ Tray activity log to see why it failed.

### Bills.php / Bill History — receipt looks fine in modal but prints garbled

The receipt modal shows HTML preview. The actual print goes via ESC/POS bytes (different format). If preview is fine but print is bad, the bug is in `api/print-receipt.php`, not the frontend. Check the server response by visiting the API URL directly.

---

## Part 4 — Removing the security prompt (optional, production)

QZ Tray's free/community mode shows the "Allow access" prompt because the connection is unsigned. To remove it permanently for production deployments:

1. Buy a **QZ Tray code signing certificate** from https://qz.io/licensing/ (~$40 one-time per deployment, or a multi-license bundle for agencies).
2. Generate your certificate using QZ Tray's web signing tool.
3. Upload `cert.pem` and `private-key.pem` somewhere readable by your PHP server.
4. Update `assets/js/qz-print.js`:

```javascript
// Replace the community-mode stubs at the bottom with:
qz.security.setCertificatePromise(function(resolve, reject) {
    fetch('/qz-cert.pem')
        .then(r => r.text())
        .then(resolve)
        .catch(reject);
});

qz.security.setSignaturePromise(function(toSign) {
    return function(resolve, reject) {
        fetch('/api/qz-sign.php?request=' + encodeURIComponent(toSign))
            .then(r => r.text())
            .then(resolve)
            .catch(reject);
    };
});
```

5. Create `api/qz-sign.php` that uses your private key to sign each request server-side (Anthropic / AXCEL can provide the template).

After this, no more permission prompts on any browser.

---

## Part 5 — How auto-print works

When **Auto-print** is enabled in Settings:

1. Cashier saves a bill in `index.php` (POS Counter) OR converts a queue order to a bill in `queue.php`.
2. The bill saves to the database.
3. The receipt modal opens on screen (so the cashier can verify visually).
4. **In parallel**, the JS calls `window.QZPrint.printReceipt(bill_no)`.
5. QZ Tray fetches the ESC/POS bytes from `api/print-receipt.php` and sends them to BC-80POS.
6. The printer prints and cuts immediately.

If QZ Tray isn't running, step 4 silently fails and the cashier can still click the Print button in the modal to use the browser-print fallback.

---

## Part 6 — Falling back to browser printing

If QZ Tray is unavailable (not installed, not running, or rejected the request):

- **The Print button still works** — it uses the existing iframe-based browser print as a fallback.
- The cashier sees the normal Chrome print dialog and prints manually.
- All existing CSS/layout work remains in place.

This means: **QZ Tray is an enhancement, not a hard dependency**. Cashiers without QZ Tray can still print receipts the old way.

---

## Need help?

Contact AXCEL support:
- Web: https://axcelworld.com/
- Tag this issue with: "posale POS — printing"
