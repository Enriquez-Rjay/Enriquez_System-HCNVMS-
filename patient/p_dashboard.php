<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

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

// Fetch user info from users table (authoritative full_name/email)
$user = ['full_name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Patient')];
$stmt = $mysqli->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    if (!empty($row['full_name'])) {
      $user['full_name'] = $row['full_name'];
    }
    if (!empty($row['email'])) {
      $user['email'] = $row['email'];
    }
  }
  $stmt->close();
}

// Fetch patient profile (optional extra details)
$profile = null;
$stmt = $mysqli->prepare('SELECT child_name, birth_date, guardian_name, address FROM patient_profiles WHERE user_id = ? LIMIT 1');
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $profile = $res->fetch_assoc() ?: null;
  $stmt->close();
}

// Next scheduled appointment with vaccine info
// This includes ALL appointments for the patient, whether created by health worker or patient
// Health worker appointments are identified by health_worker_id being set (not NULL)
$nextAppointment = null;
$stmt = $mysqli->prepare("
  SELECT a.id, a.scheduled_at, a.status, a.notes, a.dosage, 
         v.name AS vaccine_name, v.id AS vaccine_id,
         u.full_name AS health_worker_name,
         a.health_worker_id
  FROM appointments a
  LEFT JOIN vaccines v ON v.id = a.vaccine_id
  LEFT JOIN users u ON u.id = a.health_worker_id AND u.role = 'health_worker'
  WHERE a.patient_id = ? 
  AND a.status IN ('scheduled', 'pending')
  AND DATE(a.scheduled_at) >= CURDATE()
  AND a.scheduled_at >= NOW()
  ORDER BY a.scheduled_at ASC 
  LIMIT 1
");
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $nextAppointment = $res->fetch_assoc() ?: null;
  $stmt->close();
}

// All upcoming appointments
// This includes ALL appointments for the patient, whether created by health worker or patient
$upcomingAppointments = [];
$stmt = $mysqli->prepare("
  SELECT a.id, a.scheduled_at, a.status, a.notes, a.dosage, a.created_at,
         v.name AS vaccine_name, v.id AS vaccine_id,
         u.full_name AS health_worker_name,
         a.health_worker_id
  FROM appointments a
  LEFT JOIN vaccines v ON v.id = a.vaccine_id
  LEFT JOIN users u ON u.id = a.health_worker_id AND u.role = 'health_worker'
  WHERE a.patient_id = ? 
  AND a.status IN ('scheduled', 'pending')
  AND DATE(a.scheduled_at) >= CURDATE()
  AND a.scheduled_at >= NOW()
  ORDER BY a.scheduled_at ASC 
  LIMIT 10
");
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $upcomingAppointments[] = $row;
  }
  $stmt->close();
}

// Past appointments (completed or past scheduled)
$pastAppointments = [];
$stmt = $mysqli->prepare("
  SELECT a.id, a.scheduled_at, a.status, a.notes, a.dosage, a.created_at,
         v.name AS vaccine_name, v.id AS vaccine_id,
         u.full_name AS health_worker_name
  FROM appointments a
  LEFT JOIN vaccines v ON v.id = a.vaccine_id
  LEFT JOIN users u ON u.id = a.health_worker_id
  WHERE a.patient_id = ? 
  AND (a.status = 'completed' OR a.scheduled_at < NOW())
  ORDER BY a.scheduled_at DESC 
  LIMIT 10
");
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $pastAppointments[] = $row;
  }
  $stmt->close();
}

// Vaccination progress: number of distinct vaccines received vs total vaccines
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

// Recent vaccination records
$recentVaccines = [];
$stmt = $mysqli->prepare('SELECT v.name, vr.date_given, vr.dose FROM vaccination_records vr JOIN vaccines v ON v.id = vr.vaccine_id WHERE vr.patient_id = ? ORDER BY vr.date_given DESC LIMIT 5');
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $recentVaccines[] = $row;
  }
  $stmt->close();
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1.0" name="viewport" />
  <title>HCNVMS - Patient Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;700;900&amp;display=swap"
    rel="stylesheet" />
  <link
    href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
    rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <style type="text/tailwindcss">
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
            "primary": "#4A90E2",
            "background-light": "#F4F7F9",
            "background-dark": "#101922",
            "text-light": "#334155",
            "text-dark": "#E2E8F0",
            "text-muted-light": "#64748B",
            "text-muted-dark": "#94A3B8",
            "card-light": "#FFFFFF",
            "card-dark": "#1E293B",
            "border-light": "#E2E8F0",
            "border-dark": "#334155",
            "status-green": "#28A745",
            "status-orange": "#FFC107",
            "status-red": "#DC3545",
          },
          fontFamily: {
            "display": ["Public Sans", "sans-serif"]
          },
          borderRadius: { "DEFAULT": "0.5rem", "lg": "0.75rem", "xl": "1rem", "full": "9999px" },
        },
      },
    }
  </script>
