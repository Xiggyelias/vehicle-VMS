<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$checks = [];
$overallHealthy = true;

$checks['php'] = [
    'ok' => true,
    'version' => PHP_VERSION
];

try {
    require_once __DIR__ . '/config/app.php';
    require_once __DIR__ . '/config/database.php';
} catch (Throwable $e) {
    $overallHealthy = false;
    $checks['bootstrap'] = [
        'ok' => false,
        'error' => 'Configuration bootstrap failed'
    ];
    http_response_code(503);
    echo json_encode([
        'status' => 'degraded',
        'checks' => $checks,
        'timestamp' => date('c')
    ]);
    exit;
}

$dbHealthy = true;
try {
    $conn = getLegacyDatabaseConnection();
    $conn->query('SELECT 1');
    $conn->close();
} catch (Throwable $e) {
    $dbHealthy = false;
    $overallHealthy = false;
}

$checks['database'] = [
    'ok' => $dbHealthy
];

$uploadsWritable = is_dir(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads');
$logsWritable = is_dir(__DIR__ . '/logs') && is_writable(__DIR__ . '/logs');

if (!$uploadsWritable || !$logsWritable) {
    $overallHealthy = false;
}

$checks['storage'] = [
    'ok' => $uploadsWritable && $logsWritable,
    'uploads_writable' => $uploadsWritable,
    'logs_writable' => $logsWritable
];

$status = $overallHealthy ? 'ok' : 'degraded';
http_response_code($overallHealthy ? 200 : 503);

echo json_encode([
    'status' => $status,
    'app_env' => defined('APP_ENV') ? APP_ENV : null,
    'checks' => $checks,
    'timestamp' => date('c')
], JSON_UNESCAPED_SLASHES);
