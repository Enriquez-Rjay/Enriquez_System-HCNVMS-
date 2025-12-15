<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Ensure user is logged in and is a patient
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
    header('Location: /HealthCenter/login.php');
    exit;
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

$patient_id = (int) $_SESSION['user_id'];

// Get patient profile information
$profile = [];
$stmt = $mysqli->prepare("SELECT child_name, birth_date, guardian_name, address FROM patient_profiles WHERE user_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $profile = $res->fetch_assoc() ?: [];
    $stmt->close();
}

// Fetch appointments for this patient with vaccine and health worker info
// This includes ALL appointments for the patient, whether created by health worker or patient
$appointments = [];
$stmt = $mysqli->prepare("
    SELECT 
        a.id, 
        a.scheduled_at, 
        a.status, 
        a.notes, 
        a.created_at,
        a.dosage,
        v.name AS vaccine_name,
        v.id AS vaccine_id,
        u.full_name AS health_worker_name
    FROM appointments a
    LEFT JOIN vaccines v ON v.id = a.vaccine_id
    LEFT JOIN users u ON u.id = a.health_worker_id
    WHERE a.patient_id = ? 
    ORDER BY a.scheduled_at DESC
");
if ($stmt) {
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $appointments[] = $row;
    }
    $stmt->close();
}

// Fetch vaccination history
$vaccination_history = [];
$stmt = $mysqli->prepare("
    SELECT v.name AS vaccine_name, 
           vr.date_given, 
           vr.dose
    FROM vaccination_records vr 
    JOIN vaccines v ON v.id = vr.vaccine_id 
    WHERE vr.patient_id = ? 
    ORDER BY vr.date_given DESC
");
if ($stmt) {
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $vaccination_history[] = $row;
    }
    $stmt->close();
}

