<?php
/**
 * Gmail SMTP configuration for PHPMailer.
 *
 * IMPORTANT:
 * 1. Enable 2-Step Verification in your Google Account.
 * 2. Generate an App Password (NOT your regular password).
 * 3. Put the App Password below.
 *
 * Guide:
 * - Visit https://myaccount.google.com/security
 * - Enable 2-Step Verification (if needed)
 * - Open "App passwords" → Select "Mail" → Device "Other"
 * - Name it (e.g. HealthCenter), click Generate, copy the 16-character password.
 */

return [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_secure' => 'tls', // 'tls' or 'ssl'

    // TODO: Replace with your Gmail email + App Password
    'smtp_username' => 'leamaelajera1@gmail.com',
    'smtp_password' => 'vpswoglymjvatcsw', // 16 chars, no spaces

    // Sender info
    'from_email' => 'leamaelajera1@gmail.com',
    'from_name' => 'Health Center Vaccination System',
    'reply_to' => 'noreply@healthcenter.com',
];


