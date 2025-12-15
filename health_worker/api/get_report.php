<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/session.php';
$mysqli = require __DIR__ . '/../../config/db.php';

$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : '';
$vaccineProgram = isset($_GET['vaccine_program']) ? $_GET['vaccine_program'] : 'All Programs';
$dateRange = isset($_GET['date_range']) ? $_GET['date_range'] : '';

$reportsList = [];
$reportTitle = '';
$displaySubtitle = '';

if (empty($reportType)) {
    echo json_encode(['error' => 'report_type required']);
    exit;
}

if ($reportType === 'vaccination_completion') {
    $reportTitle = 'Vaccination Completion Report';
    $displaySubtitle = 'Showing data for ' . $vaccineProgram . ($dateRange ? ' | Date: ' . $dateRange : '');

    $sql = "SELECT vr.id, u.id AS patient_id, u.full_name AS patient_name, u.username AS patient_code,
                   v.name AS vaccine, vr.date_given AS administered_at, vr.status
            FROM vaccination_records vr
            LEFT JOIN users u ON u.id = vr.patient_id
            LEFT JOIN vaccines v ON v.id = vr.vaccine_id
            WHERE 1=1";

    $types = '';
    $params = [];
    if (!empty($dateRange)) {
        $sql .= " AND vr.date_given >= ?";
        $types .= 's';
        $params[] = $dateRange;
    }
    if ($vaccineProgram !== 'All Programs') {
        $sql .= " AND v.name = ?";
        $types .= 's';
        $params[] = $vaccineProgram;
    }
    $sql .= " ORDER BY vr.date_given DESC LIMIT 500";

    if ($stmt = $mysqli->prepare($sql)) {
        if (!empty($params)) {
            $bind_names[] = $types;
            for ($i = 0; $i < count($params); $i++) {
                $bind_name = 'bind' . $i;
                $$bind_name = $params[$i];
                $bind_names[] = &$$bind_name;
            }
            call_user_func_array(array($stmt, 'bind_param'), $bind_names);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $reportsList[] = $row;
        }
        $stmt->close();
    }

} elseif ($reportType === 'missed_appointments') {
    $reportTitle = 'Missed Appointments Report';
    $displaySubtitle = 'Showing missed/cancelled appointments' . ($dateRange ? ' | Date: ' . $dateRange : '');

    $sql = "SELECT a.id, u.id AS patient_id, u.full_name AS patient_name, u.username AS patient_code,
                   a.scheduled_at AS administered_at, a.status
            FROM appointments a
            LEFT JOIN users u ON u.id = a.patient_id
            WHERE a.status IN ('missed','cancelled')";

    $types = '';
    $params = [];
    if (!empty($dateRange)) {
        $sql .= " AND a.scheduled_at >= ?";
        $types .= 's';
        $params[] = $dateRange;
    }
    $sql .= " ORDER BY a.scheduled_at DESC LIMIT 500";

    if ($stmt = $mysqli->prepare($sql)) {
        if (!empty($params)) {
            $bind_names[] = $types;
            for ($i = 0; $i < count($params); $i++) {
                $bind_name = 'bind' . $i;
                $$bind_name = $params[$i];
                $bind_names[] = &$$bind_name;
            }
            call_user_func_array(array($stmt, 'bind_param'), $bind_names);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $reportsList[] = $row;
        }
        $stmt->close();
    }

} elseif ($reportType === 'vaccine_inventory') {
    $reportTitle = 'Vaccine Inventory Report';
    $displaySubtitle = 'Current vaccine stock levels';

    $sql = "SELECT v.id, v.name AS vaccine, v.batch_number AS patient_code,
                   v.expiration_date AS administered_at, v.quantity AS status
            FROM vaccines v
            ORDER BY v.name ASC LIMIT 500";

    if ($res = $mysqli->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $reportsList[] = $row;
        }
        $res->free();
    }
}

echo json_encode([
    'reportTitle' => $reportTitle,
    'displaySubtitle' => $displaySubtitle,
    'count' => count($reportsList),
    'rows' => $reportsList,
]);

exit;
