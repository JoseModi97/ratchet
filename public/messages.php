<?php
// Autoload dependencies
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Database;

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Only GET is allowed.']);
    exit;
}

// Get room_id from query parameter
$roomId = filter_input(INPUT_GET, 'room_id', FILTER_VALIDATE_INT);

if ($roomId === false || $roomId === null || $roomId <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'A valid room_id query parameter is required.']);
    exit;
}

// TODO: Add authentication and authorization here.
// 1. Check if user is authenticated (e.g., via Bearer token).
// 2. Check if the authenticated user has permission to view messages for this $roomId
//    (e.g., if the room is private, is the user a member of room_members?).
// For now, this endpoint is open for any valid room_id.

$db = new Database();
try {
    $pdo = $db->getConnection();

    // Check if the room exists and if the user has access (basic check for now)
    // A more thorough check would join with room_members if the room is_private
    $roomCheckStmt = $pdo->prepare("SELECT id, is_private FROM chat_rooms WHERE id = :room_id");
    $roomCheckStmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
    $roomCheckStmt->execute();
    $room = $roomCheckStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        http_response_code(404); // Not Found
        echo json_encode(['status' => 'error', 'message' => 'Room not found.']);
        exit;
    }

    // If room is private, future logic would check membership here.
    // if ($room['is_private']) {
    //     // ... check if authenticated user is in room_members for $roomId ...
    //     // If not, return 403 Forbidden.
    // }


    // Fetch messages for the given room_id, joining with users table to get username
    // Ordered by creation time (oldest first)
    $stmt = $pdo->prepare("
        SELECT m.id, m.room_id, m.user_id, u.username AS username, m.content, m.created_at
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.room_id = :room_id
        ORDER BY m.created_at ASC
    ");
    // Add LIMIT and OFFSET for pagination in a future enhancement

    $stmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($messages === false) {
        // Should not happen if query is correct and DB is up.
        // An empty result is an empty array.
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not fetch messages.']);
        exit;
    }

    http_response_code(200); // OK
    echo json_encode(['status' => 'success', 'room_id' => $roomId, 'messages' => $messages]);

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error: $e->getMessage()
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error: $e->getMessage()
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
}
?>
