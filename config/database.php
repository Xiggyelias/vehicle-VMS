<?php
/**
 * Database Configuration
 * 
 * This file contains all database-related configuration settings.
 * Centralized configuration makes it easier to manage database connections
 * and switch between different environments (development, production, etc.).
 */

// Load environment variables
require_once __DIR__ . '/env.php';

// Database configuration constants
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env_int('DB_PORT', 3306));
define('DB_USERNAME', env('DB_USERNAME', 'root'));
define('DB_PASSWORD', env('DB_PASSWORD', ''));
define('DB_NAME', env('DB_NAME', 'vehicleregistrationsystem'));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));
define('DB_CONNECT_MAX_ATTEMPTS', max(1, env_int('DB_CONNECT_MAX_ATTEMPTS', 3)));
define('DB_CONNECT_RETRY_MS', max(0, env_int('DB_CONNECT_RETRY_MS', 250)));

// Database connection options
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
]);

/**
 * Get Database Connection
 * 
 * Creates and returns a PDO database connection with proper error handling.
 * Uses singleton pattern to ensure only one connection is created per request.
 * 
 * @return PDO Database connection object
 * @throws Exception If connection fails
 */
function getDatabaseConnection() {
    static $connection = null;
    
    if ($connection === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $lastException = null;

        for ($attempt = 1; $attempt <= DB_CONNECT_MAX_ATTEMPTS; $attempt++) {
            try {
                $connection = new PDO($dsn, DB_USERNAME, DB_PASSWORD, DB_OPTIONS);
                break;
            } catch (PDOException $e) {
                $lastException = $e;
                if ($attempt < DB_CONNECT_MAX_ATTEMPTS && DB_CONNECT_RETRY_MS > 0) {
                    usleep(DB_CONNECT_RETRY_MS * 1000);
                }
            }
        }

        if ($connection === null) {
            $errorMessage = $lastException ? $lastException->getMessage() : 'Unknown database error';
            error_log("Database connection failed to " . DB_HOST . ":" . DB_PORT . " after " . DB_CONNECT_MAX_ATTEMPTS . " attempt(s): " . $errorMessage);
            throw new Exception("Database connection failed. Please try again later.");
        }
    }
    
    return $connection;
}

/**
 * Get Legacy MySQL Connection (for backward compatibility)
 * 
 * Creates and returns a mysqli connection for existing code that hasn't been
 * migrated to PDO yet. This should be gradually replaced with PDO.
 * 
 * @return mysqli Database connection object
 * @throws Exception If connection fails
 */
function getLegacyDatabaseConnection() {
    $lastError = null;

    for ($attempt = 1; $attempt <= DB_CONNECT_MAX_ATTEMPTS; $attempt++) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $connection = @new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);

        if ($connection && !$connection->connect_error) {
            $connection->set_charset(DB_CHARSET);
            return $connection;
        }

        $lastError = $connection && $connection->connect_error ? $connection->connect_error : 'Unknown connection error';
        if ($connection instanceof mysqli) {
            $connection->close();
        }

        if ($attempt < DB_CONNECT_MAX_ATTEMPTS && DB_CONNECT_RETRY_MS > 0) {
            usleep(DB_CONNECT_RETRY_MS * 1000);
        }
    }

    error_log("Legacy database connection failed to " . DB_HOST . ":" . DB_PORT . " after " . DB_CONNECT_MAX_ATTEMPTS . " attempt(s): " . $lastError);
    throw new Exception("Database connection failed. Please verify DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, and DB_NAME.");
}

/**
 * Close Database Connection
 * 
 * Properly closes the database connection to free up resources.
 * 
 * @param PDO|mysqli $connection Database connection to close
 */
function closeDatabaseConnection($connection) {
    if ($connection instanceof PDO) {
        $connection = null;
    } elseif ($connection instanceof mysqli) {
        $connection->close();
    }
} 
