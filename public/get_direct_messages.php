<?php
require_once __DIR__ . '/../database/db.php'; // For get_db_connection()

session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.', 'messages' => []];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'User not authenticated.';
    echo json_encode($response);
    exit;
}

$currentUserId = $_SESSION['user_id'];
$contactUserId = $_GET['contact_user_id'] ?? null; // Get from query parameter

if (empty($contactUserId) || !is_numeric($contactUserId)) {
    $response['message'] = 'Contact User ID is required and must be numeric.';
    echo json_encode($response);
    exit;
}

if ($currentUserId == $contactUserId) {
    $response['message'] = 'Cannot fetch messages with yourself using this endpoint for direct messages.';
    // Technically, a user could message themselves, but UI might prevent this.
    // For now, this restriction seems reasonable for a typical chat.
    echo json_encode($response);
    exit;
}

// Pagination parameters (optional)
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 20;
$before_message_id = isset($_GET['before_message_id']) && is_numeric($_GET['before_message_id']) ? (int)$_GET['before_message_id'] : null;


try {
    $pdo = get_db_connection();

    // Mark messages from the contact as read by the current user
    // This should ideally happen when messages are fetched or seen
    $stmtUpdateRead = $pdo->prepare(
        "UPDATE direct_messages
         SET read_at = CURRENT_TIMESTAMP
         WHERE receiver_id = :current_user_id
           AND sender_id = :contact_user_id
           AND read_at IS NULL"
    );
    $stmtUpdateRead->bindParam(':current_user_id', $currentUserId, PDO::PARAM_INT);
    $stmtUpdateRead->bindParam(':contact_user_id', $contactUserId, PDO::PARAM_INT);
    $stmtUpdateRead->execute();


    $query = "SELECT dm.id, dm.sender_id, u_sender.username AS sender_username,
                     dm.receiver_id, u_receiver.username AS receiver_username,
                     dm.content, dm.sent_at, dm.read_at
              FROM direct_messages dm
              JOIN users u_sender ON dm.sender_id = u_sender.id
              JOIN users u_receiver ON dm.receiver_id = u_receiver.id
              WHERE (dm.sender_id = :current_user_id AND dm.receiver_id = :contact_user_id)
                 OR (dm.sender_id = :contact_user_id AND dm.receiver_id = :current_user_id)";

    if ($before_message_id) {
        $query .= " AND dm.id < :before_message_id";
    }

    $query .= " ORDER BY dm.sent_at DESC, dm.id DESC LIMIT :limit";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':current_user_id', $currentUserId, PDO::PARAM_INT);
    $stmt->bindParam(':contact_user_id', $contactUserId, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);

    if ($before_message_id) {
        $stmt->bindParam(':before_message_id', $before_message_id, PDO::PARAM_INT);
    }

    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Messages are fetched in DESC order for pagination, reverse them for display
    $messages = array_reverse($messages);

    $response['success'] = true;
    $response['messages'] = $messages;
    $response['message'] = 'Direct messages fetched successfully.';

} catch (PDOException $e) {
    // Log error $e->getMessage()
    $response['message'] = 'Database error while fetching direct messages.';
    error_log("Database error in get_direct_messages.php: " . $e->getMessage());
}

echo json_encode($response);
?>
