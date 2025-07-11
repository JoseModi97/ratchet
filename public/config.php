<?php
// Database Configuration for MariaDB/MySQL

// **IMPORTANT**: Copy this file to config.php and fill in your actual database credentials.
// Make sure config.php is in your .gitignore if this is a public repository.

// define('DB_HOST', '127.0.0.1'); // Or your MariaDB host
// define('DB_NAME', 'chat_app_db');    // Your database name
// define('DB_USER', 'chat_user');  // Your database username
// define('DB_PASSWORD', 'your_secure_password'); // Your database password
// define('DB_CHARSET', 'utf8mb4');

// --- Example for local development (replace with your actual details) ---
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'chat_app_db');
define('DB_USER', getenv('DB_USER') ?: 'chat_app_user');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'password'); // Use a strong password in production!
define('DB_CHARSET', 'utf8mb4');


// To use environment variables (recommended for production):
// 1. Set the variables in your environment (e.g., in .bashrc, .env file with a loader, or server configuration).
// Example:
// export DB_HOST="your_db_host"
// export DB_NAME="your_db_name"
// export DB_USER="your_db_user"
// export DB_PASSWORD="your_db_password"
// 2. The script will automatically use them if defined. Otherwise, it falls back to the defaults above.


// Remove or comment out SQLite specific configuration
// define('DB_PATH', __DIR__ . '/../database/chat_users.sqlite');

?>
