<?php
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use MyApp\Chat;
use MyApp\Db; // Import the Db class

require dirname(__DIR__) . '/vendor/autoload.php';

// Get database configuration and establish PDO connection
try {
    $pdo = Db::getConnection();
    echo "Database connection established successfully for WebSocket server.\n";
} catch (\PDOException $e) {
    error_log("FATAL: Could not connect to database for WebSocket server: " . $e->getMessage());
    echo "FATAL: Could not connect to database. Check error logs. WebSocket server cannot start.\n";
    exit(1); // Exit if DB connection fails, as Chat class requires it
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Chat($pdo) // Pass the PDO connection to the Chat constructor
        )
    ),
    8080
);

echo "WebSocket server listening on port 8080\n";
$server->run();
