<?php
// Autoload dependencies
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Database;

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Only POST is allowed.']);
    exit;
}

// Get raw POST data
$input = json_decode(file_get_contents('php://input'), true);

// Basic Input Validation
if (empty($input['username']) || empty($input['email']) || empty($input['password'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Username, email, and password are required.']);
    exit;
}

$username = trim($input['username']);
$email = trim($input['email']);
$password = $input['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit;
}

$password_hash = password_hash($password, PASSWORD_BCRYPT);
if ($password_hash === false) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Failed to hash password.']);
    exit;
}

$db = new Database();
try {
    $pdo = $db->getConnection();

    // Check if username or email already exists
    $sqlCheck = "SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1";
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([':username' => $username, ':email' => $email]);

    if ($stmtCheck->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['status' => 'error', 'message' => 'Username or email already exists.']);
        exit;
    }

    // Insert new user
    $sqlInsert = "INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)";
    $stmtInsert = $pdo->prepare($sqlInsert);

    $insertParams = [
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $password_hash
    ];

    if ($stmtInsert->execute($insertParams)) {
        http_response_code(201); // Created
        echo json_encode(['status' => 'success', 'message' => 'User registered successfully.']);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['status' => 'error', 'message' => 'Failed to register user. Database error during insert execute.']);
    }

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Database operation failed: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
}
?>
