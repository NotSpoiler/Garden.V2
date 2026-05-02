<?php
// auth/logout.php
require_once __DIR__ . '/../includes/functions.php';
startSession();

if (!empty($_SESSION['user_id'])) {
    logAudit('user_logout', 'users', $_SESSION['user_id'], 'User logged out');
}

// Destroy the session completely
session_unset();
session_destroy();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

header("Location: " . APP_URL . "/auth/login.php");
exit;
