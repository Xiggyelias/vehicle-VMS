#!/usr/bin/env php
<?php
/**
 * Secure Admin Account Creation Script
 * 
 * This script creates an admin account securely via command line.
 * Usage: php scripts/create-admin.php [--username=admin] [--email=admin@example.com]
 * 
 * SECURITY: This script should only be run from command line, never via web.
 */

// Prevent execution via web
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Load required files
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/functions/utilities.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Get command line arguments
 */
function getCliArgs() {
    $args = [];
    foreach ($_SERVER['argv'] as $arg) {
        if (strpos($arg, '--') === 0) {
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $value = isset($parts[1]) ? $parts[1] : true;
            $args[$key] = $value;
        }
    }
    return $args;
}

/**
 * Prompt for input (hidden for passwords)
 */
function prompt($message, $hidden = false) {
    echo $message;
    if ($hidden) {
        // Hide password input on Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $exe = __DIR__ . '/hiddeninput.exe';
            if (file_exists($exe)) {
                $value = rtrim(shell_exec($exe));
            } else {
                // Fallback: show input (not secure, but works)
                $value = rtrim(fgets(STDIN));
            }
        } else {
            // Unix/Linux: use stty to hide input
            system('stty -echo');
            $value = rtrim(fgets(STDIN));
            system('stty echo');
            echo "\n";
        }
    } else {
        $value = rtrim(fgets(STDIN));
    }
    return $value;
}

/**
 * Validate password strength
 */
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 12) {
        $errors[] = "Password must be at least 12 characters long.";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
        $errors[] = "Password must contain at least one special character.";
    }
    
    return $errors;
}

/**
 * Main execution
 */
try {
    echo "========================================\n";
    echo "  Admin Account Creation Script\n";
    echo "========================================\n\n";
    
    $args = getCliArgs();
    
    // Get username
    $username = $args['username'] ?? null;
    if (!$username) {
        $username = prompt("Enter admin username: ");
    }
    
    if (empty($username)) {
        die("Error: Username cannot be empty.\n");
    }
    
    // Validate username
    if (strlen($username) < 3 || strlen($username) > 50) {
        die("Error: Username must be between 3 and 50 characters.\n");
    }
    
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        die("Error: Username can only contain letters, numbers, and underscores.\n");
    }
    
    // Get email
    $email = $args['email'] ?? null;
    if (!$email) {
        $email = prompt("Enter admin email: ");
    }
    
    if (empty($email)) {
        die("Error: Email cannot be empty.\n");
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die("Error: Invalid email address.\n");
    }
    
    // Get password
    echo "\nPassword requirements:\n";
    echo "  - At least 12 characters\n";
    echo "  - At least one uppercase letter\n";
    echo "  - At least one lowercase letter\n";
    echo "  - At least one number\n";
    echo "  - At least one special character\n\n";
    
    $password = prompt("Enter admin password: ", true);
    
    if (empty($password)) {
        die("Error: Password cannot be empty.\n");
    }
    
    // Validate password
    $passwordErrors = validatePassword($password);
    if (!empty($passwordErrors)) {
        echo "\nPassword validation failed:\n";
        foreach ($passwordErrors as $error) {
            echo "  - $error\n";
        }
        die("\nPlease try again with a stronger password.\n");
    }
    
    // Confirm password
    $passwordConfirm = prompt("Confirm password: ", true);
    
    if ($password !== $passwordConfirm) {
        die("Error: Passwords do not match.\n");
    }
    
    // Connect to database
    $conn = getLegacyDatabaseConnection();
    
    // Check if admin table exists, create if not
    $checkTable = $conn->query("SHOW TABLES LIKE 'admin'");
    if ($checkTable->num_rows === 0) {
        echo "\nCreating admin table...\n";
        $createTable = "CREATE TABLE admin (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->query($createTable);
        echo "Admin table created successfully.\n";
    }
    
    // Check if username already exists
    $stmt = $conn->prepare("SELECT id FROM admin WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        die("Error: Username '$username' already exists.\n");
    }
    $stmt->close();
    
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM admin WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        die("Error: Email '$email' is already registered.\n");
    }
    $stmt->close();
    
    // Hash password
    $hashedPassword = hashPassword($password);
    
    // Insert admin user
    $stmt = $conn->prepare("INSERT INTO admin (username, password, email) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $hashedPassword, $email);
    
    if ($stmt->execute()) {
        echo "\n✅ Admin account created successfully!\n";
        echo "   Username: $username\n";
        echo "   Email: $email\n";
        echo "\n⚠️  SECURITY REMINDER:\n";
        echo "   - Change the default password immediately after first login\n";
        echo "   - Use a strong, unique password\n";
        echo "   - Never share admin credentials\n";
        echo "   - Enable two-factor authentication if available\n\n";
    } else {
        die("Error: Failed to create admin account. " . $stmt->error . "\n");
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}
