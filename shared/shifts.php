<?php // shared/shifts.php — Volunteer Shifts + Shift Swap
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','warden','admin']);
$pdo = getPDO(); $user = currentUser();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup_shift'])) {
    validateCsrf();
    $shiftId = (int)$_POST['shift_id'];
    $shift   = $pdo->prepare("SELECT * FROM shifts WHERE id = ? AND status = 'open'");
    $shift->execute([$shiftId]); $shiftRow = $shift->fetch();
    if (!$shiftRow) { $error = 'Shift not available.'; }
    elseif ($shiftRow['filled_slots'] >= $shiftRow['available_slots']) { $error = 'This shift is full.'; }
    else {
        try {
            $pdo->prepare("INSERT INTO shift_registrations (shift_id, user_id) VALUES (?,?)")
                ->execute([$shiftId, $user['id']]);
            $pdo->prepare("UPDATE shifts SET filled_slots = filled_slots + 1 WHERE id = ?")
                ->execute([$shiftId]);
            // Award community points for signing up
            $pdo->prepare("UPDATE users SET community_points = community_points + 3 WHERE id = ?")
                ->execute([$user['id']]);
            recalculateMembershipTier($user['id']);
            sendNotification($user['id'], 'shift_confirmed',
                "You are registered for the {$shiftRow['role_type']} shift on " . date('d M Y', strtotime($shiftRow['shift_date'])));
            logAudit('shift_signup', 'shift_registrations', 0, "User signed up for shift $shiftId");
            $success = 'You are registered for this shift!';
        } catch (PDOException $e) { $error = 'You are already registered for this shift.'; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_swap'])) {
    validateCsrf();
    $shiftId  = (int)$_POST['swap_shift_id'];
    $targetId = (int)$_POST['target_user_id'];
    $pdo->prepare("INSERT INTO shift_swap_requests (shift_id, requester_id, target_id) VALUES (?,?,?)")
        ->execute([$shiftId, $user['id'], $targetId]);
    $target = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $target->execute([$targetId]); $targetName = $target->fetchColumn();
    sendNotification($targetId, 'swap_request',
        "{$user['full_name']} has requested to swap shift #$shiftId with you. Please log in to accept or reject.");
    $success = "Swap request sent to $targetName.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_swap'])) {
    validateCsrf();
    $swapId   = (int)$_POST['swap_id'];
    $response = $_POST['response'] ?? '';
    if (!in_array($response, ['accepted','rejected'])) { $error = 'Invalid response.'; }
    else {
        $swap = $pdo->prepare("SELECT * FROM shift_swap_requests WHERE id = ? AND target_id = ?");
        $swap->execute([$swapId, $user['id']]); $swapRow = $swap->fetch();
        if (!$swapRow) { $error = 'Swap request not found.'; }
        else {
            $pdo->prepare("UPDATE shift_swap_requests SET status = ?, resolved_at = NOW() WHERE id = ?")
                ->execute([$response, $swapId]);
            if ($response === 'accepted') {
                // Swap registrations
                $pdo->prepare("UPDATE shift_registrations SET user_id = ? WHERE shift_id = ? AND user_id = ?")
                    ->execute([$swapRow['target_id'], $swapRow['shift_id'], $swapRow['requester_id']]);
                $pdo->prepare("INSERT IGNORE INTO shift_registrations (shift_id, user_id) VALUES (?,?)")
                    ->execute([$swapRow['shift_id'], $swapRow['requester_id']]);
                sendNotification($swapRow['requester_id'], 'swap_accepted',
                    "{$user['full_name']} accepted your shift swap request.");
            } else {
                sendNotification($swapRow['requester_id'], 'swap_rejected',
                    "{$user['full_name']} declined your shift swap request.");
            }
            $success = "Swap request $response.";
        }
    }
}

// Log service hours
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_hours'])) {
    validateCsrf();
    $hrs  = (float)$_POST['hours_logged'];
    $desc = trim($_POST['hour_description'] ?? '');
    $month = date('Y-m');
    if ($hrs > 0) {
        $pdo->prepare("INSERT INTO service_hour_logs (user_id, hours_logged, log_date, description, compliance_month) VALUES (?,?,CURDATE(),?,?)")
            ->execute([$user['id'], $hrs, $desc, $month]);
        logAudit('hours_logged', 'service_hour_logs', 0, "Logged $hrs hours");
        $success = "$hrs hours logged for $month.";
    }
}

