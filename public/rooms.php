<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Db;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // For development
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

$pdo = Db::getConnection();
$user = getAuthenticatedUser($pdo);

if (!$user && $_SERVER['REQUEST_METHOD'] !== 'GET') { // Allow unauthenticated GET for public rooms if desired, but spec implies auth for listing.
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Fetch public rooms
        $publicRoomsStmt = $pdo->prepare(
            "SELECT cr.id, cr.name, cr.is_private, cr.created_at, u.username as created_by_username
             FROM chat_rooms cr
             LEFT JOIN users u ON cr.created_by = u.id
             WHERE cr.is_private = FALSE
             ORDER BY cr.created_at DESC"
        );
        $publicRoomsStmt->execute();
        $publicRooms = $publicRoomsStmt->fetchAll();

        $privateRooms = [];
        if ($user) { // Only fetch private rooms if user is authenticated
            $privateRoomsStmt = $pdo->prepare(
                "SELECT cr.id, cr.name, cr.is_private, cr.created_at, u.username as created_by_username
                 FROM chat_rooms cr
                 JOIN room_members rm ON cr.id = rm.room_id
                 LEFT JOIN users u ON cr.created_by = u.id
                 WHERE rm.user_id = :user_id AND cr.is_private = TRUE
                 ORDER BY cr.created_at DESC"
            );
            $privateRoomsStmt->execute([':user_id' => $user['id']]);
            $privateRooms = $privateRoomsStmt->fetchAll();
        }

        // Combine rooms, ensuring no duplicates if a user is a member of a public room (though current queries prevent this)
        // For now, just concatenate. A more robust approach might merge and unique.
        $allRooms = array_merge($publicRooms, $privateRooms);

        // A simple way to remove duplicates by ID if any were to arise from complex joins (not expected here)
        $uniqueRooms = [];
        $ids = [];
        foreach ($allRooms as $room) {
            if (!in_array($room['id'], $ids)) {
                $uniqueRooms[] = $room;
                $ids[] = $room['id'];
            }
        }

        echo json_encode(['success' => true, 'rooms' => $uniqueRooms]);

    } catch (PDOException $e) {
        error_log("GET /rooms DB Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error while fetching rooms.']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // This will be implemented in the next step (Part 2)
    if (!$user) { // Re-check user for POST explicitly
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required to create a room.']);
        exit;
    }
    if (!$user) { // Should have been caught earlier, but double check
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required to create a room.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input for room creation.']);
        exit;
    }

    $roomName = $input['name'] ?? null;
    $isPrivate = $input['is_private'] ?? false;

    if (empty($roomName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Room name is required.']);
        exit;
    }
    if (!is_string($roomName) || strlen($roomName) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid room name (must be string, max 100 chars).']);
        exit;
    }
    if (!is_bool($isPrivate)) {
        http_response_code(400);
        echo json_encode(['error' => 'is_private must be a boolean.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO chat_rooms (name, is_private, created_by, created_at) VALUES (:name, :is_private, :created_by, NOW())");
        $stmt->execute([
            ':name' => $roomName,
            ':is_private' => $isPrivate ? 1 : 0,
            ':created_by' => $user['id']
        ]);
        $roomId = $pdo->lastInsertId();

        // Automatically add creator as a member of the room
        $memberStmt = $pdo->prepare("INSERT INTO room_members (user_id, room_id, joined_at) VALUES (:user_id, :room_id, NOW())");
        $memberStmt->execute([
            ':user_id' => $user['id'],
            ':room_id' => $roomId
        ]);

        $pdo->commit();

        http_response_code(201); // Created
        echo json_encode([
            'success' => true,
            'message' => 'Room created successfully.',
            'room' => [
                'id' => (int)$roomId,
                'name' => $roomName,
                'is_private' => (bool)$isPrivate,
                'created_by' => $user['id'],
                'created_by_username' => $user['username'], // Added for consistency
                'created_at' => date('Y-m-d H:i:s') // Approximate, actual is from DB
            ]
        ]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("POST /rooms DB Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error while creating room.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("POST /rooms General Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'An unexpected error occurred while creating room.']);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Only GET and POST are supported.']);
}
