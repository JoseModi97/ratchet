<?php
require_once __DIR__ . '/../public/config.php';

function get_db_connection()
{
    static $pdo = null;
    if ($pdo === null) {
        // Database connection details
        $host = 'localhost';
        $db   = 'chat_app';
        $user = 'root';
        $pass = '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // In a real application, you'd log this error and show a user-friendly message.
            // For now, we'll just die with the error.
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}
