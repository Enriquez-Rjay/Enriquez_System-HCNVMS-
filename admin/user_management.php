<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Handle form submissions for add and edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_GET['action']) && $_GET['action'] === 'add_submit') {
		$full_name = $mysqli->real_escape_string($_POST['full_name']);
		$email = $mysqli->real_escape_string($_POST['email']);
		$role = $mysqli->real_escape_string($_POST['role']);
		$password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
		$status = 'active';
		$mysqli->query("INSERT INTO users (full_name, email, role, password_hash, status, created_at) VALUES ('$full_name', '$email', '$role', '$password_hash', '$status', NOW())");
		header('Location: /HealthCenter/admin/user_management.php');
		exit;
	}
	if (isset($_GET['action']) && $_GET['action'] === 'edit_submit' && isset($_GET['id'])) {
		$id = (int) $_GET['id'];
		$full_name = $mysqli->real_escape_string($_POST['full_name']);
		$email = $mysqli->real_escape_string($_POST['email']);
		$role = $mysqli->real_escape_string($_POST['role']);
		$status = $mysqli->real_escape_string($_POST['status']);
		$mysqli->query("UPDATE users SET full_name='$full_name', email='$email', role='$role', status='$status' WHERE id=$id");
		header('Location: /HealthCenter/admin/user_management.php');
		exit;
	}
}

