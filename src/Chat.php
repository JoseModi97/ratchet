<?php
namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use \PDO;
use \PDOException;

class Chat implements MessageComponentInterface {
    // Store connections per room: [roomId => SplObjectStorage(connections)]
    protected $rooms;
    // Store connections per user: [userId => SplObjectStorage(connections)] - For DM
    protected $userConnections;
    // Store PDO connection
    private $pdo;

    public function __construct() {
        $this->rooms = [];
        $this->userConnections = []; // Initialize userConnections
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
                return null;
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
        if (!$this->pdo) return false;
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
        $messageString = json_encode($messageData);
        foreach ($this->rooms[$roomId] as $client) {
            if ($excludeConn !== null && $client === $excludeConn) {
                continue;
            }
            $client->send($messageString);
        }
    }

    public function onOpen(ConnectionInterface $conn) {
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $queryParams);
        $username = $queryParams['username'] ?? null;
        // roomId is now optional on connection. Client can connect for DMs without specifying a room.
        // Or can specify a room to join it immediately.
        $roomIdStr = $queryParams['roomId'] ?? null;

        if (empty($username)) {
            $this->sendErrorMessage($conn, "Username parameter is required for WebSocket connection.", 4001, "Username required");
            echo "Connection attempt without username. ({$conn->resourceId}) Closing.\n";
            return;
        }

        $roomId = null; // User is not in a room by default when connecting
        if (!empty($roomIdStr)) {
            if (!ctype_digit($roomIdStr)) {
                $this->sendErrorMessage($conn, "Invalid roomId format passed: {$roomIdStr}.", 4002, "Invalid roomId format");
                echo "Connection attempt with invalid roomId format: {$roomIdStr}. ({$conn->resourceId}) Closing.\n";
                return;
            }
            $roomId = (int)$roomIdStr;
        }

        if (!$this->pdo) {
            $this->sendErrorMessage($conn, "Chat server database is currently unavailable. Please try again later.", 5003, "Server DB offline");
            echo "DB connection not available. Rejecting new connection {$conn->resourceId}.\n";
            return;
        }

        $userId = $this->getUserId($username);
        if ($userId === null) {
            $this->sendErrorMessage($conn, "User '{$username}' not found or database error during lookup.", 4003, "User not found or DB error");
            echo "User '{$username}' not found for connection {$conn->resourceId}. Closing.\n";
            return;
        }

        // Store essential info on the connection object
        $conn->userId = $userId;
        $conn->username = $username;
        // $conn->resourceId is an existing property of ConnectionInterface

