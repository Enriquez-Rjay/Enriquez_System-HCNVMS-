<?php
// scripts/send_upcoming_notifications.php
// Send email reminders to patients with upcoming appointments (today and tomorrow)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';

// Load email template from system_settings
$emailTemplate = "Subject: Vaccination Reminder\nDear [Parent Name],\nThis is a reminder that your child, [Child Name], is scheduled for the [Vaccine Name] vaccine on [Date].\nRegards,\nHealth Center Team";

if ($stmt = $mysqli->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'email_template' LIMIT 1")) {
    $stmt->execute();
    $stmt->bind_result($tpl);
    if ($stmt->fetch() && $tpl) {
        $emailTemplate = $tpl;
    }
    $stmt->close();
}

// Find scheduled appointments for today and tomorrow
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$sql = "SELECT a.id AS appt_id,
               a.scheduled_at,
               u.email,
               u.full_name,
               p.child_name
        FROM appointments a
        JOIN users u ON u.id = a.patient_id
        LEFT JOIN patient_profiles p ON p.user_id = a.patient_id
        WHERE a.status = 'scheduled'
          AND DATE(a.scheduled_at) BETWEEN ? AND ?";

$sentCount = 0;
$errors = [];

if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('ss', $today, $tomorrow);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $toEmail = $row['email'];
        if (!$toEmail) {
            continue;
        }
        $parentName = $row['full_name'] ?: 'Parent';
        $childName = $row['child_name'] ?: $parentName;
        $dateStr = date('Y-m-d', strtotime($row['scheduled_at']));

        $placeholders = [
            '[Parent Name]' => $parentName,
            '[Child Name]' => $childName,
            '[Vaccine Name]' => 'scheduled vaccination',
            '[Date]' => $dateStr,
        ];

        $filled = strtr($emailTemplate, $placeholders);

        $lines = preg_split("/\r?\n/", $filled, -1, PREG_SPLIT_NO_EMPTY);
        $subject = 'Vaccination Reminder';
        $bodyLines = $lines;
        if (isset($lines[0]) && stripos($lines[0], 'subject:') === 0) {
            $subject = trim(substr($lines[0], strlen('Subject:')));
            array_shift($bodyLines);
        }
        $body = implode("\n", $bodyLines);

        $ok = hc_send_email($toEmail, $parentName, $subject, $body);
        if ($ok === true) {
            $sentCount++;
        } else {
            $errors[] = 'Appt ' . (int) $row['appt_id'] . ': ' . $ok;
        }
    }

    $stmt->close();
}

header('Content-Type: text/plain; charset=utf-8');
echo "Sent reminders: " . $sentCount . "\n";
if (!empty($errors)) {
    echo "Errors:\n" . implode("\n", $errors);
}
