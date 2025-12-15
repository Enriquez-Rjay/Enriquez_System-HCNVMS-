<?php
// config/session.php
// Centralized session configuration — include this at the top of any PHP page that requires sessions.

// Determine if connection is secure
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// Enforce use only cookies for session id
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);

// Set session cookie params (must be before session_start)
$cookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
];

// Support older PHP versions that don't accept array for session_set_cookie_params
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($cookieParams);
} else {
    session_set_cookie_params($cookieParams['lifetime'], $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'], $cookieParams['domain'], $cookieParams['secure'], $cookieParams['httponly']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

?>