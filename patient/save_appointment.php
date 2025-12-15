<?php
// patient/save_appointment.php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Ensure user is logged in and is a patient
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if logged-in user's account is still active
$checkStmt = $mysqli->prepare("SELECT status FROM users WHERE id = ?");
$checkStmt->bind_param('i', $_SESSION['user_id']);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$userStatus = $checkResult->fetch_assoc();
$checkStmt->close();

if (!$userStatus || strtolower($userStatus['status'] ?? '') !== 'active') {
    // Account is inactive, destroy session and return error
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Account has been deactivated']);
    exit();
}

$patient_id = (int) $_SESSION['user_id'];
$vaccine_id = $_POST['vaccine_id'] ?? '';
$preferred_date = $_POST['preferred_date'] ?? '';
$preferred_time = $_POST['preferred_time'] ?? '';
$notes = $_POST['notes'] ?? '';

// Validate input
$errors = [];

if (empty($vaccine_id)) {
    $errors[] = "Please select a vaccine";
}

if (empty($preferred_date)) {
    $errors[] = "Please select a preferred date";
} elseif (strtotime($preferred_date) < strtotime('today')) {
    $errors[] = "Appointment date cannot be in the past";
}

if (empty($preferred_time)) {
    $errors[] = "Please select a preferred time";
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
    exit;
}

try {
    $scheduled_at = date('Y-m-d H:i:s', strtotime("$preferred_date $preferred_time"));
    $status = 'scheduled';

    $stmt = $mysqli->prepare("
        INSERT INTO appointments 
        (patient_id, scheduled_at, status, notes) 
        VALUES (?, ?, ?, ?)
    ");

    if ($stmt) {
        $stmt->bind_param('isss', $patient_id, $scheduled_at, $status, $notes);
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Your appointment has been scheduled successfully!'
            ]);
        } else {
            throw new Exception("Database error: " . $stmt->error);
        }
        $stmt->close();
    } else {
        throw new Exception("Database error: " . $mysqli->error);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while scheduling your appointment. Please try again.'
    ]);
    error_log('Appointment scheduling error: ' . $e->getMessage());
}