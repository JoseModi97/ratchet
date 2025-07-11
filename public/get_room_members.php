<?php
require_once __DIR__ . '/../database/db.php';

session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An error occurred.', 'members' => []];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required. Please login.';
    http_response_code(401); // Unauthorized
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $roomId = $_GET['roomId'] ?? null;
    $loggedInUserId = $_SESSION['user_id'];

    if (empty($roomId) || !is_numeric($roomId)) {
        $response['message'] = 'Room ID is required and must be numeric.';
        http_response_code(400); // Bad Request
        echo json_encode($response);
        exit;
    }
    $roomId = (int)$roomId;

    try {
        $pdo = get_db_connection();

        // Verify logged-in user is a member of the room to view members
        $stmtAccessCheck = $pdo->prepare("SELECT 1 FROM chat_room_members WHERE room_id = :room_id AND user_id = :user_id");
        $stmtAccessCheck->bindParam(':room_id', $roomId, PDO::PARAM_INT);
        $stmtAccessCheck->bindParam(':user_id', $loggedInUserId, PDO::PARAM_INT);
        $stmtAccessCheck->execute();

        if (!$stmtAccessCheck->fetch()) {
            $response['message'] = 'You are not a member of this room, or the room does not exist.';
            http_response_code(403); // Forbidden (or 404 if room not found is more appropriate)
            echo json_encode($response);
            exit;
        }

        // Fetch members of the room
        $stmtMembers = $pdo->prepare("
            SELECT u.id as userId, u.username
            FROM users u
            JOIN chat_room_members crm ON u.id = crm.user_id
            WHERE crm.room_id = :room_id
            ORDER BY u.username ASC
        ");
        $stmtMembers->bindParam(':room_id', $roomId, PDO::PARAM_INT);
        $stmtMembers->execute();

        $members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['message'] = 'Room members fetched successfully.';
        $response['members'] = array_map(function($member) {
            $member['userId'] = (int)$member['userId'];
            return $member;
        }, $members);
        http_response_code(200); // OK

    } catch (PDOException $e) {
        // Log error $e->getMessage()
        $response['message'] = 'Database error while fetching room members: ' . $e->getMessage();
        error_log("Error fetching room members: " . $e->getMessage());
        http_response_code(500); // Internal Server Error
    }
} else {
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    http_response_code(405); // Method Not Allowed
}

echo json_encode($response);
?>
