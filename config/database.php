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

            // Auto-run migrations (safe to run multiple times)
            runMigrations($pdo);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

/**
 * Run database migrations. Each migration is idempotent (safe to run multiple times).
 */
function runMigrations($pdo) {
    static $migrated = false;
    if ($migrated) return;
    $migrated = true;

    try {
        // Add komentet column to gjendja_bankare (if not exists)
        $cols = $pdo->query("SHOW COLUMNS FROM gjendja_bankare LIKE 'komentet'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE gjendja_bankare ADD COLUMN komentet TEXT DEFAULT NULL");
        }

        // Add reverted column to changelog (if not exists)
        $cols = $pdo->query("SHOW COLUMNS FROM changelog LIKE 'reverted'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE changelog ADD COLUMN reverted TINYINT(1) DEFAULT 0");
        }

        // Add 3 invoice columns to shpenzimet (if not exists)
        $cols = $pdo->query("SHOW COLUMNS FROM shpenzimet LIKE 'data_e_fatures'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE shpenzimet ADD COLUMN data_e_fatures DATE NULL");
            $pdo->exec("ALTER TABLE shpenzimet ADD COLUMN shuma_fatures DECIMAL(12,2) NULL");
            $pdo->exec("ALTER TABLE shpenzimet ADD COLUMN lloji_fatures VARCHAR(100) NULL");
        }

        // Add PDA column to kontrata (if not exists)
        $cols = $pdo->query("SHOW COLUMNS FROM kontrata LIKE 'sipas_skenimit_pda'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE kontrata ADD COLUMN sipas_skenimit_pda TEXT NULL");
        }

        // Add klienti column to gjendja_bankare for client assignment (kartela feature)
        $cols = $pdo->query("SHOW COLUMNS FROM gjendja_bankare LIKE 'klienti'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE gjendja_bankare ADD COLUMN klienti VARCHAR(255) NULL AFTER lloji");
            $pdo->exec("CREATE INDEX idx_gb_klienti ON gjendja_bankare (klienti)");
        }

        // Notes table (mirrors Excel NOTES sheet)
        $pdo->exec("CREATE TABLE IF NOT EXISTS notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data DATE NULL,
            teksti TEXT,
            barazu_nga TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Widen columns that are too small for Excel data
        // stoku_zyrtar.njesi, shitje_produkteve.statusi_i_pageses, gjendja_bankare.lloji
        try {
            $pdo->exec("ALTER TABLE stoku_zyrtar MODIFY COLUMN njesi VARCHAR(255) NULL");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE shitje_produkteve MODIFY COLUMN statusi_i_pageses TEXT NULL");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE gjendja_bankare MODIFY COLUMN lloji TEXT NULL");
        } catch (PDOException $e) {}

        // Trim whitespace in borxhet_notes (one-time cleanup)
        try {
            $pdo->exec("UPDATE borxhet_notes SET
                klient_bank_cash = TRIM(klient_bank_cash),
                kush_merr_borxhin = TRIM(kush_merr_borxhin),
                koment = TRIM(koment)
                WHERE klient_bank_cash != TRIM(klient_bank_cash)
                   OR kush_merr_borxhin != TRIM(kush_merr_borxhin)
                   OR koment != TRIM(koment)");
        } catch (PDOException $e) {}

        // Snapshots table (auto-created by snapshot.php, but ensure it exists)
        $pdo->exec("CREATE TABLE IF NOT EXISTS snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL,
            snapshot_data LONGTEXT NOT NULL,
            size_bytes INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    } catch (PDOException $e) {
        // Silently ignore migration errors (table might not exist yet during initial setup)
    }
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
    $val = $_GET[$name] ?? [];
    return is_array($val) ? $val : [$val];
}

/**
 * Build WHERE IN clause for a multi-select filter.
 * Returns ['sql' => '...', 'params' => [...]] or null if no filter active.
 * Handles blank/empty values: empty string in filter values matches NULL or empty in DB.
 */
function buildFilterIn($filterValues, $column, $tableAlias = '') {
    if (empty($filterValues)) return null;
    $col = $tableAlias ? "{$tableAlias}.{$column}" : $column;

    $hasBlank = in_array('', $filterValues, true);
    $nonBlank = array_filter($filterValues, fn($v) => $v !== '');

    $parts = [];
    $params = [];
    if ($nonBlank) {
        $placeholders = implode(',', array_fill(0, count($nonBlank), '?'));
        $parts[] = "{$col} IN ({$placeholders})";
        $params = array_values($nonBlank);
    }
    if ($hasBlank) {
        $parts[] = "({$col} IS NULL OR {$col} = '')";
    }

    return [
        'sql' => '(' . implode(' OR ', $parts) . ')',
        'params' => $params
    ];
}
