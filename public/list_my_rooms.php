<?php
require_once __DIR__ . '/../database/db.php';

session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An error occurred.', 'rooms' => []];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required. Please login.';
    http_response_code(401); // Unauthorized
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo = get_db_connection();
        $userId = $_SESSION['user_id'];

        // Fetch rooms the user is a member of
        // Fetches room id, name, and the username of the creator
        $stmt = $pdo->prepare("
            SELECT cr.id, cr.name, cr.creator_user_id, u.username as creator_username
            FROM chat_rooms cr
            JOIN chat_room_members crm ON cr.id = crm.room_id
            JOIN users u ON cr.creator_user_id = u.id
            WHERE crm.user_id = :user_id
            ORDER BY cr.name ASC
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['message'] = 'Rooms fetched successfully.';
        // Ensure id and creator_user_id are integers
        $response['rooms'] = array_map(function($room) {
            $room['id'] = (int)$room['id'];
            $room['creator_user_id'] = (int)$room['creator_user_id'];
            return $room;
        }, $rooms);
        http_response_code(200); // OK

    } catch (PDOException $e) {
        // Log error $e->getMessage()
        $response['message'] = 'Database error while fetching rooms: ' . $e->getMessage();
        error_log("Error fetching rooms: " . $e->getMessage());
        http_response_code(500); // Internal Server Error
    }
} else {
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    http_response_code(405); // Method Not Allowed
}

echo json_encode($response);
?>
