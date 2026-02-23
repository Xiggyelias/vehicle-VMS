<?php
declare(strict_types=1);

/**
 * Legacy compatibility database bootstrap.
 *
 * For new code, include `config/database.php` directly and use:
 * - getDatabaseConnection() for PDO
 * - getLegacyDatabaseConnection() for mysqli
 */
require_once __DIR__ . '/config/database.php';

try {
    // Keep `$conn` for older scripts that expect it from config.php.
    $conn = getLegacyDatabaseConnection();
} catch (Throwable $e) {
    error_log('Database bootstrap failed in config.php: ' . $e->getMessage());

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }

    exit('Database connection failed. Please check deployment database settings and try again.');
}

