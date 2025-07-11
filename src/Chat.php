<?php
namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Chat implements MessageComponentInterface {
    protected $clients;
    // Store username associated with a connection: [resourceId => username]
    protected $usernames;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->usernames = [];
        echo "Chat server started...\n";
    }

    private function getUsername(ConnectionInterface $conn) {
        return $this->usernames[$conn->resourceId] ?? null;
    }

    private function broadcastServerMessage($messageText) {
        $message = json_encode(['type' => 'server_message', 'text' => $messageText]);
        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }

    private function sendErrorMessage(ConnectionInterface $conn, $errorMessage) {
        $message = json_encode(['type' => 'error', 'text' => $errorMessage]);
        $conn->send($message);
    }

    public function onOpen(ConnectionInterface $conn) {
        // Extract username from query string
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $queryParams);
        $username = $queryParams['username'] ?? null;

        if (empty($username)) {
            echo "Connection attempt without username. ({$conn->resourceId}) Closing.\n";
            $this->sendErrorMessage($conn, "Username parameter is missing. Connection rejected.");
            $conn->close(4000, "Username required"); // 4000 is a custom close code
            return;
        }

        // Check if username is already connected (simple check, could be more robust)
        if (in_array($username, $this->usernames)) {
            echo "Connection attempt with already connected username: {$username} ({$conn->resourceId}). Closing.\n";
            $this->sendErrorMessage($conn, "Username '{$username}' is already in use. Connection rejected.");
            $conn->close(4001, "Username in use");
            return;
        }

        $this->clients->attach($conn);
        $this->usernames[$conn->resourceId] = $username;
        echo "New connection! User: {$username} ({$conn->resourceId})\n";

        $joinMessage = json_encode([
            'type' => 'server_message',
            'text' => "User '{$username}' has joined the chat."
        ]);
        foreach ($this->clients as $client) {
            // Don't send to self, but it's a server message, so all get it.
            $client->send($joinMessage);
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $senderUsername = $this->getUsername($from);
        if (!$senderUsername) {
            echo "Message from unknown user (resourceId {$from->resourceId}). Ignoring.\n";
            $this->sendErrorMessage($from, "You are not properly authenticated. Message rejected.");
            // $from->close(4000, "Not authenticated"); // Optionally close connection
            return;
        }

        $numRecv = count($this->clients) - 1;
        echo sprintf('User %s (Connection %d) sending message "%s" to %d other connection%s' . "\n",
            $senderUsername, $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's');

        $messageData = json_encode([
            'type' => 'message',
            'sender' => $senderUsername,
            'text' => $msg // Assuming $msg is plain text from client
        ]);

        foreach ($this->clients as $client) {
            // The sender is not the receiver, send to each client connected
            // Client-side will handle if sender is "Me"
            $client->send($messageData);
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $username = $this->getUsername($conn);

        $this->clients->detach($conn);
        unset($this->usernames[$conn->resourceId]);

        if ($username) {
            echo "User '{$username}' (Connection {$conn->resourceId}) has disconnected\n";
            $leaveMessage = json_encode([
                'type' => 'server_message',
                'text' => "User '{$username}' has left the chat."
            ]);
            foreach ($this->clients as $client) {
                $client->send($leaveMessage);
            }
        } else {
            echo "Connection {$conn->resourceId} (unknown user) has disconnected\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $username = $this->getUsername($conn);
        $identifier = $username ? "User '{$username}' ({$conn->resourceId})" : "Connection {$conn->resourceId}";

        echo "An error has occurred for {$identifier}: {$e->getMessage()}\n";

        // Optionally, notify other clients if it's a user-specific error that causes disconnect
        // if ($username) {
        //     $errorMessage = json_encode([
        //         'type' => 'server_message',
        //         'text' => "User '{$username}' experienced an error and may have disconnected."
        //     ]);
        //     foreach ($this->clients as $client) {
        //         if ($conn !== $client) {
        //             $client->send($errorMessage);
        //         }
        //     }
        // }
        $this->sendErrorMessage($conn, "An internal server error occurred: " . $e->getMessage());
        $conn->close();
    }
}
