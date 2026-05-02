<?php
// ============================================================
// includes/header.php
// Included at the top of every page.
// Renders the navbar, notification badge, and opens <main>
// ============================================================
// This file expects $pageTitle to be set before including it.
// Example: $pageTitle = "My Plot"; require 'includes/header.php';
// ============================================================
require_once __DIR__ . '/../includes/functions.php';
startSession();
$user        = currentUser();
$unread      = $user ? countUnread() : 0;
$role        = $user['role'] ?? 'guest';
$pageTitle   = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= clean($pageTitle) ?> — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>

<nav class="navbar">
  <a class="navbar-brand" href="<?= APP_URL ?>">🌱 <?= APP_NAME ?></a>

  <div class="navbar-links">
    <?php if ($user): ?>

      <?php if ($role === 'admin'): ?>
        <a href="<?= APP_URL ?>/admin/dashboard.php">Admin Panel</a>
        <a href="<?= APP_URL ?>/admin/users.php">Users</a>
        <a href="<?= APP_URL ?>/admin/plots.php">Plots</a>
        <a href="<?= APP_URL ?>/admin/audit.php">Audit Log</a>

      <?php elseif ($role === 'warden'): ?>
        <a href="<?= APP_URL ?>/warden/dashboard.php">Warden Panel</a>
        <a href="<?= APP_URL ?>/warden/inspections.php">Inspections</a>

      <?php elseif ($role === 'plot_owner'): ?>
        <a href="<?= APP_URL ?>/plot_owner/dashboard.php">My Plot</a>
        <a href="<?= APP_URL ?>/shared/tools.php">Tools</a>
        <a href="<?= APP_URL ?>/shared/marketplace.php">Marketplace</a>
        <a href="<?= APP_URL ?>/shared/community.php">Community</a>

      <?php else: // member ?>
        <a href="<?= APP_URL ?>/member/dashboard.php">Dashboard</a>
        <a href="<?= APP_URL ?>/shared/tools.php">Tools</a>
        <a href="<?= APP_URL ?>/shared/marketplace.php">Marketplace</a>
        <a href="<?= APP_URL ?>/shared/community.php">Community</a>
      <?php endif; ?>

      <!-- Notification bell with unread badge -->
      <a href="<?= APP_URL ?>/shared/notifications.php" class="notif-link">
        🔔 <?php if ($unread > 0): ?>
          <span class="badge"><?= $unread ?></span>
        <?php endif; ?>
      </a>

      <!-- User info + logout -->
      <span class="user-info">
        <?= clean($user['full_name']) ?>
        <span class="tier-badge tier-<?= strtolower($user['tier_name'] ?? 'bronze') ?>">
          <?= clean($user['tier_name'] ?? 'Bronze') ?>
        </span>
      </span>
      <a href="<?= APP_URL ?>/auth/logout.php">Logout</a>

    <?php else: ?>
      <a href="<?= APP_URL ?>/auth/login.php">Login</a>
      <a href="<?= APP_URL ?>/auth/register.php">Register</a>
    <?php endif; ?>
  </div>
</nav>

<main class="container">
