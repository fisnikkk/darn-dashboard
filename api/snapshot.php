<?php
/**
 * Database Snapshot API
 * Creates and restores database snapshots (stored IN the database, not filesystem)
 * This ensures snapshots survive Railway deployments (ephemeral filesystem).
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';
// Allow GET params for download action
if (empty($input)) {
    $input = $_GET;
}

$tables = ['distribuimi','shpenzimet','plini_depo','shitje_produkteve','kontrata',
           'gjendja_bankare','nxemese','klientet','stoku_zyrtar','depo','borxhet_notes','notes'];

try {
    $db = getDB();

    // Auto-create snapshots table if it doesn't exist
    $db->exec("CREATE TABLE IF NOT EXISTS snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL,
        snapshot_data LONGTEXT NOT NULL,
        size_bytes INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($action === 'create') {
        $name = $input['name'] ?? date('Y-m-d_H-i-s');
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

        $snapshot = ['created_at' => date('Y-m-d H:i:s'), 'name' => $name, 'tables' => []];
        foreach ($tables as $t) {
            try {
                $rows = $db->query("SELECT * FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
                $snapshot['tables'][$t] = $rows;
            } catch (PDOException $e) {
                // Table may not exist, skip
            }
        }

        $jsonData = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $sizeBytes = strlen($jsonData);
        $sizeMB = round($sizeBytes / 1048576, 2);

        // Insert or replace existing snapshot with same name
        $stmt = $db->prepare("REPLACE INTO snapshots (name, created_at, snapshot_data, size_bytes) VALUES (?, NOW(), ?, ?)");
        $stmt->execute([$name, $jsonData, $sizeBytes]);

        echo json_encode(['success' => true, 'message' => "Snapshot '{$name}' u krijua ({$sizeMB} MB)"]);

    } elseif ($action === 'restore') {
        $name = $input['name'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

        $stmt = $db->prepare("SELECT snapshot_data FROM snapshots WHERE name = ?");
        $stmt->execute([$name]);
        $jsonData = $stmt->fetchColumn();

        if (!$jsonData) {
            echo json_encode(['success' => false, 'error' => 'Snapshot nuk u gjet']);
            exit;
        }

        $snapshot = json_decode($jsonData, true);
        if (!$snapshot || !isset($snapshot['tables'])) {
            echo json_encode(['success' => false, 'error' => 'Snapshot i pavlefshëm']);
            exit;
        }

        $db->beginTransaction();

        foreach ($snapshot['tables'] as $t => $rows) {
            if (!in_array($t, $tables)) continue;
            $db->exec("DELETE FROM {$t}");

            if (empty($rows)) continue;

            $cols = array_keys($rows[0]);
            $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $sql = "INSERT INTO {$t} (" . implode(',', $cols) . ") VALUES {$placeholders}";
            $stmt = $db->prepare($sql);

            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
            }
        }

        // Recalculate bilanci for gjendja_bankare
        $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
        $running = 0;
        foreach ($all as $r) {
            $running = round($running + (float)$r['kredi'] + (float)$r['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
        }

        // Log restore action to changelog
        $tablesRestored = array_keys($snapshot['tables']);
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('restore', 'snapshot', 0, 'snapshot_name', NULL, ?)")
            ->execute([json_encode(['snapshot' => $name, 'tables' => $tablesRestored, 'created_at' => $snapshot['created_at'] ?? null], JSON_UNESCAPED_UNICODE)]);

        $db->commit();
        echo json_encode(['success' => true, 'message' => "Snapshot '{$name}' u rikthye me sukses"]);

    } elseif ($action === 'list') {
        $rows = $db->query("SELECT name, created_at, size_bytes FROM snapshots ORDER BY created_at DESC")->fetchAll();
        $snapshots = [];
        foreach ($rows as $r) {
            $snapshots[] = [
                'name' => $r['name'],
                'created_at' => $r['created_at'] ?? 'Pa date',
                'size' => round($r['size_bytes'] / 1048576, 2) . ' MB'
            ];
        }
        echo json_encode(['success' => true, 'snapshots' => $snapshots]);

    } elseif ($action === 'delete') {
        $name = $input['name'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        $stmt = $db->prepare("DELETE FROM snapshots WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Snapshot u fshi']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Snapshot nuk u gjet']);
        }

    } elseif ($action === 'import') {
        // Import a snapshot from JSON data (used to migrate filesystem snapshots to DB)
        $jsonData = $input['data'] ?? '';
        if (!$jsonData) {
            echo json_encode(['success' => false, 'error' => 'No data provided']);
            exit;
        }
        $snapshot = json_decode($jsonData, true);
        if (!$snapshot || !isset($snapshot['tables'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid snapshot data']);
            exit;
        }
        $name = $snapshot['name'] ?? date('Y-m-d_H-i-s');
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        $createdAt = $snapshot['created_at'] ?? date('Y-m-d H:i:s');
        $sizeBytes = strlen($jsonData);

        $stmt = $db->prepare("REPLACE INTO snapshots (name, created_at, snapshot_data, size_bytes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $createdAt, $jsonData, $sizeBytes]);

        $sizeMB = round($sizeBytes / 1048576, 2);
        echo json_encode(['success' => true, 'message' => "Snapshot '{$name}' u importua ({$sizeMB} MB)"]);

    } elseif ($action === 'import_files') {
        // Import snapshot JSON files from the snapshots/ directory into the database
        $snapDir = __DIR__ . '/../snapshots';
        if (!is_dir($snapDir)) {
            echo json_encode(['success' => false, 'error' => 'Snapshots directory not found']);
            exit;
        }

        $files = glob($snapDir . '/*.json');
        if (empty($files)) {
            echo json_encode(['success' => false, 'error' => 'No JSON files found in snapshots/']);
            exit;
        }

        $imported = [];
        $skipped = [];
        foreach ($files as $file) {
            $jsonData = file_get_contents($file);
            if (!$jsonData) continue;

            $snapshot = json_decode($jsonData, true);
            if (!$snapshot || !isset($snapshot['tables'])) continue;

            $name = $snapshot['name'] ?? pathinfo($file, PATHINFO_FILENAME);
            $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
            $createdAt = $snapshot['created_at'] ?? date('Y-m-d H:i:s', filemtime($file));
            $sizeBytes = strlen($jsonData);

            // Check if already exists
            $check = $db->prepare("SELECT name FROM snapshots WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                $skipped[] = $name;
                continue;
            }

            $stmt = $db->prepare("INSERT INTO snapshots (name, created_at, snapshot_data, size_bytes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $createdAt, $jsonData, $sizeBytes]);
            $imported[] = ['name' => $name, 'size' => round($sizeBytes / 1048576, 2) . ' MB'];
        }

        echo json_encode([
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'message' => count($imported) . ' snapshot(s) importuar, ' . count($skipped) . ' tashmë ekzistojnë'
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($action === 'download') {
        // Download raw snapshot data (or just one table from it)
        $name = $input['name'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        $table = $input['table'] ?? '';

        $stmt = $db->prepare("SELECT snapshot_data FROM snapshots WHERE name = ?");
        $stmt->execute([$name]);
        $jsonData = $stmt->fetchColumn();

        if (!$jsonData) {
            echo json_encode(['success' => false, 'error' => 'Snapshot nuk u gjet']);
            exit;
        }

        if ($table) {
            $snapshot = json_decode($jsonData, true);
            $tableData = $snapshot['tables'][$table] ?? null;
            if ($tableData === null) {
                echo json_encode(['success' => false, 'error' => "Table '{$table}' not in snapshot"]);
            } else {
                echo json_encode($tableData, JSON_UNESCAPED_UNICODE);
            }
        } else {
            echo $jsonData;
        }

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
