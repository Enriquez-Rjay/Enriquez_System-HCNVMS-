<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Ensure user is logged in and a patient
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

$user_id = (int) $_SESSION['user_id'];
$errors = [];
$success = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $errors[] = 'All password fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $errors[] = 'New password and confirm password do not match.';
    } elseif (strlen($new_password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } else {
        // Verify current password
        $stmt = $mysqli->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($current_password, $user['password'])) {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $hashed_password, $user_id);

            if ($stmt->execute()) {
                $success = 'Password updated successfully.';
            } else {
                $errors[] = 'Failed to update password. Please try again.';
            }
        } else {
            $errors[] = 'Current password is incorrect.';
        }
    }
}

// Handle form submission: update users and patient_profiles
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get all form data
    $full_name = trim($_POST['full-name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $guardian_name = trim($_POST['guardian_name'] ?? '');
    $child_name = trim($_POST['child_name'] ?? '');

    // Validate required fields
    if (empty($full_name)) {
        $errors[] = 'Full name is required.';
    }
    if (empty($email)) {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        // Start transaction
        $mysqli->begin_transaction();

        try {
            // Update users table
            $stmt = $mysqli->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');
            if (!$stmt)
                throw new Exception('Failed to prepare user update');

            $stmt->bind_param('sssi', $full_name, $email, $phone, $user_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to update user information');
            }
            $stmt->close();

            // Check if patient profile exists
            $stmt = $mysqli->prepare('SELECT id FROM patient_profiles WHERE user_id = ? LIMIT 1');
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $profileExists = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($profileExists) {
                // Update existing profile
                $stmt = $mysqli->prepare('UPDATE patient_profiles SET address = ?, birth_date = ?, guardian_name = ?, child_name = ? WHERE user_id = ?');
                if (!$stmt)
                    throw new Exception('Failed to prepare profile update');
                $stmt->bind_param('ssssi', $address, $birth_date, $guardian_name, $child_name, $user_id);
            } else {
                // Insert new profile
                $stmt = $mysqli->prepare('INSERT INTO patient_profiles (user_id, address, birth_date, guardian_name, child_name) VALUES (?, ?, ?, ?, ?)');
                if (!$stmt)
                    throw new Exception('Failed to prepare profile insert');
                $stmt->bind_param('issss', $user_id, $address, $birth_date, $guardian_name, $child_name);
            }

            if (!$stmt->execute()) {
                throw new Exception('Failed to update profile information');
            }
            $stmt->close();

            // Commit transaction
            $mysqli->commit();

            // Update session data
            $_SESSION['full_name'] = $full_name;
            $success = 'Your changes have been saved successfully!';
        } catch (Exception $e) {
            $mysqli->rollback();
            $errors[] = 'An error occurred while saving your changes. Please try again.';
            error_log('Error updating profile: ' . $e->getMessage());
        }
    }
}

// Load current user info with profile data
$user = [
    'full_name' => $_SESSION['full_name'] ?? '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'birth_date' => '',
    'guardian_name' => '',
    'child_name' => ''
];

// Load user data with left join to get profile info
$stmt = $mysqli->prepare('
    SELECT u.full_name, u.email, u.phone,
           p.address, p.birth_date, p.guardian_name, p.child_name
    FROM users u
    LEFT JOIN patient_profiles p ON u.id = p.user_id
    WHERE u.id = ?
    LIMIT 1
');

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user = array_merge($user, $row);
    }
    $stmt->close();
}

// For backward compatibility with the rest of the code
$profile = [
    'address' => $user['address'] ?? '',
    'birth_date' => $user['birth_date'] ?? '',
    'guardian_name' => $user['guardian_name'] ?? '',
    'child_name' => $user['child_name'] ?? ''
];
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Settings - HCNVMS</title>
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
</head>

