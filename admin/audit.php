<?php
// ============================================================
// admin/audit.php
// FR 29-30: System Audit Trail — immutable, searchable log
//
// WHY IMMUTABLE: The DB has no DELETE allowed on audit_log.
// We also never provide a delete button here — by design.
// This satisfies the requirement that audit records cannot
// be changed or deleted, ever.
// ============================================================
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pdo = getPDO();

// Build filter conditions from GET params
$where  = [];
$params = [];

if (!empty($_GET['type'])) {
    $where[]  = "al.action_type LIKE ?";
    $params[] = '%' . $_GET['type'] . '%';
}
if (!empty($_GET['user'])) {
    $where[]  = "u.full_name LIKE ?";
    $params[] = '%' . $_GET['user'] . '%';
}
if (!empty($_GET['date_from'])) {
    $where[]  = "al.logged_at >= ?";
    $params[] = $_GET['date_from'] . ' 00:00:00';
}
if (!empty($_GET['date_to'])) {
    $where[]  = "al.logged_at <= ?";
    $params[] = $_GET['date_to'] . ' 23:59:59';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT al.*, u.full_name
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    $whereSQL
    ORDER BY al.logged_at DESC
    LIMIT 200
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Audit Log';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <h1 class="page-title">System Audit Log</h1>
  <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-secondary btn-sm">← Back</a>
</div>

<div class="alert alert-info">
  This log is <strong>immutable</strong> — records cannot be edited or deleted. Showing the most recent 200 entries.
</div>

<!-- Filters -->
<div class="card mb-2">
  <form method="GET" style="display:grid;grid-template-columns:repeat(4,1fr) auto;gap:10px;align-items:end;">
    <div class="form-group" style="margin:0">
      <label>Action Type</label>
      <input type="text" name="type" value="<?= clean($_GET['type'] ?? '') ?>" placeholder="e.g. login">
    </div>
    <div class="form-group" style="margin:0">
      <label>User Name</label>
      <input type="text" name="user" value="<?= clean($_GET['user'] ?? '') ?>" placeholder="Name…">
    </div>
    <div class="form-group" style="margin:0">
      <label>From Date</label>
      <input type="date" name="date_from" value="<?= clean($_GET['date_from'] ?? '') ?>">
    </div>
    <div class="form-group" style="margin:0">
      <label>To Date</label>
      <input type="date" name="date_to" value="<?= clean($_GET['date_to'] ?? '') ?>">
    </div>
    <div>
      <button type="submit" class="btn btn-primary">Filter</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>User</th><th>Action</th><th>Table</th><th>Record ID</th><th>Description</th><th>IP</th><th>Time</th></tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td class="text-muted"><?= $log['id'] ?></td>
          <td><?= clean($log['full_name'] ?? 'System') ?></td>
          <td><code style="font-size:12px"><?= clean($log['action_type']) ?></code></td>
          <td class="text-muted"><?= clean($log['affected_table'] ?? '') ?></td>
          <td class="text-muted"><?= $log['affected_id'] ?: '—' ?></td>
          <td style="max-width:220px;font-size:12px"><?= clean($log['description'] ?? '') ?></td>
          <td class="text-muted" style="font-size:12px"><?= clean($log['ip_address'] ?? '') ?></td>
          <td class="text-muted" style="font-size:12px;white-space:nowrap">
            <?= date('d M Y H:i', strtotime($log['logged_at'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
