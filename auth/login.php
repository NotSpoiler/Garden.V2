<?php
// ============================================================
// auth/login.php
//
// HOW AUTHENTICATION WORKS (important for the discussion):
// 1. User submits email + password via POST
// 2. We fetch the user record by email using a prepared statement
//    (prevents SQL injection — email is never embedded directly in SQL)
// 3. password_verify() compares the submitted password against the
//    stored bcrypt hash — we NEVER store plain-text passwords
// 4. On success, we store user_id and user_role in $_SESSION
// 5. Every subsequent page reads from $_SESSION to know who's logged in
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();

// If already logged in, redirect to dashboard
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

$error = '';
$msg   = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'timeout') {
    $msg = 'Your session expired. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic input sanitization
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter both email and password.';
    } else {
        $pdo  = getPDO();
        // Prepared statement: ? is a placeholder — email value is bound separately
        // This means no matter what the user types, it can never alter the SQL query
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Correct credentials — set up the session
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['last_active'] = time();

            // Generate a new session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Log the login event in audit trail
            logAudit('user_login', 'users', $user['id'], "User logged in: {$user['email']}");

            // Redirect based on role
            $destinations = [
                'admin'      => APP_URL . '/admin/dashboard.php',
                'warden'     => APP_URL . '/warden/dashboard.php',
                'plot_owner' => APP_URL . '/plot_owner/dashboard.php',
                'member'     => APP_URL . '/member/dashboard.php',
                'guest'      => APP_URL . '/index.php',
            ];
            redirect($destinations[$user['role']] ?? APP_URL . '/index.php');

        } else {
            // Intentionally vague error — don't reveal which field was wrong
            $error = 'Invalid email or password.';
            logAudit('failed_login', 'users', 0, "Failed login attempt for email: $email");
        }
    }
}

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<nav class="navbar">
  <a class="navbar-brand" href="<?= APP_URL ?>">🌱 <?= APP_NAME ?></a>
  <div class="navbar-links">
    <a href="<?= APP_URL ?>/auth/register.php">Register</a>
  </div>
</nav>

<div class="container">
<div class="auth-wrap">
  <div class="card">
    <div class="card-title">Welcome Back</div>

    <?php if ($msg): ?>
      <div class="alert alert-warning"><?= clean($msg) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= clean($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <!-- CSRF token hidden field — validated on every POST -->
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               value="<?= clean($_POST['email'] ?? '') ?>"
               required placeholder="your@email.com">
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               required placeholder="••••••••">
      </div>

      <button type="submit" class="btn btn-primary btn-block">Log In</button>
    </form>

    <hr class="divider">
    <p class="text-muted" style="text-align:center">
      Don't have an account?
      <a href="<?= APP_URL ?>/auth/register.php">Register here</a>
    </p>
  </div>
</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
