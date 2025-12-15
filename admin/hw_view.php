<?php
// View Health Worker Details
$mysqli = require __DIR__ . '/../config/db.php';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$user = $mysqli->query("SELECT * FROM users WHERE id=$id AND role='health_worker'")->fetch_assoc();
if (!$user) {
  echo '<div class="p-8">Health worker not found.</div>';
  return;
}
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-lg shadow">
  <h2 class="text-2xl font-bold mb-6">Health Worker Details</h2>
  <ul class="mb-4">
    <li><strong>Full Name:</strong> <?= htmlspecialchars($user['full_name']) ?></li>
    <li><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></li>
    <li><strong>Status:</strong> <?= htmlspecialchars(ucfirst($user['status'])) ?></li>
    <li><strong>Date Added:</strong> <?= htmlspecialchars($user['created_at']) ?></li>
  </ul>
  <a href="/HealthCenter/admin/health_worker_management.php" class="text-primary">Back to Health Worker List</a>
</div>