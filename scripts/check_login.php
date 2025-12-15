<?php
// scripts/check_login.php
// Usage: php scripts/check_login.php username password
// This uses the same logic as auth/login.php to help debug credential issues.

// Check if running from command line
if (php_sapi_name() === 'cli') {
    // Command line mode
    if ($argc < 3) {
        echo "Usage: php scripts/check_login.php <username_or_email> <password>\n";
        exit(1);
    }
    $username = $argv[1];
    $password = $argv[2];
} else {
    // Web mode - for testing via browser
    if (!isset($_GET['username']) || !isset($_GET['password'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing username or password']);
        exit(1);
    }
    $username = $_GET['username'];
    $password = $_GET['password'];
}

$mysqli = require __DIR__ . '/../config/db.php';

$stmt = $mysqli->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ? OR email = ? LIMIT 1');
if (!$stmt) {
    $response = ['error' => "Prepare failed: " . $mysqli->error];
    outputResponse($response, 500);
}

$stmt->bind_param('ss', $username, $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    $response = ['error' => "No user found for: {$username}"];
    outputResponse($response, 404);
}

$response = [
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role']
    ],
    'stored_hash' => substr($user['password_hash'], 0, 60) . '...'
];

if (password_verify($password, $user['password_hash'])) {
    $response['status'] = 'success';
    $response['message'] = 'Password OK';
    outputResponse($response);
} else {
    $response['status'] = 'error';
    $response['message'] = 'Password mismatch';
    outputResponse($response, 401);
}

function outputResponse($data, $statusCode = 200)
{
    if (php_sapi_name() === 'cli') {
        // CLI output
        if (isset($data['error'])) {
            echo "Error: " . $data['error'] . "\n";
        } else {
            echo "User found:\n";
            echo "ID: " . $data['user']['id'] . "\n";
            echo "Username: " . $data['user']['username'] . "\n";
            echo "Role: " . $data['user']['role'] . "\n";
            echo "Stored hash: " . $data['stored_hash'] . "\n";
            echo "Status: " . $data['message'] . "\n";
        }
        exit($statusCode === 200 ? 0 : 1);
    } else {
        // Web output
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}