<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Handle vaccine batch form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_GET['action']) && $_GET['action'] === 'add_submit') {
		$vaccine_id = (int) $_POST['vaccine_id'];
		$batch_number = $mysqli->real_escape_string($_POST['batch_number']);
		$quantity_received = (int) $_POST['quantity_received'];
		$quantity_available = (int) $_POST['quantity_available'];
		$expiry_date = $mysqli->real_escape_string($_POST['expiry_date']);
		$received_at = $mysqli->real_escape_string($_POST['received_at']);
		$mysqli->query("INSERT INTO vaccine_batches (vaccine_id, batch_number, quantity_received, quantity_available, expiry_date, received_at, created_at) VALUES ($vaccine_id, '$batch_number', $quantity_received, $quantity_available, '$expiry_date', '$received_at', NOW())");
		header('Location: /HealthCenter/admin/vaccine_inventory.php');
		exit;
	}
	if (isset($_GET['action']) && $_GET['action'] === 'edit_submit' && isset($_GET['id'])) {
		$id = (int) $_GET['id'];
		$vaccine_id = (int) $_POST['vaccine_id'];
		$batch_number = $mysqli->real_escape_string($_POST['batch_number']);
		$quantity_received = (int) $_POST['quantity_received'];
		$quantity_available = (int) $_POST['quantity_available'];
		$expiry_date = $mysqli->real_escape_string($_POST['expiry_date']);
		$received_at = $mysqli->real_escape_string($_POST['received_at']);
		$mysqli->query("UPDATE vaccine_batches SET vaccine_id=$vaccine_id, batch_number='$batch_number', quantity_received=$quantity_received, quantity_available=$quantity_available, expiry_date='$expiry_date', received_at='$received_at' WHERE id=$id");
		header('Location: /HealthCenter/admin/vaccine_inventory.php');
		exit;
	}
}

