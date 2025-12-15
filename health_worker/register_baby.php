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

// Check if user has the correct role (health_worker or admin can register babies)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'health_worker' && $_SESSION['role'] !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden - Only health workers and admins can register babies']);
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

// Validate age - must be 0-12 months (newborns only)
try {
    $birthDateTime = new DateTime($birthDate);
    $today = new DateTime();
    $ageInMonths = ($today->diff($birthDateTime)->y * 12) + $today->diff($birthDateTime)->m;
    
    if ($birthDateTime > $today) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Birth date cannot be in the future']);
        exit();
    }
    
    if ($ageInMonths > 12) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only newborns (0-12 months) can be registered. This baby is ' . $ageInMonths . ' months old.']);
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

    $fullName = "$firstName $lastName";
    
    // Check if email already exists
    $checkStmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $checkStmt->bind_param('s', $email);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        $checkStmt->close();
        $mysqli->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This email address is already registered']);
        exit();
    }
    $checkStmt->close();
    
    // Generate simple username: first name (lowercase, no spaces) + last 2 digits of birth year
    // Example: "john" + "25" = "john25" (if born in 2025)
    $birthYear = date('y', strtotime($birthDate)); // Get last 2 digits of year
    $simpleFirstName = strtolower(preg_replace('/[^a-z]/', '', $firstName)); // Remove non-letters
    $username = $simpleFirstName . $birthYear;
    
    // If username exists, add a number
    $maxAttempts = 10;
    $attempt = 0;
    $baseUsername = $username;
    do {
        if ($attempt > 0) {
            $username = $baseUsername . $attempt;
        }
        $checkStmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $checkStmt->bind_param('s', $username);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        $attempt++;
    } while ($exists && $attempt < $maxAttempts);
    
    if ($exists) {
        $mysqli->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to generate unique username. Please try again.']);
        exit();
    }
    
    // Generate simple password: first name + birth year (4 digits) + "123"
    // Example: "john2025123"
    $birthYearFull = date('Y', strtotime($birthDate)); // Full year (4 digits)
    $simplePassword = strtolower($simpleFirstName) . $birthYearFull . '123';
    $defaultPassword = password_hash($simplePassword, PASSWORD_DEFAULT);

    // Insert into users table
    $stmt = $mysqli->prepare("
        INSERT INTO users (username, password_hash, full_name, email, phone, role, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'patient', 'active', NOW())
    ");

    $stmt->bind_param('sssss', $username, $defaultPassword, $fullName, $email, $contactNumber);
    $stmt->execute();
    $userId = $stmt->insert_id;
    $stmt->close();

    // Insert into patient_profiles table (using the existing table structure)
    // Include relationship in guardian_name and parentConcern in address if provided
    $stmt = $mysqli->prepare("
        INSERT INTO patient_profiles (
            user_id, child_name, birth_date, 
            guardian_name, address, created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $childName = "$firstName $lastName";
    
    // Format guardian_name with relationship: "Parent Name (Relationship)"
    $guardianNameWithRelationship = $parentName;
    if (!empty($relationship)) {
        $guardianNameWithRelationship .= " ($relationship)";
    }
    
    // Append gender and parent concern to address if provided (for storage until we add proper columns)
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
    
    $stmt->bind_param(
        'issss',
        $userId,
        $childName,
        $birthDate,
        $guardianNameWithRelationship,
        $fullAddress
    );
    $stmt->execute();
    $patientId = $stmt->insert_id;
    $stmt->close();

    // Commit transaction
    $mysqli->commit();

    // Return the new patient data
    $newPatient = [
        'id' => $userId,
        'username' => $username,
        'name' => $fullName,
        'child_name' => $childName,
        'birth_date' => $birthDate,
        'gender' => $gender,
        'email' => $email,
        'guardian_name' => $parentName,
        'contact_number' => $contactNumber,
        'address' => $address
    ];

    echo json_encode([
        'success' => true,
        'patient' => $newPatient,
        'credentials' => [
            'username' => $username,
            'password' => $simplePassword, // Simple password: firstname + birthyear + 123
            'email' => $email
        ],
        'message' => 'Baby registered successfully!'
    ]);

} catch (Exception $e) {
    // Only rollback if transaction was started
    if ($mysqli->in_transaction) {
        $mysqli->rollback();
    }
    http_response_code(500);
    $errorMessage = 'Failed to register baby: ' . $e->getMessage();
    echo json_encode(['success' => false, 'error' => $errorMessage]);
    error_log('Error in register_baby.php: ' . $e->getMessage());
}
?>