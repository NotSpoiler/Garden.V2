<?php
// ============================================================
// admin/dashboard.php
// Admin sees system-wide statistics and quick action links
// ============================================================
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pdo = getPDO();

// Fetch all stat counts in one pass for efficiency
$stats = [
    'users'       => $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn(),
    'plots'       => $pdo->query("SELECT COUNT(*) FROM plots")->fetchColumn(),
    'available'   => $pdo->query("SELECT COUNT(*) FROM plots WHERE status = 'available'")->fetchColumn(),
    'tools'       => $pdo->query("SELECT COUNT(*) FROM tools WHERE status = 'available'")->fetchColumn(),
    'open_incidents' => $pdo->query("SELECT COUNT(*) FROM audit_log WHERE logged_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'waitlist'    => $pdo->query("SELECT COUNT(*) FROM waitlist WHERE status = 'waiting'")->fetchColumn(),
];

// Recent audit log entries
$recentAudit = $pdo->query("
    SELECT al.*, u.full_name
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.logged_at DESC LIMIT 8
")->fetchAll();

// Consumable items below reorder threshold
$lowStock = $pdo->query("
    SELECT * FROM consumable_items WHERE stock_level <= reorder_threshold
")->fetchAll();

// Pending damage reports
$pendingDamage = $pdo->query("
    SELECT dr.*, t.name AS tool_name, u.full_name
    FROM damage_reports dr
    JOIN tools t ON dr.tool_id = t.id
    JOIN users u ON dr.reported_by = u.id
    WHERE dr.resolved = 0
")->fetchAll();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <h1 class="page-title">Admin Dashboard</h1>
  <div>
    <a href="<?= APP_URL ?>/admin/broadcasts.php" class="btn btn-danger btn-sm">🚨 Emergency Broadcast</a>
    <a href="<?= APP_URL ?>/admin/plots.php" class="btn btn-primary btn-sm">Manage Plots</a>
  </div>
</div>

<!-- Stats row -->
<div class="grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-value"><?= $stats['users'] ?></div>
    <div class="stat-label">Total Members</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['available'] ?>/<?= $stats['plots'] ?></div>
    <div class="stat-label">Available Plots</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['tools'] ?></div>
    <div class="stat-label">Tools Available</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['waitlist'] ?></div>
    <div class="stat-label">On Waitlist</div>
  </div>
</div>

<?php if ($lowStock): ?>
<div class="alert alert-warning">
  ⚠️ <strong><?= count($lowStock) ?> consumable item(s)</strong> are below reorder threshold.
  <a href="<?= APP_URL ?>/admin/inventory.php">View Inventory</a>
</div>
<?php endif; ?>

<?php if ($pendingDamage): ?>
<div class="alert alert-error">
  🔧 <strong><?= count($pendingDamage) ?> damage report(s)</strong> awaiting review.
  <a href="<?= APP_URL ?>/admin/damage.php">Review Now</a>
</div>
<?php endif; ?>

<div class="grid-2">
  <!-- Quick links -->
  <div class="card">
    <div class="card-title">Quick Actions</div>
    <div style="display:flex; flex-direction:column; gap:10px;">
      <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-primary">👥 Manage Users & Roles</a>
      <a href="<?= APP_URL ?>/admin/plots.php" class="btn btn-primary">🗺️ Plot Management</a>
      <a href="<?= APP_URL ?>/admin/tools.php" class="btn btn-primary">🔧 Tool Management</a>
      <a href="<?= APP_URL ?>/admin/inventory.php" class="btn btn-primary">📦 Consumable Inventory</a>
      <a href="<?= APP_URL ?>/admin/voting.php" class="btn btn-primary">🗳️ Voting Proposals</a>
      <a href="<?= APP_URL ?>/admin/shifts.php" class="btn btn-primary">📅 Shift Management</a>
      <a href="<?= APP_URL ?>/admin/audit.php" class="btn btn-secondary">📋 Audit Log</a>
    </div>
  </div>

  <!-- Recent audit log -->
  <div class="card">
    <div class="card-title">Recent Activity</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>User</th><th>Action</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach ($recentAudit as $log): ?>
          <tr>
            <td><?= clean($log['full_name'] ?? 'System') ?></td>
            <td><?= clean($log['action_type']) ?></td>
            <td class="text-muted"><?= date('d M H:i', strtotime($log['logged_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-1">
      <a href="<?= APP_URL ?>/admin/audit.php" class="text-muted">View full audit log →</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
