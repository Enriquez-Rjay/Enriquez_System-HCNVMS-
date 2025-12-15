<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
  header('Location: /HealthCenter/login.php');
  exit();
}

// Check if user has the correct role (health_worker or admin)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
  header('Location: /HealthCenter/login.php');
  exit();
}

// Check if logged-in user's account is still active
if (isset($_SESSION['user_id'])) {
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
}

// Check if viewing a specific patient profile
$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$patientProfile = null;
$vaccinationRecords = [];
$appointments = [];

// Helper: get per-vaccine dose history and next recommended dosage for a patient
if (!function_exists('get_next_dosage_for_patient_vaccine')) {
  function get_next_dosage_for_patient_vaccine(mysqli $mysqli, int $patientId, ?int $vaccineId): ?array
  {
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
}

if ($patient_id > 0) {
  // Fetch patient profile details
  $stmt = $mysqli->prepare("
        SELECT u.id as user_id, u.username, u.full_name, u.email, u.phone as contact_number, u.created_at,
               p.id, p.child_name, p.birth_date, p.guardian_name, p.address, p.created_at as profile_created
        FROM users u
        INNER JOIN patient_profiles p ON p.user_id = u.id
        WHERE u.id = ? AND u.role = 'patient'
    ");
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $patientProfile = $result->fetch_assoc();
  $stmt->close();

  if (!$patientProfile) {
    // Patient not found, redirect to patient list
    header('Location: /HealthCenter/health_worker/hw_patient.php');
    exit();
  }

  // Fetch vaccination records
  $stmt = $mysqli->prepare("
            SELECT vr.id, vr.date_given, vr.dose, v.name as vaccine_name, v.id as vaccine_id
            FROM vaccination_records vr
            LEFT JOIN vaccines v ON v.id = vr.vaccine_id
            WHERE vr.patient_id = ?
            ORDER BY vr.date_given DESC
        ");
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $vaccinationRecords[] = $row;
  }
  $stmt->close();

  // Calculate vaccination status
  $totalVaccines = 0;
  $vaccinesReceived = 0;
  $vaccinesReceivedIds = [];
  
  // Get total vaccines available
  $stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM vaccines");
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) {
    $totalVaccines = (int) $row['total'];
  }
  $stmt->close();
  
  // Get vaccines received by this patient
  $stmt = $mysqli->prepare("SELECT DISTINCT vaccine_id FROM vaccination_records WHERE patient_id = ?");
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    if ($row['vaccine_id']) {
      $vaccinesReceivedIds[] = $row['vaccine_id'];
      $vaccinesReceived++;
    }
  }
  $stmt->close();
  
  // Calculate vaccination status
  $vaccinationStatus = 'Not Started';
  $statusClass = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
  $completionPercentage = 0;
  
  if ($totalVaccines > 0) {
    $completionPercentage = round(($vaccinesReceived / $totalVaccines) * 100);
    
    if ($vaccinesReceived === 0) {
      $vaccinationStatus = 'Not Started';
      $statusClass = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
    } elseif ($vaccinesReceived === $totalVaccines) {
      $vaccinationStatus = 'Fully Vaccinated';
      $statusClass = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
    } else {
      $vaccinationStatus = 'Partially Vaccinated';
      $statusClass = 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300';
    }
  }
  
  // Get all vaccines with status (received or pending)
  $allVaccines = [];
  $stmt = $mysqli->prepare("
    SELECT v.id, v.name, 
           (SELECT COUNT(*) FROM vaccination_records vr WHERE vr.vaccine_id = v.id AND vr.patient_id = ?) as doses_received,
           (SELECT MAX(vr.date_given) FROM vaccination_records vr WHERE vr.vaccine_id = v.id AND vr.patient_id = ?) as last_date_given
    FROM vaccines v
    ORDER BY v.name
  ");
  $stmt->bind_param('ii', $patient_id, $patient_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $allVaccines[] = $row;
  }
  $stmt->close();

  // Fetch appointments with vaccine information
  $stmt = $mysqli->prepare("
            SELECT a.id, a.scheduled_at, a.status, a.notes, a.created_at, a.vaccine_id, a.dosage,
                 a.health_worker_id, u.full_name as health_worker_name, v.name as vaccine_name
            FROM appointments a
            LEFT JOIN users u ON u.id = a.health_worker_id
            LEFT JOIN vaccines v ON v.id = a.vaccine_id
            WHERE a.patient_id = ?
            ORDER BY a.scheduled_at DESC
            LIMIT 20
        ");
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
  }
  $stmt->close();
  
  // Separate upcoming and past appointments
  $upcomingAppointments = [];
  $pastAppointments = [];
  $now = new DateTime();
  foreach ($appointments as $appt) {
    $apptDate = new DateTime($appt['scheduled_at']);
    if ($appt['status'] === 'scheduled' || $appt['status'] === 'pending') {
      if ($apptDate >= $now) {
        $upcomingAppointments[] = $appt;
      } else {
        $pastAppointments[] = $appt;
      }
    } else {
      $pastAppointments[] = $appt;
    }
  }
}

// Fetch active patients with their profiles
$patients = [];
$sql = "SELECT 
            u.id, 
            u.username, 
            u.full_name, 
            u.email,
            u.phone,
            u.status,
            p.child_name, 
            p.birth_date, 
            p.guardian_name,
            p.address,
            (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = u.id) as appointment_count,
            (SELECT COUNT(*) FROM vaccination_records vr WHERE vr.patient_id = u.id) as vaccine_count
        FROM users u
        INNER JOIN patient_profiles p ON p.user_id = u.id
        WHERE u.role = 'patient' 
        AND u.status = 'active'
        ORDER BY COALESCE(u.full_name, p.child_name) ASC
        LIMIT 500";
if ($res = $mysqli->query($sql)) {
  while ($row = $res->fetch_assoc()) {
    $patients[] = $row;
  }
  $res->free();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1.0" name="viewport" />
  <title>HCNVMS - Patients</title>
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
    <aside class="flex w-64 flex-col border-r border-slate-200 dark:border-slate-800 bg-white dark:bg-background-dark">
      <div class="flex h-16 shrink-0 items-center gap-3 px-6 text-primary">
        <span class="material-symbols-outlined text-3xl">vaccines</span>
        <h2 class="text-lg font-bold leading-tight tracking-[-0.015em] text-slate-900 dark:text-white">HCNVMS</h2>
      </div>
      <div class="flex flex-col justify-between h-full p-4">
        <div class="flex flex-col gap-2">
          <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
            href="/HealthCenter/health_worker/hw_dashboard.php">
            <span class="material-symbols-outlined">dashboard</span>
            <p class="text-sm font-medium leading-normal">Dashboard</p>
          </a>
          <a class="flex items-center gap-3 rounded-lg bg-primary/10 dark:bg-primary/20 px-3 py-2 text-primary"
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
            <div class="flex items-center gap-2">
              <div class="flex flex-col">
                <h1 class="text-slate-900 dark:text-white text-sm font-medium leading-normal">Dr. Rjay Enriquez</h1>
                <p class="text-slate-500 dark:text-slate-400 text-xs font-normal leading-normal">Health Worker</p>
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
          <div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-10" data-alt="Profile picture"
            style="background-image: none;"></div>
        </div>
      </header>
      <div class="p-6 md:p-8">
        <?php if ($patientProfile): ?>
          <?php
          // Parse patient data for use throughout the page (including JavaScript)
          // Parse guardian_name to extract relationship
          $guardianName = $patientProfile['guardian_name'] ?? '';
          $relationship = '';
          $parentName = $guardianName;
          if (preg_match('/^(.+?)\s*\((.+?)\)$/', $guardianName, $matches)) {
            $parentName = trim($matches[1]);
            $relationship = trim($matches[2]);
          }
          
          // Parse address to extract gender and parent concern
          $address = $patientProfile['address'] ?? '';
          $gender = '';
          $parentConcern = '';
          $actualAddress = $address;
          
          // Split address by double newlines to separate main address from metadata
          $addressParts = preg_split('/\n\n+/', $address);
          $actualAddress = trim($addressParts[0] ?? '');
          
          // Check remaining parts for gender and parent concern
          for ($i = 1; $i < count($addressParts); $i++) {
            $part = trim($addressParts[$i]);
            if (preg_match('/^Gender:\s*(.+)$/i', $part, $genderMatches)) {
              $gender = trim($genderMatches[1]);
            } elseif (preg_match('/^Parent Concern:\s*(.+)$/is', $part, $concernMatches)) {
              $parentConcern = trim($concernMatches[1]);
            }
          }
          
          // Also check if gender or concern are in the main address (for backward compatibility)
          if (empty($gender) && preg_match('/Gender:\s*(.+?)(?:\n|$)/i', $address, $genderMatches)) {
            $gender = trim($genderMatches[1]);
            $actualAddress = preg_replace('/Gender:\s*.+?(?:\n|$)/i', '', $actualAddress);
          }
          
          if (empty($parentConcern) && preg_match('/Parent Concern:\s*(.+?)$/is', $address, $concernMatches)) {
            $parentConcern = trim($concernMatches[1]);
            $actualAddress = preg_replace('/Parent Concern:\s*.+?$/is', '', $actualAddress);
          }
          
          // Clean up address (remove extra newlines)
          $actualAddress = trim($actualAddress);
          ?>
          <!-- Patient Profile View -->
          <div class="mb-6">
            <a href="/HealthCenter/health_worker/hw_patient.php"
              class="inline-flex items-center gap-2 text-primary hover:text-primary/80 mb-4">
              <span class="material-symbols-outlined">arrow_back</span>
              <span>Back to Patient Records</span>
            </a>
          </div>
          <div class="flex flex-col gap-4 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div class="flex flex-col gap-1">
                <p class="text-2xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white lg:text-3xl">
                  Patient Profile</p>
                <p class="text-slate-600 dark:text-slate-400 text-base font-normal leading-normal">View detailed patient
                  information and records.</p>
              </div>
              <button type="button" onclick="openEditPatientModal()" 
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                <span class="material-symbols-outlined text-base">edit</span>
                Edit Patient Information
              </button>
            </div>
          </div>

          <!-- Patient Login Credentials Card -->
          <div class="rounded-xl border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20 mb-6">
            <div class="p-6">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100">Login Credentials</h3>
                <button type="button" onclick="showPatientCredentials()"
                  class="inline-flex items-center gap-1 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
                  <span class="material-symbols-outlined text-base">visibility</span>
                  View Credentials
                </button>
              </div>
              <p class="text-sm text-blue-800 dark:text-blue-200 mb-2">
                Patient can use these credentials to login and view their vaccination records.
              </p>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div>
                  <span class="text-blue-700 dark:text-blue-300 font-medium">Username:</span>
                  <span class="ml-2 font-mono text-blue-900 dark:text-blue-100"><?php echo htmlspecialchars($patientProfile['username'] ?? 'N/A'); ?></span>
                </div>
                <div>
                  <span class="text-blue-700 dark:text-blue-300 font-medium">Email:</span>
                  <span class="ml-2 font-mono text-blue-900 dark:text-blue-100"><?php echo htmlspecialchars($patientProfile['email'] ?? 'N/A'); ?></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Patient Information Card -->
          <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 mb-6">
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Patient Information</h3>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Full Name</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo htmlspecialchars($patientProfile['full_name'] ?? $patientProfile['child_name']); ?></p>
                </div>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Date of Birth</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo !empty($patientProfile['birth_date']) ? date('F j, Y', strtotime($patientProfile['birth_date'])) : 'N/A'; ?>
                  </p>
                </div>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Age</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php 
                    if (!empty($patientProfile['birth_date'])) {
                      $birthDate = new DateTime($patientProfile['birth_date']);
                      $today = new DateTime();
                      $age = $today->diff($birthDate);
                      
                      if ($age->y > 0) {
                        echo $age->y . ' year' . ($age->y > 1 ? 's' : '');
                        if ($age->m > 0) {
                          echo ', ' . $age->m . ' month' . ($age->m > 1 ? 's' : '');
                        }
                      } elseif ($age->m > 0) {
                        echo $age->m . ' month' . ($age->m > 1 ? 's' : '');
                        if ($age->d > 0) {
                          echo ', ' . $age->d . ' day' . ($age->d > 1 ? 's' : '');
                        }
                      } else {
                        echo $age->d . ' day' . ($age->d > 1 ? 's' : '');
                      }
                    } else {
                      echo 'N/A';
                    }
                    ?>
                  </p>
                </div>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Gender</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo !empty($gender) ? htmlspecialchars($gender) : 'N/A'; ?>
                  </p>
                </div>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Contact Number</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo !empty($patientProfile['contact_number']) ? htmlspecialchars($patientProfile['contact_number']) : 'N/A'; ?>
                  </p>
                </div>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Email</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo htmlspecialchars($patientProfile['email'] ?? 'N/A'); ?></p>
                </div>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Parent/Guardian Name</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo htmlspecialchars($parentName ?: 'N/A'); ?></p>
                </div>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Relationship to Baby</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo htmlspecialchars($relationship ?: 'N/A'); ?></p>
                </div>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Address</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo !empty($actualAddress) ? nl2br(htmlspecialchars($actualAddress)) : 'N/A'; ?></p>
                </div>
                <?php if (!empty($parentConcern)): ?>
                <div class="md:col-span-2">
                  <p class="text-sm text-slate-500 dark:text-slate-400">Parent's Concern</p>
                  <p class="font-medium text-slate-900 dark:text-white whitespace-pre-wrap">
                    <?php echo nl2br(htmlspecialchars($parentConcern)); ?></p>
                </div>
                <?php endif; ?>
                <div>
                  <p class="text-sm text-slate-500 dark:text-slate-400">Registered Date</p>
                  <p class="font-medium text-slate-900 dark:text-white">
                    <?php echo date('F d, Y', strtotime($patientProfile['created_at'] ?? 'now')); ?></p>
                </div>
              </div>
            </div>
          </div>

          <!-- Vaccination Status Summary Card -->
          <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 mb-6">
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Vaccination Status</h3>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                  <p class="text-sm text-slate-500 dark:text-slate-400 mb-1">Status</p>
                  <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium <?php echo $statusClass; ?>">
                    <?php echo htmlspecialchars($vaccinationStatus); ?>
                  </span>
                </div>
                <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                  <p class="text-sm text-slate-500 dark:text-slate-400 mb-1">Vaccines Received</p>
                  <p class="text-2xl font-bold text-slate-900 dark:text-white">
                    <?php echo $vaccinesReceived; ?> / <?php echo $totalVaccines; ?>
                  </p>
                </div>
                <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                  <p class="text-sm text-slate-500 dark:text-slate-400 mb-1">Completion</p>
                  <div class="flex items-center gap-2">
                    <div class="flex-1 bg-slate-200 dark:bg-slate-700 rounded-full h-2.5">
                      <div class="bg-primary h-2.5 rounded-full" style="width: <?php echo $completionPercentage; ?>%"></div>
                    </div>
                    <span class="text-sm font-semibold text-slate-900 dark:text-white"><?php echo $completionPercentage; ?>%</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- All Vaccines Card -->
          <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 mb-6">
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Vaccines</h3>
              <?php if (!empty($allVaccines)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                  <?php foreach ($allVaccines as $vaccine): ?>
                    <?php 
                    $hasReceived = (int) $vaccine['doses_received'] > 0;
                    $dosesReceived = (int) $vaccine['doses_received'];
                    $lastDate = $vaccine['last_date_given'] ?? null;

                    // Per-vaccine progress: how many doses already, and what's next
                    $nextInfo = get_next_dosage_for_patient_vaccine($mysqli, $patient_id, (int) $vaccine['id']);
                    ?>
                    <div class="border border-slate-200 dark:border-slate-700 rounded-lg p-4 <?php echo $hasReceived ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-slate-50 dark:bg-slate-800'; ?>">
                      <div class="flex items-center justify-between mb-2">
                        <span class="font-semibold text-slate-900 dark:text-white">
                          <?php echo htmlspecialchars($vaccine['name']); ?>
                        </span>
                        <?php if ($hasReceived): ?>
                          <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-sm">check_circle</span>
                        <?php else: ?>
                          <span class="material-symbols-outlined text-slate-400 text-sm">radio_button_unchecked</span>
                        <?php endif; ?>
                      </div>
                      <?php if ($hasReceived): ?>
                        <p class="text-xs text-slate-600 dark:text-slate-400">
                          <?php echo $dosesReceived; ?> dose<?php echo $dosesReceived > 1 ? 's' : ''; ?> received
                        </p>
                        <?php if ($lastDate): ?>
                          <p class="text-xs text-slate-500 dark:text-slate-500 mt-1">
                            Last: <?php echo date('M d, Y', strtotime($lastDate)); ?>
                          </p>
                        <?php endif; ?>
                        <?php if (!empty($nextInfo)): ?>
                          <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            <?php
                            $taken = (int) ($nextInfo['max_dose'] ?? 0);
                            $nextDoseLabel = $nextInfo['next_dosage'] ?? '';
                            if ($taken > 0) {
                              echo 'Doses received: ' . $taken . '. ';
                            } else {
                              echo 'No doses recorded yet. ';
                            }
                            if (!empty($nextDoseLabel)) {
                              echo 'Next: ' . htmlspecialchars($nextDoseLabel);
                            } else {
                              echo 'Series may be complete.';
                            }
                            ?>
                          </p>
                        <?php endif; ?>
                      <?php else: ?>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Not yet received</p>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="text-slate-600 dark:text-slate-400">No vaccines available.</p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Vaccination Records Card -->
          <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 mb-6">
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Vaccination Records</h3>
              <?php if (!empty($vaccinationRecords)): ?>
                <div class="overflow-x-auto">
                  <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800">
                      <tr>
                        <th class="px-6 py-3 font-medium">Vaccine</th>
                        <th class="px-6 py-3 font-medium">Date Given</th>
                        <th class="px-6 py-3 font-medium">Dose</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                      <?php foreach ($vaccinationRecords as $record): ?>
                        <tr class="bg-white hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800/50">
                          <td class="px-6 py-4 text-slate-900 dark:text-white">
                            <?php echo htmlspecialchars($record['vaccine_name'] ?? 'N/A'); ?></td>
                          <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                            <?php echo !empty($record['date_given']) ? date('M d, Y', strtotime($record['date_given'])) : 'N/A'; ?></td>
                          <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                            <?php echo htmlspecialchars($record['dose'] ?? 'N/A'); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <p class="text-slate-600 dark:text-slate-400">No vaccination records found.</p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Appointments Card -->
          <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="p-6">
              <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Appointments</h3>
                <?php if (!empty($upcomingAppointments)): ?>
                  <button type="button" onclick="completeAppointment(
                '<?php echo $upcomingAppointments[0]['id'] ?? ''; ?>', 
                '<?php echo $patientProfile['user_id'] ?? ''; ?>', 
                '<?php echo addslashes($patientProfile['full_name'] ?? $patientProfile['child_name'] ?? ''); ?>',
                '<?php echo $patientProfile['birth_date'] ?? ''; ?>',
                '<?php echo htmlspecialchars($gender ?? ''); ?>',
                '<?php echo addslashes($patientProfile['contact_number'] ?? ''); ?>',
                '<?php echo addslashes($actualAddress ?? ''); ?>',
                '<?php echo $patientProfile['email'] ?? ''; ?>',
                '<?php echo addslashes($parentName ?? ''); ?>'
            )" class="inline-flex items-center gap-1 rounded-md bg-green-100 px-3 py-1.5 text-sm font-medium text-green-800 hover:bg-green-200 dark:bg-green-900 dark:text-green-200 dark:hover:bg-green-800">
                    <span class="material-symbols-outlined text-base">check_circle</span>
                    Complete Appointment
                  </button>
                <?php endif; ?>
              </div>
              
              <?php if (!empty($upcomingAppointments) || !empty($pastAppointments)): ?>
                <!-- Upcoming Appointments -->
                <?php if (!empty($upcomingAppointments)): ?>
                  <div class="mb-6">
                    <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">Upcoming Appointments</h4>
                    <div class="overflow-x-auto">
                      <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800">
                          <tr>
                            <th class="px-6 py-3 font-medium">Date & Time</th>
                            <th class="px-6 py-3 font-medium">Vaccine</th>
                            <th class="px-6 py-3 font-medium">Dosage</th>
                            <th class="px-6 py-3 font-medium">Status</th>
                            <th class="px-6 py-3 font-medium">Notes</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                          <?php foreach ($upcomingAppointments as $appt): ?>
                            <tr class="bg-white hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800/50">
                              <td class="px-6 py-4 text-slate-900 dark:text-white">
                                <?php echo date('M d, Y h:i A', strtotime($appt['scheduled_at'])); ?></td>
                              <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                <?php echo !empty($appt['vaccine_name']) ? htmlspecialchars($appt['vaccine_name']) : 'N/A'; ?></td>
                              <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                <?php echo !empty($appt['dosage']) ? htmlspecialchars($appt['dosage']) : 'N/A'; ?></td>
                              <td class="px-6 py-4">
                                <?php
                                $statusClass = 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300';
                                if ($appt['status'] === 'completed') {
                                  $statusClass = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
                                } elseif ($appt['status'] === 'cancelled') {
                                  $statusClass = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
                                }
                                ?>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?php echo $statusClass; ?>">
                                  <?php echo ucfirst(htmlspecialchars($appt['status'])); ?>
                                </span>
                              </td>
                              <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                <?php echo htmlspecialchars($appt['notes'] ?? 'N/A'); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                <?php endif; ?>
                
                <!-- Past Appointments -->
                <?php if (!empty($pastAppointments)): ?>
                  <div>
                    <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">Past Appointments</h4>
                    <div class="overflow-x-auto">
                      <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800">
                          <tr>
                            <th class="px-6 py-3 font-medium">Date & Time</th>
                            <th class="px-6 py-3 font-medium">Vaccine</th>
                            <th class="px-6 py-3 font-medium">Dosage</th>
                            <th class="px-6 py-3 font-medium">Status</th>
                            <th class="px-6 py-3 font-medium">Notes</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                          <?php foreach ($pastAppointments as $appt): ?>
                            <tr class="bg-white hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800/50">
                              <td class="px-6 py-4 text-slate-900 dark:text-white">
                                <?php echo date('M d, Y h:i A', strtotime($appt['scheduled_at'])); ?></td>
                              <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                <?php echo !empty($appt['vaccine_name']) ? htmlspecialchars($appt['vaccine_name']) : 'N/A'; ?></td>
                              <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                <?php echo !empty($appt['dosage']) ? htmlspecialchars($appt['dosage']) : 'N/A'; ?></td>
                              <td class="px-6 py-4">
                                <?php
                                $statusClass = 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300';
                                if ($appt['status'] === 'completed') {
                                  $statusClass = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
                                } elseif ($appt['status'] === 'cancelled') {
                                  $statusClass = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
                                }
                                ?>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?php echo $statusClass; ?>">
                                  <?php echo ucfirst(htmlspecialchars($appt['status'])); ?>
                                </span>
                              </td>
                              <td class="px-6 py-4 text-slate-600 dark:text-slate-300">
                                <?php echo htmlspecialchars($appt['notes'] ?? 'N/A'); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <p class="text-slate-600 dark:text-slate-400">No appointments found.</p>
              <?php endif; ?>
            </div>
          </div>

        <?php else: ?>
          <!-- Patient List View -->
          <div class="flex flex-col gap-4 mb-6 md:flex-row md:items-center md:justify-between">
            <div class="flex flex-col gap-1">
              <p class="text-2xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white lg:text-3xl">
                Patient Records</p>
              <p class="text-slate-600 dark:text-slate-400 text-base font-normal leading-normal">Manage and view all
                registered patient information.</p>
            </div>
            <button id="openRegisterModal"
              class="flex h-10 w-full cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-lg bg-primary px-4 text-sm font-bold text-white shadow-sm transition-all hover:bg-primary/90 md:w-auto">
              <span class="material-symbols-outlined text-base">add</span>
              <span class="truncate">Register New Baby</span>
            </button>
          </div>
          <?php
          // Handle search and status filters via GET
          $q = trim($_GET['q'] ?? '');
          $status = $_GET['status'] ?? 'all';

          $where = "WHERE u.role = 'patient'";
          $params = [];
          $types = '';
          if ($q !== '') {
            $where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR p.guardian_name LIKE ? )";
            $like = "%" . $q . "%";
            $params[] = &$like;
            $params[] = &$like;
            $params[] = &$like;
            $types .= 'sss';
          }
          if ($status === 'up-to-date') {
            $where .= " AND (SELECT COUNT(DISTINCT vr.vaccine_id) FROM vaccination_records vr WHERE vr.patient_id = u.id) >= (SELECT COUNT(*) FROM vaccines)";
          } elseif ($status === 'upcoming') {
            $where .= " AND EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = u.id AND a.scheduled_at >= NOW())";
          } elseif ($status === 'overdue') {
            $where .= " AND (SELECT COUNT(DISTINCT vr.vaccine_id) FROM vaccination_records vr WHERE vr.patient_id = u.id) < (SELECT COUNT(*) FROM vaccines) AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = u.id AND a.scheduled_at >= NOW())";
          }

          // Rebuild main patients query with applied filters
// Only show patients that have been registered in patient_profiles (database entries)
          $patients = [];
          // Ensure we only show active patients
          if (stripos($where, 'u.status') === false) {
            $where .= " AND u.status = 'active'";
          }
          $sql = "SELECT 
                    u.id, 
                    u.username, 
                    COALESCE(u.full_name, p.child_name) AS full_name, 
                    p.child_name, 
                    p.birth_date, 
                    p.guardian_name,
                    u.status,
                    (SELECT COUNT(DISTINCT vr.vaccine_id) FROM vaccination_records vr WHERE vr.patient_id = u.id) as vaccines_received,
                    (SELECT COUNT(*) FROM vaccines) as total_vaccines
				FROM users u
				INNER JOIN patient_profiles p ON p.user_id = u.id
				" . $where . "
				ORDER BY COALESCE(u.full_name, p.child_name) ASC
				LIMIT 500";
          if ($stmt = $mysqli->prepare($sql)) {
            if (!empty($params)) {
              // bind params dynamically
              $bind_names[] = $types;
              for ($i = 0; $i < count($params); $i++) {
                $bind_names[] = &$params[$i];
              }
              call_user_func_array(array($stmt, 'bind_param'), $bind_names);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
              $patients[] = $row;
            }
            $stmt->close();
          }
          ?>

          <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <form method="get" class="flex w-full sm:w-auto items-center gap-3">
              <label class="relative flex min-w-40 max-w-sm flex-1 items-center">
                <span class="material-symbols-outlined absolute left-3 text-slate-500">search</span>
                <input name="q" value="<?php echo htmlspecialchars($q); ?>"
                  class="form-input w-full rounded-lg border-slate-300 bg-white py-2 pl-10 pr-4 text-slate-900 placeholder:text-slate-500 focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-400"
                  placeholder="Search by name, ID, or parent..." />
              </label>
              <div class="flex items-center gap-3">
                <label class="text-sm font-medium text-slate-700 dark:text-slate-300"
                  for="vaccination-status-filter">Status:</label>
                <select name="status"
                  class="form-select rounded-lg border-slate-300 bg-white text-slate-900 focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white"
                  id="vaccination-status-filter">
                  <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                  <option value="up-to-date" <?php echo $status === 'up-to-date' ? 'selected' : ''; ?>>Up-to-date</option>
                  <option value="upcoming" <?php echo $status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                  <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>
              </div>
              <div>
                <button type="submit"
                  class="flex h-10 w-full cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-lg bg-primary px-4 text-sm font-bold text-white shadow-sm transition-all hover:bg-primary/90 md:w-auto">Apply</button>
              </div>
            </form>
          </div>
          <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
              <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800">
                  <tr>
                    <th class="px-6 py-3 font-medium" scope="col">Patient Name / ID</th>
                    <th class="px-6 py-3 font-medium" scope="col">Date of Birth</th>
                    <th class="px-6 py-3 font-medium" scope="col">Parent/Guardian</th>
                    <th class="px-6 py-3 font-medium" scope="col">Vaccination Status</th>
                    <th class="px-6 py-3 font-medium text-right" scope="col">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                  <?php if (!empty($patients)): ?>
                    <?php foreach ($patients as $patient): ?>
                      <tr class="bg-white hover:bg-gray-50 dark:bg-slate-800 dark:hover:bg-slate-700"
                        data-patient-id="<?= $patient['id'] ?>">
                        <td class="whitespace-nowrap px-6 py-4">
                          <div class="flex items-center">
                            <div class="h-10 w-10 flex-shrink-0">
                              <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                                <span
                                  class="text-sm font-medium leading-none text-primary"><?= strtoupper(substr($patient['child_name'] ?: $patient['full_name'], 0, 1)) ?></span>
                              </span>
                            </div>
                            <div class="ml-4">
                              <div class="text-sm font-medium text-gray-900 dark:text-white">
                                <?= htmlspecialchars($patient['child_name'] ?: $patient['full_name']) ?></div>
                            </div>
                          </div>
                        </td>
                        <td class="whitespace-nowrap px-6 py-4">
                          <div class="text-sm text-gray-900 dark:text-white">
                            <?= date('M j, Y', strtotime($patient['birth_date'])) ?></div>
                          <div class="text-sm text-gray-500 dark:text-gray-400">
                            <?php 
                            if (!empty($patient['birth_date'])) {
                              $birthDate = new DateTime($patient['birth_date']);
                              $today = new DateTime();
                              $age = $today->diff($birthDate);
                              
                              if ($age->y > 0) {
                                echo $age->y . ' year' . ($age->y > 1 ? 's' : '');
                                if ($age->m > 0) {
                                  echo ', ' . $age->m . ' month' . ($age->m > 1 ? 's' : '');
                                }
                              } elseif ($age->m > 0) {
                                echo $age->m . ' month' . ($age->m > 1 ? 's' : '');
                                if ($age->d > 0) {
                                  echo ', ' . $age->d . ' day' . ($age->d > 1 ? 's' : '');
                                }
                              } else {
                                echo $age->d . ' day' . ($age->d > 1 ? 's' : '');
                              }
                            } else {
                              echo 'N/A';
                            }
                            ?>
                          </div>
                        </td>
                        <td class="whitespace-nowrap px-6 py-4">
                          <div class="text-sm text-gray-900 dark:text-white">
                            <?= htmlspecialchars($patient['guardian_name'] ?? 'N/A') ?>
                          </div>
                        </td>
                        <td class="whitespace-nowrap px-6 py-4">
                          <?php
                          // Calculate vaccination status
                          $vaccinesReceived = (int)($patient['vaccines_received'] ?? 0);
                          $totalVaccines = (int)($patient['total_vaccines'] ?? 0);
                          $vaccinationStatus = 'Not Started';
                          $statusClass = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
                          
                          if ($totalVaccines > 0) {
                            if ($vaccinesReceived === 0) {
                              $vaccinationStatus = 'Not Started';
                              $statusClass = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
                            } elseif ($vaccinesReceived >= $totalVaccines) {
                              $vaccinationStatus = 'Fully Vaccinated';
                              $statusClass = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
                            } else {
                              $vaccinationStatus = 'Partially Vaccinated';
                              $statusClass = 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300';
                            }
                          }
                          ?>
                          <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium <?= $statusClass ?>">
                            <?= htmlspecialchars($vaccinationStatus) ?>
                          </span>
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium align-middle">
                          <div class="flex items-center justify-end gap-2">
                            <a href="?patient_id=<?= $patient['id'] ?>"
                              class="inline-flex items-center justify-center p-2 text-primary hover:bg-primary/10 rounded-md transition-colors" title="View">
                              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                              </svg>
                            </a>
                            <button
                              onclick="confirmDelete(<?= $patient['id'] ?>, '<?= addslashes(htmlspecialchars($patient['child_name'] ?: $patient['full_name'])) ?>')"
                              class="inline-flex items-center justify-center p-2 text-red-600 hover:bg-red-100 dark:hover:bg-red-900/20 rounded-md transition-colors" title="Delete">
                              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                              </svg>
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr class="bg-white">
                      <td class="px-6 py-4" colspan="5">No patients found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <div
              class="flex flex-col items-center justify-between gap-4 border-t border-slate-200 p-4 dark:border-slate-800 sm:flex-row">
              <span class="text-sm text-slate-600 dark:text-slate-400">Showing <?php echo count($patients); ?>
                results</span>
              <div class="inline-flex rounded-md shadow-sm">
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </main>
  </div>

  <!-- Register Baby Modal -->
  <div id="registerBabyModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title"
    role="dialog" aria-modal="true">
    <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
      <!-- Background overlay -->
      <div id="modalOverlay" class="fixed inset-0 bg-slate-500 bg-opacity-75 transition-opacity" aria-hidden="true">
      </div>

      <!-- Modal panel -->
      <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

      <div
        class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle dark:bg-slate-800">
        <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4 dark:bg-slate-800">
          <div class="sm:flex sm:items-start">
            <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
              <h3 class="text-lg font-medium leading-6 text-slate-900 dark:text-white" id="modal-title">
                Register New Baby
              </h3>
              <div class="mt-4">
                <form id="registerBabyForm" class="space-y-6">
                  <!-- Baby's Information Section -->
                  <div class="space-y-4">
                    <h4 class="text-base font-medium text-slate-800 dark:text-slate-200 border-b border-slate-200 dark:border-slate-700 pb-2">Baby's Information</h4>
                    
                    <!-- Baby's Name -->
                    <div>
                      <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Baby's Full Name *</label>
                      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                          <input type="text" name="firstName" id="firstName" required placeholder="First Name"
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                        </div>
                        <div>
                          <input type="text" name="lastName" id="lastName" required placeholder="Last Name"
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                        </div>
                      </div>
                    </div>

                    <!-- Date of Birth -->
                    <div>
                      <label for="birthDate" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Date of Birth *</label>
                      <input type="date" name="birthDate" id="birthDate" required
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                      <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Only newborns (0-12 months) can be registered</p>
                      <p id="ageError" class="mt-1 text-xs text-red-600 dark:text-red-400 hidden"></p>
                    </div>

                    <!-- Gender -->
                    <div>
                      <label for="gender" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Gender *</label>
                      <select name="gender" id="gender" required
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                        <option value="">Select Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                      </select>
                    </div>
                  </div>

                  <!-- Parent/Guardian Information Section -->
                  <div class="space-y-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                    <h4 class="text-base font-medium text-slate-800 dark:text-slate-200 pb-2">Parent/Guardian's Information</h4>
                    
                    <!-- Parent/Guardian Name -->
                    <div>
                      <label for="parentName" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Full Name *</label>
                      <input type="text" name="parentName" id="parentName" required placeholder="Parent/Guardian's Full Name"
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                    </div>

                    <div>
                      <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Email
                        Address *</label>
                      <input type="email" name="email" id="email" required placeholder="parent@example.com"
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                      <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Used for vaccination reminders</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                      <div>
                        <label for="contactNumber"
                          class="block text-sm font-medium text-slate-700 dark:text-slate-300">Contact Number</label>
                        <input type="tel" name="contactNumber" id="contactNumber"
                          class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                      </div>
                      <div>
                        <label for="address"
                          class="block text-sm font-medium text-slate-700 dark:text-slate-300">Address</label>
                        <input type="text" name="address" id="address"
                          class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                      </div>
                    </div>
                    
                    <div>
                      <label for="relationship" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Relationship to Baby *</label>
                      <select name="relationship" id="relationship" required
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                        <option value="">Select Relationship</option>
                        <option value="Mother">Mother</option>
                        <option value="Father">Father</option>
                        <option value="Guardian">Guardian</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>

                    <!-- Parent's Concern -->
                    <div>
                      <label for="parentConcern" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Parent's Concern</label>
                      <textarea name="parentConcern" id="parentConcern" rows="3" placeholder="Enter any concerns or notes from the parent..."
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm"></textarea>
                      <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Optional: Any concerns or special notes from the parent</p>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <div class="bg-slate-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 dark:bg-slate-800/50">
            <button type="button" id="submitBabyRegistration"
              class="inline-flex w-full justify-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 sm:ml-3 sm:w-auto">
              Register Baby
            </button>
            <button type="button" id="cancelRegistration"
              class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 sm:mt-0 sm:w-auto dark:bg-slate-700 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-600">
              Cancel
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Success Notification -->
  <div id="successNotification"
    class="fixed bottom-4 right-4 z-50 hidden max-w-sm rounded-lg bg-green-100 p-4 text-green-700 shadow-lg dark:bg-green-800 dark:text-green-100">
    <div class="flex items-center">
      <span class="material-symbols-outlined mr-2">check_circle</span>
      <span id="successMessage">Baby registered successfully!</span>
      <button type="button"
        class="ml-auto -mx-1.5 -my-1.5 rounded-lg p-1.5 text-green-500 hover:bg-green-200 focus:ring-2 focus:ring-green-400 dark:bg-green-800 dark:text-green-200 dark:hover:bg-green-700">
        <span class="material-symbols-outlined text-lg">close</span>
      </button>
    </div>
  </div>

  <!-- Error Notification -->
  <div id="errorNotification"
    class="fixed bottom-4 right-4 z-50 hidden max-w-sm rounded-lg bg-red-100 p-4 text-red-700 shadow-lg dark:bg-red-800 dark:text-red-100">
    <div class="flex items-center">
      <span class="material-symbols-outlined mr-2">error</span>
      <span id="errorMessage">Error registering baby. Please try again.</span>
      <button type="button"
        class="ml-auto -mx-1.5 -my-1.5 rounded-lg p-1.5 text-red-500 hover:bg-red-200 focus:ring-2 focus:ring-red-400 dark:bg-red-800 dark:text-red-200 dark:hover:bg-red-700">
        <span class="material-symbols-outlined text-lg">close</span>
      </button>
    </div>
  </div>

  <!-- Patient Credentials Modal -->
  <div id="credentialsModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="credentials-modal-title"
    role="dialog" aria-modal="true">
    <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
      <div id="credentialsModalOverlay" class="fixed inset-0 bg-slate-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
      <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>
      <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle dark:bg-slate-800">
        <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4 dark:bg-slate-800">
          <div class="sm:flex sm:items-start">
            <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
              <h3 class="text-lg font-medium leading-6 text-slate-900 dark:text-white" id="credentials-modal-title">
                Patient Login Credentials
              </h3>
              <div class="mt-4">
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4" id="credentialsDescription">
                  Please provide these credentials to the patient so they can access their account.
                </p>
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                  <div class="space-y-3">
                    <div>
                      <label class="block text-xs font-medium text-blue-800 dark:text-blue-200 mb-1">Username</label>
                      <div class="flex items-center gap-2">
                        <input type="text" id="credUsername"
                          class="flex-1 font-mono text-sm bg-white dark:bg-slate-800 border border-blue-300 dark:border-blue-700 rounded px-3 py-2 text-blue-900 dark:text-blue-100">
                        <button type="button" onclick="copyToClipboard('credUsername')"
                          class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium">
                          Copy
                        </button>
                      </div>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-blue-800 dark:text-blue-200 mb-1">Email</label>
                      <div class="flex items-center gap-2">
                        <input type="text" id="credEmail"
                          class="flex-1 font-mono text-sm bg-white dark:bg-slate-800 border border-blue-300 dark:border-blue-700 rounded px-3 py-2 text-blue-900 dark:text-blue-100">
                        <button type="button" onclick="copyToClipboard('credEmail')"
                          class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium">
                          Copy
                        </button>
                      </div>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-blue-800 dark:text-blue-200 mb-1">Password</label>
                      <div class="flex items-center gap-2">
                        <input type="text" id="credPassword"
                          class="flex-1 font-mono text-sm bg-white dark:bg-slate-800 border border-blue-300 dark:border-blue-700 rounded px-3 py-2 text-blue-900 dark:text-blue-100">
                        <button type="button" onclick="copyToClipboard('credPassword')"
                          class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium">
                          Copy
                        </button>
                      </div>
                      <p class="text-xs text-blue-700 dark:text-blue-300 mt-1" id="passwordHint">
                        Password format: firstname + birthyear + 123. Patient can change it after logging in.
                      </p>
                    </div>
                    <div class="mt-4 pt-4 border-t border-blue-200 dark:border-blue-700">
                      <button type="button" onclick="copyAllCredentials()"
                        class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold">
                        Copy All Credentials
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="bg-slate-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 dark:bg-slate-800/50">
            <button type="button" id="saveCredentialsBtn" class="hidden inline-flex w-full justify-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-700 sm:ml-3 sm:w-auto">
              Save Changes
            </button>
            <button type="button" id="closeCredentialsModal"
              class="inline-flex w-full justify-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 sm:ml-3 sm:w-auto">
              Done
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Patient Modal -->
  <div id="editPatientModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="edit-modal-title"
    role="dialog" aria-modal="true">
    <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
      <!-- Background overlay -->
      <div id="editModalOverlay" class="fixed inset-0 bg-slate-500 bg-opacity-75 transition-opacity" aria-hidden="true">
      </div>

      <!-- Modal panel -->
      <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

      <div
        class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle dark:bg-slate-800">
        <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4 dark:bg-slate-800">
          <div class="sm:flex sm:items-start">
            <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
              <h3 class="text-lg font-medium leading-6 text-slate-900 dark:text-white" id="edit-modal-title">
                Edit Patient Information
              </h3>
              <div class="mt-4">
                <form id="editPatientForm" class="space-y-6">
                  <input type="hidden" id="editPatientId" name="patientId">
                  <input type="hidden" id="editUserId" name="userId">
                  
                  <!-- Baby's Information Section -->
                  <div class="space-y-4">
                    <h4 class="text-base font-medium text-slate-800 dark:text-slate-200 border-b border-slate-200 dark:border-slate-700 pb-2">Baby's Information</h4>
                    
                    <!-- Baby's Name -->
                    <div>
                      <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Baby's Full Name *</label>
                      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                          <input type="text" name="editFirstName" id="editFirstName" required placeholder="First Name"
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                        </div>
                        <div>
                          <input type="text" name="editLastName" id="editLastName" required placeholder="Last Name"
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                        </div>
                      </div>
                    </div>

                    <!-- Date of Birth -->
                    <div>
                      <label for="editBirthDate" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Date of Birth *</label>
                      <input type="date" name="editBirthDate" id="editBirthDate" required
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                      <p id="editAgeError" class="mt-1 text-xs text-red-600 dark:text-red-400 hidden"></p>
                    </div>

                    <!-- Gender -->
                    <div>
                      <label for="editGender" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Gender *</label>
                      <select name="editGender" id="editGender" required
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                        <option value="">Select Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                      </select>
                    </div>
                  </div>

                  <!-- Parent/Guardian Information Section -->
                  <div class="space-y-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                    <h4 class="text-base font-medium text-slate-800 dark:text-slate-200 pb-2">Parent/Guardian's Information</h4>
                    
                    <!-- Parent/Guardian Name -->
                    <div>
                      <label for="editParentName" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Full Name *</label>
                      <input type="text" name="editParentName" id="editParentName" required placeholder="Parent/Guardian's Full Name"
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                    </div>

                    <div>
                      <label for="editEmail" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Email
                        Address *</label>
                      <input type="email" name="editEmail" id="editEmail" required placeholder="parent@example.com"
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                      <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Used for vaccination reminders</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                      <div>
                        <label for="editContactNumber"
                          class="block text-sm font-medium text-slate-700 dark:text-slate-300">Contact Number</label>
                        <input type="tel" name="editContactNumber" id="editContactNumber"
                          class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                      </div>
                      <div>
                        <label for="editAddress"
                          class="block text-sm font-medium text-slate-700 dark:text-slate-300">Address</label>
                        <input type="text" name="editAddress" id="editAddress"
                          class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                      </div>
                    </div>
                    
                    <div>
                      <label for="editRelationship" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Relationship to Baby *</label>
                      <select name="editRelationship" id="editRelationship" required
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm">
                        <option value="">Select Relationship</option>
                        <option value="Mother">Mother</option>
                        <option value="Father">Father</option>
                        <option value="Guardian">Guardian</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>

                    <!-- Parent's Concern -->
                    <div>
                      <label for="editParentConcern" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Parent's Concern</label>
                      <textarea name="editParentConcern" id="editParentConcern" rows="3" placeholder="Enter any concerns or notes from the parent..."
                        class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-primary focus:ring-primary dark:border-slate-700 dark:bg-slate-900 dark:text-white sm:text-sm"></textarea>
                      <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Optional: Any concerns or special notes from the parent</p>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <div class="bg-slate-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 dark:bg-slate-800/50">
            <button type="button" id="submitEditPatient"
              class="inline-flex w-full justify-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 sm:ml-3 sm:w-auto">
              Update Patient
            </button>
            <button type="button" id="cancelEditPatient"
              class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 sm:mt-0 sm:w-auto dark:bg-slate-700 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-600">
              Cancel
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Calculate age in months
    function calculateAgeInMonths(birthDate) {
      const today = new Date();
      const birth = new Date(birthDate);
      let months = (today.getFullYear() - birth.getFullYear()) * 12;
      months += today.getMonth() - birth.getMonth();
      if (today.getDate() < birth.getDate()) {
        months--;
      }
      return months;
    }

    // Modal elements
    const modal = document.getElementById('registerBabyModal');
    const modalOverlay = document.getElementById('modalOverlay');
    const openModalBtn = document.getElementById('openRegisterModal');
    const closeModalBtn = document.getElementById('cancelRegistration');
    const submitBtn = document.getElementById('submitBabyRegistration');
    const form = document.getElementById('registerBabyForm');
    const successNotification = document.getElementById('successNotification');
    const errorNotification = document.getElementById('errorNotification');

    // Set max date to 12 months ago (newborns only)
    function setMaxDate() {
      const birthDateInput = document.getElementById('birthDate');
      const today = new Date();
      const maxDate = new Date(today);
      maxDate.setMonth(today.getMonth() - 12);
      const maxDateStr = maxDate.toISOString().split('T')[0];
      birthDateInput.setAttribute('max', maxDateStr);
      
      // Set min date to prevent future dates
      const minDateStr = today.toISOString().split('T')[0];
      birthDateInput.setAttribute('min', '2020-01-01'); // Reasonable minimum
    }

    // Validate age in real-time
    function validateAge() {
      const birthDateInput = document.getElementById('birthDate');
      const ageError = document.getElementById('ageError');
      
      if (!birthDateInput.value) {
        ageError.classList.add('hidden');
        birthDateInput.classList.remove('border-red-500');
        return true;
      }

      const ageInMonths = calculateAgeInMonths(birthDateInput.value);
      
      if (ageInMonths < 0) {
        ageError.textContent = 'Birth date cannot be in the future';
        ageError.classList.remove('hidden');
        birthDateInput.classList.add('border-red-500');
        return false;
      }
      
      if (ageInMonths > 12) {
        ageError.textContent = `Baby is ${ageInMonths} months old. Only newborns (0-12 months) can be registered.`;
        ageError.classList.remove('hidden');
        birthDateInput.classList.add('border-red-500');
        return false;
      }
      
      // Valid age
      ageError.classList.add('hidden');
      birthDateInput.classList.remove('border-red-500');
      return true;
    }

    // Open modal
    openModalBtn.addEventListener('click', () => {
      modal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      setMaxDate();
      // Reset form and clear any errors
      form.reset();
      document.getElementById('ageError').classList.add('hidden');
      document.getElementById('birthDate').classList.remove('border-red-500');
    });

    // Real-time age validation
    document.getElementById('birthDate').addEventListener('change', validateAge);
    document.getElementById('birthDate').addEventListener('input', validateAge);

    // Close modal
    function closeModal() {
      modal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      form.reset();
      document.getElementById('ageError').classList.add('hidden');
      document.getElementById('birthDate').classList.remove('border-red-500');
    }

    modalOverlay.addEventListener('click', closeModal);
    closeModalBtn.addEventListener('click', closeModal);

    // Close notifications
    function closeNotification(notification) {
      notification.classList.add('hidden');
    }

    document.querySelectorAll('#successNotification button, #errorNotification button').forEach(button => {
      button.addEventListener('click', (e) => {
        closeNotification(e.target.closest('.fixed'));
      });
    });

    // Form submission
    submitBtn.addEventListener('click', async (e) => {
      e.preventDefault();

      // Validate required fields
      const requiredFields = ['firstName', 'lastName', 'birthDate', 'gender'];
      let isValid = true;

      requiredFields.forEach(field => {
        const input = document.getElementById(field);
        if (!input.value.trim()) {
          input.classList.add('border-red-500');
          isValid = false;
        } else {
          input.classList.remove('border-red-500');
        }
      });

      if (!isValid) {
        showNotification('Please fill in all required fields', 'error');
        return;
      }

      // Validate age is 0-12 months (strict validation)
      if (!validateAge()) {
        // validateAge() already shows the error message
        return;
      }
      
      const birthDate = document.getElementById('birthDate').value;
      const ageInMonths = calculateAgeInMonths(birthDate);
      
      // Double check - prevent submission if age exceeds 12 months
      if (ageInMonths > 12) {
        showNotification('Only newborns (0-12 months) can be registered. This baby is too old for registration.', 'error');
        document.getElementById('birthDate').classList.add('border-red-500');
        return;
      }
      
      if (ageInMonths < 0) {
        showNotification('Birth date cannot be in the future', 'error');
        document.getElementById('birthDate').classList.add('border-red-500');
        return;
      }

      // Disable submit button
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="material-symbols-outlined animate-spin">refresh</span> Registering...';

      try {
        // Get form data
        const formData = {
          firstName: document.getElementById('firstName').value.trim(),
          lastName: document.getElementById('lastName').value.trim(),
          birthDate: document.getElementById('birthDate').value,
          gender: document.getElementById('gender').value,
          parentName: document.getElementById('parentName').value.trim(),
          email: document.getElementById('email').value.trim(),
          contactNumber: document.getElementById('contactNumber').value.trim(),
          address: document.getElementById('address').value.trim(),
          relationship: document.getElementById('relationship').value,
          parentConcern: document.getElementById('parentConcern').value.trim()
        };

        // Send data to server
        const response = await fetch('register_baby.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          credentials: 'include', // Include cookies/session for authentication
          body: JSON.stringify(formData)
        });

        // Check if response is OK
        if (!response.ok) {
          const errorText = await response.text();
          let errorMessage = 'Failed to register baby';
          try {
            const errorJson = JSON.parse(errorText);
            errorMessage = errorJson.error || errorMessage;
          } catch (e) {
            errorMessage = errorText || `Server error: ${response.status}`;
          }
          throw new Error(errorMessage);
        }

        const result = await response.json();

        if (result.success) {
          // Close modal
          closeModal();
          
          // Show credentials modal if credentials are available
          if (result.credentials) {
            showCredentialsModal(result.credentials, result.patient);
          } else {
            showNotification('Baby registered successfully!', 'success');
            setTimeout(() => {
              window.location.reload();
            }, 1000);
          }
        } else {
          throw new Error(result.error || 'Failed to register baby');
        }
      } catch (error) {
        console.error('Error:', error);
        // Show the actual error message from the server
        const errorMessage = error.message || 'An error occurred while registering the baby.';
        showNotification(errorMessage, 'error');
      } finally {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Register Baby';
      }
    });

    // Confirm and delete patient
    async function confirmDelete(patientId, patientName) {
      if (!confirm(`Are you sure you want to delete ${patientName}? This action cannot be undone.`)) {
        return;
      }

      try {
        const response = await fetch('delete_patient.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ patient_id: patientId })
        });

        const result = await response.json();

        if (result.success) {
          // Show success message
          showNotification(`Successfully deleted ${patientName}`, 'success');

          // Remove the row from the table
          const row = document.querySelector(`tr[data-patient-id="${patientId}"]`);
          if (row) {
            row.remove();
          }

          // If no more patients, show message
          if (document.querySelectorAll('tbody tr').length === 0) {
            const tbody = document.querySelector('tbody');
            tbody.innerHTML = '<tr class="bg-white"><td class="px-6 py-4 text-center" colspan="7">No patients found.</td></tr>';
          }
        } else {
          throw new Error(result.error || 'Failed to delete patient');
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('An error occurred while deleting the patient.', 'error');
      }
    }

    // Helper function to show notifications
    function showNotification(message, type = 'success') {
      const notification = document.createElement('div');
      notification.className = `fixed bottom-4 right-4 z-50 max-w-sm rounded-lg p-4 shadow-lg ${type === 'success'
          ? 'bg-green-100 text-green-700 dark:bg-green-800 dark:text-green-100'
          : 'bg-red-100 text-red-700 dark:bg-red-800 dark:text-red-100'
        }`;

      notification.innerHTML = `
        <div class="flex items-center">
          <span class="material-symbols-outlined mr-2">
            ${type === 'success' ? 'check_circle' : 'error'}
          </span>
          <span class="text-sm font-medium">${message}</span>
          <button type="button"
            class="ml-auto -mx-1.5 -my-1.5 rounded-lg p-1.5 text-${type === 'success' ? 'green-500' : 'red-500'} hover:bg-${type === 'success' ? 'green-200' : 'red-200'} focus:ring-2 focus:ring-${type === 'success' ? 'green-400' : 'red-400'} dark:bg-${type === 'success' ? 'green-800' : 'red-800'} dark:text-${type === 'success' ? 'green-200' : 'red-200'} dark:hover:bg-${type === 'success' ? 'green-700' : 'red-700'}">
            <span class="material-symbols-outlined text-lg">close</span>
          </button>
        </div>
      `;

      document.body.appendChild(notification);

      // Auto-remove after 5 seconds
      setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transition = 'opacity 0.5s ease-in-out';
        setTimeout(() => {
          notification.remove();
        }, 500);
      }, 5000);
    }
  </script>

  <script>
    function deletePatient(patientId, event) {
      if (!confirm('Are you sure you want to delete this patient? This action cannot be undone.')) {
        return;
      }

      // Get the button that was clicked
      const deleteBtn = event.target;
      const row = deleteBtn.closest('tr');

      // Create form data
      const formData = new FormData();
      formData.append('patient_id', patientId);

      // Show loading state
      const originalText = deleteBtn.textContent;
      deleteBtn.disabled = true;
      deleteBtn.innerHTML = '<span class="animate-spin">⏳</span> Deleting...';

      // Make the request
      fetch('/HealthCenter/health_worker/api/delete_patient.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin' // Important for sending session cookies
      })
        .then(response => {
          if (!response.ok) {
            return response.text().then(text => {
              throw new Error(text || 'Failed to delete patient');
            });
          }
          return response.text();
        })
        .then(result => {
          if (result.trim() === 'success') {
            // Fade out the row
            row.style.transition = 'opacity 0.3s';
            row.style.opacity = '0';

            // Show success message
            showNotification('Patient deleted successfully', 'success');

            // Remove the row after the transition
            setTimeout(() => {
              row.remove();

              // Check if table is empty
              const tbody = document.querySelector('tbody');
              if (tbody) {
                const rows = tbody.querySelectorAll('tr:not(.no-hover)');
                if (rows.length === 0) {
                  tbody.innerHTML = '<tr><td class="px-6 py-4 text-center text-slate-500" colspan="5">No patients found.</td></tr>';
                }
              }
            }, 300);
          } else {
            throw new Error(result || 'Failed to delete patient');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          showNotification('Error: ' + (error.message || 'Failed to delete patient. Please try again.'), 'error');
          deleteBtn.disabled = false;
          deleteBtn.innerHTML = '<span class="text-red-600">Error - Try Again</span>';

          // Revert to original text after 3 seconds
          setTimeout(() => {
            deleteBtn.innerHTML = originalText;
          }, 3000);
        });
    }

    // Function to show notification
    // Function to handle completing an appointment and updating the UI
    async function completeAppointment(appointmentId, patientId, fullName, birthDate, gender, contactNumber, address, email = '', guardianName = '') {
      if (!appointmentId || appointmentId === 'null') {
        showNotification('No appointment selected', 'error');
        return;
      }

      if (!confirm('Are you sure you want to complete this appointment?')) {
        return;
      }

      try {
        // Show loading state
        const completeBtn = document.querySelector(`button[onclick*="completeAppointment('${appointmentId}'"]`);
        const originalBtnText = completeBtn ? completeBtn.innerHTML : '';
        if (completeBtn) {
          completeBtn.disabled = true;
          completeBtn.innerHTML = '<span class="animate-spin">⏳</span> Completing...';
        }

        // Complete the appointment
        const response = await fetch('/HealthCenter/health_worker/api/complete_appointment.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `appointment_id=${encodeURIComponent(appointmentId)}`
        });

        const result = await response.json();

        if (result.success) {
          showNotification('Appointment completed successfully!', 'success');

          // Reload the page to reflect the changes
          setTimeout(() => {
            window.location.reload();
          }, 1000);
        } else {
          showNotification(result.message || 'Failed to complete appointment', 'error');
          if (completeBtn) {
            completeBtn.disabled = false;
            completeBtn.innerHTML = originalBtnText;
          }
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('An error occurred while processing your request', 'error');
      }
    }

    // Function to show notification
    function showNotification(message, type = 'success') {
      // Hide any existing notifications first
      document.querySelectorAll('.notification').forEach(el => el.remove());
      
      // Create new notification
      const notification = document.createElement('div');
      notification.className = `fixed bottom-4 right-4 z-50 max-w-sm rounded-lg p-4 shadow-lg ${type === 'success'
          ? 'bg-green-100 text-green-700 dark:bg-green-800 dark:text-green-100'
          : 'bg-red-100 text-red-700 dark:bg-red-800 dark:text-red-100'
        }`;

      notification.innerHTML = `
        <div class="flex items-center">
          <span class="material-symbols-outlined mr-2">
            ${type === 'success' ? 'check_circle' : 'error'}
          </span>
          <span class="text-sm font-medium">${message}</span>
          <button type="button"
            class="ml-auto -mx-1.5 -my-1.5 rounded-lg p-1.5 text-${type === 'success' ? 'green-500' : 'red-500'} hover:bg-${type === 'success' ? 'green-200' : 'red-200'} focus:ring-2 focus:ring-${type === 'success' ? 'green-400' : 'red-400'} dark:bg-${type === 'success' ? 'green-800' : 'red-800'} dark:text-${type === 'success' ? 'green-200' : 'red-200'} dark:hover:bg-${type === 'success' ? 'green-700' : 'red-700'}">
            <span class="material-symbols-outlined text-lg">close</span>
          </button>
                ${type === 'success' ? 'check_circle' : 'error'}
            </span>
            <span class="text-sm font-medium">${message}</span>
        </div>
    `;

      document.body.appendChild(notification);

      // Auto-remove after 5 seconds
      setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transition = 'opacity 0.5s ease-in-out';
        setTimeout(() => {
          notification.remove();
        }, 500);
      }, 5000);
    }

    // Edit Patient Modal Functions - Define globally so they can be called from onclick
    const editModal = document.getElementById('editPatientModal');
    const editModalOverlay = document.getElementById('editModalOverlay');
    const cancelEditBtn = document.getElementById('cancelEditPatient');
    const submitEditBtn = document.getElementById('submitEditPatient');

    // Function to open edit modal and populate with patient data
    function openEditPatientModal() {
      try {
        const modal = document.getElementById('editPatientModal');
        if (!modal) {
          console.error('Edit modal element not found');
          showNotification('Edit modal not available', 'error');
          return;
        }
        
        <?php if (isset($patientProfile) && $patientProfile): ?>
        // Parse patient data
        const patientData = {
          id: <?php echo intval($patientProfile['id'] ?? 0); ?>, // patient_profiles.id
          userId: <?php echo intval($patientProfile['user_id'] ?? 0); ?>, // users.id
          childName: <?php echo json_encode($patientProfile['child_name'] ?? ''); ?>,
          birthDate: <?php echo json_encode($patientProfile['birth_date'] ?? ''); ?>,
          gender: <?php echo json_encode($gender ?? ''); ?>,
          email: <?php echo json_encode($patientProfile['email'] ?? ''); ?>,
          contactNumber: <?php echo json_encode($patientProfile['contact_number'] ?? ''); ?>,
          address: <?php echo json_encode($actualAddress ?? ''); ?>,
          parentName: <?php echo json_encode($parentName ?? ''); ?>,
          relationship: <?php echo json_encode($relationship ?? ''); ?>,
          parentConcern: <?php echo json_encode($parentConcern ?? ''); ?>
        };

        // Validate required data
        if (!patientData.id || !patientData.userId) {
          console.error('Invalid patient data:', patientData);
          showNotification('Invalid patient data. Please refresh the page and try again.', 'error');
          return;
        }

        // Split child name into first and last name
        const nameParts = (patientData.childName || '').trim().split(/\s+/);
        const firstName = nameParts[0] || '';
        const lastName = nameParts.slice(1).join(' ') || '';

        // Populate form fields
        const editPatientId = document.getElementById('editPatientId');
        const editUserId = document.getElementById('editUserId');
        const editFirstName = document.getElementById('editFirstName');
        const editLastName = document.getElementById('editLastName');
        const editBirthDate = document.getElementById('editBirthDate');
        const editGender = document.getElementById('editGender');
        const editEmail = document.getElementById('editEmail');
        const editContactNumber = document.getElementById('editContactNumber');
        const editAddress = document.getElementById('editAddress');
        const editParentName = document.getElementById('editParentName');
        const editRelationship = document.getElementById('editRelationship');
        const editParentConcern = document.getElementById('editParentConcern');

        if (!editPatientId || !editUserId || !editFirstName || !editLastName || !editBirthDate || 
            !editGender || !editEmail || !editParentName || !editRelationship) {
          console.error('Required form fields not found');
          showNotification('Edit form not available', 'error');
          return;
        }
        
        editPatientId.value = patientData.id;
        editUserId.value = patientData.userId;
        editFirstName.value = firstName;
        editLastName.value = lastName;
        editBirthDate.value = patientData.birthDate || '';
        editGender.value = patientData.gender || '';
        editEmail.value = patientData.email || '';
        if (editContactNumber) editContactNumber.value = patientData.contactNumber || '';
        if (editAddress) editAddress.value = patientData.address || '';
        editParentName.value = patientData.parentName || '';
        editRelationship.value = patientData.relationship || '';
        if (editParentConcern) editParentConcern.value = patientData.parentConcern || '';

        // Show modal
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        <?php else: ?>
        console.error('Patient profile data not available');
        showNotification('No patient data available', 'error');
        <?php endif; ?>
      } catch (error) {
        console.error('Error opening edit modal:', error);
        showNotification('An error occurred while opening the edit form', 'error');
      }
    }

    // Close edit modal
    function closeEditModal() {
      const modal = document.getElementById('editPatientModal');
      if (modal) {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        const ageError = document.getElementById('editAgeError');
        const birthDate = document.getElementById('editBirthDate');
        if (ageError) ageError.classList.add('hidden');
        if (birthDate) birthDate.classList.remove('border-red-500');
      }
    }

    // Only set up event listeners if elements exist
    if (editModal && editModalOverlay && cancelEditBtn) {
      editModalOverlay.addEventListener('click', closeEditModal);
      cancelEditBtn.addEventListener('click', closeEditModal);
    }

    // Validate edit form age (no limit for editing, but still validate it's not in future)
    function validateEditAge() {
      const birthDateInput = document.getElementById('editBirthDate');
      const ageError = document.getElementById('editAgeError');
      
      if (!birthDateInput) return true;
      
      if (!birthDateInput.value) {
        if (ageError) ageError.classList.add('hidden');
        birthDateInput.classList.remove('border-red-500');
        return true;
      }

      const birthDate = new Date(birthDateInput.value);
      const today = new Date();
      
      if (birthDate > today) {
        if (ageError) {
          ageError.textContent = 'Birth date cannot be in the future';
          ageError.classList.remove('hidden');
        }
        birthDateInput.classList.add('border-red-500');
        return false;
      }
      
      if (ageError) ageError.classList.add('hidden');
      birthDateInput.classList.remove('border-red-500');
      return true;
    }

    // Only set up edit form handlers if elements exist
    if (editModal && submitEditBtn) {
      // Real-time age validation for edit form
      const editBirthDate = document.getElementById('editBirthDate');
      if (editBirthDate) {
        editBirthDate.addEventListener('change', validateEditAge);
        editBirthDate.addEventListener('input', validateEditAge);
      }

      // Handle edit form submission
      submitEditBtn.addEventListener('click', async (e) => {
      e.preventDefault();

      // Validate required fields
      const requiredFields = ['editFirstName', 'editLastName', 'editBirthDate', 'editGender', 'editParentName', 'editEmail', 'editRelationship'];
      let isValid = true;

      requiredFields.forEach(field => {
        const input = document.getElementById(field);
        if (!input.value.trim()) {
          input.classList.add('border-red-500');
          isValid = false;
        } else {
          input.classList.remove('border-red-500');
        }
      });

      if (!isValid) {
        showNotification('Please fill in all required fields', 'error');
        return;
      }

      // Validate birth date is not in future
      if (!validateEditAge()) {
        return;
      }

      // Disable submit button
      submitEditBtn.disabled = true;
      submitEditBtn.innerHTML = '<span class="material-symbols-outlined animate-spin">refresh</span> Updating...';

      try {
        // Get form data
        const formData = {
          patientId: document.getElementById('editPatientId').value,
          userId: document.getElementById('editUserId').value,
          firstName: document.getElementById('editFirstName').value.trim(),
          lastName: document.getElementById('editLastName').value.trim(),
          birthDate: document.getElementById('editBirthDate').value,
          gender: document.getElementById('editGender').value,
          parentName: document.getElementById('editParentName').value.trim(),
          email: document.getElementById('editEmail').value.trim(),
          contactNumber: document.getElementById('editContactNumber').value.trim(),
          address: document.getElementById('editAddress').value.trim(),
          relationship: document.getElementById('editRelationship').value,
          parentConcern: document.getElementById('editParentConcern').value.trim()
        };

        // Send data to server
        const response = await fetch('update_patient.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          credentials: 'include',
          body: JSON.stringify(formData)
        });

        // Check if response is OK
        if (!response.ok) {
          const errorText = await response.text();
          let errorMessage = 'Failed to update patient';
          try {
            const errorJson = JSON.parse(errorText);
            errorMessage = errorJson.error || errorMessage;
          } catch (e) {
            errorMessage = errorText || `Server error: ${response.status}`;
          }
          throw new Error(errorMessage);
        }

        const result = await response.json();

        if (result.success) {
          // Close modal and show success message
          closeEditModal();
          showNotification('Patient information updated successfully!', 'success');

          // Reload the page to show updated information
          setTimeout(() => {
            window.location.reload();
          }, 1000);
        } else {
          throw new Error(result.error || 'Failed to update patient');
        }
      } catch (error) {
        console.error('Error:', error);
        const errorMessage = error.message || 'An error occurred while updating the patient information.';
        showNotification(errorMessage, 'error');
      } finally {
        // Re-enable submit button
        submitEditBtn.disabled = false;
        submitEditBtn.innerHTML = 'Update Patient';
      }
    });
    } // End of edit modal setup (if elements exist)

    // Credentials Modal Functions
    // Edit mode: credentials can be edited (after registration)
    function showCredentialsModal(credentials, patient) {
      const modal = document.getElementById('credentialsModal');
      const usernameInput = document.getElementById('credUsername');
      const emailInput = document.getElementById('credEmail');
      const passwordInput = document.getElementById('credPassword');
      const saveBtn = document.getElementById('saveCredentialsBtn');
      const title = document.getElementById('credentials-modal-title');
      const description = document.getElementById('credentialsDescription');
      
      if (modal && usernameInput && emailInput && passwordInput) {
        // Set values from registration response
        usernameInput.value = credentials.username || '';
        emailInput.value = credentials.email || '';
        passwordInput.value = credentials.password || '';
        
        // Store initial credentials in localStorage for later display (persists across sessions)
        if (patient && patient.id && credentials.password) {
          localStorage.setItem('patient_credentials_' + patient.id, JSON.stringify({
            username: credentials.username || '',
            email: credentials.email || '',
            password: credentials.password || ''
          }));
        }
        
        // Make fields editable
        usernameInput.removeAttribute('readonly');
        emailInput.removeAttribute('readonly');
        passwordInput.removeAttribute('readonly');
        usernameInput.classList.remove('bg-slate-100', 'cursor-not-allowed');
        emailInput.classList.remove('bg-slate-100', 'cursor-not-allowed');
        passwordInput.classList.remove('bg-slate-100', 'cursor-not-allowed');
        
        // Show save button
        if (saveBtn) {
          saveBtn.classList.remove('hidden');
          saveBtn.onclick = function() {
            // Store updated credentials before saving (persists across sessions)
            const updatedCreds = {
              username: usernameInput.value,
              email: emailInput.value,
              password: passwordInput.value
            };
            localStorage.setItem('patient_credentials_' + patient.id, JSON.stringify(updatedCreds));
            saveCredentials(patient.id, usernameInput.value, emailInput.value, passwordInput.value);
          };
        }
        
        // Update title and description
        if (title) title.textContent = 'Edit Patient Login Credentials';
        if (description) description.textContent = 'You can edit these credentials before saving. Make them simple and easy to remember.';
        
        // Store patient ID for saving
        modal.dataset.patientId = patient.id;
        modal.dataset.mode = 'edit';
        
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
      } else {
        // Fallback: show notification with credentials
        const credsText = `Username: ${credentials.username}\nEmail: ${credentials.email}\nPassword: ${credentials.password || 'N/A'}`;
        showNotification('Baby registered! Credentials:\n' + credsText, 'success');
        setTimeout(() => {
          window.location.reload();
        }, 3000);
      }
    }

    // View mode: credentials are read-only (from patient profile)
    function showPatientCredentials() {
      const modal = document.getElementById('credentialsModal');
      const usernameInput = document.getElementById('credUsername');
      const emailInput = document.getElementById('credEmail');
      const passwordInput = document.getElementById('credPassword');
      const saveBtn = document.getElementById('saveCredentialsBtn');
      const title = document.getElementById('credentials-modal-title');
      const description = document.getElementById('credentialsDescription');
      
      <?php if (isset($patientProfile) && $patientProfile): ?>
      if (modal && usernameInput && emailInput && passwordInput) {
        const patientId = <?php echo $patientProfile['user_id']; ?>;
        const username = <?php echo json_encode($patientProfile['username'] ?? ''); ?>;
        const email = <?php echo json_encode($patientProfile['email'] ?? ''); ?>;
        const birthDate = <?php echo json_encode($patientProfile['birth_date'] ?? ''); ?>;
        const childName = <?php echo json_encode($patientProfile['child_name'] ?? ''); ?>;
        
        // Set username and email from database (actual saved values)
        usernameInput.value = username;
        emailInput.value = email;
        
        // Check if credentials were changed and stored in localStorage
        const storedCredentials = localStorage.getItem('patient_credentials_' + patientId);
        if (storedCredentials) {
          try {
            const creds = JSON.parse(storedCredentials);
            // Use stored credentials (username, email, password) if available (from registration/update)
            // Only use stored password if username matches (to ensure it's for the right patient)
            if (creds.username === username && creds.password) {
              passwordInput.value = creds.password;
            } else {
              // Username mismatch or no password, generate default password format
              generateDefaultPassword();
            }
          } catch (e) {
            generateDefaultPassword();
          }
        } else {
          // No stored credentials, generate default password format
          generateDefaultPassword();
        }
        
        function generateDefaultPassword() {
          if (birthDate && childName) {
            const firstName = childName.split(' ')[0].toLowerCase().replace(/[^a-z]/g, '');
            const birthYear = birthDate.substring(0, 4); // Get year (YYYY)
            const generatedPassword = firstName + birthYear + '123';
            passwordInput.value = generatedPassword;
          } else {
            passwordInput.value = 'Password format: firstname + birthyear + 123';
          }
        }
        
        // Make fields read-only
        usernameInput.setAttribute('readonly', 'readonly');
        emailInput.setAttribute('readonly', 'readonly');
        passwordInput.setAttribute('readonly', 'readonly');
        usernameInput.classList.add('bg-slate-100', 'cursor-not-allowed');
        emailInput.classList.add('bg-slate-100', 'cursor-not-allowed');
        passwordInput.classList.add('bg-slate-100', 'cursor-not-allowed');
        
        // Hide save button
        if (saveBtn) {
          saveBtn.classList.add('hidden');
        }
        
        // Update title and description
        if (title) title.textContent = 'Patient Login Credentials';
        if (description) description.textContent = 'These are the patient\'s login credentials. They are read-only for viewing purposes.';
        
        // Set mode to view
        modal.dataset.mode = 'view';
        
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
      }
      <?php endif; ?>
    }
    
    // Save credentials function
    async function saveCredentials(patientId, username, email, password) {
      if (!username || !email || !password) {
        showNotification('Please fill in all credential fields', 'error');
        return;
      }
      
      const saveBtn = document.getElementById('saveCredentialsBtn');
      const originalText = saveBtn.textContent;
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';
      
      try {
        const response = await fetch('update_credentials.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          credentials: 'include',
          body: JSON.stringify({
            patient_id: patientId,
            username: username,
            email: email,
            password: password
          })
        });
        
        const result = await response.json();
        
        if (result.success) {
          // Store updated credentials in localStorage for display in patient profile (persists across sessions)
          if (result.credentials) {
            localStorage.setItem('patient_credentials_' + patientId, JSON.stringify(result.credentials));
          }
          showNotification('Credentials updated successfully!', 'success');
          closeCredentialsModal();
          setTimeout(() => {
            window.location.reload();
          }, 1000);
        } else {
          throw new Error(result.error || 'Failed to update credentials');
        }
      } catch (error) {
        console.error('Error:', error);
        showNotification('Failed to update credentials: ' + error.message, 'error');
        saveBtn.disabled = false;
        saveBtn.textContent = originalText;
      }
    }

    function copyToClipboard(inputId) {
      const input = document.getElementById(inputId);
      if (input) {
        input.select();
        input.setSelectionRange(0, 99999); // For mobile devices
        document.execCommand('copy');
        
        // Show feedback
        const btn = event.target;
        const originalText = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.add('bg-green-600');
        setTimeout(() => {
          btn.textContent = originalText;
          btn.classList.remove('bg-green-600');
        }, 2000);
      }
    }

    function copyAllCredentials() {
      const username = document.getElementById('credUsername')?.value || '';
      const email = document.getElementById('credEmail')?.value || '';
      const password = document.getElementById('credPassword')?.value || '';
      
      const text = `Patient Login Credentials\n\nUsername: ${username}\nEmail: ${email}\nPassword: ${password}\n\nLogin URL: /HealthCenter/login.php\n\nNote: Patient can change password after logging in.`;
      
      navigator.clipboard.writeText(text).then(function() {
        showNotification('All credentials copied to clipboard!', 'success');
      }, function() {
        // Fallback
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showNotification('All credentials copied to clipboard!', 'success');
      });
    }

    // Close credentials modal
    const credentialsModal = document.getElementById('credentialsModal');
    const credentialsModalOverlay = document.getElementById('credentialsModalOverlay');
    const closeCredentialsBtn = document.getElementById('closeCredentialsModal');
    
    function closeCredentialsModal() {
      if (credentialsModal) {
        credentialsModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      }
    }

    if (credentialsModalOverlay) {
      credentialsModalOverlay.addEventListener('click', closeCredentialsModal);
    }
    if (closeCredentialsBtn) {
      closeCredentialsBtn.addEventListener('click', function() {
        closeCredentialsModal();
        // Reload page after closing credentials modal
        setTimeout(() => {
          window.location.reload();
        }, 500);
      });
    }
  </script>
</body>

</html>