<?php
// Database Configuration for MariaDB/MySQL

// **IMPORTANT**: Copy this file to config.php and fill in your actual database credentials.
// Make sure config.php is in your .gitignore if this is a public repository.

define('DB_HOST', '127.0.0.1'); // Or your MariaDB host, e.g., 'localhost'
define('DB_NAME', 'chat_app_db');    // Choose a name for your chat application's database
define('DB_USER', 'chat_user');  // Create a dedicated user for this application
define('DB_PASSWORD', 'your_secure_password'); // Replace with a strong, unique password
define('DB_CHARSET', 'utf8mb4'); // Recommended charset

// Example of how to create the database and user in MariaDB/MySQL:
// 1. Log into MariaDB/MySQL as root:
//    `sudo mysql -u root -p`
//    OR for MariaDB sometimes just `sudo mariadb`
//
// 2. Create the database:
//    `CREATE DATABASE chat_app_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
//
// 3. Create the user and grant privileges:
//    `CREATE USER 'chat_user'@'localhost' IDENTIFIED BY 'your_secure_password';`
//    `GRANT ALL PRIVILEGES ON chat_app_db.* TO 'chat_user'@'localhost';`
//    `FLUSH PRIVILEGES;`
//    `EXIT;`
//
// Remember to replace 'localhost' if your application server is different from your DB server.
// Replace 'your_secure_password' with the actual password you choose.
?>
