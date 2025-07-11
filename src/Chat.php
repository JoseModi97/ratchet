<?php
namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use MyApp\Database; // To validate tokens
use PDO;
use PDOException;

class Chat implements MessageComponentInterface {
    protected $clients;
    protected $db; // Database connection

    // Store mapping of resourceId to user_id and username
    protected $authenticatedUsers;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        // In a more robust setup, the Database object would be injected.
        // For simplicity here, we instantiate it directly.
        try {
            $this->db = (new Database())->getConnection();
        } catch (PDOException $e) {
            // Handle DB connection error during Chat server startup
            echo "FATAL: Could not connect to database for Chat server: " . $e->getMessage() . "\n";
            // Depending on policy, might exit or try to run in a degraded mode (not possible with auth)
            exit(1); // Exit if DB is essential for chat operations like auth
        }
        $this->authenticatedUsers = [];
        echo "Chat server started...\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Extract token from query string
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $queryParams);
        $token = $queryParams['token'] ?? null;

        if (!$token) {
            echo "Connection attempt without token from {$conn->remoteAddress}. Closing.\n";
            $conn->send(json_encode(['type' => 'error', 'message' => 'Authentication token required.']));
            $conn->close();
            return;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT us.user_id, u.username
                FROM user_sessions us
                JOIN users u ON us.user_id = u.id
                WHERE us.token = :token AND us.expires_at > NOW()
            ");
            $stmt->bindParam(':token', $token);
            $stmt->execute();
            $sessionUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($sessionUser) {
                $this->clients->attach($conn);
                $this->authenticatedUsers[$conn->resourceId] = [
                    'user_id' => $sessionUser['user_id'],
                    'username' => $sessionUser['username']
                ];
                echo "New authenticated connection! ({$conn->resourceId} as {$sessionUser['username']})\n";

                // Announce new user to other clients
                $joinMessage = json_encode([
                    'type' => 'user_join',
                    'username' => $sessionUser['username'],
                    'resourceId' => $conn->resourceId // Could be omitted for privacy
                ]);
                foreach ($this->clients as $client) {
                    if ($conn !== $client) {
                        $client->send($joinMessage);
                    }
                }
                 // Send confirmation to the newly connected user
                $conn->send(json_encode(['type' => 'auth_success', 'message' => 'Successfully authenticated.', 'username' => $sessionUser['username']]));

                // Update user presence to 'online'
                $this->updateUserPresence($sessionUser['user_id'], 'online');

            } else {
                echo "Invalid or expired token '{$token}' from {$conn->remoteAddress}. Closing connection {$conn->resourceId}.\n";
                $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid or expired token.']));
                $conn->close();
            }
        } catch (PDOException $e) {
            echo "Database error during token validation for {$conn->resourceId}: " . $e->getMessage() . "\n";
            $conn->send(json_encode(['type' => 'error', 'message' => 'Server error during authentication.']));
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        if (!isset($this->authenticatedUsers[$from->resourceId])) {
            // Should not happen if onOpen properly closes unauthenticated connections
            echo "Message from unauthenticated connection {$from->resourceId}. Discarding.\n";
            $from->send(json_encode(['type' => 'error', 'message' => 'Not authenticated.']));
            $from->close();
            return;
        }

        $senderInfo = $this->authenticatedUsers[$from->resourceId];
        $numRecv = count($this->clients) - 1;
        echo sprintf('Connection %d (%s) sending message "%s" to %d other connection%s' . "\n",
            $from->resourceId, $senderInfo['username'], $msg, $numRecv, $numRecv == 1 ? '' : 's');

        $messageData = json_encode([
            'type' => 'message',
            'user_id' => $senderInfo['user_id'],
            'username' => $senderInfo['username'],
            'text' => $msg // Assuming $msg is plain text for now; consider sanitization/structure
        ]);

        foreach ($this->clients as $client) {
            if ($from !== $client && isset($this->authenticatedUsers[$client->resourceId])) {
                $client->send($messageData);
            }
        }

        // Persist message to database
        // TODO: Handle room_id dynamically once chat rooms are implemented. Using default 1 for now.
        $default_room_id = 1;
        try {
            $stmt = $this->db->prepare("INSERT INTO messages (room_id, user_id, content) VALUES (:room_id, :user_id, :content)");
            $stmt->bindParam(':room_id', $default_room_id, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $senderInfo['user_id'], PDO::PARAM_INT);
            $stmt->bindParam(':content', $msg); // $msg is the raw message content
            $stmt->execute();
        } catch (PDOException $e) {
            echo "Database error during message persistence for user {$senderInfo['user_id']}: " . $e->getMessage() . "\n";
            // Optionally, notify the sender that their message could not be saved
            // $from->send(json_encode(['type' => 'error', 'message' => 'Your message could not be saved to history.']));
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);

        if (isset($this->authenticatedUsers[$conn->resourceId])) {
            $userInfo = $this->authenticatedUsers[$conn->resourceId];
            echo "Connection {$conn->resourceId} ({$userInfo['username']}) has disconnected\n";
            unset($this->authenticatedUsers[$conn->resourceId]);

            // Announce user leaving to other clients
            $leaveMessage = json_encode([
                'type' => 'user_leave',
                'username' => $userInfo['username'],
                'resourceId' => $conn->resourceId // Could be omitted
            ]);
            foreach ($this->clients as $client) {
                $client->send($leaveMessage);
            }
            // Update user presence to 'offline'
            $this->updateUserPresence($userInfo['user_id'], 'offline');
        } else {
            echo "Unauthenticated connection {$conn->resourceId} has disconnected\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred with connection {$conn->resourceId}: {$e->getMessage()}\n";
        // Ensure user info is cleaned up if an error causes a disconnect
        $userInfo = null;
        if (isset($this->authenticatedUsers[$conn->resourceId])) {
            $userInfo = $this->authenticatedUsers[$conn->resourceId];
            unset($this->authenticatedUsers[$conn->resourceId]);
        }
        $conn->close();
        // Also set user to offline if they were authenticated and an error occurred
        if ($userInfo && $userInfo['user_id']) {
            $this->updateUserPresence($userInfo['user_id'], 'offline');
        }
    }

    protected function updateUserPresence($userId, $status) {
        try {
            // Using INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing presence records.
            // Requires user_id to be a PRIMARY KEY or UNIQUE KEY in user_presence table, which it is.
            $stmt = $this->db->prepare("
                INSERT INTO user_presence (user_id, status, last_active)
                VALUES (:user_id, :status, NOW())
                ON DUPLICATE KEY UPDATE status = :status, last_active = NOW()
            ");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':status', $status); // ENUM, so string
            $stmt->execute();
            echo "User presence updated for user_id {$userId} to {$status}.\n";
        } catch (PDOException $e) {
            echo "Database error updating user presence for user_id {$userId}: " . $e->getMessage() . "\n";
            // Log this error. Depending on policy, this might not be critical enough to disconnect user.
        }
    }
}
