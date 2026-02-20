<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/middleware/security.php';
SecurityMiddleware::initialize();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Vehicle Registration System</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body { background: #121212; color: #eee; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .container { background: #1e1e1e; padding: 2rem 2.5rem; border-radius: 12px; box-shadow: 0 0 20px #d00000aa; max-width: 400px; width: 100%; }
        .logo { display: block; margin: 0 auto 1rem; height: 45px; filter: brightness(0) invert(1); }
        h2 { color: #d00000; text-align: center; margin-bottom: 1rem; }
        form { display: flex; flex-direction: column; gap: 1rem; }
        input[type="email"] { padding: 0.75rem; border: 2px solid #444; border-radius: 6px; background: #1e1e1e; color: #fff; font-size: 1.1rem; }
        button { background: #d00000; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; color: white; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.3s ease; }
        button:hover { background: #ff0000; }
        .info { color: #bbb; font-size: 1rem; text-align: center; margin-bottom: 1rem; }
        .alert { padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; text-align: center; }
        .alert-success { background: #005c00; color: #77ff77; }
        .alert-error { background: #5c0000; color: #ff7777; }
    </style>
</head>
<body>
<div class="container">
    <img src="AULogo.png" alt="AU Logo" class="logo">
    <h2>Forgot Password</h2>
    <div class="info">Enter your registered email to receive a password reset link.</div>
    <?php if (isset($_GET['sent'])): ?>
        <div class="alert alert-success">If your email exists, a reset link has been sent.</div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="alert alert-error">Unable to process your request. Please try again.</div>
    <?php endif; ?>
    <form method="post" action="send-reset.php">
        <input type="hidden" name="_token" value="<?= htmlspecialchars(SecurityMiddleware::generateCSRFToken()) ?>">
        <input type="email" name="email" placeholder="Email address" required autocomplete="email">
        <button type="submit">Send Reset Link</button>
    </form>
    <div style="margin-top:1rem;text-align:center;">
        <a href="login.php" style="color:#d00000;">Back to Login</a>
    </div>
</div>
</body>
</html>
