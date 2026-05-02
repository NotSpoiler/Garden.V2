<?php
// admin/broadcasts.php — Emergency Site Broadcaster
// Sends a notification to ALL active members instantly
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$pdo = getPDO();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $message = trim($_POST['message'] ?? '');
    $areas   = trim($_POST['affected_areas'] ?? '');
    if ($message) {
        $pdo->prepare("INSERT INTO emergency_broadcasts (sent_by, message, affected_areas) VALUES (?,?,?)")
            ->execute([$_SESSION['user_id'], $message, $areas]);
        broadcastNotification('emergency', "🚨 EMERGENCY: $message" . ($areas ? " [Affected: $areas]" : ''));
        logAudit('emergency_broadcast', 'emergency_broadcasts', (int)$pdo->lastInsertId(),
            "Emergency broadcast: $message");
        $success = 'Emergency broadcast sent to all members.';
    }
}

$history = $pdo->query("
    SELECT eb.*, u.full_name
    FROM emergency_broadcasts eb JOIN users u ON eb.sent_by = u.id
    ORDER BY eb.sent_at DESC LIMIT 20
")->fetchAll();

$pageTitle = 'Emergency Broadcast';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex-between mb-2">
  <h1 class="page-title">🚨 Emergency Site Broadcaster</h1>
  <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-secondary btn-sm">← Back</a>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<div class="alert alert-warning">This will immediately notify <strong>all active members</strong>. Use only for genuine emergencies.</div>

<div class="grid-2">
  <div class="card">
    <div class="card-title">Send Emergency Alert</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group">
        <label>Message</label>
        <textarea name="message" rows="4" required placeholder="e.g. Water main burst — all plots closed until further notice."></textarea>
      </div>
      <div class="form-group">
        <label>Affected Areas (optional)</label>
        <input type="text" name="affected_areas" placeholder="e.g. Zone A, Zone B, Main entrance">
      </div>
      <button type="submit" class="btn btn-danger btn-block">Send Emergency Broadcast</button>
    </form>
  </div>
  <div class="card">
    <div class="card-title">Broadcast History</div>
    <?php foreach ($history as $b): ?>
    <div style="padding:8px 0;border-bottom:1px solid #e9ecef;">
      <strong class="text-muted" style="font-size:12px"><?= date('d M Y H:i', strtotime($b['sent_at'])) ?> — <?= clean($b['full_name']) ?></strong>
      <p style="margin:4px 0;font-size:14px"><?= clean($b['message']) ?></p>
      <?php if ($b['affected_areas']): ?>
        <span class="text-muted" style="font-size:12px">Areas: <?= clean($b['affected_areas']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
