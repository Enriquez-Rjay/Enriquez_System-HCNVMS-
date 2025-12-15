<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Get current view type or default to 'month'
$view = isset($_GET['view']) ? $_GET['view'] : 'month';

// Handle date selection
if (isset($_GET['date'])) {
    try {
        $selectedDate = new DateTime($_GET['date']);
        $currentDate = $selectedDate;
    } catch (Exception $e) {
        $currentDate = new DateTime();
    }
} else {
    $currentDate = new DateTime();
}
$currentDate->setTime(0, 0, 0);

// Calculate date range based on view
switch ($view) {
    case 'week':
        $startDate = clone $currentDate;
        $startDate->modify('monday this week');
        $endDate = clone $startDate;
        $endDate->modify('sunday this week');
        $endDate->setTime(23, 59, 59);
        break;
    case 'overdue':
        $endDate = new DateTime();
        $endDate->setTime(0, 0, 0);
        $startDate = clone $endDate;
        $startDate->modify('-30 days');
        break;
    case 'month':
    default:
        $startDate = new DateTime($currentDate->format('Y-m-01'));
        $endDate = new DateTime($currentDate->format('Y-m-t'));
        $endDate->setTime(23, 59, 59);
        break;
}

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

// Fetch appointments with full patient profile information
$upcomingAppointments = [];
$appointmentsByDate = []; // For calendar display
if (isset($_SESSION['user_id'])) {
    $hw_id = (int) $_SESSION['user_id'];
    $sql = "SELECT a.id, a.patient_id, a.scheduled_at, a.notes, a.status, a.vaccine_id, a.dosage, a.weight, a.height,
                   u.full_name AS patient_name, u.username AS patient_code, u.email, u.phone as contact_number,
                   p.child_name, p.birth_date, p.guardian_name, p.address,
                   v.name AS vaccine_name
            FROM appointments a
            INNER JOIN users u ON u.id = a.patient_id AND u.role = 'patient' AND u.status = 'active'
            INNER JOIN patient_profiles p ON p.user_id = u.id
            LEFT JOIN vaccines v ON v.id = a.vaccine_id
            WHERE a.health_worker_id = ?
            AND a.scheduled_at BETWEEN ? AND ?
            AND a.status IN ('scheduled', 'pending')
            ORDER BY a.scheduled_at ASC";

    if ($stmt = $mysqli->prepare($sql)) {
        $start = $startDate->format('Y-m-d H:i:s');
        $end = $endDate->format('Y-m-d H:i:s');
        $stmt->bind_param('iss', $hw_id, $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $upcomingAppointments[] = $row;
            // Group by date for calendar
            $dateKey = date('Y-m-d', strtotime($row['scheduled_at']));
            if (!isset($appointmentsByDate[$dateKey])) {
                $appointmentsByDate[$dateKey] = [];
            }
            $appointmentsByDate[$dateKey][] = $row;
        }
        $stmt->close();
    }
}

// Calculate navigation URLs
$prevDate = clone $currentDate;
$nextDate = clone $currentDate;

switch ($view) {
    case 'week':
        $prevDate->modify('-1 week');
        $nextDate->modify('+1 week');
        $dateDisplay = $startDate->format('M j') . ' - ' . $endDate->format('M j, Y');
        $dateValue = $currentDate->format('Y-m-d');
        break;
    case 'month':
        $prevDate->modify('first day of previous month');
        $nextDate->modify('first day of next month');
        $dateDisplay = $currentDate->format('F Y');
        $dateValue = $currentDate->format('Y-m');
        break;
    case 'overdue':
        $dateDisplay = 'Overdue Appointments';
        $dateValue = '';
        break;
}

// Calculate calendar days for month view
$calendarDays = [];
if ($view === 'month') {
    $firstDay = new DateTime($currentDate->format('Y-m-01'));
    $lastDay = new DateTime($currentDate->format('Y-m-t'));
    $firstDayOfWeek = (int) $firstDay->format('w'); // 0 = Sunday, 6 = Saturday
    $daysInMonth = (int) $lastDay->format('d');

    // Previous month's trailing days
    $prevMonth = clone $firstDay;
    $prevMonth->modify('-1 month');
    $daysInPrevMonth = (int) $prevMonth->format('t');

    // Fill in previous month days
    for ($i = $firstDayOfWeek - 1; $i >= 0; $i--) {
        $day = $daysInPrevMonth - $i;
        $calendarDays[] = [
            'day' => $day,
            'date' => $prevMonth->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT),
            'isCurrentMonth' => false
        ];
    }

    // Current month days
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateStr = $currentDate->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
        $calendarDays[] = [
            'day' => $day,
            'date' => $dateStr,
            'isCurrentMonth' => true,
            'appointments' => $appointmentsByDate[$dateStr] ?? []
        ];
    }

    // Next month's leading days (fill to 42 total cells for 6 weeks)
    $totalCells = count($calendarDays);
    $remainingCells = 42 - $totalCells;
    $nextMonth = clone $lastDay;
    $nextMonth->modify('+1 day');
    for ($day = 1; $day <= $remainingCells; $day++) {
        $calendarDays[] = [
            'day' => $day,
            'date' => $nextMonth->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT),
            'isCurrentMonth' => false
        ];
    }
}

