<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

// Ensure no output before headers
if (ob_get_length())
    ob_clean();

header('Content-Type: application/json');

// Check if user is logged in and is a health worker
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'health_worker') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
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
    // Account is inactive, destroy session and return error
    session_destroy();
    http_response_code(401);
    echo json_encode(['error' => 'Account has been deactivated']);
    exit();
}

try {
    // Get form data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }

    // Validate required fields
    $required_fields = ['patient_id', 'scheduled_at', 'vaccine_id', 'dosage'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    $patient_id = (int) $data['patient_id'];
    $scheduled_at = $data['scheduled_at'];
    $notes = $data['notes'] ?? '';
    $vaccine_id = (int) $data['vaccine_id'];
    $dosage = $data['dosage'];
    $weight = isset($data['weight']) ? (float) $data['weight'] : null;
    $height = isset($data['height']) ? (float) $data['height'] : null;
    $status = 'scheduled';

    // Validate vaccine exists
    $stmt = $mysqli->prepare("SELECT id FROM vaccines WHERE id = ?");
    $stmt->bind_param('i', $vaccine_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        throw new Exception('Selected vaccine does not exist');
    }

    // Validate dosage format
    if (!preg_match('/^\d+(st|nd|rd|th) Dose$/', $dosage)) {
        throw new Exception('Invalid dosage format. Must be in format "1st Dose", "2nd Dose", etc.');
    }

    // Start transaction
    $mysqli->begin_transaction();

    // Insert new appointment
    $sql = "INSERT INTO appointments 
            (patient_id, health_worker_id, scheduled_at, status, notes, vaccine_id, dosage, weight, height, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param(
        'iisssisdd',
        $patient_id,
        $_SESSION['user_id'],
        $scheduled_at,
        $status,
        $notes,
        $vaccine_id,
        $dosage,
        $weight,
        $height
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to save appointment: ' . $stmt->error);
    }

    $appointment_id = $stmt->insert_id;

    // Fetch the complete appointment data
    $sql = "SELECT a.*, 
                   u.full_name AS patient_name, 
                   u.username AS patient_code, 
                   u.email, 
                   u.phone as contact_number,
                   p.child_name, 
                   p.birth_date, 
                   p.guardian_name, 
                   p.address,
                   v.name AS vaccine_name
            FROM appointments a
            INNER JOIN users u ON u.id = a.patient_id AND u.role = 'patient'
            INNER JOIN patient_profiles p ON p.user_id = u.id
            LEFT JOIN vaccines v ON v.id = a.vaccine_id
            WHERE a.id = ?";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $appointment_id);

    if (!$stmt->execute()) {
        throw new Exception('Failed to fetch appointment details');
    }

    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();

    // Commit transaction
    $mysqli->commit();

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Appointment scheduled successfully',
        'appointment' => $appointment
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($mysqli)) {
        $mysqli->rollback();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}