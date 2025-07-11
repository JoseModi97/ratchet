<?php
require_once __DIR__ . '/../database/db.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? null;
    $password = $data['password'] ?? null;

    if (empty($username) || empty($password)) {
        $response['message'] = 'Username and password are required.';
    } else {
        try {
            $pdo = get_db_connection();

            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->bindParam(':username', $username);
            $stmt->execute();

            if ($stmt->fetch()) {
                $response['message'] = 'Username already taken.';
            } else {
                // Hash password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Insert new user
                $insertStmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)");
                $insertStmt->bindParam(':username', $username);
                $insertStmt->bindParam(':password_hash', $password_hash);

                if ($insertStmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Registration successful. You can now login.';
                } else {
                    $response['message'] = 'Registration failed. Please try again.';
                }
            }
        } catch (PDOException $e) {
            // Log error $e->getMessage()
            $response['message'] = 'Database error during registration.';
        }
    }
} else {
    $response['message'] = 'Invalid request method.';
}

echo json_encode($response);
?>
