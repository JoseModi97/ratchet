<?php
namespace MyApp;

use PDO;
use PDOException;

class Db {
    private static $pdoInstance = null;

    public static function getConnection() {
        if (self::$pdoInstance === null) {
            $config = require dirname(__DIR__) . '/config/database.php';

            $dsn = "{$config['driver']}:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";

            try {
                self::$pdoInstance = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            } catch (PDOException $e) {
                // In a real application, you'd log this error and possibly throw a custom exception
                error_log("Database Connection Error: " . $e->getMessage());
                // Depending on how you want to handle critical errors, you might exit, throw, or return null.
                // For this application, we'll throw it to make it visible during development.
                throw new PDOException("Could not connect to the database: " . $e->getMessage(), (int)$e->getCode());
            }
        }
        return self::$pdoInstance;
    }
}
