<?php
// scripts/fix_passwords.php
// Fix users whose password_hash column contains a plain password (not a hash).
// Run: php scripts/fix_passwords.php

$mysqli = require __DIR__ . '/../config/db.php';

$rows = $mysqli->query("SELECT id, username, role, password_hash FROM users");
if (!$rows) {
    echo "Query failed: " . $mysqli->error . "\n";
    exit(1);
}

$updated = 0;
while ($r = $rows->fetch_assoc()) {
    $id = $r['id'];
    $role = $r['role'];
    $pwd = $r['password_hash'];
    // heuristics: if it doesn't start with '$' it's not a proper password hash
    if (!is_string($pwd) || strlen($pwd) === 0 || $pwd[0] !== '$') {
        // choose default by role
        switch ($role) {
            case 'admin':
                $new = password_hash('admin123', PASSWORD_DEFAULT);
                break;
            case 'health_worker':
                $new = password_hash('worker123', PASSWORD_DEFAULT);
                break;
            case 'patient':
                $new = password_hash('patient123', PASSWORD_DEFAULT);
                break;
            default:
                $new = password_hash('changeme123', PASSWORD_DEFAULT);
        }
        $stmt = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $new, $id);
        if ($stmt->execute()) {
            echo "Updated user id={$id} ({$r['username']}) role={$role}\n";
            $updated++;
        } else {
            echo "Failed to update id={$id}: " . $mysqli->error . "\n";
        }
    }
}

echo "Done. Updated {$updated} users.\n";

?>