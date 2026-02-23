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
// Docker/Dokploy runtime note:
// - Inside containers, "localhost" points to the PHP container itself, not MySQL.
// - Default host therefore uses the MySQL service name "db".
$resolvedDbHost = trim((string) env('DB_HOST', env('MYSQL_HOST', env('MYSQLHOST', 'db'))));
if ($resolvedDbHost === '') {
    $resolvedDbHost = 'db';
}

$isContainerRuntime = file_exists('/.dockerenv') || env_bool('RUNNING_IN_DOCKER', false);
if ($isContainerRuntime && in_array(strtolower($resolvedDbHost), ['localhost', '127.0.0.1', '::1'], true)) {
    $resolvedDbHost = 'db';
}

$resolvedDatabaseName = trim((string) env('DB_DATABASE', env('DB_NAME', env('MYSQL_DATABASE', 'vehicleregistrationsystem'))));
if ($resolvedDatabaseName === '') {
    $resolvedDatabaseName = 'vehicleregistrationsystem';
}

define('DB_HOST', $resolvedDbHost);
define('DB_PORT', max(1, env_int('DB_PORT', env_int('MYSQL_PORT', env_int('MYSQLPORT', env_int('MYSQL_TCP_PORT', 3306))))));
define('DB_USERNAME', env('DB_USERNAME', env('DB_USER', env('MYSQL_USER', env('MYSQLUSER', 'root')))));
define('DB_PASSWORD', env('DB_PASSWORD', env('MYSQL_PASSWORD', env('MYSQLPASSWORD', ''))));
define('DB_NAME', $resolvedDatabaseName);
define('DB_DATABASE', DB_NAME); // Preferred alias used by many platforms/frameworks.
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));
define('DB_CONNECT_MAX_ATTEMPTS', max(1, env_int('DB_CONNECT_MAX_ATTEMPTS', 3)));
define('DB_CONNECT_RETRY_MS', max(0, env_int('DB_CONNECT_RETRY_MS', 250)));
define('DB_HOST_FALLBACKS', env_array('DB_HOST_FALLBACKS', []));

// Database connection options
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
]);

/**
 * Parses a mysql-style connection URL into connection parts.
 *
 * @param string $url
 * @return array|null
 */
function parseDatabaseUrl($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return null;
    }

    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== '' && !in_array($scheme, ['mysql', 'mariadb'], true)) {
        return null;
    }

    $database = ltrim((string)($parts['path'] ?? ''), '/');

    return [
        'host' => (string)$parts['host'],
        'port' => isset($parts['port']) ? (int)$parts['port'] : 3306,
        'username' => isset($parts['user']) ? (string)urldecode($parts['user']) : '',
        'password' => isset($parts['pass']) ? (string)urldecode($parts['pass']) : '',
        'database' => $database
    ];
}

/**
 * Returns candidate DB connection configs to try in order.
 *
 * @return array<int, array<string, mixed>>
 */