// Pass data to the view
$viewData = [
    'view' => $view,
    'currentDate' => $currentDate,
    'dateDisplay' => $dateDisplay,
    'dateValue' => $dateValue,
    'prevUrl' => "?view=$view&date=" . $prevDate->format('Y-m-d'),
    'nextUrl' => "?view=$view&date=" . $nextDate->format('Y-m-d'),
    'todayUrl' => "?view=$view",
    'weekViewUrl' => "?view=week&date=" . $currentDate->format('Y-m-d'),
    'monthViewUrl' => "?view=month&date=" . $currentDate->format('Y-m-d'),
    'overdueViewUrl' => "?view=overdue",
    'upcomingAppointments' => $upcomingAppointments,
    'calendarDays' => $calendarDays ?? [],
    'appointmentsByDate' => $appointmentsByDate
];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>HCNVMS Schedule</title>
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
            font-variation-settings: 'FILL' 0,
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
                    <a class="flex items-center gap-3 rounded-lg bg-primary/10 dark:bg-primary/20 px-3 py-2 text-primary"
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
                        <div class="flex items-center gap-2">
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
            <?php if (isset($_SESSION['reminder_success'])): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mx-6 mt-4" role="alert">
                <div class="flex items-center">
                    <span class="material-symbols-outlined mr-2">check_circle</span>
                    <p>
                        <?php echo htmlspecialchars($_SESSION['reminder_success']); unset($_SESSION['reminder_success']); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['reminder_error'])): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mx-6 mt-4" role="alert">
                <div class="flex items-center">
                    <span class="material-symbols-outlined mr-2">error</span>
                    <p>
                        <?php echo htmlspecialchars($_SESSION['reminder_error']); unset($_SESSION['reminder_error']); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            <header
                class="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-solid border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-background-dark/80 backdrop-blur-sm px-6">
                <div class="flex items-center gap-4">
                    <a href="<?php echo htmlspecialchars($viewData['prevUrl']); ?>"
                        class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-transparent text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
                        <span class="material-symbols-outlined">arrow_back_ios_new</span>
                    </a>
                    <div class="relative">
                        <input type="month" id="datePicker" value="<?php echo $viewData['dateValue']; ?>"
                            class="bg-transparent border-0 text-lg font-semibold text-slate-900 dark:text-white cursor-pointer focus:ring-0 focus:outline-none">
                        <p id="dateDisplay" class="text-lg font-semibold text-slate-900 dark:text-white">
                            <?php echo $viewData['dateDisplay']; ?>
                        </p>
                        <?php if ($viewData['view'] !== 'overdue'): ?>
                        <a href="<?php echo $viewData['todayUrl']; ?>"
                            class="absolute -bottom-6 left-0 text-xs text-primary hover:underline">
                            Today
                        </a>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo htmlspecialchars($viewData['nextUrl']); ?>"
                        class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-transparent text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
                        <span class="material-symbols-outlined">arrow_forward_ios</span>
                    </a>
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden sm:flex items-center gap-2 rounded-lg bg-slate-100 dark:bg-slate-800 p-1">
                        <a href="<?php echo $viewData['weekViewUrl']; ?>"
                            class="rounded-md px-3 py-1.5 text-sm font-medium <?php echo $viewData['view'] === 'week' ? 'bg-white dark:bg-slate-700 font-semibold text-primary dark:text-white' : 'text-slate-600 hover:bg-white dark:text-slate-300 dark:hover:bg-slate-700'; ?>">
                            Week
                        </a>
                        <a href="<?php echo $viewData['monthViewUrl']; ?>"
                            class="rounded-md px-3 py-1.5 text-sm font-medium <?php echo $viewData['view'] === 'month' ? 'bg-white dark:bg-slate-700 font-semibold text-primary dark:text-white' : 'text-slate-600 hover:bg-white dark:text-slate-300 dark:hover:bg-slate-700'; ?>">
                            Month
                        </a>
                        <a href="<?php echo $viewData['overdueViewUrl']; ?>"
                            class="rounded-md px-3 py-1.5 text-sm font-medium <?php echo $viewData['view'] === 'overdue' ? 'bg-white dark:bg-slate-700 font-semibold text-primary dark:text-white' : 'text-slate-600 hover:bg-white dark:text-slate-300 dark:hover:bg-slate-700'; ?>">
                            Overdue
                        </a>
                    </div>
                    <button id="newAppointmentBtn"
                        class="flex h-10 cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-lg bg-primary px-4 text-sm font-bold text-white shadow-sm transition-all hover:bg-primary/90">
                        <span class="material-symbols-outlined text-base">add</span>
                        <span class="truncate">New Appointment</span>
                    </button>
                </div>
            </header>
            <div class="p-6 md:p-8">
                <?php if ($viewData['view'] === 'month'): ?>
                <div class="grid grid-cols-7 text-center text-sm font-semibold text-slate-600 dark:text-slate-300">
                    <div>Sun</div>
                    <div>Mon</div>
                    <div>Tue</div>
                    <div>Wed</div>
                    <div>Thu</div>
                    <div>Fri</div>
                    <div>Sat</div>
                </div>
                <div class="grid grid-cols-7 border-t border-l border-slate-200 dark:border-slate-800 mt-2">
                    <?php foreach ($viewData['calendarDays'] as $calDay): 
    $isToday = $calDay['date'] === date('Y-m-d');
    $dayClass = $calDay['isCurrentMonth'] ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400 dark:text-slate-500';
    $bgClass = $isToday ? 'bg-primary/10 dark:bg-primary/20' : '';
?>
                    <div class="relative h-40 border-r border-b border-slate-200 dark:border-slate-800 p-2 <?php echo $dayClass; ?> <?php echo $bgClass; ?>"
                        data-date="<?php echo htmlspecialchars($calDay['date']); ?>">
                        <p class="text-right <?php echo $isToday ? 'font-bold text-primary' : ''; ?>">
                            <?php echo $calDay['day']; ?>
                        </p>
                        <?php if ($calDay['isCurrentMonth'] && !empty($calDay['appointments'])): ?>
                        <div class="mt-1 space-y-1 text-left">
                            <?php foreach ($calDay['appointments'] as $appt): 
    $apptTime = date('g:i A', strtotime($appt['scheduled_at']));
    $patientName = $appt['child_name'] ?? $appt['patient_name'] ?? 'Unknown';
    $notes = $appt['notes'] ?? 'Appointment';
