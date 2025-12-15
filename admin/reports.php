<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Handle report generation parameters
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'Vaccination Coverage';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$coverageRows = [];
$stockRows = [];
if (isset($_GET['action']) && $_GET['action'] === 'generate') {
    $sd = $mysqli->real_escape_string($startDate);
    $ed = $mysqli->real_escape_string($endDate);

    if ($reportType === 'Vaccination Coverage') {
        // Vaccination coverage: aggregate doses and unique patients per vaccine in date range
        $sql = "SELECT v.name AS vaccine_name, COUNT(r.id) AS doses_given, COUNT(DISTINCT r.patient_id) AS unique_patients
                FROM vaccination_records r
                JOIN vaccines v ON v.id = r.vaccine_id
                WHERE r.date_given BETWEEN '$sd' AND '$ed'
                GROUP BY v.id, v.name
                ORDER BY v.name";
        if ($res = $mysqli->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $coverageRows[] = $row;
            }
        }
    } elseif ($reportType === 'Vaccine Stock') {
        // Vaccine stock: aggregate quantity_available per vaccine from vaccine_batches
        $sql = "SELECT v.id, v.name, COALESCE(SUM(b.quantity_available),0) AS qty, MIN(b.expiry_date) AS earliest_expiry
                FROM vaccines v
                LEFT JOIN vaccine_batches b ON b.vaccine_id = v.id
                GROUP BY v.id, v.name
                ORDER BY v.name";
        if ($res = $mysqli->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $stockRows[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>HCNVMS - Reports</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800;900&amp;display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            .printable-area,
            .printable-area * {
                visibility: visible;
            }

            .printable-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }

            .no-print {
                display: none !important;
            }
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
                    <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-primary/20 text-primary"
                        href="/HealthCenter/admin/reports.php">
                        <span class="material-symbols-outlined"
                            style="font-variation-settings: 'FILL' 1;">summarize</span>
                        <p class="text-sm font-semibold leading-normal">Reports</p>
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
        <main class="flex flex-col flex-1 p-6 lg:p-10 gap-6 lg:gap-10">
            <div class="flex flex-col gap-2">
                <p class="text-text-light dark:text-text-dark text-3xl font-black leading-tight tracking-tight">Reports
                </p>
                <p class="text-text-secondary-light dark:text-text-secondary-dark text-base font-normal leading-normal">
                    Generate and view system reports.</p>
            </div>
            <div
                class="flex flex-col gap-6 rounded-lg border border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark p-6">
                <p class="text-text-light dark:text-text-dark text-lg font-semibold leading-normal">Generate New Report
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium text-text-light dark:text-text-dark" for="report-type">Report
                            Type</label>
                        <div class="relative">
                            <span
                                class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-text-secondary-light dark:text-text-secondary-dark">description</span>
                            <select
                                class="w-full rounded-lg border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark py-2 pl-10 pr-4 text-text-light dark:text-text-dark focus:border-primary focus:ring-2 focus:ring-primary/50"
                                id="report-type" name="report_type" form="report-form">
                                <option <?= $reportType === 'Vaccination Coverage' ? 'selected' : '' ?>>Vaccination Coverage
                                </option>
                                <option <?= $reportType === 'Vaccine Stock' ? 'selected' : '' ?>>Vaccine Stock</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium text-text-light dark:text-text-dark" for="start-date">Start
                            Date</label>
                        <div class="relative">
                            <span
                                class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-text-secondary-light dark:text-text-secondary-dark">calendar_today</span>
                            <input
                                class="w-full rounded-lg border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark py-2 pl-10 pr-4 text-text-light dark:text-text-dark focus:border-primary focus:ring-2 focus:ring-primary/50"
                                id="start-date" name="start_date" form="report-form" type="date"
                                value="<?= htmlspecialchars($startDate) ?>" />
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium text-text-light dark:text-text-dark" for="end-date">End
                            Date</label>
                        <div class="relative">
                            <span
                                class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-text-secondary-light dark:text-text-secondary-dark">event</span>
                            <input
                                class="w-full rounded-lg border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark py-2 pl-10 pr-4 text-text-light dark:text-text-dark focus:border-primary focus:ring-2 focus:ring-primary/50"
                                id="end-date" name="end_date" form="report-form" type="date"
                                value="<?= htmlspecialchars($endDate) ?>" />
                        </div>
                    </div>
                    <form id="report-form" method="get" action="/HealthCenter/admin/reports.php"
                        class="flex h-10 items-center justify-center">
                        <input type="hidden" name="action" value="generate" />
                        <button type="submit"
                            class="flex h-10 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary/90">
                            <span class="material-symbols-outlined text-base">rocket_launch</span>
                            <span>Generate Report</span>
                        </button>
                    </form>
                    <?php if ($reportType === 'Vaccination Coverage') { ?>
                        <button onclick="window.print()"
                            class="no-print flex h-10 items-center justify-center gap-2 rounded-lg bg-success px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-success/90">
                            <span class="material-symbols-outlined text-base">print</span>
                            <span>Print Report</span>
                        </button>
                    <?php } ?>
                </div>
            </div>
            <?php if ($reportType === 'Vaccination Coverage') { ?>
                <div
                    class="printable-area flex flex-col gap-4 rounded-lg border border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark p-6 mt-4">
                    <div class="flex items-center justify-between">
                        <p class="text-lg font-semibold text-text-light dark:text-text-dark">Vaccination Coverage Report</p>
                        <p class="text-sm text-text-secondary-light dark:text-text-secondary-dark">Period:
                            <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></p>
                    </div>
                    <?php if (!empty($coverageRows)) { ?>
                        <div class="overflow-x-auto mt-2">
                            <table class="w-full text-left">
                                <thead>
                                    <tr
                                        class="border-b border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark">
                                        <th
                                            class="py-2 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                                            Vaccine</th>
                                        <th
                                            class="py-2 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                                            Doses Given</th>
                                        <th
                                            class="py-2 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                                            Unique Patients</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border-light dark:divide-border-dark">
                                    <?php foreach ($coverageRows as $row) { ?>
                                        <tr>
                                            <td class="py-2 px-4 text-sm text-text-light dark:text-text-dark">
                                                <?= htmlspecialchars($row['vaccine_name']) ?></td>
                                            <td class="py-2 px-4 text-sm"><?= (int) $row['doses_given'] ?></td>
                                            <td class="py-2 px-4 text-sm"><?= (int) $row['unique_patients'] ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } else { ?>
                        <p class="text-sm text-text-secondary-light dark:text-text-secondary-dark">No vaccination records found
                            for the selected period.</p>
                    <?php } ?>
                </div>
            <?php } elseif ($reportType === 'Vaccine Stock') { ?>
                <div
                    class="flex flex-col gap-4 rounded-lg border border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark p-6 mt-4">
                    <div class="flex items-center justify-between">
                        <p class="text-lg font-semibold text-text-light dark:text-text-dark">Vaccine Stock Report</p>
                        <p class="text-sm text-text-secondary-light dark:text-text-secondary-dark">As of
                            <?= htmlspecialchars(date('Y-m-d')) ?></p>
                    </div>
                    <?php if (!empty($stockRows)) { ?>
                        <div class="overflow-x-auto mt-2">
                            <table class="w-full text-left">
                                <thead>
                                    <tr
                                        class="border-b border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark">
                                        <th
                                            class="py-2 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                                            Vaccine</th>
                                        <th
                                            class="py-2 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                                            Quantity Available</th>
                                        <th
                                            class="py-2 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
                                            Earliest Expiry</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border-light dark:divide-border-dark">
                                    <?php foreach ($stockRows as $row) { ?>
                                        <?php
                                        $qty = (int) $row['qty'];
                                        $expiry = $row['earliest_expiry'] ? date('Y-m-d', strtotime($row['earliest_expiry'])) : 'N/A';
                                        ?>
                                        <tr>
                                            <td class="py-2 px-4 text-sm text-text-light dark:text-text-dark">
                                                <?= htmlspecialchars($row['name']) ?></td>
                                            <td class="py-2 px-4 text-sm"><?= $qty ?></td>
                                            <td
                                                class="py-2 px-4 text-sm<?= ($row['earliest_expiry'] && strtotime($row['earliest_expiry']) < strtotime('+30 days')) ? ' text-danger' : '' ?>">
                                                <?= htmlspecialchars($expiry) ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } else { ?>
                        <p class="text-sm text-text-secondary-light dark:text-text-secondary-dark">No vaccine stock data found.
                        </p>
                    <?php } ?>
                </div>
            <?php } ?>
        </main>
    </div>
    </div>
</body>

</html>