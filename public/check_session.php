<?php
session_start();
header('Content-Type: application/json');

if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    echo json_encode([
        'loggedIn' => true,
        'username' => $_SESSION['username'],
        'userId' => (int)$_SESSION['user_id'] // Add userId to the response
    ]);
} else {
    echo json_encode(['loggedIn' => false]);
}
?>
