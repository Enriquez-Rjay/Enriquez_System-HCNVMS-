<?php
// auth/login.php
// start session with secure settings
require_once __DIR__ . '/../config/session.php';

$mysqli = require __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /HealthCenter/login.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

function redirect_with_error($msg)
{
    $_SESSION['error'] = $msg;
    header('Location: /HealthCenter/login.php');
    exit;
}

if ($username === '' || $password === '') {
    redirect_with_error('Please enter username and password');
}

// Accept either username or email - also check status
$stmt = $mysqli->prepare('SELECT id, username, password_hash, role, status FROM users WHERE (username = ? OR email = ?) LIMIT 1');
if (!$stmt) {
    redirect_with_error('Database error');
}

$stmt->bind_param('ss', $username, $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    redirect_with_error('Invalid username or password');
}

// Check if account is active
if (strtolower($user['status'] ?? '') !== 'active') {
    redirect_with_error('Your account has been deactivated. Please contact an administrator.');
}

if (!password_verify($password, $user['password_hash'])) {
    redirect_with_error('Invalid username or password');
}

// authentication success - regenerate session id to prevent fixation
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];

// redirect based on role
switch ($user['role']) {
    case 'admin':
        header('Location: /HealthCenter/admin/admin_dashboard.php');
        break;
    case 'health_worker':
        header('Location: /HealthCenter/health_worker/hw_dashboard.php');
        break;
    case 'patient':
        header('Location: /HealthCenter/patient/p_dashboard.php');
        break;
    default:
        header('Location: /HealthCenter/login.php');
}

exit;

?>