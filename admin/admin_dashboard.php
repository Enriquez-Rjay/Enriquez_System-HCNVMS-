<?php
require_once __DIR__ . '/../config/session.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  $_SESSION['error'] = 'Please sign in as admin';
  header('Location: /HealthCenter/login.php');
  exit;
}
$displayName = $_SESSION['username'] ?? 'Admin User';

// Database connection for metrics
$mysqli = require __DIR__ . '/../config/db.php';

// Compute dashboard metrics (safe fallbacks if tables absent)
$totalBabies = 0;
$coveragePercent = 0;
$vaccineTypes = 0;
$appointmentsToday = 0;
$vaccinesDueThisWeek = 0;
$totalOverdue = 0;

// total patients (users with role 'patient')
if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role='patient'")) {
  $row = $res->fetch_assoc();
  $totalBabies = (int) ($row['c'] ?? 0);
  $res->free_result();
}

// vaccinated patients (distinct patient_id in vaccination_records)
if ($res = $mysqli->query("SELECT COUNT(DISTINCT patient_id) AS c FROM vaccination_records")) {
  $row = $res->fetch_assoc();
  $vaccinated = (int) ($row['c'] ?? 0);
  $res->free_result();
  if ($totalBabies > 0) {
    $coveragePercent = (int) round($vaccinated / $totalBabies * 100);
  }
}

// vaccine types count
if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM vaccines")) {
  $row = $res->fetch_assoc();
  $vaccineTypes = (int) ($row['c'] ?? 0);
  $res->free_result();
}

// appointments scheduled today
if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM appointments WHERE DATE(scheduled_at) = CURDATE() AND status IN ('scheduled','pending')")) {
  $row = $res->fetch_assoc();
  $appointmentsToday = (int) ($row['c'] ?? 0);
  $res->free_result();
}

// Get weekly appointments (Due This Week)
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d 23:59:59', strtotime('sunday this week'));
if ($stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM appointments WHERE scheduled_at BETWEEN ? AND ? AND status IN ('scheduled','pending')")) {
  $stmt->bind_param('ss', $weekStart, $weekEnd);
  $stmt->execute();
  $result = $stmt->get_result();
  $vaccinesDueThisWeek = (int) ($result->fetch_assoc()['total'] ?? 0);
  $stmt->close();
}

