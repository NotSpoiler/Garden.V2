<?php // shared/voting.php — Communal Fund Allocation Voting
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','warden','admin']);
$pdo = getPDO(); $user = currentUser();
$success = $error = '';

// Admin: create proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_proposal']) && $user['role'] === 'admin') {
    validateCsrf();
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $dl    = $_POST['deadline'] ?? '';
    if (!$title || !$dl) { $error = 'Title and deadline are required.'; }
    else {
        $pdo->prepare("INSERT INTO voting_proposals (title, description, created_by, deadline) VALUES (?,?,?,?)")
            ->execute([$title, $desc, $user['id'], $dl]);
        broadcastNotification('new_vote', "New voting proposal: '$title'. Cast your vote now!");
        $success = "Proposal '$title' created.";
    }
}

// Cast vote — one per user per proposal enforced by UNIQUE KEY in DB
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cast_vote'])) {
    validateCsrf();
    $proposalId = (int)$_POST['proposal_id'];
    // Check deadline not passed
    $prop = $pdo->prepare("SELECT * FROM voting_proposals WHERE id = ? AND status = 'active' AND deadline > NOW()");
    $prop->execute([$proposalId]); $propRow = $prop->fetch();
    if (!$propRow) { $error = 'This vote is closed or does not exist.'; }
    else {
        try {
            $pdo->prepare("INSERT INTO votes (proposal_id, user_id) VALUES (?,?)")
                ->execute([$proposalId, $user['id']]);
            logAudit('vote_cast', 'votes', $proposalId, "User voted on proposal $proposalId");
            $success = 'Your vote has been recorded!';
        } catch (PDOException $e) { $error = 'You have already voted on this proposal.'; }
    }
}

// Close proposal (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_proposal']) && $user['role'] === 'admin') {
    validateCsrf();
    $pdo->prepare("UPDATE voting_proposals SET status = 'closed' WHERE id = ?")
        ->execute([(int)$_POST['proposal_id']]);
    $success = 'Proposal closed.';
}

// Auto-close expired proposals
$pdo->exec("UPDATE voting_proposals SET status = 'closed' WHERE deadline <= NOW() AND status = 'active'");

$proposals = $pdo->query("
    SELECT vp.*,
        (SELECT COUNT(*) FROM votes WHERE proposal_id = vp.id) AS total_votes,
        u.full_name AS creator_name
    FROM voting_proposals vp
    JOIN users u ON vp.created_by = u.id
    ORDER BY vp.created_at DESC
")->fetchAll();

// Which proposals current user already voted on
$myVotes = $pdo->prepare("SELECT proposal_id FROM votes WHERE user_id = ?");
$myVotes->execute([$user['id']]); $voted = array_column($myVotes->fetchAll(), 'proposal_id');

$pageTitle = 'Community Voting';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="page-title mb-2">🗳️ Community Fund Voting</h1>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<?php if ($user['role'] === 'admin'): ?>
<div class="card mb-2">
  <div class="card-title">Create New Proposal</div>
  <form method="POST" style="display:grid;grid-template-columns:2fr 1fr auto;gap:10px;align-items:end;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="form-group" style="margin:0"><label>Title</label><input type="text" name="title" required placeholder="e.g. New Greenhouse vs. Beehives"></div>
    <div class="form-group" style="margin:0"><label>Deadline</label><input type="datetime-local" name="deadline" required></div>
    <button type="submit" name="create_proposal" class="btn btn-primary">Create</button>
  </form>
  <div class="form-group mt-1"><label>Description</label><textarea name="description" form="" placeholder="Optional details…"></textarea></div>
</div>
<?php endif; ?>

<?php foreach ($proposals as $p): ?>
<?php $alreadyVoted = in_array($p['id'], $voted); $isActive = $p['status'] === 'active'; ?>
<div class="card">
  <div class="flex-between">
    <div>
      <strong style="font-size:16px"><?= clean($p['title']) ?></strong>
      <span class="status <?= $isActive ? 'status-active' : 'status-expired' ?>" style="margin-left:8px"><?= $p['status'] ?></span>
    </div>
    <span class="text-muted" style="font-size:13px"><?= $p['total_votes'] ?> vote(s)</span>
  </div>
  <?php if ($p['description']): ?>
    <p style="font-size:14px;margin:8px 0;color:#495057"><?= clean($p['description']) ?></p>
  <?php endif; ?>
  <p class="text-muted" style="font-size:12px">
    Created by <?= clean($p['creator_name']) ?> &nbsp;|&nbsp;
    Deadline: <?= date('d M Y H:i', strtotime($p['deadline'])) ?>
  </p>

  <?php if ($isActive && !$alreadyVoted): ?>
  <form method="POST" style="margin-top:10px;">
    <input type="hidden" name="csrf_token"    value="<?= csrfToken() ?>">
    <input type="hidden" name="proposal_id"   value="<?= $p['id'] ?>">
    <button type="submit" name="cast_vote" class="btn btn-primary">Cast My Vote</button>
  </form>
  <?php elseif ($alreadyVoted): ?>
    <span class="status status-active" style="margin-top:8px;display:inline-block">✓ You voted</span>
  <?php endif; ?>

  <?php if ($user['role'] === 'admin' && $isActive): ?>
  <form method="POST" style="display:inline;margin-left:10px;">
    <input type="hidden" name="csrf_token"   value="<?= csrfToken() ?>">
    <input type="hidden" name="proposal_id"  value="<?= $p['id'] ?>">
    <button type="submit" name="close_proposal" class="btn btn-warning btn-sm mt-1">Close Vote</button>
  </form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
