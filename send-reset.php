<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/middleware/security.php';
SecurityMiddleware::initialize();
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php?error=1');
    exit;
}

$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: forgot_password.php?error=1');
    exit;
}

try {
    $conn = getLegacyDatabaseConnection();

    $stmt = $conn->prepare('SELECT applicant_id FROM applicants WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($applicantId);
        $stmt->fetch();

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $deleteStmt = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
        $deleteStmt->bind_param('i', $applicantId);
        $deleteStmt->execute();
        $deleteStmt->close();

        $insertStmt = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
        $insertStmt->bind_param('iss', $applicantId, $token, $expiresAt);
        $insertStmt->execute();
        $insertStmt->close();

        $resetLink = rtrim(BASE_URL, '/') . '/reset-password.php?token=' . urlencode($token);

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->Port = SMTP_PORT;
            $mail->SMTPSecure = (SMTP_PORT === 465)
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPDebug = 0;

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request - Vehicle Registration System';
            $mail->Body = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                    <h2 style="color: #d00000;">Password Reset Request</h2>
                    <p>Hello,</p>
                    <p>We received a request to reset your password for the Vehicle Registration System.</p>
                    <p>Click the button below to reset your password:</p>
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="' . $resetLink . '"
                           style="background-color: #d00000; color: white; padding: 12px 24px;
                                  text-decoration: none; border-radius: 5px; display: inline-block;">
                            Reset Password
                        </a>
                    </div>
                    <p>Or copy and paste this link into your browser:</p>
                    <p style="word-break: break-all; color: #666;">' . $resetLink . '</p>
                    <p><strong>This link will expire in 1 hour.</strong></p>
                    <p>If you did not request this password reset, please ignore this email.</p>
                </div>
            ';
            $mail->AltBody = "Password reset link: $resetLink";
            $mail->send();
        } catch (Exception $e) {
            logError('Password reset email error: ' . $e->getMessage(), 'ERROR');
        }
    }

    $stmt->close();
    $conn->close();
    header('Location: forgot_password.php?sent=1');
    exit;
} catch (Exception $e) {
    logError('Password reset request error: ' . $e->getMessage(), 'ERROR');
    header('Location: forgot_password.php?error=1');
    exit;
}
