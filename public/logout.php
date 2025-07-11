<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Db;

header('Content-Type: application/json');
// Replace with your actual frontend domain in production
header('Access-Control-Allow-Origin: https://your-chat-app-domain.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Handle preflight request for CORS
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Only POST method is allowed.']);
    exit;
}

$pdo = Db::getConnection();

// Get the token from the Authorization header
$token = null;
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    }
}

if (!$token) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Authentication token not provided.']);
    exit;
}

try {
    // Find the user by the current session token
    // We don't need to check token_expires_at here, as even an expired token should be invalidated if found.
    // Or, more strictly, only invalidate active tokens. For simplicity, we'll invalidate if found.
    $stmt = $pdo->prepare("SELECT id FROM users WHERE session_token = :token");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    if ($user) {
        // Invalidate the token by nullifying session_token and its expiry
        $updateStmt = $pdo->prepare("UPDATE users SET session_token = NULL, token_expires_at = NULL WHERE id = :user_id");
        $updateStmt->execute([':user_id' => $user['id']]);

        // Optionally, update user presence to offline, though WebSocket onClose should also handle this
        // $presenceStmt = $pdo->prepare("UPDATE user_presence SET status = 'offline', last_active = NOW() WHERE user_id = :user_id");
        // $presenceStmt->execute([':user_id' => $user['id']]);

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Logout successful. Token invalidated.']);
    } else {
        // Token not found, or already invalidated.
        // Still, client will proceed with local logout, so a success response might be less confusing.
        // Or a specific message:
        http_response_code(200); // Or 404 if you want to signify token not found
        echo json_encode(['success' => true, 'message' => 'Token not found or already invalidated. Client should proceed with logout.']);
    }

} catch (PDOException $e) {
    error_log("Logout DB Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error during logout.']);
} catch (Exception $e) {
    error_log("Logout General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred during logout.']);
}
?>
