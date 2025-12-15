<?php
// Edit User Form
$mysqli = require __DIR__ . '/../config/db.php';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$user = $mysqli->query("SELECT * FROM users WHERE id=$id")->fetch_assoc();
if (!$user) {
  echo '<div class="p-8">User not found.</div>';
  return;
}
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-xl shadow-lg border border-border-light">
  <h2 class="text-3xl font-extrabold mb-6 text-primary">Edit User</h2>
  <form method="post" action="/HealthCenter/admin/user_management.php?action=edit_submit&id=<?= $id ?>"
    class="space-y-5">
    <div>
      <label class="block mb-1 font-semibold text-sm">Full Name</label>
      <input type="text" name="full_name" class="form-input w-full rounded-lg border border-border-light"
        value="<?= htmlspecialchars($user['full_name']) ?>" required>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Email</label>
      <input type="email" name="email" class="form-input w-full rounded-lg border border-border-light"
        value="<?= htmlspecialchars($user['email']) ?>" required>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Role</label>
      <select name="role" class="form-select w-full rounded-lg border border-border-light" required>
        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
        <option value="health_worker" <?= $user['role'] === 'health_worker' ? 'selected' : '' ?>>Health Worker</option>
        <option value="patient" <?= $user['role'] === 'patient' ? 'selected' : '' ?>>Patient</option>
      </select>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Status</label>
      <select name="status" class="form-select w-full rounded-lg border border-border-light" required>
        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="flex gap-3 pt-2">
      <button type="submit"
        class="bg-primary text-white px-5 py-2 rounded-lg font-semibold shadow hover:bg-primary/90">Save
        Changes</button>
      <a href="/HealthCenter/admin/user_management.php"
        class="text-primary font-semibold px-5 py-2 rounded-lg hover:underline">Cancel</a>
    </div>
  </form>
</div>