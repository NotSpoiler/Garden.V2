<?php
// ============================================================
// auth/register.php
//
// HOW REGISTRATION WORKS:
// 1. User fills the form — full name, email, password
// 2. We validate: required fields, valid email, password length,
//    password confirmation match, and unique email check
// 3. password_hash() creates a bcrypt hash of the password
//    BCRYPT is a one-way hash — even if the DB is stolen,
//    the attacker cannot recover the original passwords
// 4. New users are assigned role = 'member' by default
// 5. Admin can later upgrade them to plot_owner, warden, etc.
// 6. On success, auto-login and redirect to member dashboard
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();

if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $formData['full_name'] = trim($_POST['full_name'] ?? '');
    $formData['email']     = trim($_POST['email'] ?? '');
    $password              = $_POST['password'] ?? '';
    $confirm               = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($formData['full_name'])) $errors[] = 'Full name is required.';
    if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Check email uniqueness
    if (empty($errors)) {
        $pdo  = getPDO();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$formData['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (empty($errors)) {
        $pdo  = getPDO();

        // password_hash with PASSWORD_BCRYPT:
        // - Automatically generates a salt (random string mixed into the hash)
        // - Cost factor 12 means the hash takes ~250ms to compute
        //   This slows down brute-force attacks significantly
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, email, password_hash, role, membership_tier_id)
            VALUES (?, ?, ?, 'member', 1)
        ");
        $stmt->execute([$formData['full_name'], $formData['email'], $hash]);
        $newId = (int)$pdo->lastInsertId();

        // Auto-login the new user
        $_SESSION['user_id']     = $newId;
        $_SESSION['user_role']   = 'member';
        $_SESSION['user_name']   = $formData['full_name'];
        $_SESSION['last_active'] = time();
        session_regenerate_id(true);

        logAudit('user_registered', 'users', $newId, "New member registered: {$formData['email']}");

        sendNotification($newId, 'welcome',
            'Welcome to ' . APP_NAME . '! Explore plots, tools, and the community marketplace.');

        redirect(APP_URL . '/member/dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<nav class="navbar">
  <a class="navbar-brand" href="<?= APP_URL ?>">🌱 <?= APP_NAME ?></a>
  <div class="navbar-links">
    <a href="<?= APP_URL ?>/auth/login.php">Login</a>
  </div>
</nav>

<div class="container">
<div class="auth-wrap">
  <div class="card">
    <div class="card-title">Create Your Account</div>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
          <div><?= clean($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

      <div class="form-group">
        <label for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name"
               value="<?= clean($formData['full_name'] ?? '') ?>"
               required placeholder="Ahmed Mohamed">
      </div>

      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               value="<?= clean($formData['email'] ?? '') ?>"
               required placeholder="your@email.com">
      </div>

      <div class="form-group">
        <label for="password">Password <span class="text-muted">(min. 8 characters)</span></label>
        <input type="password" id="password" name="password"
               required placeholder="••••••••">
      </div>

      <div class="form-group">
        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               required placeholder="••••••••">
      </div>

      <button type="submit" class="btn btn-primary btn-block">Create Account</button>
    </form>

    <hr class="divider">
    <p class="text-muted" style="text-align:center">
      Already have an account?
      <a href="<?= APP_URL ?>/auth/login.php">Log in</a>
    </p>
  </div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
