<?php
namespace MyApp;

use PDO;
use PDOException;

class Database {
    // TODO: Move these to a configuration file
    private $host = '127.0.0.1'; // or 'localhost'
    private $db_name = 'chat_app';
    private $username = 'your_db_user'; // Placeholder
    private $password = 'your_db_password'; // Placeholder
    private $conn;

    public function __construct() {
        $this->conn = null;
    }

    public function getConnection() {
        if ($this->conn) {
            return $this->conn;
        }

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            // Optional: PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        } catch(PDOException $exception) {
            // In a real app, log this error and handle it gracefully
            echo "Connection error: " . $exception->getMessage();
            // For now, we might want to throw the exception or return null
            // to indicate failure, depending on how we want to handle it upstream.
            // Throwing it makes it explicit that connection failed.
            throw $exception;
        }

        return $this->conn;
    }
}
?>
