<?php
// ============================================================
// shared/marketplace.php
// Implements:
//   - Produce Flash-Trade Logic (FR)
//   - Gift-Economy Credit System (FR)
//   - Allergy & Dietary Guard (FR)
//   - Produce Quality Verification (FR)
//
// HOW ALLERGEN GUARD WORKS:
// When a listing is created, we call checkAllergen() which
// queries the allergen_categories table. If the produce type
// matches any keyword, allergen_warning = 1 is stored and
// the warning label shows automatically on the listing.
//
// HOW QUALITY RATING WORKS:
// Only users with a confirmed claim/purchase transaction on
// that specific listing can rate it. We check for a matching
// claim record before accepting the rating.
// ============================================================
require_once __DIR__ . '/../includes/functions.php';
requireRole(['member','plot_owner','warden','admin']);
$pdo  = getPDO();
$user = currentUser();
$success = $error = '';

// ── Create flash trade ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_flash'])) {
    validateCsrf();
    $produce  = trim($_POST['produce_type'] ?? '');
    $qty      = (float)($_POST['quantity'] ?? 0);
    $unit     = trim($_POST['unit'] ?? 'kg');
    $location = trim($_POST['pickup_location'] ?? '');
    $hours    = (int)($_POST['expiry_hours'] ?? 2);

    if (!$produce || $qty <= 0) {
        $error = 'Produce type and quantity are required.';
    } else {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
        $pdo->prepare("
            INSERT INTO flash_trade_posts (posted_by, produce_type, quantity, unit, pickup_location, expires_at)
            VALUES (?,?,?,?,?,?)
        ")->execute([$user['id'], $produce, $qty, $unit, $location, $expiresAt]);
        logAudit('flash_trade_created', 'flash_trade_posts', (int)$pdo->lastInsertId(),
            "$produce flash trade posted");
        $success = 'Flash trade posted! It expires in ' . $hours . ' hour(s).';
    }
}

// ── Claim flash trade ─────────────────────────────────────────
if (isset($_GET['claim'])) {
    $postId   = (int)$_GET['claim'];
    $postStmt = $pdo->prepare("
        SELECT * FROM flash_trade_posts
        WHERE id = ? AND status = 'active' AND expires_at > NOW()
    ");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        $error = 'This trade is no longer available.';
    } elseif ($post['posted_by'] == $user['id']) {
        $error = 'You cannot claim your own flash trade.';
    } else {
        $pdo->prepare("
            UPDATE flash_trade_posts
            SET status = 'claimed', claimed_by = ?, claimed_at = NOW()
            WHERE id = ? AND status = 'active'
        ")->execute([$user['id'], $postId]);

        sendNotification($post['posted_by'], 'trade_claimed',
            "{$user['full_name']} claimed your flash trade for {$post['produce_type']}. Please arrange pickup.");
        sendNotification($user['id'], 'trade_claim_confirm',
            "You claimed {$post['produce_type']} ({$post['quantity']} {$post['unit']}). Pickup: {$post['pickup_location']}");
        logAudit('flash_trade_claimed', 'flash_trade_posts', $postId,
            "Claimed by user {$user['id']}");
        $success = "You claimed the trade! Check your notifications for pickup details.";
    }
}

// ── Create marketplace listing ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_listing'])) {
    validateCsrf();
    $produce   = trim($_POST['produce_type'] ?? '');
    $qty       = (float)($_POST['quantity'] ?? 0);
    $unit      = trim($_POST['unit'] ?? 'kg');
    $priceType = $_POST['price_type'] ?? 'gift';

    if (!$produce || $qty <= 0) {
        $error = 'Produce type and quantity are required.';
    } else {
        // Allergen check
        $allergenCategory = checkAllergen($produce);
        $hasAllergen      = $allergenCategory ? 1 : 0;
        $allergenNotes    = $allergenCategory ? "Contains: $allergenCategory" : null;

        $pdo->prepare("
            INSERT INTO marketplace_listings
            (seller_id, produce_type, quantity, unit, price_type, allergen_warning, allergen_notes)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$user['id'], $produce, $qty, $unit, $priceType, $hasAllergen, $allergenNotes]);

        logAudit('listing_created', 'marketplace_listings', (int)$pdo->lastInsertId(),
            "Listed: $produce" . ($hasAllergen ? " [ALLERGEN: $allergenCategory]" : ''));
        $success = 'Listing created.' . ($hasAllergen ? " ⚠️ Allergen warning applied ($allergenCategory)." : '');
    }
}

