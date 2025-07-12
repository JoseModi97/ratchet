<?php
// Database connection details
$host = 'localhost';
$db   = 'chat_app';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

try {
    // Connect to MariaDB server without specifying a database first
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create the database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET $charset COLLATE utf8mb4_unicode_ci");
    echo "Database '$db' created successfully or already exists.\n";

    // Now connect to the specific database
    $pdo->exec("USE `$db`");

    // Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=utf8mb4_unicode_ci;
    ");

    echo "Table 'users' created successfully or already exists in '$db' database.\n";
} catch (PDOException $e) {
    die("Database initialization failed: " . $e->getMessage());
}

// Example of how to run this: php database/init_db.php
