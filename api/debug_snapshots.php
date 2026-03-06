<?php
/**
 * Temporary debug endpoint: investigate snapshots vs current data vs Excel expectations.
 * Safe to delete after investigation.
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();
    $result = [];

    // 1. List all snapshots (without snapshot_data)
    $result['snapshots'] = $db->query("
        SELECT id, name, created_at, size_bytes
        FROM snapshots
        ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Current live data: month-by-month breakdown
    // plini_depo: SUM(sasia_ne_litra) WHERE menyra_e_pageses = 'Me fature' grouped by month
    $pliniMonthly = $db->query("
        SELECT
            DATE_FORMAT(data, '%Y-%m') AS month,
            COALESCE(SUM(sasia_ne_litra), 0) AS litra_blera
        FROM plini_depo
        WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'
        AND data IS NOT NULL AND data != '0000-00-00'
        GROUP BY DATE_FORMAT(data, '%Y-%m')
        ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // distribuimi: SUM(litrat_e_konvertuara) WHERE menyra_e_pageses IN (...) grouped by month
    $distMonthly = $db->query("
        SELECT
            DATE_FORMAT(data, '%Y-%m') AS month,
            COALESCE(SUM(litrat_e_konvertuara), 0) AS litra_leshuar
        FROM distribuimi
        WHERE LOWER(TRIM(menyra_e_pageses)) IN (
            'po (fature te rregullte) cash',
            'bank',
            'po (fature te rregullte) banke'
        )
        AND data IS NOT NULL AND data != '0000-00-00'
        GROUP BY DATE_FORMAT(data, '%Y-%m')
        ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Build a combined month-by-month view
    $pliniByMonth = [];
    foreach ($pliniMonthly as $r) {
        $pliniByMonth[$r['month']] = (float) $r['litra_blera'];
    }
    $distByMonth = [];
    foreach ($distMonthly as $r) {
        $distByMonth[$r['month']] = (float) $r['litra_leshuar'];
    }

    $allMonths = array_unique(array_merge(array_keys($pliniByMonth), array_keys($distByMonth)));
    sort($allMonths);

    $runningTotal = 0;
    $monthlyBreakdown = [];
    foreach ($allMonths as $m) {
        $blera = $pliniByMonth[$m] ?? 0;
        $leshuar = $distByMonth[$m] ?? 0;
        $diff = $blera - $leshuar;
        $runningTotal += $diff;
        $monthlyBreakdown[] = [
            'month' => $m,
            'plini_depo_litra_blera' => round($blera, 4),
            'distribuimi_litra_leshuar' => round($leshuar, 4),
            'difference_blera_minus_leshuar' => round($diff, 4),
            'running_total' => round($runningTotal, 4)
        ];
    }
    $result['monthly_breakdown'] = $monthlyBreakdown;

    // Totals for plini_depo and distribuimi (the specific filtered sums)
    $result['totals'] = [
        'plini_depo_litra_blera_me_fature' => (float) $db->query("
            SELECT COALESCE(SUM(sasia_ne_litra), 0) FROM plini_depo
            WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'
        ")->fetchColumn(),
        'distribuimi_litra_leshuar_fature' => (float) $db->query("
            SELECT COALESCE(SUM(litrat_e_konvertuara), 0) FROM distribuimi
            WHERE LOWER(TRIM(menyra_e_pageses)) IN (
                'po (fature te rregullte) cash',
                'bank',
                'po (fature te rregullte) banke'
            )
        ")->fetchColumn(),
        'plini_depo_litra_null_date' => (float) $db->query("
            SELECT COALESCE(SUM(sasia_ne_litra), 0) FROM plini_depo
            WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'
            AND (data IS NULL OR data = '0000-00-00')
        ")->fetchColumn(),
        'distribuimi_litra_null_date' => (float) $db->query("
            SELECT COALESCE(SUM(litrat_e_konvertuara), 0) FROM distribuimi
            WHERE LOWER(TRIM(menyra_e_pageses)) IN (
                'po (fature te rregullte) cash',
                'bank',
                'po (fature te rregullte) banke'
            )
            AND (data IS NULL OR data = '0000-00-00')
        ")->fetchColumn()
    ];

    // 3. Row counts
    $result['row_counts'] = [
        'distribuimi_total' => (int) $db->query("SELECT COUNT(*) FROM distribuimi")->fetchColumn(),
        'plini_depo_total' => (int) $db->query("SELECT COUNT(*) FROM plini_depo")->fetchColumn()
    ];

    // 4. Changelog changes to distribuimi or plini_depo after 2026-02-09
    $result['changelog_after_feb9'] = $db->query("
        SELECT
            DATE(created_at) AS change_date,
            table_name,
            COUNT(*) AS change_count,
            GROUP_CONCAT(DISTINCT field_name ORDER BY field_name SEPARATOR ', ') AS fields_changed
        FROM changelog
        WHERE table_name IN ('distribuimi', 'plini_depo')
        AND created_at > '2026-02-09 23:59:59'
        GROUP BY DATE(created_at), table_name
        ORDER BY change_date ASC, table_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
