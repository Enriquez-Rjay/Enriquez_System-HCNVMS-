<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

// Ensure user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /HealthCenter/login.php');
    exit;
}

// Get admin details
$admin_id = $_SESSION['user_id'];
$admin = [];
$stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';

    // Update admin details
    $stmt = $mysqli->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
    $stmt->bind_param('sssi', $full_name, $email, $phone, $admin_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Profile updated successfully!";
        // Update session
        $_SESSION['full_name'] = $full_name;
        $_SESSION['email'] = $email;
        header('Location: profile.php');
        exit;
    } else {
        $error = "Error updating profile: " . $mysqli->error;
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Profile - HCNVMS</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800;900&display=swap"
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
                    <h2 class="text-text-light dark:text-text-dark text-xl font-bold leading-tight tracking-[-0.015em]">
                        HCNVMS</h2>
                </div>
                <div class="flex flex-col gap-2">
                    <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
                        href="/HealthCenter/admin/admin_dashboard.php">
                        <span class="material-symbols-outlined">dashboard</span>
                        <p class="text-sm font-medium leading-normal">Dashboard</p>
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
                <a class="flex items-center gap-3 px-3 py-2 rounded-lg bg-primary/20 text-primary"
                    href="/HealthCenter/admin/profile.php">
                    <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">person</span>
                    <p class="text-sm font-medium">My Profile</p>
                </a>
                <a class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-primary/10 dark:hover:bg-primary/20"
                    href="/HealthCenter/auth/logout.php">
                    <span class="material-symbols-outlined">logout</span>
                    <p class="text-sm font-medium">Logout</p>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex flex-1 flex-col">
            <main class="flex flex-col flex-1 p-6 lg:p-10 gap-6 lg:gap-10">
                <!-- PageHeading -->
                <div class="flex flex-wrap justify-between gap-3">
                    <div class="flex min-w-72 flex-col gap-2">
                        <p class="text-text-light dark:text-text-dark text-3xl font-black leading-tight tracking-tight">
                            My Profile</p>
                        <p
                            class="text-text-secondary-light dark:text-text-secondary-dark text-base font-normal leading-normal">
                            View and update your personal information.</p>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="mb-6 p-4 bg-green-50 text-green-700 rounded-lg dark:bg-green-900/30 dark:text-green-400">
                        <?= htmlspecialchars($_SESSION['success']);
                        unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-lg dark:bg-red-900/30 dark:text-red-400">
                        <?= htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full
                                        Name</label>
                                    <input type="text" name="full_name"
                                        value="<?= htmlspecialchars($admin['full_name'] ?? '') ?>"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-4 py-2 focus:ring-2 focus:ring-primary/50 focus:border-primary">
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                                    <input type="email" name="email"
                                        value="<?= htmlspecialchars($admin['email'] ?? '') ?>"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-4 py-2 focus:ring-2 focus:ring-primary/50 focus:border-primary">
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                                    <input type="tel" name="phone"
                                        value="<?= htmlspecialchars($admin['phone'] ?? '') ?>"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-4 py-2 focus:ring-2 focus:ring-primary/50 focus:border-primary">
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Role</label>
                                    <input type="text"
                                        value="<?= htmlspecialchars(ucfirst($admin['role'] ?? 'Admin')) ?>"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-700 px-4 py-2 cursor-not-allowed"
                                        disabled>
                                </div>

                                <div class="md:col-span-2">
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Username</label>
                                    <input type="text" value="<?= htmlspecialchars($admin['username'] ?? '') ?>"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-700 px-4 py-2 cursor-not-allowed"
                                        disabled>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Username cannot be changed
                                    </p>
                                </div>
                            </div>

                            <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                                <button type="submit"
                                    class="px-6 py-2 bg-primary hover:bg-primary/90 text-white rounded-lg shadow-sm transition-colors duration-200">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
        </div>
        </main>
    </div>
</body>

</html>