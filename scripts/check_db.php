<?php
// sql/check_db.php – quick DB verification using project's DB credentials
require_once __DIR__ . '/../config/db.php';

$errors = [];
function qCount($mysqli, $sql)
{
    $res = $mysqli->query($sql);
    if (!$res)
        return ['error' => $mysqli->error];
    $row = $res->fetch_row();
    $res->free();
    return ['count' => $row[0]];
}

$metrics = [];

$metrics['users_total'] = qCount($mysqli, "SELECT COUNT(*) FROM users");
$metrics['patients_total'] = qCount($mysqli, "SELECT COUNT(*) FROM users WHERE role='patient'");
$metrics['vaccines_total'] = qCount($mysqli, "SELECT COUNT(*) FROM vaccines");
$metrics['vaccination_records'] = qCount($mysqli, "SELECT COUNT(*) FROM vaccination_records");
$metrics['appointments_today'] = qCount($mysqli, "SELECT COUNT(*) FROM appointments WHERE DATE(scheduled_at)=CURDATE()");

header('Content-Type: text/plain; charset=utf-8');
echo "DB check results for database '" . htmlspecialchars($db_name ?? 'healthcenter') . "'\n\n";
foreach ($metrics as $k => $v) {
    if (isset($v['error'])) {
        echo "$k: ERROR -> " . $v['error'] . "\n";
        $errors[] = $k;
    } else {
        echo "$k: " . $v['count'] . "\n";
    }
}

if (count($errors) > 0) {
    echo "\nOne or more queries failed.\n";
    exit(1);
}

echo "\nAll checks executed.\n";
return 0;
