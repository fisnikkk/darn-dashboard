<?php
/**
 * Database Snapshot API
 * Creates and restores database snapshots (JSON-based)
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

$tables = ['distribuimi','shpenzimet','plini_depo','shitje_produkteve','kontrata',
           'gjendja_bankare','nxemese','klientet','stoku_zyrtar','depo','borxhet_notes'];

$snapshotDir = __DIR__ . '/../snapshots';
if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);

try {
    $db = getDB();

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

        $file = $snapshotDir . '/' . $name . '.json';
        file_put_contents($file, json_encode($snapshot, JSON_UNESCAPED_UNICODE));
        $sizeMB = round(filesize($file) / 1048576, 2);
        echo json_encode(['success' => true, 'message' => "Snapshot '{$name}' u krijua ({$sizeMB} MB)"]);

    } elseif ($action === 'restore') {
        $name = $input['name'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        $file = $snapshotDir . '/' . $name . '.json';

        if (!file_exists($file)) {
            echo json_encode(['success' => false, 'error' => 'Snapshot nuk u gjet']);
            exit;
        }

        $snapshot = json_decode(file_get_contents($file), true);
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
            $running = round($running + (float)$r['kredi'] - (float)$r['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => "Snapshot '{$name}' u rikthye me sukses"]);

    } elseif ($action === 'list') {
        $files = glob($snapshotDir . '/*.json');
        $snapshots = [];
        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            $snapshots[] = [
                'name' => basename($f, '.json'),
                'created_at' => $data['created_at'] ?? 'Pa date',
                'size' => round(filesize($f) / 1048576, 2) . ' MB'
            ];
        }
        usort($snapshots, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        echo json_encode(['success' => true, 'snapshots' => $snapshots]);

    } elseif ($action === 'delete') {
        $name = $input['name'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        $file = $snapshotDir . '/' . $name . '.json';
        if (file_exists($file)) {
            unlink($file);
            echo json_encode(['success' => true, 'message' => 'Snapshot u fshi']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Snapshot nuk u gjet']);
        }

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
