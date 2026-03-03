<?php
/**
 * Diagnose Babi Cash discrepancy.
 * Shows the exact breakdown and checks rows that were changed today.
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    // Babi Cash (Gaz) formula
    $babiPayments = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash
        FROM distribuimi
        WHERE data >= '2022-08-29'
    ")->fetch();

    $babiExpenses = $db->query("
        SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet
        WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'
        AND (data_e_pageses >= '2022-08-29' OR data_e_pageses IS NULL OR data_e_pageses = '0000-00-00')
    ")->fetchColumn();

    $babiManual = 281.9;
    $babiGaz = $babiPayments['cash'] + $babiPayments['fature_cash'] - $babiExpenses + $babiManual;

    $babiProd = $db->query("
        SELECT COALESCE(SUM(totali), 0) FROM shitje_produkteve
        WHERE LOWER(TRIM(menyra_pageses)) = 'cash' AND data >= '2022-09-07'
    ")->fetchColumn();

    $babiTotal = $babiGaz + $babiProd;

    // Check the specific test rows from today's changelog
    $testRows = [312648, 312650, 312651];
    $rowDetails = [];
    foreach ($testRows as $rid) {
        $r = $db->prepare("SELECT id, klienti, data, sasia, litra, cmimi, pagesa, menyra_e_pageses, fatura_e_derguar, litrat_total, litrat_e_konvertuara FROM distribuimi WHERE id = ?");
        $r->execute([$rid]);
        $row = $r->fetch();
        if ($row) $rowDetails[] = $row;
    }

    // Also check changelog for these rows
    $changelogForTestRows = $db->query("
        SELECT id, table_name, row_id, field_name, old_value, new_value, action_type, created_at, reverted
        FROM changelog
        WHERE row_id IN (312648, 312650, 312651)
        AND DATE(created_at) = CURDATE()
        ORDER BY created_at ASC
    ")->fetchAll();

    // Find any rows from today's changelog where current value != original
    $stillWrong = $db->query("
        SELECT c.table_name, c.row_id, c.field_name, c.old_value as original_value, c.created_at as first_change
        FROM changelog c
        INNER JOIN (
            SELECT table_name, row_id, field_name, MIN(created_at) as first_change
            FROM changelog
            WHERE DATE(created_at) = CURDATE() AND action_type = 'update'
            GROUP BY table_name, row_id, field_name
        ) e ON c.table_name = e.table_name AND c.row_id = e.row_id
           AND c.field_name = e.field_name AND c.created_at = e.first_change
        WHERE DATE(c.created_at) = CURDATE() AND c.action_type = 'update'
        ORDER BY c.table_name, c.row_id
    ")->fetchAll();

    // For each, check current value
    $discrepancies = [];
    foreach ($stillWrong as $sw) {
        try {
            $cur = $db->prepare("SELECT `{$sw['field_name']}` FROM `{$sw['table_name']}` WHERE id = ?");
            $cur->execute([$sw['row_id']]);
            $currentVal = $cur->fetchColumn();
            if ($currentVal != $sw['original_value']) {
                $discrepancies[] = [
                    'table' => $sw['table_name'],
                    'row_id' => $sw['row_id'],
                    'field' => $sw['field_name'],
                    'should_be' => $sw['original_value'],
                    'currently_is' => $currentVal,
                    'first_change' => $sw['first_change']
                ];
            }
        } catch (PDOException $e) {
            // skip
        }
    }

    echo json_encode([
        'babi_cash_gaz' => round($babiGaz, 2),
        'babi_cash_gaz_components' => [
            'cash_from_distribuimi' => round((float)$babiPayments['cash'], 2),
            'fature_cash_from_distribuimi' => round((float)$babiPayments['fature_cash'], 2),
            'expenses_cash' => round((float)$babiExpenses, 2),
            'manual_adj' => $babiManual
        ],
        'babi_cash_produkte' => round((float)$babiProd, 2),
        'babi_cash_total' => round($babiTotal, 2),
        'expected_total' => 21019.14,
        'difference' => round($babiTotal - 21019.14, 2),
        'test_rows_current_state' => $rowDetails,
        'test_rows_changelog_today' => $changelogForTestRows,
        'remaining_discrepancies' => $discrepancies
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
