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
if (empty($input['login']) || empty($input['password'])) { // 'login' can be username or email
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Login identifier (username or email) and password are required.']);
    exit;
}

$loginIdentifier = trim($input['login']);
$password = $input['password'];

$db = new Database();
try {
    $pdo = $db->getConnection();

    // Fetch user by username or email
    // This query was confirmed to be working correctly with execute($array).
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :login OR email = :login LIMIT 1");
    $stmt->execute([':login' => $loginIdentifier]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401); // Unauthorized
        echo json_encode(['status' => 'error', 'message' => 'Invalid login credentials. (User not found)']);
        exit;
    }

    // Verify password
    if (password_verify($password, $user['password_hash'])) {
        // Password is correct, generate a session token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + (86400 * 30)); // Token valid for 30 days

        // Store the session token in the database
        // This query was confirmed to be working correctly with execute($array).
        $sessionStmt = $pdo->prepare("INSERT INTO user_sessions (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)");

        $sessionData = [
            ':user_id' => $user['id'],
            ':token' => $token,
            ':expires_at' => $expires_at
        ];

        if ($sessionStmt->execute($sessionData)) {
            // Update last_seen for the user
            // This is the suspected query. Re-typed carefully.
            // SQL string:
            $updateSql = "UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = :user_id";
            $updateLastSeenStmt = $pdo->prepare($updateSql);

            // Parameter array key:
            $updateParams = [':user_id' => $user['id']];

            // Execute the carefully re-typed query and parameters
            if ($updateLastSeenStmt->execute($updateParams)) {
                http_response_code(200); // OK
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Login successful.',
                    'token' => $token,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username']
                    ]
                ]);
            } else {
                // This specific else might be hard to reach if execute() throws the HY093
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to update last_seen. Execute returned false.']);
            }
        } else {
            // This specific else might be hard to reach if execute() throws the HY093
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to create session. Execute returned false.']);
        }
    } else {
        http_response_code(401); // Unauthorized
        echo json_encode(['status' => 'error', 'message' => 'Invalid login credentials. (Password mismatch)']);
    }

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error: $e->getMessage()
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection error: ' . $e->getMessage(),
        // 'pdo_trace' => $e->getTraceAsString() // Optionally include trace for HY093
    ]);
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error: $e->getMessage()
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
}
?>
