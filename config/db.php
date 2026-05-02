<?php
// ============================================================
// config/db.php
// Database connection using PDO (PHP Data Objects)
//
// WHY PDO?
// PDO lets us use prepared statements, which prevent SQL
// injection — a critical security requirement in our SRS.
// Unlike mysqli, PDO works with multiple database types,
// making the system easier to maintain in the future.
// ============================================================

define("DB_HOST", "localhost");
define("DB_NAME", "community_garden");
define("DB_USER", "root"); // change to your MySQL username
define("DB_PASS", ""); // change to your MySQL password
define("DB_CHARSET", "utf8mb4");

// APP SETTINGS
define("APP_NAME", "Community Garden Manager");
define("APP_URL", "http://localhost/garden"); // adjust to your setup
define("SESSION_TIMEOUT", 1800); // 30 minutes

// ============================================================
// getPDO() — returns a singleton PDO connection
//
// Singleton means we create the connection ONCE and reuse it
// across all files. This avoids opening multiple database
// connections per page request.
// ============================================================
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn =
            "mysql:host=" .
            DB_HOST .
            ";dbname=" .
            DB_NAME .
            ";charset=" .
            DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // ERRMODE_EXCEPTION: throws a PDOException on any DB error
            // instead of silently failing — easier to debug

            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // FETCH_ASSOC: returns rows as associative arrays
            // e.g. $row['email'] instead of $row[0]

            PDO::ATTR_EMULATE_PREPARES => false,
            // false = use REAL prepared statements, not emulated ones
            // Real prepared statements are safer against SQL injection
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never show raw DB errors to users — log them instead
            error_log("DB Connection failed: " . $e->getMessage());
            die("Database connection error. Please contact the administrator.");
        }
    }

    return $pdo;
}
