<?php
// Start session for user authentication
session_start();

// Database configuration
$host = '127.0.0.1';
$dbname = 'healthcenter';
$username = 'root';
$password = '';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Check if logged-in user's account is still active
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userStatus = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userStatus || strtolower($userStatus['status'] ?? '') !== 'active') {
        // Account is inactive, destroy session and redirect
        session_destroy();
        header('Location: /HealthCenter/login.php?error=account_inactive');
        exit();
    }
} catch (Exception $e) {
    // If database check fails, still allow access but log error
    error_log("Account status check failed: " . $e->getMessage());
}

// Initialize variables
$message = '';
$userData = [
    'full_name' => '',
    'email' => '',
    'phone' => ''
];

// Fetch user data
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if notification columns exist, if not create them
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'email_notifications'");
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE users ADD COLUMN sms_notifications TINYINT(1) DEFAULT 0");
    }
    
    $stmt = $pdo->prepare("SELECT full_name, email, phone, email_notifications, sms_notifications FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        $userData = [
            'full_name' => 'Health Worker',
            'email' => '',
            'phone' => '',
            'email_notifications' => 0,
            'sms_notifications' => 0
        ];
    }
} catch (Exception $e) {
    $message = "Database error: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update profile
    if (isset($_POST['update_profile'])) {
        try {
            $full_name = filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_STRING);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
            
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->execute([$full_name, $email, $phone, $_SESSION['user_id']]);
            
            $message = "Profile updated successfully!";
            $userData = ['full_name' => $full_name, 'email' => $email, 'phone' => $phone];
            
        } catch (Exception $e) {
            $message = "Error updating profile: " . $e->getMessage();
        }
    }
    
    // Update notifications
    if (isset($_POST['update_notifications'])) {
        try {
            $email_notifications = isset($_POST['email_notifications']) && $_POST['email_notifications'] == '1' ? 1 : 0;
            $sms_notifications = isset($_POST['sms_notifications']) && $_POST['sms_notifications'] == '1' ? 1 : 0;
            
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare("UPDATE users SET email_notifications = ?, sms_notifications = ? WHERE id = ?");
            $stmt->execute([$email_notifications, $sms_notifications, $_SESSION['user_id']]);
            
            $message = "Notification preferences updated successfully!";
            $userData['email_notifications'] = $email_notifications;
            $userData['sms_notifications'] = $sms_notifications;
            
        } catch (Exception $e) {
            $message = "Error updating notification preferences: " . $e->getMessage();
        }
    }
    
    // Change password
    if (isset($_POST['change_password'])) {
        try {
            $current_password = isset($_POST['current_password']) ? $_POST['current_password'] : '';
            $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
            $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                throw new Exception("All password fields are required!");
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match!");
            }
            
            if (strlen($new_password) < 6) {
                throw new Exception("Password must be at least 6 characters long!");
            }
            
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get current password hash
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $result = $stmt->fetch();
            
            if (!$result || !password_verify($current_password, $result['password_hash'])) {
                throw new Exception("Current password is incorrect!");
            }
            
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $_SESSION['user_id']]);
            
            $message = "Password updated successfully!";
            
        } catch (Exception $e) {
            $message = "Error changing password: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<!-- [Previous head content remains the same until the form] -->
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>HCNVMS Settings</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <style>
        .material-symbols-outlined {
            font-variation-settings:
                'FILL' 0,
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
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" href="hw_dashboard.php">
                        <span class="material-symbols-outlined">dashboard</span>
                        <p class="text-sm font-medium leading-normal">Dashboard</p>
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" href="hw_patient.php">
                        <span class="material-symbols-outlined">groups</span>
                        <p class="text-sm font-medium leading-normal">Patients</p>
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" href="hw_schedule.php">
                        <span class="material-symbols-outlined">calendar_month</span>
                        <p class="text-sm font-medium leading-normal">Schedule</p>
                    </a>
                    <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" href="hw_reports.php">
                        <span class="material-symbols-outlined">monitoring</span>
                        <p class="text-sm font-medium leading-normal">Reports</p>
                    </a>
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <a class="flex items-center gap-3 rounded-lg bg-primary/10 dark:bg-primary/20 px-3 py-2 text-primary" href="#">
                            <span class="material-symbols-outlined">settings</span>
                            <p class="text-sm font-medium leading-normal">Settings</p>
                        </a>
                        <a class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" href="../auth/logout.php">
                            <span class="material-symbols-outlined">logout</span>
                            <p class="text-sm font-medium leading-normal">Logout</p>
                        </a>
                    </div>
                    <div class="border-t border-slate-200 dark:border-slate-800 pt-4">
                        <div class="flex items-center gap-2">
                            <div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-10" style="background-image: none;"></div>
                            <div class="flex flex-col">
                                <h1 class="text-slate-900 dark:text-white text-sm font-medium leading-normal"><?php echo htmlspecialchars($userData['full_name']); ?></h1>
                                <p class="text-slate-500 dark:text-slate-400 text-xs font-normal leading-normal">Health Worker</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
        <main class="flex-1 overflow-y-auto">
            <header class="sticky top-0 z-10 flex h-16 items-center justify-end border-b border-solid border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-background-dark/80 backdrop-blur-sm px-6">
                <div class="flex items-center gap-4">
                    <button class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-transparent text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
                        <span class="material-symbols-outlined">notifications</span>
                    </button>
                    <div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-10" style="background-image: none;"></div>
                </div>
            </header>
            <div class="p-6 md:p-8">
                <div class="flex flex-col gap-1 mb-8">
                    <p class="text-2xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white lg:text-3xl">Settings</p>
                    <p class="text-slate-600 dark:text-slate-400 text-base font-normal leading-normal">Manage your profile, notifications, and password.</p>
                </div>
                
                <?php if ($message): ?>
                <div class="mb-4 p-4 rounded-lg font-medium <?php echo (strpos($message, 'Error') !== false || strpos($message, 'incorrect') !== false) ? 'bg-red-100 text-red-700 border border-red-300' : 'bg-green-100 text-green-700 border border-green-300'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>
                
                <div class="space-y-8">
                    <!-- Personal Information -->
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="border-b border-slate-200 p-4 dark:border-slate-800 sm:p-6">
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Personal Information</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Update your personal details here.</p>
                        </div>
                        <div class="p-4 sm:p-6">
                            <form class="grid grid-cols-1 gap-6 md:grid-cols-2" method="POST" action="">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2" for="full-name">Full Name</label>
                                    <input class="form-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-colors" 
                                           id="full-name" 
                                           name="full_name" 
                                           type="text" 
                                           value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>" 
                                           required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2" for="email">Email Address</label>
                                    <input class="form-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-colors" 
                                           id="email" 
                                           name="email" 
                                           type="email" 
                                           value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>" 
                                           required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2" for="phone">Phone Number</label>
                                    <input class="form-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-colors" 
                                           id="phone" 
                                           name="phone" 
                                           type="tel" 
                                           value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>" />
                                </div>
                                <div class="flex items-end">
                                    <button type="submit" name="update_profile" class="h-10 px-6 bg-primary text-white rounded-lg hover:bg-primary/90 font-medium transition-colors">
                                        Update Profile
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Notification Preferences -->
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="border-b border-slate-200 p-4 dark:border-slate-800 sm:p-6">
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Notification Preferences</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Manage how you receive patient reminders.</p>
                        </div>
                        <form method="POST" action="">
                            <div class="p-4 sm:p-6 flex items-center justify-between border-b border-slate-200 dark:border-slate-800">
                                <div>
                                    <p class="font-medium text-slate-800 dark:text-slate-200">Email Notifications</p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">Receive reminders for upcoming appointments via email.</p>
                                </div>
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input class="w-5 h-5 rounded cursor-pointer" 
                                           id="email-toggle" 
                                           name="email_notifications" 
                                           type="checkbox" 
                                           value="1" 
                                           <?php echo (isset($userData['email_notifications']) && $userData['email_notifications']) ? 'checked' : ''; ?> />
                                    <span class="sr-only">Email Notifications</span>
                                </label>
                            </div>
                            <div class="p-4 sm:p-6 flex items-center justify-between border-b border-slate-200 dark:border-slate-800">
                                <div>
                                    <p class="font-medium text-slate-800 dark:text-slate-200">SMS Notifications</p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">Receive reminders for missed appointments via SMS.</p>
                                </div>
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input class="w-5 h-5 rounded cursor-pointer" 
                                           id="sms-toggle" 
                                           name="sms_notifications" 
                                           type="checkbox" 
                                           value="1" 
                                           <?php echo (isset($userData['sms_notifications']) && $userData['sms_notifications']) ? 'checked' : ''; ?> />
                                    <span class="sr-only">SMS Notifications</span>
                                </label>
                            </div>
                            <div class="p-4 sm:p-6">
                                <button type="submit" name="update_notifications" class="h-10 px-4 bg-primary text-white rounded-lg hover:bg-primary/90 font-medium">
                                    Save Notification Preferences
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="border-b border-slate-200 p-4 dark:border-slate-800 sm:p-6">
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Change Password</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">For security, choose a strong, unique password.</p>
                        </div>
                        <form method="POST" action="" class="p-4 sm:p-6">
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2" for="current-password">Current Password</label>
                                    <input class="form-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-colors" 
                                           id="current-password" 
                                           name="current_password" 
                                           type="password" 
                                           placeholder="Enter current password"
                                           required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2" for="new-password">New Password</label>
                                    <input class="form-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-colors" 
                                           id="new-password" 
                                           name="new_password" 
                                           type="password" 
                                           placeholder="Enter new password (min 6 characters)"
                                           required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2" for="confirm-password">Confirm New Password</label>
                                    <input class="form-input w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-colors" 
                                           id="confirm-password" 
                                           name="confirm_password" 
                                           type="password" 
                                           placeholder="Confirm new password"
                                           required />
                                </div>
                                <div class="flex items-end">
                                    <button type="submit" name="change_password" class="h-10 px-6 bg-primary text-white rounded-lg hover:bg-primary/90 font-medium transition-colors">
                                        Change Password
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    // Auto-hide success/error message after 5 seconds
    setTimeout(() => {
        const message = document.querySelector('[class*="bg-green-100"], [class*="bg-red-100"]');
        if (message) {
            message.style.transition = 'opacity 0.5s ease-in-out';
            message.style.opacity = '0';
            setTimeout(() => {
                message.style.display = 'none';
            }, 500);
        }
    }, 5000);
    </script>
</body>
</html>
