<?php
// ============================================================
// admin/plots.php
// Implements FR 1-4 (Geospatial Plot Mapping) from the admin side
// Admin creates plots, assigns members, views the grid map
//
// HOW THE PLOT MAP WORKS:
// Each plot has a grid_x and grid_y coordinate.
// We build an 8x8 grid and colour each cell by plot status.
// Clicking a cell shows the plot's details (JS).
// ============================================================
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pdo = getPDO();
$success = $error = '';

// ── Create new plot ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_plot'])) {
    validateCsrf();
    $code      = strtoupper(trim($_POST['plot_code'] ?? ''));
    $area      = (float)($_POST['area_sqm'] ?? 0);
    $dims      = trim($_POST['dimensions'] ?? '');
    $sun       = $_POST['sunlight'] ?? 'full_sun';
    $soilTier  = $_POST['soil_tier'] ?? 'ground';
    $gridX     = (int)($_POST['grid_x'] ?? 0);
    $gridY     = (int)($_POST['grid_y'] ?? 0);

    if (!$code || $area <= 0) {
        $error = 'Plot code and area are required.';
    } else {
        try {
            $pdo->prepare("
                INSERT INTO plots (plot_code, area_sqm, dimensions, sunlight, soil_tier, grid_x, grid_y)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$code, $area, $dims, $sun, $soilTier, $gridX, $gridY]);

            logAudit('plot_created', 'plots', (int)$pdo->lastInsertId(),
                "New plot created: $code");
            $success = "Plot $code created successfully.";
        } catch (PDOException $e) {
            $error = 'Plot code already exists.';
        }
    }
}

// ── Assign plot to member (creates lease) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_plot'])) {
    validateCsrf();
    $plotId   = (int)$_POST['plot_id'];
    $userId   = (int)$_POST['user_id'];
    $fee      = (float)$_POST['rental_fee'];
    $months   = (int)$_POST['lease_months'];

    $startDate = date('Y-m-d');
    $endDate   = date('Y-m-d', strtotime("+$months months"));

    $pdo->beginTransaction();
    try {
        // Create the lease
        $pdo->prepare("
            INSERT INTO leases (user_id, plot_id, start_date, end_date, rental_fee, status, payment_status)
            VALUES (?, ?, ?, ?, ?, 'active', 'pending')
        ")->execute([$userId, $plotId, $startDate, $endDate, $fee]);

        // Mark plot as occupied
        $pdo->prepare("UPDATE plots SET status = 'occupied' WHERE id = ?")
            ->execute([$plotId]);

        // Upgrade user role to plot_owner if not already admin/warden
        $user = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $user->execute([$userId]);
        $userRow = $user->fetch();
        if (!in_array($userRow['role'], ['admin','warden'])) {
            $gateCode = strtoupper(substr(md5(uniqid()), 0, 6));
            $pdo->prepare("UPDATE users SET role = 'plot_owner', gate_code = ? WHERE id = ?")
                ->execute([$gateCode, $userId]);
            sendNotification($userId, 'plot_assigned',
                "You have been assigned plot #$plotId! Your gate code: $gateCode. Lease runs until $endDate.");
        }

        // Remove from waitlist if present
        $pdo->prepare("UPDATE waitlist SET status = 'assigned' WHERE user_id = ?")
            ->execute([$userId]);

        $pdo->commit();
        logAudit('plot_assigned', 'leases', (int)$pdo->lastInsertId(),
            "Plot $plotId assigned to user $userId");
        $success = 'Plot assigned and lease created successfully.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Failed to assign plot. Please try again.';
    }
}