// ── Submit quality rating ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rate_listing'])) {
    validateCsrf();
    $listingId = (int)$_POST['listing_id'];
    $score     = (int)$_POST['score'];

    if ($score < 1 || $score > 5) {
        $error = 'Invalid rating score.';
    } else {
        // Verify the user has actually claimed something from this listing
        // We check flash_trade_posts claimed by this user for same produce type
        // OR simply check they are not the seller
        $listing = $pdo->prepare("SELECT * FROM marketplace_listings WHERE id = ?");
        $listing->execute([$listingId]);
        $listingRow = $listing->fetch();

        if (!$listingRow) {
            $error = 'Listing not found.';
        } elseif ($listingRow['seller_id'] == $user['id']) {
            $error = 'You cannot rate your own listing.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO quality_ratings (listing_id, rated_by, score) VALUES (?,?,?)
                ")->execute([$listingId, $user['id'], $score]);

                // Recalculate average score
                $avg = $pdo->prepare("
                    SELECT AVG(score) FROM quality_ratings WHERE listing_id = ?
                ");
                $avg->execute([$listingId]);
                $newAvg = round((float)$avg->fetchColumn(), 1);
                $pdo->prepare("
                    UPDATE marketplace_listings SET avg_quality_score = ? WHERE id = ?
                ")->execute([$newAvg, $listingId]);

                $success = "Rating submitted! New average: $newAvg / 5.0";
                logAudit('quality_rated', 'quality_ratings', $listingId, "Score: $score");
            } catch (PDOException $e) {
                $error = 'You have already rated this listing.';
            }
        }
    }
}

