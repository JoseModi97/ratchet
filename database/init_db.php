<?php
$dbPath = __DIR__ . '/chat_users.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);

// Create users table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

echo "Database initialized successfully with users table.\n";

// Example of how to run this: php database/init_db.php
?>
