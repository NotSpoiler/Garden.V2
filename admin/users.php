<?php
// ============================================================
// admin/users.php
// RBAC: Admin assigns and changes user roles
// This implements FR 27-28 (Role-Based Access Control)
//
// HOW ROLE ASSIGNMENT WORKS:
// 1. Admin sees a table of all users with their current role
// 2. Admin submits a POST form to change a user's role
// 3. We validate the new role is one of the allowed enum values
// 4. We update the users table and log the action to audit_log
// 5. If role changed TO plot_owner, we also generate a gate code
// ============================================================
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pdo = getPDO();
$success = $error = '';

// ── Handle role change POST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    validateCsrf();

    $targetId = (int)$_POST['user_id'];
    $newRole  = $_POST['new_role'] ?? '';
    $allowed  = ['admin','warden','plot_owner','member','guest'];

    if (!in_array($newRole, $allowed)) {
        $error = 'Invalid role selected.';
    } elseif ($targetId === (int)$_SESSION['user_id']) {
        $error = 'You cannot change your own role.';
    } else {
        // Fetch current role for logging
        $old = $pdo->prepare("SELECT role, full_name FROM users WHERE id = ?");
        $old->execute([$targetId]);
        $oldData = $old->fetch();

        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")
            ->execute([$newRole, $targetId]);

        // If promoted to plot_owner, generate a gate code
        if ($newRole === 'plot_owner') {
            $gateCode = strtoupper(substr(md5(uniqid()), 0, 6));
            $pdo->prepare("UPDATE users SET gate_code = ? WHERE id = ?")
                ->execute([$gateCode, $targetId]);
            sendNotification($targetId, 'role_change',
                "Your account has been upgraded to Plot Owner. Your gate code is: $gateCode");
        } else {
            sendNotification($targetId, 'role_change',
                "Your account role has been updated to: $newRole");
        }

        logAudit('role_changed', 'users', $targetId,
            "Role changed from {$oldData['role']} to $newRole for user: {$oldData['full_name']}");

        $success = "Role updated successfully for {$oldData['full_name']}.";
    }
}

// ── Handle user deactivation ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    validateCsrf();
    $targetId  = (int)$_POST['user_id'];
    $newStatus = (int)$_POST['new_status'];
    $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")
        ->execute([$newStatus, $targetId]);
    logAudit('user_status_changed', 'users', $targetId,
        "User active status set to: $newStatus");
    $success = 'User status updated.';
}

// ── Fetch all users with tier info ───────────────────────────
$search = trim($_GET['search'] ?? '');
if ($search) {
    $stmt = $pdo->prepare("
        SELECT u.*, mt.name AS tier_name
        FROM users u
        LEFT JOIN membership_tiers mt ON u.membership_tier_id = mt.id
        WHERE u.full_name LIKE ? OR u.email LIKE ?
        ORDER BY u.created_at DESC
    ");
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt = $pdo->query("
        SELECT u.*, mt.name AS tier_name
        FROM users u
        LEFT JOIN membership_tiers mt ON u.membership_tier_id = mt.id
        ORDER BY u.created_at DESC
    ");
}
$users = $stmt->fetchAll();

$pageTitle = 'User Management';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <h1 class="page-title">User Management</h1>
  <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-secondary btn-sm">← Back</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<!-- Search -->
<div class="card mb-2">
  <form method="GET" style="display:flex;gap:10px;">
    <input type="text" name="search" placeholder="Search by name or email…"
           value="<?= clean($search) ?>" style="flex:1;padding:8px 12px;border:1px solid #ced4da;border-radius:8px;">
    <button type="submit" class="btn btn-primary">Search</button>
    <?php if ($search): ?>
      <a href="?" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Name</th><th>Email</th><th>Role</th><th>Tier</th>
          <th>Points</th><th>Status</th><th>Change Role</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= clean($u['full_name']) ?></td>
          <td><?= clean($u['email']) ?></td>
          <td><span class="status"><?= clean($u['role']) ?></span></td>
          <td>
            <span class="tier-badge tier-<?= strtolower($u['tier_name'] ?? 'bronze') ?>">
              <?= clean($u['tier_name'] ?? 'Bronze') ?>
            </span>
          </td>
          <td><?= (int)$u['community_points'] ?></td>
          <td>
            <span class="status <?= $u['is_active'] ? 'status-active' : 'status-expired' ?>">
              <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td>
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
            <!-- Role change form — one per row -->
            <form method="POST" style="display:flex;gap:6px;">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
              <select name="new_role" style="padding:4px 8px;border-radius:6px;font-size:13px;border:1px solid #ced4da;">
                <?php foreach (['member','plot_owner','warden','admin','guest'] as $r): ?>
                  <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="change_role" class="btn btn-primary btn-sm">Apply</button>
            </form>
            <?php else: ?>
              <span class="text-muted">Current user</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf_token"   value="<?= csrfToken() ?>">
              <input type="hidden" name="user_id"      value="<?= $u['id'] ?>">
              <input type="hidden" name="new_status"   value="<?= $u['is_active'] ? 0 : 1 ?>">
              <button type="submit" name="toggle_active"
                      class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-warning' ?>">
                <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
