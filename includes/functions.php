<?php
// ============================================================
// includes/functions.php
// Shared helper functions used across the entire application
// ============================================================

require_once __DIR__ . '/../config/db.php';

// ============================================================
// SESSION & AUTH HELPERS
// ============================================================

/**
 * Start session safely and check for timeout.
 * Called at the top of EVERY protected page.
 */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Auto-logout after SESSION_TIMEOUT seconds of inactivity
    if (isset($_SESSION['last_active']) &&
        (time() - $_SESSION['last_active']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header("Location: " . APP_URL . "/auth/login.php?msg=timeout");
        exit;
    }
    $_SESSION['last_active'] = time();
}

/**
 * Require the user to be logged in.
 * If not, redirect to login page.
 */
function requireLogin(): void {
    startSession();
    if (empty($_SESSION['user_id'])) {
        header("Location: " . APP_URL . "/auth/login.php");
        exit;
    }
}

/**
 * Require a specific role (or one of several roles).
 *
 * Usage: requireRole('admin')
 *        requireRole(['admin', 'warden'])
 *
 * WHY THIS MATTERS:
 * This is the enforcement of our RBAC functional requirement (FR 27-28).
 * Every protected page calls this at the top. If the user's role
 * doesn't match, they get redirected — they never see the page.
 */
function requireRole(string|array $roles): void {
    requireLogin();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($_SESSION['user_role'], $allowed)) {
        http_response_code(403);
        die("Access denied. You do not have permission to view this page.");
    }
}

/**
 * Get the currently logged-in user's full record from DB.
 */
function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        SELECT u.*, mt.name AS tier_name, mt.discount_pct, mt.priority_boost
        FROM users u
        LEFT JOIN membership_tiers mt ON u.membership_tier_id = mt.id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// ============================================================
// AUDIT LOG
// Called automatically after any significant action.
//
// WHY: FR 29 requires permanently logging all major actions.
// We call logAudit() in every function that modifies data
// so the audit trail is built automatically, not manually.
// ============================================================

/**
 * @param string $actionType  e.g. 'lease_created', 'tool_checked_out'
 * @param string $table       the affected DB table
 * @param int    $affectedId  the ID of the affected record
 * @param string $description human-readable description
 */
function logAudit(string $actionType, string $table = '', int $affectedId = 0, string $description = ''): void {
    $pdo    = getPDO();
    $userId = $_SESSION['user_id'] ?? null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $stmt = $pdo->prepare("
        INSERT INTO audit_log (user_id, action_type, affected_table, affected_id, description, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $actionType, $table, $affectedId, $description, $ip]);
}

// ============================================================
// NOTIFICATIONS
// Push a notification into a user's inbox.
// ============================================================

/**
 * @param int    $userId  recipient
 * @param string $type    notification category (e.g. 'lease_reminder')
 * @param string $message the message text
 */
function sendNotification(int $userId, string $type, string $message): void {
    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, message)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$userId, $type, $message]);
}

/**
 * Send a notification to ALL active users.
 * Used for emergency broadcasts (FR for Emergency Site Broadcaster).
 */
function broadcastNotification(string $type, string $message): void {
    $pdo   = getPDO();
    $users = $pdo->query("SELECT id FROM users WHERE is_active = 1")->fetchAll();
    $stmt  = $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)");
    foreach ($users as $u) {
        $stmt->execute([$u['id'], $type, $message]);
    }
}

/**
 * Count unread notifications for the current user.
 * Shown in the navbar as a badge.
 */
function countUnread(): int {
    if (empty($_SESSION['user_id'])) return 0;
    $pdo  = getPDO();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    return (int)$stmt->fetchColumn();
}

// ============================================================
// MEMBERSHIP TIER RECALCULATION
//
// WHY: The membership tier is not stored statically — it is
// recalculated based on community_points AND rental_months.
// This function is called whenever either value changes.
// ============================================================

/**
 * Update a user's membership tier based on their current
 * community_points and rental_months.
 */
function recalculateMembershipTier(int $userId): void {
    $pdo  = getPDO();

    // Fetch current user stats
    $stmt = $pdo->prepare("SELECT community_points, rental_months FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return;

    // Find the highest tier the user qualifies for
    // They must meet BOTH the points AND months threshold
    $tierStmt = $pdo->prepare("
        SELECT id FROM membership_tiers
        WHERE min_points <= ? AND min_months <= ?
        ORDER BY min_points DESC, min_months DESC
        LIMIT 1
    ");
    $tierStmt->execute([$user['community_points'], $user['rental_months']]);
    $tier = $tierStmt->fetch();

    if ($tier) {
        $pdo->prepare("UPDATE users SET membership_tier_id = ? WHERE id = ?")
            ->execute([$tier['id'], $userId]);
    }
}

// ============================================================
// PRIORITY SCORE CALCULATION (Waitlist)
//
// WHY: FR 12-14 require a priority score for the waitlist.
// Score = community_points + membership priority_boost + days_waiting
// ============================================================

function recalculateWaitlistPriority(int $userId): void {
    $pdo  = getPDO();

    $stmt = $pdo->prepare("
        SELECT u.community_points, mt.priority_boost, w.joined_at
        FROM users u
        LEFT JOIN membership_tiers mt ON u.membership_tier_id = mt.id
        LEFT JOIN waitlist w ON w.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $data = $stmt->fetch();
    if (!$data || !$data['joined_at']) return;

    $daysWaiting   = (int)((time() - strtotime($data['joined_at'])) / 86400);
    $priorityScore = $data['community_points'] + $data['priority_boost'] + $daysWaiting;

    $pdo->prepare("UPDATE waitlist SET priority_score = ? WHERE user_id = ?")
        ->execute([$priorityScore, $userId]);
}

// ============================================================
// ALLERGEN CHECK
// Used by marketplace listing creation (FR 36)
// ============================================================

/**
 * Check if a produce type matches any known allergen category.
 * Returns the category name if found, null if safe.
 */
function checkAllergen(string $produceType): ?string {
    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        SELECT category_name FROM allergen_categories
        WHERE LOWER(?) LIKE CONCAT('%', LOWER(produce_keyword), '%')
        LIMIT 1
    ");
    $stmt->execute([$produceType]);
    $row = $stmt->fetch();
    return $row ? $row['category_name'] : null;
}

// ============================================================
// CSRF PROTECTION
//
// WHY: Without CSRF protection, a malicious website could
// submit forms on behalf of a logged-in user without their
// knowledge. This is a basic security requirement.
// ============================================================

/**
 * Generate a CSRF token for forms and store it in session.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the submitted CSRF token against the session token.
 * Call this at the top of every POST handler.
 */
function validateCsrf(): void {
    if (!isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        die("Invalid request. Please go back and try again.");
    }
}

// ============================================================
// INPUT SANITIZATION
// ============================================================

/**
 * Clean a string input for safe display in HTML.
 * Prevents XSS (Cross-Site Scripting) attacks.
 */
function clean(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a URL and stop execution.
 */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}
