<?php // shared/notifications.php
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','warden','admin']);
$pdo = getPDO(); $user = currentUser();

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    validateCsrf();
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
        ->execute([$user['id']]);
    header("Location: " . APP_URL . "/shared/notifications.php");
    exit;
}

// Mark one as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    validateCsrf();
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
        ->execute([(int)$_POST['notif_id'], $user['id']]);
    header("Location: " . APP_URL . "/shared/notifications.php");
    exit;
}

$notifications = $pdo->prepare("
    SELECT * FROM notifications WHERE user_id = ?
    ORDER BY created_at DESC LIMIT 80
");
$notifications->execute([$user['id']]);
$notifs = $notifications->fetchAll();
$unread = array_sum(array_column($notifs, 'is_read') ? [] : [0]);

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex-between mb-2">
  <h1 class="page-title">🔔 Notifications</h1>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <button type="submit" name="mark_all_read" class="btn btn-secondary btn-sm">Mark all as read</button>
  </form>
</div>

<?php if (empty($notifs)): ?>
  <div class="card"><p class="text-muted">No notifications yet.</p></div>
<?php else: ?>
<?php foreach ($notifs as $n): ?>
<div class="card" style="<?= !$n['is_read'] ? 'border-left:4px solid #2d6a4f;' : 'opacity:.75;' ?> padding:12px 16px;margin-bottom:8px;">
  <div class="flex-between">
    <div>
      <span class="status status-<?= match($n['type']) {
        'emergency'        => 'expired',
        'pest_alert'       => 'warning',
        'lease_reminder'   => 'warning',
        'damage_fee'       => 'expired',
        'best_answer'      => 'active',
        default            => 'active'
      } ?>" style="margin-right:8px;font-size:11px"><?= clean($n['type']) ?></span>
      <?= clean($n['message']) ?>
    </div>
    <?php if (!$n['is_read']): ?>
    <form method="POST" style="margin-left:12px;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="notif_id"   value="<?= $n['id'] ?>">
      <button type="submit" name="mark_read" class="btn btn-secondary btn-sm">✓</button>
    </form>
    <?php endif; ?>
  </div>
  <p class="text-muted" style="font-size:12px;margin-top:4px">
    <?= date('d M Y H:i', strtotime($n['created_at'])) ?>
  </p>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
