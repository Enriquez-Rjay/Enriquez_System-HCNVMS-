<?php
require_once __DIR__ . '/../config/session.php';
$mysqli = require __DIR__ . '/../config/db.php';

// Load system settings (if table exists)
$smsTemplate = 'Dear Parent, your child is due for [Vaccine Name] on [Date].';
$emailTemplate = "Subject: Vaccination Reminder\nDear [Parent Name],\nThis is a reminder that your child, [Child Name], is scheduled for the [Vaccine Name] vaccine on [Date].\nRegards,\nHealth Center Team";
$stmt = $mysqli->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sms_template','email_template')");
if ($stmt) {
	$stmt->execute();
	$res = $stmt->get_result();
	while ($r = $res->fetch_assoc()) {
		if ($r['setting_key'] === 'sms_template')
			$smsTemplate = $r['setting_value'];
		if ($r['setting_key'] === 'email_template')
			$emailTemplate = $r['setting_value'];
	}
	$stmt->close();
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
	<meta charset="utf-8" />
	<meta content="width=device-width, initial-scale=1.0" name="viewport" />
	<title>HCNVMS - System Settings</title>
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

		.switch-checkbox:checked {
			right: 2px;
			left: auto;
			border-color: #4A90E2;
			background-color: #ffffff;
		}

		.switch-checkbox:checked+.switch-bg {
			background-color: #4A90E2;
			border-color: #4A90E2;
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
					<a class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-primary/20 text-primary"
						href="/HealthCenter/admin/system_settings.php">
						<span class="material-symbols-outlined"
							style="font-variation-settings: 'FILL' 1;">settings</span>
						<p class="text-sm font-semibold leading-normal">System Settings</p>
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
			<div class="flex flex-wrap justify-between gap-3">
				<div class="flex min-w-72 flex-col gap-2">
					<p class="text-text-light dark:text-text-dark text-3xl font-black leading-tight tracking-tight">
						System Settings</p>
					<p
						class="text-text-secondary-light dark:text-text-secondary-dark text-base font-normal leading-normal">
						Manage system-wide configurations and preferences.</p>
				</div>
			</div>
			<div class="flex flex-col gap-10">
				<div
					class="flex flex-col gap-6 p-6 rounded-lg border border-border-light dark:border-border-dark bg-surface dark:bg-surface-dark">
					<div class="flex flex-col">
						<h3 class="text-xl font-semibold text-text-light dark:text-text-dark">Notification Settings</h3>
						<p class="text-sm text-text-secondary-light dark:text-text-secondary-dark">Configure how and
							when notifications are sent.</p>
					</div>
					<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
						<div class="flex flex-col gap-2">
							<label class="text-sm font-medium" for="sms-template">SMS Template</label>
							<textarea
								class="form-textarea w-full rounded-lg border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark focus:ring-2 focus:ring-primary/50 focus:border-primary"
								id="sms-template" placeholder="Enter SMS message template..."
								rows="4">Dear Parent, your child is due for [Vaccine Name] on [Date]. Please visit the health center. Thank you.</textarea>
							<p class="text-xs text-text-secondary-light dark:text-text-secondary-dark">Use placeholders
								like [Vaccine Name], [Date], [Child Name].</p>
						</div>
						<div class="flex flex-col gap-2">
							<label class="text-sm font-medium" for="email-template">Email Template</label>
							<textarea
								class="form-textarea w-full rounded-lg border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark focus:ring-2 focus:ring-primary/50 focus:border-primary"
								id="email-template" placeholder="Enter Email message template..." rows="4">Subject: Vaccination Reminder
Dear [Parent Name],
This is a reminder that your child, [Child Name], is scheduled for the [Vaccine Name] vaccine on [Date].
Regards,
Health Center Team</textarea>
						</div>
					</div>
					<div class="flex items-center gap-4">
						<label class="relative inline-flex cursor-pointer items-center">
							<input checked="" class="peer sr-only switch-checkbox" type="checkbox" value="" />
							<div
								class="peer h-6 w-11 rounded-full bg-border-light dark:bg-border-dark after:absolute after:top-[2px] after:left-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-primary peer-checked:after:translate-x-full peer-checked:after:border-white">
							</div>
						</label>
						<div>
							<p class="text-sm font-medium">Enable SMS Notifications</p>
							<p class="text-xs text-text-secondary-light dark:text-text-secondary-dark">Send SMS
								reminders for upcoming appointments.</p>
						</div>
					</div>
					<div class="flex items-center gap-4">
						<label class="relative inline-flex cursor-pointer items-center">
							<input checked="" class="peer sr-only switch-checkbox" type="checkbox" value="" />
							<div
								class="peer h-6 w-11 rounded-full bg-border-light dark:bg-border-dark after:absolute after:top-[2px] after:left-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-primary peer-checked:after:translate-x-full peer-checked:after:border-white">
							</div>
						</label>
						<div>
							<p class="text-sm font-medium">Enable Email Notifications</p>
							<p class="text-xs text-text-secondary-light dark:text-text-secondary-dark">Send email
								reminders for upcoming appointments.</p>
						</div>
					</div>
					<div class="mt-6">
						<button type="button" id="sendTodayEmailsBtn"
							class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
							<span class="material-symbols-outlined text-base">send</span>
							<span>Send Today's Email Reminders</span>
						</button>
						<p class="mt-1 text-xs text-text-secondary-light dark:text-text-secondary-dark">This will send
							email reminders to all patients with appointments scheduled today.</p>
					</div>
				</div>
				<!-- rest of settings content omitted for brevity -->
		</main>
	</div>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var btn = document.getElementById('sendTodayEmailsBtn');
			if (!btn) return;
			btn.addEventListener('click', function () {
				btn.disabled = true;
				btn.classList.add('opacity-70');
				fetch('/HealthCenter/scripts/send_today_notifications.php')
					.then(function (res) { return res.text(); })
					.then(function (text) {
						alert(text.trim());
					})
					.catch(function () {
						alert('Failed to send today\'s reminders.');
					})
					.finally(function () {
						btn.disabled = false;
						btn.classList.remove('opacity-70');
					});
			});
		});
	</script>
</body>

</html>