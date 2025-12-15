<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Please log in']);
    exit();
}

// Check if user has the correct role (health_worker or admin)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden - Only health workers and admins can update credentials']);
    exit();
}

// Check if logged-in user's account is still active
$checkStmt = $mysqli->prepare("SELECT status FROM users WHERE id = ?");
$checkStmt->bind_param('i', $_SESSION['user_id']);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$userStatus = $checkResult->fetch_assoc();
$checkStmt->close();

if (!$userStatus || strtolower($userStatus['status'] ?? '') !== 'active') {
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Account has been deactivated']);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

$patientId = isset($data['patient_id']) ? (int) $data['patient_id'] : 0;
$username = trim($data['username'] ?? '');
$email = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

// Validation
if ($patientId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid patient ID']);
    exit();
}

if (empty($username) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email format']);
    exit();
}

// Validate password length
if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters long']);
    exit();
}

try {
    // Start transaction
    $mysqli->begin_transaction();
    
    // Check if patient exists and is a patient
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$patient) {
        $mysqli->rollback();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Patient not found']);
        exit();
    }
    
    // Check if username already exists for another user
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
    $stmt->bind_param('si', $username, $patientId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $mysqli->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username already exists']);
        exit();
    }
    $stmt->close();
    
    // Check if email already exists for another user
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $stmt->bind_param('si', $email, $patientId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $mysqli->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email already exists']);
        exit();
    }
    $stmt->close();
    
    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Update user credentials
    $stmt = $mysqli->prepare("UPDATE users SET username = ?, email = ?, password_hash = ? WHERE id = ?");
    $stmt->bind_param('sssi', $username, $email, $passwordHash, $patientId);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update credentials: ' . $stmt->error);
    }
    $stmt->close();
    
    // Commit transaction
    $mysqli->commit();
    
    // Return updated credentials (including plain password for display purposes)
    echo json_encode([
        'success' => true,
        'message' => 'Credentials updated successfully',
        'credentials' => [
            'username' => $username,
            'email' => $email,
            'password' => $password // Return plain password for display (only in response, not stored)
        ]
    ]);
    
} catch (Exception $e) {
    if ($mysqli->in_transaction) {
        $mysqli->rollback();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update credentials: ' . $e->getMessage()]);
    error_log('Error in update_credentials.php: ' . $e->getMessage());
}
?>

