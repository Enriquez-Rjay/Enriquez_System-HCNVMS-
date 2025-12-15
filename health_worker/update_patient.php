<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/db.php";

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Please log in']);
    exit();
}

// Check if user has the correct role (health_worker or admin can update patients)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden - Only health workers and admins can update patients']);
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
    // Account is inactive, destroy session and return error
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Account has been deactivated']);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get and validate input
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data: ' . json_last_error_msg()]);
    exit();
}

$patientId = intval($data['patientId'] ?? 0);
$userId = intval($data['userId'] ?? 0);
$firstName = trim($data['firstName'] ?? '');
$lastName = trim($data['lastName'] ?? '');
$birthDate = $data['birthDate'] ?? '';
$gender = $data['gender'] ?? '';
$address = trim($data['address'] ?? '');
$parentName = trim($data['parentName'] ?? '');
$contactNumber = trim($data['contactNumber'] ?? '');
$email = trim($data['email'] ?? '');
$relationship = trim($data['relationship'] ?? '');
$parentConcern = trim($data['parentConcern'] ?? '');

// Basic validation
if (empty($patientId) || empty($userId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Patient ID and User ID are required']);
    exit();
}

if (empty($firstName) || empty($lastName) || empty($birthDate) || empty($gender) || empty($email) || empty($parentName) || empty($relationship)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please fill in all required fields']);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']);
    exit();
}

// Validate birth date is not in the future (but no age limit for editing)
try {
    $birthDateTime = new DateTime($birthDate);
    $today = new DateTime();
    
    if ($birthDateTime > $today) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Birth date cannot be in the future']);
        exit();
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid birth date format']);
    exit();
}

try {
    // Start transaction
    $mysqli->begin_transaction();

    // Verify that the patient exists and belongs to the user_id
    $checkStmt = $mysqli->prepare("SELECT id FROM patient_profiles WHERE id = ? AND user_id = ? LIMIT 1");
    $checkStmt->bind_param('ii', $patientId, $userId);
    $checkStmt->execute();
    $patientExists = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();
    
    if (!$patientExists) {
        $mysqli->rollback();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Patient not found']);
        exit();
    }

    // Check if email is being changed and if the new email already exists for another user
    $checkStmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $checkStmt->bind_param('si', $email, $userId);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        $checkStmt->close();
        $mysqli->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This email address is already registered to another user']);
        exit();
    }
    $checkStmt->close();

    // Update users table
    $fullName = "$firstName $lastName";
    $stmt = $mysqli->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
    $stmt->bind_param('sssi', $fullName, $email, $contactNumber, $userId);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update user information: ' . $stmt->error);
    }
    $stmt->close();

    // Update patient_profiles table
    $childName = "$firstName $lastName";
    
    // Format guardian_name with relationship: "Parent Name (Relationship)"
    $guardianNameWithRelationship = $parentName;
    if (!empty($relationship)) {
        $guardianNameWithRelationship .= " ($relationship)";
    }
    
    // Append gender and parent concern to address if provided
    $fullAddress = $address;
    $addressParts = [];
    
    // Add gender if provided
    if (!empty($gender)) {
        $addressParts[] = "Gender: " . $gender;
    }
    
    // Add parent concern if provided
    if (!empty($parentConcern)) {
        $addressParts[] = "Parent Concern: " . $parentConcern;
    }
    
    // Combine address with additional info
    if (!empty($addressParts)) {
        if (!empty($fullAddress)) {
            $fullAddress .= "\n\n" . implode("\n", $addressParts);
        } else {
            $fullAddress = implode("\n", $addressParts);
        }
    }
    
    $stmt = $mysqli->prepare("
        UPDATE patient_profiles 
        SET child_name = ?, birth_date = ?, guardian_name = ?, address = ?
        WHERE id = ? AND user_id = ?
    ");
    
    $stmt->bind_param(
        'ssssii',
        $childName,
        $birthDate,
        $guardianNameWithRelationship,
        $fullAddress,
        $patientId,
        $userId
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update patient profile: ' . $stmt->error);
    }
    $stmt->close();

    // Commit transaction
    $mysqli->commit();

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Patient information updated successfully!'
    ]);

} catch (Exception $e) {
    // Only rollback if transaction was started
    if ($mysqli->in_transaction) {
        $mysqli->rollback();
    }
    http_response_code(500);
    $errorMessage = 'Failed to update patient: ' . $e->getMessage();
    echo json_encode(['success' => false, 'error' => $errorMessage]);
    error_log('Error in update_patient.php: ' . $e->getMessage());
}
?>