// Fetch all vaccines for the patient
$upcoming_vaccines = [];
$stmt = $mysqli->prepare("
    SELECT v.id, v.name, 
           (SELECT COUNT(*) FROM vaccination_records 
            WHERE vaccine_id = v.id AND patient_id = ?) as doses_received
    FROM vaccines v
    WHERE (SELECT COUNT(*) FROM vaccination_records 
           WHERE vaccine_id = v.id AND patient_id = ?) < 3
");
if ($stmt) {
    $stmt->bind_param('ii', $patient_id, $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $upcoming_vaccines[] = $row;
    }
    $stmt->close();
}

// Calculate vaccination status
$vaccinesTotal = 0;
$vaccinesReceived = 0;
if ($res = $mysqli->query('SELECT COUNT(*) AS c FROM vaccines')) {
    $r = $res->fetch_assoc();
    $vaccinesTotal = (int) ($r['c'] ?? 0);
}
$stmt = $mysqli->prepare('SELECT COUNT(DISTINCT vaccine_id) AS c FROM vaccination_records WHERE patient_id = ?');
if ($stmt) {
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $r = $res->fetch_assoc();
    $vaccinesReceived = (int) ($r['c'] ?? 0);
    $stmt->close();
}
$progressPercent = $vaccinesTotal > 0 ? round(($vaccinesReceived / $vaccinesTotal) * 100) : 0;

// Determine vaccination status
$vaccinationStatus = 'incomplete';
$vaccinationStatusText = 'Incomplete';
$vaccinationStatusColor = 'status-red';
if ($progressPercent >= 100) {
    $vaccinationStatus = 'complete';
    $vaccinationStatusText = 'Complete';
    $vaccinationStatusColor = 'status-green';
} elseif ($progressPercent >= 50) {
    $vaccinationStatus = 'partial';
    $vaccinationStatusText = 'Partially Complete';
    $vaccinationStatusColor = 'status-orange';
}

$now = time();
$upcoming_appointments = [];
$past_appointments = [];

// Separate appointments into upcoming and past
// Include ALL appointments created by health workers or patients
foreach ($appointments as $a) {
    $ts = strtotime($a['scheduled_at']);
    // Past appointments: completed, cancelled, or past scheduled date
    if ($a['status'] === 'completed' || $a['status'] === 'cancelled' || $ts < $now) {
        $past_appointments[] = $a;
    } else {
        // Upcoming appointments: scheduled or pending with future date
        if ($a['status'] === 'scheduled' || $a['status'] === 'pending') {
            $upcoming_appointments[] = $a;
        } else {
            // Other statuses go to past
            $past_appointments[] = $a;
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Appointments - HCNVMS</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,100..900;1,100..900&amp;display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
        rel="stylesheet" />
    <style>
        .material-symbols-outlined {
            font-variation-settings:
                'FILL' 0,
                'wght' 400,
                'GRAD' 0,
                'opsz' 24
        }
    </style>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#2b8cee",
                        "background-light": "#f6f7f8",
                        "background-dark": "#101922",
                        "success": "#7ED321",
                        "warning": "#F5A623",
                        "danger": "#D0021B",
                    },
                    fontFamily: {
                        "display": ["Public Sans", "sans-serif"]
                    },
                    borderRadius: { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" },
                },
            },
        }
    </script>
</head>

<body class="font-display bg-background-light dark:bg-background-dark text-[#111418] dark:text-gray-200">
    <div class="relative flex min-h-screen w-full flex-col group/design-root">
        <div class="flex flex-1">
            <!-- SideNavBar -->
            <aside
                class="sticky top-0 flex h-screen w-64 flex-col border-r border-border-light dark:border-border-dark bg-card-light dark:bg-card-dark p-4">
                <div class="flex flex-col gap-4">
                    <div class="flex items-center gap-3">
                        <img src="/HealthCenter/assets/hcnvms.png" alt="HCNVMS" class="h-10 w-auto" />
                        <div class="flex flex-col">
                            <h1 class="text-base font-bold">HCNVMS</h1>
                        </div>
                    </div>
                    <nav class="flex flex-col gap-2 mt-4">
                        <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
                            href="/HealthCenter/patient/p_dashboard.php">
                            <span class="material-symbols-outlined">dashboard</span>
                            <p class="text-sm font-medium">Dashboard</p>
                        </a>
                        <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
                            href="/HealthCenter/patient/vaccination_record.php">
                            <span class="material-symbols-outlined">vaccines</span>
                            <p class="text-sm font-medium">Vaccination Record</p>
                        </a>
                        <a class="flex items-center gap-3 px-3 py-2 rounded-lg bg-primary/10 text-primary dark:bg-primary/20"
                            href="/HealthCenter/patient/p_appointments.php">
                            <span class="material-symbols-outlined"
                                style="font-variation-settings: 'FILL' 1;">event_available</span>
                            <p class="text-sm font-medium">Appointments</p>
                        </a>
                    </nav>
                </div>
                <div class="mt-auto flex flex-col gap-1">
                    <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
                        href="/HealthCenter/patient/settings.php">
                        <span class="material-symbols-outlined">settings</span>
                        <p class="text-sm font-medium">Settings</p>
                    </a>
                    <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
                        href="/HealthCenter/auth/logout.php">
                        <span class="material-symbols-outlined">logout</span>
                        <p class="text-sm font-medium">Logout</p>
                    </a>
                </div>
            </aside>
            <!-- Main Content -->
            <main class="flex-1 p-6 lg:p-8">
                <header
                    class="border-b border-gray-200 bg-white px-8 py-3 dark:border-gray-800 dark:bg-background-dark">
                    <div class="mx-auto max-w-6xl">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <h1 class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white">Appointments</h1>
                            <button type="button" onclick="openAppointmentModal()"
                                class="flex items-center justify-center px-4 py-2 bg-primary hover:bg-primary/90 text-white rounded-lg shadow-sm transition-colors duration-200">
                                <span class="material-symbols-outlined text-base mr-2">add</span>
                                Schedule New Appointment
                            </button>
                        </div>

                        <!-- Upcoming Vaccines Section -->
                        <div class="mt-8">
                            <h3 class="text-lg font-bold text-[#111418] dark:text-white">Upcoming Vaccines</h3>
                            <div
                                class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Vaccine</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Dose</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                        <?php if (empty($upcoming_vaccines)): ?>
                                            <tr>
                                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400"
                                                    colspan="3">No upcoming vaccines scheduled.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($upcoming_vaccines as $vaccine):
                                                $next_dose = $vaccine['doses_received'] + 1;
                                                ?>
                                                <tr>
                                                    <td class="whitespace-nowrap px-6 py-4">
                                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                            <?= htmlspecialchars($vaccine['name']) ?></div>
                                                    </td>
                                                    <td
                                                        class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                        Dose <?= $next_dose ?>
                                                    </td>
                                                    <td class="whitespace-nowrap px-6 py-4">
                                                        <span
                                                            class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-800/30 dark:text-yellow-300">Pending</span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Upcoming Appointments Section -->
                        <div class="mt-8">
                            <h3 class="text-lg font-bold text-[#111418] dark:text-white">Upcoming Appointments</h3>
                            <div
                                class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Date & Time</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Vaccine</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Status</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                        <?php if (empty($upcoming_appointments)): ?>
                                            <tr>
                                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400"
                                                    colspan="4">No upcoming appointments.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($upcoming_appointments as $appt): ?>
                                                <tr>
                                                    <td class="whitespace-nowrap px-6 py-4">
                                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                            <?= date('M j, Y', strtotime($appt['scheduled_at'])) ?></div>
                                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                                            <?= date('g:i A', strtotime($appt['scheduled_at'])) ?></div>
                                                    </td>
                                                    <td
                                                        class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                        <?php if (!empty($appt['vaccine_name'])): ?>
                                                            <div>
                                                                <span class="font-medium"><?= htmlspecialchars($appt['vaccine_name']) ?></span>
                                                                <?php if (!empty($appt['dosage'])): ?>
                                                                    <span class="text-xs text-gray-400">(<?= htmlspecialchars($appt['dosage']) ?>)</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if (!empty($appt['health_worker_name'])): ?>
                                                                <div class="text-xs text-gray-400 mt-1">
                                                                    Scheduled by: <?= htmlspecialchars($appt['health_worker_name']) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            General Checkup
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="whitespace-nowrap px-6 py-4">
                                                        <span
                                                            class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-800/30 dark:text-yellow-300"><?= ucfirst($appt['status']) ?></span>
                                                    </td>
                                                    <td class="whitespace-nowrap px-6 py-4 text-center">
                                                        <button type="button" onclick="cancelAppointment(<?= $appt['id'] ?>)"
                                                            class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300 text-sm font-medium px-3 py-1 rounded-md hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                                                            Cancel
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Vaccination History Section -->
                        <div class="mt-8">
                            <h3 class="text-lg font-bold text-[#111418] dark:text-white">Vaccination History</h3>
                            <div
                                class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Vaccine</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Date Administered</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Dose</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Next Dose Due</th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                        <?php if (empty($vaccination_history)): ?>
                                            <tr>
                                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400"
                                                    colspan="4">No vaccination records found.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($vaccination_history as $record): ?>
                                                <tr>
                                                    <td class="whitespace-nowrap px-6 py-4">
                                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                            <?= htmlspecialchars($record['vaccine_name']) ?></div>
                                                    </td>
                                                    <td
                                                        class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                        <?= date('M j, Y', strtotime($record['date_given'])) ?>
                                                    </td>
                                                    <td
                                                        class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                        <?= $record['dose'] ?>
                                                    </td>
                                                    <td
                                                        class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                        <?= !empty($record['next_due_date']) ? date('M j, Y', strtotime($record['next_due_date'])) : 'N/A' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Past Appointments Section -->
                        <div class="mt-8">
                            <h3 class="text-lg font-bold text-[#111418] dark:text-white">Past Appointments</h3>
                            <div
                                class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Date & Time</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Vaccine</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                                scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                        <?php if (empty($past_appointments)): ?>
                                            <tr>
                                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400"
                                                    colspan="3">No past appointments.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($past_appointments as $appt): ?>
                                                <tr>
                                                    <td class="whitespace-nowrap px-6 py-4">
                                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                            <?= date('M j, Y', strtotime($appt['scheduled_at'])) ?></div>
                                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                                            <?= date('g:i A', strtotime($appt['scheduled_at'])) ?></div>
                                                    </td>
                                                    <td
                                                        class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                        <?php if (!empty($appt['vaccine_name'])): ?>
                                                            <div>
                                                                <span class="font-medium"><?= htmlspecialchars($appt['vaccine_name']) ?></span>
                                                                <?php if (!empty($appt['dosage'])): ?>
                                                                    <span class="text-xs text-gray-400">(<?= htmlspecialchars($appt['dosage']) ?>)</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if (!empty($appt['health_worker_name'])): ?>
                                                                <div class="text-xs text-gray-400 mt-1">
                                                                    Scheduled by: <?= htmlspecialchars($appt['health_worker_name']) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            General Checkup
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="whitespace-nowrap px-6 py-4">
                                                        <?php if ($appt['status'] === 'completed'): ?>
                                                            <span
                                                                class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-800/30 dark:text-green-300">Completed</span>
                                                        <?php else: ?>
                                                            <span
                                                                class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-800/30 dark:text-red-300"><?= ucfirst($appt['status']) === 'Cancelled' ? 'Cancelled' : 'Missed' ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
        </div>

        <!-- Appointment Modal -->
        <div id="appointmentModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title"
            role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <!-- Background overlay -->
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"
                    onclick="closeAppointmentModal()"></div>

                <!-- Modal panel -->
                <div
                    class="inline-block transform overflow-hidden rounded-lg bg-white px-4 pt-5 pb-4 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 sm:align-middle">
                    <div class="absolute top-0 right-0 hidden pt-4 pr-4 sm:block">
                        <button type="button" onclick="closeAppointmentModal()"
                            class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                            <span class="sr-only">Close</span>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div>
                        <h3 class="text-lg font-medium leading-6 text-gray-900" id="modal-title">Schedule New
                            Appointment</h3>

                        <form id="appointmentForm" class="mt-4 space-y-4">
                            <!-- Vaccine Selection -->
                            <div>
                                <label for="vaccine_id" class="block text-sm font-medium text-gray-700">Vaccine <span
                                        class="text-red-500">*</span></label>
                                <select id="vaccine_id" name="vaccine_id" required
                                    class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-primary-500 focus:outline-none focus:ring-primary-500 sm:text-sm">
                                    <option value="">Select a vaccine</option>
                                    <?php
                                    $vaccines = [];
                                    $stmt = $mysqli->prepare("SELECT id, name, description FROM vaccines ORDER BY name");
                                    if ($stmt) {
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        while ($row = $result->fetch_assoc()) {
                                            echo '<option value="' . $row['id'] . '">' .
                                                htmlspecialchars($row['name']) .
                                                (!empty($row['description']) ? ' - ' . htmlspecialchars($row['description']) : '') .
                                                '</option>';
                                        }
                                        $stmt->close();
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Preferred Date -->
                            <div>
                                <label for="preferred_date" class="block text-sm font-medium text-gray-700">Preferred
                                    Date <span class="text-red-500">*</span></label>
                                <input type="date" id="preferred_date" name="preferred_date"
                                    min="<?= date('Y-m-d', strtotime('tomorrow')) ?>" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                <p class="mt-1 text-xs text-gray-500">Earliest available date is tomorrow</p>
                            </div>

                            <!-- Preferred Time -->
                            <div>
                                <label for="preferred_time" class="block text-sm font-medium text-gray-700">Preferred
                                    Time <span class="text-red-500">*</span></label>
                                <select id="preferred_time" name="preferred_time" required
                                    class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-primary-500 focus:outline-none focus:ring-primary-500 sm:text-sm">
                                    <option value="">Select a time slot</option>
                                    <option value="08:00:00">8:00 AM</option>
                                    <option value="09:00:00">9:00 AM</option>
                                    <option value="10:00:00">10:00 AM</option>
                                    <option value="11:00:00">11:00 AM</option>
                                    <option value="13:00:00">1:00 PM</option>
                                    <option value="14:00:00">2:00 PM</option>
                                    <option value="15:00:00">3:00 PM</option>
                                    <option value="16:00:00">4:00 PM</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">Clinic hours: 8:00 AM - 5:00 PM (Closed 12:00 PM -
                                    1:00 PM)</p>
                            </div>

                            <!-- Additional Notes -->
                            <div>
                                <label for="notes" class="block text-sm font-medium text-gray-700">Additional
                                    Notes</label>
                                <textarea id="notes" name="notes" rows="3"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"></textarea>
                            </div>

                            <!-- Form Actions -->
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <button type="button" onclick="closeAppointmentModal()"
                                    class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 sm:col-start-1 sm:mt-0 sm:text-sm">
                                    Cancel
                                </button>
                                <button type="submit"
                                    style="background-color: #2563eb; color: white; padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; font-size: 0.875rem; line-height: 1.25rem; width: 100%; text-align: center;"
                                    onmouseover="this.style.backgroundColor='#1d4ed8'"
                                    onmouseout="this.style.backgroundColor='#2563eb'">
                                    Schedule Appointment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Open modal function
            function openAppointmentModal() {
                const modal = document.getElementById('appointmentModal');
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';

                // Reset form when opening modal
                const form = document.getElementById('appointmentForm');
                form.reset();

                // Set default date to tomorrow if not set
                const dateInput = document.getElementById('preferred_date');
                if (!dateInput.value) {
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    dateInput.valueAsDate = tomorrow;
                }
            }

            // Close modal function
            function closeAppointmentModal() {
                const modal = document.getElementById('appointmentModal');
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }

            // Close modal when clicking outside
            const modal = document.getElementById('appointmentModal');
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeAppointmentModal();
                }
            });

            // Close modal when pressing Escape key
            document.addEventListener('keydown', function (event) {
                const modal = document.getElementById('appointmentModal');
                if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeAppointmentModal();
                }
            });

            // Cancel appointment function
            async function cancelAppointment(appointmentId) {
                if (!confirm('Are you sure you want to cancel this appointment? This action cannot be undone.')) {
                    return;
                }

                try {
                    const response = await fetch('/HealthCenter/patient/cancel_appointment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `appointment_id=${appointmentId}`
                    });

                    const result = await response.json();

                    if (result.success) {
                        alert('Appointment cancelled successfully.');
                        // Reload the page to reflect changes
                        window.location.reload();
                    } else {
                        throw new Error(result.message || 'Failed to cancel appointment');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('An error occurred while cancelling the appointment. Please try again.');
                }
            }

            // Form submission
            document.getElementById('appointmentForm').addEventListener('submit', async function (e) {
                e.preventDefault();

                const form = e.target;
                const formData = new FormData(form);
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalBtnText = submitBtn.innerHTML;

                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = `
        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        Processing...
    `;

                try {
                    const response = await fetch('/HealthCenter/patient/save_appointment.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Show success message and reload the page
                        alert('Appointment scheduled successfully!');
                        window.location.reload();
                    } else {
                        // Show error message
                        alert(data.message || 'An error occurred. Please try again.');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            });

            // Function to schedule a specific vaccine (pre-selects the vaccine in the modal)
            function scheduleVaccination(vaccine) {
                if (vaccine && vaccine.id) {
                    document.getElementById('vaccine_id').value = vaccine.id;
                }
                openAppointmentModal();
            }
        </script>
        </script>
        </main>
    </div>
    </div>

</body>

</html>