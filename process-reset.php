<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/middleware/security.php';
SecurityMiddleware::initialize();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = 'Invalid request.';
} else {
    $token = $_POST['token'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $error = 'Invalid or missing token.';
    } elseif (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = 'Invalid CSRF token.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $conn = getLegacyDatabaseConnection();

            $stmt = $conn->prepare('
                SELECT prt.id, prt.user_id, prt.expires_at
                FROM password_reset_tokens prt
                WHERE prt.token = ? AND prt.used = FALSE
                LIMIT 1
            ');
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($tokenId, $userId, $expiresAt);
                $stmt->fetch();

                if (strtotime($expiresAt) > time()) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    $updateUserStmt = $conn->prepare('UPDATE applicants SET password = ? WHERE applicant_id = ?');
                    $updateUserStmt->bind_param('si', $passwordHash, $userId);

                    if ($updateUserStmt->execute()) {
                        $markUsedStmt = $conn->prepare('UPDATE password_reset_tokens SET used = TRUE WHERE id = ?');
                        $markUsedStmt->bind_param('i', $tokenId);
                        $markUsedStmt->execute();
                        $markUsedStmt->close();

                        $success = true;
                        unset($_SESSION['csrf_token']);
                    } else {
                        $error = 'Could not update password.';
                    }
                    $updateUserStmt->close();
                } else {
                    $error = 'This reset link has expired.';
                }
            } else {
                $error = 'Invalid reset link.';
            }

            $stmt->close();
            $conn->close();
        } catch (Exception $e) {
            logError('Password reset processing error: ' . $e->getMessage(), 'ERROR');
            $error = 'Database connection error.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - Vehicle Registration System</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        body { background: #121212; color: #eee; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .container { background: #1e1e1e; padding: 2rem 2.5rem; border-radius: 12px; box-shadow: 0 0 20px #d00000aa; max-width: 400px; width: 100%; }
        .logo { display: block; margin: 0 auto 1rem; height: 45px; filter: brightness(0) invert(1); }
        h2 { color: #d00000; text-align: center; margin-bottom: 1rem; }
        .alert { padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; text-align: center; }
        .alert-success { background: #005c00; color: #77ff77; }
        .alert-error { background: #5c0000; color: #ff7777; }
        a { color: #d00000; }
    </style>
</head>
<body>
<div class="container">
    <img src="AULogo.png" alt="AU Logo" class="logo">
    <h2>Password Reset</h2>
    <?php if ($success): ?>
        <div class="alert alert-success">Your password has been reset. <a href="login.php">Login</a></div>
    <?php else: ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <div style="text-align:center;"><a href="forgot_password.php">Request new link</a></div>
    <?php endif; ?>
</div>
</body>
</html>
