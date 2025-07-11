<?php
namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use \PDO;
use \PDOException;

class Chat implements MessageComponentInterface {
    // Store connections per room: [roomId => SplObjectStorage(connections)]
    protected $rooms;
    // Store PDO connection
    private $pdo;

    public function __construct() {
        $this->rooms = [];
        $this->pdo = $this->getDbConnection();
        echo "Chat server started...\n";
    }

    private function getDbConnection() {
        static $pdo_static = null;
        if ($pdo_static === null) {
            // Database connection details (should match db.php and your setup)
            $host = 'localhost';
            $db   = 'chat_app';
            $user = 'root';
            $pass = ''; // Empty password as configured
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                $pdo_static = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                $errorMsg = "Database connection failed in Chat server: " . $e->getMessage();
                echo $errorMsg . "\n";
                error_log($errorMsg); // Also log to PHP error log if possible
                // This is a critical error for the chat server if DB is needed for auth
                // Depending on requirements, you might want to prevent the server from starting
                // or handle this more gracefully. For now, it will try to operate without DB if connection fails.
                return null; // Return null if connection fails
            }
        }
        return $pdo_static;
    }

    private function getUserId(string $username): ?int {
        if (!$this->pdo) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ? (int)$user['id'] : null;
        } catch (PDOException $e) {
            echo "Error fetching user ID for '{$username}': " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function isUserMemberOfRoom(int $userId, int $roomId): bool {
        if (!$this->pdo) return false; // Assume not member if DB is down
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM chat_room_members WHERE user_id = :user_id AND room_id = :room_id");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            echo "Error checking room membership for user {$userId} in room {$roomId}: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function getRoomName(int $roomId): ?string {
        if (!$this->pdo) return 'Unknown Room';
        try {
            $stmt = $this->pdo->prepare("SELECT name FROM chat_rooms WHERE id = :room_id");
            $stmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->execute();
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            return $room ? $room['name'] : null;
        } catch (PDOException $e) {
            echo "Error fetching room name for room {$roomId}: " . $e->getMessage() . "\n";
            return null;
        }
    }


    private function sendErrorMessage(ConnectionInterface $conn, $errorMessage, $closeCode = null, $closeReason = null) {
        $message = json_encode(['type' => 'error', 'text' => $errorMessage]);
        $conn->send($message);
        if ($closeCode !== null) {
            $conn->close($closeCode, $closeReason ?? $errorMessage);
        }
    }

    private function broadcastToRoom(int $roomId, array $messageData, ConnectionInterface $excludeConn = null) {
        if (!isset($this->rooms[$roomId])) {
            return;
        }
        $message = json_encode($messageData);
        foreach ($this->rooms[$roomId] as $client) {
            if ($excludeConn !== null && $client === $excludeConn) {
                continue;
            }
            $client->send($message);
        }
    }

    public function onOpen(ConnectionInterface $conn) {
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $queryParams);
        $username = $queryParams['username'] ?? null;
        $roomIdStr = $queryParams['roomId'] ?? null;

        if (empty($username) || empty($roomIdStr)) {
            $this->sendErrorMessage($conn, "Username and roomId parameters are required.", 4001, "Username and roomId required");
            echo "Connection attempt without username or roomId. ({$conn->resourceId}) Closing.\n";
            return;
        }

        if (!ctype_digit($roomIdStr)) {
            $this->sendErrorMessage($conn, "Invalid roomId format.", 4002, "Invalid roomId format");
            echo "Connection attempt with invalid roomId format: {$roomIdStr}. ({$conn->resourceId}) Closing.\n";
            return;
        }
        $roomId = (int)$roomIdStr;

        if (!$this->pdo) {
            $this->sendErrorMessage($conn, "Chat server database offline. Please try again later.", 5003, "Server DB offline");
            echo "DB connection not available. Rejecting new connection {$conn->resourceId}.\n";
            return;
        }

        $userId = $this->getUserId($username);
        if ($userId === null) {
            $this->sendErrorMessage($conn, "User '{$username}' not found or database error.", 4003, "User not found");
            echo "User '{$username}' not found for connection {$conn->resourceId}. Closing.\n";
            return;
        }

        if (!$this->isUserMemberOfRoom($userId, $roomId)) {
            $this->sendErrorMessage($conn, "User '{$username}' is not a member of room {$roomId}.", 4004, "Not a room member");
            echo "User '{$username}' (ID: {$userId}) is not a member of room {$roomId}. Connection {$conn->resourceId} denied.\n";
            return;
        }

        // Check for duplicate connections from the same user to the same room
        if (isset($this->rooms[$roomId])) {
            foreach ($this->rooms[$roomId] as $client) {
                if (isset($client->userId) && $client->userId === $userId) {
                    $this->sendErrorMessage($conn, "User '{$username}' is already connected to this room.", 4005, "Already connected to room");
                    echo "Duplicate connection attempt by user '{$username}' (ID: {$userId}) to room {$roomId}. Closing new connection {$conn->resourceId}.\n";
                    // Optionally, you could close the OLD connection instead, or allow multiple connections.
                    // For now, rejecting the new one.
                    // $client->close(4006, "Newer connection established for this user in this room.");
                    return;
                }
            }
        }


        if (!isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = new \SplObjectStorage;
        }
        $this->rooms[$roomId]->attach($conn);

        // Store essential info on the connection object
        $conn->userId = $userId;
        $conn->username = $username;
        $conn->roomId = $roomId;

        $roomName = $this->getRoomName($roomId) ?? "ID {$roomId}";
        $joinText = "User '{$username}' has joined the room '{$roomName}'.";

        // Store system join message
        // Parameters: roomId, userId, messageType, content, metadata (optional)
        $this->storeMessage($roomId, $userId, 'system_join', json_encode(['username' => $username, 'action' => 'join', 'text' => $joinText]));


        echo "New connection! User: {$username} (ID: {$userId}, Conn: {$conn->resourceId}) joined Room: {$roomName} (ID: {$roomId})\n";

        $joinBroadcastMessage = [
            'type' => 'server_message', // This is for client interpretation of a generic server message
            'text' => $joinText // The simple text for immediate broadcast
        ];
        $this->broadcastToRoom($roomId, $joinBroadcastMessage);
    }

    private function storeMessage(int $roomId, int $userId, string $messageType, string $content, ?array $metadata = null): ?int {
        if (!$this->pdo) {
            $logMessage = "[FATAL storeMessage] \$this->pdo is null. DB connection likely failed during server startup. Message (Type: {$messageType}, User: {$userId}, Room: {$roomId}) cannot be stored.";
            echo $logMessage . "\\n";
            error_log($logMessage); // Attempt to log to standard PHP error log as well
            return null;
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO chat_messages (room_id, user_id, message_type, content, metadata)
                 VALUES (:room_id, :user_id, :message_type, :content, :metadata)"
            );
            $stmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT); // For system messages, user_id is who triggered it
            $stmt->bindParam(':message_type', $messageType);
            $stmt->bindParam(':content', $content); // For system messages, content can be JSON string
            $jsonMetadata = $metadata ? json_encode($metadata) : null;
            $stmt->bindParam(':metadata', $jsonMetadata);

            $stmt->execute();
            $lastId = (int)$this->pdo->lastInsertId();
            echo "Stored message ID {$lastId} of type '{$messageType}' for user {$userId} in room {$roomId}.\n";
            return $lastId;
        } catch (PDOException $e) {
            echo "Error storing message (Type: {$messageType}, User: {$userId}, Room: {$roomId}): " . $e->getMessage() . "\n";
            return null;
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        if (!isset($from->username) || !isset($from->roomId) || !isset($from->userId)) { // Added userId check
            $this->sendErrorMessage($from, "Connection not fully initialized. Message rejected.", 4007, "Connection not initialized");
            echo "Message from uninitialized connection (resourceId {$from->resourceId}). Ignoring.\n";
            return;
        }

        $senderUsername = $from->username;
        $senderUserId = $from->userId; // Get sender's user ID from connection object
        $roomId = $from->roomId;
        $roomName = $this->getRoomName($roomId) ?? "ID {$roomId}";

        // Store the user message
        $messageId = $this->storeMessage($roomId, $senderUserId, 'text', $msg);

        if ($messageId === null) {
            echo "Failed to store message for user {$senderUsername} in room {$roomId}. Message will still be broadcast without DB ID.\n";
            // Potentially send an error back to sender or handle more gracefully
        }

        $numRecv = isset($this->rooms[$roomId]) ? count($this->rooms[$roomId]) -1 : 0;
        if ($numRecv < 0) $numRecv = 0;

        echo sprintf('User %s (ID: %d, Conn: %d) in Room %s (ID: %d) sending message "%s" (DB ID: %s) to %d other connection%s' . "\n",
            $senderUsername, $senderUserId, $from->resourceId, $roomName, $roomId, $msg, $messageId ?? 'N/A', $numRecv, $numRecv == 1 ? '' : 's');

        // The broadcasted message structure remains the same for now.
        // Clients will primarily rely on fetching history for message IDs and server timestamps.
        // If real-time messageId is needed, this structure would need `messageId`.
        $messageData = [
            'type' => 'message',
            'sender' => $senderUsername,
            'text' => $msg,
            'roomId' => $roomId
            // If we wanted to send the ID in real-time: 'messageId' => $messageId
        ];

        $this->broadcastToRoom($roomId, $messageData);
    }

    public function onClose(ConnectionInterface $conn) {
        if (!isset($conn->username) || !isset($conn->roomId) || !isset($conn->userId)) { // Added userId check
            echo "Uninitialized or partially initialized connection {$conn->resourceId} has disconnected\n";
            return;
        }

        $username = $conn->username;
        $userId = $conn->userId; // Get userId from connection
        $roomId = $conn->roomId;
        $roomName = $this->getRoomName($roomId) ?? "ID {$roomId}";

        $leaveText = "User '{$username}' has left the room '{$roomName}'.";

        if (isset($this->rooms[$roomId])) {
            $this->rooms[$roomId]->detach($conn);
            echo "User '{$username}' (ID: {$userId}, Conn: {$conn->resourceId}) has disconnected from Room: {$roomName} (ID: {$roomId})\n";

            // Store system leave message
            $this->storeMessage($roomId, $userId, 'system_leave', json_encode(['username' => $username, 'action' => 'leave', 'text' => $leaveText]));

            $leaveBroadcastMessage = [
                'type' => 'server_message', // Client interprets this
                'text' => $leaveText
            ];
            $this->broadcastToRoom($roomId, $leaveBroadcastMessage);

            if (count($this->rooms[$roomId]) == 0) {
                echo "Room {$roomName} (ID: {$roomId}) is now empty. Removing from active rooms.\n";
                unset($this->rooms[$roomId]);
            }
        } else {
            echo "User '{$username}' (Conn {$conn->resourceId}) disconnected, but room {$roomId} was not found in active rooms list.\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $identifier = "Connection {$conn->resourceId}";
        if (isset($conn->username) && isset($conn->roomId)) {
            $identifier = "User '{$conn->username}' (Conn {$conn->resourceId}) in Room ID {$conn->roomId}";
        } elseif (isset($conn->username)) {
            $identifier = "User '{$conn->username}' (Conn {$conn->resourceId})";
        }

        echo "An error has occurred for {$identifier}: {$e->getMessage()}\n";
        // Additional details for debugging if needed
        // echo "Error details: File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
        // echo "Trace: " . $e->getTraceAsString() . "\n";


        // Send a generic error to the client causing the error
        $this->sendErrorMessage($conn, "An internal server error occurred: " . $e->getMessage());

        // It's important to close the connection if an error makes its state uncertain
        $conn->close(1011, "Internal server error"); // 1011 is for internal server error

        // Consider if other clients in the room should be notified,
        // but typically individual client errors shouldn't spam the room.
        // If the error is critical for the room, different logic would be needed.
    }
}
