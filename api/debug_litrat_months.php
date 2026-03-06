<?php
ini_set("memory_limit", "256M");
set_time_limit(60);
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../config/database.php";
try {
    $pdo = getDB();
    $excel_blera = [
        '2021-11' => 0, '2021-12' => 12675, '2022-01' => 8833.5, '2022-02' => 3042, '2022-03' => 17434.95, '2022-04' => 19500, '2022-05' => 4914, '2022-06' => 3451.5, '2022-07' => 4504.5, '2022-08' => 0, '2022-09' => 0, '2022-10' => 0, '2022-11' => 9087, '2022-12' => 8443.5, '2023-01' => 9305.5, '2023-02' => 14763, '2023-03' => 9083.5, '2023-04' => 4643.5, '2023-05' => 13449.5, '2023-06' => 8232.5, '2023-07' => 12710, '2023-08' => 23776, '2023-09' => 15786, '2023-10' => 14463.2, '2023-11' => 28904, '2023-12' => 18760, '2024-01' => 18400, '2024-02' => 18620, '2024-03' => 13400, '2024-04' => 17900, '2024-05' => 11900, '2024-06' => 18400, '2024-07' => 14650, '2024-08' => 25889, '2024-09' => 16900, '2024-10' => 25500, '2024-11' => 33800, '2024-12' => 21200, '2025-01' => 16240, '2025-02' => 47190, '2025-03' => 16250, '2025-04' => 18980, '2025-05' => 16640, '2025-06' => 31720, '2025-07' => 16510, '2025-08' => 37050, '2025-09' => 18279.3, '2025-10' => 49708.1, '2025-11' => 49476.7, '2025-12' => 64886.9, '2026-01' => 36020.4, '2026-02' => 0
    ];
    $excel_leshuar = [
        '2021-11' => 156, '2021-12' => 1260, '2022-01' => 1200, '2022-02' => 1500, '2022-03' => 2300, '2022-04' => 1280, '2022-05' => 1370, '2022-06' => 2210, '2022-07' => 1500, '2022-08' => 1580, '2022-09' => 2000, '2022-10' => 3810, '2022-11' => 10310, '2022-12' => 12760, '2023-01' => 13450, '2023-02' => 13670, '2023-03' => 14770, '2023-04' => 12230, '2023-05' => 14860, '2023-06' => 12680, '2023-07' => 15270, '2023-08' => 15880, '2023-09' => 15240, '2023-10' => 16280, '2023-11' => 17590, '2023-12' => 23370, '2024-01' => 23530, '2024-02' => 19590, '2024-03' => 17970, '2024-04' => 17050, '2024-05' => 17850, '2024-06' => 14920, '2024-07' => 20050, '2024-08' => 22430, '2024-09' => 20560, '2024-10' => 21180, '2024-11' => 23360, '2024-12' => 22530, '2025-01' => 30450, '2025-02' => 31180, '2025-03' => 25700, '2025-04' => 9690, '2025-05' => 29790, '2025-06' => 25910, '2025-07' => 27260, '2025-08' => 30820, '2025-09' => 30890, '2025-10' => 36690, '2025-11' => 33290, '2025-12' => 44430, '2026-01' => 41440, '2026-02' => 0
    ];
    $all_months = array_unique(array_merge(array_keys($excel_blera), array_keys($excel_leshuar)));
    sort($all_months);

    $stmt = $pdo->query("SELECT DATE_FORMAT(data, '%Y-%m') as month_key, SUM(sasia_ne_litra) as total_litra FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature' GROUP BY DATE_FORMAT(data, '%Y-%m') ORDER BY month_key");
    $db_blera = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $db_blera[$row['month_key']] = round(floatval($row['total_litra']), 2); }

    $stmt = $pdo->query("SELECT DATE_FORMAT(data, '%Y-%m') as month_key, SUM(litrat_e_konvertuara) as total_litra FROM distribuimi WHERE LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke') GROUP BY DATE_FORMAT(data, '%Y-%m') ORDER BY month_key");
    $db_leshuar = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $db_leshuar[$row['month_key']] = round(floatval($row['total_litra']), 2); }

    $db_only_months = array_unique(array_merge(array_keys($db_blera), array_keys($db_leshuar)));
    $all_months = array_unique(array_merge($all_months, $db_only_months));
    sort($all_months);
    $month_details = [];
    $rdb = 0; $reb = 0; $rdl = 0; $rel = 0;

    foreach ($all_months as $m) {
        $db_b = isset($db_blera[$m]) ? $db_blera[$m] : 0;
        $ex_b = isset($excel_blera[$m]) ? $excel_blera[$m] : 0;
        $db_l = isset($db_leshuar[$m]) ? $db_leshuar[$m] : 0;
        $ex_l = isset($excel_leshuar[$m]) ? $excel_leshuar[$m] : 0;
        $rdb += $db_b; $reb += $ex_b; $rdl += $db_l; $rel += $ex_l;
        $bd = round($db_b - $ex_b, 2);
        $ld = round($db_l - $ex_l, 2);
        $entry = ['month' => $m, 'blera' => ['db' => $db_b, 'excel' => $ex_b, 'diff' => $bd], 'leshuar' => ['db' => $db_l, 'excel' => $ex_l, 'diff' => $ld], 'running_totals' => ['db_blera' => round($rdb,2), 'excel_blera' => round($reb,2), 'blera_cumulative_diff' => round($rdb-$reb,2), 'db_leshuar' => round($rdl,2), 'excel_leshuar' => round($rel,2), 'leshuar_cumulative_diff' => round($rdl-$rel,2)]];
        if (abs($bd) > 0.01) {
            $s2 = $pdo->prepare("SELECT id, data, sasia_ne_litra, menyra_e_pageses FROM plini_depo WHERE DATE_FORMAT(data, '%Y-%m') = ? AND LOWER(TRIM(menyra_e_pageses)) = 'me fature' ORDER BY data");
            $s2->execute([$m]);
            $entry['blera']['db_rows'] = $s2->fetchAll(PDO::FETCH_ASSOC);
            $s3 = $pdo->prepare("SELECT menyra_e_pageses, COUNT(*) as cnt, SUM(sasia_ne_litra) as total FROM plini_depo WHERE DATE_FORMAT(data, '%Y-%m') = ? GROUP BY menyra_e_pageses");
            $s3->execute([$m]);
            $entry['blera']['all_payment_types'] = $s3->fetchAll(PDO::FETCH_ASSOC);
        }
        if (abs($ld) > 0.01) {
            $s2 = $pdo->prepare("SELECT menyra_e_pageses, COUNT(*) as row_count, SUM(litrat_e_konvertuara) as total_litra FROM distribuimi WHERE DATE_FORMAT(data, '%Y-%m') = ? GROUP BY menyra_e_pageses");
            $s2->execute([$m]);
            $entry['leshuar']['db_summary_by_payment'] = $s2->fetchAll(PDO::FETCH_ASSOC);
            $s3 = $pdo->prepare("SELECT menyra_e_pageses, COUNT(*) as row_count, SUM(litrat_e_konvertuara) as total_litra FROM distribuimi WHERE DATE_FORMAT(data, '%Y-%m') = ? AND LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke') GROUP BY menyra_e_pageses");
            $s3->execute([$m]);
            $entry['leshuar']['fature_only'] = $s3->fetchAll(PDO::FETCH_ASSOC);
        }
        $month_details[] = $entry;
    }
    $tdb = round($rdb, 2); $teb = round($reb, 2); $tdl = round($rdl, 2); $tel = round($rel, 2);
    $db_disp = round($tdb - $tdl, 2); $ex_disp = round($teb - $tel, 2);
    $btd = round($tdb - $teb, 2); $ltd = round($tdl - $tel, 2);
    $result = [
        'summary' => [
            'total_blera' => ['db' => $tdb, 'excel' => $teb, 'diff' => $btd],
            'total_leshuar' => ['db' => $tdl, 'excel' => $tel, 'diff' => $ltd],
            'litrat_ne_dispozicion' => ['db' => $db_disp, 'excel' => $ex_disp, 'diff' => round($db_disp - $ex_disp, 2), 'explanation' => 'diff = blera_diff - leshuar_diff = ' . $btd . ' - ' . $ltd . ' = ' . round($btd - $ltd, 2)]
        ],
        'months_with_blera_diff' => array_values(array_filter($month_details, function($e) { return abs($e['blera']['diff']) > 0.01; })),
        'months_with_leshuar_diff' => array_values(array_filter($month_details, function($e) { return abs($e['leshuar']['diff']) > 0.01; })),
        'all_months' => $month_details
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}