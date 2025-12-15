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

// Logged-in user info (authoritative full_name/email)
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

// Patient profile (extra details such as birth_date)
$profile = null;
$stmt = $mysqli->prepare('SELECT child_name, birth_date, guardian_name, address FROM patient_profiles WHERE user_id = ? LIMIT 1');
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $profile = $res->fetch_assoc() ?: null;
  $stmt->close();
}

// Vaccination history
$records = [];
$stmt = $mysqli->prepare('SELECT v.name AS vaccine_name, vr.date_given, vr.dose, vr.created_at, vr.id AS record_id FROM vaccination_records vr JOIN vaccines v ON v.id = vr.vaccine_id WHERE vr.patient_id = ? ORDER BY vr.date_given DESC');
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $records[] = $r;
  }
  $stmt->close();
}

// Upcoming scheduled appointments
$appointments = [];
$stmt = $mysqli->prepare("SELECT id, scheduled_at, status FROM appointments WHERE patient_id = ? AND scheduled_at >= NOW() ORDER BY scheduled_at ASC LIMIT 5");
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($a = $res->fetch_assoc()) {
    $appointments[] = $a;
  }
  $stmt->close();
}

// Vaccines not yet received (to be scheduled)
$dueVaccines = [];
$stmt = $mysqli->prepare('SELECT v.name FROM vaccines v WHERE NOT EXISTS (SELECT 1 FROM vaccination_records vr WHERE vr.patient_id = ? AND vr.vaccine_id = v.id) LIMIT 10');
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($v = $res->fetch_assoc()) {
    $dueVaccines[] = $v['name'];
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

?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1.0" name="viewport" />
  <title>Vaccination Records - HCNVMS</title>
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
  <style>
    @media print {
      @page {
        size: A4;
        margin: 1cm;
      }

      body * {
        visibility: hidden;
      }

      .print-section,
      .print-section * {
        visibility: visible;
      }

      .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 20px;
      }

      .print-header {
        text-align: center;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #000;
      }

      .print-header h1 {
        margin: 0;
        font-size: 24px;
        color: #000;
      }

      .patient-info {
        margin-bottom: 20px;
        padding: 10px;
        background: #f5f5f5;
        border-radius: 4px;
      }

      .print-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
      }

      .print-table th,
      .print-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
      }

      .print-table th {
        background-color: #f2f2f2;
      }

      .print-section h2 {
        font-size: 18px;
        margin: 20px 0 10px 0;
        color: #000;
        border-bottom: 1px solid #ddd;
        padding-bottom: 5px;
      }
    }
  </style>
</head>

