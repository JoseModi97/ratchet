<?php
require_once __DIR__ . '/../database/db.php'; // For get_db_connection()

session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.', 'users' => []];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'User not authenticated.';
    echo json_encode($response);
    exit;
}

$currentUserId = $_SESSION['user_id'];

try {
    $pdo = get_db_connection();
    // Select users, excluding the current user
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id != :current_user_id ORDER BY username ASC");
    $stmt->bindParam(':current_user_id', $currentUserId, PDO::PARAM_INT);
    $stmt->execute();

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($users) {
        $response['success'] = true;
        $response['users'] = $users;
        $response['message'] = 'Users fetched successfully.';
    } else {
        $response['success'] = true; // Still a success, just no other users
        $response['users'] = [];
        $response['message'] = 'No other users found.';
    }

} catch (PDOException $e) {
    // Log error $e->getMessage()
    $response['message'] = 'Database error while fetching users.';
    error_log("Database error in list_users.php: " . $e->getMessage());
}

echo json_encode($response);
?>
