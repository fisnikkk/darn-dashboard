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

        // Snapshots table (auto-created by snapshot.php, but ensure it exists)
        $pdo->exec("CREATE TABLE IF NOT EXISTS snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL,
            snapshot_data LONGTEXT NOT NULL,
            size_bytes INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // One-time: import any filesystem JSON snapshots into the DB
        $snapshotDir = __DIR__ . '/../snapshots';
        if (is_dir($snapshotDir)) {
            $files = glob($snapshotDir . '/*.json');
            foreach ($files as $f) {
                $name = basename($f, '.json');
                // Check if already imported
                $check = $pdo->prepare("SELECT COUNT(*) FROM snapshots WHERE name = ?");
                $check->execute([$name]);
                if ((int)$check->fetchColumn() === 0) {
                    $jsonData = file_get_contents($f);
                    $data = json_decode($jsonData, true);
                    $createdAt = $data['created_at'] ?? date('Y-m-d H:i:s');
                    $sizeBytes = strlen($jsonData);
                    $stmt = $pdo->prepare("INSERT INTO snapshots (name, created_at, snapshot_data, size_bytes) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $createdAt, $jsonData, $sizeBytes]);
                }
            }
        }

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
