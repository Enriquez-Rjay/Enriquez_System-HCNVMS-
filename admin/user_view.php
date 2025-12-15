<?php
// View User Details
require_once __DIR__ . '/../config/db.php';
$mysqli = require __DIR__ . '/../config/db.php';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$user = $mysqli->query("SELECT * FROM users WHERE id=$id")->fetch_assoc();
if (!$user) {
  echo '<div class="p-8">User not found.</div>';
  return;
}
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-xl shadow-lg border border-border-light">
  <h2 class="text-3xl font-extrabold mb-6 text-primary">User Details</h2>
  <ul class="mb-6 space-y-2">
    <li><span class="font-semibold">Full Name:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($user['full_name']) ?></span>
    </li>
    <li><span class="font-semibold">Email:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($user['email']) ?></span>
    </li>
    <li><span class="font-semibold">Role:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars(ucfirst($user['role'])) ?></span>
    </li>
    <li><span class="font-semibold">Status:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars(ucfirst($user['status'])) ?></span>
    </li>
    <li><span class="font-semibold">Date Added:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($user['created_at']) ?></span>
    </li>
  </ul>
  <a href="/HealthCenter/admin/user_management.php"
    class="text-primary font-semibold px-5 py-2 rounded-lg hover:underline">Back to User List</a>
</div>