<?php
// Edit Health Worker Form
$mysqli = require __DIR__ . '/../config/db.php';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$user = $mysqli->query("SELECT * FROM users WHERE id=$id AND role='health_worker'")->fetch_assoc();
if (!$user) {
  echo '<div class="p-8">Health worker not found.</div>';
  return;
}
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-lg shadow">
  <h2 class="text-2xl font-bold mb-6">Edit Health Worker</h2>
  <form method="post" action="/HealthCenter/admin/health_worker_management.php?action=edit_submit&id=<?= $id ?>">
    <div class="mb-4">
      <label class="block mb-1 font-semibold">Full Name</label>
      <input type="text" name="full_name" class="form-input w-full" value="<?= htmlspecialchars($user['full_name']) ?>"
        required>
    </div>
    <div class="mb-4">
      <label class="block mb-1 font-semibold">Email</label>
      <input type="email" name="email" class="form-input w-full" value="<?= htmlspecialchars($user['email']) ?>"
        required>
    </div>
    <div class="mb-4">
      <label class="block mb-1 font-semibold">Status</label>
      <select name="status" class="form-select w-full" required>
        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <button type="submit" class="bg-primary text-white px-4 py-2 rounded">Save Changes</button>
    <a href="/HealthCenter/admin/health_worker_management.php" class="ml-4 text-primary">Cancel</a>
  </form>
</div>