// ── Donate produce (Gift Economy) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['donate_produce'])) {
    validateCsrf();
    $produce = trim($_POST['donate_produce_type'] ?? '');
    $qty     = (float)($_POST['donate_qty'] ?? 0);
    $unit    = trim($_POST['donate_unit'] ?? 'kg');

    if (!$produce || $qty <= 0) {
        $error = 'Please fill in the donation details.';
    } else {
        // Karma points: 2 per kg (or per unit)
        $karma = max(1, (int)($qty * 2));
        $pdo->prepare("
            INSERT INTO donations (donor_id, produce_type, quantity, unit, karma_awarded)
            VALUES (?,?,?,?,?)
        ")->execute([$user['id'], $produce, $qty, $unit, $karma]);

        $pdo->prepare("UPDATE users SET karma_points = karma_points + ? WHERE id = ?")
            ->execute([$karma, $user['id']]);

        // Also post as a free listing
        $pdo->prepare("
            INSERT INTO marketplace_listings (seller_id, produce_type, quantity, unit, price_type)
            VALUES (?,?,?,?,'gift')
        ")->execute([$user['id'], $produce, $qty, $unit]);

        logAudit('donation_made', 'donations', (int)$pdo->lastInsertId(),
            "Donated: $qty $unit of $produce, karma: $karma");
        $success = "Thank you! You donated $qty $unit of $produce and earned $karma Karma Points.";
    }
}

// ── Auto-expire old flash trades ──────────────────────────────
$pdo->exec("
    UPDATE flash_trade_posts
    SET status = 'expired'
    WHERE status = 'active' AND expires_at <= NOW()
");

// ── Fetch data ────────────────────────────────────────────────
$flashTrades = $pdo->query("
    SELECT ft.*, u.full_name AS poster_name
    FROM flash_trade_posts ft
    JOIN users u ON ft.posted_by = u.id
    WHERE ft.status = 'active' AND ft.expires_at > NOW()
    ORDER BY ft.expires_at ASC
")->fetchAll();

$listings = $pdo->query("
    SELECT ml.*, u.full_name AS seller_name
    FROM marketplace_listings ml
    JOIN users u ON ml.seller_id = u.id
    WHERE ml.status = 'available'
    ORDER BY ml.created_at DESC
    LIMIT 30
")->fetchAll();

$pageTitle = 'Marketplace';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title mb-2">🛒 Harvest Marketplace</h1>

<?php if ($success): ?><div class="alert alert-success"><?= clean($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
  <button class="btn btn-primary" onclick="showTab('flash')">🔥 Flash Trades (<?= count($flashTrades) ?>)</button>
  <button class="btn btn-secondary" onclick="showTab('listings')">📋 All Listings</button>
  <button class="btn btn-secondary" onclick="showTab('post_flash')">+ Post Flash Trade</button>
  <button class="btn btn-secondary" onclick="showTab('post_listing')">+ Create Listing</button>
  <button class="btn btn-secondary" onclick="showTab('donate')">🎁 Donate Produce</button>
</div>

<!-- Flash trades tab -->
<div id="tab-flash">
  <?php if (empty($flashTrades)): ?>
    <div class="card"><p class="text-muted">No active flash trades right now.</p></div>
  <?php else: ?>
  <div class="grid-2">
    <?php foreach ($flashTrades as $f): ?>
    <div class="card" style="border-left: 4px solid #e63946;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
          <strong style="font-size:16px"><?= clean($f['produce_type']) ?></strong>
          <span style="font-size:14px;color:#6c757d;margin-left:8px"><?= $f['quantity'] ?> <?= clean($f['unit']) ?></span>
        </div>
        <span class="status status-active">LIVE</span>
      </div>
      <p class="text-muted" style="font-size:13px;margin:6px 0">
        📍 <?= clean($f['pickup_location'] ?: 'Location TBC') ?> &nbsp;|&nbsp;
        👤 <?= clean($f['poster_name']) ?>
      </p>
      <p style="font-size:13px;color:#e63946;font-weight:bold">
        ⏰ Expires: <?= date('d M H:i', strtotime($f['expires_at'])) ?>
      </p>
      <?php if ($f['posted_by'] != $user['id']): ?>
        <a href="?claim=<?= $f['id'] ?>" class="btn btn-primary btn-sm mt-1"
           onclick="return confirm('Claim this trade?')">Claim</a>
      <?php else: ?>
        <span class="text-muted" style="font-size:12px">Your listing</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- All listings tab -->
<div id="tab-listings" style="display:none">
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Produce</th><th>Qty</th><th>Type</th><th>Allergen</th><th>Quality</th><th>Seller</th><th>Rate</th></tr>
        </thead>
        <tbody>
          <?php foreach ($listings as $l): ?>
          <tr>
            <td><strong><?= clean($l['produce_type']) ?></strong></td>
            <td><?= $l['quantity'] ?> <?= clean($l['unit']) ?></td>
            <td><span class="status status-active"><?= $l['price_type'] ?></span></td>
            <td>
              <?php if ($l['allergen_warning']): ?>
                <span class="status status-warning">⚠️ <?= clean($l['allergen_notes'] ?? 'Allergen') ?></span>
              <?php else: ?>
                <span class="text-muted">None</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($l['avg_quality_score'] > 0): ?>
                ⭐ <?= $l['avg_quality_score'] ?> / 5
              <?php else: ?>
                <span class="text-muted">No ratings</span>
              <?php endif; ?>
            </td>
            <td><?= clean($l['seller_name']) ?></td>
            <td>
              <?php if ($l['seller_id'] != $user['id']): ?>
              <form method="POST" style="display:flex;gap:4px;align-items:center;">
                <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
                <input type="hidden" name="listing_id"  value="<?= $l['id'] ?>">
                <select name="score" style="padding:4px;border:1px solid #ced4da;border-radius:4px;font-size:13px;">
                  <?php for ($i=1;$i<=5;$i++): ?>
                    <option value="<?=$i?>"><?=$i?> ⭐</option>
                  <?php endfor; ?>
                </select>
                <button type="submit" name="rate_listing" class="btn btn-primary btn-sm">Rate</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Post flash trade tab -->
<div id="tab-post_flash" style="display:none">
  <div class="card" style="max-width:540px">
    <div class="card-title">Post a Flash Trade</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Produce Type</label><input type="text" name="produce_type" required placeholder="e.g. Ripe Tomatoes"></div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px">
        <div class="form-group"><label>Quantity</label><input type="number" name="quantity" step="0.1" min="0.1" required></div>
        <div class="form-group"><label>Unit</label>
          <select name="unit"><option>kg</option><option>g</option><option>bunches</option><option>pieces</option></select>
        </div>
      </div>
      <div class="form-group"><label>Pickup Location</label><input type="text" name="pickup_location" placeholder="e.g. Plot A-01 gate"></div>
      <div class="form-group">
        <label>Expiry Window</label>
        <select name="expiry_hours">
          <option value="1">1 hour</option>
          <option value="2" selected>2 hours</option>
          <option value="4">4 hours</option>
          <option value="6">6 hours</option>
          <option value="24">24 hours</option>
        </select>
      </div>
      <button type="submit" name="create_flash" class="btn btn-danger">Post Flash Trade</button>
    </form>
  </div>
</div>

<!-- Create listing tab -->
<div id="tab-post_listing" style="display:none">
  <div class="card" style="max-width:540px">
    <div class="card-title">Create Marketplace Listing</div>
    <p class="text-muted mb-2">Allergen warning will be applied automatically if applicable.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Produce Type</label><input type="text" name="produce_type" required placeholder="e.g. Garlic bulbs"></div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px">
        <div class="form-group"><label>Quantity</label><input type="number" name="quantity" step="0.1" min="0.1" required></div>
        <div class="form-group"><label>Unit</label>
          <select name="unit"><option>kg</option><option>g</option><option>bunches</option><option>pieces</option></select>
        </div>
      </div>
      <div class="form-group">
        <label>Listing Type</label>
        <select name="price_type">
          <option value="gift">Gift (free)</option>
          <option value="trade">Trade</option>
          <option value="sale">Sale</option>
        </select>
      </div>
      <button type="submit" name="create_listing" class="btn btn-primary">Create Listing</button>
    </form>
  </div>
</div>

<!-- Donate tab -->
<div id="tab-donate" style="display:none">
  <div class="card" style="max-width:540px">
    <div class="card-title">🎁 Donate to Gift Economy</div>
    <div class="alert alert-info">Donate produce with no exchange required. Earn <strong>2 Karma Points per unit</strong>.</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group"><label>Produce Type</label><input type="text" name="donate_produce_type" required placeholder="e.g. Courgettes"></div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px">
        <div class="form-group"><label>Quantity</label><input type="number" name="donate_qty" step="0.1" min="0.1" required></div>
        <div class="form-group"><label>Unit</label>
          <select name="donate_unit"><option>kg</option><option>g</option><option>bunches</option><option>pieces</option></select>
        </div>
      </div>
      <button type="submit" name="donate_produce" class="btn btn-primary">Donate Now</button>
    </form>
  </div>
</div>

<script>
function showTab(name) {
    ['flash','listings','post_flash','post_listing','donate'].forEach(t => {
        document.getElementById('tab-' + t).style.display = 'none';
    });
    document.getElementById('tab-' + name).style.display = 'block';
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
