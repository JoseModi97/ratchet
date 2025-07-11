<?php
// Autoload dependencies
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Database;

header('Content-Type: application/json');

// Only allow GET requests for listing rooms
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Only GET is allowed for this endpoint.']);
    exit;
}

// TODO: Add authentication check here.
// For now, it's public, but listing rooms should ideally be for authenticated users.
// We would typically get the token from a header (e.g., Authorization: Bearer <token>)
// and validate it similar to how the WebSocket server does.

$db = new Database();
try {
    $pdo = $db->getConnection();

    // Fetch all public chat rooms (is_private = FALSE or 0)
    // Also fetching created_by username for more context
    $stmt = $pdo->prepare("
        SELECT cr.id, cr.name, cr.is_private, cr.created_at, u.username as created_by_username
        FROM chat_rooms cr
        LEFT JOIN users u ON cr.created_by = u.id
        WHERE cr.is_private = FALSE
        ORDER BY cr.created_at DESC
    ");
    // If we want to list ALL rooms for an admin or for a user to see private rooms they are part of,
    // the query would be different and involve checking room_members table.
    // For now, just public rooms.

    $stmt->execute();
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rooms === false) {
        // This case is unlikely for fetchAll unless query itself fails,
        // but good for robustness. An empty result is an empty array, not false.
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not fetch rooms.']);
        exit;
    }

    http_response_code(200); // OK
    echo json_encode(['status' => 'success', 'rooms' => $rooms]);

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error: $e->getMessage()
    echo json_encode(['status' => 'error', 'message' => 'Database connection error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error: $e->getMessage()
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
}

?>
