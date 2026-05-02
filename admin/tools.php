<?php
// ============================================================
// admin/tools.php
// FR: Tool Maintenance State Machine
//
// VALID STATE TRANSITIONS (enforced here):
//   available     → checked_out  (when member checks out)
//   checked_out   → available    (on clean return)
//   checked_out   → in_repair    (damage reported)
//   in_repair     → available    (after repair)
//   any           → decommissioned (admin only)
//
// WHY STATE MACHINE:
// We use an allowed-transitions map. Before any status change,
// we check if the transition is in the map. If not, we reject it.
// This prevents invalid states like available → in_repair directly.
// ============================================================
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pdo = getPDO();
$success = $error = '';

// Allowed state transitions — key = current state, value = allowed next states
const TOOL_TRANSITIONS = [
    'available'       => ['checked_out', 'decommissioned'],
    'checked_out'     => ['available', 'in_repair', 'decommissioned'],
    'in_repair'       => ['available', 'decommissioned'],
    'decommissioned'  => [],   // terminal state — no transitions out
];

// ── Add new tool ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tool'])) {
    validateCsrf();
    $name      = trim($_POST['name'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $threshold = (float)($_POST['maintenance_threshold'] ?? 100);

    if (!$name) {
        $error = 'Tool name is required.';
    } else {
        $pdo->prepare("INSERT INTO tools (name, description, maintenance_threshold) VALUES (?,?,?)")
            ->execute([$name, $desc, $threshold]);
        $toolId = (int)$pdo->lastInsertId();
        logAudit('tool_created', 'tools', $toolId, "New tool added: $name");
        $success = "Tool '$name' added.";
    }
}

// ── Change tool state ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_state'])) {
    validateCsrf();
    $toolId   = (int)$_POST['tool_id'];
    $newState = $_POST['new_state'] ?? '';

    // Fetch current state
    $stmt = $pdo->prepare("SELECT status, name FROM tools WHERE id = ?");
    $stmt->execute([$toolId]);
    $tool = $stmt->fetch();

    if (!$tool) {
        $error = 'Tool not found.';
    } elseif (!in_array($newState, TOOL_TRANSITIONS[$tool['status']] ?? [])) {
        // Transition not allowed
        $error = "Cannot transition '{$tool['name']}' from {$tool['status']} to $newState.";
    } else {
        $pdo->prepare("UPDATE tools SET status = ? WHERE id = ?")
            ->execute([$newState, $toolId]);

        // If coming back to available from repair, reset usage if maintenance was done
        if ($newState === 'available' && $tool['status'] === 'in_repair') {
            $pdo->prepare("UPDATE tools SET usage_hours = 0 WHERE id = ?")
                ->execute([$toolId]);
        }

        logAudit('tool_state_changed', 'tools', $toolId,
            "Tool '{$tool['name']}' state: {$tool['status']} → $newState");
        $success = "Tool state updated to $newState.";
    }
}

