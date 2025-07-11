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
$password = $input['password']; // Password will be hashed, no need to trim whitespace from it specifically

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit;
}

// Password Hashing
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
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1");
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':email', $email);
    $stmt->execute();

    if ($stmt->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['status' => 'error', 'message' => 'Username or email already exists.']);
        exit;
    }

    // Insert new user
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)");
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':password_hash', $password_hash);

    if ($stmt->execute()) {
        http_response_code(201); // Created
        echo json_encode(['status' => 'success', 'message' => 'User registered successfully.']);
    } else {
        http_response_code(500); // Internal Server Error
        // Log detailed error information here in a real application
        echo json_encode(['status' => 'error', 'message' => 'Failed to register user. Database error.']);
    }

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error: $e->getMessage()
    echo json_encode(['status' => 'error', 'message' => 'Database connection error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error: $e->getMessage()
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
}
?>
