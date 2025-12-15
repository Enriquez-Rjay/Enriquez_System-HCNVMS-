<?php
// config/db.php
// MySQLi connection used by the app. Update credentials as needed.
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'healthcenter';

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo 'Database connection failed: ' . $mysqli->connect_error;
    exit;
}

// set charset
$mysqli->set_charset('utf8mb4');

return $mysqli;

?>