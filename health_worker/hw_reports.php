<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /HealthCenter/login.php');
    exit();
}

// Check if logged-in user's account is still active
$checkStmt = $mysqli->prepare("SELECT status FROM users WHERE id = ?");
$checkStmt->bind_param('i', $_SESSION['user_id']);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$userStatus = $checkResult->fetch_assoc();
$checkStmt->close();

if (!$userStatus || strtolower($userStatus['status'] ?? '') !== 'active') {
    // Account is inactive, destroy session and redirect
    session_destroy();
    header('Location: /HealthCenter/login.php?error=account_inactive');
    exit();
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Get the report data first
    $query = "
        SELECT 
            u.id AS patient_id,
            u.username AS patient_code,
            COALESCE(u.full_name, p.child_name) AS patient_name,
            p.birth_date,
            p.guardian_name,
            p.address,
            v.name AS vaccine,
            vr.date_given AS administered_at,
            vr.dose,
            CASE 
                WHEN COUNT(DISTINCT vr.vaccine_id) = 0 THEN 'Not Started'
                WHEN COUNT(DISTINCT vr.vaccine_id) = (SELECT COUNT(*) FROM vaccines) THEN 'Fully Vaccinated'
                WHEN COUNT(DISTINCT vr.vaccine_id) > 0 AND COUNT(DISTINCT vr.vaccine_id) < (SELECT COUNT(*) FROM vaccines) THEN 'Partially Vaccinated'
                ELSE 'Not Started'
            END AS vaccination_status
        FROM users u
        INNER JOIN patient_profiles p ON p.user_id = u.id
        LEFT JOIN vaccination_records vr ON vr.patient_id = u.id
        LEFT JOIN vaccines v ON v.id = vr.vaccine_id
        WHERE u.role = 'patient' 
        AND u.status = 'active' 
        AND u.status = 'active'
        GROUP BY u.id, u.username, u.full_name, p.child_name, 
                 p.birth_date, p.guardian_name, p.address,
                 v.name, vr.date_given, vr.dose
        ORDER BY u.full_name, p.child_name
    ";

    $stmt = $mysqli->prepare($query);
    if ($stmt) {
        $stmt->execute();
        $reportsList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=vaccination_report_' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');

        // Add CSV headers
        fputcsv($output, [
            'Patient ID',
            'Patient Name',
            'Guardian',
            'Vaccine',
            'Date Administered',
            'Dose',
            'Status'
        ]);

        // Add data rows
        foreach ($reportsList as $row) {
            fputcsv($output, [
                $row['patient_code'] ?? '',
                $row['patient_name'] ?? '',
                $row['guardian_name'] ?? '',
                $row['vaccine'] ?? '',
                !empty($row['administered_at']) ? date('Y-m-d', strtotime($row['administered_at'])) : 'N/A',
                $row['dose'] ?? '',
                $row['vaccination_status'] ?? 'Unknown'
            ]);
        }

        fclose($output);
        exit();
    }
}

// Check database connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Get filter parameters with default values
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of current month
$endDate = $_GET['end_date'] ?? date('Y-m-t');     // Default to end of current month
$status = $_GET['status'] ?? 'all';                // all, completed, incomplete, not_started

// Get total number of required vaccines
$totalVaccines = $mysqli->query("SELECT COUNT(*) as count FROM vaccines")->fetch_assoc()['count'];

