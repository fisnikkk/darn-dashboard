<?php
/**
 * Temporary debug endpoint: investigate Litrat ne dispozicion me fature discrepancy.
 * Dashboard shows 62,143.05 L vs Excel shows 54,187.05 L (difference: 7,956.00 L).
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    $bleraMonthly = $db->query("
        SELECT DATE_FORMAT(data, '%Y-%m') AS month, ROUND(SUM(sasia_ne_litra), 2) AS blera_litra, COUNT(*) AS row_count
        FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'
        GROUP BY DATE_FORMAT(data, '%Y-%m') ORDER BY month
    ")->fetchAll();

    $leshuarMonthly = $db->query("
        SELECT DATE_FORMAT(data, '%Y-%m') AS month, ROUND(SUM(litrat_e_konvertuara), 2) AS leshuar_litra, COUNT(*) AS row_count
        FROM distribuimi WHERE LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke')
        GROUP BY DATE_FORMAT(data, '%Y-%m') ORDER BY month
    ")->fetchAll();

    $bleraByMonth = [];
    foreach ($bleraMonthly as $r) { $bleraByMonth[$r['month']] = $r; }
    $leshuarByMonth = [];
    foreach ($leshuarMonthly as $r) { $leshuarByMonth[$r['month']] = $r; }

    $allMonths = array_unique(array_merge(array_column($bleraMonthly, 'month'), array_column($leshuarMonthly, 'month')));
    sort($allMonths);

    $runningTotal = 0;
    $monthlyBreakdown = [];
    $postExcelMonths = [];

    foreach ($allMonths as $m) {
        $blera = isset($bleraByMonth[$m]) ? (float)$bleraByMonth[$m]['blera_litra'] : 0;
        $bleraRows = isset($bleraByMonth[$m]) ? (int)$bleraByMonth[$m]['row_count'] : 0;
        $leshuar = isset($leshuarByMonth[$m]) ? (float)$leshuarByMonth[$m]['leshuar_litra'] : 0;
        $leshuarRows = isset($leshuarByMonth[$m]) ? (int)$leshuarByMonth[$m]['row_count'] : 0;
        $net = round($blera - $leshuar, 2);
        $runningTotal = round($runningTotal + $net, 2);
        $beyondExcel = ($m > '2026-01');
        $entry = ['month' => $m, 'blera_me_fature' => $blera, 'blera_rows' => $bleraRows, 'leshuar_me_fature' => $leshuar, 'leshuar_rows' => $leshuarRows, 'net_monthly' => $net, 'running_total' => $runningTotal];
        if ($beyondExcel) { $entry['flag'] = 'BEYOND_EXCEL_SNAPSHOT'; }
        $monthlyBreakdown[] = $entry;
        if ($beyondExcel) { $postExcelMonths[] = $entry; }
    }

    $totalBlera = $db->query("SELECT ROUND(COALESCE(SUM(sasia_ne_litra),0),2) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='me fature'")->fetchColumn();
    $totalLeshuar = $db->query("SELECT ROUND(COALESCE(SUM(litrat_e_konvertuara),0),2) FROM distribuimi WHERE LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke')")->fetchColumn();
    $grandTotal = round((float)$totalBlera - (float)$totalLeshuar, 2);

    $totalBleraUpToJan = $db->query("SELECT ROUND(COALESCE(SUM(sasia_ne_litra),0),2) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='me fature' AND data < '2026-02-01'")->fetchColumn();
    $totalLeshuarUpToJan = $db->query("SELECT ROUND(COALESCE(SUM(litrat_e_konvertuara),0),2) FROM distribuimi WHERE LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke') AND data < '2026-02-01'")->fetchColumn();
    $totalUpToJan = round((float)$totalBleraUpToJan - (float)$totalLeshuarUpToJan, 2);

    $bleraAfterSnapshot = $db->query("SELECT DATE_FORMAT(data,'%Y-%m') AS month, ROUND(SUM(sasia_ne_litra),2) AS blera_litra, COUNT(*) AS row_count FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='me fature' AND data >= '2026-02-01' GROUP BY DATE_FORMAT(data,'%Y-%m') ORDER BY month")->fetchAll();
    $leshuarAfterSnapshot = $db->query("SELECT DATE_FORMAT(data,'%Y-%m') AS month, ROUND(SUM(litrat_e_konvertuara),2) AS leshuar_litra, COUNT(*) AS row_count FROM distribuimi WHERE LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke') AND data >= '2026-02-01' GROUP BY DATE_FORMAT(data,'%Y-%m') ORDER BY month")->fetchAll();

    $bleraPaymentTypes = $db->query("SELECT menyra_e_pageses, COUNT(*) AS cnt FROM plini_depo GROUP BY menyra_e_pageses ORDER BY cnt DESC")->fetchAll();
    $leshuarPaymentTypes = $db->query("SELECT menyra_e_pageses, COUNT(*) AS cnt FROM distribuimi GROUP BY menyra_e_pageses ORDER BY cnt DESC")->fetchAll();

    $bleraNullDates = $db->query("SELECT COUNT(*) AS cnt, ROUND(COALESCE(SUM(sasia_ne_litra),0),2) AS total_litra FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='me fature' AND (data IS NULL OR data='0000-00-00')")->fetch();
    $leshuarNullDates = $db->query("SELECT COUNT(*) AS cnt, ROUND(COALESCE(SUM(litrat_e_konvertuara),0),2) AS total_litra FROM distribuimi WHERE LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke') AND (data IS NULL OR data='0000-00-00')")->fetch();

    echo json_encode([
        'investigation' => 'Litrat ne dispozicion me fature discrepancy',
        'dashboard_value' => 62143.05,
        'excel_value' => 54187.05,
        'difference' => round(62143.05 - 54187.05, 2),
        'grand_totals' => [
            'total_blera_me_fature' => (float)$totalBlera,
            'total_leshuar_me_fature' => (float)$totalLeshuar,
            'litrat_ne_dispozicion_ALL' => $grandTotal,
        ],
        'totals_up_to_jan_2026' => [
            'note' => 'Matching Excel range (data < 2026-02-01)',
            'blera_me_fature' => (float)$totalBleraUpToJan,
            'leshuar_me_fature' => (float)$totalLeshuarUpToJan,
            'litrat_ne_dispozicion' => $totalUpToJan,
        ],
        'data_beyond_jan_2026' => [
            'blera_after_feb' => $bleraAfterSnapshot,
            'leshuar_after_feb' => $leshuarAfterSnapshot,
            'months_beyond_excel' => $postExcelMonths,
            'extra_from_post_jan_months' => round($grandTotal - $totalUpToJan, 2),
        ],
        'monthly_breakdown' => $monthlyBreakdown,
        'null_or_zero_date_rows' => [
            'blera_null_dates' => $bleraNullDates,
            'leshuar_null_dates' => $leshuarNullDates,
        ],
        'reference_payment_types' => [
            'plini_depo_types' => $bleraPaymentTypes,
            'distribuimi_types' => $leshuarPaymentTypes,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
