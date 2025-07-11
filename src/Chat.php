<?php
namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use MyApp\Db; // Assuming Db.php is in MyApp namespace and autoloaded

class Chat implements MessageComponentInterface {
    protected $clients; // Stores ConnectionInterface objects mapped to user info
    protected $pdo;
    // For managing clients per room: [roomId => SplObjectStorage of connections]
    protected $roomClients;

    public function __construct(\PDO $pdo) {
        $this->clients = new \SplObjectStorage;
        $this->pdo = $pdo;
        $this->roomClients = []; // Initialize roomClients
        echo "Chat server started with DB connection...\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Extract token from query parameter
        $queryParams = [];
        parse_str($conn->httpRequest->getUri()->getQuery(), $queryParams);
        $token = $queryParams['token'] ?? null;

        if (!$token) {
            echo "Connection attempt without token. Closing ({$conn->resourceId})\n";
            $conn->send(json_encode(['type' => 'error', 'message' => 'Authentication token required.']));
            $conn->close();
            return;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT id, username FROM users WHERE session_token = :token AND token_expires_at > NOW()");
            $stmt->execute([':token' => $token]);
            $user = $stmt->fetch();

            if (!$user) {
                echo "Invalid or expired token. Closing connection ({$conn->resourceId})\n";
                $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid or expired token.']));
                $conn->close();
                return;
            }

            // Store user information with the connection
            $userInfo = [
                'user_id' => $user['id'],
                'username' => $user['username'],
                // 'rooms' => new \SplObjectStorage() // We will manage room subscriptions differently
            ];
            $this->clients->attach($conn, $userInfo);
            echo "User {$user['username']} (ID: {$user['id']}) connected. ({$conn->resourceId})\n";
            $conn->send(json_encode(['type' => 'auth_success', 'message' => 'Successfully authenticated.', 'user_id' => $user['id'], 'username' => $user['username']]));


            // Update user presence to 'online'
            $presenceStmt = $this->pdo->prepare("UPDATE user_presence SET status = 'online', last_active = NOW() WHERE user_id = :user_id");
            $presenceStmt->execute([':user_id' => $user['id']]);
            if ($presenceStmt->rowCount() === 0) {
                $insertPresenceStmt = $this->pdo->prepare("INSERT INTO user_presence (user_id, status, last_active) VALUES (:user_id, 'online', NOW())");
                $insertPresenceStmt->execute([':user_id' => $user['id']]);
            }

        } catch (\PDOException $e) {
            error_log("onOpen DB Error: " . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'message' => 'Server authentication error.']));
            $conn->close();
        } catch (\Exception $e) {
            error_log("onOpen General Error: " . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'message' => 'Unexpected server error during connection.']));
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        if (!$this->clients->contains($from)) {
            // Should not happen if onOpen was successful
            $from->send(json_encode(['type' => 'error', 'message' => 'Not authenticated.']));
            $from->close();
            return;
        }
        $senderInfo = $this->clients[$from];
        $userId = $senderInfo['user_id'];
        $username = $senderInfo['username'];

        echo sprintf("User %s (ID: %d, Conn %d) sent: %s\n", $username, $userId, $from->resourceId, $msg);

        $data = json_decode($msg, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['type']) || !isset($data['roomId'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid message format. Expecting JSON with type and roomId.']));
            return;
        }

        $roomId = (int)$data['roomId'];

        // Ensure roomClients entry exists for this room
        if (!isset($this->roomClients[$roomId])) {
            $this->roomClients[$roomId] = new \SplObjectStorage();
        }

        try {
            switch ($data['type']) {
                case 'joinRoom':
                    // 1. Verify user can join (is public, or member of private room via room_members)
                    // This check might be better on API side before client attempts to join via WS
                    // For now, a simple check on room_members, assuming API handled initial access.
                    $checkMemberStmt = $this->pdo->prepare(
                        "SELECT cr.is_private FROM chat_rooms cr
                         LEFT JOIN room_members rm ON cr.id = rm.room_id AND rm.user_id = :user_id
                         WHERE cr.id = :room_id"
                    );
                    $checkMemberStmt->execute([':user_id' => $userId, ':room_id' => $roomId]);
                    $roomDetails = $checkMemberStmt->fetch();

                    if (!$roomDetails) {
                        $from->send(json_encode(['type' => 'error', 'roomId' => $roomId, 'message' => 'Room not found or access denied.']));
                        return;
                    }

                    // If room is private, they must be a member (rm.user_id would be part of the check if query was different)
                    // A more explicit check:
                    if ($roomDetails['is_private']) {
                        $memberCheck = $this->pdo->prepare("SELECT 1 FROM room_members WHERE room_id = :room_id AND user_id = :user_id");
                        $memberCheck->execute([':room_id' => $roomId, ':user_id' => $userId]);
                        if (!$memberCheck->fetch()) {
                             $from->send(json_encode(['type' => 'error', 'roomId' => $roomId, 'message' => 'Access denied to private room.']));
                             return;
                        }
                    }

                    // Add connection to the room's client list
                    $this->roomClients[$roomId]->attach($from);
                    $from->send(json_encode(['type' => 'joinedRoom', 'roomId' => $roomId, 'message' => "Successfully joined room {$roomId}"]));
                    echo "User {$username} joined room {$roomId}\n";

                    // Broadcast to other clients in the room
                    $joinNotification = json_encode([
                        'type' => 'userJoined',
                        'roomId' => $roomId,
                        'user' => ['id' => $userId, 'username' => $username]
                    ]);
                    foreach ($this->roomClients[$roomId] as $clientInRoom) {
                        if ($clientInRoom !== $from) {
                            $clientInRoom->send($joinNotification);
                        }
                    }
                    break;

                case 'leaveRoom':
                    if (isset($this->roomClients[$roomId]) && $this->roomClients[$roomId]->contains($from)) {
                        $this->roomClients[$roomId]->detach($from);
                        echo "User {$username} left room {$roomId}\n";
                        $from->send(json_encode(['type' => 'leftRoom', 'roomId' => $roomId, 'message' => "Successfully left room {$roomId}"]));

                        // Broadcast to other clients in the room
                        $leaveNotification = json_encode([
                            'type' => 'userLeft',
                            'roomId' => $roomId,
                            'user' => ['id' => $userId, 'username' => $username]
                        ]);
                        foreach ($this->roomClients[$roomId] as $clientInRoom) {
                            $clientInRoom->send($leaveNotification);
                        }
                        // If room becomes empty, clean it up from $this->roomClients
                        if (count($this->roomClients[$roomId]) === 0) {
                            unset($this->roomClients[$roomId]);
                            echo "Room {$roomId} is now empty and removed from active list.\n";
                        }
                    } else {
                        $from->send(json_encode(['type' => 'error', 'roomId' => $roomId, 'message' => 'Not currently in this room.']));
                    }
                    break;

                case 'message':
                    if (!isset($data['content']) || !is_string($data['content']) || trim($data['content']) === '') {
                        $from->send(json_encode(['type' => 'error', 'roomId' => $roomId, 'message' => 'Message content is required.']));
                        return;
                    }
                    $content = htmlspecialchars(trim($data['content'])); // Basic XSS protection

                    if (!isset($this->roomClients[$roomId]) || !$this->roomClients[$roomId]->contains($from)) {
                        $from->send(json_encode(['type' => 'error', 'roomId' => $roomId, 'message' => 'You must join the room before sending messages.']));
                        return;
                    }

                    // Save to messages table
                    $stmt = $this->pdo->prepare("INSERT INTO messages (room_id, user_id, content, created_at) VALUES (:room_id, :user_id, :content, NOW())");
                    $stmt->execute([
                        ':room_id' => $roomId,
                        ':user_id' => $userId,
                        ':content' => $content
                    ]);
                    $messageId = $this->pdo->lastInsertId();
                    $timestamp = date('Y-m-d H:i:s'); // Approximate, actual is from DB

                    // Broadcast to all clients in the room (including sender for confirmation)
                    $messageData = json_encode([
                        'type' => 'newMessage',
                        'messageId' => $messageId,
                        'roomId' => $roomId,
                        'user' => ['id' => $userId, 'username' => $username],
                        'content' => $content,
                        'timestamp' => $timestamp
                    ]);
                    foreach ($this->roomClients[$roomId] as $clientInRoom) {
                        $clientInRoom->send($messageData);
                    }
                    echo "User {$username} sent message to room {$roomId}: {$content}\n";
                    break;

                default:
                    $from->send(json_encode(['type' => 'error', 'message' => "Unknown message type: {$data['type']}"]));
                    break;
            }
        } catch (\PDOException $e) {
            error_log("onMessage DB Error for user {$username} (Room {$roomId}): " . $e->getMessage());
            $from->send(json_encode(['type' => 'error', 'roomId' => $roomId, 'message' => 'Database error processing your request.']));
        } catch (\Exception $e) {
            error_log("onMessage General Error for user {$username} (Room {$roomId}): " . $e->getMessage());
            $from->send(json_encode(['type' => 'error', 'roomId' => $roomId, 'message' => 'Server error processing your request.']));
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if ($this->clients->contains($conn)) {
            $userInfo = $this->clients[$conn];
            echo "User {$userInfo['username']} (ID: {$userInfo['user_id']}) disconnected. ({$conn->resourceId})\n";

            try {
                // Update user presence to 'offline'
                $presenceStmt = $this->pdo->prepare("UPDATE user_presence SET status = 'offline', last_active = NOW() WHERE user_id = :user_id");
                $presenceStmt->execute([':user_id' => $userInfo['user_id']]);
            } catch (\PDOException $e) {
                error_log("onClose DB Error: " . $e->getMessage());
            }

            // Remove from global client list
            $this->clients->detach($conn);

            // Remove from all room subscriptions (logic to be added in Step 11/12)
            // This requires iterating through $this->roomClients and detaching $conn
            foreach ($this->roomClients as $roomId => $roomConnections) {
                if ($roomConnections->contains($conn)) {
                    $roomConnections->detach($conn);
                    // Broadcast leave message to this room (logic in Step 11)
                    $leaveMessage = json_encode([
                        'type' => 'userLeft',
                        'roomId' => $roomId,
                        'user' => ['id' => $userInfo['user_id'], 'username' => $userInfo['username']]
                    ]);
                    foreach ($roomConnections as $clientInRoom) {
                        $clientInRoom->send($leaveMessage);
                    }
                     echo "Broadcasted leave for user {$userInfo['username']} from room {$roomId}\n";
                }
            }

        } else {
            echo "Unknown connection disconnected ({$conn->resourceId})\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $errorMessage = "An error has occurred: {$e->getMessage()}";
        error_log($errorMessage . " for connection {$conn->resourceId}");

        if ($this->clients->contains($conn)) {
            $userInfo = $this->clients[$conn];
            echo "Error for user {$userInfo['username']}: {$e->getMessage()}\n";
            // Potentially send an error message to the client before closing
            // $conn->send(json_encode(['type' => 'error', 'message' => 'Server error encountered.']));
        }

        $conn->close(); // This will trigger onClose eventually
    }
}
