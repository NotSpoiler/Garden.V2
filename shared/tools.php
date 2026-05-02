<?php
// ============================================================
// shared/tools.php
// Tool reservation, checkout viewing, damage reporting
// Implements: Shared Resource Conflict Resolver (FR 22-23)
//             Tool Maintenance State Machine (FR 24)
//             Tool Damage Liability Workflow
//             Late Return Penalty Engine
//
// HOW CONFLICT RESOLUTION WORKS:
// Before confirming a reservation, we check if another
// reservation already exists for the same tool on the same
// date AND overlapping time slot. If it does, we reject and
// suggest the next available slot.
// ============================================================
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','warden','admin']);
$pdo  = getPDO();
$user = currentUser();
$success = $error = '';

// ── Reserve a tool ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reserve_tool'])) {
    validateCsrf();
    $toolId    = (int)$_POST['tool_id'];
    $resDate   = $_POST['reservation_date'] ?? '';
    $timeSlot  = $_POST['time_slot'] ?? '';

    // Check tool is available
    $toolStmt = $pdo->prepare("SELECT * FROM tools WHERE id = ? AND status = 'available'");
    $toolStmt->execute([$toolId]);
    $tool = $toolStmt->fetch();

    if (!$tool) {
        $error = 'This tool is not currently available for reservation.';
    } elseif (!$resDate || !$timeSlot) {
        $error = 'Please select a date and time slot.';
    } else {
        // Conflict check — same tool, same date, same slot
        $conflict = $pdo->prepare("
            SELECT id FROM tool_reservations
            WHERE tool_id = ? AND reservation_date = ? AND time_slot = ? AND status = 'confirmed'
        ");
        $conflict->execute([$toolId, $resDate, $timeSlot]);

        if ($conflict->fetch()) {
            // Suggest next available slot
            $slots = ['09:00-11:00','11:00-13:00','13:00-15:00','15:00-17:00'];
            $taken = $pdo->prepare("
                SELECT time_slot FROM tool_reservations
                WHERE tool_id = ? AND reservation_date = ? AND status = 'confirmed'
            ");
            $taken->execute([$toolId, $resDate]);
            $takenSlots = array_column($taken->fetchAll(), 'time_slot');
            $freeSlots  = array_diff($slots, $takenSlots);
            $suggestion = !empty($freeSlots) ? 'Available: ' . implode(', ', $freeSlots) : 'No slots available on this date.';
            $error = "That time slot is already booked. $suggestion";
        } else {
            $pdo->prepare("
                INSERT INTO tool_reservations (tool_id, user_id, reservation_date, time_slot)
                VALUES (?,?,?,?)
            ")->execute([$toolId, $user['id'], $resDate, $timeSlot]);

            sendNotification($user['id'], 'tool_reserved',
                "Your reservation for '{$tool['name']}' on $resDate ($timeSlot) is confirmed.");
            logAudit('tool_reserved', 'tool_reservations',
                (int)$pdo->lastInsertId(), "Tool '{$tool['name']}' reserved for $resDate $timeSlot");
            $success = "Reservation confirmed for {$tool['name']} on $resDate ($timeSlot).";
        }
    }
}

// ── Report damage ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_damage'])) {
    validateCsrf();
    $toolId = (int)$_POST['damage_tool_id'];
    $desc   = trim($_POST['damage_description'] ?? '');
    if ($desc) {
        $pdo->prepare("INSERT INTO damage_reports (tool_id, reported_by, description) VALUES (?,?,?)")
            ->execute([$toolId, $user['id'], $desc]);
        // Transition tool to in_repair
        $pdo->prepare("UPDATE tools SET status = 'in_repair' WHERE id = ?")
            ->execute([$toolId]);
        logAudit('damage_reported', 'damage_reports', 0,
            "Damage reported for tool ID $toolId by user {$user['id']}");
        sendNotification($user['id'], 'damage_submitted',
            'Your damage report has been submitted. An admin will review it shortly.');
        $success = 'Damage report submitted. The tool has been flagged as In Repair.';
    }
}

// ── Cancel reservation ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reservation'])) {
    validateCsrf();
    $resId = (int)$_POST['reservation_id'];
    $pdo->prepare("
        UPDATE tool_reservations SET status = 'cancelled'
        WHERE id = ? AND user_id = ?
    ")->execute([$resId, $user['id']]);
    $success = 'Reservation cancelled.';
}

// ── Data ─────────────────────────────────────────────────────
$tools = $pdo->query("
    SELECT * FROM tools WHERE status != 'decommissioned' ORDER BY name
")->fetchAll();

// User's active reservations
$myReservations = $pdo->prepare("
    SELECT tr.*, t.name AS tool_name
    FROM tool_reservations tr
    JOIN tools t ON tr.tool_id = t.id
    WHERE tr.user_id = ? AND tr.status = 'confirmed'
      AND tr.reservation_date >= CURDATE()
    ORDER BY tr.reservation_date, tr.time_slot
");
$myReservations->execute([$user['id']]);
$myRes = $myReservations->fetchAll();

// Overdue checkouts for this user (for late return penalty display)
$overdueStmt = $pdo->prepare("
    SELECT tc.*, t.name AS tool_name,
           DATEDIFF(NOW(), tc.due_date) AS days_overdue
    FROM tool_checkouts tc
    JOIN tools t ON tc.tool_id = t.id
    WHERE tc.user_id = ? AND tc.status = 'active' AND tc.due_date < CURDATE()
");
$overdueStmt->execute([$user['id']]);
$overdue = $overdueStmt->fetchAll();

// Time slots available
$timeSlots = ['09:00-11:00','11:00-13:00','13:00-15:00','15:00-17:00'];

$pageTitle = 'Tools & Reservations';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title mb-2">🔧 Tool Library</h1>

<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<?php if ($overdue): ?>
<div class="alert alert-error">
  ⚠️ You have <strong><?= count($overdue) ?></strong> overdue tool(s).
  Late return penalties apply at EGP 5 per day.
  <?php foreach ($overdue as $od): ?>
    <br>• <strong><?= clean($od['tool_name']) ?></strong> — <?= $od['days_overdue'] ?> days overdue
    (EGP <?= $od['days_overdue'] * 5 ?> fine)
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Tool grid -->
<div class="card mb-3">
  <div class="card-title">Available Tools</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Tool</th><th>Status</th><th>Usage Hours</th><th>Cleaning</th><th>Reserve</th><th>Report Damage</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tools as $t): ?>
        <tr>
          <td><strong><?= clean($t['name']) ?></strong>
            <?php if (!empty($t['description'])): ?>
              <br><span class="text-muted" style="font-size:12px"><?= clean($t['description']) ?></span>
            <?php endif; ?>
          </td>
          <td><span class="status status-<?= $t['status'] ?>"><?= $t['status'] ?></span></td>
          <td>
            <?= (float)$t['usage_hours'] ?>h / <?= (float)$t['maintenance_threshold'] ?>h
            <?php if ((float)$t['usage_hours'] >= (float)$t['maintenance_threshold']): ?>
              <span class="status status-warning">Maint. Due</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="status <?= $t['cleaning_status'] === 'clean' ? 'status-active' : 'status-warning' ?>">
              <?= $t['cleaning_status'] ?>
            </span>
          </td>
          <td>
            <?php if ($t['status'] === 'available'): ?>
            <!-- Inline reservation form per tool -->
            <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="tool_id" value="<?= $t['id'] ?>">
              <input type="date" name="reservation_date"
                     min="<?= date('Y-m-d') ?>"
                     style="padding:4px 8px;border:1px solid #ced4da;border-radius:6px;font-size:13px;">
              <select name="time_slot" style="padding:4px 8px;border:1px solid #ced4da;border-radius:6px;font-size:13px;">
                <?php foreach ($timeSlots as $slot): ?>
                  <option value="<?= $slot ?>"><?= $slot ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="reserve_tool" class="btn btn-primary btn-sm">Reserve</button>
            </form>
            <?php else: ?>
              <span class="text-muted">Not available</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($t['status'] === 'available' || $t['status'] === 'checked_out'): ?>
            <button class="btn btn-danger btn-sm"
                    onclick="openDamageModal(<?= $t['id'] ?>, '<?= clean($t['name']) ?>')">
              Report
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- My reservations -->
<?php if ($myRes): ?>
<div class="card mb-3">
  <div class="card-title">My Upcoming Reservations</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tool</th><th>Date</th><th>Time Slot</th><th>Cancel</th></tr></thead>
      <tbody>
        <?php foreach ($myRes as $r): ?>
        <tr>
          <td><?= clean($r['tool_name']) ?></td>
          <td><?= date('d M Y', strtotime($r['reservation_date'])) ?></td>
          <td><?= clean($r['time_slot']) ?></td>
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf_token"      value="<?= csrfToken() ?>">
              <input type="hidden" name="reservation_id"  value="<?= $r['id'] ?>">
              <button type="submit" name="cancel_reservation" class="btn btn-danger btn-sm">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Damage report modal -->
<div id="damageModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:420px;width:90%;">
    <h3 style="margin-bottom:12px">Report Damage</h3>
    <form method="POST">
      <input type="hidden" name="csrf_token"       value="<?= csrfToken() ?>">
      <input type="hidden" name="damage_tool_id"   id="damageToolId">
      <p id="damageToolName" style="margin-bottom:12px;font-weight:bold"></p>
      <div class="form-group">
        <label>Damage Description</label>
        <textarea name="damage_description" rows="3" required
                  placeholder="Describe what happened and how the damage occurred…"></textarea>
      </div>
      <div style="display:flex;gap:10px;">
        <button type="submit" name="report_damage" class="btn btn-danger">Submit Report</button>
        <button type="button" onclick="closeDamageModal()" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openDamageModal(id, name) {
    document.getElementById('damageToolId').value   = id;
    document.getElementById('damageToolName').textContent = 'Tool: ' + name;
    document.getElementById('damageModal').style.display = 'flex';
}
function closeDamageModal() {
    document.getElementById('damageModal').style.display = 'none';
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
