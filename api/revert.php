<?php
/**
 * Revert a row to its previous state using changelog
 * Finds the most recent set of changes for a row and undoes them
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['table'], $input['id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$table = $input['table'];
$id = (int)$input['id'];

$allowedTables = ['distribuimi','shpenzimet','plini_depo','shitje_produkteve','kontrata',
                  'gjendja_bankare','nxemese','klientet','stoku_zyrtar','depo'];
if (!in_array($table, $allowedTables)) {
    echo json_encode(['success' => false, 'error' => 'Table not allowed']);
    exit;
}

try {
    $db = getDB();

    // Find the most recent batch of updates for this row
    // A "batch" = all changes with the same created_at timestamp (within 2 seconds)
    $lastChange = $db->prepare("
        SELECT created_at FROM changelog
        WHERE table_name = ? AND row_id = ? AND action_type = 'update'
        ORDER BY created_at DESC LIMIT 1
    ");
    $lastChange->execute([$table, $id]);
    $lastTs = $lastChange->fetchColumn();

    if (!$lastTs) {
        echo json_encode(['success' => false, 'error' => 'Nuk u gjet asnje ndryshim per kete rresht']);
        exit;
    }

    // Get all changes in that batch (within 2 second window)
    $changes = $db->prepare("
        SELECT field_name, old_value FROM changelog
        WHERE table_name = ? AND row_id = ? AND action_type = 'update'
        AND created_at >= DATE_SUB(?, INTERVAL 2 SECOND)
        AND created_at <= DATE_ADD(?, INTERVAL 2 SECOND)
    ");
    $changes->execute([$table, $id, $lastTs, $lastTs]);
    $batch = $changes->fetchAll();

    if (empty($batch)) {
        echo json_encode(['success' => false, 'error' => 'Nuk ka ndryshime per te kthyer']);
        exit;
    }

    $db->beginTransaction();

    $reverted = [];
    foreach ($batch as $ch) {
        $field = $ch['field_name'];
        $oldVal = $ch['old_value'];
        $db->prepare("UPDATE {$table} SET {$field} = ? WHERE id = ?")->execute([$oldVal, $id]);
        $reverted[] = $field;

        // Log the revert as a new update
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('update', ?, ?, ?, ?, ?)")
            ->execute([$table, $id, $field, null, $oldVal]);
    }

    // Recalculate bilanci if gjendja_bankare
    if ($table === 'gjendja_bankare') {
        $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
        $running = 0;
        foreach ($all as $r) {
            $running = round($running + (float)$r['kredi'] - (float)$r['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'reverted' => $reverted,
        'message' => 'U kthyen ' . count($reverted) . ' ndryshime']);
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