// Helper: get per-vaccine dose history and next recommended dosage for a patient
function get_next_dosage_for_patient_vaccine(mysqli $mysqli, int $patientId, ?int $vaccineId): ?array {
    if (!$vaccineId || $patientId <= 0) {
        return null;
    }

    $stmt = $mysqli->prepare("
        SELECT 
            'completed' as type,
            vr.date_given as date,
            vr.dose,
            NULL as dosage_text
        FROM vaccination_records vr
        WHERE vr.patient_id = ? AND vr.vaccine_id = ?
        
        UNION ALL
        
        SELECT 
            'scheduled' as type,
            a.scheduled_at as date,
            NULL as dose,
            a.dosage as dosage_text
        FROM appointments a
        WHERE a.patient_id = ? AND a.vaccine_id = ? 
        AND a.status IN ('scheduled', 'pending')
        
        ORDER BY date DESC
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iiii', $patientId, $vaccineId, $patientId, $vaccineId);
    $stmt->execute();
    $result = $stmt->get_result();

    $history = [];
    $maxDose = 0;
    $dosageTexts = [];

    while ($row = $result->fetch_assoc()) {
        $history[] = $row;

        if (!empty($row['dosage_text'])) {
            if (preg_match('/(\d+)(st|nd|rd|th)\s+Dose/i', $row['dosage_text'], $matches)) {
                $doseNum = (int) $matches[1];
                $maxDose = max($maxDose, $doseNum);
                $dosageTexts[] = $row['dosage_text'];
            } elseif (stripos($row['dosage_text'], 'booster') !== false) {
                $maxDose = max($maxDose, 4);
                $dosageTexts[] = $row['dosage_text'];
            } elseif (stripos($row['dosage_text'], 'additional') !== false) {
                $maxDose = max($maxDose, 5);
                $dosageTexts[] = $row['dosage_text'];
            }
        } elseif (!empty($row['dose'])) {
            $maxDose = max($maxDose, (int) $row['dose']);
        }
    }
    $stmt->close();

    $nextDosage = '';
    if ($maxDose === 0) {
        $nextDosage = '1st Dose';
    } elseif ($maxDose === 1) {
        $nextDosage = '2nd Dose';
    } elseif ($maxDose === 2) {
        $nextDosage = '3rd Dose';
    } elseif ($maxDose >= 3) {
        $hasBooster = false;
        $hasAdditional = false;
        foreach ($dosageTexts as $text) {
            if (stripos($text, 'booster') !== false) {
                $hasBooster = true;
            }
            if (stripos($text, 'additional') !== false) {
                $hasAdditional = true;
            }
        }

        if (!$hasBooster) {
            $nextDosage = 'Booster';
        } elseif (!$hasAdditional) {
            $nextDosage = 'Additional Dose';
        } else {
            $nextDosage = 'Additional Dose';
        }
    }

    return [
        'max_dose' => $maxDose,
        'next_dosage' => $nextDosage,
        'has_history' => count($history) > 0,
    ];
}

// Prepare base query for vaccination completion report
$query = "
    SELECT 
        u.id AS patient_id,
        u.username AS patient_code,
        COALESCE(u.full_name, p.child_name) AS patient_name,
        p.birth_date,
        p.guardian_name,
        p.address,
        u.phone as contact_number,
        v.name AS vaccine_name,
        a.vaccine_id,
        a.dosage,
        (SELECT COUNT(*) FROM vaccines) AS total_vaccines,
        COUNT(DISTINCT vr.vaccine_id) AS vaccines_received_count,
        GROUP_CONCAT(DISTINCT v.name ORDER BY v.name SEPARATOR ', ') AS received_vaccines,
        (SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') 
         FROM vaccines 
         WHERE id NOT IN (
             SELECT vaccine_id 
             FROM vaccination_records 
             WHERE patient_id = u.id
         )) AS pending_vaccines,
        ROUND((COUNT(DISTINCT vr.vaccine_id) * 100.0) / 
              NULLIF(" . $totalVaccines . ", 0), 1) AS completion_percentage,
        MAX(vr.date_given) AS last_vaccination_date,
        a.scheduled_at,
        a.status AS appointment_status,
        CASE 
            WHEN COUNT(DISTINCT vr.vaccine_id) = 0 THEN 'Not Started'
            WHEN COUNT(DISTINCT vr.vaccine_id) = " . $totalVaccines . " THEN 'Fully Vaccinated'
            WHEN COUNT(DISTINCT vr.vaccine_id) > 0 THEN 'Partially Vaccinated'
            ELSE 'Not Started'
        END AS vaccination_status
    FROM users u
    INNER JOIN patient_profiles p ON p.user_id = u.id
    LEFT JOIN appointments a ON a.patient_id = u.id
    LEFT JOIN vaccination_records vr ON vr.patient_id = u.id
    LEFT JOIN vaccines v ON v.id = a.vaccine_id
    WHERE u.role = 'patient' 
        AND u.status = 'active' 
    AND u.status = 'active'
    GROUP BY u.id, u.username, u.full_name, p.child_name, 
             p.birth_date, p.guardian_name, p.address, u.phone,
             v.name, a.vaccine_id, a.dosage, a.scheduled_at, a.status
    ORDER BY 
        CASE 
            WHEN COUNT(DISTINCT vr.vaccine_id) = " . $totalVaccines . " THEN 1
            WHEN COUNT(DISTINCT vr.vaccine_id) > 0 THEN 2
            ELSE 3
        END,
        u.full_name, p.child_name
";

// Prepare and execute the query
$stmt = $mysqli->prepare($query);
if (!$stmt) {
    die("Error in query preparation: " . $mysqli->error);
}

// Execute the query directly since we're not using parameters
if (!$stmt->execute()) {
    die("Error executing query: " . $stmt->error);
}

$result = $stmt->get_result();
$reportsList = $result->fetch_all(MYSQLI_ASSOC);

// Get summary statistics
$summary = [
    'total_patients' => 0,
    'fully_vaccinated' => 0,
    'partially_vaccinated' => 0,
    'not_vaccinated' => 0,
    'avg_completion' => 0
];

if (!empty($reportsList)) {
    $summary['total_patients'] = count($reportsList);
    $totalCompletion = 0;

    foreach ($reportsList as $row) {
        $completion = (float) $row['completion_percentage'];
        $totalCompletion += $completion;

        if ($completion >= 100) {
            $summary['fully_vaccinated']++;
        } elseif ($completion > 0) {
            $summary['partially_vaccinated']++;
        } else {
            $summary['not_vaccinated']++;
        }
    }

    $summary['avg_completion'] = $summary['total_patients'] > 0
        ? round($totalCompletion / $summary['total_patients'], 1)
        : 0;
}

// Get vaccination statistics by gender
$genderStats = ['Male' => 0, 'Female' => 0];
$genderQuery = "
    SELECT 
        CASE 
            WHEN p.address LIKE '%Gender: Male%' OR p.address LIKE '%Gender: male%' THEN 'Male'
            WHEN p.address LIKE '%Gender: Female%' OR p.address LIKE '%Gender: female%' THEN 'Female'
            ELSE 'Unknown'
        END AS gender,
        COUNT(DISTINCT vr.patient_id) AS vaccinated_count
    FROM vaccination_records vr
    INNER JOIN patient_profiles p ON p.user_id = vr.patient_id
    INNER JOIN users u ON u.id = vr.patient_id
    WHERE u.role = 'patient' AND u.status = 'active'
    GROUP BY gender
";
$stmt = $mysqli->prepare($genderQuery);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($row['gender'] === 'Male' || $row['gender'] === 'Female') {
            $genderStats[$row['gender']] = (int)$row['vaccinated_count'];
        }
    }
    $stmt->close();
}

// Get vaccination statistics by age groups
$ageStats = [
    '0-6 months' => 0,
    '7-12 months' => 0,
    '13-18 months' => 0,
    '19-24 months' => 0,
    '2+ years' => 0
];
$ageQuery = "
    SELECT 
        CASE 
            WHEN TIMESTAMPDIFF(MONTH, p.birth_date, CURDATE()) BETWEEN 0 AND 6 THEN '0-6 months'
            WHEN TIMESTAMPDIFF(MONTH, p.birth_date, CURDATE()) BETWEEN 7 AND 12 THEN '7-12 months'
            WHEN TIMESTAMPDIFF(MONTH, p.birth_date, CURDATE()) BETWEEN 13 AND 18 THEN '13-18 months'
            WHEN TIMESTAMPDIFF(MONTH, p.birth_date, CURDATE()) BETWEEN 19 AND 24 THEN '19-24 months'
            WHEN TIMESTAMPDIFF(MONTH, p.birth_date, CURDATE()) > 24 THEN '2+ years'
            ELSE 'Unknown'
        END AS age_group,
        COUNT(DISTINCT vr.patient_id) AS vaccinated_count
    FROM vaccination_records vr
    INNER JOIN patient_profiles p ON p.user_id = vr.patient_id
    INNER JOIN users u ON u.id = vr.patient_id
    WHERE u.role = 'patient' AND u.status = 'active' AND p.birth_date IS NOT NULL
    GROUP BY age_group
