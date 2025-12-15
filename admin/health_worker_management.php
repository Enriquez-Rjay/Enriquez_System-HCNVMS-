<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Handle form submissions for add and edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_GET['action']) && $_GET['action'] === 'add_submit') {
		$full_name = $mysqli->real_escape_string($_POST['full_name']);
		$email = $mysqli->real_escape_string($_POST['email']);
		// Derive a simple username from email (part before @) to satisfy unique username constraint
		$emailParts = explode('@', $_POST['email']);
		$rawUsername = $emailParts[0] ?? $_POST['email'];
		$username = $mysqli->real_escape_string($rawUsername);
		$password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
		$status = 'active';
		$role = 'health_worker';
		$mysqli->query("INSERT INTO users (username, full_name, email, role, password_hash, status, created_at) VALUES ('$username', '$full_name', '$email', '$role', '$password_hash', '$status', NOW())");
		header('Location: /HealthCenter/admin/health_worker_management.php');
		exit;
	}
	if (isset($_GET['action']) && $_GET['action'] === 'edit_submit' && isset($_GET['id'])) {
		$id = (int) $_GET['id'];
		$full_name = $mysqli->real_escape_string($_POST['full_name']);
		$email = $mysqli->real_escape_string($_POST['email']);
		$status = $mysqli->real_escape_string($_POST['status']);
		$mysqli->query("UPDATE users SET full_name='$full_name', email='$email', status='$status' WHERE id=$id AND role='health_worker'");
		header('Location: /HealthCenter/admin/health_worker_management.php');
		exit;
	}
}

