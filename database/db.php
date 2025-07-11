<?php
require_once __DIR__ . '/../public/config.php';

function get_db_connection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('sqlite:' . DB_PATH);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            // In a real application, you'd log this error and show a user-friendly message.
            // For now, we'll just die with the error.
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}
?>
