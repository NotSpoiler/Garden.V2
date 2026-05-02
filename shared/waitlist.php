<?php // shared/waitlist.php — Plot Waitlist & Priority
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','admin']);
$pdo = getPDO(); $user = currentUser();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_waitlist'])) {
    validateCsrf();
    // Check no active lease already
    $lease = $pdo->prepare("SELECT id FROM leases WHERE user_id = ? AND status = 'active'");
    $lease->execute([$user['id']]);
    if ($lease->fetch()) { $error = 'You already have an active plot lease.'; }
    else {
        try {
            $pdo->prepare("INSERT INTO waitlist (user_id) VALUES (?)")->execute([$user['id']]);
            recalculateWaitlistPriority($user['id']);
            logAudit('joined_waitlist', 'waitlist', 0, "User joined waitlist");
            sendNotification($user['id'], 'waitlist_joined',
                'You have joined the plot waitlist. You will be notified when a plot becomes available.');
            $success = 'You have joined the waitlist!';
        } catch (PDOException $e) { $error = 'You are already on the waitlist.'; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_waitlist'])) {
    validateCsrf();
    $pdo->prepare("UPDATE waitlist SET status = 'cancelled' WHERE user_id = ?")->execute([$user['id']]);
    $success = 'You have been removed from the waitlist.';
}

// My waitlist entry
$myEntry = $pdo->prepare("
    SELECT w.*, mt.priority_boost
    FROM waitlist w
    JOIN users u ON w.user_id = u.id
    LEFT JOIN membership_tiers mt ON u.membership_tier_id = mt.id
    WHERE w.user_id = ? AND w.status = 'waiting'
");
$myEntry->execute([$user['id']]); $myPosition = $myEntry->fetch();

// Full waitlist (publicly visible — shows position)
$waitlist = $pdo->query("
    SELECT w.*, u.full_name, u.community_points, mt.name AS tier_name
    FROM waitlist w
    JOIN users u ON w.user_id = u.id
    LEFT JOIN membership_tiers mt ON u.membership_tier_id = mt.id
    WHERE w.status = 'waiting'
    ORDER BY w.priority_score DESC
")->fetchAll();

$pageTitle = 'Plot Waitlist';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="page-title mb-2">🏡 Plot Waitlist</h1>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<?php if ($myPosition): ?>
<div class="card mb-2" style="border-left:4px solid #2d6a4f;">
  <div class="card-title">Your Waitlist Status</div>
  <div class="grid-3">
    <div class="stat-card"><div class="stat-value">#<?= array_search($user['id'], array_column($waitlist,'user_id')) + 1 ?></div><div class="stat-label">Queue Position</div></div>
    <div class="stat-card"><div class="stat-value"><?= $myPosition['priority_score'] ?></div><div class="stat-label">Priority Score</div></div>
    <div class="stat-card"><div class="stat-value"><?= date('d M', strtotime($myPosition['joined_at'])) ?></div><div class="stat-label">Joined</div></div>
  </div>
  <div class="mt-2">
    <p class="text-muted" style="font-size:13px">Priority = Community Points + Membership Boost + Days Waiting</p>
    <form method="POST" style="margin-top:10px;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <button type="submit" name="leave_waitlist" class="btn btn-danger btn-sm"
              onclick="return confirm('Remove yourself from the waitlist?')">Leave Waitlist</button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card mb-2">
  <div class="card-title">Join the Waitlist</div>
  <p class="text-muted mb-2">No plots available right now. Join the waitlist and we'll notify you when one opens up. Your position is based on your community points, membership tier, and how long you've been waiting.</p>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <button type="submit" name="join_waitlist" class="btn btn-primary">Join Waitlist</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-title">Current Waitlist (<?= count($waitlist) ?>)</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Member</th><th>Tier</th><th>Points</th><th>Priority Score</th><th>Waiting Since</th></tr></thead>
      <tbody>
        <?php foreach ($waitlist as $i => $w): ?>
        <tr style="<?= $w['user_id'] == $user['id'] ? 'background:#d8f3dc;font-weight:bold' : '' ?>">
          <td><?= $i + 1 ?></td>
          <td><?= clean($w['full_name']) ?> <?= $w['user_id'] == $user['id'] ? '(You)' : '' ?></td>
          <td><span class="tier-badge tier-<?= strtolower($w['tier_name'] ?? 'bronze') ?>"><?= clean($w['tier_name'] ?? 'Bronze') ?></span></td>
          <td><?= (int)$w['community_points'] ?></td>
          <td><?= (int)$w['priority_score'] ?></td>
          <td class="text-muted"><?= date('d M Y', strtotime($w['joined_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
