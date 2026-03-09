<?php
/**
 * Revert all of today's test changes back to original state.
 *
 * For each (table, row_id, field) changed today, finds the EARLIEST
 * changelog entry's old_value — that's the pre-testing original.
 * Then sets the current DB value back to that original.
 *
 * GET  = preview (shows what will be reverted)
 * POST = execute the revert
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    // Find all unique (table, row_id, field) combos changed today
    // and get the EARLIEST old_value for each (= the pre-testing original)
    $sql = "
        SELECT c.table_name, c.row_id, c.field_name, c.old_value, c.created_at
        FROM changelog c
        INNER JOIN (
            SELECT table_name, row_id, field_name, MIN(created_at) as first_change
            FROM changelog
            WHERE DATE(created_at) = CURDATE()
              AND action_type = 'update'
            GROUP BY table_name, row_id, field_name
        ) earliest ON c.table_name = earliest.table_name
                   AND c.row_id = earliest.row_id
                   AND c.field_name = earliest.field_name
                   AND c.created_at = earliest.first_change
        WHERE DATE(c.created_at) = CURDATE()
          AND c.action_type = 'update'
        ORDER BY c.table_name, c.row_id, c.field_name
    ";
    $changes = $db->query($sql)->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Preview mode — show what would be reverted
        $preview = [];
        foreach ($changes as $ch) {
            // Get current value
            $cur = $db->prepare("SELECT `{$ch['field_name']}` FROM `{$ch['table_name']}` WHERE id = ?");
            $cur->execute([$ch['row_id']]);
            $currentVal = $cur->fetchColumn();

            $preview[] = [
                'table' => $ch['table_name'],
                'row_id' => $ch['row_id'],
                'field' => $ch['field_name'],
                'original_value' => $ch['old_value'],
                'current_value' => $currentVal,
                'needs_revert' => ($currentVal != $ch['old_value']),
                'first_change_at' => $ch['created_at']
            ];
        }

        $needsRevert = array_filter($preview, fn($p) => $p['needs_revert']);
        echo json_encode([
            'success' => true,
            'total_changes_today' => count($preview),
            'needs_revert' => count($needsRevert),
            'already_original' => count($preview) - count($needsRevert),
            'changes' => $preview
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    } else {
        // Execute revert
        $db->beginTransaction();
        $reverted = 0;
        $skipped = 0;

        $allowedTables = ['distribuimi','shpenzimet','plini_depo','shitje_produkteve','kontrata',
                          'gjendja_bankare','nxemese','klientet','stoku_zyrtar','depo'];

        // Prepare changelog statement for revert logging
        $revertLog = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('revert', ?, ?, ?, ?, ?)");

        foreach ($changes as $ch) {
            if (!in_array($ch['table_name'], $allowedTables)) continue;

            // Get current value
            $cur = $db->prepare("SELECT `{$ch['field_name']}` FROM `{$ch['table_name']}` WHERE id = ?");
            $cur->execute([$ch['row_id']]);
            $currentVal = $cur->fetchColumn();

            if ($currentVal != $ch['old_value']) {
                $db->prepare("UPDATE `{$ch['table_name']}` SET `{$ch['field_name']}` = ? WHERE id = ?")
                   ->execute([$ch['old_value'], $ch['row_id']]);

                // Log the revert to changelog
                $revertLog->execute([$ch['table_name'], $ch['row_id'], $ch['field_name'], $currentVal, $ch['old_value']]);
                $reverted++;
            } else {
                $skipped++;
            }
        }

        // Recalculate bilanci for gjendja_bankare (in case any bank rows changed)
        $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
        $running = 0;
        foreach ($all as $r) {
            $running = round($running + (float)$r['kredi'] + (float)$r['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
        }

        // NOTE: pagesa and litrat_total in distribuimi are STORED values — NOT recomputed.

        // Mark today's changelog entries as reverted
        $db->exec("UPDATE changelog SET reverted = 1 WHERE DATE(created_at) = CURDATE() AND action_type = 'update'");

        $db->commit();
        echo json_encode([
            'success' => true,
            'reverted' => $reverted,
            'skipped_already_original' => $skipped,
            'message' => "Reverted {$reverted} changes back to pre-testing state"
        ]);
    }

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
