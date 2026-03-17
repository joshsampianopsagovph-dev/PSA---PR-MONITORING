<?php
require 'database.php';

$sql = "
CREATE TABLE IF NOT EXISTS pr_status_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    end_user VARCHAR(255) NOT NULL,
    pr_number VARCHAR(50) NOT NULL UNIQUE,
    posted BOOLEAN DEFAULT FALSE,
    canvas BOOLEAN DEFAULT FALSE,
    category VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pr_number (pr_number),
    INDEX idx_end_user (end_user),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $pdo->exec($sql);
    echo "Table pr_status_tracking created successfully\n";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}