// Get overdue appointments
if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM appointments WHERE DATE(scheduled_at) < CURDATE() AND status IN ('scheduled','pending')")) {
  $row = $res->fetch_assoc();
  $totalOverdue = (int) ($row['c'] ?? 0);
  $res->free_result();
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
if ($res = $mysqli->query($genderQuery)) {
  while ($row = $res->fetch_assoc()) {
    if ($row['gender'] === 'Male' || $row['gender'] === 'Female') {
      $genderStats[$row['gender']] = (int)$row['vaccinated_count'];
    }
  }
  $res->free_result();
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
if ($res = $mysqli->query($ageQuery)) {
  while ($row = $res->fetch_assoc()) {
    if (isset($ageStats[$row['age_group']])) {
      $ageStats[$row['age_group']] = (int)$row['vaccinated_count'];
    }
  }
  $res->free_result();
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
if ($res = $mysqli->query($genderQuery)) {
  while ($row = $res->fetch_assoc()) {
    if ($row['gender'] === 'Male' || $row['gender'] === 'Female') {
      $genderStats[$row['gender']] = (int)$row['vaccinated_count'];
    }
  }
  $res->free_result();
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
if ($res = $mysqli->query($ageQuery)) {
  while ($row = $res->fetch_assoc()) {
    if (isset($ageStats[$row['age_group']])) {
      $ageStats[$row['age_group']] = (int)$row['vaccinated_count'];
    }
  }
  $res->free_result();
}

// Coverage by vaccine (count vaccinated per vaccine)
$coverageByVaccine = [];
if ($res = $mysqli->query("SELECT v.id, v.name, COALESCE(COUNT(vr.id),0) AS doses FROM vaccines v LEFT JOIN vaccination_records vr ON vr.vaccine_id = v.id GROUP BY v.id, v.name ORDER BY v.name")) {
  while ($row = $res->fetch_assoc()) {
    $name = $row['name'];
    $doses = (int) $row['doses'];
    // percent relative to total patients (if total zero, percent 0)
    $percent = ($totalBabies > 0) ? round(min(100, ($doses / $totalBabies) * 100)) : 0;
    $coverageByVaccine[] = ['name' => $name, 'doses' => $doses, 'percent' => $percent];
  }
  $res->free_result();
}

// Vaccination status breakdown: fully / partially / none
$statusBreakdown = ['fully' => 0, 'partially' => 0, 'none' => 0];
// total vaccine types to define "fully"
$totalVaccineTypes = 0;
if ($r = $mysqli->query("SELECT COUNT(*) AS c FROM vaccines")) {
  $rr = $r->fetch_assoc();
  $totalVaccineTypes = (int) ($rr['c'] ?? 0);
  $r->free_result();
}
if ($totalBabies > 0) {
  // fully: patients having at least one record for every vaccine type
  $fullyQuery = "SELECT COUNT(*) AS c FROM users u WHERE u.role='patient' AND NOT EXISTS (SELECT 1 FROM vaccines v WHERE NOT EXISTS (SELECT 1 FROM vaccination_records vr WHERE vr.patient_id = u.id AND vr.vaccine_id = v.id))";
  if ($r = $mysqli->query($fullyQuery)) {
    $row = $r->fetch_assoc();
    $statusBreakdown['fully'] = (int) ($row['c'] ?? 0);
    $r->free_result();
  }
  // partially: patients with at least one vaccination but not fully
  $partialQuery = "SELECT COUNT(DISTINCT u.id) AS c FROM users u JOIN vaccination_records vr ON vr.patient_id = u.id WHERE u.role='patient'";
  if ($r = $mysqli->query($partialQuery)) {
    $row = $r->fetch_assoc();
    $withAny = (int) ($row['c'] ?? 0);
    $r->free_result();
    $statusBreakdown['partially'] = max(0, $withAny - $statusBreakdown['fully']);
  }
  $statusBreakdown['none'] = max(0, $totalBabies - $statusBreakdown['fully'] - $statusBreakdown['partially']);
}

// Low stock alerts (aggregate quantities per vaccine)
$lowStockAlerts = [];
if ($res = $mysqli->query("SELECT v.id, v.name, COALESCE(SUM(b.quantity_available),0) AS qty FROM vaccines v LEFT JOIN vaccine_batches b ON b.vaccine_id = v.id GROUP BY v.id, v.name HAVING qty <= 50 ORDER BY qty ASC")) {
  while ($row = $res->fetch_assoc()) {
    $qty = (int) $row['qty'];
    $levelClass = '';
    $levelLabel = '';
    if ($qty <= 0) {
      $levelClass = 'bg-gray-100 text-text-secondary-light';
      $levelLabel = 'Out';
    } elseif ($qty <= 20) {
      $levelClass = 'bg-danger/20 text-danger';
      $levelLabel = 'Critical';
    } elseif ($qty <= 50) {
      $levelClass = 'bg-warning/20 text-warning';
      $levelLabel = 'Low Stock';
    }
    $lowStockAlerts[] = ['name' => $row['name'], 'qty' => $qty, 'status' => $levelLabel, 'class' => $levelClass];
  }
  $res->free_result();
}

// Recent system activity: collect latest rows from different tables
$recentActivities = [];

// 1. New users (patients) registered
if ($res = $mysqli->query("SELECT CONCAT('New patient ', full_name, ' registered') AS text, created_at AS ts, 'user' AS type FROM users WHERE role='patient' ORDER BY created_at DESC LIMIT 3")) {
  while ($row = $res->fetch_assoc()) {
    $recentActivities[] = ['type' => 'user', 'text' => $row['text'], 'ts' => $row['ts']];
  }
  $res->free_result();
}

// 2. New health workers added
if ($res = $mysqli->query("SELECT CONCAT('New health worker ', u.full_name, ' added') AS text, hw.created_at AS ts, 'hw' AS type FROM health_worker_profiles hw JOIN users u ON u.id = hw.user_id ORDER BY hw.created_at DESC LIMIT 3")) {
  while ($row = $res->fetch_assoc()) {
    $recentActivities[] = ['type' => 'hw', 'text' => $row['text'], 'ts' => $row['ts']];
  }
  $res->free_result();
}

// 3. Vaccination records (patients vaccinated)
if ($res = $mysqli->query("SELECT CONCAT('Patient ', u.full_name, ' vaccinated with ', v.name) AS text, vr.date_given AS ts, 'vaccine' AS type FROM vaccination_records vr JOIN users u ON u.id = vr.patient_id JOIN vaccines v ON v.id = vr.vaccine_id ORDER BY vr.date_given DESC LIMIT 3")) {
  while ($row = $res->fetch_assoc()) {
    $recentActivities[] = ['type' => 'vaccine', 'text' => $row['text'], 'ts' => $row['ts']];
  }
  $res->free_result();
}

// 4. Appointments scheduled/completed
if ($res = $mysqli->query("SELECT CONCAT('Appointment ', a.status, ' for patient ', u.full_name) AS text, a.scheduled_at AS ts, 'appointment' AS type FROM appointments a JOIN users u ON u.id = a.patient_id ORDER BY a.scheduled_at DESC LIMIT 3")) {
  while ($row = $res->fetch_assoc()) {
    $recentActivities[] = ['type' => 'appointment', 'text' => $row['text'], 'ts' => $row['ts']];
  }
  $res->free_result();
}

// 5. Vaccine transactions (stock updates)
if ($res = $mysqli->query("SELECT CONCAT('Vaccine stock for ', COALESCE(v.name,'Unknown'), ' ', vt.type) AS text, vt.created_at AS ts, 'stock' AS type FROM vaccine_transactions vt LEFT JOIN vaccine_batches b ON b.id = vt.batch_id LEFT JOIN vaccines v ON v.id = b.vaccine_id ORDER BY vt.created_at DESC LIMIT 3")) {
  while ($row = $res->fetch_assoc()) {
    $recentActivities[] = ['type' => 'stock', 'text' => $row['text'], 'ts' => $row['ts']];
  }
  $res->free_result();
}

// 6. Reports generated (if reports table exists)
if ($res = $mysqli->query("SELECT CONCAT(UCASE(report_type), ' report generated') AS text, generated_at AS ts, 'report' AS type FROM reports ORDER BY generated_at DESC LIMIT 2")) {
  while ($row = $res->fetch_assoc()) {
    $recentActivities[] = ['type' => 'report', 'text' => $row['text'], 'ts' => $row['ts']];
  }
  $res->free_result();
}

// 7. System settings updates (if system_settings table exists and has timestamp)
if ($res = $mysqli->query("SELECT CONCAT('System setting updated: ', setting_key) AS text, NOW() AS ts, 'settings' AS type FROM system_settings ORDER BY id DESC LIMIT 2")) {
  while ($row = $res->fetch_assoc()) {
    $recentActivities[] = ['type' => 'settings', 'text' => $row['text'], 'ts' => $row['ts']];
  }
  $res->free_result();
}

// Sort activities by timestamp desc and limit to 5 most recent
usort($recentActivities, function ($a, $b) {
  $timeA = strtotime($a['ts'] ?? '1970-01-01');
  $timeB = strtotime($b['ts'] ?? '1970-01-01');
  return $timeB - $timeA;
});
$recentActivities = array_slice($recentActivities, 0, 5);

function time_ago($ts)
{
  if (!$ts)
    return '';
  $t = strtotime($ts);
  if ($t === false)
    return $ts;
  $diff = time() - $t;
  if ($diff < 60)
    return $diff . ' seconds ago';
  if ($diff < 3600)
    return floor($diff / 60) . ' minutes ago';
  if ($diff < 86400)
    return floor($diff / 3600) . ' hours ago';
  return floor($diff / 86400) . ' days ago';
}
?>
<!DOCTYPE html>

<html class="light" lang="en">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1.0" name="viewport" />
  <title>HCNVMS - Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800;900&amp;display=swap"
    rel="stylesheet" />
  <link
    href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
    rel="stylesheet" />
  <style>
    .material-symbols-outlined {
      font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    }
  </style>
  <script>
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          colors: {
            "primary": "#4A90E2",
            "background-light": "#F4F7FA",
            "background-dark": "#101922",
            "surface": "#FFFFFF",
            "surface-dark": "#1A242E",
            "text-light": "#333333",
            "text-dark": "#E0E0E0",
            "text-secondary-light": "#617589",
            "text-secondary-dark": "#A0AEC0",
            "border-light": "#E2E8F0",
            "border-dark": "#2D3748",
            "success": "#2ECC71",
            "warning": "#F39C12",
            "danger": "#E74C3C",
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
  <div class="relative flex min-h-screen w-full flex-row">
    <!-- SideNavBar -->
    <aside
      class="flex h-screen w-64 flex-col justify-between bg-surface dark:bg-surface-dark p-4 border-r border-border-light dark:border-border-dark sticky top-0">
      <div class="flex flex-col gap-8">
        <div class="flex items-center gap-3 px-3 text-primary">
          <img src="/HealthCenter/assets/hcnvms.png" alt="HCNVMS" class="h-10 w-auto" />
          <h2 class="text-text-light dark:text-text-dark text-xl font-bold leading-tight tracking-[-0.015em]">HCNVMS
          </h2>
        </div>
        <div class="flex flex-col gap-2">
          <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-primary/20 text-primary"
            href="/HealthCenter/admin/admin_dashboard.php">
            <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">dashboard</span>
            <p class="text-sm font-semibold leading-normal">Dashboard</p>
          </a>
          <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
            href="/HealthCenter/admin/user_management.php">
            <span class="material-symbols-outlined">group</span>
            <p class="text-sm font-medium leading-normal">User Management</p>
          </a>
          <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
            href="/HealthCenter/admin/health_worker_management.php">
            <span class="material-symbols-outlined">health_and_safety</span>
            <p class="text-sm font-medium leading-normal">Health Worker Mgt.</p>
          </a>
          <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
            href="/HealthCenter/admin/vaccine_inventory.php">
            <span class="material-symbols-outlined">inventory_2</span>
            <p class="text-sm font-medium leading-normal">Vaccine Inventory</p>
          </a>
          <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
            href="/HealthCenter/admin/reports.php">
            <span class="material-symbols-outlined">summarize</span>
            <p class="text-sm font-medium leading-normal">Reports</p>
          </a>
          <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
            href="/HealthCenter/admin/system_settings.php">
            <span class="material-symbols-outlined">settings</span>
            <p class="text-sm font-medium leading-normal">System Settings</p>
          </a>
        </div>
      </div>
      <div class="mt-auto flex flex-col gap-1">
        <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
          href="/HealthCenter/admin/profile.php">
          <span class="material-symbols-outlined">person</span>
          <p class="text-sm font-medium">My Profile</p>
        </a>
        <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
          href="/HealthCenter/auth/logout.php">
          <span class="material-symbols-outlined">logout</span>
          <p class="text-sm font-medium">Logout</p>
        </a>
      </div>
    </aside>
    <div class="flex flex-1 flex-col">
      <!-- Main Content -->
      <main class="flex flex-col flex-1 p-6 lg:p-10 gap-6 lg:gap-10">
        <!-- PageHeading -->
        <div class="flex flex-wrap justify-between gap-3">
          <div class="flex min-w-72 flex-col gap-2">
            <p class="text-text-light dark:text-text-dark text-3xl font-black leading-tight tracking-tight">Admin
              Dashboard</p>
            <p class="text-text-secondary-light dark:text-text-secondary-dark text-base font-normal leading-normal">
              Overview of the vaccination monitoring system.</p>
          </div>
        </div>
        <!-- Stats -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-6">
          <div
            class="flex min-w-[158px] flex-1 flex-col gap-2 rounded-lg p-6 bg-surface dark:bg-surface-dark border border-border-light dark:border-border-dark">
            <p class="text-text-secondary-light dark:text-text-secondary-dark text-base font-medium leading-normal">
              Total Babies Registered</p>
            <p class="text-text-light dark:text-text-dark tracking-light text-3xl font-bold leading-tight">
              <?php echo number_format($totalBabies); ?></p>
          </div>
          <div
            class="flex min-w-[158px] flex-1 flex-col gap-2 rounded-lg p-6 bg-surface dark:bg-surface-dark border border-border-light dark:border-border-dark">
            <p class="text-text-secondary-light dark:text-text-secondary-dark text-base font-medium leading-normal">
              Vaccination Coverage</p>
            <p class="text-text-light dark:text-text-dark tracking-light text-3xl font-bold leading-tight">
              <?php echo htmlspecialchars($coveragePercent); ?>%</p>
          </div>
          <div
            class="flex min-w-[158px] flex-1 flex-col gap-2 rounded-lg p-6 bg-surface dark:bg-surface-dark border border-border-light dark:border-border-dark">
            <p class="text-text-secondary-light dark:text-text-secondary-dark text-base font-medium leading-normal">
              Vaccine Types in Stock</p>
            <p class="text-text-light dark:text-text-dark tracking-light text-3xl font-bold leading-tight">
              <?php echo number_format($vaccineTypes); ?></p>
          </div>
          <div
            class="flex min-w-[158px] flex-1 flex-col gap-2 rounded-lg p-6 bg-surface dark:bg-surface-dark border border-border-light dark:border-border-dark">
            <p class="text-text-secondary-light dark:text-text-secondary-dark text-base font-medium leading-normal">
              Appointments Today</p>
            <p class="text-text-light dark:text-text-dark tracking-light text-3xl font-bold leading-tight">
              <?php echo number_format($appointmentsToday); ?></p>
          </div>
          <div
            class="flex min-w-[158px] flex-1 flex-col gap-2 rounded-lg p-6 bg-surface dark:bg-surface-dark border border-border-light dark:border-border-dark">
            <p class="text-text-secondary-light dark:text-text-secondary-dark text-base font-medium leading-normal">
              Due This Week</p>
            <p class="text-text-light dark:text-text-dark tracking-light text-3xl font-bold leading-tight">
              <?php echo number_format($vaccinesDueThisWeek); ?></p>
          </div>
          <div
            class="flex min-w-[158px] flex-1 flex-col gap-2 rounded-lg p-6 bg-surface dark:bg-surface-dark border border-border-light dark:border-border-dark">
            <p class="text-text-secondary-light dark:text-text-secondary-dark text-base font-medium leading-normal">
              Overdue Vaccinations</p>
            <p class="text-danger tracking-light text-3xl font-bold leading-tight">
              <?php echo number_format($totalOverdue); ?></p>
          </div>
        </div>
        <!-- Charts -->
        <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
          <div
            class="flex min-w-72 xl:col-span-3 flex-col gap-4 rounded-lg border border-border-light dark:border-border-dark p-6 bg-surface dark:bg-surface-dark">
            <p class="text-text-light dark:text-text-dark text-lg font-semibold leading-normal">Vaccination Coverage by
              Vaccine</p>
            <div
              class="grid min-h-[240px] grid-flow-col gap-6 grid-rows-[1fr_auto] items-end justify-items-center px-3 pt-4">
              <?php if (!empty($coverageByVaccine)): ?>
                <?php foreach ($coverageByVaccine as $v): ?>
                  <?php $h = max(6, (int) $v['percent']); ?>
                  <div class="flex flex-col h-full w-full justify-end items-center gap-2">
                    <div class="bg-primary rounded-t w-3/4" style="height: <?php echo htmlspecialchars($h); ?>%;"></div>
                    <p class="text-text-secondary-light dark:text-text-secondary-dark text-xs font-medium tracking-wide">
                      <?php echo htmlspecialchars($v['name']); ?></p>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="text-text-secondary-light px-3">No vaccine data available.</div>
              <?php endif; ?>
            </div>
          </div>
          <div
            class="flex min-w-72 xl:col-span-2 flex-col gap-4 rounded-lg border border-border-light dark:border-border-dark p-6 bg-surface dark:bg-surface-dark">
            <p class="text-text-light dark:text-text-dark text-lg font-semibold leading-normal">Vaccination Status
              Breakdown</p>
            <div class="flex-1 flex items-center justify-center min-h-[240px] relative">
              <svg class="w-full h-full" viewbox="0 0 36 36">
                <path class="stroke-current text-primary/20"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none"
                  stroke-width="3"></path>
                <path class="stroke-current text-success"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none"
                  stroke-dasharray="60, 100" stroke-linecap="round" stroke-width="3"></path>
                <path class="stroke-current text-warning"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none"
                  stroke-dasharray="30, 100" stroke-dashoffset="-60" stroke-linecap="round" stroke-width="3"></path>
              </svg>
              <div class="absolute flex flex-col items-center justify-center">
                <span
                  class="text-3xl font-bold text-text-light dark:text-text-secondary-dark"><?php echo number_format($totalBabies); ?></span>
                <span class="text-sm text-text-secondary-light dark:text-text-secondary-dark">Total Babies</span>
              </div>
            </div>
            <div class="flex justify-center gap-4 text-sm">
              <?php
              $tot = max(1, $totalBabies);
              $fullyPct = (int) round(($statusBreakdown['fully'] / $tot) * 100);
              $partialPct = (int) round(($statusBreakdown['partially'] / $tot) * 100);
              $nonePct = max(0, 100 - $fullyPct - $partialPct);
              ?>
              <div class="flex items-center gap-2">
                <div class="size-3 rounded-full bg-success"></div><span>Fully (<?php echo $fullyPct; ?>%)</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="size-3 rounded-full bg-warning"></div><span>Partially (<?php echo $partialPct; ?>%)</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="size-3 rounded-full bg-primary/20"></div><span>None (<?php echo $nonePct; ?>%)</span>
              </div>
            </div>
          </div>
        </div>
        <!-- Gender and Age Reports -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Gender Chart -->
          <div class="flex flex-col gap-4 rounded-lg border border-border-light dark:border-border-dark p-6 bg-surface dark:bg-surface-dark">
            <h3 class="text-text-light dark:text-text-dark text-lg font-semibold leading-normal">Vaccinations by Gender</h3>
            <div class="flex items-center justify-between mb-2">
              <span class="text-text-secondary-light dark:text-text-secondary-dark text-sm">
                Total: <span class="font-bold text-text-light dark:text-text-dark"><?= $genderStats['Male'] + $genderStats['Female'] ?></span>
              </span>
            </div>
            <div class="relative h-64">
              <canvas id="genderChart"></canvas>
            </div>
            <div class="mt-3 flex items-center justify-center gap-6 pt-3 border-t border-border-light dark:border-border-dark">
              <div class="flex items-center gap-2">
                <div class="w-3 h-3 rounded-full bg-blue-500"></div>
                <span class="text-sm font-medium text-text-light dark:text-text-dark">Male: <?= $genderStats['Male'] ?></span>
              </div>
              <div class="flex items-center gap-2">
                <div class="w-3 h-3 rounded-full bg-pink-500"></div>
                <span class="text-sm font-medium text-text-light dark:text-text-dark">Female: <?= $genderStats['Female'] ?></span>
              </div>
            </div>
          </div>
          
          <!-- Age Group Chart -->
          <div class="flex flex-col gap-4 rounded-lg border border-border-light dark:border-border-dark p-6 bg-surface dark:bg-surface-dark">
            <h3 class="text-text-light dark:text-text-dark text-lg font-semibold leading-normal">Vaccinations by Age</h3>
            <div class="flex items-center justify-between mb-2">
              <span class="text-text-secondary-light dark:text-text-secondary-dark text-sm">
                Total: <span class="font-bold text-text-light dark:text-text-dark"><?= array_sum($ageStats) ?></span>
              </span>
            </div>
            <div class="relative h-64">
              <canvas id="ageChart"></canvas>
            </div>
          </div>
        </div>
        <!-- Alerts & Activities -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <!-- Low Stock Alerts -->
          <div
            class="flex flex-col gap-4 rounded-lg border border-border-light dark:border-border-dark p-6 bg-surface dark:bg-surface-dark">
            <h3 class="text-text-light dark:text-text-dark text-lg font-semibold leading-normal">Low Stock Alerts</h3>
            <div class="overflow-x-auto">
              <table class="w-full text-left">
                <thead>
                  <tr class="border-b border-border-light dark:border-border-dark">
                    <th class="py-2 px-3 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                      Vaccine Name</th>
                    <th class="py-2 px-3 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                      Quantity Left</th>
                    <th class="py-2 px-3 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                      Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($lowStockAlerts)): ?>
                    <?php foreach ($lowStockAlerts as $a): ?>
                      <tr class="border-b border-border-light dark:border-border-dark">
                        <td class="py-3 px-3 text-sm"><?php echo htmlspecialchars($a['name']); ?></td>
                        <td class="py-3 px-3 text-sm"><?php echo number_format($a['qty']); ?> vials</td>
                        <td class="py-3 px-3 text-sm"><span
                            class="<?php echo htmlspecialchars($a['class']); ?> px-2 py-0.5 rounded-full text-xs font-semibold"><?php echo htmlspecialchars($a['status']); ?></span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td class="py-3 px-3 text-sm" colspan="3">No low stock alerts.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <!-- Recent System Activity -->
          <div
            class="flex flex-col gap-4 rounded-lg border border-border-light dark:border-border-dark p-6 bg-surface dark:bg-surface-dark">
            <h3 class="text-text-light dark:text-text-dark text-lg font-semibold leading-normal">Recent System Activity
            </h3>
            <ul class="space-y-4">
              <?php if (!empty($recentActivities)): ?>
                <?php foreach ($recentActivities as $act): ?>
                  <li class="flex items-center gap-4">
                    <div class="flex items-center justify-center size-9 rounded-full <?php
                    $iconConfig = [
                      'user' => ['bg-primary/20', 'text-primary', 'person_add'],
                      'hw' => ['bg-primary/20', 'text-primary', 'medical_services'],
                      'vaccine' => ['bg-success/20', 'text-success', 'vaccines'],
                      'appointment' => ['bg-warning/20', 'text-warning', 'event'],
                      'stock' => ['bg-success/20', 'text-success', 'inventory'],
                      'report' => ['bg-warning/20', 'text-warning', 'summarize'],
                      'settings' => ['bg-danger/20', 'text-danger', 'settings']
                    ];
                    $config = $iconConfig[$act['type']] ?? ['bg-gray-100', 'text-gray-600', 'info'];
                    echo $config[0] . ' ' . $config[1];
                    ?>">
                      <span class="material-symbols-outlined text-xl"><?php echo $config[2]; ?></span>
                    </div>
                    <div class="flex-1">
                      <p class="text-sm font-medium"><?php echo htmlspecialchars($act['text']); ?></p>
                      <p class="text-xs text-text-secondary-light dark:text-text-secondary-dark">
                        <?php echo htmlspecialchars(time_ago($act['ts'] ?? '')); ?></p>
                    </div>
                  </li>
                <?php endforeach; ?>
              <?php else: ?>
                <li class="text-sm text-text-secondary-light">No recent activity.</li>
              <?php endif; ?>
            </ul>
          </div>
        </div>
      </main>
    </div>
  </div>
  <script>
    // Initialize Charts
    document.addEventListener('DOMContentLoaded', function() {
      const isDark = document.documentElement.classList.contains('dark');
      const textColor = isDark ? '#A0AEC0' : '#617589';
      const gridColor = isDark ? 'rgba(160, 174, 192, 0.1)' : 'rgba(97, 117, 137, 0.1)';
      
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
              borderColor: isDark ? '#1A242E' : '#FFFFFF',
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
                backgroundColor: isDark ? '#1A242E' : '#FFFFFF',
                titleColor: isDark ? '#E0E0E0' : '#333333',
                bodyColor: isDark ? '#E0E0E0' : '#333333',
                borderColor: isDark ? '#2D3748' : '#E2E8F0',
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
              backgroundColor: 'rgba(74, 144, 226, 0.7)',
              borderColor: 'rgba(74, 144, 226, 1)',
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
                backgroundColor: isDark ? '#1A242E' : '#FFFFFF',
                titleColor: isDark ? '#E0E0E0' : '#333333',
                bodyColor: isDark ? '#E0E0E0' : '#333333',
                borderColor: isDark ? '#2D3748' : '#E2E8F0',
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
    });
  </script>
</body>

</html>