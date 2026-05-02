<?php // admin/shifts.php — Admin manages shifts and tasks
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$pdo = getPDO(); $success = $error = '';

// Create shift
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_shift'])) {
    validateCsrf();
    $date  = $_POST['shift_date']      ?? '';
    $role  = $_POST['role_type']       ?? 'heavy';
    $slots = (int)$_POST['available_slots'];
    if (!$date || $slots < 1) { $error = 'Date and slots required.'; }
    else {
        $pdo->prepare("INSERT INTO shifts (shift_date, role_type, available_slots) VALUES (?,?,?)")
            ->execute([$date, $role, $slots]);
        $success = 'Shift created.';
    }
}

// Create task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_task'])) {
    validateCsrf();
    $name  = trim($_POST['task_name'] ?? '');
    $score = (int)$_POST['difficulty_score'];
    $desc  = trim($_POST['task_desc'] ?? '');
    if (!$name) { $error = 'Task name required.'; }
    else {
        $pdo->prepare("INSERT INTO communal_tasks (name, description, difficulty_score, created_by) VALUES (?,?,?,?)")
            ->execute([$name, $desc, $score, $_SESSION['user_id']]);
        $success = "Task '$name' created.";
    }
}

// Complete task verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_task'])) {
    validateCsrf();
    $completionId = (int)$_POST['completion_id'];
    $tc = $pdo->prepare("SELECT tc.*, t.difficulty_score FROM task_completions tc JOIN communal_tasks t ON tc.task_id = t.id WHERE tc.id = ?");
    $tc->execute([$completionId]); $tcRow = $tc->fetch();
    if ($tcRow) {
        $pdo->prepare("UPDATE task_completions SET verified = 1, points_awarded = ? WHERE id = ?")
            ->execute([$tcRow['difficulty_score'], $completionId]);
        $pdo->prepare("UPDATE users SET community_points = community_points + ? WHERE id = ?")
            ->execute([$tcRow['difficulty_score'], $tcRow['user_id']]);
        recalculateMembershipTier($tcRow['user_id']);
        sendNotification($tcRow['user_id'], 'task_verified',
            "Your task completion was verified! You earned {$tcRow['difficulty_score']} community points.");
        $success = 'Task completion verified and points awarded.';
    }
}

$shifts  = $pdo->query("SELECT * FROM shifts ORDER BY shift_date DESC LIMIT 20")->fetchAll();
$tasks   = $pdo->query("SELECT * FROM communal_tasks WHERE is_active = 1 ORDER BY difficulty_score DESC")->fetchAll();
$pending = $pdo->query("
    SELECT tc.*, t.name AS task_name, t.difficulty_score, u.full_name
    FROM task_completions tc
    JOIN communal_tasks t ON tc.task_id = t.id
    JOIN users u ON tc.user_id = u.id
    WHERE tc.verified = 0
")->fetchAll();

$pageTitle = 'Shifts & Tasks';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex-between mb-2">
  <h1 class="page-title">📅 Shifts & Communal Tasks</h1>
  <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-secondary btn-sm">← Back</a>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<div class="grid-2">
  <div class="card">
    <div class="card-title">Create Shift</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Date</label><input type="date" name="shift_date" required min="<?= date('Y-m-d') ?>"></div>
      <div class="form-group">
        <label>Role Type</label>
        <select name="role_type"><option value="heavy">Heavy Laborer</option><option value="light">Administrative / Light</option></select>
      </div>
      <div class="form-group"><label>Available Slots</label><input type="number" name="available_slots" value="5" min="1"></div>
      <button type="submit" name="create_shift" class="btn btn-primary">Create Shift</button>
    </form>
  </div>
  <div class="card">
    <div class="card-title">Create Communal Task</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Task Name</label><input type="text" name="task_name" required placeholder="e.g. Turn the compost pile"></div>
      <div class="form-group"><label>Description</label><textarea name="task_desc" rows="2" placeholder="Optional details…"></textarea></div>
      <div class="form-group"><label>Difficulty Score (points awarded)</label><input type="number" name="difficulty_score" value="5" min="1" max="50"></div>
      <button type="submit" name="create_task" class="btn btn-primary">Create Task</button>
    </form>
  </div>
</div>

<?php if ($pending): ?>
<div class="card mt-2">
  <div class="card-title">Pending Task Verifications (<?= count($pending) ?>)</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Member</th><th>Task</th><th>Points</th><th>Completed</th><th>Verify</th></tr></thead>
      <tbody>
        <?php foreach ($pending as $p): ?>
        <tr>
          <td><?= clean($p['full_name']) ?></td>
          <td><?= clean($p['task_name']) ?></td>
          <td><?= $p['difficulty_score'] ?></td>
          <td class="text-muted"><?= date('d M Y', strtotime($p['completed_at'])) ?></td>
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf_token"     value="<?= csrfToken() ?>">
              <input type="hidden" name="completion_id"  value="<?= $p['id'] ?>">
              <button type="submit" name="verify_task" class="btn btn-primary btn-sm">Verify & Award</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="grid-2 mt-2">
  <div class="card">
    <div class="card-title">Recent Shifts</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Role</th><th>Slots</th><th>Filled</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($shifts as $s): ?>
          <tr>
            <td><?= date('d M Y', strtotime($s['shift_date'])) ?></td>
            <td><?= $s['role_type'] ?></td>
            <td><?= $s['available_slots'] ?></td>
            <td><?= $s['filled_slots'] ?></td>
            <td><span class="status status-<?= $s['status'] === 'open' ? 'active' : 'expired' ?>"><?= $s['status'] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-title">Active Tasks</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Task</th><th>Difficulty</th></tr></thead>
        <tbody>
          <?php foreach ($tasks as $t): ?>
          <tr>
            <td><?= clean($t['name']) ?></td>
            <td><span class="status status-<?= $t['difficulty_score'] >= 10 ? 'expired' : ($t['difficulty_score'] >= 5 ? 'warning' : 'active') ?>"><?= $t['difficulty_score'] ?> pts</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
