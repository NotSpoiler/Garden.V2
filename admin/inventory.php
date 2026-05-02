<?php
// admin/inventory.php — Consumable Inventory Monitor (FR 24-25)
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$pdo = getPDO();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    validateCsrf();
    $name   = trim($_POST['name'] ?? '');
    $unit   = trim($_POST['unit'] ?? 'kg');
    $stock  = (float)$_POST['stock_level'];
    $thresh = (float)$_POST['reorder_threshold'];
    if (!$name) { $error = 'Item name required.'; }
    else {
        $pdo->prepare("INSERT INTO consumable_items (name,unit,stock_level,reorder_threshold) VALUES(?,?,?,?)")
            ->execute([$name, $unit, $stock, $thresh]);
        logAudit('consumable_added', 'consumable_items', (int)$pdo->lastInsertId(), "Added: $name");
        $success = "Item '$name' added.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    validateCsrf();
    $itemId   = (int)$_POST['item_id'];
    $newStock = (float)$_POST['new_stock'];
    $pdo->prepare("UPDATE consumable_items SET stock_level = ? WHERE id = ?")
        ->execute([$newStock, $itemId]);

    // Check if now below threshold — if so notify admin again
    $item = $pdo->prepare("SELECT * FROM consumable_items WHERE id = ?");
    $item->execute([$itemId]);
    $row = $item->fetch();
    if ($row && $row['stock_level'] <= $row['reorder_threshold']) {
        sendNotification((int)$_SESSION['user_id'], 'low_stock',
            "⚠️ {$row['name']} is at or below reorder threshold ({$row['reorder_threshold']} {$row['unit']}).");
    }
    logAudit('stock_updated', 'consumable_items', $itemId, "Stock updated to $newStock");
    $success = 'Stock level updated.';
}

$items = $pdo->query("SELECT * FROM consumable_items ORDER BY name")->fetchAll();
$pageTitle = 'Consumable Inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex-between mb-2">
  <h1 class="page-title">Consumable Inventory</h1>
  <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-secondary btn-sm">← Back</a>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<div class="grid-2">
  <div class="card">
    <div class="card-title">Add Consumable Item</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Item Name</label><input type="text" name="name" required placeholder="Organic Fertilizer"></div>
      <div class="form-group"><label>Unit</label><input type="text" name="unit" value="kg"></div>
      <div class="form-group"><label>Initial Stock</label><input type="number" name="stock_level" step="0.01" value="0"></div>
      <div class="form-group"><label>Reorder Threshold</label><input type="number" name="reorder_threshold" step="0.01" value="10"></div>
      <button type="submit" name="add_item" class="btn btn-primary">Add Item</button>
    </form>
  </div>
  <div class="card">
    <div class="card-title">Current Stock Levels</div>
    <?php foreach ($items as $item): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e9ecef;">
      <div>
        <strong><?= clean($item['name']) ?></strong>
        <span class="text-muted" style="margin-left:8px;"><?= $item['stock_level'] ?> <?= clean($item['unit']) ?></span>
        <?php if ($item['stock_level'] <= $item['reorder_threshold']): ?>
          <span class="status status-warning" style="margin-left:8px;">Low Stock</span>
        <?php endif; ?>
      </div>
      <form method="POST" style="display:flex;gap:6px;">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
        <input type="number" name="new_stock" value="<?= $item['stock_level'] ?>" step="0.01" style="width:80px;padding:4px 8px;border:1px solid #ced4da;border-radius:6px;">
        <button type="submit" name="update_stock" class="btn btn-primary btn-sm">Update</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
