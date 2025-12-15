<?php
// patient/schedule_appointment.php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
    header('Location: /HealthCenter/login.php');
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
    // Account is inactive, destroy session and redirect
    session_destroy();
    header('Location: /HealthCenter/login.php?error=account_inactive');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get patient information
$user = [];
$stmt = $mysqli->prepare("SELECT full_name, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $user = $row;
}
$stmt->close();

// Get available vaccines
$vaccines = [];
$stmt = $mysqli->query("SELECT id, name, description FROM vaccines");
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $vaccines[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vaccine_id = $_POST['vaccine_id'] ?? '';
    $preferred_date = $_POST['preferred_date'] ?? '';
    $preferred_time = $_POST['preferred_time'] ?? '';
    $notes = $_POST['notes'] ?? '';

    $errors = [];

    // Basic validation
    if (empty($vaccine_id)) {
        $errors[] = "Please select a vaccine";
    }
    if (empty($preferred_date)) {
        $errors[] = "Please select a preferred date";
    }
    if (empty($preferred_time)) {
        $errors[] = "Please select a preferred time";
    }

    if (empty($errors)) {
        $scheduled_at = date('Y-m-d H:i:s', strtotime("$preferred_date $preferred_time"));
        $status = 'pending'; // or 'scheduled' if auto-confirm

        $stmt = $mysqli->prepare("
            INSERT INTO appointments 
            (patient_id, vaccine_id, scheduled_at, status, notes, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        if ($stmt) {
            $stmt->bind_param("iisss", $user_id, $vaccine_id, $scheduled_at, $status, $notes);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Appointment scheduled successfully!";
                header("Location: p_appointments.php");
                exit();
            } else {
                $errors[] = "Error scheduling appointment: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errors[] = "Database error: " . $mysqli->error;
        }
    }
}

// Include the header
include_once '../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8 px-4 sm:px-6 lg:px-8">
    <div class="max-w-3xl mx-auto">
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Schedule New Appointment</h3>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border-l-4 border-red-400 p-4 m-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800">Please fix the following errors:</h3>
                            <div class="mt-2 text-sm text-red-700">
                                <ul class="list-disc pl-5 space-y-1">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" class="p-6">
                <div class="space-y-6">
                    <!-- Patient Information (Read-only) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Patient Name</label>
                        <div class="mt-1">
                            <input type="text" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
                                class="bg-gray-100 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                readonly>
                        </div>
                    </div>

                    <!-- Vaccine Selection -->
                    <div>
                        <label for="vaccine_id" class="block text-sm font-medium text-gray-700">Vaccine <span
                                class="text-red-500">*</span></label>
                        <select id="vaccine_id" name="vaccine_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-primary-500 focus:outline-none focus:ring-primary-500 sm:text-sm">
                            <option value="">Select a vaccine</option>
                            <?php foreach ($vaccines as $vaccine): ?>
                                <option value="<?= $vaccine['id'] ?>" <?= (isset($_POST['vaccine_id']) && $_POST['vaccine_id'] == $vaccine['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($vaccine['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-sm text-gray-500">Select the vaccine you need</p>
                    </div>

                    <!-- Preferred Date -->
                    <div>
                        <label for="preferred_date" class="block text-sm font-medium text-gray-700">Preferred Date <span
                                class="text-red-500">*</span></label>
                        <div class="mt-1">
                            <input type="date" id="preferred_date" name="preferred_date" min="<?= date('Y-m-d') ?>"
                                value="<?= $_POST['preferred_date'] ?? '' ?>" required
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                        </div>
                        <p class="mt-1 text-sm text-gray-500">Earliest available date is tomorrow</p>
                    </div>

                    <!-- Preferred Time -->
                    <div>
                        <label for="preferred_time" class="block text-sm font-medium text-gray-700">Preferred Time <span
                                class="text-red-500">*</span></label>
                        <div class="mt-1">
                            <select id="preferred_time" name="preferred_time" required
                                class="block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-primary-500 focus:outline-none focus:ring-primary-500 sm:text-sm">
                                <option value="">Select a time slot</option>
                                <option value="08:00" <?= (isset($_POST['preferred_time']) && $_POST['preferred_time'] == '08:00') ? 'selected' : '' ?>>8:00 AM</option>
                                <option value="09:00" <?= (isset($_POST['preferred_time']) && $_POST['preferred_time'] == '09:00') ? 'selected' : '' ?>>9:00 AM</option>
                                <option value="10:00" <?= (isset($_POST['preferred_time']) && $_POST['preferred_time'] == '10:00') ? 'selected' : '' ?>>10:00 AM</option>
                                <option value="11:00" <?= (isset($_POST['preferred_time']) && $_POST['preferred_time'] == '11:00') ? 'selected' : '' ?>>11:00 AM</option>
                                <option value="13:00" <?= (isset($_POST['preferred_time']) && $_POST['preferred_time'] == '13:00') ? 'selected' : '' ?>>1:00 PM</option>
                                <option value="14:00" <?= (isset($_POST['preferred_time']) && $_POST['preferred_time'] == '14:00') ? 'selected' : '' ?>>2:00 PM</option>
                                <option value="15:00" <?= (isset($_POST['preferred_time']) && $_POST['preferred_time'] == '15:00') ? 'selected' : '' ?>>3:00 PM</option>
                                <option value="16:00" <?= (isset($_POST['preferred_time']) && $_POST['preferred_time'] == '16:00') ? 'selected' : '' ?>>4:00 PM</option>
                            </select>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">Clinic hours: 8:00 AM - 5:00 PM (Closed 12:00 PM - 1:00
                            PM)</p>
                    </div>

                    <!-- Additional Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Additional Notes</label>
                        <div class="mt-1">
                            <textarea id="notes" name="notes" rows="3"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">Any special requirements or notes for the healthcare
                            provider</p>
                    </div>

                    <!-- Form Actions -->
                    <div class="pt-5">
                        <div class="flex justify-end gap-3">
                            <a href="p_appointments.php"
                                class="rounded-md border border-gray-300 bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                                Cancel
                            </a>
                            <button type="submit"
                                class="inline-flex justify-center rounded-md border border-transparent bg-primary-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                                Schedule Appointment
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Set minimum date to tomorrow
    document.addEventListener('DOMContentLoaded', function () {
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);

        // Format as YYYY-MM-DD
        const minDate = tomorrow.toISOString().split('T')[0];
        document.getElementById('preferred_date').min = minDate;

        // If no date is selected, set it to tomorrow
        if (!document.getElementById('preferred_date').value) {
            document.getElementById('preferred_date').value = minDate;
        }
    });
</script>

<?php include_once '../includes/footer.php'; ?>