<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/middleware/security.php';
SecurityMiddleware::initialize();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = $_GET['token'] ?? '';
$error = '';
$isValidToken = false;

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Invalid reset link.';
} else {
    try {
        $conn = getLegacyDatabaseConnection();
        $stmt = $conn->prepare('
            SELECT id
            FROM password_reset_tokens
            WHERE token = ? AND used = FALSE AND expires_at > NOW()
            LIMIT 1
        ');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->store_result();
        $isValidToken = $stmt->num_rows === 1;
        $stmt->close();
        $conn->close();

        if (!$isValidToken) {
            $error = 'This reset link is invalid or has expired.';
        }
    } catch (Exception $e) {
        logError('Reset link validation error: ' . $e->getMessage(), 'ERROR');
        $error = 'Unable to validate reset link.';
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Vehicle Registration System</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body { background: #121212; color: #eee; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .container { background: #1e1e1e; padding: 2rem 2.5rem; border-radius: 12px; box-shadow: 0 0 20px #d00000aa; max-width: 420px; width: 100%; }
        .logo { display: block; margin: 0 auto 1rem; height: 45px; filter: brightness(0) invert(1); }
        h2 { color: #d00000; text-align: center; margin-bottom: 1rem; }
        form { display: flex; flex-direction: column; gap: 0.9rem; }
        input[type="password"] { padding: 0.75rem; border: 2px solid #444; border-radius: 6px; background: #1e1e1e; color: #fff; font-size: 1rem; }
        button { background: #d00000; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; color: white; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.3s ease; }
        button:hover { background: #ff0000; }
        .alert { padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; text-align: center; }
        .alert-error { background: #5c0000; color: #ff7777; }
        .hint { color: #bbb; text-align: center; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="container">
    <img src="AULogo.png" alt="AU Logo" class="logo">
    <h2>Reset Password</h2>

    <?php if (!$isValidToken): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <div style="text-align:center;"><a href="forgot_password.php" style="color:#d00000;">Request a new reset link</a></div>
    <?php else: ?>
        <div class="hint">Choose a new password for your account.</div>
        <form method="post" action="process-reset.php">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="_token" value="<?= htmlspecialchars(SecurityMiddleware::generateCSRFToken()) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="password" name="password" placeholder="New password" required minlength="8" autocomplete="new-password">
            <input type="password" name="password_confirm" placeholder="Confirm new password" required minlength="8" autocomplete="new-password">
            <button type="submit">Reset Password</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
