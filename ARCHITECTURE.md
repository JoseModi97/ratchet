# Multi-User WebSocket Chat Application Architecture

This document outlines the architecture, setup, and usage of the real-time multi-user chat application.

## 1. Overview

The application consists of:
- A **PHP-based RESTful API backend** for user registration, login, room management, and fetching message history.
- A **PHP-based WebSocket server** using Ratchet for real-time message exchange.
- A **MariaDB database** for persisting users, rooms, messages, and user presence.
- A **vanilla HTML, CSS, and JavaScript frontend** for user interaction.

## 2. Database Setup

The application uses a MariaDB database named `chat_app`.

**Connection Details (default):**
- Host: `localhost`
- Username: `root`
- Password: (empty)
- Database: `chat_app`

These can be configured in `config/database.php`.

**SQL Schema:**

Use the following SQL script to create the necessary database and tables. You can run this in a MariaDB client like phpMyAdmin or the command-line interface.

```sql
-- Create database
CREATE DATABASE IF NOT EXISTS chat_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE chat_app;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    session_token VARCHAR(255) NULL UNIQUE, -- Added for session management
    token_expires_at DATETIME NULL,      -- Added for session management
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Chat Rooms table
CREATE TABLE chat_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_private BOOLEAN DEFAULT FALSE,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Room Members table
CREATE TABLE room_members (
    user_id INT,
    room_id INT,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, room_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE
);

-- Messages table
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Attachments table (optional as per SRS)
CREATE TABLE attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    file_path VARCHAR(255),
    mime_type VARCHAR(100),
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
);

-- User Presence table
CREATE TABLE user_presence (
    user_id INT PRIMARY KEY,
    status ENUM('online', 'offline', 'away') DEFAULT 'offline',
    last_active DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- User Blocking table (optional as per SRS)
CREATE TABLE user_blocks (
    blocker_id INT,
    blocked_id INT,
    blocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (blocker_id, blocked_id),
    FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## 3. Backend Components

### 3.1 REST APIs (in `public/` directory)

- **`public/register.php`**
  - Method: `POST`
  - Body: `{"username": "user", "email": "user@example.com", "password": "securepassword"}`
  - Description: Registers a new user. Passwords are hashed.
- **`public/login.php`**
  - Method: `POST`
  - Body: `{"username": "user", "password": "securepassword"}` (or use email as username)
  - Description: Authenticates a user. Returns a session token on success.
- **`public/rooms.php`**
  - Method: `GET`
    - Headers: `Authorization: Bearer <YOUR_SESSION_TOKEN>`
    - Description: Lists public rooms and private rooms the authenticated user is a member of.
  - Method: `POST`
    - Headers: `Authorization: Bearer <YOUR_SESSION_TOKEN>`
    - Body: `{"name": "Room Name", "is_private": false}`
    - Description: Creates a new chat room.
- **`public/room_members.php`** (Planned)
  - Method: `POST` (Join a room)
    - Headers: `Authorization: Bearer <YOUR_SESSION_TOKEN>`
    - Body: `{"room_id": 1}` (user joins the specified room_id)
  - Method: `DELETE` (Leave a room)
    - Headers: `Authorization: Bearer <YOUR_SESSION_TOKEN>`
    - Body: `{"room_id": 1}` (user leaves the specified room_id)
- **`public/messages.php`**
  - Method: `GET`
    - Headers: `Authorization: Bearer <YOUR_SESSION_TOKEN>`
    - Parameters: `?room_id=<ROOM_ID>`
    - Description: Fetches message history for a specific room. User must be a member or room must be public.

### 3.2 WebSocket Server

- Script: `bin/server.php`
- Address: `ws://localhost:8080`
- Authentication: Requires a valid session token passed as a query parameter: `ws://localhost:8080?token=<YOUR_SESSION_TOKEN>`
- Handles real-time message broadcasting, user presence updates, and room joining/leaving notifications.

**WebSocket Message Format (Client <-> Server):**
Messages are JSON strings.
- Client to Server (examples):
  - Join Room: `{"type": "joinRoom", "roomId": 1}`
  - Leave Room: `{"type": "leaveRoom", "roomId": 1}`
  - Send Message: `{"type": "message", "roomId": 1, "content": "Hello world!"}`
- Server to Client (examples):
  - New Message: `{"type": "newMessage", "roomId": 1, "user": "username", "content": "Hello world!", "timestamp": "..."}`
  - User Joined: `{"type": "userJoined", "roomId": 1, "user": "username"}`
  - User Left: `{"type": "userLeft", "roomId": 1, "user": "username"}`
  - Error: `{"type": "error", "message": "Error details"}`

## 4. Frontend

- Location: `public/index.html` (will be expanded significantly)
- Description: Single Page Application (SPA-like) interface to interact with the APIs and WebSocket server.
  - User registration and login forms.
  - Room listing, creation, and joining.
  - Chat interface for sending and receiving real-time messages.
  - Display of message history.

## 5. How to Run

1.  **Setup Database:**
    - Ensure MariaDB is running.
    - Create the `chat_app` database.
    - Execute the SQL script provided above to create the tables.
2.  **Install Dependencies:**
    - Run `php composer.phar install` (or `composer install` if composer is globally installed) in the project root.
3.  **Start WebSocket Server:**
    - Open a terminal and navigate to the project root.
    - Run `php bin/server.php`. You should see a message indicating the server is listening on port 8080.
4.  **Access Frontend:**
    - Ensure you have a web server (like Apache or Nginx from XAMPP/WAMP/MAMP/LEMP) configured to serve files from the `public` directory of the project.
    - Open `http://localhost/your_project_path/public/` in your web browser.
    - Alternatively, for simple testing of `index.html` without a full web server setup for PHP files, you might be able to open `public/index.html` directly in the browser, but API calls (register, login) will require PHP to be processed by a web server.

## 6. Project Structure

```
/
├── bin/
│   └── server.php          # WebSocket server runner
├── config/
│   └── database.php        # Database connection settings
├── public/                 # Web server document root
│   ├── index.html          # Main frontend HTML file
│   ├── register.php        # API endpoint
│   ├── login.php           # API endpoint
│   ├── rooms.php           # API endpoint
│   ├── messages.php        # API endpoint
│   └── room_members.php    # API endpoint (planned)
│   └── (css/ and js/ directories eventually)
├── src/
│   ├── Chat.php            # WebSocket application logic (MessageComponentInterface)
│   └── Db.php              # Database connection utility
├── vendor/                 # Composer dependencies
├── ARCHITECTURE.md         # This file
├── composer.json
└── composer.lock
```

This `ARCHITECTURE.md` includes the SQL schema with the added `session_token` and `token_expires_at` fields in the `users` table, as discussed for step 5 of the plan.
This completes the "Project Setup & Configuration" step.
