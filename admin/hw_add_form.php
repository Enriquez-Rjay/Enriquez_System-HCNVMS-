<?php
// Add Health Worker Form
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-lg shadow">
  <h2 class="text-2xl font-bold mb-6">Add New Health Worker</h2>
  <form method="post" action="/HealthCenter/admin/health_worker_management.php?action=add_submit">
    <div class="mb-4">
      <label class="block mb-1 font-semibold">Full Name</label>
      <input type="text" name="full_name" class="form-input w-full" required>
    </div>
    <div class="mb-4">
      <label class="block mb-1 font-semibold">Email</label>
      <input type="email" name="email" class="form-input w-full" required>
    </div>
    <div class="mb-4">
      <label class="block mb-1 font-semibold">Password</label>
      <input type="password" name="password" class="form-input w-full" required>
    </div>
    <button type="submit" class="bg-primary text-white px-4 py-2 rounded">Add Health Worker</button>
    <a href="/HealthCenter/admin/health_worker_management.php" class="ml-4 text-primary">Cancel</a>
  </form>
</div>