<?php
require_once __DIR__ . '/../../config/session.php';
$mysqli = require __DIR__ . '/../../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check if user has the correct role (health_worker or admin)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// Get patient ID from POST request
if (!isset($_POST['patient_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing patient_id']);
    exit();
}

$patient_id = (int) $_POST['patient_id'];

if ($patient_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid patient_id']);
    exit();
}

// Verify the patient exists and is a patient user
$stmt = $mysqli->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'patient'");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $mysqli->error]);
    exit();
}

$stmt->bind_param('i', $patient_id);
$stmt->execute();
$result = $stmt->get_result();
$patient = $result->fetch_assoc();
$stmt->close();

if (!$patient) {
    http_response_code(404);
    echo json_encode(['error' => 'Patient not found']);
    exit();
}

// Start transaction to ensure data consistency
$mysqli->begin_transaction();

try {
    // Delete vaccination records
    $stmt = $mysqli->prepare("DELETE FROM vaccination_records WHERE patient_id = ?");
    $stmt->bind_param('i', $patient_id);
    if (!$stmt->execute()) {
        throw new Exception("Error deleting vaccination records: " . $stmt->error);
    }
    $stmt->close();

    // Delete appointments
    $stmt = $mysqli->prepare("DELETE FROM appointments WHERE patient_id = ?");
    $stmt->bind_param('i', $patient_id);
    if (!$stmt->execute()) {
        throw new Exception("Error deleting appointments: " . $stmt->error);
    }
    $stmt->close();

    // Delete patient profile
    $stmt = $mysqli->prepare("DELETE FROM patient_profiles WHERE user_id = ?");
    $stmt->bind_param('i', $patient_id);
    if (!$stmt->execute()) {
        throw new Exception("Error deleting patient profile: " . $stmt->error);
    }
    $stmt->close();

    // Delete user account
    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param('i', $patient_id);
    if (!$stmt->execute()) {
        throw new Exception("Error deleting user account: " . $stmt->error);
    }
    $stmt->close();

    // Commit the transaction
    $mysqli->commit();

    // Return success
    echo 'success';
    exit();

} catch (Exception $e) {
    // Rollback on error
    $mysqli->rollback();

    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete patient: ' . $e->getMessage()]);
    exit();
}
?>