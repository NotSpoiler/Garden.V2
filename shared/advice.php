<?php // shared/advice.php — P2P Advice Exchange
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','warden','admin']);
$pdo = getPDO(); $user = currentUser();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_question'])) {
    validateCsrf();
    $q = trim($_POST['question'] ?? '');
    if (strlen($q) < 10) { $error = 'Question too short.'; }
    else {
        $pdo->prepare("INSERT INTO advice_posts (posted_by, question) VALUES (?,?)")->execute([$user['id'], $q]);
        $success = 'Question posted!';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_answer'])) {
    validateCsrf();
    $postId  = (int)$_POST['post_id'];
    $content = trim($_POST['content'] ?? '');
    if (!$content) { $error = 'Answer cannot be empty.'; }
    else {
        $pdo->prepare("INSERT INTO advice_answers (post_id, answered_by, content) VALUES (?,?,?)")
            ->execute([$postId, $user['id'], $content]);
        $success = 'Answer submitted!';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_best'])) {
    validateCsrf();
    $postId   = (int)$_POST['post_id'];
    $answerId = (int)$_POST['answer_id'];
    // Verify current user owns the post
    $check = $pdo->prepare("SELECT id FROM advice_posts WHERE id = ? AND posted_by = ?");
    $check->execute([$postId, $user['id']]);
    if ($check->fetch()) {
        $pdo->prepare("UPDATE advice_posts SET best_answer_id = ? WHERE id = ?")->execute([$answerId, $postId]);
        // Award 5 seed credits to the best answer author
        $ans = $pdo->prepare("SELECT answered_by FROM advice_answers WHERE id = ?");
        $ans->execute([$answerId]); $ansRow = $ans->fetch();
        if ($ansRow) {
            $pdo->prepare("UPDATE users SET seed_credits = seed_credits + 5 WHERE id = ?")
                ->execute([$ansRow['answered_by']]);
            $pdo->prepare("UPDATE advice_answers SET credits_awarded = 5 WHERE id = ?")
                ->execute([$answerId]);
            sendNotification($ansRow['answered_by'], 'best_answer',
                'Your answer was selected as the best answer! You earned 5 Seed Bank Credits.');
        }
        $success = 'Best answer selected! 5 seed credits awarded.';
    }
}

$posts = $pdo->query("
    SELECT ap.*, u.full_name AS author_name,
           (SELECT COUNT(*) FROM advice_answers WHERE post_id = ap.id) AS answer_count
    FROM advice_posts ap JOIN users u ON ap.posted_by = u.id
    ORDER BY ap.created_at DESC LIMIT 30
")->fetchAll();

$pageTitle = 'P2P Advice Exchange';
require __DIR__ . '/../includes/header.php';
?>
<div class="flex-between mb-2">
  <h1 class="page-title">💬 P2P Advice Exchange</h1>
  <button class="btn btn-primary" onclick="document.getElementById('newQ').style.display='block'">+ Ask Question</button>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<div id="newQ" style="display:none" class="card mb-2">
  <div class="card-title">Ask a Gardening Question</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="form-group"><textarea name="question" rows="3" required placeholder="e.g. How do I treat aphids on my tomato plants organically?"></textarea></div>
    <button type="submit" name="post_question" class="btn btn-primary">Post Question</button>
  </form>
</div>

<?php foreach ($posts as $post): ?>
<?php
$answers = $pdo->prepare("SELECT aa.*, u.full_name AS author_name FROM advice_answers aa JOIN users u ON aa.answered_by = u.id WHERE aa.post_id = ? ORDER BY aa.created_at");
$answers->execute([$post['id']]); $answerList = $answers->fetchAll();
?>
<div class="card mb-2">
  <div style="display:flex;justify-content:space-between;">
    <strong style="font-size:15px"><?= clean($post['question']) ?></strong>
    <?php if ($post['best_answer_id']): ?><span class="status status-active">✓ Answered</span><?php endif; ?>
  </div>
  <p class="text-muted" style="font-size:12px">
    By <?= clean($post['author_name']) ?> — <?= date('d M Y', strtotime($post['created_at'])) ?>
    &nbsp;|&nbsp; <?= $post['answer_count'] ?> answer(s)
  </p>

  <?php foreach ($answerList as $ans): ?>
  <div style="border-left:3px solid <?= $ans['id'] == $post['best_answer_id'] ? '#2d6a4f' : '#ced4da' ?>;padding:8px 12px;margin:8px 0;background:#f8f9fa;border-radius:0 6px 6px 0;">
    <?= clean($ans['content']) ?>
    <div style="font-size:12px;color:#6c757d;margin-top:4px">
      <?= clean($ans['author_name']) ?>
      <?php if ($ans['id'] == $post['best_answer_id']): ?><span class="status status-active" style="margin-left:8px">Best Answer ⭐</span><?php endif; ?>
      <?php if ($post['posted_by'] == $user['id'] && !$post['best_answer_id']): ?>
        <form method="POST" style="display:inline;margin-left:10px;">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="post_id"    value="<?= $post['id'] ?>">
          <input type="hidden" name="answer_id"  value="<?= $ans['id'] ?>">
          <button type="submit" name="select_best" class="btn btn-primary btn-sm">Mark Best</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <form method="POST" style="margin-top:10px;display:flex;gap:8px;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="post_id"    value="<?= $post['id'] ?>">
    <input type="text"   name="content" placeholder="Write your answer…" style="flex:1;padding:7px 10px;border:1px solid #ced4da;border-radius:6px;font-size:13px;">
    <button type="submit" name="post_answer" class="btn btn-secondary btn-sm">Reply</button>
  </form>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
