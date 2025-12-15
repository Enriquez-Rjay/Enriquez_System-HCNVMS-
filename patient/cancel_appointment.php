<?php
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

// Get the appointment ID from the request
$appointment_id = $_POST['appointment_id'] ?? null;
$patient_id = (int) $_SESSION['user_id'];

if (!$appointment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Appointment ID is required']);
    exit;
}

try {
    // First, verify that the appointment belongs to the patient
    $stmt = $mysqli->prepare("SELECT id FROM appointments WHERE id = ? AND patient_id = ?");
    if (!$stmt) {
        throw new Exception("Database error: " . $mysqli->error);
    }

    $stmt->bind_param('ii', $appointment_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment not found or access denied']);
        exit;
    }
    $stmt->close();

    // Update the appointment status to 'cancelled'
    $stmt = $mysqli->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database error: " . $mysqli->error);
    }

    $stmt->bind_param('i', $appointment_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Appointment cancelled successfully']);
    } else {
        throw new Exception("Failed to cancel appointment");
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
