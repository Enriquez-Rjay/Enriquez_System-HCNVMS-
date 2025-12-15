<?php
/**
 * Automatic Reminder System
 * Sends email reminders to patients before their scheduled appointments
 * 
 * This script should be run via cron job daily (e.g., every hour or once per day)
 * Example cron: 0 * * * * /usr/bin/php /path/to/send_automatic_reminders.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../scripts/src/PHPMailer.php';
require_once __DIR__ . '/../scripts/src/SMTP.php';
require_once __DIR__ . '/../scripts/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load email configuration
$email_config = require __DIR__ . '/../config/email_config.php';

// Configuration: Send reminders X hours before appointment (default: 24 hours)
$reminder_hours_before = 24; // Can be changed to 12, 48, etc.

// Calculate the time range for appointments that need reminders
$now = new DateTime();
$reminder_time = clone $now;
$reminder_time->modify("+{$reminder_hours_before} hours");

// Check if reminder_sent_at column exists
$column_exists = false;
$check_column = $mysqli->query("SHOW COLUMNS FROM appointments LIKE 'reminder_sent_at'");
if ($check_column && $check_column->num_rows > 0) {
    $column_exists = true;
}

// Find appointments that:
// 1. Are scheduled (not completed or cancelled)
// 2. Are within the reminder time window (e.g., 24 hours from now)
// 3. Haven't had a reminder sent yet (reminder_sent_at IS NULL) - if column exists
// 4. Have a patient with an email address

$sql = "
    SELECT 
        a.id AS appointment_id,
        a.patient_id,
        a.scheduled_at,
        a.notes,
        a.vaccine_id,
        a.dosage,
        a.weight,
        a.height,
        u.email,
        u.full_name AS patient_name,
        u.username AS patient_code,
        p.child_name,
        p.birth_date,
        p.guardian_name,
        v.name AS vaccine_name" . 
        ($column_exists ? ", a.reminder_sent_at" : "") . "
    FROM appointments a
    INNER JOIN users u ON u.id = a.patient_id AND u.role = 'patient'
    INNER JOIN patient_profiles p ON p.user_id = u.id
    LEFT JOIN vaccines v ON v.id = a.vaccine_id
    WHERE a.status IN ('scheduled', 'pending')
      AND a.scheduled_at BETWEEN ? AND ?
      " . ($column_exists ? "AND (a.reminder_sent_at IS NULL OR a.reminder_sent_at = '0000-00-00 00:00:00')" : "") . "
      AND u.email IS NOT NULL
      AND u.email != ''
    ORDER BY a.scheduled_at ASC
";

$sent_count = 0;
$error_count = 0;
$errors = [];

if ($stmt = $mysqli->prepare($sql)) {
    $start_time = $now->format('Y-m-d H:i:s');
    $end_time = $reminder_time->format('Y-m-d H:i:s');
    
    $stmt->bind_param('ss', $start_time, $end_time);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($appt = $result->fetch_assoc()) {
        $appointment_id = $appt['appointment_id'];
        $patient_email = $appt['email'];
        $patient_name = $appt['child_name'] ?? $appt['patient_name'] ?? 'Patient';
        $guardian_name = $appt['guardian_name'] ?? $appt['patient_name'] ?? 'Parent/Guardian';
        
        // Prepare email content
        $apptDateTime = new DateTime($appt['scheduled_at']);
        $apptDate = $apptDateTime->format('l, F j, Y');
        $apptTime = $apptDateTime->format('g:i A');
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
        
        $subject = "Vaccination Reminder - " . $patient_name;
        $message = "
Dear " . htmlspecialchars($guardian_name) . ",

This is an automatic reminder that " . htmlspecialchars($patient_name) . " has a scheduled vaccination appointment.

Appointment Details:
- Date: " . $apptDate . "
- Time: " . $apptTime . $age_info . $physical_info . $vaccine_info . "
- Notes: " . htmlspecialchars($notes) . "

Please ensure you arrive on time for the appointment. If you need to reschedule or cancel, please contact the health center as soon as possible.

Thank you,
Health Center Vaccination Management System
";
        
        // Send email using PHPMailer
        $emailSent = false;
        $emailError = '';
        
        try {
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host = $email_config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $email_config['smtp_username'];
            $mail->Password = $email_config['smtp_password'];
            $mail->SMTPSecure = $email_config['smtp_secure'];
            $mail->Port = $email_config['smtp_port'];
            $mail->CharSet = 'UTF-8';
            
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
            $mail->addAddress($patient_email, $guardian_name);
            $mail->addReplyTo($email_config['reply_to'], $email_config['from_name']);
            
            // Content
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $message;
            
            $emailSent = $mail->send();
        } catch (Exception $e) {
            $emailError = 'Mailer Error: ' . $mail->ErrorInfo;
            error_log("Automatic reminder error for appointment {$appointment_id}: " . $emailError);
            $emailSent = false;
        }
        
        // Update appointment to mark reminder as sent (even if email failed, to avoid retrying immediately)
        if ($emailSent && $column_exists) {
            $update_stmt = $mysqli->prepare("UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ?");
            $update_stmt->bind_param('i', $appointment_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $sent_count++;
            
            // Log the reminder
            $report_type = 'automatic_reminder';
            $params = json_encode([
                'appointment_id' => $appointment_id,
                'patient_id' => (int) $appt['patient_id'],
                'email_sent' => true,
                'email_to' => $patient_email,
                'reminder_hours_before' => $reminder_hours_before
            ]);
            $ins = $mysqli->prepare('INSERT INTO reports (report_type, params, generated_at) VALUES (?, ?, NOW())');
            $ins->bind_param('ss', $report_type, $params);
            $ins->execute();
            $ins->close();
        } else {
            $error_count++;
            $errors[] = "Appointment #{$appointment_id} ({$patient_email}): " . ($emailError ?: 'Unknown error');
        }
    }
    
    $stmt->close();
}

// Output results (useful for cron job logging)
if (php_sapi_name() === 'cli') {
    echo "Automatic Reminder System\n";
    echo "========================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Reminder window: {$reminder_hours_before} hours before appointment\n";
    echo "Reminders sent: {$sent_count}\n";
    echo "Errors: {$error_count}\n";
    if (!empty($errors)) {
        echo "\nError details:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
    }
} else {
    // If accessed via web browser, return JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'sent_count' => $sent_count,
        'error_count' => $error_count,
        'errors' => $errors,
        'reminder_hours_before' => $reminder_hours_before
    ]);
}
?>

