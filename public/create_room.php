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
    $roomName = $data['roomName'] ?? null;

    if (empty($roomName)) {
        $response['message'] = 'Room name is required.';
        http_response_code(400); // Bad Request
    } elseif (strlen($roomName) > 255) {
        $response['message'] = 'Room name is too long (max 255 characters).';
        http_response_code(400);
    } else {
        try {
            $pdo = get_db_connection();
            $creatorUserId = $_SESSION['user_id'];

            // Check if room name already exists
            $stmt = $pdo->prepare("SELECT id FROM chat_rooms WHERE name = :name");
            $stmt->bindParam(':name', $roomName);
            $stmt->execute();
            if ($stmt->fetch()) {
                $response['message'] = 'A room with this name already exists.';
                http_response_code(409); // Conflict
            } else {
                $pdo->beginTransaction();

                // Insert new room
                $insertRoomStmt = $pdo->prepare("INSERT INTO chat_rooms (name, creator_user_id) VALUES (:name, :creator_user_id)");
                $insertRoomStmt->bindParam(':name', $roomName);
                $insertRoomStmt->bindParam(':creator_user_id', $creatorUserId, PDO::PARAM_INT);
                $insertRoomStmt->execute();
                $newRoomId = $pdo->lastInsertId();

                // Add creator to chat_room_members
                $insertMemberStmt = $pdo->prepare("INSERT INTO chat_room_members (room_id, user_id) VALUES (:room_id, :user_id)");
                $insertMemberStmt->bindParam(':room_id', $newRoomId, PDO::PARAM_INT);
                $insertMemberStmt->bindParam(':user_id', $creatorUserId, PDO::PARAM_INT);
                $insertMemberStmt->execute();

                $pdo->commit();

                $response['success'] = true;
                $response['message'] = "Room '{$roomName}' created successfully.";
                $response['roomId'] = (int)$newRoomId;
                $response['roomName'] = $roomName;
                http_response_code(201); // Created
            }
        } catch (PDOException $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Log error $e->getMessage()
            $response['message'] = 'Database error during room creation: ' . $e->getMessage();
            error_log("Error creating room: " . $e->getMessage());
            http_response_code(500); // Internal Server Error
        }
    }
} else {
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    http_response_code(405); // Method Not Allowed
}

echo json_encode($response);
?>