// ── Data for the page ────────────────────────────────────────
$plots    = $pdo->query("
    SELECT p.*, l.id AS lease_id, u.full_name AS owner_name
    FROM plots p
    LEFT JOIN leases l  ON l.plot_id = p.id AND l.status = 'active'
    LEFT JOIN users u   ON l.user_id = u.id
    ORDER BY p.plot_code
")->fetchAll();

// Build an 8x8 grid array (max 64 plots on map)
$grid = array_fill(0, 8, array_fill(0, 8, null));
foreach ($plots as $plot) {
    $x = min((int)$plot['grid_x'], 7);
    $y = min((int)$plot['grid_y'], 7);
    $grid[$y][$x] = $plot;
}

// Members eligible for plot assignment (active, no current active lease)
$eligibleMembers = $pdo->query("
    SELECT u.id, u.full_name, u.email
    FROM users u
    WHERE u.is_active = 1
      AND u.role IN ('member','plot_owner')
      AND u.id NOT IN (SELECT user_id FROM leases WHERE status = 'active')
    ORDER BY u.full_name
")->fetchAll();

$availablePlots = array_filter($plots, fn($p) => $p['status'] === 'available');

$pageTitle = 'Plot Management';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <h1 class="page-title">Plot Management</h1>
  <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-secondary btn-sm">← Back</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<!-- Visual map -->
<div class="card mb-3">
  <div class="card-title">Plot Map</div>
  <div class="plot-grid">
    <?php for ($row = 0; $row < 8; $row++): ?>
      <?php for ($col = 0; $col < 8; $col++): ?>
        <?php $cell = $grid[$row][$col]; ?>
        <?php if ($cell): ?>
          <div class="plot-cell plot-<?= $cell['status'] ?>"
               title="<?= clean($cell['plot_code']) ?> — <?= clean($cell['status']) ?><?= $cell['owner_name'] ? ' — '.$cell['owner_name'] : '' ?>">
            <?= clean($cell['plot_code']) ?>
          </div>
        <?php else: ?>
          <div class="plot-cell" style="background:#e9ecef;opacity:.4;" title="Empty grid slot"></div>
        <?php endif; ?>
      <?php endfor; ?>
    <?php endfor; ?>
  </div>
  <div class="mt-1" style="display:flex;gap:16px;font-size:12px;">
    <span><span style="display:inline-block;width:12px;height:12px;background:#74c69d;border-radius:2px;"></span> Available</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:#6b4226;border-radius:2px;"></span> Occupied</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:#f4a261;border-radius:2px;"></span> Maintenance</span>
  </div>
</div>

<div class="grid-2">
  <!-- Create plot form -->
  <div class="card">
    <div class="card-title">Add New Plot</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group">
        <label>Plot Code</label>
        <input type="text" name="plot_code" placeholder="A-01" required>
      </div>
      <div class="form-group">
        <label>Area (m²)</label>
        <input type="number" name="area_sqm" step="0.01" placeholder="20.00" required>
      </div>
      <div class="form-group">
        <label>Dimensions</label>
        <input type="text" name="dimensions" placeholder="5m x 4m">
      </div>
      <div class="form-group">
        <label>Sunlight</label>
        <select name="sunlight">
          <option value="full_sun">Full Sun</option>
          <option value="partial_shade">Partial Shade</option>
          <option value="full_shade">Full Shade</option>
        </select>
      </div>
      <div class="form-group">
        <label>Soil Tier</label>
        <select name="soil_tier">
          <option value="ground">Ground Soil</option>
          <option value="standard_raised">Standard Raised</option>
          <option value="premium_raised">Premium Raised Bed</option>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group">
          <label>Grid X (0-7)</label>
          <input type="number" name="grid_x" min="0" max="7" value="0">
        </div>
        <div class="form-group">
          <label>Grid Y (0-7)</label>
          <input type="number" name="grid_y" min="0" max="7" value="0">
        </div>
      </div>
      <button type="submit" name="create_plot" class="btn btn-primary">Create Plot</button>
    </form>
  </div>

  <!-- Assign plot form -->
  <div class="card">
    <div class="card-title">Assign Plot to Member</div>
    <?php if (empty($availablePlots)): ?>
      <div class="alert alert-info">No available plots at this time.</div>
    <?php elseif (empty($eligibleMembers)): ?>
      <div class="alert alert-info">No eligible members for assignment.</div>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group">
        <label>Select Plot</label>
        <select name="plot_id" required>
          <?php foreach ($availablePlots as $p): ?>
            <option value="<?= $p['id'] ?>">
              <?= clean($p['plot_code']) ?> — <?= $p['area_sqm'] ?>m² (<?= $p['soil_tier'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Select Member</label>
        <select name="user_id" required>
          <?php foreach ($eligibleMembers as $m): ?>
            <option value="<?= $m['id'] ?>"><?= clean($m['full_name']) ?> — <?= clean($m['email']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Monthly Rental Fee (EGP)</label>
        <input type="number" name="rental_fee" step="0.01" placeholder="150.00" required>
      </div>
      <div class="form-group">
        <label>Lease Duration (months)</label>
        <input type="number" name="lease_months" min="1" max="24" value="12" required>
      </div>
      <button type="submit" name="assign_plot" class="btn btn-primary">Assign Plot & Create Lease</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Plots table -->
<div class="card">
  <div class="card-title">All Plots</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Code</th><th>Area</th><th>Sunlight</th><th>Soil</th><th>Status</th><th>Owner</th><th>Compliance</th></tr>
      </thead>
      <tbody>
        <?php foreach ($plots as $p): ?>
        <tr>
          <td><strong><?= clean($p['plot_code']) ?></strong></td>
          <td><?= $p['area_sqm'] ?> m²</td>
          <td><?= clean($p['sunlight']) ?></td>
          <td><?= clean($p['soil_tier']) ?></td>
          <td><span class="status status-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
          <td><?= $p['owner_name'] ? clean($p['owner_name']) : '<span class="text-muted">—</span>' ?></td>
          <td><span class="status status-<?= $p['compliance_status'] ?>"><?= $p['compliance_status'] ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
