<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Db;

header('Content-Type: application/json');

// Allow POST requests from any origin (for development convenience)
// In production, restrict this to your frontend's origin
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

$username = $input['username'] ?? null;
$email = $input['email'] ?? null;
$password = $input['password'] ?? null;

if (empty($username) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Username, email, and password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email format.']);
    exit;
}

if (strlen($password) < 6) { // Basic password length validation
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 6 characters long.']);
    exit;
}

try {
    $pdo = Db::getConnection();

    // Check if username or email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
    $stmt->execute([':username' => $username, ':email' => $email]);
    if ($stmt->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['error' => 'Username or email already taken.']);
        exit;
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, created_at, last_seen) VALUES (:username, :email, :password_hash, NOW(), NOW())");
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $password_hash
    ]);

    $userId = $pdo->lastInsertId();

    // Also create a user_presence record
    $stmtPresence = $pdo->prepare("INSERT INTO user_presence (user_id, status, last_active) VALUES (:user_id, 'offline', NOW()) ON DUPLICATE KEY UPDATE status='offline', last_active=NOW()");
    $stmtPresence->execute([':user_id' => $userId]);


    http_response_code(201); // Created
    echo json_encode(['success' => true, 'message' => 'User registered successfully.', 'user_id' => $userId]);

} catch (PDOException $e) {
    error_log("Registration Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error during registration.']);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
}
