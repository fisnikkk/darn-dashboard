<?php
/**
 * Compare ALL distribuimi rows between snapshot and current DB.
 * Finds every pagesa/litrat difference, not just changelog rows.
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    $snapFile = __DIR__ . '/../snapshots/Snapshot_Testues.json';
    $snap = json_decode(file_get_contents($snapFile), true);

    // Index snapshot distribuimi by ID
    $snapById = [];
    foreach ($snap['tables']['distribuimi'] as $r) {
        $snapById[(int)$r['id']] = $r;
    }

    // Get ALL current distribuimi rows
    $current = $db->query("SELECT id, klienti, data, sasia, litra, cmimi, pagesa, menyra_e_pageses, litrat_total, litrat_e_konvertuara FROM distribuimi ORDER BY id")->fetchAll();

    $diffs = [];
    $cashPagesaDiffTotal = 0;

    foreach ($current as $cur) {
        $id = (int)$cur['id'];
        if (!isset($snapById[$id])) continue; // New row since snapshot

        $sn = $snapById[$id];
        $curP = round((float)$cur['pagesa'], 2);
        $snP = round((float)$sn['pagesa'], 2);
        $curLT = round((float)$cur['litrat_total'], 2);
        $snLT = round((float)$sn['litrat_total'], 2);
        $curLK = round((float)$cur['litrat_e_konvertuara'], 2);
        $snLK = round((float)$sn['litrat_e_konvertuara'], 2);

        if (abs($curP - $snP) > 0.01 || abs($curLT - $snLT) > 0.01 || abs($curLK - $snLK) > 0.01) {
            $isCash = strtolower(trim($cur['menyra_e_pageses'])) === 'cash';
            $isFatureCash = strtolower(trim($cur['menyra_e_pageses'])) === 'po (fature te rregullte) cash';
            $afterDate = $cur['data'] >= '2022-08-29';
            $affectsBabi = ($isCash || $isFatureCash) && $afterDate;

            $pagesaDiff = round($curP - $snP, 2);
            if ($affectsBabi) $cashPagesaDiffTotal += $pagesaDiff;

            $diffs[] = [
                'id' => $id,
                'klienti' => $cur['klienti'],
                'data' => $cur['data'],
                'menyra' => $cur['menyra_e_pageses'],
                'affects_babi' => $affectsBabi,
                'pagesa_current' => $curP,
                'pagesa_snapshot' => $snP,
                'pagesa_diff' => $pagesaDiff,
                'sasia_current' => $cur['sasia'],
                'sasia_snapshot' => $sn['sasia'],
                'litrat_total_diff' => round($curLT - $snLT, 2),
                'litrat_konv_diff' => round($curLK - $snLK, 2)
            ];
        }
    }

    // Also find rows in current that don't exist in snapshot
    $newRows = [];
    foreach ($current as $cur) {
        if (!isset($snapById[(int)$cur['id']])) {
            $isCash = strtolower(trim($cur['menyra_e_pageses'])) === 'cash';
            $isFatureCash = strtolower(trim($cur['menyra_e_pageses'])) === 'po (fature te rregullte) cash';
            $afterDate = $cur['data'] >= '2022-08-29';
            $affectsBabi = ($isCash || $isFatureCash) && $afterDate;

            if ($affectsBabi) $cashPagesaDiffTotal += round((float)$cur['pagesa'], 2);

            $newRows[] = [
                'id' => (int)$cur['id'],
                'klienti' => $cur['klienti'],
                'data' => $cur['data'],
                'menyra' => $cur['menyra_e_pageses'],
                'pagesa' => round((float)$cur['pagesa'], 2),
                'affects_babi' => $affectsBabi
            ];
        }
    }

    echo json_encode([
        'total_diffs' => count($diffs),
        'cash_pagesa_diff_total' => $cashPagesaDiffTotal,
        'note' => 'cash_pagesa_diff_total shows how much extra pagesa is in CASH rows vs snapshot (positive = current is higher)',
        'diffs' => $diffs,
        'new_rows_since_snapshot' => $newRows
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
