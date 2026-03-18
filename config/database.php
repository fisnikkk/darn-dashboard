<?php
/**
 * DARN Dashboard - Database Configuration
 * Update these values for your environment
 */

// Load environment file if it exists (GoDaddy deployment — Railway uses system env vars)
// Uses .env.php so IIS never serves credentials as plain text
$envFiles = [__DIR__ . '/../.env.php', __DIR__ . '/../.env'];
foreach ($envFiles as $envFile) {
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || strpos($trimmed, '<?') === 0 || strpos($trimmed, '//') === 0 || strpos($trimmed, 'die') === 0) continue;
            if (strpos($trimmed, '=') !== false) putenv($trimmed);
        }
        break;
    }
}

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
        // ============================================================
        // Core tables — CREATE IF NOT EXISTS (safety net so imports
        // never fail with "table not found"). In normal operation these
        // already exist from the initial schema import.
        // ============================================================

        $pdo->exec("CREATE TABLE IF NOT EXISTS distribuimi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            row_nr INT,
            klienti VARCHAR(255),
            data DATE,
            sasia INT DEFAULT 0,
            boca_te_kthyera INT DEFAULT 0,
            litra DECIMAL(10,2) DEFAULT 0,
            cmimi DECIMAL(10,4) DEFAULT 0,
            pagesa DECIMAL(12,2) DEFAULT 0,
            menyra_e_pageses VARCHAR(100),
            fatura_e_derguar TEXT,
            data_e_fletepageses DATE NULL,
            koment TEXT,
            litrat_total DECIMAL(10,2) DEFAULT 0,
            litrat_e_konvertuara DECIMAL(10,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_klienti (klienti),
            INDEX idx_data (data),
            INDEX idx_menyra (menyra_e_pageses),
            INDEX idx_klienti_data (klienti, data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS shpenzimet (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data_e_pageses DATE,
            shuma DECIMAL(12,2),
            arsyetimi VARCHAR(255),
            lloji_i_pageses VARCHAR(100),
            lloji_i_transaksionit VARCHAR(100),
            pershkrim_i_detajuar TEXT,
            nafta_ne_litra DECIMAL(10,2) NULL,
            numri_i_fatures VARCHAR(100),
            fatura_e_rregullte VARCHAR(50) NULL,
            data_e_fatures DATE NULL,
            shuma_fatures DECIMAL(12,2) NULL,
            lloji_fatures VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_data (data_e_pageses),
            INDEX idx_lloji_trans (lloji_i_transaksionit),
            INDEX idx_lloji_pag (lloji_i_pageses)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS plini_depo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nr_i_fatures VARCHAR(100),
            data DATE,
            kg DECIMAL(12,2),
            sasia_ne_litra DECIMAL(12,2),
            cmimi DECIMAL(10,4),
            faturat_e_pranuara DECIMAL(12,2),
            dalje_pagesat_sipas_bankes DECIMAL(12,2) DEFAULT 0,
            menyra_e_pageses VARCHAR(100),
            cash_banke VARCHAR(50),
            furnitori VARCHAR(255),
            koment TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_data (data),
            INDEX idx_menyra (menyra_e_pageses)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS shitje_produkteve (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data DATE,
            cilindra_sasia INT DEFAULT 0,
            produkti VARCHAR(255),
            klienti VARCHAR(255),
            adresa VARCHAR(255),
            qyteti VARCHAR(100),
            cmimi DECIMAL(10,2),
            totali DECIMAL(12,2),
            menyra_pageses VARCHAR(100),
            koment TEXT,
            statusi_i_pageses TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_data (data),
            INDEX idx_klienti (klienti),
            INDEX idx_menyra (menyra_pageses)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS kontrata (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nr_i_kontrates INT NULL,
            data DATE NULL,
            biznesi VARCHAR(255),
            name_from_database VARCHAR(255),
            numri_ne_stok_sipas_kontrates INT DEFAULT 0,
            sipas_skenimit_pda TEXT NULL,
            bashkepunim VARCHAR(50),
            qyteti VARCHAR(100),
            rruga VARCHAR(255),
            numri_unik VARCHAR(100),
            perfaqesuesi VARCHAR(255),
            nr_telefonit VARCHAR(100),
            koment TEXT,
            email VARCHAR(255),
            ne_grup_njoftues VARCHAR(50),
            kontrate_e_vjeter VARCHAR(100),
            lloji_i_bocave VARCHAR(100),
            bocat_e_paguara VARCHAR(50),
            data_rregullatoret DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_biznesi (biznesi),
            INDEX idx_name_db (name_from_database)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS gjendja_bankare (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data DATE,
            data_valutes DATE NULL,
            ora TIME NULL,
            shpjegim TEXT,
            valuta VARCHAR(10) DEFAULT 'EUR',
            debia DECIMAL(12,2) DEFAULT 0,
            kredi DECIMAL(12,2) DEFAULT 0,
            bilanci DECIMAL(12,2) DEFAULT 0,
            deftesa VARCHAR(100),
            lloji TEXT,
            klienti VARCHAR(255) NULL,
            komentet TEXT DEFAULT NULL,
            e_kontrolluar BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_data (data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS nxemese (
            id INT AUTO_INCREMENT PRIMARY KEY,
            klienti VARCHAR(255),
            data DATE,
            te_dhena INT DEFAULT 0,
            te_marra INT DEFAULT 0,
            lloji_i_nxemjes VARCHAR(100),
            koment TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_klienti (klienti),
            INDEX idx_data (data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS klientet (
            id INT AUTO_INCREMENT PRIMARY KEY,
            emri VARCHAR(255),
            bashkepunim VARCHAR(100),
            data_e_kontrates DATE NULL,
            stoku INT DEFAULT 0,
            koment TEXT,
            kontakti VARCHAR(255),
            i_regjistruar_ne_emer VARCHAR(255),
            numri_unik_identifikues VARCHAR(100),
            adresa VARCHAR(255),
            telefoni VARCHAR(100),
            telefoni_2 VARCHAR(100),
            email VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_emri (emri)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS changelog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action_type VARCHAR(50),
            table_name VARCHAR(100),
            row_id INT DEFAULT 0,
            field_name VARCHAR(100),
            old_value TEXT,
            new_value TEXT,
            reverted TINYINT(1) DEFAULT 0,
            batch_id VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_table (table_name),
            INDEX idx_changelog_batch (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS borxhet_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            klienti VARCHAR(255),
            klient_bank_cash VARCHAR(255),
            kush_merr_borxhin VARCHAR(255),
            koment TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_klienti (klienti)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ============================================================
        // Column migrations (add/widen columns on existing tables)
        // ============================================================

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

        // Stoku zyrtar table (Official Product Inventory — ~31 rows from Excel)
        $pdo->exec("CREATE TABLE IF NOT EXISTS stoku_zyrtar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data DATE NULL,
            kodi VARCHAR(100),
            kodi_2 VARCHAR(255),
            pershkrimi TEXT,
            njesi VARCHAR(255),
            sasia DECIMAL(12,2) DEFAULT 0,
            cmimi DECIMAL(10,4) NULL,
            vlera DECIMAL(12,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_kodi (kodi),
            INDEX idx_data (data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Depo table (Product Accessories Stock — ~21 rows from Excel)
        $pdo->exec("CREATE TABLE IF NOT EXISTS depo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data DATE NULL,
            produkti VARCHAR(255),
            sasia INT DEFAULT 0,
            cmimi DECIMAL(10,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_produkti (produkti)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Widen columns that were originally too narrow for Excel data
        // (CREATE TABLE above uses wide types, but existing DBs may have old narrow types)
        $widenColumns = [
            ['distribuimi', 'fatura_e_derguar', 'TEXT NULL'],
            ['stoku_zyrtar', 'njesi', 'VARCHAR(255) NULL'],
            ['stoku_zyrtar', 'pershkrimi', 'TEXT NULL'],
            ['shitje_produkteve', 'statusi_i_pageses', 'TEXT NULL'],
            ['gjendja_bankare', 'lloji', 'TEXT NULL'],
        ];
        foreach ($widenColumns as [$tbl, $col, $newType]) {
            try { $pdo->exec("ALTER TABLE {$tbl} MODIFY COLUMN {$col} {$newType}"); } catch (PDOException $e) {}
        }

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

        // Add batch_id column to changelog for grouping GoDaddy imports
        $cols = $pdo->query("SHOW COLUMNS FROM changelog LIKE 'batch_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE changelog ADD COLUMN batch_id VARCHAR(50) NULL");
            $pdo->exec("CREATE INDEX idx_changelog_batch ON changelog (batch_id)");
        }

        // Login attempts table for rate limiting
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL,
            INDEX idx_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Snapshots table (auto-created by snapshot.php, but ensure it exists)
        $pdo->exec("CREATE TABLE IF NOT EXISTS snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL,
            snapshot_data LONGTEXT NOT NULL,
            size_bytes INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Invoices table
        $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number INT NOT NULL,
            klienti VARCHAR(255) NOT NULL,
            date_from DATE NOT NULL,
            date_to DATE NOT NULL,
            total_amount DECIMAL(12,2) DEFAULT 0,
            total_delivered INT DEFAULT 0,
            total_returned INT DEFAULT 0,
            row_ids TEXT,
            pdf_filename VARCHAR(255),
            status VARCHAR(50) DEFAULT 'created',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_invoice_number (invoice_number),
            INDEX idx_klienti (klienti)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Invoice settings (counter)
        $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT IGNORE INTO invoice_settings (setting_key, setting_value) VALUES ('next_invoice_number', '131')");

        // Add email column to klientet
        $cols = $pdo->query("SHOW COLUMNS FROM klientet LIKE 'email'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE klientet ADD COLUMN email VARCHAR(255) DEFAULT NULL");
        }

        // Pending borxh approval queue — borxh changes go here first, admin approves before distribuimi updates
        $pdo->exec("CREATE TABLE IF NOT EXISTS pending_borxh (
            id INT AUTO_INCREMENT PRIMARY KEY,
            distribuimi_id INT NOT NULL,
            klienti VARCHAR(255),
            old_menyra_e_pageses VARCHAR(255),
            new_menyra_e_pageses VARCHAR(255),
            pagesa DECIMAL(12,2) DEFAULT 0,
            data_e_shitjes DATE NULL,
            koment TEXT,
            requested_by VARCHAR(255),
            requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            approved_by VARCHAR(255) NULL,
            approved_at DATETIME NULL,
            reject_reason TEXT NULL,
            INDEX idx_pending_status (status),
            INDEX idx_pending_dist (distribuimi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Widen pending_borxh.pagesa to match distribuimi.pagesa precision (DECIMAL(12,2))
        try {
            $pdo->exec("ALTER TABLE pending_borxh MODIFY COLUMN pagesa DECIMAL(12,2) DEFAULT 0");
        } catch (PDOException $e) {}

        // Add source_table column to pending_borxh to distinguish delivery_report vs distribuimi IDs
        // For Leje Borxhin: source_table='delivery_report', distribuimi_id stores delivery_report.ID
        // For Merr Borxhin: source_table='distribuimi' (default), distribuimi_id stores distribuimi.id
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM pending_borxh LIKE 'source_table'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE pending_borxh ADD COLUMN source_table VARCHAR(50) DEFAULT 'distribuimi' AFTER distribuimi_id");
            }
        } catch (PDOException $e) {}

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
