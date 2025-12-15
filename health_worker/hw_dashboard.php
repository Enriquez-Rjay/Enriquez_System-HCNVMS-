<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/db.php";

// Health worker dashboard: prepare metrics and upcoming appointments
$dueRecords = [];
$nextAppointment = null;
$progressPercent = 0;
$totalPatientsAssigned = 0;
$appointmentsToday = 0;
$lowStockCount = 0;
$vaccinesDueThisWeek = 0;
$totalOverdue = 0;
$userName = 'Health Worker';
$userFullName = '';

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

if (isset($_SESSION['user_id'])) {
	$hw_id = (int) $_SESSION['user_id'];

	// Get user information
	if ($stmt = $mysqli->prepare("SELECT full_name, username FROM users WHERE id = ?")) {
		$stmt->bind_param('i', $hw_id);
		$stmt->execute();
		$res = $stmt->get_result();
		if ($user = $res->fetch_assoc()) {
			$userName = $user['full_name'] ?? $user['username'] ?? 'Health Worker';
			$userFullName = $user['full_name'] ?? '';
		}
		$stmt->close();
	}

	// Next upcoming appointment for this HW
	if ($stmt = $mysqli->prepare("SELECT id, scheduled_at, patient_id, notes, status FROM appointments WHERE health_worker_id = ? AND scheduled_at >= NOW() ORDER BY scheduled_at ASC LIMIT 1")) {
		$stmt->bind_param('i', $hw_id);
		$stmt->execute();
		$res = $stmt->get_result();
		$nextAppointment = $res->fetch_assoc() ?: null;
		$stmt->close();
	}

	// Short list of upcoming and overdue appointments with vaccine information (exclude health workers and inactive patients)
	if ($stmt = $mysqli->prepare("SELECT a.id AS appointment_id, a.patient_id, a.scheduled_at, a.notes, a.status, a.vaccine_id, a.dosage, u.full_name AS patient_name, COALESCE(p.child_name, u.full_name) AS display_name, v.name AS vaccine_name FROM appointments a INNER JOIN users u ON u.id = a.patient_id AND u.role = 'patient' AND u.status = 'active' INNER JOIN patient_profiles p ON p.user_id = u.id LEFT JOIN vaccines v ON v.id = a.vaccine_id WHERE a.health_worker_id = ? AND a.status IN ('scheduled','pending') ORDER BY a.scheduled_at ASC LIMIT 50")) {
		$stmt->bind_param('i', $hw_id);
		$stmt->execute();
		$res = $stmt->get_result();
		while ($row = $res->fetch_assoc()) {
			// Determine if overdue - compare dates only (not time)
			$apptDate = new DateTime($row['scheduled_at']);
			$today = new DateTime();
			$today->setTime(0, 0, 0);
			$apptDate->setTime(0, 0, 0);
			// Appointment is overdue if scheduled date is before today
			$row['is_overdue'] = $apptDate < $today;
			// Calculate days overdue
			if ($row['is_overdue']) {
				$diff = $today->diff($apptDate);
				$row['days_overdue'] = $diff->days;
			} else {
				$row['days_overdue'] = 0;
			}
			$dueRecords[] = $row;
		}
		$stmt->close();
	}

	// Get total active patients (exclude health workers by requiring patient_profiles)
	if (
		$stmt = $mysqli->prepare("
		SELECT COUNT(DISTINCT u.id) as total 
		FROM users u
		INNER JOIN patient_profiles p ON p.user_id = u.id
		WHERE u.role = 'patient' 
		AND u.status = 'active'")
	) {
		$stmt->execute();
		$result = $stmt->get_result();
		$totalPatientsAssigned = (int) ($result->fetch_assoc()['total'] ?? 0);
		$stmt->close();
	}

	// Get today's appointments
	if (
		$stmt = $mysqli->prepare("
		SELECT COUNT(*) as total 
		FROM appointments 
		WHERE health_worker_id = ? 
		AND DATE(scheduled_at) = CURDATE()
		AND status IN ('scheduled','pending')")
	) {
		$stmt->bind_param('i', $hw_id);
		$stmt->execute();
		$result = $stmt->get_result();
		$appointmentsToday = (int) ($result->fetch_assoc()['total'] ?? 0);
		$stmt->close();
	}

	// Get weekly appointments
	$weekStart = date('Y-m-d', strtotime('monday this week'));
	$weekEnd = date('Y-m-d 23:59:59', strtotime('sunday this week'));
	if (
		$stmt = $mysqli->prepare("
		SELECT COUNT(*) as total 
		FROM appointments 
		WHERE health_worker_id = ? 
		AND scheduled_at BETWEEN ? AND ?
		AND status IN ('scheduled','pending')")
	) {
		$stmt->bind_param('iss', $hw_id, $weekStart, $weekEnd);
		$stmt->execute();
		$result = $stmt->get_result();
		$vaccinesDueThisWeek = (int) ($result->fetch_assoc()['total'] ?? 0);
		$stmt->close();
	}

	// Get overdue appointments
	if (
		$stmt = $mysqli->prepare("
		SELECT COUNT(*) as total 
		FROM appointments 
		WHERE health_worker_id = ? 
		AND DATE(scheduled_at) < CURDATE() 
		AND status IN ('scheduled','pending')")
	) {
		$stmt->bind_param('i', $hw_id);
		$stmt->execute();
		$result = $stmt->get_result();
		$totalOverdue = (int) ($result->fetch_assoc()['total'] ?? 0);
		$stmt->close();
	}

	// Low stock vaccine batches (threshold 20)
	if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM vaccine_batches WHERE quantity_available < 20")) {
		$r = $res->fetch_assoc();
		$lowStockCount = (int) ($r['c'] ?? 0);
		$res->free();
	}

	// Compute patient progress if we have a next appointment
	if (!empty($nextAppointment['patient_id'])) {
		$pid = (int) $nextAppointment['patient_id'];
		$totalVaccines = 0;
		if ($r = $mysqli->query("SELECT COUNT(*) AS c FROM vaccines")) {
			$totalVaccines = (int) (($r->fetch_assoc())['c'] ?? 0);
			$r->free();
		}
		$received = 0;
		if ($stmt = $mysqli->prepare("SELECT COUNT(DISTINCT vaccine_id) AS c FROM vaccination_records WHERE patient_id = ?")) {
			$stmt->bind_param('i', $pid);
			$stmt->execute();
			$rr = $stmt->get_result()->fetch_assoc();
			$received = (int) ($rr['c'] ?? 0);
			$stmt->close();
		}
		$progressPercent = $totalVaccines > 0 ? round(($received / $totalVaccines) * 100) : 0;
	}
}

?>
<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>HCNVMS Dashboard</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com" rel="preconnect" />
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect" />
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,100..900;1,100..900&amp;display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
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
        <!-- SideNavBar -->
        <aside
            class="flex w-64 flex-col border-r border-slate-200 dark:border-slate-800 bg-white dark:bg-background-dark">
            <div class="flex h-16 shrink-0 items-center gap-3 px-6 text-primary">
                <span class="material-symbols-outlined text-3xl">vaccines</span>
                <h2 class="text-lg font-bold leading-tight tracking-[-0.015em] text-slate-900 dark:text-white">HCNVMS
                </h2>
            </div>
            <div class="flex flex-col justify-between h-full p-4">
                <div class="flex flex-col gap-2">
                    <a class="flex items-center gap-3 rounded-lg bg-primary/10 dark:bg-primary/20 px-3 py-2 text-primary"
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
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
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
                                <h1 class="text-slate-900 dark:text-white text-sm font-medium leading-normal">
                                    <?php echo htmlspecialchars($userName); ?>
                                </h1>
                                <p class="text-slate-500 dark:text-slate-400 text-xs font-normal leading-normal">Health
                                    Worker</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <!-- TopNavBar -->
            <header
                class="sticky top-0 z-10 flex h-16 items-center justify-end border-b border-solid border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-background-dark/80 backdrop-blur-sm px-6">
                <div class="flex items-center gap-4">
                    <div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-10"
                        data-alt="Profile picture" style="background-image: none;"></div>
                </div>
            </header>
            <div class="p-6 md:p-8">
                <!-- PageHeading -->
                <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                    <div class="flex flex-col gap-1">
                        <p
                            class="text-2xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white lg:text-3xl">
                            Dashboard</p>
                        <p class="text-slate-600 dark:text-slate-400 text-base font-normal leading-normal">Welcome back,
                            <?php echo htmlspecialchars($userName); ?>. Here is a summary of vaccination activities.
                        </p>
                    </div>
                    <a href="hw_patient.php"
                        class="flex h-10 items-center justify-center overflow-hidden rounded-lg bg-white px-4 text-sm font-bold text-slate-800 shadow-sm ring-1 ring-inset ring-slate-300 transition-all hover:bg-slate-50 dark:bg-slate-800 dark:text-white dark:ring-slate-700 dark:hover:bg-slate-700">
                        <span class="truncate">View All Patients</span>
                    </a>
                </div>
            </div>
            <!-- Stats -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">
                <!-- Total Active Patients -->
                <div
                    class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">group</span>
                        <p class="text-base font-medium text-slate-600 dark:text-slate-300">Active Patients</p>
                    </div>
                    <p class="text-3xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white">
                        <?php echo number_format($totalPatientsAssigned); ?>
                    </p>
                </div>

                <!-- Appointments Today -->
                <div
                    class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-500">today</span>
                        <p class="text-base font-medium text-slate-600 dark:text-slate-300">Today's Appointments</p>
                    </div>
                    <p class="text-3xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white">
                        <?php echo number_format($appointmentsToday); ?>
                    </p>
                </div>

                <!-- Vaccines Due This Week -->
                <div
                    class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-amber-500">calendar_month</span>
                        <p class="text-base font-medium text-slate-600 dark:text-slate-300">Due This Week</p>
                    </div>
                    <p class="text-3xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white">
                        <?php echo number_format($vaccinesDueThisWeek); ?>
                    </p>
                </div>

                <!-- Total Overdue -->
                <div
                    class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-red-500">warning</span>
                        <p class="text-base font-medium text-slate-600 dark:text-slate-300">Overdue Vaccinations</p>
                    </div>
                    <p class="text-3xl font-bold leading-tight tracking-tight text-red-600 dark:text-red-500">
                        <?php echo number_format($totalOverdue); ?>
                    </p>
                </div>
            </div>
            <?php
// Determine active tab from URL or default to 'upcoming'
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'overdue' ? 'overdue' : 'upcoming';
?>
            <!-- Vaccination Status Section -->
            <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex border-b border-slate-200 dark:border-slate-800 p-2">
                    <a href="?tab=upcoming"
                        class="flex-1 rounded-md px-3 py-1.5 text-sm font-semibold text-center <?php echo $activeTab === 'upcoming' ? 'text-primary bg-primary/10 dark:bg-primary/20' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'; ?>">Upcoming</a>
                    <a href="?tab=overdue"
                        class="flex-1 rounded-md px-3 py-1.5 text-sm font-semibold text-center <?php echo $activeTab === 'overdue' ? 'text-primary bg-primary/10 dark:bg-primary/20' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'; ?>">Overdue</a>
                </div>
                <!-- Data Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="text-xs uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-6 py-3 font-medium" scope="col">Patient Name</th>
                                <th class="px-6 py-3 font-medium" scope="col">Vaccine</th>
                                <th class="px-6 py-3 font-medium" scope="col">Due Date</th>
                                <th class="px-6 py-3 font-medium" scope="col">Status</th>
                                <th class="px-6 py-3 font-medium text-right" scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <?php 
// Filter records based on active tab
$filteredRecords = array_filter($dueRecords, function($record) use ($activeTab) {
    $isOverdue = $record['is_overdue'] ?? false;
    return $activeTab === 'overdue' ? $isOverdue : !$isOverdue;
});

if (empty($filteredRecords)): 
?>
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">
                                    <?php echo $activeTab === 'overdue' ? 'No overdue appointments found.' : 'No upcoming appointments found.'; ?>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($filteredRecords as $record): 
	$apptDate = new DateTime($record['scheduled_at']);
	$formattedDate = $apptDate->format('Y-m-d');
	$isOverdue = isset($record['is_overdue']) && $record['is_overdue'];
	$daysOverdue = isset($record['days_overdue']) ? $record['days_overdue'] : 0;
	$rowClass = $isOverdue ? 'bg-red-50/50 hover:bg-red-50 dark:bg-red-900/10 dark:hover:bg-red-900/20' : 'bg-white hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800/50';
	$statusClass = $isOverdue ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300';
	$statusText = $isOverdue ? 'Overdue' : 'Upcoming';
	$dateClass = $isOverdue ? 'font-semibold text-red-600 dark:text-red-500' : 'text-slate-600 dark:text-slate-300';
	$dateText = $isOverdue ? "Overdue ($daysOverdue " . ($daysOverdue == 1 ? 'day' : 'days') . ")" : $formattedDate;
	$patientName = $record['display_name'] ?? $record['patient_name'] ?? 'Unknown';
	// Get vaccine information - prefer vaccine name, then dosage, then notes
	$vaccineInfo = 'Vaccination';
	if (!empty($record['vaccine_name'])) {
		$vaccineInfo = $record['vaccine_name'];
		if (!empty($record['dosage'])) {
			$vaccineInfo .= ' - ' . $record['dosage'];
		}
	} elseif (!empty($record['dosage'])) {
		$vaccineInfo = $record['dosage'];
	} elseif (!empty($record['notes'])) {
		$vaccineInfo = $record['notes'];
	}
?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="whitespace-nowrap px-6 py-4 font-medium text-slate-900 dark:text-white">
                                    <?php echo htmlspecialchars($patientName); ?>
                                </td>
                                <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                    <?php echo htmlspecialchars($vaccineInfo); ?>
                                </td>
                                <td class="px-6 py-4 <?php echo $dateClass; ?>">
                                    <?php echo htmlspecialchars($dateText); ?>
                                </td>
                                <td class="px-6 py-4"><span
                                        class="inline-flex items-center rounded-full <?php echo $statusClass; ?> px-2.5 py-0.5 text-xs font-medium">
                                        <?php echo htmlspecialchars($statusText); ?>
                                    </span></td>
                                <td class="px-6 py-4 text-right"><a class="font-medium text-primary hover:underline"
                                        href="/HealthCenter/health_worker/hw_patient.php?patient_id=<?php echo urlencode($record['patient_id']); ?>">View
                                        Profile</a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between border-t border-slate-200 p-4 dark:border-slate-800">
                    <span class="text-sm text-slate-600 dark:text-slate-400">Showing
                        <?php echo min(1, count($dueRecords)); ?> to
                        <?php echo min(50, count($dueRecords)); ?> of
                        <?php echo count($dueRecords); ?> results
                    </span>
                </div>
            </div>
    </div>
    </main>
    </div>
    <script>
        // Auto-refresh the page every 60 seconds to keep data fresh
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>

</html>