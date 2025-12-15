<?php
// Add User Form
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-xl shadow-lg border border-border-light">
  <h2 class="text-3xl font-extrabold mb-6 text-primary">Add New User</h2>
  <form method="post" action="/HealthCenter/admin/user_management.php?action=add_submit" class="space-y-5">
    <div>
      <label class="block mb-1 font-semibold text-sm">Username</label>
      <input type="text" name="username" class="form-input w-full rounded-lg border border-border-light" required>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Full Name</label>
      <input type="text" name="full_name" class="form-input w-full rounded-lg border border-border-light" required>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Email</label>
      <input type="email" name="email" class="form-input w-full rounded-lg border border-border-light" required>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Role</label>
      <select name="role" class="form-select w-full rounded-lg border border-border-light" required>
        <option value="admin">Admin</option>
        <option value="health_worker">Health Worker</option>
        <option value="patient">Patient</option>
      </select>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Password</label>
      <input type="password" name="password" class="form-input w-full rounded-lg border border-border-light" required>
    </div>
    <div class="flex gap-3 pt-2">
      <button type="submit"
        class="bg-primary text-white px-5 py-2 rounded-lg font-semibold shadow hover:bg-primary/90">Add User</button>
      <a href="/HealthCenter/admin/user_management.php"
        class="text-primary font-semibold px-5 py-2 rounded-lg hover:underline">Cancel</a>
    </div>
  </form>
</div>