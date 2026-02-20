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

function getDBConnection() {
    return getLegacyDatabaseConnection();
}

try {
    $conn = getDBConnection();

    $stmt = $conn->prepare("
        SELECT n.*, a.fullName AS user_name, a.registrantType AS user_role
        FROM notifications n
        JOIN applicants a ON n.user_id = a.applicant_id
        ORDER BY n.is_read ASC, n.created_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    $formattedNotifications = array_map(function($notification) {
        return [
            'id' => $notification['id'],
            'type' => $notification['type'],
            'role' => $notification['role'] ?? null,
            'user_name' => $notification['user_name'],
            'user_role' => $notification['user_role'],
            'message' => $notification['message'],
            'created_at' => date('M j, Y g:i A', strtotime($notification['created_at'])),
            'is_read' => (bool)$notification['is_read']
        ];
    }, $notifications);

    echo json_encode([
        'success' => true,
        'notifications' => $formattedNotifications
    ]);
} catch (Exception $e) {
    logError('Error fetching notifications: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => isDevelopment() ? ('Error fetching notifications: ' . $e->getMessage()) : 'Unable to fetch notifications right now.'
    ]);
}
