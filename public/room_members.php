<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Db;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // For development
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
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
$authenticatedUser = getAuthenticatedUser($pdo);

if (!$authenticatedUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE && $_SERVER['REQUEST_METHOD'] !== 'DELETE') { // DELETE might not have a body
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input: ' . json_last_error_msg()]);
    exit;
}

$roomId = $input['room_id'] ?? null;
if (empty($roomId) || !is_numeric($roomId)) {
    // For DELETE, some might pass it in URL, but plan implies body. We'll stick to body for now.
    // If using URL params for DELETE: $roomId = $_GET['room_id'] ?? null;
    http_response_code(400);
    echo json_encode(['error' => 'room_id is required and must be numeric.']);
    exit;
}
$roomId = (int)$roomId;

// Check if room exists
$roomStmt = $pdo->prepare("SELECT id, name, is_private, created_by FROM chat_rooms WHERE id = :room_id");
$roomStmt->execute([':room_id' => $roomId]);
$room = $roomStmt->fetch();

if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'Room not found.']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') { // Join Room
    try {
        // Check if already a member
        $checkStmt = $pdo->prepare("SELECT 1 FROM room_members WHERE user_id = :user_id AND room_id = :room_id");
        $checkStmt->execute([':user_id' => $authenticatedUser['id'], ':room_id' => $roomId]);
        if ($checkStmt->fetch()) {
            http_response_code(200); // Or 409 Conflict, but 200 is fine as "already in desired state"
            echo json_encode(['success' => true, 'message' => 'User is already a member of this room.']);
            exit;
        }

        // Authorization: Can the user join this room?
        // Public rooms: anyone can join.
        // Private rooms: For now, only if they created it (implicitly handled by auto-join on creation)
        // or if we implement an invitation system later. The current SRS doesn't specify invite logic.
        // So, effectively, users can only "join" public rooms through this endpoint if not already a member.
        // If they created a private room, they are already a member.
        if ($room['is_private']) {
            // More complex logic would go here for private room invitations.
            // For now, if it's private and they are not the creator (and thus not auto-joined), deny.
            // This check is a bit redundant if creator is always auto-added.
            // But it makes explicit that random users cannot join arbitrary private rooms.
             if ($room['created_by'] != $authenticatedUser['id']) {
                 // Check if they are already a member (which they wouldn't be if previous check passed)
                 // This path is mostly for future extensibility (e.g. invites)
                 http_response_code(403);
                 echo json_encode(['error' => 'You do not have permission to join this private room.']);
                 exit;
             }
        }

        $stmt = $pdo->prepare("INSERT INTO room_members (user_id, room_id, joined_at) VALUES (:user_id, :room_id, NOW())");
        $stmt->execute([
            ':user_id' => $authenticatedUser['id'],
            ':room_id' => $roomId
        ]);

        http_response_code(200); // OK, as user successfully became a member (or already was)
        echo json_encode(['success' => true, 'message' => 'Successfully joined room.']);

    } catch (PDOException $e) {
        error_log("POST /room_members DB Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error while joining room.']);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') { // Leave Room
    try {
        // Users can leave any room they are a member of.
        // Room creators cannot be "removed" by this, they just leave their membership.
        // The room itself persists.
        $stmt = $pdo->prepare("DELETE FROM room_members WHERE user_id = :user_id AND room_id = :room_id");
        $stmt->execute([
            ':user_id' => $authenticatedUser['id'],
            ':room_id' => $roomId
        ]);

        if ($stmt->rowCount() > 0) {
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Successfully left room.']);
        } else {
            http_response_code(404); // Or 400 if "not a member" is considered client error
            echo json_encode(['error' => 'User is not a member of this room or room does not exist.']);
        }

    } catch (PDOException $e) {
        error_log("DELETE /room_members DB Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error while leaving room.']);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Only POST (join) and DELETE (leave) are supported.']);
}
