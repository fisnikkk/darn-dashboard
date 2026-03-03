<?php
/**
 * Revert a row to its previous state using changelog
 * Finds the most recent UNREVERTED set of changes for a row and undoes them.
 * Marks reverted entries so clicking again goes back another step (multi-level undo).
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

    // Find the most recent UNREVERTED batch of updates for this row
    $lastChange = $db->prepare("
        SELECT created_at FROM changelog
        WHERE table_name = ? AND row_id = ? AND action_type = 'update' AND reverted = 0
        ORDER BY created_at DESC LIMIT 1
    ");
    $lastChange->execute([$table, $id]);
    $lastTs = $lastChange->fetchColumn();

    if (!$lastTs) {
        echo json_encode(['success' => false, 'error' => 'Nuk ka me ndryshime per te kthyer']);
        exit;
    }

    // Get all changes in that batch (within 2 second window)
    $changes = $db->prepare("
        SELECT id as changelog_id, field_name, old_value FROM changelog
        WHERE table_name = ? AND row_id = ? AND action_type = 'update' AND reverted = 0
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
    $changelogIds = [];
    foreach ($batch as $ch) {
        $field = $ch['field_name'];
        $oldVal = $ch['old_value'];
        $db->prepare("UPDATE {$table} SET {$field} = ? WHERE id = ?")->execute([$oldVal, $id]);
        $reverted[] = $field;
        $changelogIds[] = $ch['changelog_id'];
    }

    // Mark these changelog entries as reverted (instead of creating new entries)
    if ($changelogIds) {
        $placeholders = implode(',', array_fill(0, count($changelogIds), '?'));
        $db->prepare("UPDATE changelog SET reverted = 1 WHERE id IN ({$placeholders})")->execute($changelogIds);
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

    // Auto-recalc triggers for other tables
    if ($table === 'plini_depo' && in_array('kg', $reverted)) {
        $kgVal = $db->prepare("SELECT kg FROM plini_depo WHERE id = ?");
        $kgVal->execute([$id]);
        $newLitra = round((float)$kgVal->fetchColumn() * 1.95, 2);
        $db->prepare("UPDATE plini_depo SET sasia_ne_litra = ? WHERE id = ?")->execute([$newLitra, $id]);
    }

    if ($table === 'distribuimi') {
        $row = $db->prepare("SELECT sasia, litra, cmimi FROM distribuimi WHERE id = ?");
        $row->execute([$id]);
        $r = $row->fetch();
        if ($r && (in_array('sasia', $reverted) || in_array('litra', $reverted) || in_array('cmimi', $reverted))) {
            $s = (float)$r['sasia']; $l = (float)$r['litra']; $c = (float)$r['cmimi'];
            $db->prepare("UPDATE distribuimi SET pagesa = ?, litrat_total = ?, litrat_e_konvertuara = ? WHERE id = ?")
                ->execute([round($s * $l * $c, 2), round($s * $l, 2), round($s * $l, 2), $id]);
        }
    }

    if ($table === 'shitje_produkteve' && (in_array('cilindra_sasia', $reverted) || in_array('cmimi', $reverted))) {
        $row = $db->prepare("SELECT cilindra_sasia, cmimi FROM shitje_produkteve WHERE id = ?");
        $row->execute([$id]);
        $r = $row->fetch();
        if ($r) {
            $db->prepare("UPDATE shitje_produkteve SET totali = ? WHERE id = ?")->execute([round((float)$r['cilindra_sasia'] * (float)$r['cmimi'], 2), $id]);
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'reverted' => $reverted,
        'message' => 'U kthyen ' . count($reverted) . ' ndryshime']);
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