?>
                            <div class="bg-amber-100 dark:bg-amber-900/50 p-1.5 rounded-md cursor-pointer hover:bg-amber-200 dark:hover:bg-amber-900"
                                onclick="selectAppointment(<?php echo $appt['id']; ?>)">
                                <p class="text-xs font-semibold text-amber-800 dark:text-amber-200 truncate">
                                    <?php echo htmlspecialchars($patientName); ?>
                                </p>
                                <p class="text-xs text-amber-700 dark:text-amber-300 truncate">
                                    <?php echo htmlspecialchars($notes); ?>
                                </p>
                                <p class="text-xs text-amber-600 dark:text-amber-400">
                                    <?php echo htmlspecialchars($apptTime); ?>
                                </p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center text-slate-500 dark:text-slate-400 py-12">
                    <p>Week and Overdue views coming soon. Please use Month view.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
        <aside
            class="w-96 flex-col border-l border-slate-200 dark:border-slate-800 bg-white dark:bg-background-dark p-6 flex">
            <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-1">Appointment Details</h3>
            <?php 
$upcomingAppointments = $viewData['upcomingAppointments'] ?? [];
$selectedApptId = isset($_GET['appt_id']) ? (int)$_GET['appt_id'] : null;
$selectedAppt = null;
if ($selectedApptId) {
    foreach ($upcomingAppointments as $appt) {
        if ($appt['id'] == $selectedApptId) {
            $selectedAppt = $appt;
            break;
        }
    }
}
if (!$selectedAppt && !empty($upcomingAppointments)) {
    $selectedAppt = $upcomingAppointments[0];
}
?>
            <?php if ($selectedAppt): 
        $appt = $selectedAppt;
        $apptDateTime = new DateTime($appt['scheduled_at']);
        $apptDate = $apptDateTime->format('l, F j, Y');
        $apptTime = $apptDateTime->format('g:i A');
        $patientName = $appt['child_name'] ?? $appt['patient_name'] ?? 'Unknown';
        $birthDate = $appt['birth_date'] ?? '';
        $guardianName = $appt['guardian_name'] ?? 'N/A';
        $address = $appt['address'] ?? 'N/A';
        $email = $appt['email'] ?? 'N/A';
        $notes = $appt['notes'] ?? 'No notes';
