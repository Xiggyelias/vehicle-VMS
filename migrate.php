<?php
/**
 * Migration Runner
 *
 * Usage:
 *   php migrate.php
 *   php migrate.php --status
 *   php migrate.php --dry-run
 *   php migrate.php --file=create_reports_table.sql
 *   php migrate.php --path=database
 *   php migrate.php --force
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$options = parseArguments($argv);

if ($options['help']) {
    printHelp();
    exit(0);
}

$migrationDir = resolveMigrationDirectory($options['path']);
if ($migrationDir === null) {
    fwrite(STDERR, "Migration path not found: {$options['path']}\n");
    exit(1);
}

$files = collectMigrationFiles($migrationDir, $options['file']);
if (empty($files)) {
    fwrite(STDOUT, "No SQL migration files found in {$migrationDir}\n");
    exit(0);
}

try {
    $pdo = getDatabaseConnection();
    ensureMigrationsTable($pdo);
    $applied = getAppliedMigrations($pdo);

    if ($options['status']) {
        printStatus($files, $applied);
        exit(0);
    }

    $appliedCount = 0;
    $skippedCount = 0;

    foreach ($files as $filePath) {
        $fileName = basename($filePath);
        $checksum = hash_file('sha256', $filePath);
        $existing = $applied[$fileName] ?? null;

        if ($existing !== null && !$options['force']) {
            if ($existing['checksum'] === $checksum) {
                fwrite(STDOUT, "[SKIP] {$fileName} already applied.\n");
                $skippedCount++;
                continue;
            }

            fwrite(STDERR, "[ERROR] {$fileName} changed since it was applied. Use --force to re-run.\n");
            exit(1);
        }

        if ($options['dry_run']) {
            fwrite(STDOUT, "[DRY-RUN] Would apply {$fileName}\n");
            continue;
        }

        fwrite(STDOUT, "[RUN ] {$fileName}\n");
        applyMigrationFile($pdo, $filePath);
        recordMigration($pdo, $fileName, $checksum);
        $appliedCount++;
    }

    if ($options['dry_run']) {
        fwrite(STDOUT, "Dry run complete.\n");
    } else {
        fwrite(STDOUT, "Completed. Applied: {$appliedCount}, Skipped: {$skippedCount}\n");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failure: {$e->getMessage()}\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{path:string,file:?string,dry_run:bool,force:bool,status:bool,help:bool}
 */
