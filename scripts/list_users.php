<?php
// scripts/list_users.php
// Run: php scripts/list_users.php
require __DIR__ . '/../config/db.php';
$mysqli = require __DIR__ . '/../config/db.php';

$res = $mysqli->query("SELECT id, username, email, role, created_at FROM users ORDER BY id DESC");
if (!$res) {
    echo "Query failed: " . $mysqli->error . "\n";
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    echo implode(" | ", $row) . "\n";
}

if ($res->num_rows === 0) {
    echo "No users found.\n";
}

?>