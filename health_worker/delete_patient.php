<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

// Check if user is logged in and has the right permissions
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$patient_id = $input['patient_id'] ?? null;

if (!$patient_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No patient ID provided']);
    exit();
}

// Start transaction
$mysqli->begin_transaction();

try {
    // Delete from vaccination records first (due to foreign key constraints)
    $stmt = $mysqli->prepare("DELETE FROM vaccination_records WHERE patient_id = ?");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $stmt->close();

    // Delete from appointments
    $stmt = $mysqli->prepare("DELETE FROM appointments WHERE patient_id = ?");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $stmt->close();

    // Delete from patient_profiles
    $stmt = $mysqli->prepare("DELETE FROM patient_profiles WHERE user_id = ?");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $stmt->close();

    // Finally, delete from users table
    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ? AND role = 'patient'");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception('No patient found with the specified ID');
    }

    $stmt->close();

    // Commit transaction
    $mysqli->commit();

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    // Rollback transaction on error
    $mysqli->rollback();

    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$mysqli->close();
?>