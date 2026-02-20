<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/middleware/security.php';
SecurityMiddleware::initialize();

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$notificationId = (int)($data['notification_id'] ?? 0);

if ($notificationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Notification ID is required']);
    exit;
}

function getDBConnection() {
    return getLegacyDatabaseConnection();
}

try {
    $conn = getDBConnection();

    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ?");
    $stmt->bind_param("i", $notificationId);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('Failed to mark notification as read');
    }

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    logError('Error marking notification as read: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => isDevelopment() ? ('Error marking notification as read: ' . $e->getMessage()) : 'Unable to update notification status right now.'
    ]);
}