";
$stmt = $mysqli->prepare($ageQuery);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (isset($ageStats[$row['age_group']])) {
            $ageStats[$row['age_group']] = (int)$row['vaccinated_count'];
        }
    }
    $stmt->close();
}

// Get vaccination counts for today, this week, and this month
$todayCount = 0;
$weekCount = 0;
$monthCount = 0;

// Today
$todayQuery = "SELECT COUNT(DISTINCT patient_id) AS count FROM vaccination_records WHERE DATE(date_given) = CURDATE()";
$stmt = $mysqli->prepare($todayQuery);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $todayCount = (int)$row['count'];
    }
    $stmt->close();
}

// This week
$weekQuery = "SELECT COUNT(DISTINCT patient_id) AS count FROM vaccination_records WHERE YEARWEEK(date_given, 1) = YEARWEEK(CURDATE(), 1)";
$stmt = $mysqli->prepare($weekQuery);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $weekCount = (int)$row['count'];
    }
    $stmt->close();
}

// This month
$monthQuery = "SELECT COUNT(DISTINCT patient_id) AS count FROM vaccination_records WHERE YEAR(date_given) = YEAR(CURDATE()) AND MONTH(date_given) = MONTH(CURDATE())";
$stmt = $mysqli->prepare($monthQuery);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $monthCount = (int)$row['count'];
    }
    $stmt->close();
}

// Get vaccination coverage statistics for health workers
$healthWorkerId = $_SESSION['user_id'] ?? 0;
$healthWorkerCoverage = [
    'total_patients_vaccinated' => 0,
    'total_vaccinations_given' => 0,
    'today_vaccinations' => 0,
    'week_vaccinations' => 0,
    'month_vaccinations' => 0,
    'coverage_percentage' => 0
];

// Total patients vaccinated by this health worker (through completed appointments)
$coverageQuery = "
    SELECT COUNT(DISTINCT a.patient_id) AS total_patients,
           COUNT(DISTINCT CONCAT(a.patient_id, '-', a.vaccine_id, '-', DATE(a.scheduled_at))) AS total_vaccinations
    FROM appointments a
    WHERE a.health_worker_id = ? 
    AND a.status = 'completed'
    AND a.vaccine_id IS NOT NULL
";
$stmt = $mysqli->prepare($coverageQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $healthWorkerCoverage['total_patients_vaccinated'] = (int)$row['total_patients'];
        $healthWorkerCoverage['total_vaccinations_given'] = (int)$row['total_vaccinations'];
    }
    $stmt->close();
}

// Today's vaccinations by this health worker
$todayCoverageQuery = "
    SELECT COUNT(DISTINCT a.patient_id) AS count
    FROM appointments a
    WHERE a.health_worker_id = ? 
    AND a.status = 'completed'
    AND DATE(a.scheduled_at) = CURDATE()
    AND a.vaccine_id IS NOT NULL
";
$stmt = $mysqli->prepare($todayCoverageQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $healthWorkerCoverage['today_vaccinations'] = (int)$row['count'];
    }
    $stmt->close();
}

// This week's vaccinations by this health worker
$weekCoverageQuery = "
    SELECT COUNT(DISTINCT a.patient_id) AS count
    FROM appointments a
    WHERE a.health_worker_id = ? 
    AND a.status = 'completed'
    AND YEARWEEK(a.scheduled_at, 1) = YEARWEEK(CURDATE(), 1)
    AND a.vaccine_id IS NOT NULL
";
$stmt = $mysqli->prepare($weekCoverageQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $healthWorkerCoverage['week_vaccinations'] = (int)$row['count'];
    }
    $stmt->close();
}

// This month's vaccinations by this health worker
$monthCoverageQuery = "
    SELECT COUNT(DISTINCT a.patient_id) AS count
    FROM appointments a
    WHERE a.health_worker_id = ? 
    AND a.status = 'completed'
    AND YEAR(a.scheduled_at) = YEAR(CURDATE()) 
    AND MONTH(a.scheduled_at) = MONTH(CURDATE())
    AND a.vaccine_id IS NOT NULL
";
$stmt = $mysqli->prepare($monthCoverageQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $healthWorkerCoverage['month_vaccinations'] = (int)$row['count'];
    }
    $stmt->close();
}

// Calculate coverage percentage (patients vaccinated / total active patients assigned to this health worker)
$totalAssignedPatientsQuery = "
    SELECT COUNT(DISTINCT a.patient_id) AS total
    FROM appointments a
    INNER JOIN users u ON u.id = a.patient_id
    WHERE a.health_worker_id = ? 
    AND u.role = 'patient' 
    AND u.status = 'active'
";
$stmt = $mysqli->prepare($totalAssignedPatientsQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $totalAssigned = (int)$row['total'];
        if ($totalAssigned > 0) {
            $healthWorkerCoverage['coverage_percentage'] = round(
                ($healthWorkerCoverage['total_patients_vaccinated'] / $totalAssigned) * 100, 
                1
            );
        }
    }
    $stmt->close();
}

// Get vaccine-specific statistics for this health worker
$vaccineStats = [];
$vaccineStatsQuery = "
    SELECT 
        v.id,
        v.name,
        COUNT(DISTINCT a.patient_id) AS patients_count,
        COUNT(a.id) AS vaccinations_count
    FROM appointments a
    INNER JOIN vaccines v ON v.id = a.vaccine_id
    WHERE a.health_worker_id = ? 
    AND a.status = 'completed'
    AND a.vaccine_id IS NOT NULL
    GROUP BY v.id, v.name
    ORDER BY vaccinations_count DESC, v.name ASC
