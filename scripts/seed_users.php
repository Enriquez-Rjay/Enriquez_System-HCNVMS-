<?php
// scripts/seed_users.php
// Run this from CLI or browser once to create sample users with hashed passwords.

// Usage (CLI):
// php scripts/seed_users.php

require __DIR__ . '/../config/db.php';
$mysqli = require __DIR__ . '/../config/db.php';

$users = [
    ['username' => 'admin', 'password' => 'admin123', 'role' => 'admin', 'full_name' => 'System Administrator'],
    ['username' => 'worker', 'password' => 'worker123', 'role' => 'health_worker', 'full_name' => 'Health Worker'],
    ['username' => 'patient', 'password' => 'patient123', 'role' => 'patient', 'full_name' => 'Sample Patient'],
];

foreach ($users as $u) {
    $username = $u['username'];
    $password_hash = password_hash($u['password'], PASSWORD_DEFAULT);
    $role = $u['role'];
    $full_name = $u['full_name'];

    $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->fetch_assoc()) {
        echo "User {$username} already exists\n";
        continue;
    }

    $insert = $mysqli->prepare('INSERT INTO users (username, password_hash, role, full_name, email) VALUES (?, ?, ?, ?, ?)');
    $email = $username . '@example.local';
    $insert->bind_param('sssss', $username, $password_hash, $role, $full_name, $email);
    if ($insert->execute()) {
        echo "Created user: {$username} ({$role})\n";
    } else {
        echo "Failed to create {$username}: " . $mysqli->error . "\n";
    }
}

echo "Done.\n";

?>