?>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">
                <?php echo htmlspecialchars($apptDate); ?>
            </p>
            <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-6">
                <?php echo htmlspecialchars($apptTime); ?>
            </p>
            <div class="space-y-6 overflow-y-auto flex-1">
                <div class="flex items-center gap-4">
                    <div
                        class="flex-shrink-0 size-12 rounded-full bg-slate-200 dark:bg-slate-700 flex items-center justify-center">
                        <span
                            class="material-symbols-outlined text-slate-500 dark:text-slate-400 text-3xl">child_care</span>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-white">
                            <?php echo htmlspecialchars($patientName); ?>
                        </p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">ID:
                            <?php echo htmlspecialchars($appt['patient_code'] ?? ''); ?>
                        </p>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-slate-500 dark:text-slate-400 mb-2">Patient Information</h4>
                    <div class="space-y-2 text-sm text-slate-800 dark:text-slate-200">
                        <div class="flex justify-between"><span>Date of Birth:</span> <span class="font-medium">
                                <?php echo htmlspecialchars($birthDate ? date('M d, Y', strtotime($birthDate)) : 'N/A'); ?>
                            </span></div>
                        <div class="flex justify-between"><span>Age:</span> <span class="font-medium">
                                <?php echo $birthDate ? 
                (new DateTime($birthDate))->diff(new DateTime())->format('%y years, %m months') : 'N/A'; ?>
                            </span></div>
                        <div class="flex justify-between"><span>Weight:</span> <span class="font-medium">
                                <?php echo isset($appt['weight']) ? htmlspecialchars($appt['weight']) . ' kg' : 'N/A'; ?>
                            </span></div>
                        <div class="flex justify-between"><span>Height:</span> <span class="font-medium">
                                <?php echo isset($appt['height']) ? htmlspecialchars($appt['height']) . ' cm' : 'N/A'; ?>
                            </span></div>
                        <div class="flex justify-between"><span>Contact Number:</span> <span class="font-medium">
                                <?php echo !empty($appt['contact_number']) ? htmlspecialchars($appt['contact_number']) : 'N/A'; ?>
                            </span></div>
                        <div class="flex justify-between"><span>Email:</span> <span class="font-medium">
                                <?php echo htmlspecialchars($email); ?>
                            </span></div>
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg">
                    <h4 class="text-sm font-semibold text-blue-700 dark:text-blue-300 mb-3">Vaccination Details</h4>
                    <div class="space-y-3">
                        <?php if (!empty($appt['vaccine_name'])): ?>
                        <div class="flex items-start">
                            <span
                                class="material-symbols-outlined text-blue-500 dark:text-blue-400 mr-2 text-lg">vaccines</span>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-blue-900 dark:text-blue-100">
                                    <?php echo htmlspecialchars($appt['vaccine_name']); ?>
                                </p>
                                <?php if (!empty($appt['dosage'])): ?>
                                <p class="text-xs text-blue-700 dark:text-blue-300">Dosage:
                                    <?php echo htmlspecialchars($appt['dosage']); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="flex items-center text-amber-700 dark:text-amber-300">
                            <span class="material-symbols-outlined mr-2">warning</span>
                            <p class="text-sm">No vaccine selected for this appointment</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-slate-500 dark:text-slate-400 mb-2">Appointment Information
                    </h4>
                    <div class="space-y-2 text-sm text-slate-800 dark:text-slate-200">
                        <div class="flex justify-between"><span>Date & Time:</span> <span class="font-medium">
                                <?php echo htmlspecialchars($apptDate . ' at ' . $apptTime); ?>
                            </span></div>
                        <div class="flex justify-between"><span>Notes:</span> <span
                                class="font-medium text-right max-w-[200px]">
                                <?php echo htmlspecialchars($notes); ?>
                            </span></div>
                        <div class="flex justify-between"><span>Status:</span> <span
                                class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-300">
                                <?php echo htmlspecialchars(ucfirst($appt['status'] ?? 'scheduled')); ?>
                            </span></div>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-slate-500 dark:text-slate-400 mb-2">Guardian Information</h4>
                    <div class="space-y-2 text-sm text-slate-800 dark:text-slate-200">
                        <div><span class="font-medium">Name:</span> <span>
                                <?php echo htmlspecialchars($guardianName); ?>
                            </span></div>
                        <div><span class="font-medium">Address:</span> <span class="block mt-1">
                                <?php echo htmlspecialchars($address); ?>
                            </span></div>
                    </div>
                </div>

                <?php
                // Fetch patient's vaccination checklist
                $patientId = $appt['patient_id'] ?? 0;
                $vaccineChecklist = [];
                if ($patientId > 0) {
                    $checklistSql = "SELECT v.id, v.name, 
                                     (SELECT COUNT(*) FROM vaccination_records vr WHERE vr.vaccine_id = v.id AND vr.patient_id = ?) as doses_received,
                                     (SELECT GROUP_CONCAT(CASE 
                                         WHEN vr.dose = 1 THEN '1st Dose'
                                         WHEN vr.dose = 2 THEN '2nd Dose'
                                         WHEN vr.dose = 3 THEN '3rd Dose'
                                         WHEN vr.dose = 4 THEN 'Booster'
                                         WHEN vr.dose = 5 THEN 'Additional Dose'
                                         ELSE CONCAT(vr.dose, 'th Dose')
                                     END SEPARATOR ', ') 
                                     FROM vaccination_records vr 
                                     WHERE vr.vaccine_id = v.id AND vr.patient_id = ?) as received_doses
                                     FROM vaccines v
                                     ORDER BY v.name";
                    if ($checklistStmt = $mysqli->prepare($checklistSql)) {
                        $checklistStmt->bind_param('ii', $patientId, $patientId);
                        $checklistStmt->execute();
                        $checklistResult = $checklistStmt->get_result();
                        while ($checklistRow = $checklistResult->fetch_assoc()) {
                            $vaccineChecklist[] = $checklistRow;
                        }
                        $checklistStmt->close();
                    }
                }
                ?>
                <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg">
                    <h4 class="text-sm font-semibold text-green-700 dark:text-green-300 mb-3">Vaccination Checklist</h4>
                    <div class="space-y-2 text-sm">
                        <?php if (!empty($vaccineChecklist)): ?>
                            <?php foreach ($vaccineChecklist as $checklistItem): 
                                $dosesReceived = (int) $checklistItem['doses_received'];
                                $receivedDoses = $checklistItem['received_doses'] ?? '';
                                $isComplete = $dosesReceived > 0;
                            ?>
                            <div class="flex items-start gap-2">
                                <?php if ($isComplete): ?>
                                    <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-base">check_circle</span>
                                <?php else: ?>
                                    <span class="material-symbols-outlined text-slate-400 text-base">radio_button_unchecked</span>
                                <?php endif; ?>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white <?php echo $isComplete ? 'line-through text-slate-500' : ''; ?>">
                                        <?php echo htmlspecialchars($checklistItem['name']); ?>
                                    </p>
                                    <?php if ($isComplete && $receivedDoses): ?>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">
                                            Received: <?php echo htmlspecialchars($receivedDoses); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-slate-600 dark:text-slate-400">No vaccines available.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="mt-auto pt-6 border-t border-slate-200 dark:border-slate-800 space-y-3">
                <?php if ($appt['status'] === 'scheduled' || $appt['status'] === 'pending'): ?>
                <form method="post" action="/HealthCenter/health_worker/mark_administered.php">
                    <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appt['id']); ?>" />
                    <button type="submit"
                        class="flex w-full h-10 cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-lg bg-green-600 px-4 text-sm font-bold text-white shadow-sm transition-all hover:bg-green-700">
                        <span class="material-symbols-outlined text-base">check_circle</span>
                        <span>Mark as Administered</span>
                    </button>
                </form>
                <form method="post" action="/HealthCenter/health_worker/send_reminder.php">
                    <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appt['id']); ?>" />
                    <button type="submit"
                        class="flex w-full h-10 cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-lg bg-slate-100 px-4 text-sm font-bold text-slate-800 shadow-sm ring-1 ring-inset ring-slate-200 transition-all hover:bg-slate-200 dark:bg-slate-800 dark:text-white dark:ring-slate-700 dark:hover:bg-slate-700">
                        <span class="material-symbols-outlined text-base">sms</span>
                        <span>Send Reminder (Manual)</span>
                    </button>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 text-center">
                        Automatic reminders sent 24h before appointment
                    </p>
                </form>
                <form method="post" action="/HealthCenter/health_worker/cancel_appointment.php" 
                      onsubmit="return confirm('Are you sure you want to cancel this appointment? This action cannot be undone.');">
                    <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appt['id']); ?>" />
                    <button type="submit"
                        class="flex w-full h-10 cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-lg bg-red-600 px-4 text-sm font-bold text-white shadow-sm transition-all hover:bg-red-700">
                        <span class="material-symbols-outlined text-base">cancel</span>
                        <span>Cancel Appointment</span>
                    </button>
                </form>
                <?php endif; ?>
                <a href="/HealthCenter/health_worker/hw_patient.php?patient_id=<?php echo urlencode($appt['patient_id']); ?>"
                    class="flex w-full h-10 items-center justify-center gap-2 overflow-hidden rounded-lg bg-white px-4 text-sm font-bold text-slate-800 shadow-sm ring-1 ring-inset ring-slate-300 transition-all hover:bg-slate-50 dark:bg-slate-800 dark:text-white dark:ring-slate-700 dark:hover:bg-slate-700">
                    <span class="truncate">View Full Patient Profile</span>
                </a>
            </div>
            <?php else: ?>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">No appointments scheduled for this period.</p>
            <div class="mt-auto pt-6 border-t border-slate-200 dark:border-slate-800 space-y-3">
                <button id="newAppointmentBtnSidebar"
                    class="flex w-full h-10 items-center justify-center gap-2 overflow-hidden rounded-lg bg-primary px-4 text-sm font-bold text-white shadow-sm transition-all hover:bg-primary/90">
                    <span class="material-symbols-outlined text-base">add</span>
                    <span>Schedule New Appointment</span>
                </button>
            </div>
            <?php endif; ?>
        </aside>
    </div>

    <script>
        // Function to select appointment from calendar
        function selectAppointment(apptId) {
            const url = new URL(window.location.href);
            url.searchParams.set('appt_id', apptId);
            window.location.href = url.toString();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const datePicker = document.getElementById('datePicker');
            const dateDisplay = document.getElementById('dateDisplay');
            const view = '<?php echo $viewData['view']; ?>';
            const newAppointmentBtnSidebar = document.getElementById('newAppointmentBtnSidebar');

            // Open modal from sidebar button
            if (newAppointmentBtnSidebar) {
                newAppointmentBtnSidebar.addEventListener('click', () => {
                    if (newAppointmentBtn) {
                        newAppointmentBtn.click();
                    }
                });
            }

            // Toggle between date picker and display
            if (dateDisplay && datePicker) {
                dateDisplay.addEventListener('click', function () {
                    datePicker.style.display = 'inline-block';
                    datePicker.focus();
                    dateDisplay.style.display = 'none';
                });

                datePicker.addEventListener('change', function () {
                    // Reload the page with the new date parameter
                    const url = new URL(window.location.href);
                    url.searchParams.set('date', this.value);
                    url.searchParams.delete('appt_id'); // Clear selected appointment
                    window.location.href = url.toString();
                });

                datePicker.addEventListener('blur', function () {
                    datePicker.style.display = 'none';
                    dateDisplay.style.display = 'block';
                });

                // Initialize display
                datePicker.style.display = 'none';
            }
        });
    </script>

