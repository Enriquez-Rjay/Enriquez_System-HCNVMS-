<?php
// View Vaccine Batch Receipt / Details
$mysqli = require __DIR__ . '/../config/db.php';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$sql = "SELECT b.*, v.name AS vaccine_name FROM vaccine_batches b JOIN vaccines v ON b.vaccine_id = v.id WHERE b.id=$id";
$batch = $mysqli->query($sql)->fetch_assoc();
if (!$batch) {
  echo '<div class="p-8">Batch not found.</div>';
  return;
}
?>
<div class="max-w-lg mx-auto p-8 bg-white rounded-xl shadow-lg border border-border-light">
  <h2 class="text-3xl font-extrabold mb-6 text-primary">Vaccine Batch Details</h2>
  <ul class="mb-6 space-y-2 text-sm">
    <li><span class="font-semibold">Vaccine:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($batch['vaccine_name']) ?></span>
    </li>
    <li><span class="font-semibold">Batch Number:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($batch['batch_number']) ?></span>
    </li>
    <li><span class="font-semibold">Quantity Received:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= (int) $batch['quantity_received'] ?></span>
    </li>
    <li><span class="font-semibold">Quantity Available:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= (int) $batch['quantity_available'] ?></span>
    </li>
    <li><span class="font-semibold">Expiry Date:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($batch['expiry_date']) ?></span>
    </li>
    <li><span class="font-semibold">Received Date:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($batch['received_at']) ?></span>
    </li>
    <li><span class="font-semibold">Storage Location:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($batch['storage_location']) ?></span>
    </li>
    <li><span class="font-semibold">Created At:</span> <span
        class="ml-2 text-text-secondary-light dark:text-text-secondary-dark"><?= htmlspecialchars($batch['created_at']) ?></span>
    </li>
  </ul>
  <div class="flex justify-end">
    <button type="button"
      class="modal-close text-primary font-semibold px-5 py-2 rounded-lg hover:underline">Close</button>
  </div>
</div>