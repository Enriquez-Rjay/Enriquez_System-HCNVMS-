<?php
// Add Vaccine Batch Form
$mysqli = require __DIR__ . '/../config/db.php';
$vaccines = $mysqli->query("SELECT id, name FROM vaccines ORDER BY name");
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-xl shadow-lg border border-border-light">
  <h2 class="text-3xl font-extrabold mb-6 text-primary">Add New Vaccine Batch</h2>
  <form method="post" action="/HealthCenter/admin/vaccine_inventory.php?action=add_submit" class="space-y-5">
    <div>
      <label class="block mb-1 font-semibold text-sm">Vaccine</label>
      <select name="vaccine_id" class="form-select w-full rounded-lg border border-border-light" required>
        <option value="">Select vaccine...</option>
        <?php if ($vaccines) {
          while ($v = $vaccines->fetch_assoc()) { ?>
            <option value="<?= (int) $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
          <?php }
        } ?>
      </select>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Batch Number</label>
      <input type="text" name="batch_number" class="form-input w-full rounded-lg border border-border-light" required>
    </div>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block mb-1 font-semibold text-sm">Quantity Received</label>
        <input type="number" name="quantity_received" min="0"
          class="form-input w-full rounded-lg border border-border-light" required>
      </div>
      <div>
        <label class="block mb-1 font-semibold text-sm">Quantity Available</label>
        <input type="number" name="quantity_available" min="0"
          class="form-input w-full rounded-lg border border-border-light" required>
      </div>
    </div>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block mb-1 font-semibold text-sm">Expiry Date</label>
        <input type="date" name="expiry_date" class="form-input w-full rounded-lg border border-border-light" required>
      </div>
      <div>
        <label class="block mb-1 font-semibold text-sm">Received Date</label>
        <input type="date" name="received_at" class="form-input w-full rounded-lg border border-border-light" required>
      </div>
    </div>
    <div class="flex gap-3 pt-2">
      <button type="submit"
        class="bg-primary text-white px-5 py-2 rounded-lg font-semibold shadow hover:bg-primary/90">Save Batch</button>
      <button type="button"
        class="modal-close text-primary font-semibold px-5 py-2 rounded-lg hover:underline">Cancel</button>
    </div>
  </form>
</div>