function getDatabaseConnectionCandidates() {
    static $candidates = null;
    if ($candidates !== null) {
        return $candidates;
    }

    $candidates = [];
    $seen = [];
    $addCandidate = function($host, $port, $username, $password, $database, $source) use (&$candidates, &$seen) {
        $host = trim((string)$host);
        $username = (string)$username;
        $password = (string)$password;
        $database = trim((string)$database);
        $port = (int)$port;

        if ($host === '' || $database === '' || $username === '') {
            return;
        }

        $key = hash('sha256', json_encode([$host, $port, $username, $password, $database]));
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        $candidates[] = [
            'host' => $host,
            'port' => $port > 0 ? $port : 3306,
            'username' => $username,
            'password' => $password,
            'database' => $database,
            'source' => (string)$source
        ];
    };

    // Primary explicit DB_* config.
    $addCandidate(DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, DB_NAME, 'DB_*');

    // Optional fallback hosts with same credentials/database.
    foreach (DB_HOST_FALLBACKS as $fallbackHost) {
        $addCandidate($fallbackHost, DB_PORT, DB_USERNAME, DB_PASSWORD, DB_NAME, 'DB_HOST_FALLBACKS');
    }

    // Common managed DB aliases injected by platforms.
    $addCandidate(
        env('MYSQLHOST', env('MYSQL_HOST', '')),
        env_int('MYSQLPORT', env_int('MYSQL_PORT', DB_PORT)),
        env('MYSQLUSER', env('MYSQL_USER', '')),
        env('MYSQLPASSWORD', env('MYSQL_PASSWORD', '')),
        env('MYSQLDATABASE', env('MYSQL_DATABASE', DB_NAME)),
        'MYSQL*'
    );

    // URL-based managed DB configs.
    $urlCandidate = parseDatabaseUrl(env('DATABASE_URL', env('MYSQL_URL', '')));
    if (is_array($urlCandidate)) {
        $addCandidate(
            $urlCandidate['host'],
            $urlCandidate['port'],
            $urlCandidate['username'],
            $urlCandidate['password'],
            $urlCandidate['database'] !== '' ? $urlCandidate['database'] : DB_NAME,
            'DATABASE_URL'
        );
    }

    // Ensure at least one candidate exists for error reporting.
    if (empty($candidates)) {
        $candidates[] = [
            'host' => DB_HOST,
            'port' => DB_PORT,
            'username' => DB_USERNAME,
            'password' => DB_PASSWORD,
            'database' => DB_NAME,
            'source' => 'DB_*'
        ];
    }

    return $candidates;
}

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
        $candidates = getDatabaseConnectionCandidates();
        $lastException = null;
        $attemptedTargets = [];

        foreach ($candidates as $candidate) {
            $target = $candidate['source'] . ':' . $candidate['host'] . ':' . $candidate['port'] . '/' . $candidate['database'];
            $attemptedTargets[] = $target;
            $dsn = "mysql:host=" . $candidate['host'] . ";port=" . $candidate['port'] . ";dbname=" . $candidate['database'] . ";charset=" . DB_CHARSET;

            for ($attempt = 1; $attempt <= DB_CONNECT_MAX_ATTEMPTS; $attempt++) {
                try {
                    $connection = new PDO($dsn, $candidate['username'], $candidate['password'], DB_OPTIONS);
                    break 2;
                } catch (PDOException $e) {
                    $lastException = $e;
                    if ($attempt < DB_CONNECT_MAX_ATTEMPTS && DB_CONNECT_RETRY_MS > 0) {
                        usleep(DB_CONNECT_RETRY_MS * 1000);
                    }
                }
            }
        }

        if ($connection === null) {
            $errorMessage = $lastException ? $lastException->getMessage() : 'Unknown database error';
            error_log(
                "Database connection failed after " . DB_CONNECT_MAX_ATTEMPTS . " attempt(s) per target. Tried: "
                . implode(', ', $attemptedTargets)
                . ". Last error: " . $errorMessage
            );
            throw new Exception("Database connection failed. Please verify DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, and DB_DATABASE/DB_NAME.");
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
    $candidates = getDatabaseConnectionCandidates();
    $lastError = null;
    $attemptedTargets = [];

    foreach ($candidates as $candidate) {
        $attemptedTargets[] = $candidate['source'] . ':' . $candidate['host'] . ':' . $candidate['port'] . '/' . $candidate['database'];

        for ($attempt = 1; $attempt <= DB_CONNECT_MAX_ATTEMPTS; $attempt++) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $connection = @new mysqli(
                $candidate['host'],
                $candidate['username'],
                $candidate['password'],
                $candidate['database'],
                $candidate['port']
            );

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
    }

    error_log(
        "Legacy database connection failed after " . DB_CONNECT_MAX_ATTEMPTS . " attempt(s) per target. Tried: "
        . implode(', ', $attemptedTargets)
        . ". Last error: " . $lastError
    );
    throw new Exception("Database connection failed. Please verify DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, and DB_DATABASE/DB_NAME.");
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
