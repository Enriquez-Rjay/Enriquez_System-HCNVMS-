<?php
// Edit Vaccine Batch Form
$mysqli = require __DIR__ . '/../config/db.php';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$batch = $mysqli->query("SELECT * FROM vaccine_batches WHERE id=$id")->fetch_assoc();
if (!$batch) {
  echo '<div class="p-8">Batch not found.</div>';
  return;
}
$vaccines = $mysqli->query("SELECT id, name FROM vaccines ORDER BY name");
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-xl shadow-lg border border-border-light">
  <h2 class="text-3xl font-extrabold mb-6 text-primary">Edit Vaccine Batch</h2>
  <form method="post" action="/HealthCenter/admin/vaccine_inventory.php?action=edit_submit&id=<?= $id ?>"
    class="space-y-5">
    <div>
      <label class="block mb-1 font-semibold text-sm">Vaccine</label>
      <select name="vaccine_id" class="form-select w-full rounded-lg border border-border-light" required>
        <?php if ($vaccines) {
          while ($v = $vaccines->fetch_assoc()) { ?>
            <option value="<?= (int) $v['id'] ?>" <?= $batch['vaccine_id'] == $v['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($v['name']) ?></option>
          <?php }
        } ?>
      </select>
    </div>
    <div>
      <label class="block mb-1 font-semibold text-sm">Batch Number</label>
      <input type="text" name="batch_number" class="form-input w-full rounded-lg border border-border-light"
        value="<?= htmlspecialchars($batch['batch_number']) ?>" required>
    </div>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block mb-1 font-semibold text-sm">Quantity Received</label>
        <input type="number" name="quantity_received" min="0"
          class="form-input w-full rounded-lg border border-border-light"
          value="<?= (int) $batch['quantity_received'] ?>" required>
      </div>
      <div>
        <label class="block mb-1 font-semibold text-sm">Quantity Available</label>
        <input type="number" name="quantity_available" min="0"
          class="form-input w-full rounded-lg border border-border-light"
          value="<?= (int) $batch['quantity_available'] ?>" required>
      </div>
    </div>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block mb-1 font-semibold text-sm">Expiry Date</label>
        <input type="date" name="expiry_date" class="form-input w-full rounded-lg border border-border-light"
          value="<?= htmlspecialchars($batch['expiry_date']) ?>" required>
      </div>
      <div>
        <label class="block mb-1 font-semibold text-sm">Received Date</label>
        <input type="date" name="received_at" class="form-input w-full rounded-lg border border-border-light"
          value="<?= htmlspecialchars(substr($batch['received_at'], 0, 10)) ?>" required>
      </div>
    </div>
    <div class="flex gap-3 pt-2">
      <button type="submit"
        class="bg-primary text-white px-5 py-2 rounded-lg font-semibold shadow hover:bg-primary/90">Save
        Changes</button>
      <button type="button"
        class="modal-close text-primary font-semibold px-5 py-2 rounded-lg hover:underline">Cancel</button>
    </div>
  </form>
</div>