<!-- Add Appointment Modal -->
<div id="appointmentModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog"
    aria-modal="true">
    <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>
        <div
            class="inline-block transform overflow-hidden rounded-lg bg-white px-4 pt-5 pb-4 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 sm:align-middle">
            <div>
                <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-white" id="modal-title">Schedule New
                    Appointment</h3>
                <form id="appointmentForm" class="mt-5 space-y-4">
                    <div>
                        <label for="patient_id"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300">Patient</label>
                        <select id="patient_id" name="patient_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            <option value="">Select Patient</option>
                            <?php
                            // Fetch patients with birth dates for age calculation (exclude health workers by requiring patient_profiles)
                            $patients = [];
                            $sql = "SELECT u.id, u.full_name, p.birth_date, p.child_name 
                                    FROM users u 
                                    INNER JOIN patient_profiles p ON p.user_id = u.id 
                                    WHERE u.role = 'patient' 
                                    AND u.status = 'active'
                                    ORDER BY COALESCE(p.child_name, u.full_name)";
                            $result = $mysqli->query($sql);
                            while ($row = $result->fetch_assoc()) {
                                $displayName = $row['child_name'] ?: $row['full_name'];
                                $birthDate = $row['birth_date'] ? htmlspecialchars($row['birth_date']) : '';
                                echo "<option value='" . $row['id'] . "' data-birth-date='" . $birthDate . "'>" . htmlspecialchars($displayName) . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div>
                        <label for="scheduled_at"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300">Date & Time</label>
                        <input type="datetime-local" id="scheduled_at" name="scheduled_at" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes
                            (Optional)</label>
                        <textarea id="notes" name="notes" rows="3"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"></textarea>
                    </div>

                    <div>
                        <label for="vaccine_id"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300">Vaccine <span
                                class="text-red-500">*</span></label>
                        <select id="vaccine_id" name="vaccine_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            <option value="">Select Vaccine</option>
                            <?php
                            // Fetch vaccines for dropdown - store all vaccines for filtering
                            $sql = "SELECT id, name FROM vaccines ORDER BY name";
                            $result = $mysqli->query($sql);
                            $allVaccines = [];
                            while ($row = $result->fetch_assoc()) {
                                $allVaccines[] = $row;
                                echo "<option value='" . $row['id'] . "' data-vaccine-id='" . $row['id'] . "' data-vaccine-name='" . htmlspecialchars($row['name']) . "'>" . htmlspecialchars($row['name']) . "</option>";
                            }
                            ?>
                        </select>
                        <div id="vaccineRecommendation" class="mt-2 text-sm text-blue-600 dark:text-blue-400 hidden">
                            <span class="material-symbols-outlined text-base align-middle">info</span>
                            <span id="recommendationText"></span>
                        </div>
                    </div>

                    <div>
                        <label for="dosage" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Dosage
                            <span class="text-red-500">*</span></label>
                        <select id="dosage" name="dosage" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            <option value="">Select Dosage</option>
                            <option value="1st Dose">1st Dose</option>
                            <option value="2nd Dose">2nd Dose</option>
                            <option value="Booster">Booster</option>
                            <option value="Additional Dose">Additional Dose</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="weight"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300">Weight (kg) <span
                                    class="text-red-500">*</span></label>
                            <input type="number" id="weight" name="weight" step="0.1" min="0" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                placeholder="e.g. 12.5">
                        </div>
                        <div>
                            <label for="height"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300">Height (cm) <span
                                    class="text-red-500">*</span></label>
                            <input type="number" id="height" name="height" step="0.1" min="0" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                placeholder="e.g. 75.0">
                        </div>
                    </div>
                </form>
            </div>
            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                <button type="button" id="saveAppointmentBtn"
                    class="inline-flex w-full justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 sm:col-start-2 sm:text-sm">
                    Save
                </button>
                <button type="button" id="cancelAppointmentBtn"
                    class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 sm:col-start-1 sm:mt-0 sm:text-sm dark:bg-gray-600 dark:text-white dark:border-gray-600 dark:hover:bg-gray-500">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Modal elements
    const appointmentModal = document.getElementById('appointmentModal');
    const newAppointmentBtn = document.getElementById('newAppointmentBtn');
    const cancelAppointmentBtn = document.getElementById('cancelAppointmentBtn');
    const saveAppointmentBtn = document.getElementById('saveAppointmentBtn');
    const appointmentForm = document.getElementById('appointmentForm');

    // Age-based vaccine schedule (using WHO-style timing, in weeks)
    // We will map the baby's age to a specific "window" and ONLY show vaccines allowed in that window.
    // Windows (approximate, based on weeks since birth):
    // - Birth: 0–1 week          → BCG, HepB (birth), OPV (birth)
    // - 6 weeks: 5–7 weeks       → DPT, HepB, Hib, OPV
    // - 10 weeks: 9–11 weeks     → DPT, HepB, Hib, OPV
    // - 14 weeks: 13–15 weeks    → DPT, HepB, Hib, OPV, IPV
    // - 9–12 months: 39–52 weeks → MMR (1st dose)
    // - 12–15 months: 52–65 weeks→ DPT booster, Hib booster, MMR (2nd dose), IPV
    // - 4–6 years: 208–312 weeks → DPT booster, OPV booster, MMR (2nd dose)

    const ageWindows = [
        {
            id: 'birth',
            label: 'Birth (0 months)',
            minWeeks: 0,
            maxWeeks: 1,
            vaccines: ['BCG', 'HEPB', 'OPV']
        },
        {
            id: '6w',
            label: '6 weeks',
            minWeeks: 5,
            maxWeeks: 7,
            vaccines: ['DPT', 'HEPB', 'HIB', 'OPV']
        },
        {
            id: '10w',
            label: '10 weeks',
            minWeeks: 9,
            maxWeeks: 11,
            vaccines: ['DPT', 'HEPB', 'HIB', 'OPV']
        },
        {
            id: '14w',
            label: '14 weeks',
            minWeeks: 13,
            maxWeeks: 15,
            vaccines: ['DPT', 'HEPB', 'HIB', 'OPV', 'IPV']
        },
        {
            id: '9to12m',
            label: '9–12 months',
            minWeeks: 39,
            maxWeeks: 52,
            vaccines: ['MMR']
        },
        {
            id: '12to15m',
            label: '12–15 months',
            minWeeks: 52,
            maxWeeks: 65,
            vaccines: ['DPT', 'HIB', 'MMR', 'IPV']
        },
        {
            id: '4to6y',
            label: '4–6 years',
            minWeeks: 208,
            maxWeeks: 312,
            vaccines: ['DPT', 'OPV', 'MMR']
        }
    ];

    // Calculate age in weeks from birth date
    function calculateAgeInWeeks(birthDate) {
        if (!birthDate) return null;
        const birth = new Date(birthDate);
        const today = new Date();
        const diffTime = today - birth;
        const diffWeeks = Math.floor(diffTime / (1000 * 60 * 60 * 24 * 7));
        return diffWeeks;
    }

    // Determine which age window the baby falls into
    function getAgeWindow(ageInWeeks) {
        if (ageInWeeks === null || ageInWeeks < 0) return null;
        for (const win of ageWindows) {
            if (ageInWeeks >= win.minWeeks && ageInWeeks <= win.maxWeeks) {
                return win;
            }
        }
        return null;
    }

    // Filter vaccines based on patient age
    function filterVaccinesByAge(ageInWeeks) {
        const vaccineSelect = document.getElementById('vaccine_id');
        const recommendationDiv = document.getElementById('vaccineRecommendation');
        const recommendationText = document.getElementById('recommendationText');
        
        if (!vaccineSelect) return;

        // Get all vaccine options
        const allOptions = Array.from(vaccineSelect.options);
        
        if (ageInWeeks === null || ageInWeeks < 0) {
            // Show all vaccines if no age data
            allOptions.forEach(option => {
                option.style.display = '';
                option.disabled = false;
            });
            recommendationDiv.classList.add('hidden');
            return;
        }

        // Determine the applicable age window and allowed vaccines
        const window = getAgeWindow(ageInWeeks);
        const allowedVaccines = window ? window.vaccines : [];

        // Show/hide and enable/disable options based on allowed vaccines
        allOptions.forEach(option => {
            if (option.value === '') {
                // Keep the "Select Vaccine" option
                option.style.display = '';
                option.disabled = false;
                return;
            }

            const originalName = option.getAttribute('data-vaccine-name') || option.textContent.trim().replace(' ⭐', '');
            const vaccineName = originalName.toUpperCase();

            if (window && allowedVaccines.includes(vaccineName)) {
                option.style.display = '';
                option.disabled = false;
                option.textContent = originalName + ' ⭐';
            } else if (window) {
                // Inside a defined window: hide all vaccines that are not allowed
                option.style.display = 'none';
                option.disabled = true;
                option.textContent = originalName;
            } else {
                // No specific window (age outside defined schedule): show nothing except placeholder
                option.style.display = 'none';
                option.disabled = true;
                option.textContent = originalName;
            }
        });

        // Show recommendation message
        const ageInMonths = Math.floor(ageInWeeks / 4.33);
        if (window) {
            const vaccineLabels = allowedVaccines.join(', ');
            recommendationText.innerHTML =
                `<strong>Age:</strong> ${ageInWeeks} weeks (${ageInMonths} months) - ` +
                `<strong>Schedule window:</strong> ${window.label} - ` +
                `<strong>Allowed vaccines:</strong> ${vaccineLabels}`;
            recommendationDiv.classList.remove('hidden');
        } else {
            recommendationText.textContent =
                `Baby is ${ageInWeeks} weeks (${ageInMonths} months) old. ` +
                `No specific vaccines defined for this age window in the schedule.`;
            recommendationDiv.classList.remove('hidden');
        }
    }

    // Helper: reset dosage select to editable state with all options
    function resetDosageField() {
        const dosageSelect = document.getElementById('dosage');
        if (!dosageSelect) return;
        dosageSelect.disabled = false;
        const options = Array.from(dosageSelect.options);
        options.forEach(opt => {
            opt.disabled = false;
            opt.style.display = '';
            opt.style.color = '';
            // Remove "(Already received)" text if present
            opt.textContent = opt.textContent.replace(' (Already received)', '');
        });
        dosageSelect.value = '';
        const badge = document.getElementById('dosageAutoFilled');
        if (badge) badge.remove();
    }

    // Helper: lock dosage to a single auto-selected value (no other options visible)
    function lockDosageTo(value) {
        const dosageSelect = document.getElementById('dosage');
        if (!dosageSelect) return;
        const options = Array.from(dosageSelect.options);
        let found = false;
        options.forEach(opt => {
            if (opt.value === value) {
                opt.disabled = false;
                opt.style.display = '';
                found = true;
            } else {
                opt.disabled = true;
                opt.style.display = 'none';
            }
        });
        if (found) {
            dosageSelect.value = value;
            dosageSelect.disabled = true;
        }
    }

    // Auto-populate dosage based on patient's vaccination history and age
    async function autoPopulateDosage(patientId, vaccineId, ageInWeeks = null) {
        const dosageSelect = document.getElementById('dosage');
        if (!dosageSelect || !patientId || !vaccineId) {
            return;
        }

        try {
            const response = await fetch(`get_vaccination_history.php?patient_id=${patientId}&vaccine_id=${vaccineId}`);
            if (!response.ok) {
                throw new Error('Failed to fetch vaccination history');
            }

            const data = await response.json();
            
            // Get all completed doses from history
            const completedDoses = [];
            if (data.success && data.history) {
                data.history.forEach(item => {
                    if (item.type === 'completed' && item.dosage_text) {
                        completedDoses.push(item.dosage_text);
                    } else if (item.type === 'completed' && item.dose) {
                        // Convert dose number to dosage text
                        if (item.dose == 1) completedDoses.push('1st Dose');
                        else if (item.dose == 2) completedDoses.push('2nd Dose');
                        else if (item.dose == 3) completedDoses.push('3rd Dose');
                        else if (item.dose == 4) completedDoses.push('Booster');
                        else if (item.dose == 5) completedDoses.push('Additional Dose');
                    } else if (item.dosage_text) {
                        // Also include scheduled doses to prevent duplicate scheduling
                        completedDoses.push(item.dosage_text);
                    }
                });
            }
            
            // Disable completed doses in dropdown
            const options = Array.from(dosageSelect.options);
            options.forEach(option => {
                if (option.value && completedDoses.includes(option.value)) {
                    option.disabled = true;
                    option.style.color = '#9ca3af';
                    option.textContent = option.value + ' (Already received)';
                } else if (option.value) {
                    option.disabled = false;
                    option.style.color = '';
                    // Remove "(Already received)" if it was added
                    option.textContent = option.textContent.replace(' (Already received)', '');
                }
            });
            
            if (data.success && data.next_dosage) {
                // Set the dosage dropdown to the next recommended dose
                const matchingOption = options.find(opt => opt.value === data.next_dosage && !opt.disabled);
                
                if (matchingOption) {
                    lockDosageTo(data.next_dosage);
                    // Show a brief notification
                    const dosageLabel = document.querySelector('label[for="dosage"]');
                    if (dosageLabel) {
                        // Remove existing badge if any
                        const existingBadge = document.getElementById('dosageAutoFilled');
                        if (existingBadge) existingBadge.remove();
                        
                        const badge = document.createElement('span');
                        badge.className = 'auto-filled-badge ml-2 text-xs text-green-600 dark:text-green-400';
                        badge.textContent = '(Auto-filled)';
                        badge.id = 'dosageAutoFilled';
                        dosageLabel.appendChild(badge);
                        
                        // Remove badge after 3 seconds
                        setTimeout(() => {
                            if (badge.parentNode) {
                                badge.remove();
                            }
                        }, 3000);
                    }
                } else {
                    // If next_dosage is disabled, find first available dose
                    const firstAvailable = options.find(opt => opt.value && !opt.disabled);
                    if (firstAvailable) {
                        lockDosageTo(firstAvailable.value);
                    } else {
                        lockDosageTo('1st Dose');
                    }
                }
            } else {
                // If no history, default to 1st Dose and lock
                lockDosageTo('1st Dose');
            }
        } catch (error) {
            console.error('Error fetching vaccination history:', error);
            // Default to 1st Dose on error and lock
            lockDosageTo('1st Dose');
        }
    }

    // Store current patient age for dosage calculation
    let currentPatientAge = null;

    // Handle patient selection
    const patientSelect = document.getElementById('patient_id');
    if (patientSelect) {
        patientSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const birthDate = selectedOption.getAttribute('data-birth-date');
            const patientId = this.value;
            
            // Reset vaccine selection when patient changes
            const vaccineSelect = document.getElementById('vaccine_id');
            if (vaccineSelect) {
                vaccineSelect.value = '';
            }
            
            // Reset dosage when patient changes
            resetDosageField();
            
            if (birthDate) {
                currentPatientAge = calculateAgeInWeeks(birthDate);
                filterVaccinesByAge(currentPatientAge);
            } else {
                // Reset to show all vaccines if no birth date
                currentPatientAge = null;
                filterVaccinesByAge(null);
            }
        });
    }

    // Handle vaccine selection
    const vaccineSelect = document.getElementById('vaccine_id');
    if (vaccineSelect) {
        vaccineSelect.addEventListener('change', function() {
            const vaccineId = this.value;
            const patientId = patientSelect ? patientSelect.value : null;
            
            // Auto-populate dosage when vaccine is selected (only if patient is selected)
            if (vaccineId && patientId) {
                autoPopulateDosage(patientId, vaccineId, currentPatientAge);
            } else {
                // Reset dosage if no patient or vaccine selected
                resetDosageField();
            }
        });
    }

    // Open modal
    newAppointmentBtn.addEventListener('click', () => {
        appointmentModal.classList.remove('hidden');
        // Set default datetime to now, rounded to nearest 30 minutes
        const now = new Date();
        const minutes = now.getMinutes();
        now.setMinutes(minutes - (minutes % 30), 0, 0);
        document.getElementById('scheduled_at').value = now.toISOString().slice(0, 16);
        
        // Reset all fields
        document.getElementById('patient_id').value = '';
        document.getElementById('vaccine_id').value = '';
        document.getElementById('dosage').value = '';
        document.getElementById('notes').value = '';
        document.getElementById('weight').value = '';
        document.getElementById('height').value = '';
        
        // Reset vaccine filter when opening modal - show all vaccines initially
        const vaccineSelect = document.getElementById('vaccine_id');
        if (vaccineSelect) {
            const allOptions = Array.from(vaccineSelect.options);
            allOptions.forEach(option => {
                option.style.display = '';
                option.disabled = false;
                // Restore original name
                const originalName = option.getAttribute('data-vaccine-name');
                if (originalName) {
                    option.textContent = originalName;
                } else {
                    option.textContent = option.textContent.replace(' ⭐', '');
                }
            });
        }
        document.getElementById('vaccineRecommendation').classList.add('hidden');
        
        // Reset dosage
        resetDosageField();
        
        // Reset patient age
        currentPatientAge = null;
        
        document.getElementById('patient_id').focus();
    });

    // Close modal
    function closeAppointmentModal() {
        appointmentModal.classList.add('hidden');
        appointmentForm.reset();
        // Reset vaccine filter
        const vaccineSelect = document.getElementById('vaccine_id');
        if (vaccineSelect) {
            const allOptions = Array.from(vaccineSelect.options);
            allOptions.forEach(option => {
                option.style.display = '';
                option.disabled = false;
                // Restore original name
                const originalName = option.getAttribute('data-vaccine-name');
                if (originalName) {
                    option.textContent = originalName;
                } else {
                    option.textContent = option.textContent.replace(' ⭐', '');
                }
            });
        }
        document.getElementById('vaccineRecommendation').classList.add('hidden');
        
        // Reset dosage
        resetDosageField();
    }

    cancelAppointmentBtn.addEventListener('click', closeAppointmentModal);

    // Close modal when clicking outside
    window.addEventListener('click', (e) => {
        if (e.target === appointmentModal) {
            closeAppointmentModal();
        }
    });

    // Save appointment
    saveAppointmentBtn.addEventListener('click', async () => {
        const formData = {
            patient_id: document.getElementById('patient_id').value,
            vaccine_id: document.getElementById('vaccine_id').value,
            dosage: document.getElementById('dosage').value,
            weight: document.getElementById('weight').value,
            height: document.getElementById('height').value,
            scheduled_at: document.getElementById('scheduled_at').value,
            notes: document.getElementById('notes').value,
            status: 'scheduled'
        };

        // Validate required fields
        const requiredFields = ['patient_id', 'vaccine_id', 'dosage', 'scheduled_at', 'weight', 'height'];
        const missingFields = requiredFields.filter(field => !formData[field]);

        if (missingFields.length > 0) {
            showNotification(`Please fill in all required fields: ${missingFields.join(', ')}`, 'error');
            return;
        }

        try {
            // Show loading state
            const saveBtn = document.getElementById('saveAppointmentBtn');
            const originalBtnText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const response = await fetch('add_appointment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                showNotification('Appointment scheduled successfully!', 'success');
                appointmentModal.classList.add('hidden');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                throw new Error(data.error || 'Failed to save appointment');
            }
        } catch (error) {
            console.error('Error:', error);
            showNotification(error.message || 'Failed to save appointment. Please try again.', 'error');
        } finally {
            const saveBtn = document.getElementById('saveAppointmentBtn');
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = 'Save';
            }
        }
    });

    // Helper function to show notifications
    function showNotification(message, type = 'success') {
        // Check if notification already exists
        let notification = document.querySelector('.notification-message');

        if (!notification) {
            notification = document.createElement('div');
            notification.className = 'notification-message fixed top-4 right-4 p-4 rounded-md shadow-lg z-50';
            document.body.appendChild(notification);
        }

        // Update notification content and style
        notification.className = `notification-message fixed top-4 right-4 p-4 rounded-md shadow-lg z-50 ${type === 'success' ? 'bg-green-500' : 'bg-red-500'
            } text-white`;
        notification.textContent = message;

        // Remove notification after 3 seconds
        setTimeout(() => {
            notification.classList.add('opacity-0', 'transition-opacity', 'duration-300');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }
</script>