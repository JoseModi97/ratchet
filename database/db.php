<?php
require_once __DIR__ . '/../public/config.php';

function get_db_connection() {
    static $pdo = null;
    if ($pdo === null) {
        // Check if essential DB constants are defined
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
            die("Database configuration is incomplete. Please ensure DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD are defined in config.php.");
        }

        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Important for security and performance
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
        } catch (PDOException $e) {
            // In a real application, you'd log this error and show a user-friendly message.
            // For now, we'll just die with the error.
            error_log("Database connection failed: " . $e->getMessage()); // Log to server error log
            die("Database connection failed. Please check your configuration and ensure the database server is running. Details: " . $e->getMessage());
        }
    }
    return $pdo;
}
?>
?>
