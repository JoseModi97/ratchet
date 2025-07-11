<?php
require_once __DIR__ . '/../database/db.php';

session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An error occurred.', 'messages' => []];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required. Please login.';
    http_response_code(401); // Unauthorized
    echo json_encode($response);
    exit;
}
$loggedInUserId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $roomId = filter_input(INPUT_GET, 'roomId', FILTER_VALIDATE_INT);
    $beforeMessageId = filter_input(INPUT_GET, 'before_message_id', FILTER_VALIDATE_INT); // Using INT, but BIGINT in DB
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 50, 'min_range' => 1, 'max_range' => 100]]);

    if (!$roomId) {
        $response['message'] = 'Valid Room ID is required.';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    try {
        $pdo = get_db_connection();

        // Verify user is a member of the room
        $stmtMember = $pdo->prepare("SELECT 1 FROM chat_room_members WHERE room_id = :room_id AND user_id = :user_id");
        $stmtMember->bindParam(':room_id', $roomId, PDO::PARAM_INT);
        $stmtMember->bindParam(':user_id', $loggedInUserId, PDO::PARAM_INT);
        $stmtMember->execute();
        if (!$stmtMember->fetchColumn()) {
            $response['message'] = 'Access denied. You are not a member of this room or the room does not exist.';
            http_response_code(403); // Forbidden
            echo json_encode($response);
            exit;
        }

        // Construct query for messages
        $sql = "
            SELECT cm.id, cm.room_id AS roomId, cm.user_id AS userId,
                   u.username AS senderUsername,
                   cm.message_type AS messageType, cm.content, cm.metadata,
                   DATE_FORMAT(cm.sent_at, '%Y-%m-%dT%H:%i:%sZ') as sentAt
            FROM chat_messages cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.room_id = :room_id
        ";

        // For system messages where user_id might point to the user who *caused* the event,
        // but we might want a generic "System" sender or handle it client-side.
        // For simplicity, current join will show the user who joined as sender.
        // A more advanced approach might use a different join for system messages or a dedicated system user ID.

        if ($beforeMessageId) {
            $sql .= " AND cm.id < :before_message_id ";
        }
        $sql .= " ORDER BY cm.id DESC LIMIT :limit"; // Fetch latest first (which are older than before_message_id)

        $stmtMessages = $pdo->prepare($sql);
        $stmtMessages->bindParam(':room_id', $roomId, PDO::PARAM_INT);
        if ($beforeMessageId) {
            // PDO might treat large integers from filter_input as strings, ensure it's int for binding if column is BIGINT
            // However, comparison should still work. PHP int max might be an issue for very large BIGINT IDs.
            // For now, assuming IDs fit within PHP_INT_MAX or string comparison is fine.
            $stmtMessages->bindParam(':before_message_id', $beforeMessageId, PDO::PARAM_INT);
        }
        $stmtMessages->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmtMessages->execute();

        $messages = $stmtMessages->fetchAll(PDO::FETCH_ASSOC);

        // Messages are fetched newest-first (within the page), reverse to get oldest-first for prepending
        $messages = array_reverse($messages);

        $response['success'] = true;
        $response['message'] = 'Messages fetched successfully.';
        $response['messages'] = array_map(function($msg) {
            $msg['id'] = (int)$msg['id']; // Ensure ID is int
            $msg['userId'] = (int)$msg['userId'];
            $msg['roomId'] = (int)$msg['roomId'];
            if ($msg['metadata']) {
                $decodedMetadata = json_decode($msg['metadata'], true);
                // Only assign if JSON is valid, otherwise keep as null or original string
                $msg['metadata'] = (json_last_error() === JSON_ERROR_NONE) ? $decodedMetadata : $msg['metadata'];
            }
            // For system messages, the 'content' is JSON. Let client parse it if needed.
            // If messageType is 'text', content is plain text.
            return $msg;
        }, $messages);
        http_response_code(200);

    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
        error_log("Error fetching room messages for room {$roomId}: " . $e->getMessage());
        http_response_code(500);
    }

} else {
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    http_response_code(405);
}

echo json_encode($response);
?>