// ── Resolve damage report ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_damage'])) {
    validateCsrf();
    $reportId       = (int)$_POST['report_id'];
    $classification = $_POST['classification'] ?? 'natural_wear';
    $repairFee      = (float)($_POST['repair_fee'] ?? 0);

    $pdo->prepare("
        UPDATE damage_reports
        SET classification = ?, repair_fee = ?, resolved = 1
        WHERE id = ?
    ")->execute([$classification, $repairFee, $reportId]);

    // If negligence, apply penalty to the user who reported it
    if ($classification === 'negligence' && $repairFee > 0) {
        $dr = $pdo->prepare("SELECT reported_by FROM damage_reports WHERE id = ?");
        $dr->execute([$reportId]);
        $row = $dr->fetch();
        if ($row) {
            $pdo->prepare("
                INSERT INTO penalties (user_id, penalty_type, amount)
                VALUES (?, 'fine', ?)
            ")->execute([$row['reported_by'], $repairFee]);
            sendNotification($row['reported_by'], 'damage_fee',
                "A repair fee of EGP $repairFee has been applied to your account for tool damage.");
        }
    } else {
        $dr = $pdo->prepare("SELECT reported_by FROM damage_reports WHERE id = ?");
        $dr->execute([$reportId]);
        $row = $dr->fetch();
        if ($row) {
            sendNotification($row['reported_by'], 'damage_exempt',
                'Your damage report has been reviewed. Classified as natural wear — no fee applied.');
        }
    }

    logAudit('damage_report_resolved', 'damage_reports', $reportId,
        "Damage classified as: $classification, fee: $repairFee");
    $success = 'Damage report resolved.';
}

// ── Fetch data ───────────────────────────────────────────────
$tools   = $pdo->query("SELECT * FROM tools ORDER BY name")->fetchAll();
$pending = $pdo->query("
    SELECT dr.*, t.name AS tool_name, u.full_name
    FROM damage_reports dr
    JOIN tools t ON dr.tool_id = t.id
    JOIN users u ON dr.reported_by = u.id
    WHERE dr.resolved = 0
")->fetchAll();

$pageTitle = 'Tool Management';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <h1 class="page-title">Tool Management</h1>
  <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-secondary btn-sm">← Back</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<?php if ($pending): ?>
<div class="card mb-2">
  <div class="card-title">⚠️ Pending Damage Reports (<?= count($pending) ?>)</div>
  <?php foreach ($pending as $d): ?>
  <div style="border:1px solid #ced4da;border-radius:8px;padding:12px;margin-bottom:10px;">
    <strong><?= clean($d['tool_name']) ?></strong> — reported by <?= clean($d['full_name']) ?><br>
    <span class="text-muted" style="font-size:13px"><?= clean($d['description']) ?></span>
    <form method="POST" style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="report_id"  value="<?= $d['id'] ?>">
      <select name="classification" style="padding:6px 10px;border-radius:6px;border:1px solid #ced4da;">
        <option value="natural_wear">Natural Wear (no fee)</option>
        <option value="negligence">Negligence (apply fee)</option>
      </select>
      <input type="number" name="repair_fee" step="0.01" placeholder="Repair fee (EGP)"
             style="padding:6px 10px;border-radius:6px;border:1px solid #ced4da;width:160px;">
      <button type="submit" name="resolve_damage" class="btn btn-warning btn-sm">Resolve</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid-2">
  <!-- Add tool -->
  <div class="card">
    <div class="card-title">Add New Tool</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group">
        <label>Tool Name</label>
        <input type="text" name="name" placeholder="Rototiller" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" placeholder="Optional description…"></textarea>
      </div>
      <div class="form-group">
        <label>Maintenance Threshold (hours)</label>
        <input type="number" name="maintenance_threshold" value="100" min="1">
      </div>
      <button type="submit" name="add_tool" class="btn btn-primary">Add Tool</button>
    </form>
  </div>

  <!-- State machine transition -->
  <div class="card">
    <div class="card-title">Change Tool State</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group">
        <label>Select Tool</label>
        <select name="tool_id" id="toolSelect" onchange="updateTransitions(this)">
          <?php foreach ($tools as $t): ?>
            <option value="<?= $t['id'] ?>"
                    data-state="<?= $t['status'] ?>">
              <?= clean($t['name']) ?> (<?= $t['status'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Transition To</label>
        <select name="new_state" id="transitionSelect">
          <!-- filled by JS -->
        </select>
      </div>
      <button type="submit" name="change_state" class="btn btn-warning">Apply Transition</button>
    </form>
  </div>
</div>

<!-- Tools table -->
<div class="card">
  <div class="card-title">All Tools</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Name</th><th>Status</th><th>Usage Hours</th><th>Maint. Threshold</th><th>Cleaning</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tools as $t): ?>
        <tr>
          <td><strong><?= clean($t['name']) ?></strong></td>
          <td><span class="status status-<?= $t['status'] ?>"><?= $t['status'] ?></span></td>
          <td>
            <?= (float)$t['usage_hours'] ?>h
            <?php if ((float)$t['usage_hours'] >= (float)$t['maintenance_threshold']): ?>
              <span class="status status-warning" style="margin-left:6px">Maintenance Due</span>
            <?php endif; ?>
          </td>
          <td><?= (float)$t['maintenance_threshold'] ?>h</td>
          <td><span class="status status-<?= $t['cleaning_status'] === 'clean' ? 'active' : 'warning' ?>">
            <?= $t['cleaning_status'] ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Allowed transitions — mirrors PHP constant for UI consistency
const transitions = {
    available:      ['checked_out','decommissioned'],
    checked_out:    ['available','in_repair','decommissioned'],
    in_repair:      ['available','decommissioned'],
    decommissioned: []
};

function updateTransitions(sel) {
    const state = sel.options[sel.selectedIndex].dataset.state;
    const trans = transitions[state] || [];
    const out   = document.getElementById('transitionSelect');
    out.innerHTML = '';
    if (trans.length === 0) {
        out.innerHTML = '<option>No transitions available</option>';
    } else {
        trans.forEach(t => {
            const opt = document.createElement('option');
            opt.value = t; opt.textContent = t;
            out.appendChild(opt);
        });
    }
}
// Run on page load
updateTransitions(document.getElementById('toolSelect'));
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
