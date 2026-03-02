<?php
/**
 * DARN Dashboard - Database Configuration
 * Update these values for your environment
 */

define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'darn_dashboard');
define('DB_PORT', getenv('MYSQLPORT') ?: '3306');
define('DB_CHARSET', 'utf8mb4');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // MySQL 8 strict mode breaks GROUP BY and zero-date queries
            $pdo->exec("SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''), 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '')");
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Helper: format number as Euro
function eur($val) {
    if ($val === null || $val === '') return '-';
    return number_format((float)$val, 2, '.', ',');
}

// Helper: format integer
function num($val) {
    if ($val === null || $val === '') return '-';
    return number_format((int)$val, 0, '.', ',');
}

// Helper: safe output
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Inject data-filter attributes into a <th> HTML string for Excel-like column filters.
 */
function withFilter($thHtml, $filterName, $filterValues) {
    $attr = ' data-filter="' . e($filterName) . '" data-filter-values="' . e(json_encode($filterValues, JSON_UNESCAPED_UNICODE)) . '"';
    return preg_replace('/<th\b/', '<th' . $attr, $thHtml, 1);
}

/**
 * Read multi-select filter params from URL.
 * Returns array of selected values, or empty array if no filter active.
 */
function getFilterParam($name) {
    return $_GET[$name] ?? [];
}

/**
 * Build WHERE IN clause for a multi-select filter.
 * Returns ['sql' => '...', 'params' => [...]] or null if no filter active.
 */
function buildFilterIn($filterValues, $column, $tableAlias = '') {
    if (empty($filterValues)) return null;
    $col = $tableAlias ? "{$tableAlias}.{$column}" : $column;
    $placeholders = implode(',', array_fill(0, count($filterValues), '?'));
    return [
        'sql' => "{$col} IN ({$placeholders})",
        'params' => array_values($filterValues)
    ];
}
