# PHP Ratchet Chat with MariaDB/MySQL Authentication

This is a simple real-time chat application built with PHP, Ratchet (for WebSockets), and a MariaDB/MySQL backend for user authentication.

## Features

*   User registration and login
*   Real-time messaging using WebSockets
*   Usernames displayed in chat
*   Notifications for users joining/leaving

## Requirements

*   PHP (version 8.0 or higher recommended)
    *   `php-cli` (for running the WebSocket server)
    *   `php-mysql` extension (for database connectivity)
    *   `php-sqlite3` (if you were previously using or want to switch back to SQLite for some reason, but this guide focuses on MariaDB)
    *   `composer` (PHP dependency manager)
*   MariaDB Server (or MySQL Server)
*   A web server (like Apache or Nginx) to serve the PHP authentication scripts and HTML, OR PHP's built-in server for development.

## Setup Instructions

### 1. Clone the Repository

```bash
git clone <repository_url>
cd <repository_directory>
```

### 2. Install PHP Dependencies

If you haven't already, install Composer (see [getcomposer.org](https://getcomposer.org/download/)).
Then, install the project dependencies:

```bash
php composer.phar install
# or if composer is installed globally
# composer install
```

This will install Ratchet and other necessary libraries defined in `composer.json`.

### 3. Setup MariaDB/MySQL Database

You need to create a database and a user for the chat application.

1.  **Log into MariaDB/MySQL:**
    As root or a user with privileges to create databases and users.
    ```bash
    sudo mysql -u root -p
    # or for MariaDB, often just:
    # sudo mariadb
    ```

2.  **Create the database:**
    (Replace `chat_app_db` if you prefer a different name, but update `config.php` accordingly).
    ```sql
    CREATE DATABASE chat_app_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ```

3.  **Create a database user:**
    (Replace `chat_user` and `your_secure_password` with your desired username and a strong password. Update `config.php` accordingly).
    ```sql
    CREATE USER 'chat_user'@'localhost' IDENTIFIED BY 'your_secure_password';
    ```
    If your application server is not on the same machine as the database server, replace `'localhost'` with the appropriate hostname or IP address (e.g., `'%'` for any host, but be cautious with security).

4.  **Grant privileges to the user for the database:**
    ```sql
    GRANT ALL PRIVILEGES ON chat_app_db.* TO 'chat_user'@'localhost';
    ```

5.  **Apply the changes:**
    ```sql
    FLUSH PRIVILEGES;
    EXIT;
    ```

### 4. Configure Application

1.  **Copy the example configuration file:**
    ```bash
    cp public/config.example.php public/config.php
    ```

2.  **Edit `public/config.php`:**
    Open `public/config.php` and fill in the database credentials you set up in the previous step:
    ```php
    define('DB_HOST', '127.0.0.1'); // Or your MariaDB host
    define('DB_NAME', 'chat_app_db');    // Your database name
    define('DB_USER', 'chat_user');  // Your database username
    define('DB_PASSWORD', 'your_secure_password'); // Your database password
    define('DB_CHARSET', 'utf8mb4');
    ```

### 5. Initialize Database Table

Run the initialization script to create the `users` table in your database:

```bash
php database/init_db.php
```
You should see a success message. If there are errors, double-check your `config.php` and MariaDB setup.

### 6. Run the WebSocket Server

Open a terminal window and start the Ratchet WebSocket server:

```bash
php bin/server.php
```
This server will listen for WebSocket connections (defaulting to port 8080). Keep this terminal window open.

### 7. Serve the Frontend and PHP Scripts

The HTML, CSS, JavaScript, and PHP authentication scripts (`login.php`, `register.php`, etc.) in the `public/` directory need to be served by a web server.

*   **Using Apache/Nginx:** Configure a virtual host or place the project in your web server's document root, ensuring PHP is processed correctly. The document root for this site should point to the `public/` directory.
*   **Using PHP's Built-in Server (for development only):**
    Open *another* terminal window, navigate to the project root, and run:
    ```bash
    php -S localhost:8000 -t public
    ```
    This will serve files from the `public` directory on `http://localhost:8000`.

### 8. Access the Application

Open your web browser and navigate to the URL where the application is served.
*   If using PHP's built-in server as above: `http://localhost:8000/index.html` or just `http://localhost:8000/`
*   If using Apache/Nginx, use the URL you configured.

You should see the login/registration page. Register a new user, then log in to start chatting!

## Troubleshooting

*   **"Database connection failed"**:
    *   Ensure your MariaDB/MySQL server is running.
    *   Verify credentials in `public/config.php` are correct (host, db name, user, password).
    *   Check that the user has privileges on the database.
    *   Ensure the `php-mysql` extension is installed and enabled.
*   **WebSocket server (`bin/server.php`) errors**:
    *   Check PHP error logs and the console output for details.
    *   Ensure `vendor/autoload.php` exists (run `composer install`).
*   **Frontend issues (login/chat not working)**:
    *   Open your browser's developer console (usually F12) and check for JavaScript errors or failed network requests (e.g., to `login.php` or the WebSocket).
    *   Ensure the WebSocket server is running and accessible (port 8080 by default).
    *   Verify the WebSocket URL in `public/index.html` (`ws://localhost:8080`) is correct for your setup.

## Switching Back to SQLite (Optional)

If you wish to revert to SQLite:
1.  Modify `public/config.php` to define `DB_PATH` instead of MariaDB credentials.
2.  Update `database/db.php` to use the SQLite PDO connection string.
3.  Update `database/init_db.php` for SQLite syntax and connection.
4.  Ensure `php-sqlite3` extension is installed.
5.  Delete or rename the MariaDB-specific `README.md` instructions or configuration files if necessary.
(The previous version of this codebase used SQLite).
```
