<?php
/**
 * Temporary debug endpoint: investigate "leshuar me fature" differences
 * between Dashboard and Excel snapshot (Feb 9, 2026).
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    // First, discover changelog column names
    $clCols = $db->query("SHOW COLUMNS FROM changelog")->fetchAll(PDO::FETCH_COLUMN);

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

    // ---- 2) All changelog entries for distribuimi after Feb 9, 2026 ----
    $changes = $db->query("
        SELECT * FROM changelog
        WHERE table_name = 'distribuimi'
          AND created_at >= '2026-02-09 00:00:00'
        ORDER BY created_at DESC
        LIMIT 200
    ")->fetchAll();

    // ---- 3) Find changelog entries specifically for our invoice row IDs ----
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
            SELECT * FROM changelog
            WHERE table_name = 'distribuimi'
              AND row_id IN ($idPlaceholders)
            ORDER BY created_at DESC
        ");
        $stmt->execute($allInvoiceIds);
        $relevantChanges = $stmt->fetchAll();
    }

    // ---- 4) All distribuimi rows for these months grouped by method ----
    $allMethodsTotals = [];
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
        $allMethodsTotals[$key] = $stmt->fetchAll();
    }

    echo json_encode([
        'changelog_columns' => $clCols,
        'invoice_rows_by_month' => $result,
        'all_changes_after_feb9' => $changes,
        'changes_to_invoice_rows' => $relevantChanges,
        'all_methods_totals' => $allMethodsTotals,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
