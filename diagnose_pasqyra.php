<?php
/**
 * Diagnostic: Compare Pasqyra values with Excel expectations
 */
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

echo "=== PASQYRA DIAGNOSTIC ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// ============================================================
// 1. Cash nga shitjet e produkteve
// Dashboard: SUM(totali) WHERE menyra_pageses = 'cash'
// Excel: 54,813.24 | Dashboard: 54,873.24 | Diff: 60.00
// ============================================================
echo "=== 1. CASH NGA SHITJET E PRODUKTEVE ===\n";
$produkteCash = $db->query("SELECT COALESCE(SUM(totali), 0) FROM shitje_produkteve WHERE LOWER(TRIM(menyra_pageses)) = 'cash'")->fetchColumn();
echo "Dashboard query (cash): $produkteCash\n";
echo "Excel expected: 54813.24\n";
echo "Difference: " . round($produkteCash - 54813.24, 2) . "\n\n";

// Check all payment types in shitje_produkteve
echo "Payment type breakdown in shitje_produkteve:\n";
$sp = $db->query("SELECT LOWER(TRIM(menyra_pageses)) as typ, COUNT(*) as cnt, SUM(totali) as total FROM shitje_produkteve GROUP BY LOWER(TRIM(menyra_pageses)) ORDER BY total DESC")->fetchAll();
foreach ($sp as $r) echo "  '{$r['typ']}': {$r['cnt']} rows, total={$r['total']}\n";
echo "\n";

// Check if there are rows with date issues or duplicates
$spTotal = $db->query("SELECT COUNT(*) FROM shitje_produkteve")->fetchColumn();
echo "Total rows in shitje_produkteve: $spTotal\n";
$spMinDate = $db->query("SELECT MIN(data) FROM shitje_produkteve")->fetchColumn();
$spMaxDate = $db->query("SELECT MAX(data) FROM shitje_produkteve")->fetchColumn();
echo "Date range: $spMinDate to $spMaxDate\n\n";

// Check for 'Cash' vs 'cash' differences
$cashVariants = $db->query("SELECT menyra_pageses, COUNT(*) as cnt, SUM(totali) as total FROM shitje_produkteve WHERE LOWER(TRIM(menyra_pageses)) = 'cash' GROUP BY menyra_pageses")->fetchAll();
echo "Cash variants:\n";
foreach ($cashVariants as $r) echo "  '{$r['menyra_pageses']}': {$r['cnt']} rows, total={$r['total']}\n";
echo "\n";

// ============================================================
// 2. Pare cash nga plini
// Dashboard: paymentsFrom[cash] + paymentsFrom[fature_cash] - shpenzimCashFrom + 281.9
// Excel: -29,730.90 | Dashboard: -29,762.90 | Diff: 32.00
// ============================================================
echo "=== 2. PARE CASH NGA PLINI ===\n";

// Dashboard formula components (from 2022-08-28)
$paymentsFrom = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash
    FROM distribuimi WHERE data >= '2022-08-28'
")->fetch();
$shpenzimCashFrom = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash' AND data_e_pageses >= '2022-08-28'")->fetchColumn();

echo "From 2022-08-28 onwards:\n";
echo "  Cash from distribuimi: {$paymentsFrom['cash']}\n";
echo "  Fature cash from distribuimi: {$paymentsFrom['fature_cash']}\n";
echo "  Shpenzime cash: $shpenzimCashFrom\n";
echo "  Pre-system addition: 281.9\n";
$pareCashPlin = $paymentsFrom['cash'] + $paymentsFrom['fature_cash'] - $shpenzimCashFrom + 281.9;
echo "  Dashboard = {$paymentsFrom['cash']} + {$paymentsFrom['fature_cash']} - $shpenzimCashFrom + 281.9 = $pareCashPlin\n";
echo "  Excel expected: -29730.90\n";
echo "  Difference: " . round($pareCashPlin - (-29730.90), 2) . "\n\n";

// Also show ALL dates version (no date filter) for comparison
$paymentsAll = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash
    FROM distribuimi
")->fetch();
$shpenzimCashAll = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'")->fetchColumn();
echo "ALL dates (no date filter):\n";
echo "  Cash from distribuimi (G3): {$paymentsAll['cash']}\n";
echo "  Fature cash from distribuimi (H3): {$paymentsAll['fature_cash']}\n";
echo "  Shpenzime cash ALL (U3): $shpenzimCashAll\n";
$L3_all = $paymentsAll['cash'] + $paymentsAll['fature_cash'] - $shpenzimCashAll;
echo "  L3 = G3+H3-U3 = {$paymentsAll['cash']} + {$paymentsAll['fature_cash']} - $shpenzimCashAll = $L3_all\n\n";

// ============================================================
// 3. Total pare cash (plin + produkte)
// Dashboard: pareCashPlin + produkteCash
// Excel: 189,500.87 (formula L3+N3, L3=G3+H3-U3)
// ============================================================
echo "=== 3. TOTAL PARE CASH (PLIN + PRODUKTE) ===\n";
$totalPareDashboard = $pareCashPlin + $produkteCash;
echo "Dashboard: $pareCashPlin + $produkteCash = $totalPareDashboard\n";
$L3_nofilt = $paymentsAll['cash'] + $paymentsAll['fature_cash'] - $shpenzimCashAll;
$excelTotal = $L3_nofilt + $produkteCash;
echo "Excel formula L3+N3 (all dates): $L3_nofilt + $produkteCash = $excelTotal\n";
echo "Excel expected: 189500.87\n";
echo "Dashboard value: $totalPareDashboard\n\n";

