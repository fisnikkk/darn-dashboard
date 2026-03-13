<?php
/**
 * DARN Dashboard - Excel Import API
 * Receives pre-parsed rows (JSON) from the browser (SheetJS) and inserts into DB.
 *
 * Action: import_rows
 *   POST JSON: { action: "import_rows", table: "...", mode: "replace"|"append", rows: [{field:val,...},...] }
 */
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);
set_time_limit(300);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

// Allowed tables for import
$allowedTables = [
    'distribuimi', 'shpenzimet', 'plini_depo', 'shitje_produkteve', 'kontrata',
    'gjendja_bankare', 'notes', 'klientet', 'nxemese', 'stoku_zyrtar', 'depo'
];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

// Temporary diagnostic action
if ($action === 'diagnose') {
    $db = getDB();
    $results = [];

    // 1. Distinct menyra_e_pageses values in plini_depo with sums
    $results['plini_depo_by_menyra'] = $db->query("
        SELECT LOWER(TRIM(menyra_e_pageses)) as menyra, COUNT(*) as cnt,
               ROUND(SUM(faturat_e_pranuara),2) as total_faturat
        FROM plini_depo GROUP BY LOWER(TRIM(menyra_e_pageses)) ORDER BY total_faturat DESC
    ")->fetchAll();

    // 2. Distinct menyra_e_pageses in distribuimi with sums
    $results['distribuimi_by_menyra'] = $db->query("
        SELECT LOWER(TRIM(menyra_e_pageses)) as menyra, COUNT(*) as cnt,
               ROUND(SUM(pagesa),2) as total_pagesa
        FROM distribuimi GROUP BY LOWER(TRIM(menyra_e_pageses)) ORDER BY total_pagesa DESC
    ")->fetchAll();

    // 3. Distinct lloji_i_pageses in shpenzimet with sums
    $results['shpenzimet_by_lloji_pageses'] = $db->query("
        SELECT LOWER(TRIM(lloji_i_pageses)) as lloji, COUNT(*) as cnt,
               ROUND(SUM(shuma),2) as total_shuma
        FROM shpenzimet GROUP BY LOWER(TRIM(lloji_i_pageses)) ORDER BY total_shuma DESC
    ")->fetchAll();

    // 4. Distinct menyra_pageses in shitje_produkteve with sums
    $results['shitje_produkteve_by_menyra'] = $db->query("
        SELECT LOWER(TRIM(menyra_pageses)) as menyra, COUNT(*) as cnt,
               ROUND(SUM(totali),2) as total
        FROM shitje_produkteve GROUP BY LOWER(TRIM(menyra_pageses)) ORDER BY total DESC
    ")->fetchAll();

    // 5. Key computed values
    $results['shpenzimCashAll'] = $db->query("SELECT ROUND(SUM(shuma),2) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'")->fetchColumn();
    $results['shpenzimPlin'] = $db->query("SELECT ROUND(SUM(shuma),2) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit)) = 'pagesa per plin'")->fetchColumn();
    $results['shpenzimTjera'] = $db->query("SELECT ROUND(SUM(shuma),2) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit)) = 'shpenzim'")->fetchColumn();

    // 6. Distinct lloji_i_transaksionit in shpenzimet
    $results['shpenzimet_by_lloji_trans'] = $db->query("
        SELECT LOWER(TRIM(lloji_i_transaksionit)) as lloji, COUNT(*) as cnt,
               ROUND(SUM(shuma),2) as total_shuma
        FROM shpenzimet GROUP BY LOWER(TRIM(lloji_i_transaksionit)) ORDER BY total_shuma DESC
    ")->fetchAll();

    // 7. Check menyra_pageses column existence in shitje_produkteve
    $results['shitje_produkteve_cols'] = $db->query("SHOW COLUMNS FROM shitje_produkteve")->fetchAll(PDO::FETCH_COLUMN, 0);

    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'diagnose2') {
    $db = getDB();
    $r = [];
    // Cross-tab: shpenzimet by lloji_i_pageses AND lloji_i_transaksionit (CASH only)
    $r['shpenzimet_cash_by_trans'] = $db->query("
        SELECT LOWER(TRIM(lloji_i_transaksionit)) as trans, COUNT(*) as cnt, ROUND(SUM(shuma),2) as total
        FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'
        GROUP BY LOWER(TRIM(lloji_i_transaksionit)) ORDER BY total DESC
    ")->fetchAll();
    // statusi_i_pageses distinct values in shitje_produkteve
    $r['shitje_statusi'] = $db->query("
        SELECT LOWER(TRIM(statusi_i_pageses)) as status, COUNT(*) as cnt, ROUND(SUM(totali),2) as total
        FROM shitje_produkteve GROUP BY LOWER(TRIM(statusi_i_pageses)) ORDER BY total DESC
    ")->fetchAll();
    // Total shitje_produkteve cash if we look at statusi = 'cash' or 'paguar'
    $r['shitje_total'] = $db->query("SELECT ROUND(SUM(totali),2) FROM shitje_produkteve")->fetchColumn();
    // Blerje pa fature - check with dalje_pagesat column too
    $r['plini_depo_dalje_by_menyra'] = $db->query("
        SELECT LOWER(TRIM(menyra_e_pageses)) as menyra, ROUND(SUM(dalje_pagesat_sipas_bankes),2) as total_dalje,
               ROUND(SUM(faturat_e_pranuara),2) as total_faturat
        FROM plini_depo GROUP BY LOWER(TRIM(menyra_e_pageses))
    ")->fetchAll();
    // fatura_e_rregullte in shpenzimet
    $r['shpenzimet_by_fatura'] = $db->query("
        SELECT LOWER(TRIM(fatura_e_rregullte)) as fatura, COUNT(*) as cnt, ROUND(SUM(shuma),2) as total
        FROM shpenzimet GROUP BY LOWER(TRIM(fatura_e_rregullte)) ORDER BY total DESC
    ")->fetchAll();
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'import_rows') {
    $tableName = $input['table'] ?? '';
    $mode = $input['mode'] ?? 'replace';
    $rows = $input['rows'] ?? [];

    if (!$tableName || !in_array($tableName, $allowedTables)) {
        echo json_encode(['success' => false, 'error' => 'Tabelë e pavlefshme: ' . $tableName]);
        exit;
    }

    if (empty($rows)) {
        echo json_encode(['success' => true, 'imported' => 0, 'deleted' => 0]);
        exit;
    }

    try {
        $db = getDB();
        $deleted = 0;

        // --- Pre-import: ensure table schema can handle ANY incoming data ---

        // 1. Get incoming columns from first row
        $columns = array_keys($rows[0]);
        $columns = array_filter($columns, fn($c) => $c !== '' && $c !== null);
        $columns = array_values($columns);

        // 2. Read existing DB columns
        $existingCols = [];
        try {
            $colInfo = $db->query("SHOW COLUMNS FROM {$tableName}")->fetchAll();
            foreach ($colInfo as $ci) {
                $existingCols[$ci['Field']] = $ci;
            }
        } catch (PDOException $e) {
            // Table might not exist — migrations in getDB() should have created it
            throw new PDOException("Tabela '{$tableName}' nuk ekziston në databazë. Rifresko faqen dhe provo përsëri.");
        }

        // 3. Auto-add missing columns (Excel has a column the DB doesn't)
        $addedCols = [];
        foreach ($columns as $col) {
            if (!isset($existingCols[$col])) {
                try {
                    $safeName = preg_replace('/[^a-z0-9_]/', '_', strtolower($col));
                    // Use the original column name (already sanitized by SheetJS mapper)
                    $db->exec("ALTER TABLE `{$tableName}` ADD COLUMN `{$col}` TEXT NULL");
                    $existingCols[$col] = ['Field' => $col, 'Type' => 'text'];
                    $addedCols[] = $col;
                } catch (PDOException $e) { /* column might already exist with different case */ }
            }
        }

        // 4. Auto-widen VARCHAR columns if incoming data exceeds their limit
        //    Scan max string length per column across all incoming rows
        $varcharCols = [];
        foreach ($columns as $col) {
            if (!isset($existingCols[$col])) continue;
            $type = strtolower($existingCols[$col]['Type']);
            if (preg_match('/^varchar\((\d+)\)/', $type, $m)) {
                $varcharCols[$col] = (int)$m[1];
            }
        }

        if (!empty($varcharCols)) {
            $maxLengths = array_fill_keys(array_keys($varcharCols), 0);
            foreach ($rows as $row) {
                foreach ($varcharCols as $col => $limit) {
                    $val = $row[$col] ?? null;
                    if ($val !== null && $val !== '') {
                        $len = mb_strlen((string)$val);
                        if ($len > $maxLengths[$col]) $maxLengths[$col] = $len;
                    }
                }
            }

            foreach ($maxLengths as $col => $maxLen) {
                if ($maxLen > $varcharCols[$col]) {
                    try {
                        // Drop any index on this column first (TEXT can't have a regular index)
                        $indexes = $db->query("SHOW INDEX FROM `{$tableName}` WHERE Column_name = " . $db->quote($col))->fetchAll();
                        foreach ($indexes as $idx) {
                            if ($idx['Key_name'] !== 'PRIMARY') {
                                $db->exec("ALTER TABLE `{$tableName}` DROP INDEX `{$idx['Key_name']}`");
                            }
                        }
                        $db->exec("ALTER TABLE `{$tableName}` MODIFY COLUMN `{$col}` TEXT NULL");
                        $existingCols[$col]['Type'] = 'text';
                    } catch (PDOException $e) { /* ignore */ }
                }
            }
        }

        // --- Replace mode: delete existing data first ---
        if ($mode === 'replace') {
            $deleted = (int)$db->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();
            $db->exec("DELETE FROM `{$tableName}`");
            $db->exec("ALTER TABLE `{$tableName}` AUTO_INCREMENT = 1");
        }

        // --- Insert rows ---
        $db->beginTransaction();
        $imported = 0;
        $errors = [];

        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $colList = implode(',', array_map(fn($c) => "`{$c}`", $columns));
        $sql = "INSERT INTO `{$tableName}` ({$colList}) VALUES {$placeholders}";
        $stmt = $db->prepare($sql);

        // Detect date/numeric columns for value sanitization
        $dateColumnsDB = [];
        $numColumnsDB = [];
        foreach ($columns as $col) {
            if (!isset($existingCols[$col])) continue;
            $type = strtolower($existingCols[$col]['Type']);
            if (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
                $dateColumnsDB[] = $col;
            } elseif (preg_match('/^(int|decimal|float|double|bigint|smallint|tinyint|numeric)/', $type)) {
                $numColumnsDB[] = $col;
            }
        }

        foreach ($rows as $idx => $row) {
            try {
                $values = [];
                foreach ($columns as $col) {
                    $val = $row[$col] ?? null;
                    if ($val === '' || $val === null) {
                        $values[] = null;
                    } else {
                        // Sanitize date values: must be YYYY-MM-DD or NULL
                        if (in_array($col, $dateColumnsDB)) {
                            if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) {
                                $values[] = substr($val, 0, 10);
                            } elseif (is_numeric($val) && (float)$val > 1) {
                                // Excel serial date
                                $ts = ((float)$val - 25569) * 86400;
                                $d = gmdate('Y-m-d', (int)$ts);
                                $values[] = ($d && $d !== '1970-01-01') ? $d : null;
                            } else {
                                $values[] = null; // invalid date → NULL
                            }
                        } elseif (in_array($col, $numColumnsDB)) {
                            // Sanitize numeric values
                            if (is_numeric($val)) {
                                $values[] = $val;
                            } else {
                                $cleaned = preg_replace('/[^\d.\-]/', '', (string)$val);
                                $values[] = is_numeric($cleaned) ? $cleaned : null;
                            }
                        } else {
                            $values[] = $val;
                        }
                    }
                }
                $stmt->execute($values);
                $imported++;
            } catch (PDOException $e) {
                if (count($errors) < 50) {
                    $errors[] = "Row " . ($idx + 1) . ": " . $e->getMessage();
                }
                // Continue processing remaining rows (don't break!)
            }
        }

        // Auto-parse dates from note text after notes import
        if ($tableName === 'notes') {
            $months = [
                'janar' => 1, 'shkurt' => 2, 'mars' => 3, 'prill' => 4,
                'maj' => 5, 'qershor' => 6, 'korrik' => 7, 'gusht' => 8,
                'shtator' => 9, 'tetor' => 10, 'nëntor' => 11, 'nentor' => 11,
                'dhjetor' => 12
            ];
            $monthPat = implode('|', array_keys($months));
            $patAlb = '/\b(\d{1,2})\s+(' . $monthPat . ')(?:\s+(20[2-9]\d))?\b/iu';
            $patNum = '/\b(\d{1,2})[.\/]\s*(\d{1,2})[.\/]\s*(\d{4})\b/';
            $today = new DateTime();

            $notesRows = $db->query("SELECT id, teksti, created_at FROM notes WHERE data IS NULL")->fetchAll();
            $updateStmt = $db->prepare("UPDATE notes SET data = ? WHERE id = ?");

            foreach ($notesRows as $nr) {
                $txt = $nr['teksti'] ?? '';
                if (!$txt) continue;
                $parsedDate = null;

                // Albanian month pattern
                if (preg_match($patAlb, $txt, $pm)) {
                    $d = (int)$pm[1];
                    $mo = $months[mb_strtolower($pm[2])] ?? null;
                    if ($mo && $d >= 1 && $d <= 31) {
                        $yr = !empty($pm[3]) ? (int)$pm[3] : (int)date('Y');
                        if (empty($pm[3])) {
                            $cand = sprintf('%04d-%02d-%02d', $yr, $mo, min($d, 28));
                            if (new DateTime($cand) > $today) $yr--;
                        }
                        if (!checkdate($mo, $d, $yr)) $d = min($d, (int)(new DateTime("$yr-$mo-01"))->format('t'));
                        if (checkdate($mo, $d, $yr)) $parsedDate = sprintf('%04d-%02d-%02d', $yr, $mo, $d);
                    }
                }
                // Numeric DD.MM.YYYY or DD/MM/YYYY
                if (!$parsedDate && preg_match($patNum, $txt, $pm)) {
                    $d = (int)$pm[1]; $mo = (int)$pm[2]; $yr = (int)$pm[3];
                    if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && $yr >= 2020 && $yr <= 2030) {
                        if (!checkdate($mo, $d, $yr)) $d = min($d, (int)(new DateTime("$yr-$mo-01"))->format('t'));
                        if (checkdate($mo, $d, $yr)) $parsedDate = sprintf('%04d-%02d-%02d', $yr, $mo, $d);
                    }
                }

                if ($parsedDate) $updateStmt->execute([$parsedDate, $nr['id']]);
            }
        }

        // Recalculate bilanci for gjendja_bankare
        if ($tableName === 'gjendja_bankare') {
            $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
            $running = 0;
            foreach ($all as $r) {
                $running = round($running + (float)$r['kredi'] + (float)$r['debia'], 2);
                $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
            }
        }

        // Log to changelog
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('excel_import', ?, 0, ?, NULL, ?)")
            ->execute([$tableName, $mode, json_encode(['imported' => $imported, 'deleted' => $deleted, 'errors' => count($errors)])]);

        $db->commit();

        $response = ['success' => true, 'imported' => $imported, 'deleted' => $deleted];
        if (!empty($addedCols)) {
            $response['added_columns'] = $addedCols;
            $response['info'] = count($addedCols) . ' kolona të reja u shtuan automatikisht: ' . implode(', ', $addedCols);
        }
        if ($errors) {
            $response['errors'] = $errors;
            $response['warning'] = count($errors) . ' rreshta me gabime';
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Veprim i pavlefshëm']);
