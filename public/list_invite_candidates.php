<?php
require_once __DIR__ . '/../database/db.php';

session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An error occurred.', 'users' => []];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required. Please login.';
    http_response_code(401); // Unauthorized
    echo json_encode($response);
    exit;
}

$loggedInUserId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $roomId = $_GET['roomId'] ?? null;

    if (empty($roomId) || !is_numeric($roomId)) {
        $response['message'] = 'Room ID is required and must be numeric.';
        http_response_code(400); // Bad Request
        echo json_encode($response);
        exit;
    }
    $roomId = (int)$roomId;

    try {
        $pdo = get_db_connection();

        // Verify logged-in user is the creator of the room
        $stmtRoom = $pdo->prepare("SELECT creator_user_id FROM chat_rooms WHERE id = :room_id");
        $stmtRoom->bindParam(':room_id', $roomId, PDO::PARAM_INT);
        $stmtRoom->execute();
        $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $response['message'] = 'Room not found.';
            http_response_code(404); // Not Found
            echo json_encode($response);
            exit;
        }

        if ((int)$room['creator_user_id'] !== $loggedInUserId) {
            $response['message'] = 'Only the room creator can see invite candidates.';
            http_response_code(403); // Forbidden
            echo json_encode($response);
            exit;
        }

        // Fetch users who are NOT in the current room AND are not the logged-in user (creator)
        $stmtUsers = $pdo->prepare("
            SELECT u.id as userId, u.username
            FROM users u
            WHERE u.id != :logged_in_user_id
            AND NOT EXISTS (
                SELECT 1
                FROM chat_room_members crm
                WHERE crm.room_id = :room_id AND crm.user_id = u.id
            )
            ORDER BY u.username ASC
        ");
        $stmtUsers->bindParam(':logged_in_user_id', $loggedInUserId, PDO::PARAM_INT);
        $stmtUsers->bindParam(':room_id', $roomId, PDO::PARAM_INT);
        $stmtUsers->execute();

        $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['message'] = 'Invite candidates fetched successfully.';
        $response['users'] = array_map(function($user) {
            $user['userId'] = (int)$user['userId'];
            return $user;
        }, $users);
        http_response_code(200); // OK

    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
        error_log("Error fetching invite candidates: " . $e->getMessage());
        http_response_code(500); // Internal Server Error
    }
} else {
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    http_response_code(405); // Method Not Allowed
}

echo json_encode($response);
?>
