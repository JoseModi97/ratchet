<?php
session_start();
require_once __DIR__ . '/../database/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$currentUserId = $_SESSION['user_id'];
$pdon = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $receiverId = $data['receiver_id'] ?? null;
    $message = $data['message'] ?? null;

    if (!$receiverId || !$message) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing receiver_id or message']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO direct_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$currentUserId, $receiverId, $message]);
        echo json_encode(['success' => true, 'message' => 'Message sent']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $receiverId = $_GET['receiver_id'] ?? null;

    if (!$receiverId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing receiver_id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT dm.*, u.username as sender_username
             FROM direct_messages dm
             JOIN users u ON u.id = dm.sender_id
             WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
             ORDER BY sent_at ASC"
        );
        $stmt->execute([$currentUserId, $receiverId, $receiverId, $currentUserId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'messages' => $messages]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>