</head>

<body class="font-display bg-background-light dark:bg-background-dark text-text-light dark:text-text-dark">
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
              <p class="text-text-muted-light dark:text-text-muted-dark text-sm font-normal">Newborn Vaccination</p>
            </div>
          </div>
          <nav class="flex flex-col gap-2 mt-4">
            <a class="flex items-center gap-3 px-3 py-2 rounded-lg bg-primary/10 text-primary dark:bg-primary/20"
              href="/HealthCenter/patient/p_dashboard.php">
              <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">dashboard</span>
              <p class="text-sm font-medium">Dashboard</p>
            </a>
            <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
              href="/HealthCenter/patient/vaccination_record.php">
              <span class="material-symbols-outlined">vaccines</span>
              <p class="text-sm font-medium">Vaccination Record</p>
            </a>
            <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
              href="/HealthCenter/patient/p_appointments.php">
              <span class="material-symbols-outlined">calendar_month</span>
              <p class="text-sm font-medium">Appointments</p>
            </a>
          </nav>
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
        <div class="mx-auto max-w-6xl">
          <!-- PageHeading -->
          <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
            <div class="flex flex-col gap-1">
              <h1 class="text-3xl font-bold tracking-tight">Welcome, <?= htmlspecialchars($user['full_name']) ?></h1>
              <p class="text-text-muted-light dark:text-text-muted-dark text-base font-normal">Here's an overview of
                your vaccination status.</p>
            </div>
            <!-- ButtonGroup -->
            <div class="flex flex-wrap gap-3">
              <a href="/HealthCenter/patient/p_appointments.php"
                class="flex min-w-[84px] cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-lg h-11 px-4 bg-primary text-white text-sm font-bold shadow-sm hover:bg-primary/90">
                <span class="material-symbols-outlined">event</span> <span class="truncate">Manage Appointments</span>
              </a>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-10">
            <!-- Left Column -->
            <div class="lg:col-span-2 flex flex-col gap-8 lg:gap-10">
              <!-- Main Status Card (without avatar/logo) -->
              <div class="w-full bg-card-light dark:bg-card-dark p-6 rounded-xl shadow-sm border border-border-light dark:border-border-dark">
                <div class="flex flex-col gap-4">
                  <!-- Patient Name and Upcoming Appointment Side by Side -->
                  <div class="flex flex-col md:flex-row gap-6 items-start">
                    <!-- Patient Info Section -->
                    <div class="flex-1 border-r-0 md:border-r-2 border-border-light dark:border-border-dark pr-0 md:pr-6">
                      <div class="flex items-center gap-3 mb-2">
                        <p class="text-2xl font-bold tracking-tight"><?= htmlspecialchars($user['full_name']) ?></p>
                      </div>
                      <p class="text-text-muted-light dark:text-text-muted-dark text-base font-normal">Born:
                        <?= htmlspecialchars($profile['birth_date'] ?? '') ?></p>
                    </div>
                    <!-- Upcoming Appointment Section -->
                    <?php if ($nextAppointment): ?>
                      <div class="flex-1 border-l-0 md:border-l-2 border-border-light dark:border-border-dark pl-0 md:pl-6">
                        <div class="flex items-start gap-3">
                          <span class="material-symbols-outlined text-primary mt-0.5">event</span>
                          <div class="flex-1">
                            <p class="text-xs font-semibold text-text-muted-light dark:text-text-muted-dark mb-2 uppercase tracking-wide">Next Appointment</p>
                            <p class="font-bold text-base text-text-light dark:text-text-dark mb-1">
                              <?php if (!empty($nextAppointment['vaccine_name'])): ?>
                                <?= htmlspecialchars($nextAppointment['vaccine_name']) ?>
                                <?php if (!empty($nextAppointment['dosage'])): ?>
                                  <span class="text-sm font-normal text-text-muted-light dark:text-text-muted-dark">(<?= htmlspecialchars($nextAppointment['dosage']) ?>)</span>
                                <?php endif; ?>
                              <?php else: ?>
                                Appointment
                              <?php endif; ?>
                            </p>
                            <div class="flex items-center gap-2 mt-2">
                              <span class="material-symbols-outlined text-xs text-text-muted-light dark:text-text-muted-dark">calendar_month</span>
                              <p class="text-sm text-text-muted-light dark:text-text-muted-dark">
                                <?= htmlspecialchars(date('M j, Y', strtotime($nextAppointment['scheduled_at']))) ?>
                              </p>
                            </div>
                            <div class="flex items-center gap-2 mt-1">
                              <span class="material-symbols-outlined text-xs text-text-muted-light dark:text-text-muted-dark">schedule</span>
                              <p class="text-sm text-text-muted-light dark:text-text-muted-dark">
                                <?= htmlspecialchars(date('g:i A', strtotime($nextAppointment['scheduled_at']))) ?>
                              </p>
                            </div>
                            <?php if (!empty($nextAppointment['health_worker_id']) && !empty($nextAppointment['health_worker_name'])): ?>
                              <div class="flex items-center gap-2 mt-1">
                                <span class="material-symbols-outlined text-xs text-text-muted-light dark:text-text-muted-dark">person</span>
                                <p class="text-sm text-text-muted-light dark:text-text-muted-dark">
                                  Scheduled by: <?= htmlspecialchars($nextAppointment['health_worker_name']) ?>
                                </p>
                              </div>
                            <?php elseif (!empty($nextAppointment['health_worker_id'])): ?>
                              <div class="flex items-center gap-2 mt-1">
                                <span class="material-symbols-outlined text-xs text-text-muted-light dark:text-text-muted-dark">person</span>
                                <p class="text-sm text-text-muted-light dark:text-text-muted-dark">
                                  Scheduled by Health Worker
                                </p>
                              </div>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    <?php else: ?>
                      <div class="flex-1 border-l-0 md:border-l-2 border-border-light dark:border-border-dark pl-0 md:pl-6">
                        <div class="flex items-start gap-3">
                          <span class="material-symbols-outlined text-text-muted-light dark:text-text-muted-dark mt-0.5">event</span>
                          <div class="flex-1">
                            <p class="text-xs font-semibold text-text-muted-light dark:text-text-muted-dark mb-2 uppercase tracking-wide">Next Appointment</p>
                            <p class="text-sm text-text-muted-light dark:text-text-muted-dark">No upcoming appointments scheduled.</p>
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                  <p class="text-base font-normal">All vaccinations progress is shown below.</p>
                  <div class="mt-2 flex flex-wrap gap-3">
                    <a href="/HealthCenter/patient/vaccination_record.php"
                      class="flex min-w-[84px] max-w-[480px] cursor-pointer items-center justify-center overflow-hidden rounded-lg h-10 px-4 bg-primary/10 text-primary text-sm font-bold hover:bg-primary/20 dark:bg-primary/20 dark:hover:bg-primary/30">
                      <span class="truncate">View Full Record</span>
                    </a>
                    <a href="/HealthCenter/patient/certificate.php"
                      class="flex min-w-[84px] max-w-[480px] cursor-pointer items-center justify-center overflow-hidden rounded-lg h-10 px-4 bg-green-500/10 text-green-600 text-sm font-bold hover:bg-green-500/20 dark:bg-green-500/20 dark:hover:bg-green-500/30 dark:text-green-400">
                      <span class="material-symbols-outlined text-base mr-2">verified</span>
                      <span class="truncate">Digital Certificate</span>
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Vaccination Schedule -->
          <div class="w-full bg-card-light dark:bg-card-dark p-6 rounded-xl shadow-sm mt-8">
            <div class="flex flex-col gap-3">
              <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                  <p class="text-lg font-bold">Vaccination Progress</p>
                  <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold <?= $vaccinationStatus === 'complete' ? 'bg-status-green/20 text-status-green dark:bg-status-green/30' : ($vaccinationStatus === 'partial' ? 'bg-status-orange/20 text-status-orange dark:bg-status-orange/30' : 'bg-status-red/20 text-status-red dark:bg-status-red/30') ?>">
                    <span class="material-symbols-outlined text-xs mr-1" style="font-variation-settings: 'FILL' <?= $vaccinationStatus === 'complete' ? '1' : '0' ?>;">
                      <?= $vaccinationStatus === 'complete' ? 'check_circle' : ($vaccinationStatus === 'partial' ? 'pending' : 'cancel') ?>
                    </span>
                    Status: <?= $vaccinationStatusText ?>
                  </span>
                </div>
                <p class="text-sm font-medium"><?= $progressPercent ?>% Complete</p>
              </div>
              <div class="w-full rounded-full bg-background-light dark:bg-background-dark h-3">
                <div class="h-3 rounded-full <?= $vaccinationStatus === 'complete' ? 'bg-status-green' : ($vaccinationStatus === 'partial' ? 'bg-status-orange' : 'bg-status-red') ?>" style="width: <?= $progressPercent ?>%;"></div>
              </div>
              <div class="flex items-center justify-between mt-2">
                <p class="text-text-muted-light dark:text-text-muted-dark text-sm font-normal">
                  <?= $vaccinationStatus === 'complete' ? 'All recommended vaccinations are up to date!' : ($vaccinationStatus === 'partial' ? 'Some vaccinations are pending. Please follow the recommended schedule.' : 'Vaccination schedule needs attention. Please schedule appointments.') ?>
                </p>
                <p class="text-xs text-text-muted-light dark:text-text-muted-dark">
                  <?= $vaccinesReceived ?> of <?= $vaccinesTotal ?> vaccines received
                </p>
              </div>
            </div>
          </div>
          <!-- Announcements/Health Tips Card -->
          <div class="bg-card-light dark:bg-card-dark rounded-xl shadow-sm mt-6 p-6">
            <h3 class="text-lg font-bold mb-4">Announcements &amp; Health Tips</h3>
            <div class="flex items-start gap-4 p-4 rounded-lg bg-primary/10 border border-primary/20">
              <span class="material-symbols-outlined text-primary mt-1">campaign</span>
              <div>
                <h4 class="font-bold">Flu Shot Clinic Now Open</h4>
                <p class="text-sm text-text-muted-light dark:text-text-muted-dark mt-1">Our annual flu shot clinic is
                  now available for family members. Schedule an appointment today.</p>
              </div>
              <button class="ml-auto text-text-muted-light dark:text-text-muted-dark">
                <span class="material-symbols-outlined">close</span>
              </button>
            </div>
          </div>
        </div>
        <!-- Right Column -->
        <div class="lg:col-span-1 flex flex-col gap-6 lg:gap-8">
          <!-- Recent Vaccination Records Card -->
          <div class="bg-card-light dark:bg-card-dark rounded-xl shadow-sm mt-6 p-6">
            <h3 class="text-lg font-bold mb-4">Recent Vaccines Received</h3>
            <?php if (!empty($recentVaccines)): ?>
              <div class="flex flex-col gap-3">
                <?php foreach ($recentVaccines as $vaccine): ?>
                  <div class="flex items-center justify-between border-b border-border-light dark:border-border-dark pb-2">
                    <div>
                      <p class="font-medium text-sm"><?= htmlspecialchars($vaccine['name']) ?></p>
                      <p class="text-xs text-text-muted-light dark:text-text-muted-dark">
                        Dose <?= htmlspecialchars($vaccine['dose']) ?> - 
                        <?= htmlspecialchars(date('M j, Y', strtotime($vaccine['date_given']))) ?>
                      </p>
                    </div>
                    <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-sm">check_circle</span>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="mt-4">
                <a href="/HealthCenter/patient/vaccination_record.php"
                  class="text-sm text-primary hover:underline">View All Records →</a>
              </div>
            <?php else: ?>
              <p class="text-sm text-text-muted-light">No vaccination records yet.</p>
            <?php endif; ?>
          </div>
          <!-- resources removed as requested -->
        </div>
    </div>
  </div>
  </main>
  </div>
  </div>
</body>

</html>