";
$stmt = $mysqli->prepare($vaccineStatsQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $vaccineStats[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'patients_count' => (int)$row['patients_count'],
            'vaccinations_count' => (int)$row['vaccinations_count']
        ];
    }
    $stmt->close();
} else {
    // Log error if query preparation fails
    error_log("Failed to prepare vaccine stats query: " . $mysqli->error);
}

// Get list of patients vaccinated by this health worker
$totalPatientsList = [];
$totalPatientsListQuery = "
    SELECT DISTINCT
        u.id,
        COALESCE(u.full_name, p.child_name) AS patient_name,
        p.guardian_name,
        COUNT(DISTINCT a.id) AS vaccination_count
    FROM appointments a
    INNER JOIN users u ON u.id = a.patient_id
    INNER JOIN patient_profiles p ON p.user_id = u.id
    WHERE a.health_worker_id = ? 
    AND a.status = 'completed'
    AND a.vaccine_id IS NOT NULL
    GROUP BY u.id, u.full_name, p.child_name, p.guardian_name
    ORDER BY patient_name ASC
";
$stmt = $mysqli->prepare($totalPatientsListQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $totalPatientsList[] = [
            'id' => (int)$row['id'],
            'patient_name' => $row['patient_name'],
            'guardian_name' => $row['guardian_name'],
            'vaccination_count' => (int)$row['vaccination_count']
        ];
    }
    $stmt->close();
}

// Get list of all vaccinations given by this health worker
$totalVaccinationsList = [];
$totalVaccinationsListQuery = "
    SELECT 
        a.id,
        COALESCE(u.full_name, p.child_name) AS patient_name,
        v.name AS vaccine_name,
        a.dosage,
        DATE(a.scheduled_at) AS vaccination_date
    FROM appointments a
    INNER JOIN users u ON u.id = a.patient_id
    INNER JOIN patient_profiles p ON p.user_id = u.id
    INNER JOIN vaccines v ON v.id = a.vaccine_id
    WHERE a.health_worker_id = ? 
    AND a.status = 'completed'
    AND a.vaccine_id IS NOT NULL
    ORDER BY a.scheduled_at DESC
";
$stmt = $mysqli->prepare($totalVaccinationsListQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $totalVaccinationsList[] = [
            'id' => (int)$row['id'],
            'patient_name' => $row['patient_name'],
            'vaccine_name' => $row['vaccine_name'],
            'dosage' => $row['dosage'],
            'vaccination_date' => $row['vaccination_date']
        ];
    }
    $stmt->close();
}

// Get list of patients vaccinated this month by this health worker
$monthPatientsList = [];
$monthPatientsListQuery = "
    SELECT DISTINCT
        u.id,
        COALESCE(u.full_name, p.child_name) AS patient_name,
        p.guardian_name,
        COUNT(DISTINCT a.id) AS vaccination_count,
        MAX(DATE(a.scheduled_at)) AS last_vaccination_date
    FROM appointments a
    INNER JOIN users u ON u.id = a.patient_id
    INNER JOIN patient_profiles p ON p.user_id = u.id
    WHERE a.health_worker_id = ? 
    AND a.status = 'completed'
    AND a.vaccine_id IS NOT NULL
    AND YEAR(a.scheduled_at) = YEAR(CURDATE()) 
    AND MONTH(a.scheduled_at) = MONTH(CURDATE())
    GROUP BY u.id, u.full_name, p.child_name, p.guardian_name
    ORDER BY last_vaccination_date DESC, patient_name ASC
";
$stmt = $mysqli->prepare($monthPatientsListQuery);
if ($stmt) {
    $stmt->bind_param('i', $healthWorkerId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthPatientsList[] = [
            'id' => (int)$row['id'],
            'patient_name' => $row['patient_name'],
            'guardian_name' => $row['guardian_name'],
            'vaccination_count' => (int)$row['vaccination_count'],
            'last_vaccination_date' => $row['last_vaccination_date']
        ];
    }
    $stmt->close();
}
?>

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>HCNVMS Reports</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com" rel="preconnect" />
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect" />
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,100..900;1,100..900&amp;display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .material-symbols-outlined {
            font-variation-settings:
                'FILL' 0,
                'wght' 400,
                'GRAD' 0,
                'opsz' 24
        }
    </style>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#2b8cee",
                        "background-light": "#f6f7f8",
                        "background-dark": "#101922",
                    },
                    fontFamily: {
                        "display": ["Public Sans", "sans-serif"]
                    },
                    borderRadius: {
                        "DEFAULT": "0.25rem",
                        "lg": "0.5rem",
                        "xl": "0.75rem",
                        "full": "9999px"
                    },
                },
            },
        }
    </script>
</head>

