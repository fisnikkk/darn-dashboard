<?php
/**
 * Fix pagesa values that were wrongly recalculated by revert_today.php.
 * Reads correct values from the March 2 snapshot and restores them.
 *
 * GET  = preview
 * POST = execute fix
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    // Load snapshot from committed file
    $snapFile = __DIR__ . '/../snapshots/Snapshot_Testues.json';
    if (!file_exists($snapFile)) {
        echo json_encode(['error' => 'Snapshot file not found']);
        exit;
    }
    $snap = json_decode(file_get_contents($snapFile), true);
    $snapRows = $snap['tables']['distribuimi'] ?? [];

    // Index snapshot by ID
    $snapById = [];
    foreach ($snapRows as $r) {
        $snapById[(int)$r['id']] = $r;
    }

    // Row IDs that were affected by revert_today.php recalculation
    $affectedIds = [279966,280123,280148,280868,281259,284111,285670,285841,
                    286140,286219,286333,286616,286778,286957,286990,287023,
                    287100,287130,287131,287211,287310,287381,287511,287541,
                    287724,289075,293785,295355,297580,297870,298422,302984,
                    308515,308686,310303,312648,312650,312651];

    $fixes = [];
    foreach ($affectedIds as $id) {
        if (!isset($snapById[$id])) continue;

        $snapPagesa = round((float)$snapById[$id]['pagesa'], 2);
        $snapLitratTotal = round((float)$snapById[$id]['litrat_total'], 2);
        $snapLitratKonv = round((float)$snapById[$id]['litrat_e_konvertuara'], 2);

        // Get current DB values
        $cur = $db->prepare("SELECT pagesa, litrat_total, litrat_e_konvertuara FROM distribuimi WHERE id = ?");
        $cur->execute([$id]);
        $dbRow = $cur->fetch();
        if (!$dbRow) continue;

        $dbPagesa = round((float)$dbRow['pagesa'], 2);
        $dbLitratTotal = round((float)$dbRow['litrat_total'], 2);
        $dbLitratKonv = round((float)$dbRow['litrat_e_konvertuara'], 2);

        if ($dbPagesa != $snapPagesa || $dbLitratTotal != $snapLitratTotal || $dbLitratKonv != $snapLitratKonv) {
            $fixes[] = [
                'id' => $id,
                'klienti' => $snapById[$id]['klienti'],
                'pagesa_current' => $dbPagesa,
                'pagesa_correct' => $snapPagesa,
                'pagesa_diff' => round($dbPagesa - $snapPagesa, 2),
                'litrat_total_current' => $dbLitratTotal,
                'litrat_total_correct' => $snapLitratTotal,
                'litrat_konv_current' => $dbLitratKonv,
                'litrat_konv_correct' => $snapLitratKonv
            ];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $totalPagesaDiff = array_sum(array_column($fixes, 'pagesa_diff'));
        echo json_encode([
            'rows_to_fix' => count($fixes),
            'total_pagesa_difference' => $totalPagesaDiff,
            'fixes' => $fixes
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        // Execute fixes with changelog logging
        $fixed = 0;
        $logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('update', 'distribuimi', ?, ?, ?, ?)");
        foreach ($fixes as $f) {
            $db->prepare("UPDATE distribuimi SET pagesa = ?, litrat_total = ?, litrat_e_konvertuara = ? WHERE id = ?")
               ->execute([$f['pagesa_correct'], $f['litrat_total_correct'], $f['litrat_konv_correct'], $f['id']]);
            // Log each changed field
            if ($f['pagesa_current'] != $f['pagesa_correct']) {
                $logStmt->execute([$f['id'], 'pagesa', (string)$f['pagesa_current'], (string)$f['pagesa_correct']]);
            }
            if ($f['litrat_total_current'] != $f['litrat_total_correct']) {
                $logStmt->execute([$f['id'], 'litrat_total', (string)$f['litrat_total_current'], (string)$f['litrat_total_correct']]);
            }
            if ($f['litrat_konv_current'] != $f['litrat_konv_correct']) {
                $logStmt->execute([$f['id'], 'litrat_e_konvertuara', (string)$f['litrat_konv_current'], (string)$f['litrat_konv_correct']]);
            }
            $fixed++;
        }
        echo json_encode([
            'success' => true,
            'fixed' => $fixed,
            'message' => "Restored correct pagesa values for {$fixed} rows"
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
