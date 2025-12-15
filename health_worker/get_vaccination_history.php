<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in and is a health worker
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get patient_id and vaccine_id from query parameters
$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$vaccine_id = isset($_GET['vaccine_id']) ? (int) $_GET['vaccine_id'] : 0;

if ($patient_id <= 0 || $vaccine_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid patient_id or vaccine_id']);
    exit();
}

try {
    // Get vaccine name
    $stmt = $mysqli->prepare("SELECT name FROM vaccines WHERE id = ?");
    $stmt->bind_param('i', $vaccine_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vaccine = $result->fetch_assoc();
    $stmt->close();
    
    if (!$vaccine) {
        throw new Exception('Vaccine not found');
    }
    
    // Get patient's vaccination history for this specific vaccine
    // Check both vaccination_records (completed) and appointments (scheduled)
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
    $stmt->bind_param('iiii', $patient_id, $vaccine_id, $patient_id, $vaccine_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    $maxDose = 0;
    $dosageTexts = [];
    
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
        
        // Determine dose number from dosage text or dose field
        if ($row['dosage_text']) {
            // Parse dosage text like "1st Dose", "2nd Dose", etc.
            if (preg_match('/(\d+)(st|nd|rd|th)\s+Dose/i', $row['dosage_text'], $matches)) {
                $doseNum = (int) $matches[1];
                $maxDose = max($maxDose, $doseNum);
                $dosageTexts[] = $row['dosage_text'];
            } elseif (stripos($row['dosage_text'], 'booster') !== false) {
                $maxDose = max($maxDose, 4); // Booster is typically after 3rd dose
                $dosageTexts[] = $row['dosage_text'];
            } elseif (stripos($row['dosage_text'], 'additional') !== false) {
                $maxDose = max($maxDose, 5); // Additional dose is after booster
                $dosageTexts[] = $row['dosage_text'];
            }
        } elseif ($row['dose']) {
            $maxDose = max($maxDose, (int) $row['dose']);
        }
    }
    $stmt->close();
    
    // Determine next recommended dosage
    $nextDosage = '';
    if ($maxDose === 0) {
        $nextDosage = '1st Dose';
    } elseif ($maxDose === 1) {
        $nextDosage = '2nd Dose';
    } elseif ($maxDose === 2) {
        $nextDosage = '3rd Dose';
    } elseif ($maxDose >= 3) {
        // Check if booster and additional dose are already given
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
            // All doses given, suggest additional dose anyway
            $nextDosage = 'Additional Dose';
        }
    }
    
    echo json_encode([
        'success' => true,
        'vaccine_name' => $vaccine['name'],
        'history' => $history,
        'max_dose' => $maxDose,
        'next_dosage' => $nextDosage,
        'has_history' => count($history) > 0
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

