<?php
// Autoload dependencies
require dirname(__DIR__) . '/vendor/autoload.php';

use MyApp\Database;

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Only POST is allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['login']) || empty($input['password'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Login identifier (username or email) and password are required.']);
    exit;
}

$loginIdentifier = trim($input['login']);
$password = $input['password'];

$db = new Database();
try {
    $pdo = $db->getConnection();

    // Query 1: Fetch user (Known to work)
    $sqlFetchUser = "SELECT id, username, password_hash FROM users WHERE username = :login OR email = :login LIMIT 1";
    $stmtFetchUser = $pdo->prepare($sqlFetchUser);
    $stmtFetchUser->execute([':login' => $loginIdentifier]);
    $user = $stmtFetchUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid login credentials. (User not found)']);
        exit;
    }

    if (password_verify($password, $user['password_hash'])) {
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + (86400 * 30));

        // Query 2: Insert session (Known to work)
        $sqlInsertSession = "INSERT INTO user_sessions (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)";
        $stmtInsertSession = $pdo->prepare($sqlInsertSession);
        $sessionData = [
            ':user_id' => $user['id'],
            ':token' => $token,
            ':expires_at' => $expires_at
        ];

        if ($stmtInsertSession->execute($sessionData)) {

            // Query 3: Update last_seen (Alternative: PHP generated timestamp)
            $phpTimestamp = date('Y-m-d H:i:s'); // Generate timestamp in PHP
            $sqlUpdateLastSeen = "UPDATE users SET last_seen = :last_seen_time WHERE id = :user_id";
            $stmtUpdateLastSeen = $pdo->prepare($sqlUpdateLastSeen);

            $updateLastSeenParams = [
                ':last_seen_time' => $phpTimestamp, // Bind PHP timestamp
                ':user_id' => $user['id']
            ];

            if ($stmtUpdateLastSeen->execute($updateLastSeenParams)) {
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
                http_response_code(500);
                echo json_encode([
                    'status' => 'error_update_last_seen_execute_false',
                    'message' => 'Failed to update last_seen (PHP time). Execute returned false.'
                ]);
            }
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error_session_insert_execute_false', 'message' => 'Failed to create session. Execute returned false.']);
        }
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid login credentials. (Password mismatch)']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error_pdo_exception',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $eGeneric) {
    http_response_code(500);
    echo json_encode(['status' => 'error_generic_exception', 'message' => 'Unexpected error: ' . $eGeneric->getMessage()]);
}
?>
