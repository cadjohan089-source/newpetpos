# 🍽️ Restaurant POS System
### Complete PHP Billing & Management Software

---

## 🚀 QUICK START (2 minutes)

### Option A — PHP Built-in Server (Easiest, no XAMPP needed)

1. **Extract** the ZIP anywhere on your computer, e.g. `C:\restaurant-pos\`

2. **Open terminal / command prompt** in that folder:
   ```
   cd C:\restaurant-pos
   ```

3. **Start the server:**
   ```
   php -S localhost:8000 router.php
   ```

4. **Open browser:**
   ```
   http://localhost:8000/
   ```

> ✅ The database creates itself automatically on first visit.

---

### Option B — XAMPP / WAMP / Laragon

1. **Extract** ZIP into your `htdocs` folder:
   ```
   C:\xampp\htdocs\restaurant-pos\
   ```

2. **Start Apache** from XAMPP Control Panel.

3. **Open browser:**
   ```
   http://localhost/restaurant-pos/
   ```

---

### Option C — Nginx (Linux/Mac)

```nginx
server {
    listen 80;
    server_name pos.local;
    root /var/www/restaurant-pos;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ^~ /data/ { deny all; }
}
```

---

## 🔐 Login Credentials

| Role    | Username | Password | Access |
|---------|----------|----------|--------|
| Admin   | admin    | admin123 | Everything |
| Cashier | cashier  | cash123  | Counter + Bill History |

> ⚠️ Change passwords after first login: **Admin → Users**

---

## ✅ Features

| Feature | Description |
|---------|-------------|
| 🛒 POS Counter | Product grid, cart, discount, payment methods |
| 🖨️ Print Bills | Receipt modal + thermal printer ready (80mm) |
| 📋 Bill History | Search, date filter, view/reprint any bill |
| 📊 Dashboard | Revenue charts, top products, recent bills |
| 🍱 Products | Add/edit/disable/delete menu items |
| 🏷️ Categories | Organise menu into categories |
| ✏️ Manage Bills | Admin: edit customer/table info, delete bills |
| 👤 Users | Multi-user with Admin & Cashier roles |
| 📉 Reports | Daily/monthly revenue, busiest hours, payment split |
| ⚙️ Settings | Restaurant name, tax %, currency, receipt footer |

---

## 📁 Project Structure

```
restaurant-pos/
├── index.php              ← POS Counter
├── bills.php              ← Bill History
├── login.php / logout.php ← Auth
├── router.php             ← PHP built-in server router
├── .htaccess              ← Apache config
│
├── admin/
│   ├── dashboard.php      ← Sales dashboard + charts
│   ├── products.php       ← Product management
│   ├── categories.php     ← Category management
│   ├── bills-admin.php    ← Edit/delete bills
│   ├── users.php          ← User management
│   ├── reports.php        ← Analytics & reports
│   └── settings.php       ← System settings
│
├── api/
│   ├── bills.php          ← Bills REST API
│   └── products.php       ← Products API
│
├── includes/
│   ├── db.php             ← Database + seed data
│   ├── auth.php           ← Session & authentication
│   ├── header.php         ← Layout header + sidebar
│   └── footer.php         ← Layout footer
│
├── assets/
│   ├── css/style.css      ← Full stylesheet
│   └── js/app.js          ← POS JavaScript
│
└── data/
    └── restaurant.db      ← SQLite database (auto-created)
```

---

## 🖨️ Printing Bills

1. Click **Save Bill** on counter → receipt modal opens
2. Click **🖨️ Print Bill** to send to printer
3. Works with any printer — optimised for **80mm thermal printers**
4. Reprint any old bill from **Bill History → View/Print**

---

## 🔧 Customise for Your Restaurant

Go to **Admin → Settings** to change:
- Restaurant name, address, phone
- Tax rate and currency symbol
- Bill number prefix (e.g. `SG` → `SG-250316-0001`)
- Receipt footer message

---

## 🗄️ Database

- Uses **SQLite** — zero configuration, no MySQL needed
- File: `data/restaurant.db` (created automatically)
- **Backup**: just copy `data/restaurant.db`
- **Reset everything**: delete `data/restaurant.db` and refresh

---

## 🆘 Troubleshooting

| Problem | Solution |
|---------|----------|
| HTTP 500 error | Make sure you run: `php -S localhost:8000 router.php` (not just `php -S localhost:8000`) |
| Blank white page | Check PHP version is 7.4+: `php -v` |
| CSS not loading | Access via `http://localhost:8000` not `file://` |
| Can't login | Delete `data/restaurant.db` to reset (loses all data) |
| "data folder" error | Create `data/` folder manually and give write permission |
| Print layout wrong | Use browser print, set margins to None, paper 80mm or A4 |

---

## ⚙️ Requirements

- PHP 7.4+ (PHP 8.x recommended)
- SQLite3 extension (enabled by default on most PHP installs)
- Any web server: Apache, Nginx, or PHP built-in server

---

*Restaurant POS v1.1 — PHP + SQLite — Works out of the box*
