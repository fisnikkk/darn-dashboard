<?php
/**
 * GoDaddy Database Connection (READ-ONLY)
 * Connects to adaptive_cylinder.delivery_report on GoDaddy's MySQL
 *
 * Set these in Railway dashboard → Variables:
 *   GODADDY_DB_HOST = n1nlmysql29plsk.secureserver.net
 *   GODADDY_DB_NAME = adaptive_cylinder
 *   GODADDY_DB_USER = adaptive_jd
 *   GODADDY_DB_PASS = (password)
 */

define('GD_DB_HOST', getenv('GODADDY_DB_HOST') ?: 'n1nlmysql29plsk.secureserver.net');
define('GD_DB_NAME', getenv('GODADDY_DB_NAME') ?: 'adaptive_cylinder');
define('GD_DB_USER', getenv('GODADDY_DB_USER') ?: 'adaptive_jd');
define('GD_DB_PASS', getenv('GODADDY_DB_PASS') ?: '');
define('GD_DB_PORT', getenv('GODADDY_DB_PORT') ?: '3306');

function getGoDaddyDB() {
    static $pdo = null;
    static $tried = false;

    if ($tried) return $pdo;
    $tried = true;

    if (!GD_DB_PASS) return null;

    try {
        $dsn = "mysql:host=" . GD_DB_HOST . ";port=" . GD_DB_PORT . ";dbname=" . GD_DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, GD_DB_USER, GD_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("GoDaddy DB connection failed: " . $e->getMessage());
        return null;
    }
}
