<?php
require_once __DIR__ . '/config.php';
session_start();

function is_authed() {
    return !empty($_SESSION['fwi_authed']);
}

function require_auth_or_show_login() {
    if (is_authed()) return;

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (ADMIN_PASSWORD_HASH !== 'REPLACE_ME' && password_verify($_POST['password'], ADMIN_PASSWORD_HASH)) {
            $_SESSION['fwi_authed'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $error = 'Incorrect password.';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Insights, Sign in</title>
      <link rel="stylesheet" href="../css/style.css">
      <style>
        body{ align-items: center; }
        .login-card{ max-width: 380px; padding: 48px 40px; }
        .login-card input{ margin-bottom: 16px; }
      </style>
    </head>
    <body>
      <div class="backdrop" aria-hidden="true">
        <div class="blob blob-a"></div><div class="blob blob-b"></div>
      </div>
      <main class="stage">
        <div class="card login-card">
          <p class="eyebrow">Fortis Wealth Insights</p>
          <h2 class="display-sm">Sign in to view insights</h2>
          <?php if ($error): ?><p class="error" style="margin-bottom:16px;"><?= htmlspecialchars($error) ?></p><?php endif; ?>
          <form method="post">
            <input type="password" name="password" placeholder="Admin password" required autofocus>
            <button type="submit" class="btn btn-primary" style="width:100%;">Sign in</button>
          </form>
        </div>
      </main>
    </body>
    </html>
    <?php
    exit;
}
