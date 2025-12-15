<?php
// scripts/mailer.php
// Shared mail helper using PHPMailer and Gmail SMTP.

require_once __DIR__ . '/../config/db.php';
$mailConfig = require __DIR__ . '/../config/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// PHPMailer src files are located in scripts/src
require __DIR__ . '/src/Exception.php';
require __DIR__ . '/src/PHPMailer.php';
require __DIR__ . '/src/SMTP.php';

function hc_send_email($toEmail, $toName, $subject, $body)
{
    global $mailConfig;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $mailConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $mailConfig['username'];
        $mail->Password = $mailConfig['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $mailConfig['port'];

        $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
        $mail->addAddress($toEmail, $toName);

        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // i-return nato ang detailed error
        return 'Mail error: ' . $mail->ErrorInfo;
    }
}