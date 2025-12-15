<?php
require_once __DIR__ . '/../config/session.php';

// Include PHPMailer files
require_once __DIR__ . '/../scripts/src/PHPMailer.php';
require_once __DIR__ . '/../scripts/src/SMTP.php';
require_once __DIR__ . '/../scripts/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load email configuration
$email_config = require __DIR__ . '/../config/email_config.php';

$mysqli = require __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /HealthCenter/health_worker/hw_schedule.php');
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'health_worker') {
    header('Location: /HealthCenter/login.php');
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;
if ($appointment_id <= 0) {
    header('Location: /HealthCenter/health_worker/hw_schedule.php');
    exit;
}

// Fetch appointment with patient and profile information, including vaccine details
$stmt = $mysqli->prepare("
    SELECT a.id, a.patient_id, a.health_worker_id, a.scheduled_at, a.notes, a.status,
           a.vaccine_id, a.dosage, a.weight, a.height,
           u.full_name AS patient_name, u.email, u.username AS patient_code,
           p.child_name, p.birth_date, p.guardian_name, p.address,
           v.name AS vaccine_name
    FROM appointments a
    INNER JOIN users u ON u.id = a.patient_id AND u.role = 'patient'
    INNER JOIN patient_profiles p ON p.user_id = u.id
    LEFT JOIN vaccines v ON v.id = a.vaccine_id
    WHERE a.id = ? LIMIT 1
");
$stmt->bind_param('i', $appointment_id);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt || (int) $appt['health_worker_id'] !== (int) $_SESSION['user_id']) {
    header('Location: /HealthCenter/health_worker/hw_schedule.php');
    exit;
}

// Check if patient has email
if (empty($appt['email'])) {
    $_SESSION['reminder_error'] = 'Patient does not have an email address registered.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/HealthCenter/health_worker/hw_schedule.php'));
    exit;
}

// Prepare email content
$apptDateTime = new DateTime($appt['scheduled_at']);
$apptDate = $apptDateTime->format('l, F j, Y');
$apptTime = $apptDateTime->format('g:i A');
$patientName = $appt['child_name'] ?? $appt['patient_name'] ?? 'Patient';
$guardianName = $appt['guardian_name'] ?? 'Guardian';
$notes = $appt['notes'] ?? 'Vaccination appointment';

// Calculate age from birth date
$age_info = '';
if (!empty($appt['birth_date'])) {
    $birthDate = new DateTime($appt['birth_date']);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    
    $ageParts = [];
    if ($age->y > 0) {
        $ageParts[] = $age->y . ' year' . ($age->y > 1 ? 's' : '');
    }
    if ($age->m > 0) {
        $ageParts[] = $age->m . ' month' . ($age->m > 1 ? 's' : '');
    }
    if ($age->d > 0 && $age->y == 0) {
        $ageParts[] = $age->d . ' day' . ($age->d > 1 ? 's' : '');
    }
    
    if (!empty($ageParts)) {
        $age_info = "\n- Age: " . implode(', ', $ageParts);
    }
}

// Build vaccine information
$vaccine_info = '';
if (!empty($appt['vaccine_name'])) {
    $vaccine_info = "\n- Vaccine: " . htmlspecialchars($appt['vaccine_name']);
    if (!empty($appt['dosage'])) {
        $vaccine_info .= "\n- Dosage: " . htmlspecialchars($appt['dosage']);
    }
} elseif (!empty($appt['dosage'])) {
    $vaccine_info = "\n- Dosage: " . htmlspecialchars($appt['dosage']);
}

// Build weight and height information
$physical_info = '';
if (!empty($appt['weight'])) {
    $physical_info = "\n- Weight: " . htmlspecialchars($appt['weight']) . " kg";
}
if (!empty($appt['height'])) {
    $physical_info .= "\n- Height: " . htmlspecialchars($appt['height']) . " cm";
}

$subject = "Vaccination Reminder - " . $patientName;
$message = "
Dear " . htmlspecialchars($guardianName) . ",

This is a reminder that " . htmlspecialchars($patientName) . " has a scheduled vaccination appointment.

Appointment Details:
- Date: " . $apptDate . "
- Time: " . $apptTime . $age_info . $physical_info . $vaccine_info . "
- Notes: " . htmlspecialchars($notes) . "

Please ensure you arrive on time for the appointment. If you need to reschedule, please contact the health center as soon as possible.

Thank you,
Health Center Vaccination Management System
";

// Send email using PHPMailer
$emailSent = false;
$emailError = '';

try {
    $mail = new PHPMailer(true);

    // Server settings
    $mail->SMTPDebug = SMTP::DEBUG_OFF;  // Disable debug output for production
    $mail->isSMTP();
    $mail->Host = $email_config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $email_config['smtp_username'];
    $mail->Password = $email_config['smtp_password'];
    $mail->SMTPSecure = $email_config['smtp_secure'];
    $mail->Port = $email_config['smtp_port'];
    $mail->CharSet = 'UTF-8';

    // Enable debug for troubleshooting (uncomment if needed)
    // $mail->SMTPDebug = SMTP::DEBUG_SERVER;

    // Disable SSL certificate verification (use with caution)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    // Recipients
    $mail->setFrom($email_config['from_email'], $email_config['from_name']);
    $mail->addAddress($appt['email'], $guardianName);
    $mail->addReplyTo($email_config['reply_to'], $email_config['from_name']);

    // Content
    $mail->isHTML(false); // Set email format to plain text
    $mail->Subject = $subject;
    $mail->Body = $message;

    $emailSent = $mail->send();
} catch (Exception $e) {
    $emailError = 'Mailer Error: ' . $mail->ErrorInfo;
    error_log($emailError);
    $emailSent = false;
}

// Mark reminder as sent in appointments table (to prevent automatic reminder from sending again)
if ($emailSent) {
    // Check if reminder_sent_at column exists
    $check_column = $mysqli->query("SHOW COLUMNS FROM appointments LIKE 'reminder_sent_at'");
    if ($check_column && $check_column->num_rows > 0) {
        $update_stmt = $mysqli->prepare("UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ?");
        $update_stmt->bind_param('i', $appointment_id);
        $update_stmt->execute();
        $update_stmt->close();
    }
}

// Record a reminder action by creating a lightweight report entry (acts as a log)
$report_type = 'reminder';
$params = json_encode([
    'appointment_id' => $appointment_id,
    'patient_id' => (int) $appt['patient_id'],
    'email_sent' => $emailSent,
    'email_to' => $appt['email'],
    'manual' => true
]);
$generated_by = (int) $_SESSION['user_id'];

$ins = $mysqli->prepare('INSERT INTO reports (report_type, params, generated_by, generated_at) VALUES (?, ?, ?, NOW())');
$ins->bind_param('ssi', $report_type, $params, $generated_by);
$ins->execute();
$ins->close();

if ($emailSent) {
    $_SESSION['reminder_success'] = 'Reminder email sent successfully to ' . htmlspecialchars($appt['email']);
} else {
    $errorMsg = 'Failed to send reminder email. ';
    if (!empty($emailError)) {
        $errorMsg .= $emailError;
    } else {
        $errorMsg .= 'Please check email configuration.';
    }
    $_SESSION['reminder_error'] = $errorMsg;
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/HealthCenter/health_worker/hw_schedule.php'));
exit;

?>