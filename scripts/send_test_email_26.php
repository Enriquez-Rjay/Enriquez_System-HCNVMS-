<?php
// scripts/send_test_email_26.php
// Sends a test email to patient id 26 using the email template from system settings.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';

$patientId = 26;

// Get patient email & name (adjust table/columns if needed)
$res = $mysqli->query("SELECT email, full_name FROM users WHERE id = $patientId LIMIT 1");
$patient = $res ? $res->fetch_assoc() : null;

if (!$patient || empty($patient['email'])) {
    echo 'No email found for patient id ' . (int)$patientId;
    exit;
}

$toEmail = $patient['email'];
$toName  = $patient['full_name'] ?: 'Patient';

// Load email template from system_settings table if present
$emailTemplate = "Subject: Vaccination Reminder\nDear [Parent Name],\nThis is a reminder that your child, [Child Name], is scheduled for the [Vaccine Name] vaccine on [Date].\nRegards,\nHealth Center Team";

if ($stmt = $mysqli->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'email_template' LIMIT 1")) {
    $stmt->execute();
    $stmt->bind_result($tpl);
    if ($stmt->fetch() && $tpl) {
        $emailTemplate = $tpl;
    }
    $stmt->close();
}

// Very simple placeholder replacement for testing
$placeholders = [
    '[Parent Name]'  => $toName,
    '[Child Name]'   => $toName,
    '[Vaccine Name]' => 'Test Vaccine',
    '[Date]'         => date('Y-m-d', strtotime('+7 days')),
];

$filled = strtr($emailTemplate, $placeholders);

// Split subject and body if template starts with "Subject: ..." on first line
$lines = preg_split("/\r?\n/", $filled, -1, PREG_SPLIT_NO_EMPTY);
$subject = 'Vaccination Reminder';
$bodyLines = $lines;
if (isset($lines[0]) && stripos($lines[0], 'subject:') === 0) {
    $subject = trim(substr($lines[0], strlen('Subject:')));
    array_shift($bodyLines);
}
$body = implode("\n", $bodyLines);

$ok = hc_send_email($toEmail, $toName, $subject, $body);

if ($ok === true) {
    echo 'Test email sent to ' . htmlspecialchars($toEmail);
} else {
    echo htmlspecialchars($ok);
}