// Handle health worker management actions
if (isset($_GET['action'])) {
	$action = $_GET['action'];
	$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
	if ($action === 'add') {
		// Show add health worker form
		include __DIR__ . '/hw_add_form.php';
		exit;
	} elseif ($action === 'edit' && $id) {
		// Show edit health worker form
		include __DIR__ . '/hw_edit_form.php';
		exit;
	} elseif ($action === 'view' && $id) {
		// Show health worker details
		include __DIR__ . '/hw_view.php';
		exit;
	} elseif ($action === 'toggle' && $id) {
		// Toggle health worker status (active/inactive)
		$user = $mysqli->query("SELECT status FROM users WHERE id=$id AND role='health_worker'")->fetch_assoc();
		if ($user) {
			$newStatus = strtolower($user['status']) === 'active' ? 'inactive' : 'active';
			$mysqli->query("UPDATE users SET status='$newStatus' WHERE id=$id AND role='health_worker'");
		}
		header('Location: /HealthCenter/admin/health_worker_management.php');
		exit;
	}
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
	<meta charset="utf-8" />
	<meta content="width=device-width, initial-scale=1.0" name="viewport" />
	<title>HCNVMS - Health Worker Management</title>
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
					borderRadius: {
						"DEFAULT": "0.5rem",
						"lg": "0.75rem",
						"xl": "1rem",
						"full": "9999px"
					},
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
					<a class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-primary/20 text-primary"
						href="/HealthCenter/admin/health_worker_management.php">
						<span class="material-symbols-outlined"
							style="font-variation-settings: 'FILL' 1;">health_and_safety</span>
						<p class="text-sm font-semibold leading-normal">Health Worker Mgt.</p>
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
		<div class="flex flex-1 flex-col">
			</header>
			<main class="flex flex-col flex-1 p-6 lg:p-10 gap-6 lg:gap-10">
				<div class="flex flex-wrap items-center justify-between gap-4">
					<div class="flex min-w-72 flex-col gap-2">
						<h1
							class="text-text-light dark:text-text-dark text-3xl font-black leading-tight tracking-tight">
							Health Worker Management</h1>
						<p
							class="text-text-secondary-light dark:text-text-secondary-dark text-base font-normal leading-normal">
							Manage health worker accounts, roles, and assignments.</p>
					</div>
					<button type="button" id="modalAddHWBtn"
						class="flex items-center justify-center gap-2 rounded-lg bg-primary text-white h-10 px-4 text-sm font-semibold leading-normal">
						<span class="material-symbols-outlined">add</span>
						<span>Add New Health Worker</span>
					</button>
				</div>
				<div
					class="flex flex-col gap-6 rounded-lg border border-border-light dark:border-border-dark p-6 bg-surface dark:bg-surface-dark">
					<div class="flex flex-wrap items-center justify-between gap-4">
						<div class="flex items-center gap-4">
							<label class="flex flex-col min-w-40 w-full sm:w-auto !h-10 max-w-64">
								<div class="flex w-full flex-1 items-stretch rounded-lg h-full">
									<div
										class="text-text-secondary-light dark:text-text-secondary-dark flex border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark items-center justify-center pl-3 rounded-l-lg border-r-0">
										<span class="material-symbols-outlined text-base">search</span>
									</div>
									<input id="hwSearchInput"
										class="form-input flex w-full min-w-0 flex-1 resize-none overflow-hidden rounded-lg text-text-light dark:text-text-dark focus:outline-0 focus:ring-2 focus:ring-primary/50 border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark h-full placeholder:text-text-secondary-light dark:placeholder:text-text-secondary-dark px-3 rounded-l-none border-l-0 pl-2 text-sm font-normal leading-normal"
										placeholder="Search by name or email..." />
								</div>
							</label>
						</div>
					</div>
					<!-- Health workers table (loaded from DB) -->
					<div
						class="overflow-x-auto mt-4 rounded-lg border border-border-light dark:divide-border-dark bg-surface dark:bg-surface-dark">
						<table class="w-full text-left">
							<thead>
								<tr
									class="border-b border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark">
									<th
										class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
										Name</th>
									<th
										class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
										Email</th>
									<th
										class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
										Date Added</th>
									<th
										class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark text-right">
										Actions</th>
								</tr>
							</thead>
							<tbody class="divide-y divide-border-light dark:divide-border-dark">
								<?php
								// Fetch all users with role = 'health_worker'
								$hw_q = $mysqli->query("SELECT id, full_name, email, created_at FROM users WHERE role = 'health_worker' ORDER BY created_at DESC LIMIT 200");
								if ($hw_q) {
									while ($hw = $hw_q->fetch_assoc()) {
										$hid = (int) $hw['id'];
										?>
										<tr>
											<td class="py-3 px-4 text-sm font-medium text-text-light dark:text-text-dark">
												<?= htmlspecialchars($hw['full_name'] ?? '') ?></td>
											<td class="py-3 px-4 text-sm"><?= htmlspecialchars($hw['email'] ?? '') ?></td>
											<td class="py-3 px-4 text-sm">
												<?= htmlspecialchars(substr($hw['created_at'] ?? '', 0, 10)) ?></td>
											<td class="py-3 px-4 text-sm text-right">
												<div class="flex justify-end gap-2">
													<button type="button"
														class="p-1.5 rounded-md hover:bg-primary/10 text-text-secondary-light dark:text-text-secondary-dark hover:text-primary modal-hw-edit-btn"
														data-id="<?= $hid ?>"><span
															class="material-symbols-outlined text-xl">edit</span></button>
													<button type="button"
														class="p-1.5 rounded-md hover:bg-primary/10 text-text-secondary-light dark:text-text-secondary-dark hover:text-primary modal-hw-view-btn"
														data-id="<?= $hid ?>"><span
															class="material-symbols-outlined text-xl">visibility</span></button>
												</div>
											</td>
										</tr>
										<?php
									}
								} else {
									echo '<tr><td class="p-4" colspan="4">No health workers found.</td></tr>';
								}
								?>
							</tbody>
						</table>
					</div>
			</main>
		</div>
	</div>
	</div>
	<script src="/HealthCenter/js/modal.js"></script>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			// Modal logic for Health Worker management actions
			// View button
			document.querySelectorAll('.modal-hw-view-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					fetch('/HealthCenter/admin/hw_view.php?id=' + btn.dataset.id)
						.then(function (res) { return res.text(); })
						.then(function (html) { modal.show(html); });
				});
			});
			// Edit button
			document.querySelectorAll('.modal-hw-edit-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					fetch('/HealthCenter/admin/hw_edit_form.php?id=' + btn.dataset.id)
						.then(function (res) { return res.text(); })
						.then(function (html) { modal.show(html); });
				});
			});
			// Add New Health Worker button
			var addBtn = document.getElementById('modalAddHWBtn');
			if (addBtn) {
				addBtn.addEventListener('click', function () {
					fetch('/HealthCenter/admin/hw_add_form.php')
						.then(function (res) { return res.text(); })
						.then(function (html) { modal.show(html); });
				});
			}

			// Search filter: by name or email
			var searchInput = document.getElementById('hwSearchInput');
			if (searchInput) {
				searchInput.addEventListener('input', function () {
					var term = searchInput.value.toLowerCase();
					document.querySelectorAll('tbody tr').forEach(function (row) {
						var nameCell = row.querySelector('td:nth-child(1)');
						var emailCell = row.querySelector('td:nth-child(2)');
						if (!nameCell || !emailCell) return;
						var nameText = nameCell.textContent.toLowerCase();
						var emailText = emailCell.textContent.toLowerCase();
						var show = !term || nameText.includes(term) || emailText.includes(term);
						row.style.display = show ? '' : 'none';
					});
				});
			}
		});
	</script>
</body>

</html>