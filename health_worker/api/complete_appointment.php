<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in and is a health worker or admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get the appointment ID from the request
$appointmentId = $_POST['appointment_id'] ?? null;

if (!$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'No appointment ID provided']);
    exit();
}

try {
    // Start transaction
    $mysqli->begin_transaction();

    // 1. Get appointment details (exclude health workers)
    $stmt = $mysqli->prepare("
        SELECT a.*, u.full_name as patient_name, u.id as patient_id, p.child_name, v.name as vaccine_name
        FROM appointments a
        INNER JOIN users u ON a.patient_id = u.id AND u.role = 'patient'
        INNER JOIN patient_profiles p ON p.user_id = u.id
        LEFT JOIN vaccines v ON a.vaccine_id = v.id
        WHERE a.id = ?
    ");
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        throw new Exception('Appointment not found');
    }

    // 2. Update the appointment status to 'completed'
    $stmt = $mysqli->prepare("
        UPDATE appointments 
        SET status = 'completed', 
            completed_at = NOW() 
        WHERE id = ? AND status != 'completed'
    ");
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception('Appointment not found or already completed');
    }
    $stmt->close();

    // 3. Create a report entry
    $patientName = $appointment['patient_name'] ?? $appointment['child_name'] ?? 'Unknown';
    $vaccineName = $appointment['vaccine_name'] ?? 'General Checkup';

    $stmt = $mysqli->prepare("
        INSERT INTO reports (
            patient_id, 
            patient_name, 
            report_type, 
            status, 
            appointment_id, 
            vaccine_id,
            notes,
            created_by,
            created_at
        ) VALUES (?, ?, 'vaccination', 'completed', ?, ?, ?, ?, NOW())
    ");

    $notes = "Vaccination: " . $vaccineName . " - Completed on " . date('Y-m-d H:i:s');
    $administeredBy = $_SESSION['user_id'];

    $stmt->bind_param(
        'issiisi',
        $appointment['patient_id'],
        $patientName,
        $appointmentId,
        $appointment['vaccine_id'] ?? null,
        $notes,
        $administeredBy
    );
    $stmt->execute();
    $reportId = $mysqli->insert_id;
    $stmt->close();

    // 4. Update vaccination record if vaccine_id exists
    if (!empty($appointment['vaccine_id'])) {
        $stmt = $mysqli->prepare("
            INSERT INTO vaccination_records 
            (patient_id, vaccine_id, date_given, administered_by, notes)
            VALUES (?, ?, NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE 
                date_given = NOW(),
                administered_by = ?,
                notes = ?
        ");
        $notes = "Administered by: " . $_SESSION['full_name'] . " on " . date('Y-m-d H:i:s');
        $stmt->bind_param(
            'iiisss',
            $appointment['patient_id'],
            $appointment['vaccine_id'],
            $administeredBy,
            $notes,
            $administeredBy,
            $notes
        );
        $stmt->execute();
        $stmt->close();
    }

    // Commit the transaction
    $mysqli->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Appointment completed and reported successfully',
        'report_id' => $reportId
    ]);

} catch (Exception $e) {
    // Rollback the transaction on error
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