// What if Excel N3 uses a different value for produkteCash?
echo "If Excel's N3 = 54813.24 (their value): " . round($L3_nofilt + 54813.24, 2) . "\n";
echo "Excel expected total: 189500.87\n";
echo "So Excel L3 should be: " . round(189500.87 - 54813.24, 2) . "\n";
echo "Our L3 (all dates): $L3_nofilt\n\n";

// ============================================================
// 4. Shpenzime tjera
// Dashboard: SUM(shuma) WHERE lloji_i_transaksionit = 'shpenzim'
// Excel: 314,560.42 | Dashboard: 314,606.42 | Diff: 46.00
// ============================================================
echo "=== 4. SHPENZIME TJERA ===\n";
$shpenzimTjera = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit)) = 'shpenzim'")->fetchColumn();
echo "Dashboard: $shpenzimTjera\n";
echo "Excel expected: 314560.42\n";
echo "Difference: " . round($shpenzimTjera - 314560.42, 2) . "\n\n";

// Check all transaction types
echo "Transaction type breakdown in shpenzimet:\n";
$shpTrans = $db->query("SELECT LOWER(TRIM(lloji_i_transaksionit)) as typ, COUNT(*) as cnt, SUM(shuma) as total FROM shpenzimet GROUP BY LOWER(TRIM(lloji_i_transaksionit)) ORDER BY total DESC")->fetchAll();
foreach ($shpTrans as $r) echo "  '{$r['typ']}': {$r['cnt']} rows, total={$r['total']}\n";
echo "\n";

// ============================================================
// 5. Shpenzimet Cash
// Dashboard: SUM(shuma) WHERE lloji_i_pageses = 'cash'
// Excel: 940,939.25 | Dashboard: 1,101,059.63 | Diff: ~160,120
// ============================================================
echo "=== 5. SHPENZIMET CASH ===\n";
$shpCashAll = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'")->fetchColumn();
echo "Dashboard (all cash): $shpCashAll\n";
echo "Excel expected: 940939.25\n";
echo "Difference: " . round($shpCashAll - 940939.25, 2) . "\n\n";

// Breakdown by transaction type for CASH payments only
echo "Cash payments by transaction type:\n";
$cashByType = $db->query("SELECT LOWER(TRIM(lloji_i_transaksionit)) as typ, COUNT(*) as cnt, SUM(shuma) as total FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash' GROUP BY LOWER(TRIM(lloji_i_transaksionit)) ORDER BY total DESC")->fetchAll();
foreach ($cashByType as $r) echo "  '{$r['typ']}': {$r['cnt']} rows, total={$r['total']}\n";
echo "\n";

// What if Excel excludes 'pagesa per plin' from cash expenses?
$cashNoPlin = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash' AND LOWER(TRIM(lloji_i_transaksionit)) != 'pagesa per plin'")->fetchColumn();
echo "Cash WITHOUT 'pagesa per plin': $cashNoPlin\n";

// What if Excel excludes deponim types?
$cashNoDeponim = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash' AND LOWER(TRIM(lloji_i_transaksionit)) NOT LIKE '%deponim%'")->fetchColumn();
echo "Cash WITHOUT deponim types: $cashNoDeponim\n";

// What if both excluded?
$cashNoPlinNoDeponim = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash' AND LOWER(TRIM(lloji_i_transaksionit)) != 'pagesa per plin' AND LOWER(TRIM(lloji_i_transaksionit)) NOT LIKE '%deponim%'")->fetchColumn();
echo "Cash WITHOUT plin AND deponim: $cashNoPlinNoDeponim\n\n";

// Payment type breakdown for ALL shpenzimet
echo "All payment types in shpenzimet:\n";
$payTypes = $db->query("SELECT LOWER(TRIM(lloji_i_pageses)) as typ, COUNT(*) as cnt, SUM(shuma) as total FROM shpenzimet GROUP BY LOWER(TRIM(lloji_i_pageses)) ORDER BY total DESC")->fetchAll();
foreach ($payTypes as $r) echo "  '{$r['typ']}': {$r['cnt']} rows, total={$r['total']}\n";
echo "\n";

// Check row counts
echo "=== ROW COUNTS ===\n";
echo "distribuimi: " . $db->query("SELECT COUNT(*) FROM distribuimi")->fetchColumn() . "\n";
echo "shpenzimet: " . $db->query("SELECT COUNT(*) FROM shpenzimet")->fetchColumn() . "\n";
echo "shitje_produkteve: " . $db->query("SELECT COUNT(*) FROM shitje_produkteve")->fetchColumn() . "\n";
echo "plini_depo: " . $db->query("SELECT COUNT(*) FROM plini_depo")->fetchColumn() . "\n";

echo "\n=== DONE ===\n";
