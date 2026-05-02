<?php
// member/dashboard.php — Community Member home
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','warden','admin']);
$pdo  = getPDO();
$user = currentUser();

// Recent notifications (last 5 unread)
$notifs = $pdo->prepare("
    SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5
");
$notifs->execute([$user['id']]);
$notifications = $notifs->fetchAll();

// Active flash trades
$flashes = $pdo->query("
    SELECT ft.*, u.full_name AS poster_name
    FROM flash_trade_posts ft
    JOIN users u ON ft.posted_by = u.id
    WHERE ft.status = 'active' AND ft.expires_at > NOW()
    ORDER BY ft.expires_at ASC LIMIT 4
")->fetchAll();

// Community tasks available
$tasks = $pdo->query("
    SELECT * FROM communal_tasks WHERE is_active = 1 LIMIT 5
")->fetchAll();

// Upcoming shifts user can sign up for
$shifts = $pdo->query("
    SELECT s.*,
      (s.available_slots - s.filled_slots) AS remaining
    FROM shifts s
    WHERE s.shift_date >= CURDATE() AND s.status = 'open'
    ORDER BY s.shift_date ASC LIMIT 4
")->fetchAll();

$pageTitle = 'My Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
  <h1 class="page-title">Welcome, <?= clean($user['full_name']) ?> 👋</h1>
  <span>
    <span class="tier-badge tier-<?= strtolower($user['tier_name']) ?>"><?= clean($user['tier_name']) ?> Member</span>
  </span>
</div>

<!-- Member stats -->
<div class="grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-value"><?= (int)$user['community_points'] ?></div>
    <div class="stat-label">Community Points</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= (int)$user['karma_points'] ?></div>
    <div class="stat-label">Karma Points</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= (int)$user['seed_credits'] ?></div>
    <div class="stat-label">Seed Credits</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= (int)$user['rental_months'] ?></div>
    <div class="stat-label">Months as Renter</div>
  </div>
</div>

<div class="grid-2">
  <!-- Notifications panel -->
  <div class="card">
    <div class="card-title flex-between">
      Notifications
      <a href="<?= APP_URL ?>/shared/notifications.php" class="btn btn-secondary btn-sm">View all</a>
    </div>
    <?php if ($notifications): ?>
      <?php foreach ($notifications as $n): ?>
      <div style="padding:8px 0;border-bottom:1px solid #e9ecef;<?= !$n['is_read'] ? 'font-weight:bold' : '' ?>">
        <span style="font-size:12px;color:#6c757d"><?= date('d M H:i', strtotime($n['created_at'])) ?></span><br>
        <?= clean($n['message']) ?>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="text-muted">No notifications yet.</p>
    <?php endif; ?>
  </div>

  <!-- Quick links -->
  <div class="card">
    <div class="card-title">Quick Actions</div>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <a href="<?= APP_URL ?>/shared/tools.php"        class="btn btn-primary">🔧 Browse & Reserve Tools</a>
      <a href="<?= APP_URL ?>/shared/marketplace.php"  class="btn btn-primary">🛒 Marketplace & Flash Trades</a>
      <a href="<?= APP_URL ?>/shared/advice.php"       class="btn btn-primary">💬 P2P Advice Exchange</a>
      <a href="<?= APP_URL ?>/shared/shifts.php"       class="btn btn-primary">📅 Volunteer Shifts</a>
      <a href="<?= APP_URL ?>/shared/voting.php"       class="btn btn-primary">🗳️ Community Voting</a>
      <a href="<?= APP_URL ?>/shared/seeds.php"        class="btn btn-secondary">🌱 Seed Bank</a>
      <?php if ($user['role'] === 'member'): ?>
      <a href="<?= APP_URL ?>/shared/waitlist.php"     class="btn btn-secondary">🏡 Join Plot Waitlist</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Active flash trades -->
<?php if ($flashes): ?>
<div class="card mt-2">
  <div class="card-title flex-between">
    🔥 Active Flash Trades
    <a href="<?= APP_URL ?>/shared/marketplace.php" class="btn btn-secondary btn-sm">All trades</a>
  </div>
  <div class="grid-2">
    <?php foreach ($flashes as $f): ?>
    <div style="border:1px solid #ced4da;border-radius:8px;padding:12px;">
      <strong><?= clean($f['produce_type']) ?></strong> — <?= $f['quantity'] ?> <?= clean($f['unit']) ?><br>
      <span class="text-muted" style="font-size:12px">📍 <?= clean($f['pickup_location']) ?></span><br>
      <span class="text-muted" style="font-size:12px">⏰ Expires: <?= date('d M H:i', strtotime($f['expires_at'])) ?></span><br>
      <span class="text-muted" style="font-size:12px">By <?= clean($f['poster_name']) ?></span>
      <br>
      <?php if ($f['posted_by'] != $user['id']): ?>
      <a href="<?= APP_URL ?>/shared/marketplace.php?claim=<?= $f['id'] ?>" class="btn btn-primary btn-sm" style="margin-top:8px">Claim</a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
