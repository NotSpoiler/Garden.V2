<?php
// index.php — Public landing page
// Guests see this. Logged-in users are redirected to their dashboard.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
startSession();

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? 'member';
    $destinations = [
        'admin'      => APP_URL . '/admin/dashboard.php',
        'warden'     => APP_URL . '/warden/dashboard.php',
        'plot_owner' => APP_URL . '/plot_owner/dashboard.php',
        'member'     => APP_URL . '/member/dashboard.php',
    ];
    redirect($destinations[$role] ?? APP_URL . '/member/dashboard.php');
}

// Public stats for guests
try {
    $pdo         = getPDO();
    $totalPlots  = $pdo->query("SELECT COUNT(*) FROM plots")->fetchColumn();
    $available   = $pdo->query("SELECT COUNT(*) FROM plots WHERE status = 'available'")->fetchColumn();
    $members     = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    $trades      = $pdo->query("SELECT COUNT(*) FROM flash_trade_posts WHERE status = 'claimed'")->fetchColumn();
} catch (Exception $e) {
    $totalPlots = $available = $members = $trades = '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<style>
.hero {
    background: linear-gradient(135deg, #2d6a4f 0%, #40916c 60%, #74c69d 100%);
    color: #fff;
    padding: 80px 24px;
    text-align: center;
}
.hero h1 { font-size: 36px; margin-bottom: 14px; }
.hero p  { font-size: 18px; opacity: .88; max-width: 560px; margin: 0 auto 28px; }
.features { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; padding: 40px 24px; max-width: 1100px; margin: 0 auto; }
.feature-card { background: #fff; border-radius: 10px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.feature-icon { font-size: 36px; margin-bottom: 10px; }
.feature-title { font-weight: bold; color: #2d6a4f; margin-bottom: 6px; }
.feature-desc  { font-size: 13px; color: #6c757d; line-height: 1.5; }
@media(max-width:768px) { .features { grid-template-columns: 1fr 1fr; } .hero h1 { font-size: 26px; } }
</style>
</head>
<body>

<nav class="navbar">
  <a class="navbar-brand" href="<?= APP_URL ?>">🌱 <?= APP_NAME ?></a>
  <div class="navbar-links">
    <a href="<?= APP_URL ?>/auth/login.php">Login</a>
    <a href="<?= APP_URL ?>/auth/register.php" style="background:#40916c;color:#fff;padding:6px 14px;border-radius:8px;">Join Now</a>
  </div>
</nav>

<!-- Hero -->
<div class="hero">
  <h1>🌱 Grow Together, Share Together</h1>
  <p>The complete digital platform for managing your community garden — plots, tools, harvests, and more, all in one place.</p>
  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
    <a href="<?= APP_URL ?>/auth/register.php" class="btn" style="background:#fff;color:#2d6a4f;font-size:16px;padding:12px 28px">Get Started — It's Free</a>
    <a href="<?= APP_URL ?>/auth/login.php"    class="btn" style="background:rgba(255,255,255,.15);color:#fff;font-size:16px;padding:12px 28px;border:1px solid rgba(255,255,255,.4)">Log In</a>
  </div>
</div>

<!-- Stats bar -->
<div style="background:#f0e0c8;padding:18px 24px;">
  <div style="display:flex;justify-content:center;gap:48px;max-width:700px;margin:0 auto;flex-wrap:wrap;text-align:center;">
    <div><strong style="font-size:22px;color:#2d6a4f"><?= $totalPlots ?></strong><br><span style="font-size:13px;color:#6b4226">Garden Plots</span></div>
    <div><strong style="font-size:22px;color:#2d6a4f"><?= $available ?></strong><br><span style="font-size:13px;color:#6b4226">Available Now</span></div>
    <div><strong style="font-size:22px;color:#2d6a4f"><?= $members ?></strong><br><span style="font-size:13px;color:#6b4226">Active Members</span></div>
    <div><strong style="font-size:22px;color:#2d6a4f"><?= $trades ?></strong><br><span style="font-size:13px;color:#6b4226">Harvests Traded</span></div>
  </div>
</div>

<!-- Features -->
<div class="features">
  <div class="feature-card"><div class="feature-icon">🗺️</div><div class="feature-title">Plot Management</div><div class="feature-desc">Visual map of all garden plots. Rent, renew, track soil health and lease status in one place.</div></div>
  <div class="feature-card"><div class="feature-icon">🔧</div><div class="feature-title">Tool Library</div><div class="feature-desc">Reserve shared tools, track their maintenance status, and report damage with a full state machine.</div></div>
  <div class="feature-card"><div class="feature-icon">🛒</div><div class="feature-title">Harvest Marketplace</div><div class="feature-desc">Flash trades for perishables, gift economy donations, and peer quality ratings for produce.</div></div>
  <div class="feature-card"><div class="feature-icon">🤝</div><div class="feature-title">Community Hub</div><div class="feature-desc">Volunteer shift scheduling, communal task tracking, fund allocation voting, and a P2P advice board.</div></div>
  <div class="feature-card"><div class="feature-icon">🌱</div><div class="feature-title">Seed Bank</div><div class="feature-desc">Community seed sharing with viability tracking — exchange seeds and earn credits.</div></div>
  <div class="feature-card"><div class="feature-icon">🐛</div><div class="feature-title">Pest Alerts</div><div class="feature-desc">Report transmissible pests and automatically alert adjacent plot owners in real time.</div></div>
  <div class="feature-card"><div class="feature-icon">🏅</div><div class="feature-title">Membership Tiers</div><div class="feature-desc">Earn Bronze → Silver → Gold → Platinum based on rental duration and community contributions. Unlock rental discounts.</div></div>
  <div class="feature-card"><div class="feature-icon">🔐</div><div class="feature-title">Role-Based Access</div><div class="feature-desc">Distinct dashboards for Members, Plot Owners, Garden Wardens, and Admins. Secure and private.</div></div>
</div>

<footer class="footer">
  <p>&copy; <?= date('Y') ?> <?= APP_NAME ?> — Team 38, CS251 Software Engineering 1, Spring 2026</p>
</footer>

</body>
</html>
