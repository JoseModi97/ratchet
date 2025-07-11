<?php
require_once __DIR__ . '/../database/db.php';

session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An error occurred.'];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required. Please login.';
    http_response_code(401); // Unauthorized
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') { // Using POST for removal actions
    $data = json_decode(file_get_contents('php://input'), true);
    $roomId = $data['roomId'] ?? null;
    $targetUserIdToRemove = $data['targetUserIdToRemove'] ?? null;
    $loggedInUserId = $_SESSION['user_id'];

    if (empty($roomId) || !is_numeric($roomId) || empty($targetUserIdToRemove) || !is_numeric($targetUserIdToRemove)) {
        $response['message'] = 'Room ID and Target User ID are required and must be numeric.';
        http_response_code(400); // Bad Request
        echo json_encode($response);
        exit;
    }
    $roomId = (int)$roomId;
    $targetUserIdToRemove = (int)$targetUserIdToRemove;

    if ($loggedInUserId === $targetUserIdToRemove) {
        $response['message'] = 'You cannot remove yourself from the room via this action.';
        http_response_code(400); // Bad Request
        echo json_encode($response);
        exit;
    }

    try {
        $pdo = get_db_connection();

        // Verify logged-in user is the creator of the room
        $stmt = $pdo->prepare("SELECT creator_user_id FROM chat_rooms WHERE id = :room_id");
        $stmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->execute();
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $response['message'] = 'Room not found.';
            http_response_code(404); // Not Found
        } elseif ((int)$room['creator_user_id'] !== $loggedInUserId) {
            $response['message'] = 'Only the room creator can remove users.';
            http_response_code(403); // Forbidden
        } else {
            // Check if target user is actually a member
            $stmtMemberCheck = $pdo->prepare("SELECT 1 FROM chat_room_members WHERE room_id = :room_id AND user_id = :user_id");
            $stmtMemberCheck->bindParam(':room_id', $roomId, PDO::PARAM_INT);
            $stmtMemberCheck->bindParam(':user_id', $targetUserIdToRemove, PDO::PARAM_INT);
            $stmtMemberCheck->execute();

            if (!$stmtMemberCheck->fetch()) {
                $response['message'] = "User ID {$targetUserIdToRemove} is not a member of this room.";
                http_response_code(404); // Not Found for the member
            } else {
                // Remove target user from chat_room_members
                $deleteMemberStmt = $pdo->prepare("DELETE FROM chat_room_members WHERE room_id = :room_id AND user_id = :user_id");
                $deleteMemberStmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
                $deleteMemberStmt->bindParam(':user_id', $targetUserIdToRemove, PDO::PARAM_INT);

                if ($deleteMemberStmt->execute() && $deleteMemberStmt->rowCount() > 0) {
                    $response['success'] = true;
                    $response['message'] = "User ID {$targetUserIdToRemove} removed from the room successfully.";
                    http_response_code(200); // OK
                } else {
                    // This case might happen if the user was already removed in a race condition, or ID was wrong but passed previous check.
                    $response['message'] = "Failed to remove user ID {$targetUserIdToRemove}. User might have already been removed or not found.";
                    http_response_code(404); // Or 500 if it implies a server state issue
                }
            }
        }
    } catch (PDOException $e) {
        // Log error $e->getMessage()
        $response['message'] = 'Database error during user removal: ' . $e->getMessage();
        error_log("Error removing from room: " . $e->getMessage());
        http_response_code(500); // Internal Server Error
    }
} else {
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    http_response_code(405); // Method Not Allowed
}

echo json_encode($response);
?>
