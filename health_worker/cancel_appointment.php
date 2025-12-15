<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

// Check if user is logged in and is a health worker or admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
    $_SESSION['reminder_error'] = 'Unauthorized access';
    header('Location: /HealthCenter/health_worker/hw_schedule.php');
    exit();
}

// Get the appointment ID from the request
$appointment_id = $_POST['appointment_id'] ?? null;

if (!$appointment_id) {
    $_SESSION['reminder_error'] = 'Appointment ID is required';
    header('Location: /HealthCenter/health_worker/hw_schedule.php');
    exit();
}

try {
    // Verify the appointment exists and belongs to the health worker (or any health worker can cancel)
    $stmt = $mysqli->prepare("
        SELECT id, status, patient_id 
        FROM appointments 
        WHERE id = ? AND status IN ('scheduled', 'pending')
    ");
    
    if (!$stmt) {
        throw new Exception("Database error: " . $mysqli->error);
    }

    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        $_SESSION['reminder_error'] = 'Appointment not found or cannot be cancelled';
        header('Location: /HealthCenter/health_worker/hw_schedule.php');
        exit();
    }

    // Update the appointment status to 'cancelled'
    $stmt = $mysqli->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?");
    
    if (!$stmt) {
        throw new Exception("Database error: " . $mysqli->error);
    }

    $stmt->bind_param('i', $appointment_id);

    if ($stmt->execute()) {
        $_SESSION['reminder_success'] = 'Appointment cancelled successfully';
    } else {
        throw new Exception("Failed to cancel appointment");
    }

    $stmt->close();
} catch (Exception $e) {
    $_SESSION['reminder_error'] = 'Error: ' . $e->getMessage();
}

// Redirect back to schedule page
header('Location: /HealthCenter/health_worker/hw_schedule.php');
exit();
?>