<body class="font-display bg-background-light dark:bg-background-dark text-[#111418] dark:text-gray-200">
    <div class="flex min-h-screen bg-white dark:bg-background-dark">
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
            </div>
            <div class="mt-auto flex flex-col gap-1">
                <a class="flex items-center gap-3 px-3 py-2 rounded-lg bg-primary/10 text-primary dark:bg-primary/20"
                    href="/HealthCenter/patient/settings.php">
                    <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">settings</span>
                    <p class="text-sm font-medium">Settings</p>
                </a>
                <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
                    href="/HealthCenter/auth/logout.php">
                    <span class="material-symbols-outlined">logout</span>
                    <p class="text-sm font-medium">Logout</p>
                </a>
            </div>
        </aside>

        <div class="p-8">
            <div class="mx-auto max-w-5xl">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <p
                        class="text-[#111418] dark:text-white text-4xl font-black leading-tight tracking-[-0.033em] min-w-72">
                        Settings</p>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="mt-4 rounded-md bg-success/10 border border-success/30 p-3 text-success text-sm">
                        <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="mt-4 rounded-md bg-danger/10 border border-danger/30 p-3 text-danger text-sm">
                        <ul class="list-disc pl-5">
                            <?php foreach ($errors as $e): ?>
                                <li><?= htmlspecialchars($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-3">
                    <div class="lg:col-span-1">
                        <h2 class="text-lg font-semibold text-[#111418] dark:text-white">Personal Information</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Update your contact details here. This
                            information is private and will not be shared.</p>
                    </div>
                    <div class="lg:col-span-2">
                        <div
                            class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                            <form method="post" action="" class="grid grid-cols-1 gap-6 sm:grid-cols-2"
                                id="profileForm">
                                <!-- Personal Information -->
                                <div class="sm:col-span-2">
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Personal
                                        Information</h3>
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        for="full-name">Full Name <span class="text-red-500">*</span></label>
                                    <input required
                                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 sm:text-sm"
                                        id="full-name" name="full-name" type="text"
                                        value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        for="email">Email Address <span class="text-red-500">*</span></label>
                                    <input required
                                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 sm:text-sm"
                                        id="email" name="email" type="email"
                                        value="<?= htmlspecialchars($user['email'] ?? '') ?>" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        for="phone">Phone Number</label>
                                    <input
                                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 sm:text-sm"
                                        id="phone" name="phone" type="tel"
                                        value="<?= htmlspecialchars($user['phone'] ?? '') ?>" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        for="birth_date">Date of Birth</label>
                                    <input
                                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 sm:text-sm"
                                        id="birth_date" name="birth_date" type="date"
                                        value="<?= htmlspecialchars($profile['birth_date'] ?? '') ?>" />
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        for="address">Home Address</label>
                                    <input
                                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 sm:text-sm"
                                        id="address" name="address" type="text"
                                        value="<?= htmlspecialchars($profile['address'] ?? '') ?>" />
                                </div>

                                <!-- Child Information -->
                                <div class="sm:col-span-2 mt-6">
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Child Information
                                    </h3>
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Patient's
                                        Name</label>
                                    <div
                                        class="mt-1 block w-full rounded-lg bg-gray-100 px-3 py-2 text-gray-900 dark:bg-gray-800 dark:text-gray-200 sm:text-sm">
                                        <?= htmlspecialchars($user['full_name'] ?? '') ?>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">This is your registered
                                        name as the patient.</p>
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        for="guardian_name">Guardian's Name <span class="text-red-500">*</span></label>
                                    <input required
                                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 sm:text-sm"
                                        id="guardian_name" name="guardian_name" type="text"
                                        value="<?= htmlspecialchars($profile['guardian_name'] ?? '') ?>" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Name of parent or legal
                                        guardian (required for child patients)</p>
                                </div>
                                <!-- Password Section -->
                                <div class="sm:col-span-2 mt-8">
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Password</h3>
                                    <div id="passwordDisplay" class="cursor-pointer" onclick="showPasswordForm()">
                                        <div
                                            class="flex items-center justify-between p-4 border border-gray-200 rounded-lg dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                            <div>
                                                <span
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300">Password</span>
                                                <span class="text-sm text-gray-500 dark:text-gray-400">•••••••••</span>
                                            </div>
                                            <span class="text-primary text-sm font-medium flex items-center gap-1">
                                                <span class="material-symbols-outlined text-base">edit</span>
                                                Change
                                            </span>
                                        </div>
                                    </div>

                                    <div id="passwordForm"
                                        class="hidden mt-4 space-y-4 p-4 border border-gray-200 rounded-lg dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                                        <div>
                                            <label
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                                                for="current_password">Current Password <span
                                                    class="text-red-500">*</span></label>
                                            <div class="relative">
                                                <input type="password" id="current_password" name="current_password"
                                                    class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 sm:text-sm"
                                                    required>
                                                <button type="button"
                                                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                                    onclick="togglePassword('current_password')">
                                                    <span class="material-symbols-outlined text-base">visibility</span>
                                                </button>
                                            </div>
                                        </div>

                                        <div>
                                            <label
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                                                for="new_password">New Password <span
                                                    class="text-red-500">*</span></label>
                                            <div class="relative">
                                                <input type="password" id="new_password" name="new_password"
                                                    class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 sm:text-sm"
                                                    required>
                                                <button type="button"
                                                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                                    onclick="togglePassword('new_password')">
                                                    <span class="material-symbols-outlined text-base">visibility</span>
                                                </button>
                                            </div>
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Password must be at
                                                least 8 characters long</p>
                                        </div>

                                        <div>
                                            <label
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                                                for="confirm_password">Confirm New Password <span
                                                    class="text-red-500">*</span></label>
                                            <div class="relative">
                                                <input type="password" id="confirm_password" name="confirm_password"
                                                    class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 sm:text-sm"
                                                    required>
                                                <button type="button"
                                                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                                    onclick="togglePassword('confirm_password')">
                                                    <span class="material-symbols-outlined text-base">visibility</span>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="flex justify-end gap-3 pt-2">
                                            <button type="button" onclick="hidePasswordForm()"
                                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200">
                                                Cancel
                                            </button>
                                            <button type="submit" name="change_password"
                                                class="px-4 py-2 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-base">lock_reset</span>
                                                Update Password
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-6 dark:border-gray-800">
                                    <a href="/HealthCenter/patient/p_dashboard.php"
                                        class="flex max-w-[480px] cursor-pointer items-center justify-center overflow-hidden rounded-lg h-10 border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-[#111418] dark:text-gray-200 gap-2 text-sm font-medium leading-normal px-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        Cancel
                                    </a>
                                    <button type="submit" name="save_changes"
                                        class="flex max-w-[480px] cursor-pointer items-center justify-center overflow-hidden rounded-lg h-10 bg-primary text-white gap-2 text-sm font-medium leading-normal px-4 hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                        id="saveButton">
                                        <span class="material-symbols-outlined text-base">save</span>
                                        Save Changes
                                    </button>
                                </div>
                            </form>

                            <script>
                                // Toggle password visibility
                                function togglePassword(inputId) {
                                    const input = document.getElementById(inputId);
                                    const icon = input.nextElementSibling.querySelector('span');
                                    if (input.type === 'password') {
                                        input.type = 'text';
                                        icon.textContent = 'visibility_off';
                                    } else {
                                        input.type = 'password';
                                        icon.textContent = 'visibility';
                                    }
                                }

                                // Show password form
                                function showPasswordForm() {
                                    document.getElementById('passwordDisplay').classList.add('hidden');
                                    document.getElementById('passwordForm').classList.remove('hidden');
                                    document.getElementById('current_password').focus();
                                }

                                // Hide password form
                                function hidePasswordForm() {
                                    document.getElementById('passwordForm').classList.add('hidden');
                                    document.getElementById('passwordDisplay').classList.remove('hidden');
                                    // Reset form
                                    document.querySelectorAll('#passwordForm input').forEach(input => {
                                        input.value = '';
                                        input.type = 'password';
                                    });
                                }

                                // Handle form submission
                                document.getElementById('profileForm').addEventListener('submit', function (e) {
                                    const saveButton = e.submitter;

                                    // Handle profile update submission
                                    if (saveButton.name === 'save_changes') {
                                        saveButton.disabled = true;
                                        saveButton.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Saving...
        `;
                                    }

                                    // Handle password change submission
                                    if (saveButton.name === 'change_password') {
                                        e.preventDefault();

                                        const currentPassword = document.getElementById('current_password').value;
                                        const newPassword = document.getElementById('new_password').value;
                                        const confirmPassword = document.getElementById('confirm_password').value;

                                        // Basic validation
                                        if (!currentPassword || !newPassword || !confirmPassword) {
                                            alert('Please fill in all password fields');
                                            return false;
                                        }

                                        if (newPassword.length < 8) {
                                            alert('Password must be at least 8 characters long');
                                            return false;
                                        }

                                        if (newPassword !== confirmPassword) {
                                            alert('New password and confirm password do not match');
                                            return false;
                                        }

                                        // If validation passes, submit the form
                                        saveButton.disabled = true;
                                        saveButton.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Updating...
        `;

                                        // Submit the form
                                        this.submit();
                                    }
                                });
                            </script>
                        </div>
                    </div>
                </div>
            </div>
            </main>
        </div>
    </div>

</body>

</html>