        // Manage user's global WebSocket connections (for DMs)
        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = new \SplObjectStorage;
        }

        // Policy: Allow only one active WebSocket connection per user ID for DMs to simplify routing.
        // Close any existing connections for this user.
        if ($this->userConnections[$userId]->count() > 0) {
            echo "User '{$username}' (ID: {$userId}) already has active WebSocket connection(s). Closing older one(s).\n";
            // Iterate over a copy for safe removal
            $tempStorage = clone $this->userConnections[$userId];
            foreach ($tempStorage as $existingClient) {
                if ($existingClient !== $conn) { // Don't close the current, incoming connection yet
                    $this->sendErrorMessage($existingClient, "Disconnected: A new WebSocket session was started from another location/tab.", 4000, "New session superseded");
                    // Actual detachment of $existingClient will happen in its onClose handler.
                }
            }
        }
        $this->userConnections[$userId]->attach($conn); // Attach the new, current connection

        echo "User '{$username}' (ID: {$userId}, Conn: {$conn->resourceId}) connected to WebSocket server. Ready for DMs.\n";
        $conn->send(json_encode(['type' => 'system_message', 'text' => 'Successfully connected to the chat server.']));

        // If a roomId was provided with the initial connection, attempt to join that room
        if ($roomId !== null) {
            if (!$this->isUserMemberOfRoom($userId, $roomId)) {
                // User is connected to server for DMs, but can't join this specific room.
                // Send a message to client about this room, but keep general WS connection alive.
                $conn->send(json_encode(['type' => 'error', 'context' => 'room_join', 'roomId' => $roomId, 'text' => "You are not a member of the specified room."]));
                echo "User '{$username}' (ID: {$userId}) attempted to auto-join room {$roomId} on connection, but is not a member.\n";
            } else {
                // User is a member, proceed to join the room
                if (!isset($this->rooms[$roomId])) {
                    $this->rooms[$roomId] = new \SplObjectStorage;
                }
                // Check if this user (identified by userId) already has a connection in this room.
                // This is slightly different from the global user connection check. A user might have one global DM connection,
                // and then join multiple rooms, each potentially creating a "room session" on the server.
                // For simplicity, if they are already in the room via another $conn object, we might also choose to supersede.
                // Current logic in onOpen for rooms already handles this by iterating $this->rooms[$roomId].
                // Let's ensure $conn->roomId is set before broadcasting join.
                $this->rooms[$roomId]->attach($conn);
                $conn->roomId = $roomId; // Associate this connection with the joined room

                $roomName = $this->getRoomName($roomId) ?? "ID {$roomId}";
                $joinText = "User '{$username}' has joined the room '{$roomName}'.";
                // Storing room-specific messages (join/leave/text)
                $this->storeRoomMessage($roomId, $userId, 'system_join', json_encode(['username' => $username, 'action' => 'join', 'text' => $joinText]));
                echo "User '{$username}' (ID: {$userId}, Conn: {$conn->resourceId}) also joined Room: {$roomName} (ID: {$roomId})\n";

                $joinBroadcastMessage = [
                    'type' => 'server_message',
                    'text' => $joinText,
                    'roomId' => $roomId // For client context
                ];
                $this->broadcastToRoom($roomId, $joinBroadcastMessage); // Broadcast to all in room
            }
        }
    }

    // Renamed from storeMessage to storeRoomMessage for clarity
    private function storeRoomMessage(int $roomId, int $userId, string $messageType, string $content, ?array $metadata = null): ?int {
        if (!$this->pdo) {
            $logMessage = "[FATAL storeRoomMessage] \$this->pdo is null. Message (Type: {$messageType}, User: {$userId}, Room: {$roomId}) cannot be stored.";
            echo $logMessage . "\n"; error_log($logMessage);
            return null;
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO chat_messages (room_id, user_id, message_type, content, metadata)
                 VALUES (:room_id, :user_id, :message_type, :content, :metadata)"
            );
            $stmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':message_type', $messageType);
            $stmt->bindParam(':content', $content);
            $jsonMetadata = $metadata ? json_encode($metadata) : null;
            $stmt->bindParam(':metadata', $jsonMetadata);
            $stmt->execute();
            $lastId = (int)$this->pdo->lastInsertId();
            echo "Stored room message ID {$lastId} of type '{$messageType}' for user {$userId} in room {$roomId}.\n";
            return $lastId;
        } catch (PDOException $e) {
            echo "Error storing room message (Type: {$messageType}, User: {$userId}, Room: {$roomId}): " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function storeDirectMessage(int $senderId, int $receiverId, string $content): ?int {
        if (!$this->pdo) {
            echo "[FATAL storeDirectMessage] DB connection not available. Message from {$senderId} to {$receiverId} cannot be stored.\n";
            return null;
        }
        try {
            // Note: direct_messages table was defined in previous step.
            $stmt = $this->pdo->prepare(
                "INSERT INTO direct_messages (sender_id, receiver_id, content, sent_at)
                 VALUES (:sender_id, :receiver_id, :content, CURRENT_TIMESTAMP)"
            );
            $stmt->bindParam(':sender_id', $senderId, PDO::PARAM_INT);
            $stmt->bindParam(':receiver_id', $receiverId, PDO::PARAM_INT);
            $stmt->bindParam(':content', $content);
            $stmt->execute();
            $lastId = (int)$this->pdo->lastInsertId();
            echo "Stored direct message ID {$lastId} from user {$senderId} to user {$receiverId}.\n";
            return $lastId;
        } catch (PDOException $e) {
            echo "Error storing direct message from {$senderId} to {$receiverId}: " . $e->getMessage() . "\n";
            return null;
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // Ensure connection is initialized with userId and username
        if (!isset($from->userId) || !isset($from->username)) {
            $this->sendErrorMessage($from, "Connection not fully initialized. Cannot process message.", 4007, "Connection not initialized");
            echo "Message received from uninitialized connection (resourceId {$from->resourceId}). Ignoring.\n";
            return;
        }

        $decodedMsg = json_decode($msg, true);

        // All client messages should now be JSON with a 'type' field
        if ($decodedMsg && isset($decodedMsg['type'])) {
            switch ($decodedMsg['type']) {
                case 'direct_message':
                    $this->handleDirectMessage($from, $decodedMsg);
                    break;
                case 'room_message':
                    // User must be in a room (conn->roomId must be set from onOpen or a join_room message)
                    if (isset($from->roomId) && isset($decodedMsg['text'])) {
                        $this->handleRoomMessage($from, $decodedMsg['text']);
                    } else if (!isset($from->roomId)){
                        $this->sendErrorMessage($from, "You are not currently in a room to send a room message.", 4015, "Not in room for room_message");
                    } else { // Missing text
                        $this->sendErrorMessage($from, "Missing 'text' field for room_message.", 4008, "Invalid room_message format");
                    }
                    break;
                // Example for future: case 'join_room': $this->handleJoinRoom($from, $decodedMsg); break;
                // Example for future: case 'leave_room': $this->handleLeaveRoom($from, $decodedMsg); break;
                default:
                    $this->sendErrorMessage($from, "Unknown message type received: '{$decodedMsg['type']}'.", 4009, "Unknown message type");
                    echo "Received message with unknown JSON type '{$decodedMsg['type']}' from {$from->username}. Ignoring.\n";
            }
        } else {
            // Non-JSON message or JSON without 'type'
             // For backward compatibility, if it's plain text and user is in a room, treat as room message.
            if (isset($from->roomId) && is_string($msg)) {
                echo "Received plain text message from {$from->username} in room {$from->roomId}. Processing as legacy room message.\n";
                $this->handleRoomMessage($from, $msg);
            } else {
                $this->sendErrorMessage($from, "Message format unrecognized. Messages must be JSON with a 'type' field.", 4010, "Unrecognized message format");
                echo "User {$from->username} (ID: {$from->userId}, Conn: {$from->resourceId}) sent unrecognized non-JSON message or JSON without type: {$msg}\n";
            }
        }
    }

    private function handleRoomMessage(ConnectionInterface $from, string $textContent) {
        // This connection must have a roomId associated from onOpen or a join_room action
        if (!isset($from->roomId)) {
            // This check is defensive; onMessage should already ensure $from->roomId for 'room_message' type.
            $this->sendErrorMessage($from, "Cannot send room message. You are not currently associated with a room.", 4014, "Not in a room");
            echo "User {$from->username} (ID: {$from->userId}) attempted to send room message but connection is not associated with a room. Ignoring.\n";
            return;
        }

        $senderUsername = $from->username;
        $senderUserId = $from->userId;
        $roomId = $from->roomId;
        $roomName = $this->getRoomName($roomId) ?? "ID {$roomId}"; // Fallback name

        // Store the user's text message in the chat_messages table
        $messageId = $this->storeRoomMessage($roomId, $senderUserId, 'text', $textContent);

        if ($messageId === null) {
            echo "Failed to store room message for user {$senderUsername} in room {$roomId}. Message will still attempt to broadcast.\n";
            // Optionally send an error back to the sender that DB store failed
        }

        $numRecv = (isset($this->rooms[$roomId]) ? $this->rooms[$roomId]->count() : 0);
        // If we exclude sender, $numRecv-1. If sender also gets it, $numRecv.
        // Current broadcastToRoom sends to all, including sender.

        echo sprintf('User %s (ID: %d, Conn: %d) in Room %s (ID: %d) sending message "%s" (DB ID: %s) to %d connection(s) in room.' . "\n",
            $senderUsername, $senderUserId, $from->resourceId, $roomName, $roomId, $textContent, $messageId ?? 'N/A', $numRecv);

        $messageDataForBroadcast = [
            'type' => 'room_message', // Explicit type for client-side routing
            'message_id' => $messageId, // DB ID of the message
            'sender_user_id' => $senderUserId,
            'sender_username' => $senderUsername,
            'room_id' => $roomId,
            'text' => $textContent,
            'timestamp' => date('Y-m-d H:i:s P') // Server timestamp, ISO 8601 format
        ];

        $this->broadcastToRoom($roomId, $messageDataForBroadcast);
    }


    private function handleDirectMessage(ConnectionInterface $from, array $messageData) {
        $senderUserId = $from->userId;
        $senderUsername = $from->username;
        $receiverId = $messageData['receiver_id'] ?? null;
        $content = $messageData['text'] ?? null;

        if ($receiverId === null || !is_numeric($receiverId) || $content === null || trim($content) === '') {
            $this->sendErrorMessage($from, "Missing, invalid, or empty 'receiver_id' or 'text' for direct_message.", 4011, "Invalid DM format");
            return;
        }
        $receiverId = (int)$receiverId;

        if ($senderUserId === $receiverId) {
            $this->sendErrorMessage($from, "Cannot send a direct message to yourself using this WebSocket method.", 4013, "DM to self via WS");
            return;
        }

        // Store the direct message in the direct_messages table
        $messageId = $this->storeDirectMessage($senderUserId, $receiverId, $content);
        if ($messageId === null) {
            $this->sendErrorMessage($from, "Failed to store your direct message in the database. Please try again.", 5004, "DM DB store failed");
            return; // Do not proceed if DB store fails, to maintain consistency
        }

        echo "User {$senderUsername} (ID: {$senderUserId}) sending DM to User ID {$receiverId}: \"{$content}\" (DB ID: {$messageId})\n";

        $payloadToDeliver = [
            'type' => 'direct_message', // Type for client-side routing
            'message_id' => $messageId, // DB ID of the message
            'sender_user_id' => $senderUserId,
            'sender_username' => $senderUsername,
            'receiver_user_id' => $receiverId, // Echo back for sender's context
            'text' => $content,
            'timestamp' => date('Y-m-d H:i:s P') // Server timestamp, ISO 8601 format
        ];

        // Attempt to deliver to receiver's active WebSocket connection(s)
        $receiverOnlineAndMessaged = false;
        if (isset($this->userConnections[$receiverId])) {
            foreach ($this->userConnections[$receiverId] as $receiverConn) {
                $receiverConn->send(json_encode($payloadToDeliver));
                $receiverOnlineAndMessaged = true;
            }
        }

        if ($receiverOnlineAndMessaged) {
             echo "DM from {$senderUsername} (ID {$senderUserId}) delivered to User ID {$receiverId} via WebSocket.\n";
        } else {
             echo "User ID {$receiverId} is not currently connected via WebSocket. DM has been stored.\n";
        }

        // Send the same payload (confirmation) back to the sender.
        // This confirms processing & gives sender the message_id and server timestamp.
        $from->send(json_encode($payloadToDeliver));
    }


    public function onClose(ConnectionInterface $conn) {
        $userId = $conn->userId ?? null; // User ID might not be set if onOpen failed early
        $username = $conn->username ?? 'UnknownUser';
        $resourceId = $conn->resourceId;

        // Remove connection from global userConnections list (for DMs)
        if ($userId !== null && isset($this->userConnections[$userId])) {
            $this->userConnections[$userId]->detach($conn);
            if ($this->userConnections[$userId]->count() === 0) {
                unset($this->userConnections[$userId]);
                echo "User {$username} (ID: {$userId}) has no more active WebSocket connections. Removed from global user list.\n";
            }
        }

        // If the connection was part of a specific room, handle room departure
        $roomId = $conn->roomId ?? null; // Check if connection was associated with a room
        if ($roomId !== null) {
            if (isset($this->rooms[$roomId])) {
                $this->rooms[$roomId]->detach($conn);
                $roomName = $this->getRoomName($roomId) ?? "ID {$roomId}"; // Fallback name
                echo "User '{$username}' (ID: {$userId}, Conn: {$resourceId}) has disconnected from Room: {$roomName} (ID: {$roomId})\n";

                // Only store and broadcast leave message if user was properly identified (had userId)
                if ($userId !== null) {
                    $leaveText = "User '{$username}' has left the room '{$roomName}'.";
                    // Storing room-specific system message
                    $this->storeRoomMessage($roomId, $userId, 'system_leave', json_encode(['username' => $username, 'action' => 'leave', 'text' => $leaveText]));

                    $leaveBroadcastMessage = [
                        'type' => 'server_message', // Generic server message for room events
                        'text' => $leaveText,
                        'roomId' => $roomId // Context for client
                    ];
                    $this->broadcastToRoom($roomId, $leaveBroadcastMessage);
                }

                // Clean up room if empty
                if ($this->rooms[$roomId]->count() == 0) {
                    echo "Room {$roomName} (ID: {$roomId}) is now empty. Removing from active server rooms.\n";
                    unset($this->rooms[$roomId]);
                }
            } else {
                // This might occur if room was already cleaned up or if $conn->roomId was stale.
                echo "User '{$username}' (Conn {$resourceId}) disconnected; was noted as in room {$roomId}, but room was not found in active server list.\n";
            }
        } else if ($userId !== null) {
            // User was connected to server (e.g., for DMs) but not in a specific room context when disconnecting.
             echo "User '{$username}' (ID: {$userId}, Conn {$resourceId}) has disconnected from the WebSocket server (was not in a specific room context at time of disconnect).\n";
        } else {
            // Fallback for connections that didn't fully initialize or were anonymous.
            echo "An unassociated or partially initialized WebSocket connection {$resourceId} has disconnected.\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $identifier = "Connection {$conn->resourceId}";
        // Enhance identifier if user details are available on the connection
        if (isset($conn->userId) && isset($conn->username)) {
            $identifier = "User '{$conn->username}' (ID: {$conn->userId}, Conn {$conn->resourceId})";
        } elseif (isset($conn->userId)) {
             $identifier = "User ID {$conn->userId} (Conn {$conn->resourceId})";
        }

        echo "An error has occurred with {$identifier}: {$e->getMessage()}\n";
        // For debugging, you might want to log: $e->getFile(), $e->getLine(), $e->getTraceAsString()
        error_log("WebSocket Error for {$identifier}: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");


        // Attempt to send a generic error message to the client, if the connection is still viable for sending
        try {
            // Check if httpRequest is available, which is a property of Ratchet's ConnectionInterface for WsServer
            if ($conn->httpRequest !== null) {
                 $this->sendErrorMessage($conn, "An internal server error occurred. Please try reconnecting. Details: " . $e->getMessage());
            }
        } catch (\Exception $sendException) {
            // Log if sending the error message itself fails
            error_log("Could not send error message to client {$identifier} after an error: {$sendException->getMessage()}");
        }

        // It's critical to close the connection if an error makes its state uncertain or unrecoverable.
        // The onClose method will then handle any further cleanup.
        $conn->close(1011, "Internal server error"); // 1011 indicates server error preventing fulfillment of request.
    }
}
