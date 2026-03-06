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

header('Content-Type: application/json');

// Allowed tables for import
$allowedTables = [
    'distribuimi', 'shpenzimet', 'plini_depo', 'shitje_produkteve', 'kontrata',
    'gjendja_bankare', 'notes', 'klientet', 'nxemese', 'stoku_zyrtar', 'depo'
];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

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

        // Ensure TEXT columns are wide enough (one-time fix, safe to run repeatedly)
        try {
            $db->exec("ALTER TABLE gjendja_bankare MODIFY COLUMN lloji TEXT NULL");
            $db->exec("ALTER TABLE shitje_produkteve MODIFY COLUMN statusi_i_pageses TEXT NULL");
            $db->exec("ALTER TABLE stoku_zyrtar MODIFY COLUMN njesi VARCHAR(255) NULL");
            $db->exec("ALTER TABLE stoku_zyrtar MODIFY COLUMN pershkrimi TEXT NULL");
        } catch (PDOException $e) { /* ignore */ }

        // Replace mode: delete existing data first
        if ($mode === 'replace') {
            $deleted = (int)$db->query("SELECT COUNT(*) FROM {$tableName}")->fetchColumn();
            $db->exec("DELETE FROM {$tableName}");
            $db->exec("ALTER TABLE {$tableName} AUTO_INCREMENT = 1");
        }

        // Insert rows in a transaction
        $db->beginTransaction();
        $imported = 0;
        $errors = [];

        // Get columns from first row
        $columns = array_keys($rows[0]);
        // Filter out any null/empty column names
        $columns = array_filter($columns, fn($c) => $c !== '' && $c !== null);
        $columns = array_values($columns);

        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = "INSERT INTO {$tableName} (" . implode(',', $columns) . ") VALUES {$placeholders}";
        $stmt = $db->prepare($sql);

        // Detect date columns from DB schema for value sanitization
        $dateColumnsDB = [];
        $numColumnsDB = [];
        try {
            $colInfo = $db->query("SHOW COLUMNS FROM {$tableName}")->fetchAll();
            foreach ($colInfo as $ci) {
                $type = strtolower($ci['Type']);
                if (in_array($ci['Field'], $columns)) {
                    if (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
                        $dateColumnsDB[] = $ci['Field'];
                    } elseif (preg_match('/^(int|decimal|float|double|bigint|smallint|tinyint|numeric)/', $type)) {
                        $numColumnsDB[] = $ci['Field'];
                    }
                }
            }
        } catch (PDOException $e) { /* ignore */ }

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