// Handle modal actions for add/edit/receipt
if (isset($_GET['action'])) {
	$action = $_GET['action'];
	$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
	if ($action === 'add') {
		include __DIR__ . '/vb_add_form.php';
		exit;
	} elseif ($action === 'edit' && $id) {
		include __DIR__ . '/vb_edit_form.php';
		exit;
	} elseif ($action === 'receipt' && $id) {
		include __DIR__ . '/vb_receipt_view.php';
		exit;
	}
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
	<meta charset="utf-8" />
	<meta content="width=device-width, initial-scale=1.0" name="viewport" />
	<title>HCNVMS - Vaccine Inventory</title>
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
					<a class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-primary/20 text-primary"
						href="/HealthCenter/admin/vaccine_inventory.php">
						<span class="material-symbols-outlined"
							style="font-variation-settings: 'FILL' 1;">inventory_2</span>
						<p class="text-sm font-semibold leading-normal">Vaccine Inventory</p>
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
		</header>
		<main class="flex flex-col flex-1 p-6 lg:p-10 gap-6 lg:gap-10">
			<div class="flex flex-wrap items-center justify-between gap-4">
				<div class="flex min-w-72 flex-col gap-2">
					<p class="text-text-light dark:text-text-dark text-3xl font-black leading-tight tracking-tight">
						Vaccine Inventory</p>
					<p
						class="text-text-secondary-light dark:text-text-secondary-dark text-base font-normal leading-normal">
						Manage vaccine stock, batches, and usage logs.</p>
				</div>
				<div class="flex flex-wrap items-center gap-3">
					<button type="button" id="modalAddBatchBtn"
						class="flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-all hover:bg-primary/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
						<span class="material-symbols-outlined text-base">add</span>
						<span>Add New Batch</span>
					</button>
				</div>
			</div>
			<div
				class="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-surface dark:bg-surface-dark p-4 border border-border-light dark:border-border-dark">
				<div class="flex flex-wrap items-center gap-3">
					<label class="flex flex-col min-w-40 !h-10 max-w-64">
						<div class="flex w-full flex-1 items-stretch rounded-lg h-full">
							<div
								class="text-text-secondary-light dark:text-text-secondary-dark flex border-none bg-background-light dark:bg-background-dark items-center justify-center pl-3 rounded-l-lg border-r-0">
								<span class="material-symbols-outlined text-base">search</span>
							</div>
							<input id="vaccineSearchInput"
								class="form-input flex w-full min-w-0 flex-1 resize-none overflow-hidden rounded-lg text-text-light dark:text-text-dark focus:outline-0 focus:ring-2 focus:ring-primary/50 border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark h-full placeholder:text-text-secondary-light dark:placeholder:text-text-secondary-dark px-3 rounded-l-none border-l-0 pl-2 text-sm font-normal leading-normal"
								placeholder="Search vaccines..." />
						</div>
					</label>
					<div class="relative min-w-40">
						<select id="stockFilter"
							class="form-select w-full rounded-lg border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark text-sm focus:border-primary focus:ring-primary/50">
							<option selected="">All Stock Levels</option>
							<option>In Stock</option>
							<option>Low Stock</option>
							<option>Critical</option>
							<option>Out of Stock</option>
						</select>
					</div>
					<button
						class="flex items-center justify-center gap-2 rounded-lg border border-border-light dark:border-border-dark px-4 py-2.5 text-sm font-semibold text-text-light dark:text-text-dark transition-all hover:bg-background-light dark:hover:bg-background-dark">
						<span class="material-symbols-outlined text-base">filter_list</span>
						<span>Filter</span>
					</button>
				</div>
				<div class="flex items-center gap-3">
					<button
						class="flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark transition-all hover:text-primary">
						<span class="material-symbols-outlined text-base">warning</span>
						<span>Generate Low-Stock Alert</span>
					</button>
				</div>
			</div>
			<div
				class="rounded-lg border border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark overflow-hidden">
				<div class="overflow-x-auto">
					<table class="w-full text-left">
						<thead>
							<tr
								class="border-b border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark">
								<th
									class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
									Vaccine / Batch</th>
								<th
									class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
									Stock Level</th>
								<th
									class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark">
									Earliest Expiry</th>
								<th
									class="py-3 px-4 text-sm font-semibold text-text-secondary-light dark:text-text-secondary-dark text-right">
									Actions</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-border-light dark:divide-border-dark">
							<?php
							// Query stock by batch so admin can see per-batch quantities
							$vsql = "SELECT 
							            b.id AS batch_id,
							            v.name,
							            b.batch_number,
							            b.quantity_available,
							            b.expiry_date
							        FROM vaccine_batches b
							        JOIN vaccines v ON v.id = b.vaccine_id
							        ORDER BY v.name, b.expiry_date";
							if ($vres = $mysqli->query($vsql)) {
								if ($vres->num_rows > 0) {
									while ($row = $vres->fetch_assoc()) {
										$batchId = (int) $row['batch_id'];
										$qty = (int) $row['quantity_available'];
										$levelClass = 'bg-success/20 text-success';
										$levelLabel = 'In Stock';
										if ($qty <= 0) {
											$levelClass = 'bg-gray-100 text-text-secondary-light';
											$levelLabel = 'Out';
										} elseif ($qty <= 20) {
											$levelClass = 'bg-danger/20 text-danger';
											$levelLabel = 'Critical';
										} elseif ($qty <= 50) {
											$levelClass = 'bg-warning/20 text-warning';
											$levelLabel = 'Low Stock';
										}
										$expiry = $row['expiry_date'] ? date('M j, Y', strtotime($row['expiry_date'])) : 'N/A';
										?>
									<tr>
										<td class="py-3 px-4 text-sm font-medium text-text-light dark:text-text-dark">
											<?= htmlspecialchars($row['name']) ?>
											<div class="text-xs text-text-secondary-light dark:text-text-secondary-dark">
												Batch: <?= htmlspecialchars($row['batch_number']) ?>
											</div>
										</td>
										<td class="py-3 px-4 text-sm">
											<div class="flex items-center gap-2">
												<span class="font-semibold"><?= $qty ?></span>
												<span
													class="<?= $levelClass ?> px-2 py-0.5 rounded-full text-xs font-semibold"><?= htmlspecialchars($levelLabel) ?></span>
											</div>
										</td>
										<td
											class="py-3 px-4 text-sm <?= ($row['expiry_date'] && strtotime($row['expiry_date']) < strtotime('+30 days')) ? 'text-danger' : '' ?>">
											<?= htmlspecialchars($expiry) ?></td>
										<td class="py-3 px-4 text-sm">
											<div class="flex justify-end gap-2">
												<button type="button"
													class="p-1.5 rounded-md hover:bg-primary/10 text-text-secondary-light dark:text-text-secondary-dark hover:text-primary modal-batch-edit-btn"
													data-id="<?= $batchId ?>"><span
														class="material-symbols-outlined text-xl">edit</span></button>
												<button type="button"
													class="p-1.5 rounded-md hover:bg-primary/10 text-text-secondary-light dark:text-text-secondary-dark hover:text-primary modal-batch-receipt-btn"
													data-id="<?= $batchId ?>"><span
														class="material-symbols-outlined text-xl">receipt_long</span></button>
											</div>
										</td>
									</tr>
									<?php
									}
								} else {
									echo '<tr><td class="p-4" colspan="4">No vaccine batches found.</td></tr>';
								}
								$vres->free();
							} else {
								echo '<tr><td class="p-4" colspan="4">Failed to load vaccine inventory.</td></tr>';
							}
							?>
						</tbody>
					</table>
				</div>
			</div>
		</main>
	</div>
	</div>

</body>
<script src="/HealthCenter/js/modal.js"></script>
<script>
	document.addEventListener('DOMContentLoaded', function () {
		// Add New Batch modal
		var addBtn = document.getElementById('modalAddBatchBtn');
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				fetch('/HealthCenter/admin/vaccine_inventory.php?action=add')
					.then(function (res) { return res.text(); })
					.then(function (html) { modal.show(html); });
			});
		}

		// Edit batch modal
		document.querySelectorAll('.modal-batch-edit-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				fetch('/HealthCenter/admin/vaccine_inventory.php?action=edit&id=' + btn.dataset.id)
					.then(function (res) { return res.text(); })
					.then(function (html) { modal.show(html); });
			});
		});

		// Receipt / details modal
		document.querySelectorAll('.modal-batch-receipt-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				fetch('/HealthCenter/admin/vaccine_inventory.php?action=receipt&id=' + btn.dataset.id)
					.then(function (res) { return res.text(); })
					.then(function (html) { modal.show(html); });
			});
		});

		// Stock level filter
		var stockFilter = document.getElementById('stockFilter');
		var searchInput = document.getElementById('vaccineSearchInput');

		function applyFilters() {
			var selected = stockFilter ? stockFilter.value.toLowerCase() : '';
			var term = searchInput ? searchInput.value.toLowerCase() : '';
			document.querySelectorAll('tbody tr').forEach(function (row) {
				var badge = row.querySelector('td:nth-child(2) span:nth-child(2)');
				var nameCell = row.querySelector('td:nth-child(1)');
				if (!badge || !nameCell) return;
				var label = badge.textContent.toLowerCase();
				var nameText = nameCell.textContent.toLowerCase();
				var show = true;
				// stock filter
				if (selected && selected !== 'all stock levels'.toLowerCase()) {
					show = false;
					if (selected === 'in stock' && label === 'in stock') show = true;
					else if (selected === 'low stock' && label === 'low stock') show = true;
					else if (selected === 'critical' && label === 'critical') show = true;
					else if (selected === 'out of stock' && label === 'out') show = true;
				}
				// text search
				if (show && term && !nameText.includes(term)) {
					show = false;
				}
				row.style.display = show ? '' : 'none';
			});
		}

		if (stockFilter) {
			stockFilter.addEventListener('change', applyFilters);
		}
		if (searchInput) {
			searchInput.addEventListener('input', applyFilters);
		}
	});
</script>

</html>