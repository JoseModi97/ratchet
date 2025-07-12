# WebSocket Chat Application

A real-time chat application built with PHP, WebSocket, and vanilla JavaScript. This project allows users to create chat rooms, invite others, and communicate in real-time.

## Live Demo

[Link to Live Demo]

## Features

- **User Authentication**: Secure user login and registration system.
- **Session Management**: PHP session-based authentication to keep users logged in.
- **Real-Time Chat**: WebSocket server for instant message delivery.
- **Chat Rooms**:
  - Create and join multiple chat rooms.
  - View a list of rooms you belong to.
  - Load historical messages with a "load more" feature.
  - Invite other users to join your rooms.
  - See a list of members in each room.
- **Notifications**:
  - In-room notifications for users joining or leaving.
  - Bell icon with a counter for new notifications (visible to room creators).
- **Responsive UI**: A clean and responsive user interface built with Bootstrap.

## Technologies Used

- **Backend**:
  - PHP
  - MySQL (with PDO for database access)
  - WebSocket (Ratchet or similar PHP library)
- **Frontend**:
  - HTML, CSS, JavaScript
  - Bootstrap
- **Database**:
  - MySQL

## System Design

The application is composed of a PHP backend that handles user authentication and room management, and a WebSocket server for real-time communication. The frontend is built with vanilla JavaScript and communicates with the backend via API calls and a WebSocket connection.

### Components

- **Authentication**: Manages user login, registration, and sessions.
- **WebSocket Server**: Handles real-time messaging and notifications.
- **Chat Rooms**: Manages room creation, membership, and message history.
- **Messaging**: Stores and delivers messages in real-time.
- **UI**: Provides a user-friendly interface for interacting with the application.

## Setup and Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/your-username/your-repo-name.git
   cd your-repo-name
   ```

2. **Set up the database**:
   - Create a new MySQL database.
   - Import the `database.sql` file to create the necessary tables.

3. **Configure the backend**:
   - Rename `config.example.php` to `config.php`.
   - Update `config.php` with your database credentials.

4. **Start the WebSocket server**:
   ```bash
   php server.php
   ```

5. **Open the application**:
   - Open `index.html` in your web browser.

## Usage

1. **Register a new account or log in.**
2. **Create a new chat room from the dashboard.**
3. **Invite other users to your room.**
4. **Start chatting in real-time!**

## Future Enhancements

- **Direct Messaging**: Re-enable one-on-one messaging.
- **UI/UX Improvements**: Enhance the user interface for better responsiveness and user experience.
- **Avatars and Timestamps**: Add user avatars and timestamps to chat messages.
- **Typing Indicators**: Show when a user is typing.
- **Presence Tracking**: Display the online status of users.
- **Frontend Framework**: Migrate the frontend to a modern framework like React for better maintainability.

## Contributing

Contributions are welcome! Please feel free to submit a pull request or open an issue.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
