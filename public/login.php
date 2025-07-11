<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Db;

header('Content-Type: application/json');
// Allow POST requests from any origin (for development convenience)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization'); // Allow Authorization for future use if needed here

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

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input: ' . json_last_error_msg()]);
    exit;
}

$loginIdentifier = $input['username'] ?? ($input['email'] ?? null); // Allow login with username or email
$password = $input['password'] ?? null;

if (empty($loginIdentifier) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Username/email and password are required.']);
    exit;
}

try {
    $pdo = Db::getConnection();

    // Try to fetch user by username or email
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :login OR email = :login");
    $stmt->execute([':login' => $loginIdentifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401); // Unauthorized
        echo json_encode(['error' => 'Invalid credentials.']);
        exit;
    }

    // Generate session token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day')); // Token valid for 1 day

    // Store token and update last_seen
    $updateStmt = $pdo->prepare("UPDATE users SET session_token = :token, token_expires_at = :expires_at, last_seen = NOW() WHERE id = :user_id");
    $updateStmt->execute([
        ':token' => $token,
        ':expires_at' => $expiresAt,
        ':user_id' => $user['id']
    ]);

    // Update user presence (optional here, but good for consistency, WebSocket onOpen will also do this)
    $presenceStmt = $pdo->prepare("UPDATE user_presence SET status = 'online', last_active = NOW() WHERE user_id = :user_id");
    $presenceStmt->execute([':user_id' => $user['id']]);
    // If the user_presence record didn't exist for some reason, create it.
    if ($presenceStmt->rowCount() === 0) {
        $insertPresenceStmt = $pdo->prepare("INSERT INTO user_presence (user_id, status, last_active) VALUES (:user_id, 'online', NOW())");
        $insertPresenceStmt->execute([':user_id' => $user['id']]);
    }


    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'token' => $token,
        'user_id' => $user['id'],
        'username' => $user['username'],
        'expires_at' => $expiresAt
    ]);

} catch (PDOException $e) {
    error_log("Login DB Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error during login.']);
} catch (Exception $e) {
    error_log("Login General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
}
