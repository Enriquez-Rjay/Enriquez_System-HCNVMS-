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

// Certificate issue date - Set at the moment this certificate page is accessed/clicked
// This ensures the date reflects when the user actually views/prints the certificate
$certificateIssueDate = date('F j, Y');
$certificateIssueDateTime = date('F j, Y \a\t g:i A');

// Fetch user info
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

// Fetch patient profile
$profile = null;
$stmt = $mysqli->prepare('SELECT child_name, birth_date, guardian_name, address FROM patient_profiles WHERE user_id = ? LIMIT 1');
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $profile = $res->fetch_assoc() ?: null;
  $stmt->close();
}

// Get vaccination records with dose information - Always fetch latest data from database
$records = [];
$stmt = $mysqli->prepare('
  SELECT v.name AS vaccine_name, vr.date_given, vr.dose, vr.created_at 
  FROM vaccination_records vr 
  JOIN vaccines v ON v.id = vr.vaccine_id 
  WHERE vr.patient_id = ? 
  ORDER BY vr.date_given ASC, vr.dose ASC
');
if ($stmt) {
  $stmt->bind_param('i', $patient_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $records[] = $r;
  }
  $stmt->close();
}

// Calculate total vaccines received - Always recalculate from current records
$totalVaccines = count($records);
$uniqueVaccines = [];
foreach ($records as $record) {
  if (!in_array($record['vaccine_name'], $uniqueVaccines)) {
    $uniqueVaccines[] = $record['vaccine_name'];
  }
}
$uniqueVaccineCount = count($uniqueVaccines);

// Certificate issue date - Always use current date/time when certificate is accessed
$certificateIssueDate = date('F j, Y');
$certificateIssueDateTime = date('F j, Y \a\t g:i A');

// Parse guardian name and address for display
$guardianName = '';
$relationship = '';
$gender = '';
$actualAddress = '';

if ($profile && !empty($profile['guardian_name'])) {
  // Format: "Parent Name (Relationship)"
  if (preg_match('/^(.+?)\s*\((.+?)\)$/', $profile['guardian_name'], $matches)) {
    $guardianName = trim($matches[1]);
    $relationship = trim($matches[2]);
  } else {
    $guardianName = $profile['guardian_name'];
  }
}

if ($profile && !empty($profile['address'])) {
  // Format: "Address\n\nGender: [Gender]\nParent Concern: [Concern]"
  $addressParts = explode("\n\n", $profile['address']);
  $actualAddress = trim($addressParts[0] ?? '');
  
  if (isset($addressParts[1])) {
    if (preg_match('/Gender:\s*(.+)/i', $addressParts[1], $genderMatch)) {
      $gender = trim($genderMatch[1]);
    }
  }
}

// Get child name or use user's full name
$childName = !empty($profile['child_name']) ? $profile['child_name'] : $user['full_name'];
$birthDate = $profile['birth_date'] ?? '';

// Certificate issue date - Always use current date/time when certificate is accessed/generated
// This ensures the date reflects when the certificate is actually released/printed
$certificateIssueDate = date('F j, Y');
$certificateIssueDateTime = date('F j, Y \a\t g:i A');

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Digital Vaccination Certificate - <?= htmlspecialchars($user['full_name']) ?></title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Georgia', 'Times New Roman', serif;
      background: #f5f5f5;
      padding: 20px;
      color: #333;
    }

    .certificate-container {
      max-width: 900px;
      margin: 0 auto;
      background: white;
      box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }

    .no-print {
      background: white;
      padding: 20px;
      border-bottom: 2px solid #ddd;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 15px;
    }

    .no-print h1 {
      font-size: 24px;
      color: #2b8cee;
      margin: 0;
    }

    .btn-group {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn {
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
      transition: all 0.3s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
    }

    .btn-primary {
      background: #2b8cee;
      color: white;
    }

    .btn-primary:hover {
      background: #1e6bc7;
    }

    .btn-secondary {
      background: #6c757d;
      color: white;
    }

    .btn-secondary:hover {
      background: #5a6268;
    }

    .certificate {
      padding: 60px 80px;
      position: relative;
      min-height: 800px;
      background: linear-gradient(to bottom, #ffffff 0%, #f8f9fa 100%);
    }

    .watermark {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%) rotate(-45deg);
      font-size: 120px;
      font-weight: bold;
      color: rgba(43, 140, 238, 0.05);
      z-index: 0;
      white-space: nowrap;
    }

    .certificate-content {
      position: relative;
      z-index: 1;
    }

    .header {
      text-align: center;
      margin-bottom: 50px;
      padding-bottom: 30px;
      border-bottom: 3px solid #2b8cee;
    }

    .header h1 {
      font-size: 42px;
      font-weight: bold;
      color: #1a5490;
      margin-bottom: 10px;
      letter-spacing: 2px;
    }

    .header h2 {
      font-size: 24px;
      color: #2b8cee;
      font-weight: normal;
      margin-top: 10px;
    }

    .header .subtitle {
      font-size: 16px;
      color: #666;
      margin-top: 15px;
    }

    .certificate-body {
      margin: 40px 0;
    }

    .certificate-text {
      font-size: 18px;
      line-height: 1.8;
      text-align: center;
      margin-bottom: 30px;
    }

    .patient-name {
      font-size: 36px;
      font-weight: bold;
      color: #1a5490;
      text-align: center;
      margin: 30px 0;
      padding: 20px;
      border: 2px dashed #2b8cee;
      background: #f0f7ff;
    }

    .patient-info {
      background: #f8f9fa;
      padding: 25px;
      border-radius: 8px;
      margin: 30px 0;
      border-left: 4px solid #2b8cee;
    }

    .patient-info h3 {
      font-size: 20px;
      color: #1a5490;
      margin-bottom: 15px;
      border-bottom: 2px solid #2b8cee;
      padding-bottom: 10px;
    }

    .info-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px;
      margin-top: 15px;
    }

    .info-item {
      display: flex;
      flex-direction: column;
    }

    .info-label {
      font-size: 12px;
      color: #666;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 5px;
    }

    .info-value {
      font-size: 16px;
      color: #333;
      font-weight: 600;
    }

    .vaccination-table {
      width: 100%;
      border-collapse: collapse;
      margin: 30px 0;
      background: white;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .vaccination-table thead {
      background: #1a5490;
      color: white;
    }

    .vaccination-table th {
      padding: 15px;
      text-align: left;
      font-size: 14px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .vaccination-table td {
      padding: 12px 15px;
      border-bottom: 1px solid #e0e0e0;
      font-size: 15px;
    }

    .vaccination-table tbody tr:hover {
      background: #f8f9fa;
    }

    .vaccination-table tbody tr:last-child td {
      border-bottom: none;
    }

    .dose-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 600;
      background: #e3f2fd;
      color: #1976d2;
    }

    .summary {
      background: #e8f5e9;
      padding: 20px;
      border-radius: 8px;
      margin: 30px 0;
      border-left: 4px solid #4caf50;
    }

    .summary h3 {
      font-size: 18px;
      color: #2e7d32;
      margin-bottom: 10px;
    }

    .summary p {
      font-size: 16px;
      color: #1b5e20;
      margin: 5px 0;
    }

    .signature-section {
      margin-top: 60px;
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
    }

    .signature-box {
      text-align: center;
      flex: 1;
      max-width: 300px;
    }

    .signature-line {
      border-top: 2px solid #333;
      margin: 60px 0 10px 0;
      width: 100%;
    }

    .signature-label {
      font-size: 14px;
      color: #666;
      margin-top: 5px;
    }

    .footer {
      text-align: center;
      margin-top: 40px;
      padding-top: 20px;
      border-top: 2px solid #e0e0e0;
      font-size: 12px;
      color: #999;
    }

    .certificate-id {
      text-align: right;
      font-size: 12px;
      color: #999;
      margin-bottom: 20px;
    }

    @media print {
      body {
        background: white;
        padding: 0;
      }

      .no-print {
        display: none;
      }

      .certificate {
        padding: 40px 60px;
        box-shadow: none;
      }

      .certificate-container {
        box-shadow: none;
        max-width: 100%;
      }

      @page {
        size: A4;
        margin: 0;
      }
    }

    @media (max-width: 768px) {
      .certificate {
        padding: 30px 20px;
      }

      .header h1 {
        font-size: 32px;
      }

      .patient-name {
        font-size: 28px;
      }

      .info-grid {
        grid-template-columns: 1fr;
      }

      .signature-section {
        flex-direction: column;
        gap: 40px;
      }
    }
  </style>
</head>
<body>
  <div class="certificate-container">
    <!-- Action Buttons (Hidden when printing) -->
    <div class="no-print">
      <h1>Digital Vaccination Certificate</h1>
      <div class="btn-group">
        <button onclick="window.print()" class="btn btn-primary">
          <span>🖨️</span> Print Certificate
        </button>
        <a href="/HealthCenter/patient/p_dashboard.php" class="btn btn-secondary">
          ← Back to Dashboard
        </a>
      </div>
    </div>

    <!-- Certificate Content -->
    <div class="certificate">
      <div class="watermark">HCNVMS</div>
      
      <div class="certificate-content">
        <!-- Certificate ID -->
        <div class="certificate-id">
          Certificate ID: <?= strtoupper(substr(md5($patient_id . $user['full_name']), 0, 12)) ?>
        </div>

        <!-- Header -->
        <div class="header">
          <h1>CERTIFICATE OF VACCINATION</h1>
          <h2>Health Center Vaccination Management System</h2>
          <div class="subtitle">Official Digital Certificate</div>
        </div>

        <!-- Certificate Body -->
        <div class="certificate-body">
          <div class="certificate-text">
            This is to certify that
          </div>

          <div class="patient-name">
            <?= htmlspecialchars($childName) ?>
          </div>

          <div class="certificate-text">
            has been vaccinated according to the immunization schedule as detailed below.
          </div>

          <!-- Patient Information -->
          <div class="patient-info">
            <h3>Patient Information</h3>
            <div class="info-grid">
              <div class="info-item">
                <span class="info-label">Full Name</span>
                <span class="info-value"><?= htmlspecialchars($childName) ?></span>
              </div>
              <?php if (!empty($birthDate)): ?>
              <div class="info-item">
                <span class="info-label">Date of Birth</span>
                <span class="info-value"><?= htmlspecialchars(date('F j, Y', strtotime($birthDate))) ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($gender)): ?>
              <div class="info-item">
                <span class="info-label">Gender</span>
                <span class="info-value"><?= htmlspecialchars($gender) ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($guardianName)): ?>
              <div class="info-item">
                <span class="info-label"><?= htmlspecialchars($relationship ?: 'Guardian') ?></span>
                <span class="info-value"><?= htmlspecialchars($guardianName) ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($actualAddress)): ?>
              <div class="info-item" style="grid-column: 1 / -1;">
                <span class="info-label">Address</span>
                <span class="info-value"><?= htmlspecialchars($actualAddress) ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Vaccination Records -->
          <?php if (!empty($records)): ?>
            <table class="vaccination-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Vaccine Name</th>
                  <th>Dose</th>
                  <th>Date Administered</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($records as $index => $record): ?>
                  <tr>
                    <td><?= $index + 1 ?></td>
                    <td><strong><?= htmlspecialchars($record['vaccine_name']) ?></strong></td>
                    <td>
                      <span class="dose-badge">
                        <?= htmlspecialchars($record['dose'] ?? 'N/A') ?><?= is_numeric($record['dose']) ? (($record['dose'] == 1) ? 'st' : (($record['dose'] == 2) ? 'nd' : (($record['dose'] == 3) ? 'rd' : 'th'))) . ' Dose' : '' ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars(date('F j, Y', strtotime($record['date_given']))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <!-- Summary -->
            <div class="summary">
              <h3>Vaccination Summary</h3>
              <p><strong>Total Vaccines Received:</strong> <?= $totalVaccines ?> dose(s)</p>
              <p><strong>Unique Vaccines:</strong> <?= $uniqueVaccineCount ?> vaccine(s)</p>
              <p><strong>Certificate Issued:</strong> <?= $certificateIssueDate ?></p>
            </div>
          <?php else: ?>
            <div class="summary" style="background: #fff3cd; border-left-color: #ffc107;">
              <h3 style="color: #856404;">No Vaccination Records</h3>
              <p style="color: #856404;">This patient has not yet received any vaccinations.</p>
            </div>
          <?php endif; ?>
        </div>

        <!-- Signature Section -->
        <div class="signature-section">
          <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">Authorized Health Worker</div>
          </div>
          <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">Date: <?= $certificateIssueDate ?></div>
          </div>
        </div>

        <!-- Footer -->
        <div class="footer">
          <p>This is a digitally generated certificate from the Health Center Vaccination Management System.</p>
          <p>For verification, please contact your health center.</p>
          <p style="margin-top: 10px;"><strong>Certificate Generated:</strong> <?= $certificateIssueDateTime ?></p>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Handle print button click - immediately open print dialog
    document.addEventListener('DOMContentLoaded', function() {
      const printBtn = document.querySelector('.btn-primary');
      if (printBtn) {
        printBtn.addEventListener('click', function(e) {
          e.preventDefault();
          // Immediately trigger print dialog
          window.print();
        });
      }
    });

    // Also allow direct print via window.print() if accessed directly
    // Auto-print when page loads (optional - uncomment if needed)
    // window.onload = function() {
    //   setTimeout(function() {
    //     window.print();
    //   }, 500);
    // };
  </script>
</body>
</html>