// Handle user management actions
if (isset($_GET['action'])) {
	$action = $_GET['action'];
	$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
	if ($action === 'add') {
		// Show add user form
		include __DIR__ . '/user_add_form.php';
		exit;
	} elseif ($action === 'edit' && $id) {
		// Show edit user form
		include __DIR__ . '/user_edit_form.php';
		exit;
	} elseif ($action === 'view' && $id) {
		// Show user details
		include __DIR__ . '/user_view.php';
		exit;
	} elseif ($action === 'toggle' && $id) {
		// Toggle user status (active/inactive)
		$user = $mysqli->query("SELECT status FROM users WHERE id=$id")->fetch_assoc();
		if ($user) {
			$newStatus = strtolower($user['status']) === 'active' ? 'inactive' : 'active';
			$mysqli->query("UPDATE users SET status='$newStatus' WHERE id=$id");
		}
		header('Location: /HealthCenter/admin/user_management.php');
		exit;
	} elseif ($action === 'delete' && $id) {
		// Delete user account
		// Prevent deleting the currently logged-in admin
		if (isset($_SESSION['user_id']) && $id == $_SESSION['user_id']) {
			$_SESSION['error'] = 'You cannot delete your own account.';
			header('Location: /HealthCenter/admin/user_management.php');
			exit;
		}

		// Get user role before deletion
		$user = $mysqli->query("SELECT role FROM users WHERE id=$id")->fetch_assoc();
		if (!$user) {
			$_SESSION['error'] = 'User not found.';
			header('Location: /HealthCenter/admin/user_management.php');
			exit;
		}

		$role = $user['role'];
		
		// Start transaction
		$mysqli->begin_transaction();
		
		try {
			if ($role === 'patient') {
				// Delete patient-related data
				$mysqli->query("DELETE FROM vaccination_records WHERE patient_id=$id");
				$mysqli->query("DELETE FROM appointments WHERE patient_id=$id");
				$mysqli->query("DELETE FROM patient_profiles WHERE user_id=$id");
			} elseif ($role === 'health_worker') {
				// Update appointments to remove health_worker_id reference
				$mysqli->query("UPDATE appointments SET health_worker_id=NULL WHERE health_worker_id=$id");
				// Delete health worker profile if exists
				$mysqli->query("DELETE FROM health_worker_profiles WHERE user_id=$id");
			}
			// For admin role, just delete the user (already prevented self-deletion above)
			
			// Delete the user account
			$mysqli->query("DELETE FROM users WHERE id=$id");
			
			// Commit transaction
			$mysqli->commit();
			
			$_SESSION['success'] = 'User account deleted successfully.';
		} catch (Exception $e) {
			// Rollback on error
			$mysqli->rollback();
			$_SESSION['error'] = 'Failed to delete user: ' . $e->getMessage();
		}
		
		header('Location: /HealthCenter/admin/user_management.php');
		exit;
	}
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
	<meta charset="utf-8" />
	<meta content="width=device-width, initial-scale=1.0" name="viewport" />
	<title>HCNVMS - User Management</title>
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
					<a class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-primary/20 text-primary"
						href="/HealthCenter/admin/user_management.php">
						<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">group</span>
						<p class="text-sm font-semibold leading-normal">User Management</p>
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
			<div class="flex flex-1 justify-end gap-4"></div>
			</header>
			<main class="flex flex-col flex-1 p-6 lg:p-10 gap-6 lg:gap-10">
				<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
					<div class="flex min-w-72 flex-col gap-2">
						<p class="text-text-light dark:text-text-dark text-3xl font-black leading-tight tracking-tight">
							User Management</p>
						<p
							class="text-text-secondary-light dark:text-text-secondary-dark text-base font-normal leading-normal">
							Manage all user accounts in the system.</p>
					</div>
					<button type="button" id="modalAddUserBtn"
						class="flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-primary px-5 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary/50 disabled:cursor-not-allowed disabled:opacity-70">
						<span class="material-symbols-outlined text-xl">add</span>
						<span>Add New User</span>
					</button>
				</div>
				<div
					class="flex flex-col gap-6 rounded-lg border border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark p-6">
					<div class="flex flex-col md:flex-row gap-4 justify-between">
						<label class="flex-1 min-w-40 max-w-sm">
							<div class="flex w-full flex-1 items-stretch rounded-lg h-10">
								<div
									class="text-text-secondary-light dark:text-text-secondary-dark flex border-none bg-background-light dark:bg-background-dark items-center justify-center pl-4 rounded-l-lg border-r-0">
									<span class="material-symbols-outlined">search</span>
								</div>
								<input
									class="form-input flex w-full min-w-0 flex-1 resize-none overflow-hidden rounded-lg text-text-light dark:text-text-dark focus:outline-0 focus:ring-2 focus:ring-primary/50 border-none bg-background-light dark:bg-background-dark h-full placeholder:text-text-secondary-light dark:placeholder:text-secondary-dark px-4 rounded-l-none border-l-0 pl-2 text-base font-normal leading-normal"
									placeholder="Search by name or email..." value="" />
							</div>
						</label>
						<div class="flex gap-4">
							<select
								class="form-select h-10 rounded-lg border border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark text-text-secondary-light dark:text-text-secondary-dark focus:ring-2 focus:ring-primary/50 focus:border-primary/50">
								<option>Filter by Role</option>
								<option>Admin</option>
								<option>Health Worker</option>
								<option>Patient</option>
							</select>
							<select
								class="form-select h-10 rounded-lg border border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark text-text-secondary-light dark:text-text-secondary-dark focus:ring-2 focus:ring-primary/50 focus:border-primary/50">
								<option>Filter by Status</option>
								<option>Active</option>
								<option>Inactive</option>
							</select>
						</div>
					</div>
					<div class="overflow-x-auto">
						<table class="w-full text-left">
							<thead>
								<tr class="border-b border-border-light dark:divide-border-dark">
									<th
										class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
										User</th>
									<th
										class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
										Role</th>
									<th
										class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
										Status</th>
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
								// Fetch users from DB
								$users_q = $mysqli->query("SELECT id, full_name, email, role, status, created_at FROM users ORDER BY created_at DESC LIMIT 100");
								if ($users_q) {
									while ($u = $users_q->fetch_assoc()) {
										$uid = (int) $u['id'];
										$statusLabel = strtolower($u['status'] ?? 'active') === 'active' ? 'Active' : 'Inactive';
										$statusClass = strtolower($statusLabel) === 'active'
											? 'bg-green-100 text-green-600'
											: 'bg-red-100 text-red-600';
										?>
										<tr>
											<td class="p-4">
												<div class="flex items-center gap-3">
													<div
														class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-10">
													</div>
													<div>
														<p class="font-medium text-text-light dark:text-text-dark">
															<?= htmlspecialchars($u['full_name'] ?? $u['email']) ?></p>
														<p
															class="text-sm text-text-secondary-light dark:text-text-secondary-dark">
															<?= htmlspecialchars($u['email']) ?></p>
													</div>
												</div>
											</td>
											<td class="p-4 text-sm">
												<?php
												$role = $u['role'] ?? 'patient';
												if ($role === 'admin') {
													echo 'Admin';
												} elseif ($role === 'health_worker') {
													echo 'Health Worker';
												} else {
													echo 'Patient';
												}
												?>
											</td>
											<td class="p-4">
												<span
													class="inline-block px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>"
													style="min-width:60px;text-align:center;">
													<?= htmlspecialchars($statusLabel) ?>
												</span>
											</td>
											<td class="p-4 text-sm"><?= htmlspecialchars(substr($u['created_at'] ?? '', 0, 10)) ?>
											</td>
											<td class="p-4 text-right">
												<div class="flex justify-end gap-2">
													<button type="button"
														class="flex items-center justify-center size-8 rounded-lg text-text-secondary-light dark:text-text-secondary-dark hover:bg-primary/10 hover:text-primary modal-edit-btn"
														data-id="<?= $uid ?>"><span
															class="material-symbols-outlined text-xl">edit</span></button>
													<button type="button"
														class="flex items-center justify-center size-8 rounded-lg text-text-secondary-light dark:text-text-secondary-dark hover:bg-primary/10 hover:text-primary modal-view-btn"
														data-id="<?= $uid ?>"><span
															class="material-symbols-outlined text-xl">visibility</span></button>
													<a href="/HealthCenter/admin/user_management.php?action=toggle&id=<?= $uid ?>"
														class="flex items-center justify-center size-8 rounded-lg text-danger hover:bg-danger/10"><span
															class="material-symbols-outlined text-xl">toggle_off</span></a>
													<button type="button"
														class="flex items-center justify-center size-8 rounded-lg text-danger hover:bg-danger/10 delete-user-btn"
														data-id="<?= $uid ?>"
														data-name="<?= htmlspecialchars($u['full_name'] ?? $u['email'], ENT_QUOTES) ?>"><span
															class="material-symbols-outlined text-xl">delete</span></button>
												</div>
											</td>
										</tr>
										<?php
									}
								} else {
									echo '<tr><td class="p-4" colspan="5">No users found.</td></tr>';
								}
								?>
							</tbody>
						</table>
					</div>
					<div class="flex flex-col sm:flex-row items-center justify-between pt-4">

					</div>
				</div>
			</main>
			<?php if (isset($_SESSION['success'])): ?>
			<div class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg bg-green-100 p-4 text-green-700 shadow-lg dark:bg-green-800 dark:text-green-100">
				<div class="flex items-center">
					<span class="material-symbols-outlined mr-2">check_circle</span>
					<span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
				</div>
			</div>
			<?php endif; ?>
			<?php if (isset($_SESSION['error'])): ?>
			<div class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg bg-red-100 p-4 text-red-700 shadow-lg dark:bg-red-800 dark:text-red-100">
				<div class="flex items-center">
					<span class="material-symbols-outlined mr-2">error</span>
					<span><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
				</div>
			</div>
			<?php endif; ?>
			<script src="/HealthCenter/js/modal.js"></script>
			<script>
				document.addEventListener('DOMContentLoaded', function () {
					// Modal logic for user management actions
					// View button
					document.querySelectorAll('.modal-view-btn').forEach(function (btn) {
						btn.addEventListener('click', function () {
							fetch('/HealthCenter/admin/user_view.php?id=' + btn.dataset.id)
								.then(res => res.text())
								.then(html => modal.show(html));
						});
					});
					// Edit button
					document.querySelectorAll('.modal-edit-btn').forEach(function (btn) {
						btn.addEventListener('click', function () {
							fetch('/HealthCenter/admin/user_edit_form.php?id=' + btn.dataset.id)
								.then(res => res.text())
								.then(html => modal.show(html));
						});
					});
					// Add New User button
					var addBtn = document.getElementById('modalAddUserBtn');
					if (addBtn) {
						addBtn.addEventListener('click', function () {
							fetch('/HealthCenter/admin/user_add_form.php')
								.then(res => res.text())
								.then(html => modal.show(html));
						});
					}
					// Delete button
					document.querySelectorAll('.delete-user-btn').forEach(function (btn) {
						btn.addEventListener('click', function () {
							var userId = btn.dataset.id;
							var userName = btn.dataset.name;
							if (confirm('Are you sure you want to delete the account for "' + userName + '"? This action cannot be undone.')) {
								window.location.href = '/HealthCenter/admin/user_management.php?action=delete&id=' + userId;
							}
						});
					});
				});
			</script>
			<script>
				document.addEventListener('DOMContentLoaded', function () {
					const searchInput = document.querySelector('input[placeholder="Search by name or email..."]');
					const roleSelect = document.querySelector('select.form-select:nth-of-type(1)');
					const statusSelect = document.querySelector('select.form-select:nth-of-type(2)');
					function filterTable() {
						const search = searchInput.value.toLowerCase();
						const role = roleSelect.value.toLowerCase();
						const status = statusSelect.value.toLowerCase();
						document.querySelectorAll('tbody tr').forEach(function (row) {
							const name = row.querySelector('td:nth-child(1) p').textContent.toLowerCase();
							const email = row.querySelector('td:nth-child(1) p + p').textContent.toLowerCase();
							const rowRole = row.querySelector('td:nth-child(2)').textContent.trim().toLowerCase();
							const rowStatus = row.querySelector('td:nth-child(3)').textContent.trim().toLowerCase();
							let show = true;
							if (search && !(name.includes(search) || email.includes(search))) show = false;
							if (role !== 'filter by role' && rowRole !== role) show = false;
							if (status !== 'filter by status' && rowStatus !== status) show = false;
							row.style.display = show ? '' : 'none';
						});
					}
					searchInput.addEventListener('input', filterTable);
					roleSelect.addEventListener('change', filterTable);
					statusSelect.addEventListener('change', filterTable);
				});
			</script>
		</div>
	</div>

</body>

</html>