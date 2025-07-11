<?php
require_once __DIR__ . '/../public/config.php'; // To get DB credentials
require_once __DIR__ . '/db.php'; // To get the get_db_connection function

echo "Attempting to initialize database table 'users'...\n";

if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
    echo "Error: Database configuration is incomplete. Please ensure DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD are defined in public/config.php and that you have copied config.example.php to config.php and updated it.\n";
    exit(1);
}

try {
    $pdo = get_db_connection(); // This function now connects to MariaDB/MySQL

    // SQL to create users table for MariaDB/MySQL
    // Note: AUTO_INCREMENT instead of AUTOINCREMENT
    // Note: INT instead of INTEGER for id for common MySQL practice, though INTEGER works.
    // Note: VARCHAR for username for common MySQL practice, TEXT is also fine.
    $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4') . ";
    ";

    $pdo->exec($sql);
    echo "Table 'users' checked/created successfully in database '" . DB_NAME . "'.\n";
    echo "Please ensure the database '" . DB_NAME . "' itself was created and user '" . DB_USER . "' has permissions.\n";
    echo "Refer to public/config.example.php or README.md for database/user creation steps if needed.\n";

} catch (PDOException $e) {
    echo "Error initializing database table: " . $e->getMessage() . "\n";
    echo "Please ensure your database server is running, the database '" . DB_NAME . "' exists, and the user '" . DB_USER . "' has the correct permissions (CREATE, INSERT, SELECT, UPDATE, DELETE on the users table or ALL PRIVILEGES on the database).\n";
    exit(1);
}
?>
