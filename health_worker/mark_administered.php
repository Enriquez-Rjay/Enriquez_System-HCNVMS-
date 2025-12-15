<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /HealthCenter/health_worker/hw_schedule.php');
    exit;
}

// Only health workers may mark administered
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'health_worker') {
    header('Location: /HealthCenter/login.php');
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
    // Account is inactive, destroy session and redirect
    session_destroy();
    header('Location: /HealthCenter/login.php?error=account_inactive');
    exit();
}

$appointment_id = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;
if ($appointment_id <= 0) {
    header('Location: /HealthCenter/health_worker/hw_schedule.php');
    exit;
}

// Verify appointment exists and belongs to this health worker
// Get full appointment details including vaccine and dosage
$stmt = $mysqli->prepare('
    SELECT id, patient_id, health_worker_id, vaccine_id, dosage, scheduled_at 
    FROM appointments 
    WHERE id = ? 
    LIMIT 1
');
$stmt->bind_param('i', $appointment_id);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt || (int) $appt['health_worker_id'] !== (int) $_SESSION['user_id']) {
    header('Location: /HealthCenter/health_worker/hw_schedule.php');
    exit;
}

// Mark appointment completed and insert a vaccination record when vaccine data is present
try {
    $mysqli->begin_transaction();

    $u = $mysqli->prepare('UPDATE appointments SET status = ? WHERE id = ?');
    $status = 'completed';
    $u->bind_param('si', $status, $appointment_id);
    $u->execute();
    $u->close();

    // Automatically create vaccination_records entry based on the appointment's vaccine and dosage
    if (!empty($appt['vaccine_id'])) {
        $vaccine_id = (int) $appt['vaccine_id'];
        $patientIdForRecord = (int) $appt['patient_id'];

        // Use today's date as the administration date (or fall back to scheduled date's date part)
        $date_given = date('Y-m-d');
        if (!empty($appt['scheduled_at'])) {
            $scheduledDate = date('Y-m-d', strtotime($appt['scheduled_at']));
            // If scheduled date is not in the future, you can also choose to use it
            if ($scheduledDate <= $date_given) {
                $date_given = $scheduledDate;
            }
        }

        // Derive numeric dose from textual dosage (e.g., "1st Dose", "Booster", "Additional Dose")
        $doseNumber = 0;
        $dosageText = $appt['dosage'] ?? '';
        if (!empty($dosageText)) {
            if (preg_match('/(\d+)(st|nd|rd|th)\s+Dose/i', $dosageText, $matches)) {
                $doseNumber = (int) $matches[1];
            } elseif (stripos($dosageText, 'booster') !== false) {
                $doseNumber = 4;
            } elseif (stripos($dosageText, 'additional') !== false) {
                $doseNumber = 5;
            }
        }
        if ($doseNumber <= 0) {
            $doseNumber = 1;
        }

        // Insert vaccination record
        $ins = $mysqli->prepare('
            INSERT INTO vaccination_records (patient_id, vaccine_id, date_given, dose, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ');
        $ins->bind_param('iisi', $patientIdForRecord, $vaccine_id, $date_given, $doseNumber);
        $ins->execute();
        $ins->close();

        // Decrement vaccine stock from the oldest batch with available quantity
        // and log a vaccine_transactions entry so the admin dashboard stays in sync.
        $batchStmt = $mysqli->prepare('
            SELECT id, quantity_available 
            FROM vaccine_batches 
            WHERE vaccine_id = ? AND quantity_available > 0 
            ORDER BY expiry_date ASC, id ASC 
            LIMIT 1
        ');
        $batchStmt->bind_param('i', $vaccine_id);
        $batchStmt->execute();
        $batchRes = $batchStmt->get_result();
        $batch = $batchRes->fetch_assoc();
        $batchStmt->close();

        if ($batch) {
            $batchId = (int) $batch['id'];

            // Reduce available quantity by 1 (assuming 1 dose uses 1 unit from stock)
            $upd = $mysqli->prepare('
                UPDATE vaccine_batches 
                SET quantity_available = GREATEST(quantity_available - 1, 0) 
                WHERE id = ?
            ');
            $upd->bind_param('i', $batchId);
            $upd->execute();
            $upd->close();

            // Log transaction
            $txn = $mysqli->prepare('
                INSERT INTO vaccine_transactions (batch_id, type, quantity, notes, performed_by, created_at)
                VALUES (?, "use", ?, ?, ?, NOW())
            ');
            $qtyUsed = 1;
            $notes = 'Dose ' . $doseNumber . ' administered from appointment ID ' . $appointment_id;
            $performedBy = (int) $_SESSION['user_id'];
            $txn->bind_param('iisi', $batchId, $qtyUsed, $notes, $performedBy);
            $txn->execute();
            $txn->close();
        }
    }

    $mysqli->commit();
} catch (Exception $e) {
    $mysqli->rollback();
}

// After marking completed, forward user to Reports so they can see updated completion counts
header('Location: /HealthCenter/health_worker/hw_reports.php');
exit;

?>