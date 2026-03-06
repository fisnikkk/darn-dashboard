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

    $invoiceMethods = ['po (fature te rregullte) cash', 'bank', 'po (fature te rregullte) banke'];
    $placeholders = implode(',', array_fill(0, count($invoiceMethods), '?'));

    // ---- 1) Sum litrat_e_konvertuara for invoice methods per month ----
    $months = [
        'dec_2025' => ['2025-12-01', '2025-12-31', 44430],
        'jan_2026' => ['2026-01-01', '2026-01-31', 41440],
        'feb_2026' => ['2026-02-01', '2026-02-28', 0],
    ];

    $summaries = [];
    foreach ($months as $key => [$from, $to, $excelExpected]) {
        // Sum by method
        $stmt = $db->prepare("
            SELECT LOWER(TRIM(menyra_e_pageses)) as method, 
                   COUNT(*) as cnt, 
                   ROUND(SUM(litrat_e_konvertuara)) as total_litrat
            FROM distribuimi
            WHERE data >= ? AND data <= ?
              AND LOWER(TRIM(menyra_e_pageses)) IN ($placeholders)
            GROUP BY LOWER(TRIM(menyra_e_pageses))
        ");
        $params = array_merge([$from, $to], $invoiceMethods);
        $stmt->execute($params);
        $byMethod = $stmt->fetchAll();

        // Total
        $stmt2 = $db->prepare("
            SELECT ROUND(SUM(litrat_e_konvertuara)) as total
            FROM distribuimi
            WHERE data >= ? AND data <= ?
              AND LOWER(TRIM(menyra_e_pageses)) IN ($placeholders)
        ");
        $stmt2->execute($params);
        $total = (int)$stmt2->fetchColumn();

        $summaries[$key] = [
            'current_db_total' => $total,
            'excel_expected' => $excelExpected,
            'diff' => $total - $excelExpected,
            'by_method' => $byMethod,
        ];
    }

    // ---- 2) All methods totals for context ----
    $allMethodsTotals = [];
    foreach ($months as $key => [$from, $to, $excelExpected]) {
        $stmt = $db->prepare("
            SELECT LOWER(TRIM(menyra_e_pageses)) as method, 
                   COUNT(*) as cnt, 
                   ROUND(SUM(litrat_e_konvertuara)) as total_litrat
            FROM distribuimi
            WHERE data >= ? AND data <= ?
            GROUP BY LOWER(TRIM(menyra_e_pageses))
            ORDER BY total_litrat DESC
        ");
        $stmt->execute([$from, $to]);
        $allMethodsTotals[$key] = $stmt->fetchAll();
    }

    // ---- 3) Changelog for distribuimi after Feb 9 - summary ----
    $changesSummary = $db->query("
        SELECT field_name, COUNT(*) as cnt
        FROM changelog
        WHERE table_name = 'distribuimi'
          AND created_at >= '2026-02-09 00:00:00'
        GROUP BY field_name
        ORDER BY cnt DESC
    ")->fetchAll();

    // ---- 4) Recent changes to litrat_e_konvertuara or menyra_e_pageses ----
    $litratChanges = $db->query("
        SELECT c.row_id, c.field_name, c.old_value, c.new_value, c.created_at,
               d.data, d.klienti, d.menyra_e_pageses, d.litrat_e_konvertuara
        FROM changelog c
        LEFT JOIN distribuimi d ON d.id = c.row_id
        WHERE c.table_name = 'distribuimi'
          AND c.created_at >= '2026-02-09 00:00:00'
          AND c.field_name IN ('litrat_e_konvertuara', 'menyra_e_pageses', 'data', 'sasia', 'litra')
        ORDER BY c.created_at DESC
        LIMIT 100
    ")->fetchAll();

    // ---- 5) Feb 2026 invoice rows (small set, show details) ----
    $febRows = [];
    $stmt = $db->prepare("
        SELECT id, data, klienti, sasia, litra, litrat_e_konvertuara, menyra_e_pageses, pagesa
        FROM distribuimi
        WHERE data >= '2026-02-01' AND data <= '2026-02-28'
          AND LOWER(TRIM(menyra_e_pageses)) IN ($placeholders)
        ORDER BY data, id
    ");
    $stmt->execute($invoiceMethods);
    $febRows = $stmt->fetchAll();

    // ---- 6) Check for rows inserted after Feb 9 in Dec/Jan/Feb ----
    // We check changelog for 'insert' actions
    $insertedRows = $db->query("
        SELECT c.row_id, c.created_at, d.data, d.klienti, d.litrat_e_konvertuara, d.menyra_e_pageses
        FROM changelog c
        LEFT JOIN distribuimi d ON d.id = c.row_id
        WHERE c.table_name = 'distribuimi'
          AND c.created_at >= '2026-02-09 00:00:00'
          AND c.action_type = 'insert'
          AND d.data >= '2025-12-01' AND d.data <= '2026-02-28'
          AND LOWER(TRIM(d.menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke')
        ORDER BY c.created_at DESC
    ")->fetchAll();

    // ---- 7) Deleted rows from changelog (action_type = 'delete') ----
    $deletedRows = $db->query("
        SELECT c.row_id, c.field_name, c.old_value, c.new_value, c.created_at
        FROM changelog c
        WHERE c.table_name = 'distribuimi'
          AND c.created_at >= '2026-02-09 00:00:00'
          AND c.action_type = 'delete'
        ORDER BY c.created_at DESC
        LIMIT 50
    ")->fetchAll();

    echo json_encode([
        'invoice_summaries' => $summaries,
        'all_methods_by_month' => $allMethodsTotals,
        'changelog_field_summary' => $changesSummary,
        'litrat_field_changes' => $litratChanges,
        'feb_invoice_rows' => $febRows,
        'rows_inserted_after_feb9' => $insertedRows,
        'rows_deleted_after_feb9' => $deletedRows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