$shifts = $pdo->query("SELECT * FROM shifts WHERE shift_date >= CURDATE() ORDER BY shift_date, role_type")->fetchAll();
$myRegistrations = $pdo->prepare("SELECT sr.*, s.shift_date, s.role_type FROM shift_registrations sr JOIN shifts s ON sr.shift_id = s.id WHERE sr.user_id = ? ORDER BY s.shift_date");
$myRegistrations->execute([$user['id']]); $myRegs = $myRegistrations->fetchAll();
$pendingSwaps = $pdo->prepare("SELECT ssr.*, s.shift_date, s.role_type, u.full_name AS requester_name FROM shift_swap_requests ssr JOIN shifts s ON ssr.shift_id = s.id JOIN users u ON ssr.requester_id = u.id WHERE ssr.target_id = ? AND ssr.status = 'pending'");
$pendingSwaps->execute([$user['id']]); $swaps = $pendingSwaps->fetchAll();

// Monthly hours summary
$monthHours = $pdo->prepare("SELECT COALESCE(SUM(hours_logged),0) FROM service_hour_logs WHERE user_id = ? AND compliance_month = ?");
$monthHours->execute([$user['id'], date('Y-m')]); $myHours = (float)$monthHours->fetchColumn();

$pageTitle = 'Volunteer Shifts';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="page-title mb-2">📅 Volunteer Shifts</h1>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<?php if ($swaps): ?>
<div class="card mb-2">
  <div class="card-title">⚠️ Pending Swap Requests (<?= count($swaps) ?>)</div>
  <?php foreach ($swaps as $sw): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e9ecef;">
    <span><?= clean($sw['requester_name']) ?> wants to swap shift on <?= date('d M Y', strtotime($sw['shift_date'])) ?> (<?= $sw['role_type'] ?>)</span>
    <form method="POST" style="display:flex;gap:6px;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="swap_id"    value="<?= $sw['id'] ?>">
      <button type="submit" name="respond_swap" value="accepted" class="btn btn-primary btn-sm">Accept</button>
      <button type="submit" name="respond_swap" value="rejected" class="btn btn-danger btn-sm">Reject</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid-2">
<div class="card">
  <div class="card-title">Available Shifts</div>
  <?php foreach ($shifts as $s): ?>
  <?php $remaining = $s['available_slots'] - $s['filled_slots']; ?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e9ecef;">
    <div>
      <strong><?= date('d M Y', strtotime($s['shift_date'])) ?></strong>
      <span class="status status-<?= $s['role_type'] === 'heavy' ? 'warning' : 'active' ?>" style="margin-left:8px"><?= $s['role_type'] ?></span>
      <span class="text-muted" style="font-size:12px;margin-left:8px"><?= $remaining ?> slots left</span>
    </div>
    <form method="POST" style="display:flex;gap:6px;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="shift_id"   value="<?= $s['id'] ?>">
      <button type="submit" name="signup_shift" class="btn btn-primary btn-sm"
              <?= $remaining <= 0 ? 'disabled' : '' ?>>
        <?= $remaining > 0 ? 'Sign Up' : 'Full' ?>
      </button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<div>
  <div class="card mb-2">
    <div class="card-title">My Service Hours — <?= date('M Y') ?></div>
    <p>Logged this month: <strong><?= $myHours ?>h</strong> / 4h required</p>
    <?php if ($myHours >= 4): ?>
      <span class="status status-active">Compliant ✓</span>
    <?php else: ?>
      <span class="status status-warning">Incomplete — <?= max(0, 4 - $myHours) ?>h remaining</span>
    <?php endif; ?>
    <form method="POST" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="number" name="hours_logged" step="0.5" min="0.5" placeholder="Hours" style="width:80px;padding:6px 8px;border:1px solid #ced4da;border-radius:6px;">
      <input type="text"   name="hour_description" placeholder="Task description" style="flex:1;padding:6px 8px;border:1px solid #ced4da;border-radius:6px;">
      <button type="submit" name="log_hours" class="btn btn-primary">Log Hours</button>
    </form>
  </div>

  <?php if ($myRegs): ?>
  <div class="card">
    <div class="card-title">My Registered Shifts</div>
    <?php foreach ($myRegs as $reg): ?>
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #e9ecef;font-size:14px;">
      <span><?= date('d M Y', strtotime($reg['shift_date'])) ?> — <?= $reg['role_type'] ?></span>
      <form method="POST" style="display:flex;gap:6px;">
        <input type="hidden" name="csrf_token"    value="<?= csrfToken() ?>">
        <input type="hidden" name="swap_shift_id" value="<?= $reg['shift_id'] ?>">
        <select name="target_user_id" style="padding:3px 6px;border:1px solid #ced4da;border-radius:4px;font-size:12px;">
          <?php
          $members = $pdo->query("SELECT id, full_name FROM users WHERE is_active=1 AND id != {$user['id']} ORDER BY full_name");
          foreach ($members->fetchAll() as $m): ?>
            <option value="<?= $m['id'] ?>"><?= clean($m['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" name="request_swap" class="btn btn-warning btn-sm">Request Swap</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