<body class="bg-background-light dark:bg-background-dark font-display">
    <div class="flex h-screen w-full">
        <aside
            class="flex w-64 flex-col border-r border-slate-200 dark:border-slate-800 bg-white dark:bg-background-dark">
            <div class="flex h-16 shrink-0 items-center gap-3 px-6 text-primary">
                <span class="material-symbols-outlined text-3xl">vaccines</span>
                <h2 class="text-lg font-bold leading-tight tracking-[-0.015em] text-slate-900 dark:text-white">HCNVMS
                </h2>
            </div>
            <div class="flex flex-col justify-between h-full p-4">
                <div class="flex flex-col gap-2">
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        href="/HealthCenter/health_worker/hw_dashboard.php">
                        <span class="material-symbols-outlined">dashboard</span>
                        <p class="text-sm font-medium leading-normal">Dashboard</p>
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        href="/HealthCenter/health_worker/hw_patient.php">
                        <span class="material-symbols-outlined">groups</span>
                        <p class="text-sm font-medium leading-normal">Patients</p>
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        href="/HealthCenter/health_worker/hw_schedule.php">
                        <span class="material-symbols-outlined">calendar_month</span>
                        <p class="text-sm font-medium leading-normal">Schedule</p>
                    </a>
                    <a class="flex items-center gap-3 rounded-lg bg-primary/10 dark:bg-primary/20 px-3 py-2 text-primary"
                        href="/HealthCenter/health_worker/hw_reports.php">
                        <span class="material-symbols-outlined">monitoring</span>
                        <p class="text-sm font-medium leading-normal">Reports</p>
                    </a>
                </div>
                    <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                            href="/HealthCenter/health_worker/hw_settings.php">
                            <span class="material-symbols-outlined">settings</span>
                            <p class="text-sm font-medium leading-normal">Settings</p>
                        </a>
                        <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                            href="/HealthCenter/auth/logout.php">
                            <span class="material-symbols-outlined">logout</span>
                            <p class="text-sm font-medium leading-normal">Logout</p>
                        </a>
                    </div>
                    <div class="border-t border-slate-200 dark:border-slate-800 pt-4">
                        <div class="flex items-center gap-3">
                            <div class="flex flex-col">
                                <h1 class="text-slate-900 dark:text-white text-sm font-medium leading-normal">Dr. Rjay
                                    Enriquez</h1>
                                <p class="text-slate-500 dark:text-slate-400 text-xs font-normal leading-normal">Health
                                    Worker</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
        <main class="flex-1 overflow-y-auto">
            <header
                class="sticky top-0 z-10 flex h-16 items-center justify-end border-b border-solid border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-background-dark/80 backdrop-blur-sm px-6">
                <div class="flex items-center gap-4">
                    <button
                        class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-transparent text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
                        <span class="material-symbols-outlined">notifications</span>
                    </button>
                    <div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-10"
                        data-alt="Profile picture of Dr. Eleanor Vance" style="background-image: none;"></div>
                </div>
            </header>
            <div class="p-6 md:p-8">
                <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                    <div class="flex flex-col gap-1">
                        <p
                            class="text-2xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white lg:text-3xl">
                            Reports</p>
                        <p class="text-slate-600 dark:text-slate-400 text-base font-normal leading-normal">Generate and
                            view reports on vaccination activities.</p>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <!-- Today's Vaccinations -->
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-400 font-medium">Today</p>
                                <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?= $todayCount ?></p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Patients vaccinated</p>
                            </div>
                            <div class="rounded-full bg-blue-100 dark:bg-blue-900/30 p-3">
                                <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-2xl">today</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- This Week's Vaccinations -->
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-400 font-medium">This Week</p>
                                <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?= $weekCount ?></p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Patients vaccinated</p>
                            </div>
                            <div class="rounded-full bg-green-100 dark:bg-green-900/30 p-3">
                                <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-2xl">date_range</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- This Month's Vaccinations -->
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-400 font-medium">This Month</p>
                                <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?= $monthCount ?></p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Patients vaccinated</p>
                            </div>
                            <div class="rounded-full bg-purple-100 dark:bg-purple-900/30 p-3">
                                <span class="material-symbols-outlined text-purple-600 dark:text-purple-400 text-2xl">calendar_month</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Gender Chart -->
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Vaccinations by Gender</h3>
                            <span class="text-xs text-slate-500 dark:text-slate-400">
                                Total: <span class="font-bold text-slate-900 dark:text-white"><?= $genderStats['Male'] + $genderStats['Female'] ?></span>
                            </span>
                        </div>
                        <div class="relative h-48">
                            <canvas id="genderChart"></canvas>
                        </div>
                        <div class="mt-3 flex items-center justify-center gap-6 pt-3 border-t border-slate-200 dark:border-slate-800">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-full bg-blue-500"></div>
                                <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Male: <?= $genderStats['Male'] ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-full bg-pink-500"></div>
                                <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Female: <?= $genderStats['Female'] ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Age Group Chart -->
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Vaccinations by Age</h3>
                            <span class="text-xs text-slate-500 dark:text-slate-400">
                                Total: <span class="font-bold text-slate-900 dark:text-white"><?= array_sum($ageStats) ?></span>
                            </span>
                        </div>
                        <div class="relative h-48">
                            <canvas id="ageChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Time-based Chart -->
                <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 p-4 mb-6">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white mb-3">Vaccinations This Period</h3>
                    <div class="relative h-56">
                        <canvas id="timeChart"></canvas>
                    </div>
                    <div class="mt-3 flex items-center justify-center gap-8 pt-3 border-t border-slate-200 dark:border-slate-800">
                        <div class="text-center">
                            <p class="text-xl font-bold text-blue-600 dark:text-blue-400"><?= $todayCount ?></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Today</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xl font-bold text-green-600 dark:text-green-400"><?= $weekCount ?></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">This Week</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xl font-bold text-purple-600 dark:text-purple-400"><?= $monthCount ?></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">This Month</p>
                        </div>
                    </div>
                </div>
                
                <!-- Health Worker Vaccination Coverage -->
                <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 p-4 mb-6">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white mb-4">Your Vaccination Coverage</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <!-- Total Patients Vaccinated -->
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-xs text-blue-600 dark:text-blue-400 font-medium">Total Patients Vaccinated</p>
                                <button type="button" onclick="toggleList('totalPatientsList')" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                                    <span class="material-symbols-outlined text-sm" id="totalPatientsListIcon">expand_more</span>
                                </button>
                            </div>
                            <p class="text-2xl font-bold text-blue-700 dark:text-blue-300"><?= $healthWorkerCoverage['total_patients_vaccinated'] ?></p>
                            <!-- Expandable List -->
                            <div id="totalPatientsList" class="hidden mt-3 pt-3 border-t border-blue-200 dark:border-blue-700 max-h-60 overflow-y-auto">
                                <?php if (!empty($totalPatientsList)): ?>
                                    <ul class="space-y-2 text-xs">
                                        <?php foreach ($totalPatientsList as $patient): ?>
                                            <li class="flex items-center justify-between text-blue-800 dark:text-blue-200">
                                                <span class="truncate"><?= htmlspecialchars($patient['patient_name']) ?></span>
                                                <span class="ml-2 text-blue-600 dark:text-blue-400"><?= $patient['vaccination_count'] ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-xs text-blue-600 dark:text-blue-400">No patients vaccinated yet</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Total Vaccinations Given -->
                        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-xs text-green-600 dark:text-green-400 font-medium">Total Vaccinations Given</p>
                                <button type="button" onclick="toggleList('totalVaccinationsList')" class="text-green-600 dark:text-green-400 hover:text-green-700 dark:hover:text-green-300">
                                    <span class="material-symbols-outlined text-sm" id="totalVaccinationsListIcon">expand_more</span>
                                </button>
                            </div>
                            <p class="text-2xl font-bold text-green-700 dark:text-green-300"><?= $healthWorkerCoverage['total_vaccinations_given'] ?></p>
                            <!-- Expandable List -->
                            <div id="totalVaccinationsList" class="hidden mt-3 pt-3 border-t border-green-200 dark:border-green-700 max-h-60 overflow-y-auto">
                                <?php if (!empty($totalVaccinationsList)): ?>
                                    <ul class="space-y-2 text-xs">
                                        <?php foreach (array_slice($totalVaccinationsList, 0, 20) as $vaccination): ?>
                                            <li class="text-green-800 dark:text-green-200">
                                                <div class="flex items-start justify-between">
                                                    <div class="flex-1 min-w-0">
                                                        <p class="font-medium truncate"><?= htmlspecialchars($vaccination['patient_name']) ?></p>
                                                        <p class="text-green-600 dark:text-green-400"><?= htmlspecialchars($vaccination['vaccine_name']) ?> - <?= htmlspecialchars($vaccination['dosage'] ?? 'N/A') ?></p>
                                                        <p class="text-green-500 dark:text-green-500 text-xs"><?= date('M d, Y', strtotime($vaccination['vaccination_date'])) ?></p>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                        <?php if (count($totalVaccinationsList) > 20): ?>
                                            <p class="text-xs text-green-600 dark:text-green-400 mt-2">... and <?= count($totalVaccinationsList) - 20 ?> more</p>
                                        <?php endif; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-xs text-green-600 dark:text-green-400">No vaccinations given yet</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Coverage Rate -->
                        <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4 border border-purple-200 dark:border-purple-800">
                            <p class="text-xs text-purple-600 dark:text-purple-400 font-medium mb-1">Coverage Rate</p>
                            <p class="text-2xl font-bold text-purple-700 dark:text-purple-300"><?= $healthWorkerCoverage['coverage_percentage'] ?>%</p>
                        </div>
                        
                        <!-- This Month -->
                        <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4 border border-orange-200 dark:border-orange-800">
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-xs text-orange-600 dark:text-orange-400 font-medium">This Month</p>
                                <button type="button" onclick="toggleList('monthPatientsList')" class="text-orange-600 dark:text-orange-400 hover:text-orange-700 dark:hover:text-orange-300">
                                    <span class="material-symbols-outlined text-sm" id="monthPatientsListIcon">expand_more</span>
                                </button>
                            </div>
                            <p class="text-2xl font-bold text-orange-700 dark:text-orange-300"><?= $healthWorkerCoverage['month_vaccinations'] ?></p>
                            <p class="text-xs text-orange-600 dark:text-orange-400 mt-1">patients</p>
                            <!-- Expandable List -->
                            <div id="monthPatientsList" class="hidden mt-3 pt-3 border-t border-orange-200 dark:border-orange-700 max-h-60 overflow-y-auto">
                                <?php if (!empty($monthPatientsList)): ?>
                                    <ul class="space-y-2 text-xs">
                                        <?php foreach ($monthPatientsList as $patient): ?>
                                            <li class="flex items-center justify-between text-orange-800 dark:text-orange-200">
                                                <div class="flex-1 min-w-0">
                                                    <p class="font-medium truncate"><?= htmlspecialchars($patient['patient_name']) ?></p>
                                                    <p class="text-orange-600 dark:text-orange-400 text-xs"><?= date('M d, Y', strtotime($patient['last_vaccination_date'])) ?></p>
                                                </div>
                                                <span class="ml-2 text-orange-600 dark:text-orange-400"><?= $patient['vaccination_count'] ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-xs text-orange-600 dark:text-orange-400">No patients vaccinated this month</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-800">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Today: <span class="font-semibold text-slate-900 dark:text-white"><?= $healthWorkerCoverage['today_vaccinations'] ?></span> patients</span>
                            <span class="text-slate-600 dark:text-slate-400">This Week: <span class="font-semibold text-slate-900 dark:text-white"><?= $healthWorkerCoverage['week_vaccinations'] ?></span> patients</span>
                        </div>
                    </div>
                </div>
                
                <!-- Vaccine Breakdown -->
                <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 p-4 mb-6">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white mb-4">Vaccines Administered</h3>
                    <?php if (!empty($vaccineStats)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800">
                                <tr>
                                    <th class="px-4 py-3 font-medium" scope="col">Vaccine Name</th>
                                    <th class="px-4 py-3 font-medium text-center" scope="col">Patients</th>
                                    <th class="px-4 py-3 font-medium text-center" scope="col">Total Doses</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php foreach ($vaccineStats as $vaccine): ?>
                                <tr class="bg-white hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800/50">
                                    <td class="px-4 py-3 font-medium text-slate-900 dark:text-white">
                                        <?= htmlspecialchars($vaccine['name']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-center text-slate-600 dark:text-slate-300">
                                        <?= $vaccine['patients_count'] ?>
                                    </td>
                                    <td class="px-4 py-3 text-center text-slate-600 dark:text-slate-300">
                                        <?= $vaccine['vaccinations_count'] ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No vaccines administered yet. Complete appointments with vaccines to see statistics here.</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="border-b border-slate-200 p-4 dark:border-slate-800">
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white" id="reportTitle">Vaccination
                            Completion Report</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400" id="reportSubtitle">Select filters to
                            generate report</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead
                                class="text-xs uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800">
                                <tr>
                                    <th class="px-6 py-3 font-medium" scope="col">Patient ID</th>
                                    <th class="px-6 py-3 font-medium" scope="col">Patient Name</th>
                                    <th class="px-6 py-3 font-medium" scope="col">Vaccine Type</th>
                                    <th class="px-6 py-3 font-medium" scope="col">Dosage</th>
                                    <th class="px-6 py-3 font-medium" scope="col">Guardian</th>
                                    <th class="px-6 py-3 font-medium" scope="col">Contact</th>
                                    <th class="px-6 py-3 font-medium" scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php if (!empty($reportsList)): ?>
                                <?php 
    // Group records by patient so each patient appears only once in the report table
    $groupedRecords = [];
    foreach ($reportsList as $r) {
        $patientId = $r['patient_id'];
        if (!isset($groupedRecords[$patientId])) {
            $groupedRecords[$patientId] = $r;
        }
    }
    
    // Display one row per patient
    foreach ($groupedRecords as $r): 
        $statusClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300';
        if (($r['vaccination_status'] ?? '') === 'Fully Vaccinated') {
            $statusClass = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
        } elseif (($r['vaccination_status'] ?? '') === 'Not Started') {
            $statusClass = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
        }
        
        $completionPercent = min(100, max(0, (float)($r['completion_percentage'] ?? 0)));
        $progressColor = $completionPercent == 100 ? 'bg-green-500' : ($completionPercent > 50 ? 'bg-blue-500' : 'bg-yellow-500');
    ?>
                                <tr class="bg-white hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800/50">
                                    <td
                                        class="whitespace-nowrap px-6 py-4 font-mono text-slate-600 dark:text-slate-400">
                                        <?php echo htmlspecialchars($r['patient_code'] ?? ''); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 font-medium text-slate-900 dark:text-white">
                                        <?php echo htmlspecialchars($r['patient_name'] ?? ''); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-slate-600 dark:text-slate-300">
                                        <?php 
                // Get vaccine name from the appointment
                $vaccineName = 'N/A';
                if (!empty($r['vaccine_name'])) {
                    $vaccineName = htmlspecialchars($r['vaccine_name']);
                } elseif (!empty($r['received_vaccines'])) {
                    // Fallback to received vaccines if no specific vaccine is set
                    $vaccineName = htmlspecialchars(explode(',', $r['received_vaccines'])[0]);
                }
                echo $vaccineName;
                ?>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-slate-600 dark:text-slate-300">
                                        <?php 
                // Current / last dosage from appointment
                $currentDosage = !empty($r['dosage']) ? htmlspecialchars($r['dosage']) : 'N/A';
                echo $currentDosage;

                // Per-vaccine progress: how many doses already, and what's next
                $nextInfo = null;
                if (!empty($r['vaccine_id'])) {
                    $nextInfo = get_next_dosage_for_patient_vaccine($mysqli, (int) $r['patient_id'], (int) $r['vaccine_id']);
                }

                if (!empty($nextInfo)) {
                    $taken = (int) ($nextInfo['max_dose'] ?? 0);
                    $nextDoseLabel = $nextInfo['next_dosage'] ?? '';
                    echo '<br><span class="block text-xs text-slate-500 dark:text-slate-400">';
                    if ($taken > 0) {
                        echo 'Doses received: ' . $taken . '. ';
                    } else {
                        echo 'No doses received yet. ';
                    }
                    if (!empty($nextDoseLabel)) {
                        echo 'Next: ' . htmlspecialchars($nextDoseLabel);
                    } else {
                        echo 'Series may be complete.';
                    }
                    echo '</span>';
                }
                ?>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-slate-600 dark:text-slate-300">
                                        <?php echo htmlspecialchars($r['guardian_name'] ?? ''); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-slate-600 dark:text-slate-300">
                                        <?php echo !empty($r['contact_number']) ? htmlspecialchars($r['contact_number']) : 'N/A'; ?>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($r['vaccination_status'] ?? 'Unknown'); ?>
                                            <?php if (!empty($r['vaccines_received_count']) && !empty($r['total_vaccines'])): ?>
                                            <span class="ml-1">(
                                                <?php echo (int)$r['vaccines_received_count'] . '/' . (int)$r['total_vaccines']; ?>)</span>
                                            <?php endif; ?>
                                        </span>

                                        <?php if (!empty($r['pending_vaccines'])): ?>
                                        <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                            Pending:
                                            <?php echo htmlspecialchars($r['pending_vaccines']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td class="px-6 py-4" colspan="5">No report data available.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-200 p-4 dark:border-slate-800">
                        <span class="text-sm text-slate-600 dark:text-slate-400" id="resultCount">Showing
                            <?php 
                            // Ensure the count shown matches the actual number of rows displayed
                            echo isset($groupedRecords) ? count($groupedRecords) : count($reportsList); 
                            ?> results
                        </span>
                        <div class="inline-flex rounded-md shadow-sm">
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
        function updateReport() {
            const reportType = document.getElementById('report-type').value;
            const vaccineProgram = document.getElementById('vaccine-program').value;

            // Only proceed if report type is explicitly selected
            if (!reportType) {
                alert('Please select a Report Type before clicking Select');
                return;
            }

            // Update header immediately
            let reportTitle = 'Vaccination Completion Report';
            let reportSubtitle = 'Select filters to generate report';
            if (reportType === 'vaccination_completion') {
                reportTitle = 'Vaccination Completion Report';
                reportSubtitle = 'Showing data for ' + vaccineProgram;
            } else if (reportType === 'missed_appointments') {
                reportTitle = 'Missed Appointments Report';
                reportSubtitle = 'Showing missed/cancelled appointments';
            } else if (reportType === 'vaccine_inventory') {
                reportTitle = 'Vaccine Inventory Report';
                reportSubtitle = 'Current vaccine stock levels';
            }
            document.getElementById('reportTitle').textContent = reportTitle;
            document.getElementById('reportSubtitle').textContent = reportSubtitle;

            // Fetch data via AJAX and update table without page reload
            const params = new URLSearchParams();
            params.append('report_type', reportType);
            params.append('vaccine_program', vaccineProgram);

            fetch('api/get_report.php?' + params.toString(), { credentials: 'same-origin' })
                .then(resp => resp.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    document.getElementById('resultCount').textContent = 'Showing ' + (data.count || 0) + ' results';
                    const tbody = document.querySelector('table tbody');
                    tbody.innerHTML = '';
                    if (data.rows && data.rows.length) {
                        data.rows.forEach(r => {
                            const tr = document.createElement('tr');
                            tr.className = 'bg-white hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800/50';

                            const idTd = document.createElement('td');
                            idTd.className = 'whitespace-nowrap px-6 py-4 font-mono text-slate-600 dark:text-slate-400';
                            idTd.textContent = r.patient_code || r.patient_id || '';

                            const nameTd = document.createElement('td');
                            nameTd.className = 'whitespace-nowrap px-6 py-4 font-medium text-slate-900 dark:text-white';
                            nameTd.textContent = r.patient_name || '';

                            const vaccineTd = document.createElement('td');
                            vaccineTd.className = 'px-6 py-4 text-slate-600 dark:text-slate-300';
                            vaccineTd.textContent = r.vaccine || '';

                            const dateTd = document.createElement('td');
                            dateTd.className = 'px-6 py-4 text-slate-600 dark:text-slate-300';
                            dateTd.textContent = (r.administered_at || '').substring(0, 10) || '';

                            const statusTd = document.createElement('td');
                            statusTd.className = 'px-6 py-4';
                            const span = document.createElement('span');
                            span.className = 'inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-300';
                            span.textContent = r.status || 'Completed';
                            statusTd.appendChild(span);

                            tr.appendChild(idTd);
                            tr.appendChild(nameTd);
                            tr.appendChild(vaccineTd);
                            tr.appendChild(dateTd);
                            tr.appendChild(statusTd);
                            tbody.appendChild(tr);
                        });
                    } else {
                        const tr = document.createElement('tr');
                        const td = document.createElement('td');
                        td.setAttribute('colspan', '5');
                        td.className = 'px-6 py-4';
                        td.textContent = 'No report data available.';
                        tr.appendChild(td);
                        tbody.appendChild(tr);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Failed to load report');
                });
        }

        // Initialize Charts - Simple and Professional
        // Toggle list function for expandable lists
        function toggleList(listId) {
            const list = document.getElementById(listId);
            const icon = document.getElementById(listId + 'Icon');
            
            if (list && icon) {
                if (list.classList.contains('hidden')) {
                    list.classList.remove('hidden');
                    icon.textContent = 'expand_less';
                } else {
                    list.classList.add('hidden');
                    icon.textContent = 'expand_more';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const textColor = isDark ? '#94a3b8' : '#64748b';
            const gridColor = isDark ? 'rgba(148, 163, 184, 0.1)' : 'rgba(100, 116, 139, 0.1)';
            
            // Gender Chart - Simple Doughnut
            const genderCtx = document.getElementById('genderChart');
            if (genderCtx) {
                const maleCount = <?= $genderStats['Male'] ?>;
                const femaleCount = <?= $genderStats['Female'] ?>;
                const total = maleCount + femaleCount;
                
                new Chart(genderCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Male', 'Female'],
                        datasets: [{
                            data: [maleCount, femaleCount],
                            backgroundColor: ['#3b82f6', '#ec4899'],
                            borderColor: isDark ? '#1e293b' : '#ffffff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 12,
                                    font: { size: 11 },
                                    color: textColor
                                }
                            },
                            tooltip: {
                                backgroundColor: isDark ? '#1e293b' : '#ffffff',
                                titleColor: isDark ? '#e2e8f0' : '#334155',
                                bodyColor: isDark ? '#e2e8f0' : '#334155',
                                borderColor: isDark ? '#334155' : '#e2e8f0',
                                borderWidth: 1,
                                padding: 10,
                                callbacks: {
                                    label: function(context) {
                                        const value = context.parsed;
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return context.label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Age Group Chart - Horizontal Bar with Clear Labels
            const ageCtx = document.getElementById('ageChart');
            if (ageCtx) {
                const ageLabels = [<?php echo "'" . implode("','", array_keys($ageStats)) . "'"; ?>];
                const ageData = [<?php echo implode(',', array_values($ageStats)); ?>];
                
                new Chart(ageCtx, {
                    type: 'bar',
                    data: {
                        labels: ageLabels,
                        datasets: [{
                            data: ageData,
                            backgroundColor: 'rgba(43, 140, 238, 0.7)',
                            borderColor: 'rgba(43, 140, 238, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: isDark ? '#1e293b' : '#ffffff',
                                titleColor: isDark ? '#e2e8f0' : '#334155',
                                bodyColor: isDark ? '#e2e8f0' : '#334155',
                                borderColor: isDark ? '#334155' : '#e2e8f0',
                                borderWidth: 1,
                                padding: 10,
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.x + ' patients';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    color: textColor,
                                    font: { size: 11 }
                                },
                                grid: { color: gridColor }
                            },
                            y: {
                                ticks: {
                                    color: textColor,
                                    font: { size: 11 }
                                },
                                grid: { display: false }
                            }
                        }
                    }
                });
            }

            // Time-based Chart - Simple Bar Chart
            const timeCtx = document.getElementById('timeChart');
            if (timeCtx) {
                new Chart(timeCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Today', 'This Week', 'This Month'],
                        datasets: [{
                            data: [<?= $todayCount ?>, <?= $weekCount ?>, <?= $monthCount ?>],
                            backgroundColor: ['rgba(59, 130, 246, 0.7)', 'rgba(34, 197, 94, 0.7)', 'rgba(168, 85, 247, 0.7)'],
                            borderColor: ['rgba(59, 130, 246, 1)', 'rgba(34, 197, 94, 1)', 'rgba(168, 85, 247, 1)'],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: isDark ? '#1e293b' : '#ffffff',
                                titleColor: isDark ? '#e2e8f0' : '#334155',
                                bodyColor: isDark ? '#e2e8f0' : '#334155',
                                borderColor: isDark ? '#334155' : '#e2e8f0',
                                borderWidth: 1,
                                padding: 10,
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y + ' patients';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    color: textColor,
                                    font: { size: 11 }
                                },
                                grid: { color: gridColor }
                            },
                            x: {
                                ticks: {
                                    color: textColor,
                                    font: { size: 11 }
                                },
                                grid: { display: false }
                            }
                        }
                    }
                });
            }
        });

        // Only trigger report when the user clicks the Select button.
        document.getElementById('generateBtn')?.addEventListener('click', updateReport);
    </script>
</body>

</html>