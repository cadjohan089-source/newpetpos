<?php
require_once __DIR__ . '/includes/auth.php';
startSession();

$error = '';
if (isset($_GET['err']) && $_GET['err'] === 'nostore') {
    $error = 'Your account is not assigned to any store. Contact super admin.';
}

// Redirect logged-in users — but never back to login.php (that causes a redirect loop)
if (isLoggedIn()) {
    $dest = loginRedirectUrl();
    if (strpos($dest, 'login.php') === false) {
        header('Location: ' . $dest);
        exit;
    }
    if (!$error) {
        $error = 'Your account is not assigned to any store. Please contact the super admin or log out and use another account.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        $dest = loginRedirectUrl();
        if (strpos($dest, 'login.php') === false) {
            header('Location: ' . $dest);
            exit;
        }
        $error = 'Your account is not assigned to any store. Contact super admin.';
    } else {
        $error = 'Invalid username or password.';
    }
}
$resName = 'Multi-Store POS';
$showLogout = isLoggedIn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In — <?= htmlspecialchars($resName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --brand: #e85d26; --dark: #0b0e1a; --card: #1a2038; --border: rgba(255,255,255,.07);
  --text: #f0f2f8; --text2: #8892b0; --text3: #4a5568;
  --font: 'Plus Jakarta Sans', sans-serif; --serif: 'Playfair Display', Georgia, serif;
}
html, body { height: 100%; }
body { font-family: var(--font); background: var(--dark); color: var(--text); min-height: 100vh; display: flex; overflow: hidden; }
.bg-canvas { position: fixed; inset: 0; z-index: 0; }
.bg-canvas::before { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse 80% 60% at 20% 50%, rgba(232,93,38,.18) 0%, transparent 60%); }
.page { position: relative; z-index: 1; display: flex; width: 100%; min-height: 100vh; align-items: center; justify-content: center; }
.login-container { width: 100%; max-width: 460px; padding: 20px; }
.form-card { width: 100%; background: rgba(26,32,56,.7); backdrop-filter: blur(24px); border: 1px solid var(--border); border-radius: 24px; padding: 40px 36px; box-shadow: 0 25px 60px rgba(0,0,0,.5); animation: fadeUp .4s ease both; }
.card-brand { display: flex; flex-direction: column; align-items: center; margin-bottom: 32px; text-align: center; }
.brand-icon-large { width: 72px; height: 72px; background: var(--brand); border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 16px; }
.brand-name-large { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.brand-tagline-large { font-size: 12px; color: var(--text2); text-transform: uppercase; letter-spacing: .06em; }
.form-title { font-family: var(--serif); font-size: 28px; font-weight: 800; text-align: center; margin-bottom: 8px; }
.form-subtitle { font-size: 14px; color: var(--text2); margin-bottom: 32px; text-align: center; }
.field { margin-bottom: 20px; }
.field label { display: block; font-size: 11px; font-weight: 700; color: var(--text2); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 7px; }
.input-wrap { position: relative; }
.input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 15px; opacity: .45; }
.field input { width: 100%; padding: 14px 14px 14px 42px; background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.1); border-radius: 10px; font-family: var(--font); font-size: 14px; color: var(--text); outline: none; }
.field input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(232,93,38,.15); }
.error-box { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 10px; background: rgba(229,62,62,.1); border: 1px solid rgba(229,62,62,.25); margin-bottom: 18px; }
.error-box span { font-size: 13px; color: #fc8181; }
.submit-btn { width: 100%; padding: 15px; background: var(--brand); border: none; border-radius: 10px; font-family: var(--font); font-size: 15px; font-weight: 700; color: #fff; cursor: pointer; margin-top: 12px; }
.submit-btn:hover { background: #ff6b35; }
.form-footer { text-align: center; margin-top: 32px; font-size: 11px; color: var(--text3); border-top: 1px solid var(--border); padding-top: 24px; }
@keyframes fadeUp { from { opacity:0; transform: translateY(16px); } to { opacity:1; transform: translateY(0); } }
</style>
</head>
<body>
<div class="bg-canvas"></div>
<div class="page">
  <div class="login-container">
    <div class="form-card">
      <div class="card-brand">
        <img src="<?= baseUrl('assets/default-logo.png') ?>" alt="DoctorPetStore" style="width:120px;height:auto;border-radius:12px;margin-bottom:16px">
        <div class="brand-name-large"><?= htmlspecialchars($resName) ?></div>
        <div class="brand-tagline-large">Multi-Store Point of Sale</div>
      </div>
      <h2 class="form-title">Welcome Back</h2>
      <p class="form-subtitle">Sign in to your assigned store</p>
      <?php if ($error): ?>
      <div class="error-box"><span>⚠</span><span><?= htmlspecialchars($error) ?></span></div>
      <?php endif; ?>
      <form method="POST" action="<?= baseUrl('login.php') ?>" id="login-form">
        <div class="field">
          <label>Username</label>
          <div class="input-wrap">
            <span class="input-icon">👤</span>
            <input type="text" name="username" placeholder="Enter your username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
          </div>
        </div>
        <div class="field">
          <label>Password</label>
          <div class="input-wrap">
            <span class="input-icon">🔑</span>
            <input type="password" name="password" placeholder="Enter your password" required>
          </div>
        </div>
        <button type="submit" class="submit-btn" id="submit-btn">Sign In →</button>
      </form>
      <?php if ($showLogout): ?>
      <p style="text-align:center;margin-top:16px">
        <a href="<?= baseUrl('logout.php') ?>" style="color:var(--text2);font-size:13px">Log out and try another account</a>
      </p>
      <?php endif; ?>
      <div class="form-footer">© <?= date('Y') ?> Multi-Store POS v2.0</div>
    </div>
  </div>
</div>
<script>
document.getElementById('login-form').addEventListener('submit', function() {
  const btn = document.getElementById('submit-btn');
  btn.textContent = 'Signing in...';
  btn.disabled = true;
});
</script>
</body>
</html>
