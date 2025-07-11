<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Db;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // For development
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function getAuthenticatedUser($pdo) {
    if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return null;
    }
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE session_token = :token AND token_expires_at > NOW()");
        $stmt->execute([':token' => $token]);
        return $stmt->fetch();
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Only GET is supported.']);
    exit;
}

$pdo = Db::getConnection();
$authenticatedUser = getAuthenticatedUser($pdo);

if (!$authenticatedUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$roomId = $_GET['room_id'] ?? null;

if (empty($roomId) || !is_numeric($roomId)) {
    http_response_code(400);
    echo json_encode(['error' => 'room_id parameter is required and must be numeric.']);
    exit;
}
$roomId = (int)$roomId;

try {
    // Check if room exists and if user has access
    $roomAccessStmt = $pdo->prepare(
        "SELECT cr.id, cr.is_private
         FROM chat_rooms cr
         LEFT JOIN room_members rm ON cr.id = rm.room_id AND rm.user_id = :user_id
         WHERE cr.id = :room_id"
    );
    $roomAccessStmt->execute([':room_id' => $roomId, ':user_id' => $authenticatedUser['id']]);
    $roomInfo = $roomAccessStmt->fetch();

    if (!$roomInfo) {
        http_response_code(404);
        echo json_encode(['error' => 'Room not found.']);
        exit;
    }

    // If room is private, user must be a member (rm.user_id would be non-null due to LEFT JOIN condition)
    // The check `rm.user_id = :user_id` in the join and then checking if $roomInfo itself is found along with is_private
    // effectively checks membership for private rooms.
    // A more explicit check:
    if ($roomInfo['is_private']) {
        $memberCheckStmt = $pdo->prepare("SELECT 1 FROM room_members WHERE room_id = :room_id AND user_id = :user_id");
        $memberCheckStmt->execute([':room_id' => $roomId, ':user_id' => $authenticatedUser['id']]);
        if (!$memberCheckStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied. You are not a member of this private room.']);
            exit;
        }
    }

    // Fetch messages
    // Add pagination later if needed (e.g., ?limit=50&offset=0 or ?before_message_id=X)
    $messagesStmt = $pdo->prepare(
        "SELECT m.id, m.room_id, m.user_id, u.username as sender_username, m.content, m.created_at
         FROM messages m
         JOIN users u ON m.user_id = u.id
         WHERE m.room_id = :room_id
         ORDER BY m.created_at ASC" // Or DESC for newest first, depending on UI
    );
    $messagesStmt->execute([':room_id' => $roomId]);
    $messages = $messagesStmt->fetchAll();

    echo json_encode(['success' => true, 'messages' => $messages]);

} catch (PDOException $e) {
    error_log("GET /messages DB Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error while fetching messages.']);
} catch (Exception $e) {
    error_log("GET /messages General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
}
