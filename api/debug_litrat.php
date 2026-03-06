<?php
/**
 * Temporary debug endpoint: investigate "leshuar me fature" differences
 * between Dashboard and Excel snapshot (Feb 9, 2026).
 *
 * Differences to explain:
 *   Dec 2025: Dashboard 44,330 vs Excel 44,430 (dashboard -100)
 *   Jan 2026: Dashboard 41,420 vs Excel 41,440 (dashboard -20)
 *   Feb 2026: Dashboard 1,930 vs Excel 0 (dashboard +1,930)
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    $invoiceMethods = ['po (fature te rregullte) cash', 'bank', 'po (fature te rregullte) banke'];
    $placeholders = implode(',', array_fill(0, count($invoiceMethods), '?'));

    // ---- 1) Current DB rows for each month (invoice-type only) ----
    $months = [
        'dec_2025' => ['2025-12-01', '2025-12-31', 44430],
        'jan_2026' => ['2026-01-01', '2026-01-31', 41440],
        'feb_2026' => ['2026-02-01', '2026-02-28', 0],
    ];

    $result = [];
    foreach ($months as $key => [$from, $to, $excelExpected]) {
        $stmt = $db->prepare("
            SELECT id, data, klienti, sasia, litra, litrat_e_konvertuara, menyra_e_pageses, pagesa
            FROM distribuimi
            WHERE data >= ? AND data <= ?
              AND LOWER(TRIM(menyra_e_pageses)) IN ($placeholders)
            ORDER BY data, id
        ");
        $params = array_merge([$from, $to], $invoiceMethods);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $sum = 0;
        foreach ($rows as $r) {
            $sum += (float)$r['litrat_e_konvertuara'];
        }

        $result[$key] = [
            'current_sum' => $sum,
            'expected_excel' => $excelExpected,
            'diff_from_excel' => $sum - $excelExpected,
            'row_count' => count($rows),
            'rows' => $rows,
        ];
    }

    // ---- 2) Changelog entries for distribuimi after Feb 9, 2026 ----
    $stmt = $db->prepare("
        SELECT id, table_name, row_id, action, field_name, old_value, new_value, created_at
        FROM changelog
        WHERE table_name = 'distribuimi'
          AND created_at >= '2026-02-09 00:00:00'
        ORDER BY created_at DESC
        LIMIT 200
    ");
    $stmt->execute();
    $changes = $stmt->fetchAll();

    // ---- 3) Find changelog entries specifically for invoice rows in Dec/Jan/Feb ----
    // Get all distribuimi row IDs for these months (invoice-type)
    $allInvoiceIds = [];
    foreach ($result as $mdata) {
        foreach ($mdata['rows'] as $r) {
            $allInvoiceIds[] = (int)$r['id'];
        }
    }

    $relevantChanges = [];
    if ($allInvoiceIds) {
        $idPlaceholders = implode(',', array_fill(0, count($allInvoiceIds), '?'));
        $stmt = $db->prepare("
            SELECT id, table_name, row_id, action, field_name, old_value, new_value, created_at
            FROM changelog
            WHERE table_name = 'distribuimi'
              AND row_id IN ($idPlaceholders)
            ORDER BY created_at DESC
        ");
        $stmt->execute($allInvoiceIds);
        $relevantChanges = $stmt->fetchAll();
    }

    // ---- 4) Also check for deleted rows or rows whose payment method changed ----
    $stmt = $db->prepare("
        SELECT id, table_name, row_id, action, field_name, old_value, new_value, created_at
        FROM changelog
        WHERE table_name = 'distribuimi'
          AND (field_name IN ('menyra_e_pageses', 'litrat_e_konvertuara', 'data')
               OR action IN ('delete', 'insert'))
          AND created_at >= '2026-02-09 00:00:00'
        ORDER BY created_at DESC
        LIMIT 200
    ");
    $stmt->execute();
    $fieldChanges = $stmt->fetchAll();

    // ---- 5) All distribuimi rows for these months (any payment method) for totals ----
    $allRowsTotals = [];
    foreach ($months as $key => [$from, $to, $excelExpected]) {
        $stmt = $db->prepare("
            SELECT LOWER(TRIM(menyra_e_pageses)) as method, 
                   COUNT(*) as cnt, 
                   SUM(litrat_e_konvertuara) as total_litrat
            FROM distribuimi
            WHERE data >= ? AND data <= ?
            GROUP BY LOWER(TRIM(menyra_e_pageses))
            ORDER BY total_litrat DESC
        ");
        $stmt->execute([$from, $to]);
        $allRowsTotals[$key] = $stmt->fetchAll();
    }

    echo json_encode([
        'invoice_rows_by_month' => $result,
        'all_changes_after_feb9' => $changes,
        'changes_to_invoice_rows' => $relevantChanges,
        'field_changes_after_feb9' => $fieldChanges,
        'all_methods_totals' => $allRowsTotals,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