function parseArguments($argv) {
    $options = [
        'path' => 'database',
        'file' => null,
        'dry_run' => false,
        'force' => false,
        'status' => false,
        'help' => false
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--force') {
            $options['force'] = true;
        } elseif ($arg === '--status') {
            $options['status'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif (str_starts_with($arg, '--path=')) {
            $options['path'] = trim(substr($arg, 7));
        } elseif (str_starts_with($arg, '--file=')) {
            $value = trim(substr($arg, 7));
            $options['file'] = $value !== '' ? $value : null;
        } else {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            $options['help'] = true;
            return $options;
        }
    }

    return $options;
}

function printHelp() {
    $help = <<<TXT
Migration Runner

Options:
  --path=<dir>     Directory containing .sql files (default: database)
  --file=<name>    Run a single SQL file from that directory
  --status         Show migration status without applying
  --dry-run        Show what would run, but do not execute SQL
  --force          Re-run files even if previously applied
  --help, -h       Show this help

Examples:
  php migrate.php
  php migrate.php --status
  php migrate.php --file=create_reports_table.sql
  php migrate.php --path=database --dry-run
TXT;

    fwrite(STDOUT, $help . PHP_EOL);
}

/**
 * @param string $path
 * @return string|null
 */
function resolveMigrationDirectory($path) {
    if ($path === '') {
        return null;
    }

    if (preg_match('/^[a-zA-Z]:\\\\/', $path)) {
        $resolved = realpath($path);
        return $resolved !== false ? $resolved : null;
    }

    $resolved = realpath(__DIR__ . DIRECTORY_SEPARATOR . $path);
    return $resolved !== false ? $resolved : null;
}

/**
 * @param string $migrationDir
 * @param string|null $singleFile
 * @return array<int, string>
 */
function collectMigrationFiles($migrationDir, $singleFile = null) {
    if ($singleFile !== null) {
        $target = realpath($migrationDir . DIRECTORY_SEPARATOR . $singleFile);
        if ($target === false || !is_file($target)) {
            throw new RuntimeException("Requested migration file not found: {$singleFile}");
        }
        return [$target];
    }

    $files = glob($migrationDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

/**
 * @param PDO $pdo
 */
function ensureMigrationsTable($pdo) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            file_name VARCHAR(255) NOT NULL UNIQUE,
            checksum CHAR(64) NOT NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * @param PDO $pdo
 * @return array<string, array{checksum:string,executed_at:string}>
 */
function getAppliedMigrations($pdo) {
    $stmt = $pdo->query('SELECT file_name, checksum, executed_at FROM schema_migrations ORDER BY executed_at ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $map = [];
    foreach ($rows as $row) {
        $map[$row['file_name']] = [
            'checksum' => (string)$row['checksum'],
            'executed_at' => (string)$row['executed_at']
        ];
    }
    return $map;
}

/**
 * @param array<int, string> $files
 * @param array<string, array{checksum:string,executed_at:string}> $applied
 */
function printStatus($files, $applied) {
    fwrite(STDOUT, "Migration Status\n");
    fwrite(STDOUT, str_repeat('-', 90) . PHP_EOL);
    fwrite(STDOUT, str_pad('File', 45) . str_pad('Status', 15) . "Applied At\n");
    fwrite(STDOUT, str_repeat('-', 90) . PHP_EOL);

    foreach ($files as $filePath) {
        $fileName = basename($filePath);
        $checksum = hash_file('sha256', $filePath);
        $row = $applied[$fileName] ?? null;

        if ($row === null) {
            $status = 'pending';
            $appliedAt = '-';
        } elseif ($row['checksum'] !== $checksum) {
            $status = 'drifted';
            $appliedAt = $row['executed_at'];
        } else {
            $status = 'applied';
            $appliedAt = $row['executed_at'];
        }

        fwrite(STDOUT, str_pad($fileName, 45) . str_pad($status, 15) . $appliedAt . PHP_EOL);
    }

    fwrite(STDOUT, str_repeat('-', 90) . PHP_EOL);
}

/**
 * @param PDO $pdo
 * @param string $filePath
 */
function applyMigrationFile($pdo, $filePath) {
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration file: ' . basename($filePath));
    }

    $statements = splitSqlStatements($sql);
    if (empty($statements)) {
        return;
    }

    $pdo->beginTransaction();
    try {
        foreach ($statements as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }
            $pdo->exec($trimmed);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('Failed in ' . basename($filePath) . ': ' . $e->getMessage(), 0, $e);
    }
}

/**
 * Handles MySQL DELIMITER blocks so trigger/procedure scripts can run.
 *
 * @param string $sql
 * @return array<int, string>
 */
function splitSqlStatements($sql) {
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    $lines = preg_split('/\R/', $sql) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $matches)) {
            $delimiter = $matches[1];
            continue;
        }

        $buffer .= $line . "\n";
        if (statementEndsWithDelimiter($line, $delimiter)) {
            $statement = removeTrailingDelimiter($buffer, $delimiter);
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * @param string $line
 * @param string $delimiter
 * @return bool
 */
function statementEndsWithDelimiter($line, $delimiter) {
    $trimmed = rtrim($line);
    return $delimiter !== '' && str_ends_with($trimmed, $delimiter);
}

/**
 * @param string $sql
 * @param string $delimiter
 * @return string
 */
function removeTrailingDelimiter($sql, $delimiter) {
    $quoted = preg_quote($delimiter, '/');
    return (string)preg_replace('/' . $quoted . '\s*$/', '', rtrim($sql));
}

/**
 * @param PDO $pdo
 * @param string $fileName
 * @param string $checksum
 */
function recordMigration($pdo, $fileName, $checksum) {
    $stmt = $pdo->prepare(
        'INSERT INTO schema_migrations (file_name, checksum, executed_at)
         VALUES (:file_name, :checksum, NOW())
         ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), executed_at = VALUES(executed_at)'
    );
    $stmt->execute([
        ':file_name' => $fileName,
        ':checksum' => $checksum
    ]);
}

