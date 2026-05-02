<?php // plot_owner/renew_lease.php — Lease Renewal
require_once __DIR__ . '/../includes/functions.php';
requireRole(['plot_owner','admin']);
$pdo = getPDO(); $user = currentUser();
$success = $error = '';

// Fetch active lease
$leaseStmt = $pdo->prepare("
    SELECT l.*, p.plot_code, p.area_sqm, p.soil_tier, mt.discount_pct
    FROM leases l
    JOIN plots p ON l.plot_id = p.id
    JOIN users u ON l.user_id = u.id
    LEFT JOIN membership_tiers mt ON u.membership_tier_id = mt.id
    WHERE l.user_id = ? AND l.status = 'active' LIMIT 1
");
$leaseStmt->execute([$user['id']]); $lease = $leaseStmt->fetch();

if (!$lease) { redirect(APP_URL . '/plot_owner/dashboard.php'); }

// Discounted fee
$discount    = (float)$lease['discount_pct'];
$baseFee     = (float)$lease['rental_fee'];
$discounted  = round($baseFee * (1 - $discount / 100), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew'])) {
    validateCsrf();
    $months = (int)($_POST['months'] ?? 12);
    $newEnd = date('Y-m-d', strtotime($lease['end_date'] . " +$months months"));
    $totalFee = round($discounted * $months, 2);

    $pdo->prepare("
        UPDATE leases
        SET end_date = ?, status = 'active', payment_status = 'paid'
        WHERE id = ?
    ")->execute([$newEnd, $lease['id']]);

    // Increment rental_months on user
    $pdo->prepare("UPDATE users SET rental_months = rental_months + ? WHERE id = ?")
        ->execute([$months, $user['id']]);

    recalculateMembershipTier($user['id']);
    recalculateWaitlistPriority($user['id']);

    sendNotification($user['id'], 'lease_renewed',
        "Your lease for plot {$lease['plot_code']} has been renewed until $newEnd. Total paid: EGP $totalFee.");
    logAudit('lease_renewed', 'leases', $lease['id'],
        "Renewed {$months}m. New end: $newEnd. Fee: EGP $totalFee");
    $success = "Lease renewed until $newEnd! Total: EGP $totalFee.";
}

$pageTitle = 'Renew Lease';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex-between mb-2">
  <h1 class="page-title">🔄 Renew Lease — Plot <?= clean($lease['plot_code']) ?></h1>
  <a href="<?= APP_URL ?>/plot_owner/dashboard.php" class="btn btn-secondary btn-sm">← Back</a>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>

<div class="card" style="max-width:480px">
  <div class="card-title">Lease Details</div>
  <table style="width:100%;font-size:14px;border-collapse:collapse;">
    <tr><td style="padding:6px 0;color:#6c757d">Plot</td><td><strong><?= clean($lease['plot_code']) ?></strong> (<?= $lease['area_sqm'] ?> m²)</td></tr>
    <tr><td style="padding:6px 0;color:#6c757d">Soil Tier</td><td><?= clean($lease['soil_tier']) ?></td></tr>
    <tr><td style="padding:6px 0;color:#6c757d">Current Expiry</td><td><?= date('d M Y', strtotime($lease['end_date'])) ?></td></tr>
    <tr><td style="padding:6px 0;color:#6c757d">Base Monthly Fee</td><td>EGP <?= number_format($baseFee, 2) ?></td></tr>
    <?php if ($discount > 0): ?>
    <tr><td style="padding:6px 0;color:#2d6a4f">Your Discount (<?= $user['tier_name'] ?>)</td><td style="color:#2d6a4f">— <?= $discount ?>%</td></tr>
    <tr><td style="padding:6px 0;color:#6c757d">Discounted Monthly Fee</td><td><strong>EGP <?= number_format($discounted, 2) ?></strong></td></tr>
    <?php endif; ?>
  </table>
  <hr class="divider">
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="form-group">
      <label>Renewal Duration</label>
      <select name="months" id="monthSel" onchange="updateTotal()">
        <option value="3">3 months — EGP <?= number_format($discounted * 3, 2) ?></option>
        <option value="6">6 months — EGP <?= number_format($discounted * 6, 2) ?></option>
        <option value="12" selected>12 months — EGP <?= number_format($discounted * 12, 2) ?></option>
      </select>
    </div>
    <p>Total due: <strong id="totalDisplay">EGP <?= number_format($discounted * 12, 2) ?></strong></p>
    <button type="submit" name="renew" class="btn btn-primary mt-1">Confirm Renewal & Payment</button>
  </form>
</div>

<script>
const fee = <?= $discounted ?>;
function updateTotal() {
    const months = document.getElementById('monthSel').value;
    document.getElementById('totalDisplay').textContent = 'EGP ' + (fee * months).toFixed(2);
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
