<?php
/**
 * DARN Dashboard - GoDaddy Database Connection
 * Read-only connection to the GoDaddy MySQL (adaptive_cylinder database)
 * Used for syncing delivery_report → distribuimi
 *
 * Environment variables (set in Railway dashboard):
 *   GODADDY_DB_HOST = m1nlmysql29plsk.secureserver.net
 *   GODADDY_DB_NAME = adaptive_cylinder
 *   GODADDY_DB_USER = adaptive_jd
 *   GODADDY_DB_PASS = (password from GoDaddy Plesk / api.php)
 *   GODADDY_DB_PORT = 3306  (optional, defaults to 3306)
 */

define('GD_DB_HOST', getenv('GODADDY_DB_HOST') ?: 'm1nlmysql29plsk.secureserver.net');
define('GD_DB_NAME', getenv('GODADDY_DB_NAME') ?: 'adaptive_cylinder');
define('GD_DB_USER', getenv('GODADDY_DB_USER') ?: 'adaptive_jd');
define('GD_DB_PASS', getenv('GODADDY_DB_PASS') ?: '');
define('GD_DB_PORT', getenv('GODADDY_DB_PORT') ?: '3306');

/**
 * Get a READ-ONLY PDO connection to the GoDaddy database.
 * Returns null if credentials are not configured.
 */
function getGoDaddyDB() {
    static $pdo = null;
    static $tried = false;

    if ($tried) return $pdo;
    $tried = true;

    if (!GD_DB_PASS) {
        // No password configured — can't connect
        return null;
    }

    try {
        $dsn = "mysql:host=" . GD_DB_HOST . ";port=" . GD_DB_PORT . ";dbname=" . GD_DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, GD_DB_USER, GD_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10, // 10-second connection timeout
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("GoDaddy DB connection failed: " . $e->getMessage());
        return null;
    }
}
