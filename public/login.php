<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Db;

header('Content-Type: application/json');
// Allow POST requests from a specific origin in production
header('Access-Control-Allow-Origin: https://your-chat-app-domain.com'); // TODO: Replace with your actual frontend domain
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

// --- Conceptual Rate Limiting Placeholder ---
// In a production environment, implement robust rate limiting here.
// Example using a session or a more advanced method (e.g., Redis, Memcached, Fail2ban integration):
// session_start(); // If using sessions
// $ipAddress = $_SERVER['REMOTE_ADDR'];
// $maxRequests = 10; // Max requests
// $timeWindow = 60;  // Seconds (e.g., 10 requests per minute)
//
// // Example: $_SESSION['login_attempts'][$ipAddress] = ['count' => X, 'timestamp' => Y];
// // if (isset($_SESSION['login_attempts'][$ipAddress]) &&
// //     $_SESSION['login_attempts'][$ipAddress]['timestamp'] > (time() - $timeWindow) &&
// //     $_SESSION['login_attempts'][$ipAddress]['count'] >= $maxRequests) {
// //     http_response_code(429); // Too Many Requests
// //     echo json_encode(['error' => 'Too many login attempts. Please try again later.']);
// //     exit;
// // }
// // // Update attempt counter
// // if (!isset($_SESSION['login_attempts'][$ipAddress]) || $_SESSION['login_attempts'][$ipAddress]['timestamp'] < (time() - $timeWindow)) {
// //     $_SESSION['login_attempts'][$ipAddress] = ['count' => 1, 'timestamp' => time()];
// // } else {
// //     $_SESSION['login_attempts'][$ipAddress]['count']++;
// // }
// --- End Conceptual Rate Limiting Placeholder ---

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
