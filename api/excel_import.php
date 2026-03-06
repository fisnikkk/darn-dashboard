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

        foreach ($rows as $idx => $row) {
            try {
                $values = [];
                foreach ($columns as $col) {
                    $val = $row[$col] ?? null;
                    if ($val === '' || $val === null) {
                        $values[] = null;
                    } else {
                        $values[] = $val;
                    }
                }
                $stmt->execute($values);
                $imported++;
            } catch (PDOException $e) {
                $errors[] = "Row " . ($idx + 1) . ": " . $e->getMessage();
                if (count($errors) > 10) break;
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
