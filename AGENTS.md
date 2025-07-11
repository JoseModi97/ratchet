# AGENTS.md - Real-Time Multi-User Chat Application

This document provides guidance for agents and developers working on this PHP-based real-time chat application.

## 1. Project Overview

This application implements a multi-user chat system using WebSockets (via Ratchet) for real-time communication and a PHP/MySQL backend for user authentication, message persistence, and other features. The requirements are detailed in the Software Requirements Specification (SRS).

## 2. Setup Instructions

### 2.1 Prerequisites
- PHP 7.4 or higher (ideally 8.0+ for modern features, though current code aims for broad compatibility)
- Composer (PHP dependency manager)
- MySQL 5.7+ or MariaDB equivalent

### 2.2 Installation Steps

1.  **Clone the Repository:**
    ```bash
    git clone <repository-url>
    cd <repository-directory>
    ```

2.  **Install PHP Dependencies:**
    If `composer.phar` is not present, download it first: `php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"` then `php composer-setup.php` then `php -r "unlink('composer-setup.php');"`
    ```bash
    php composer.phar install
    ```
    This will install Ratchet and other necessary libraries defined in `composer.json` into the `vendor/` directory.

3.  **Database Setup:**
    a.  Create the MySQL database:
        ```sql
        -- Connect to MySQL as a privileged user (e.g., root)
        -- mysql -u root -p
        CREATE DATABASE IF NOT EXISTS chat_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ```
    b.  Create the necessary tables by importing the schema:
        ```bash
        # From the project root directory:
        mysql -u your_db_user -p chat_app < db/schema.sql
        ```
        Replace `your_db_user` with your MySQL username. You will be prompted for the password.

    c.  **Important:** You might need to manually add an initial chat room for messages to be associated correctly, as the message persistence currently defaults to `room_id = 1`.
        ```sql
        -- Connect to your chat_app database: mysql -u your_db_user -p chat_app
        INSERT INTO chat_rooms (id, name, is_private, created_by, created_at)
        VALUES (1, 'General Chat', FALSE, NULL, NOW());
        -- created_by can be NULL or a valid user_id if you create a user first.
        ```

4.  **Configure Database Connection:**
    Open `src/Database.php` and update the placeholder database credentials:
    ```php
    // src/Database.php
    // ...
    private $host = '127.0.0.1'; // or 'localhost'
    private $db_name = 'chat_app';
    private $username = 'your_db_user'; // << UPDATE THIS
    private $password = 'your_db_password'; // << UPDATE THIS
    // ...
    ```
    *Ideally, these should be moved to environment variables or a configuration file not committed to the repository.*

### 2.3 Running the Application

1.  **Start the WebSocket Server:**
    Open a terminal in the project root and run:
    ```bash
    php bin/server.php
    ```
    You should see output like:
    ```
    WebSocket server listening on port 8080
    Chat server started...
    ```
    Keep this terminal window open.

2.  **Access the Frontend:**
    The frontend is `public/index.html`. You'll need a web server (like Apache or Nginx) to serve the `public` directory.
    - Configure your web server to point a virtual host to the `public` directory of this project.
    - Ensure PHP is correctly processed by your web server for the API endpoints (`.php` files in `public/`).
    - Access the application via your browser (e.g., `http://localhost/index.html` or `http://your-chat-app-domain.test/`).

    *Alternatively, for simple testing of PHP API endpoints without a full web server setup, you can use PHP's built-in server from the `public` directory (less ideal for full app testing):*
    ```bash
    # Navigate to the public directory
    cd public
    # Start PHP's built-in server (e.g., on port 8000)
    php -S localhost:8000
    ```
    *Then access `http://localhost:8000/index.html`. Note that the WebSocket server still runs separately on port 8080.*


## 3. API Endpoints (under `public/`)

-   `register.php` (POST): User registration. Expects JSON: `{ "username": "...", "email": "...", "password": "..." }`
-   `login.php` (POST): User login. Expects JSON: `{ "login": "username_or_email", "password": "..." }`
-   `rooms.php` (GET): Lists public chat rooms.
-   `messages.php` (GET): Fetches message history for a room. Requires `?room_id=X`.

## 4. Coding Conventions

-   Follow PSR-12 for PHP code style where possible.
-   Use meaningful variable and function names.
-   Comment complex logic.
-   Ensure all user inputs are validated and sanitized, especially before database interaction or output.
-   Handle errors gracefully and provide informative JSON responses for API endpoints.

## 5. Current Status & Known Issues/TODOs

-   **Frontend Room Implementation**: The frontend (`public/index.html`) does not yet fully support room selection or fetching message history based on rooms. It primarily works with a global chat concept for now.
-   **WebSocket Room Logic**: The WebSocket server (`src/Chat.php`) currently sends messages to a default `room_id = 1` for persistence and broadcasts to all connected clients rather than room-specific clients. This needs refinement.
-   **API Authentication**: Some API endpoints (`/rooms.php`, `/messages.php`) lack proper authentication/authorization checks.
-   **Configuration Management**: Database credentials are hardcoded in `src/Database.php`. This should be externalized.
-   **Error Handling**: While basic error handling is in place, it can be made more robust and user-friendly.
-   **Security**: Continue to review for security best practices (XSS, SQLi, CSRF if applicable for future form submissions not via JS). Input validation is present but always needs diligence.

---
*This AGENTS.md should be updated as the project evolves.*
