<?php
require_once __DIR__ . '/config/database.php';

function getDBConnection() {
    return getLegacyDatabaseConnection();
}

try {
    $conn = getDBConnection();
    
    // Create notifications table with role-aware fields
    $sql = "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('new-registration', 'update', 'transfer-request', 'disk-assignment', 'driver-assignment') NOT NULL,
        role ENUM('student', 'staff', 'guest') NOT NULL,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES applicants(applicant_id)
    )";
    
    if ($conn->query($sql)) {
        echo "Notifications table created successfully";
    } else {
        throw new Exception("Error creating table: " . $conn->error);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

$conn->close();
?> 
