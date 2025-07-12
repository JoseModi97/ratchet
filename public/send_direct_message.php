<?php
require_once __DIR__ . '/../database/db.php'; // For get_db_connection()

session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'User not authenticated.';
    echo json_encode($response);
    exit;
}

$senderId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $receiverId = $data['receiver_id'] ?? null;
    $content = $data['content'] ?? null;

    if (empty($receiverId) || !is_numeric($receiverId)) {
        $response['message'] = 'Receiver ID is required and must be numeric.';
        echo json_encode($response);
        exit;
    }

    if (empty($content)) {
        $response['message'] = 'Message content cannot be empty.';
        echo json_encode($response);
        exit;
    }

    if ($senderId == $receiverId) {
        $response['message'] = 'Cannot send a message to yourself.';
        echo json_encode($response);
        exit;
    }

    try {
        $pdo = get_db_connection();

        // Check if receiver exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :receiver_id");
        $stmt->bindParam(':receiver_id', $receiverId, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetch()) {
            $response['message'] = 'Receiver user not found.';
            echo json_encode($response);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO direct_messages (sender_id, receiver_id, content) VALUES (:sender_id, :receiver_id, :content)");
        $stmt->bindParam(':sender_id', $senderId, PDO::PARAM_INT);
        $stmt->bindParam(':receiver_id', $receiverId, PDO::PARAM_INT);
        $stmt->bindParam(':content', $content, PDO::PARAM_STR);

        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Message sent successfully.';
            $response['message_id'] = $pdo->lastInsertId();
        } else {
            $response['message'] = 'Failed to send message.';
        }

    } catch (PDOException $e) {
        // Log error $e->getMessage()
        $response['message'] = 'Database error while sending message.';
        error_log("Database error in send_direct_message.php: " . $e->getMessage());
    }

} else {
    $response['message'] = 'Invalid request method.';
}

echo json_encode($response);
?>
