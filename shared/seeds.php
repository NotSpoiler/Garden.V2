<?php // shared/seeds.php — Seed Viability & Expiry Tracker
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','warden','admin']);
$pdo = getPDO(); $user = currentUser();
$success = $error = '';

// Admin / plot_owner: donate seeds to bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['donate_seeds'])) {
    validateCsrf();
    $seedType   = trim($_POST['seed_type'] ?? '');
    $variety    = trim($_POST['variety'] ?? '');
    $qty        = (int)$_POST['quantity'];
    $storageDate = date('Y-m-d');
    $expiryDate  = date('Y-m-d', strtotime('+2 years'));

    if (!$seedType || $qty < 1) { $error = 'Seed type and quantity required.'; }
    else {
        $pdo->prepare("
            INSERT INTO seed_batches (seed_type, variety, quantity, storage_date, expiry_date, donated_by)
            VALUES (?,?,?,?,?,?)
        ")->execute([$seedType, $variety, $qty, $storageDate, $expiryDate, $user['id']]);
        // Award seed credits: 1 per packet donated
        $pdo->prepare("UPDATE users SET seed_credits = seed_credits + ? WHERE id = ?")
            ->execute([$qty, $user['id']]);
        logAudit('seeds_donated', 'seed_batches', (int)$pdo->lastInsertId(),
            "Donated $qty packets of $seedType");
        $success = "Donated $qty packet(s) of $seedType. You earned $qty seed credits.";
    }
}

// Withdraw seeds
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw_seeds'])) {
    validateCsrf();
    $batchId = (int)$_POST['batch_id'];
    $qty     = (int)$_POST['withdraw_qty'];

    // Check user has enough credits
    if ((int)$user['seed_credits'] < $qty) {
        $error = "Not enough seed credits. You have {$user['seed_credits']}, need $qty.";
    } else {
        $batch = $pdo->prepare("SELECT * FROM seed_batches WHERE id = ? AND status = 'valid'");
        $batch->execute([$batchId]); $batchRow = $batch->fetch();
        if (!$batchRow || $batchRow['quantity'] < $qty) {
            $error = 'Insufficient quantity available.';
        } else {
            $pdo->prepare("UPDATE seed_batches SET quantity = quantity - ? WHERE id = ?")
                ->execute([$qty, $batchId]);
            $pdo->prepare("UPDATE users SET seed_credits = seed_credits - ? WHERE id = ?")
                ->execute([$qty, $user['id']]);
            logAudit('seeds_withdrawn', 'seed_batches', $batchId,
                "Withdrew $qty packets of {$batchRow['seed_type']}");
            $success = "Withdrew $qty packet(s) of {$batchRow['seed_type']}.";
        }
    }
}

// Auto-flag and expire seeds (runs on every page load)
// Flag seeds within 30 days of expiry
$pdo->exec("
    UPDATE seed_batches
    SET status = 'flagged'
    WHERE status = 'valid'
      AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      AND expiry_date > CURDATE()
");
// Mark fully expired as unusable
$pdo->exec("
    UPDATE seed_batches SET status = 'unusable'
    WHERE expiry_date <= CURDATE() AND status != 'unusable'
");

$batches = $pdo->query("
    SELECT sb.*, u.full_name AS donor_name
    FROM seed_batches sb
    LEFT JOIN users u ON sb.donated_by = u.id
    ORDER BY sb.status ASC, sb.expiry_date ASC
")->fetchAll();

$pageTitle = 'Seed Bank';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex-between mb-2">
  <h1 class="page-title">🌱 Community Seed Bank</h1>
  <span class="text-muted">Your seed credits: <strong><?= (int)$user['seed_credits'] ?></strong></span>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<div class="grid-2 mb-3">
  <div class="card">
    <div class="card-title">Donate Seeds to Bank</div>
    <p class="text-muted mb-2">Earn 1 seed credit per packet donated.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Seed Type</label><input type="text" name="seed_type" required placeholder="e.g. Tomato, Basil"></div>
      <div class="form-group"><label>Variety (optional)</label><input type="text" name="variety" placeholder="e.g. Roma, Cherry"></div>
      <div class="form-group"><label>Quantity (packets)</label><input type="number" name="quantity" min="1" required></div>
      <button type="submit" name="donate_seeds" class="btn btn-primary">Donate to Bank</button>
    </form>
  </div>
  <div class="card">
    <div class="card-title">Seed Bank Status</div>
    <?php
    $valid    = count(array_filter($batches, fn($b) => $b['status'] === 'valid'));
    $flagged  = count(array_filter($batches, fn($b) => $b['status'] === 'flagged'));
    $unusable = count(array_filter($batches, fn($b) => $b['status'] === 'unusable'));
    ?>
    <div class="grid-3" style="gap:10px;">
      <div class="stat-card"><div class="stat-value"><?= $valid ?></div><div class="stat-label">Valid Batches</div></div>
      <div class="stat-card" style="border-left-color:#f4a261"><div class="stat-value"><?= $flagged ?></div><div class="stat-label">Near Expiry</div></div>
      <div class="stat-card" style="border-left-color:#e63946"><div class="stat-value"><?= $unusable ?></div><div class="stat-label">Unusable</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-title">Available Seed Batches</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Seed Type</th><th>Variety</th><th>Qty (pkts)</th><th>Stored</th><th>Expires</th><th>Status</th><th>Donate By</th><th>Withdraw</th></tr>
      </thead>
      <tbody>
        <?php foreach ($batches as $b): ?>
        <tr>
          <td><strong><?= clean($b['seed_type']) ?></strong></td>
          <td><?= clean($b['variety'] ?? '—') ?></td>
          <td><?= (int)$b['quantity'] ?></td>
          <td class="text-muted"><?= date('d M Y', strtotime($b['storage_date'])) ?></td>
          <td class="text-muted"><?= date('d M Y', strtotime($b['expiry_date'])) ?></td>
          <td><span class="status status-<?= $b['status'] ?>"><?= $b['status'] ?></span></td>
          <td class="text-muted"><?= clean($b['donor_name'] ?? 'Unknown') ?></td>
          <td>
            <?php if ($b['status'] !== 'unusable' && $b['quantity'] > 0): ?>
            <form method="POST" style="display:flex;gap:4px;">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="batch_id"   value="<?= $b['id'] ?>">
              <input type="number" name="withdraw_qty" value="1" min="1" max="<?= $b['quantity'] ?>"
                     style="width:50px;padding:4px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
              <button type="submit" name="withdraw_seeds" class="btn btn-primary btn-sm">Get</button>
            </form>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
