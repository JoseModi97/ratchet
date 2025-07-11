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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $roomId = $data['roomId'] ?? null;
    $targetUsername = $data['targetUsername'] ?? null;
    $loggedInUserId = $_SESSION['user_id'];

    if (empty($roomId) || !is_numeric($roomId) || empty($targetUsername)) {
        $response['message'] = 'Room ID and target username are required.';
        http_response_code(400); // Bad Request
        echo json_encode($response);
        exit;
    }
    $roomId = (int)$roomId;

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
            $response['message'] = 'Only the room creator can invite users.';
            http_response_code(403); // Forbidden
        } else {
            // Find target user ID
            $stmtUser = $pdo->prepare("SELECT id FROM users WHERE username = :username");
            $stmtUser->bindParam(':username', $targetUsername);
            $stmtUser->execute();
            $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $response['message'] = "User '{$targetUsername}' not found.";
                http_response_code(404);
            } else {
                $targetUserId = (int)$targetUser['id'];

                // Check if target user is already a member
                $stmtMemberCheck = $pdo->prepare("SELECT 1 FROM chat_room_members WHERE room_id = :room_id AND user_id = :user_id");
                $stmtMemberCheck->bindParam(':room_id', $roomId, PDO::PARAM_INT);
                $stmtMemberCheck->bindParam(':user_id', $targetUserId, PDO::PARAM_INT);
                $stmtMemberCheck->execute();

                if ($stmtMemberCheck->fetch()) {
                    $response['message'] = "User '{$targetUsername}' is already a member of this room.";
                    http_response_code(409); // Conflict
                } else {
                    // Add target user to chat_room_members
                    $insertMemberStmt = $pdo->prepare("INSERT INTO chat_room_members (room_id, user_id) VALUES (:room_id, :user_id)");
                    $insertMemberStmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
                    $insertMemberStmt->bindParam(':user_id', $targetUserId, PDO::PARAM_INT);

                    if ($insertMemberStmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = "User '{$targetUsername}' invited to the room successfully.";
                        http_response_code(200); // OK
                    } else {
                        $response['message'] = "Failed to invite user '{$targetUsername}'.";
                        http_response_code(500);
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // Log error $e->getMessage()
        $response['message'] = 'Database error during invitation: ' . $e->getMessage();
        error_log("Error inviting to room: " . $e->getMessage());
        http_response_code(500); // Internal Server Error
    }
} else {
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    http_response_code(405); // Method Not Allowed
}

echo json_encode($response);
?>
