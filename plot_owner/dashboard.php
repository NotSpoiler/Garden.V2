<?php
// plot_owner/dashboard.php — Plot Owner's dedicated dashboard
// Shows lease status, soil health, pest reports, compost log
require_once __DIR__ . '/../includes/functions.php';
requireRole(['plot_owner','admin']);
$pdo  = getPDO();
$user = currentUser();

// Fetch active lease and plot
$leaseStmt = $pdo->prepare("
    SELECT l.*, p.plot_code, p.area_sqm, p.sunlight, p.soil_tier,
           p.compliance_status, p.id AS plot_id, p.grid_x, p.grid_y
    FROM leases l
    JOIN plots p ON l.plot_id = p.id
    WHERE l.user_id = ? AND l.status = 'active'
    LIMIT 1
");
$leaseStmt->execute([$user['id']]);
$lease = $leaseStmt->fetch();

if (!$lease) {
    // No active lease — shouldn't happen for plot_owner, but handle gracefully
    redirect(APP_URL . '/member/dashboard.php');
}

// Soil health records (last 8)
$soilRecords = $pdo->prepare("
    SELECT * FROM soil_health_records WHERE plot_id = ?
    ORDER BY recorded_at DESC LIMIT 8
");
$soilRecords->execute([$lease['plot_id']]);
$soilHistory = $soilRecords->fetchAll();

// Last inspection
$lastInspection = $pdo->prepare("
    SELECT ci.*, u.full_name AS warden_name
    FROM compliance_inspections ci
    JOIN users u ON ci.warden_id = u.id
    WHERE ci.plot_id = ? ORDER BY ci.inspected_at DESC LIMIT 1
");
$lastInspection->execute([$lease['plot_id']]);
$inspection = $lastInspection->fetch();

// Compost contributions (this month)
$compostStmt = $pdo->prepare("
    SELECT SUM(quantity_kg) AS total_kg
    FROM compost_contributions
    WHERE user_id = ? AND MONTH(contributed_at) = MONTH(NOW())
");
$compostStmt->execute([$user['id']]);
$monthCompost = (float)$compostStmt->fetchColumn();

// ── POST handlers ────────────────────────────────────────────

// Log soil health event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_soil'])) {
    validateCsrf();
    $type   = $_POST['event_type'] ?? 'other';
    $pH     = !empty($_POST['ph_level']) ? (float)$_POST['ph_level'] : null;
    $fertT  = trim($_POST['fertilizer_type'] ?? '');
    $fertQ  = !empty($_POST['fertilizer_qty']) ? (float)$_POST['fertilizer_qty'] : null;
    $crop   = trim($_POST['crop_name'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');

    $pdo->prepare("
        INSERT INTO soil_health_records
        (plot_id, user_id, event_type, fertilizer_type, fertilizer_qty, ph_level, crop_name, notes)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([$lease['plot_id'], $user['id'], $type, $fertT, $fertQ, $pH, $crop, $notes]);

    // Check pH alert (safe range 6.0 – 7.5 for most vegetables)
    if ($pH && ($pH < 6.0 || $pH > 7.5)) {
        sendNotification($user['id'], 'soil_alert',
            "⚠️ pH reading of $pH is outside the safe range (6.0–7.5). Consider adjusting your soil.");
    }

    logAudit('soil_record_added', 'soil_health_records', 0,
        "Soil event logged for plot {$lease['plot_code']}");
    header("Location: " . APP_URL . "/plot_owner/dashboard.php?soil=ok");
    exit;
}

// Submit pest report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_pest'])) {
    validateCsrf();
    $pestType = trim($_POST['pest_type'] ?? '');
    $severity = $_POST['severity'] ?? 'medium';
    $isTrans  = (int)($_POST['is_transmissible'] ?? 0);
    $notes    = trim($_POST['pest_notes'] ?? '');

    if ($pestType) {
        $pdo->prepare("
            INSERT INTO infection_reports (plot_id, user_id, pest_type, severity, is_transmissible, notes)
            VALUES (?,?,?,?,?,?)
        ")->execute([$lease['plot_id'], $user['id'], $pestType, $severity, $isTrans, $notes]);

        // If transmissible — alert all adjacent plot owners
        if ($isTrans) {
            // Find plots adjacent (within ±1 in grid)
            $adjacentStmt = $pdo->prepare("
                SELECT p.id, l2.user_id
                FROM plots p
                JOIN leases l2 ON l2.plot_id = p.id AND l2.status = 'active'
                WHERE p.id != ?
                  AND ABS(p.grid_x - ?) <= 1
                  AND ABS(p.grid_y - ?) <= 1
            ");
            $adjacentStmt->execute([$lease['plot_id'], $lease['grid_x'], $lease['grid_y']]);
            $neighbors = $adjacentStmt->fetchAll();

            foreach ($neighbors as $nb) {
                sendNotification($nb['user_id'], 'pest_alert',
                    "⚠️ Pest/disease alert: '$pestType' reported on an adjacent plot ({$lease['plot_code']}). Please inspect your plot.");
            }

            $notifiedCount = count($neighbors);
            sendNotification($user['id'], 'pest_report_sent',
                "Your pest report for '$pestType' was submitted. $notifiedCount adjacent plot owner(s) were notified.");
        }

        logAudit('infection_reported', 'infection_reports', 0,
            "Pest '$pestType' reported on plot {$lease['plot_code']}");
        header("Location: " . APP_URL . "/plot_owner/dashboard.php?pest=ok");
        exit;
    }
}

// Log compost contribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_compost'])) {
    validateCsrf();
    $wasteType = trim($_POST['waste_type'] ?? '');
    $qty       = (float)($_POST['quantity_kg'] ?? 0);
    if ($wasteType && $qty > 0) {
        $pdo->prepare("INSERT INTO compost_contributions (user_id, waste_type, quantity_kg) VALUES (?,?,?)")
            ->execute([$user['id'], $wasteType, $qty]);

        // Award community points (1 point per kg)
        $pointsToAdd = max(1, (int)$qty);
        $pdo->prepare("UPDATE users SET community_points = community_points + ? WHERE id = ?")
            ->execute([$pointsToAdd, $user['id']]);
        recalculateMembershipTier($user['id']);

        logAudit('compost_logged', 'compost_contributions', 0,
            "Compost: {$qty}kg of $wasteType");
        header("Location: " . APP_URL . "/plot_owner/dashboard.php?compost=ok");
        exit;
    }
}

$pageTitle = 'My Plot — ' . $lease['plot_code'];
require __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <h1 class="page-title">Plot <?= clean($lease['plot_code']) ?></h1>
  <a href="<?= APP_URL ?>/member/dashboard.php" class="btn btn-secondary btn-sm">← Dashboard</a>
</div>

<?php if (isset($_GET['soil'])):  ?><div class="alert alert-success">Soil event logged.</div><?php endif; ?>
<?php if (isset($_GET['pest'])):  ?><div class="alert alert-success">Pest report submitted.</div><?php endif; ?>
<?php if (isset($_GET['compost'])): ?><div class="alert alert-success">Compost contribution logged.</div><?php endif; ?>

<!-- Lease status card -->
<div class="grid-3 mb-3">
  <div class="stat-card">
    <div class="stat-value" style="font-size:22px"><?= clean($lease['plot_code']) ?></div>
    <div class="stat-label"><?= $lease['area_sqm'] ?> m² — <?= clean($lease['soil_tier']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-value" style="font-size:20px"><?= date('d M Y', strtotime($lease['end_date'])) ?></div>
    <div class="stat-label">Lease Expires
      <span class="status status-<?= $lease['status'] ?>" style="margin-left:6px"><?= $lease['status'] ?></span>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-value" style="font-size:20px">
      <span class="status status-<?= $lease['compliance_status'] ?>"><?= $lease['compliance_status'] ?></span>
    </div>
    <div class="stat-label">Compliance Status</div>
  </div>
</div>

<!-- Lease payment status -->
<?php if ($lease['payment_status'] !== 'paid'): ?>
<div class="alert alert-<?= $lease['payment_status'] === 'overdue' ? 'error' : 'warning' ?>">
  <?= $lease['payment_status'] === 'overdue' ? '⚠️ Your lease payment is OVERDUE.' : '💳 Lease payment pending.' ?>
  Monthly fee: EGP <?= number_format($lease['rental_fee'], 2) ?>
  <a href="<?= APP_URL ?>/plot_owner/renew_lease.php" class="btn btn-primary btn-sm" style="margin-left:12px">Pay Now</a>
</div>
<?php endif; ?>

<?php if ($inspection): ?>
<div class="alert alert-info">
  Last inspection by <?= clean($inspection['warden_name']) ?> on
  <?= date('d M Y', strtotime($inspection['inspected_at'])) ?>:
  <strong><?= $inspection['result_status'] ?></strong>
  <?php if ($inspection['notes']): ?> — "<?= clean($inspection['notes']) ?>"<?php endif; ?>
</div>
<?php endif; ?>

<div class="grid-2">
  <!-- Soil health log form -->
  <div class="card">
    <div class="card-title">🌱 Log Soil Health Event</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group">
        <label>Event Type</label>
        <select name="event_type" id="eventType" onchange="toggleSoilFields(this.value)">
          <option value="fertilizer">Fertilizer Application</option>
          <option value="ph_reading">pH Reading</option>
          <option value="crop_rotation">Crop Rotation</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div id="fieldFertilizer">
        <div class="form-group"><label>Fertilizer Type</label><input type="text" name="fertilizer_type" placeholder="e.g. Compost, NPK 20-20-20"></div>
        <div class="form-group"><label>Quantity (kg)</label><input type="number" name="fertilizer_qty" step="0.01"></div>
      </div>
      <div id="fieldPH" style="display:none">
        <div class="form-group"><label>pH Level</label><input type="number" name="ph_level" step="0.1" min="0" max="14" placeholder="e.g. 6.5"></div>
      </div>
      <div id="fieldCrop" style="display:none">
        <div class="form-group"><label>Crop Name</label><input type="text" name="crop_name" placeholder="e.g. Tomatoes"></div>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="notes" rows="2" placeholder="Additional observations…"></textarea></div>
      <button type="submit" name="log_soil" class="btn btn-primary">Log Event</button>
    </form>
  </div>

  <!-- Soil history -->
  <div class="card">
    <div class="card-title">Soil History</div>
    <?php if ($soilHistory): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Type</th><th>Details</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($soilHistory as $rec): ?>
          <tr>
            <td><span class="status status-active"><?= clean($rec['event_type']) ?></span></td>
            <td style="font-size:13px">
              <?php if ($rec['ph_level']): ?>pH <?= $rec['ph_level'] ?><?php endif; ?>
              <?php if ($rec['fertilizer_type']): ?><?= clean($rec['fertilizer_type']) ?><?php endif; ?>
              <?php if ($rec['crop_name']): ?><?= clean($rec['crop_name']) ?><?php endif; ?>
              <?php if ($rec['notes']): ?><br><span class="text-muted"><?= clean($rec['notes']) ?></span><?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:12px"><?= date('d M Y', strtotime($rec['recorded_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <p class="text-muted">No soil records yet.</p>
    <?php endif; ?>
  </div>

  <!-- Pest report form -->
  <div class="card">
    <div class="card-title">🐛 Report Pest / Disease</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Pest / Disease Name</label><input type="text" name="pest_type" required placeholder="e.g. Potato Blight, Aphids"></div>
      <div class="form-group">
        <label>Severity</label>
        <select name="severity">
          <option value="low">Low</option>
          <option value="medium" selected>Medium</option>
          <option value="high">High</option>
        </select>
      </div>
      <div class="form-group">
        <label>
          <input type="checkbox" name="is_transmissible" value="1">
          This is highly transmissible — alert my neighbors
        </label>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="pest_notes" rows="2" placeholder="Description of symptoms…"></textarea></div>
      <button type="submit" name="report_pest" class="btn btn-danger">Submit Report</button>
    </form>
  </div>

  <!-- Compost contribution -->
  <div class="card">
    <div class="card-title">🍂 Log Compost Contribution</div>
    <p class="text-muted mb-1">This month: <strong><?= $monthCompost ?> kg</strong> contributed. Earn 1 community point per kg.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Waste Type</label><input type="text" name="waste_type" required placeholder="e.g. Vegetable scraps, Leaves"></div>
      <div class="form-group"><label>Quantity (kg)</label><input type="number" name="quantity_kg" step="0.1" min="0.1" required></div>
      <button type="submit" name="log_compost" class="btn btn-primary">Log Contribution</button>
    </form>
  </div>
</div>

<script>
function toggleSoilFields(type) {
    document.getElementById('fieldFertilizer').style.display = type === 'fertilizer' ? 'block' : 'none';
    document.getElementById('fieldPH').style.display         = type === 'ph_reading'  ? 'block' : 'none';
    document.getElementById('fieldCrop').style.display       = type === 'crop_rotation' ? 'block' : 'none';
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