<body class="font-display bg-background-light dark:bg-background-dark text-[#111418] dark:text-gray-200">
  <div class="relative flex h-auto min-h-screen w-full flex-col">
    <div class="flex h-full grow">
      <!-- Side Navigation Bar -->
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
            <a class="flex items-center gap-3 px-3 py-2 rounded-lg bg-primary/10 text-primary dark:bg-primary/20"
              href="/HealthCenter/patient/vaccination_record.php">
              <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">vaccines</span>
              <p class="text-sm font-medium">Vaccination Record</p>
            </a>
            <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
              href="/HealthCenter/patient/p_appointments.php">
              <span class="material-symbols-outlined">calendar_month</span>
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
      <main class="flex-1"><!-- Top Navigation Bar -->
        <div class="flex items-center gap-4">
          <div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-10"
            data-alt="User profile picture"></div>
        </div>
        </header>
        <div class="p-8">
          <div class="mx-auto max-w-5xl">
            <!-- Page Heading -->
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div class="flex flex-col gap-2">
                <div class="flex items-center gap-3 flex-wrap">
                  <p class="text-[#111418] dark:text-white text-4xl font-black leading-tight tracking-[-0.033em] min-w-72">
                    Vaccination Record</p>
                  <span class="inline-flex items-center rounded-full px-4 py-1.5 text-sm font-bold <?= $vaccinationStatus === 'complete' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($vaccinationStatus === 'partial' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400') ?>">
                    <span class="material-symbols-outlined text-base mr-1.5" style="font-variation-settings: 'FILL' <?= $vaccinationStatus === 'complete' ? '1' : '0' ?>;">
                      <?= $vaccinationStatus === 'complete' ? 'check_circle' : ($vaccinationStatus === 'partial' ? 'pending' : 'cancel') ?>
                    </span>
                    Vaccination Status: <?= $vaccinationStatusText ?>
                  </span>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                  <?= $vaccinesReceived ?> of <?= $vaccinesTotal ?> vaccines received (<?= $progressPercent ?>%)
                </p>
              </div>
              <div class="flex gap-3">
                <a href="/HealthCenter/patient/certificate.php"
                  class="flex cursor-pointer items-center justify-center overflow-hidden rounded-lg h-10 border border-green-500 dark:border-green-600 bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 gap-2 text-sm font-medium leading-normal px-4 hover:bg-green-100 dark:hover:bg-green-900/30">
                  <span class="material-symbols-outlined text-base">verified</span>
                  <span>Digital Certificate</span>
                </a>
                <button
                  class="flex cursor-pointer items-center justify-center overflow-hidden rounded-lg h-10 border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-[#111418] dark:text-gray-200 gap-2 text-sm font-medium leading-normal px-4"
                  onclick="printVaccinationRecord()">
                  <span class="material-symbols-outlined text-base">print</span>
                  <span>Print Record</span>
                </button>
              </div>
            </div>
            <!-- Child Profile and Alerts -->
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
              <div
                class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900 lg:col-span-2">
                <h3 class="text-lg font-bold text-[#111418] dark:text-white"><?= htmlspecialchars($user['full_name']) ?>
                </h3>
                <div
                  class="mt-4 grid grid-cols-2 gap-x-4 gap-y-6 border-t border-solid border-gray-200 pt-4 dark:border-gray-800">
                  <div class="flex flex-col gap-1">
                    <p class="text-sm text-[#617589] dark:text-gray-400">Date of Birth</p>
                    <p class="text-sm font-medium text-[#111418] dark:text-gray-200">
                      <?= htmlspecialchars($profile['birth_date'] ?? '') ?></p>
                  </div>
                  <div class="flex flex-col gap-1">
                    <p class="text-sm text-[#617589] dark:text-gray-400">Age</p>
                    <p class="text-sm font-medium text-[#111418] dark:text-gray-200">
                      <?php if (!empty($profile['birth_date'])) {
                        $dob = new DateTime($profile['birth_date']);
                        $age = $dob->diff(new DateTime());
                        echo $age->y . ' years, ' . $age->m . ' months';
                      } ?>
                    </p>
                  </div>
                  <div class="flex flex-col gap-1">
                    <p class="text-sm text-[#617589] dark:text-gray-400">Patient ID</p>
                    <p class="text-sm font-medium text-[#111418] dark:text-gray-200">
                      P-<?= htmlspecialchars($patient_id) ?></p>
                  </div>
                  <div class="flex flex-col gap-1">
                    <p class="text-sm text-[#617589] dark:text-gray-400">Health Center</p>
                    <p class="text-sm font-medium text-[#111418] dark:text-gray-200">City General Hospital</p>
                  </div>
                </div>
              </div>
              <div
                class="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <h3 class="text-lg font-bold text-[#111418] dark:text-white">Reminders &amp; Alerts</h3>
                <div class="flex flex-col gap-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                  <div
                    class="flex items-start gap-3 rounded-lg border border-warning/50 bg-warning/10 p-3 text-sm text-yellow-800 dark:border-warning/60 dark:bg-warning/20 dark:text-yellow-200">
                    <span class="material-symbols-outlined mt-0.5 text-warning text-lg">warning</span>
                    <div>
                      <p class="font-bold">Overdue: Please check your upcoming doses</p>
                      <p class="text-xs">Check the schedule and contact the health center if overdue.</p>
                    </div>
                  </div>
                  <?php if (!empty($appointments)): ?>
                    <div
                      class="flex items-start gap-3 rounded-lg border border-primary/50 bg-primary/10 p-3 text-sm text-primary dark:border-primary/60 dark:bg-primary/20 dark:text-blue-300">
                      <span class="material-symbols-outlined mt-0.5 text-primary text-lg">calendar_month</span>
                      <div>
                        <p class="font-bold">You have upcoming appointments</p>
                        <p class="text-xs"><?= count($appointments) ?> scheduled</p>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              <!-- Vaccination History Table -->
              <div
                class="mt-6 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900 lg:col-span-3">
                <h3 class="text-lg font-bold text-[#111418] dark:text-white">Vaccination History</h3>
                <div class="mt-4 overflow-x-auto">
                  <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                      <tr>
                        <th
                          class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                          scope="col">Vaccine</th>
                        <th
                          class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                          scope="col">Date Administered</th>
                        <th
                          class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                          scope="col">Administered At</th>
                        <th
                          class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                          scope="col">Next Dose Due</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                      <?php if (!empty($records)): ?>
                        <?php foreach ($records as $r): ?>
                          <tr>
                            <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900 dark:text-white">
                              <?= htmlspecialchars($r['vaccine_name']) ?></td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">
                              <?= htmlspecialchars(date('F j, Y', strtotime($r['date_given']))) ?></td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">Community
                              Clinic</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-300">N/A</td>
                          </tr>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <tr>
                          <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-300" colspan="4">No vaccination
                            records found.</td>
                        </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
      </main>
    </div>
  </div>
  <div id="print-content" style="display: none;">
    <div class="print-section">
      <div class="print-header">
        <h1>Vaccination Record</h1>
        <p>Generated on: <?= date('F j, Y') ?></p>
      </div>

      <div class="patient-info">
        <h2>Patient Information</h2>
        <p><strong>Name:</strong> <?= htmlspecialchars($user['full_name']) ?></p>
        <p><strong>Date of Birth:</strong> <?= htmlspecialchars($profile['birth_date'] ?? 'N/A') ?></p>
        <p><strong>Patient ID:</strong> P-<?= htmlspecialchars($patient_id) ?></p>
        <p><strong>Age:</strong>
          <?php
          if (!empty($profile['birth_date'])) {
            $dob = new DateTime($profile['birth_date']);
            $age = $dob->diff(new DateTime());
            echo $age->y . ' years, ' . $age->m . ' months';
          }
          ?>
        </p>
      </div>

      <div class="vaccination-history">
        <h2>Vaccination History</h2>
        <?php if (!empty($records)): ?>
          <table class="print-table">
            <thead>
              <tr>
                <th>Vaccine</th>
                <th>Date Administered</th>
                <th>Administered At</th>
                <th>Next Dose Due</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($records as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['vaccine_name']) ?></td>
                  <td><?= htmlspecialchars(date('F j, Y', strtotime($r['date_given']))) ?></td>
                  <td>Community Clinic</td>
                  <td>N/A</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p>No vaccination records found.</p>
        <?php endif; ?>
      </div>

      <div class="upcoming-schedule">
        <h2>Upcoming Schedule</h2>
        <?php if (!empty($appointments) || !empty($dueVaccines)): ?>
          <table class="print-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Details</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($appointments as $a): ?>
                <tr>
                  <td>Appointment</td>
                  <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($a['scheduled_at']))) ?></td>
                  <td>Scheduled</td>
                </tr>
              <?php endforeach; ?>
              <?php foreach ($dueVaccines as $vname): ?>
                <tr>
                  <td>Vaccine Due</td>
                  <td><?= htmlspecialchars($vname) ?></td>
                  <td>To be Scheduled</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p>No upcoming schedule items.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    function printVaccinationRecord() {
      // Create a new window for printing
      const printWindow = window.open('', '_blank');

      // Get the content to print
      const printContent = document.getElementById('print-content').innerHTML;

      // Write the content to the new window
      printWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Vaccination Record</title>
      <style>
        @page {
          size: A4;
          margin: 1cm;
        }
        body {
          font-family: Arial, sans-serif;
          line-height: 1.6;
          color: #000;
          padding: 20px;
        }
        .print-header {
          text-align: center;
          margin-bottom: 20px;
          padding-bottom: 10px;
          border-bottom: 2px solid #000;
        }
        .print-header h1 {
          margin: 0 0 10px 0;
          font-size: 24px;
        }
        .patient-info {
          margin-bottom: 20px;
          padding: 15px;
          background: #f5f5f5;
          border-radius: 4px;
        }
        .print-table {
          width: 100%;
          border-collapse: collapse;
          margin: 15px 0;
          font-size: 14px;
        }
        .print-table th, .print-table td {
          border: 1px solid #ddd;
          padding: 8px;
          text-align: left;
        }
        .print-table th {
          background-color: #f2f2f2;
          font-weight: bold;
        }
        h2 {
          font-size: 18px;
          margin: 25px 0 10px 0;
          color: #000;
          border-bottom: 1px solid #ddd;
          padding-bottom: 5px;
        }
        p {
          margin: 5px 0;
        }
        @media print {
          body {
            padding: 0;
          }
        }
      </style>
    </head>
    <body>
      ${printContent}
    </body>
    </html>
  `);

      printWindow.document.close();

      // Wait for content to load before printing
      printWindow.onload = function () {
        setTimeout(function () {
          printWindow.print();
          printWindow.close();
        }, 250);
      };
    }
  </script>
</body>

</html>