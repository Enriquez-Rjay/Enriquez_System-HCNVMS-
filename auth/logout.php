<?php
// auth/logout.php
require_once __DIR__ . '/../config/session.php';
// clear session data
$_SESSION = [];
// delete session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();
header('Location: /HealthCenter/login.php');
exit;

?>