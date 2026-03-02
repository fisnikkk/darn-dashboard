<?php
require_once __DIR__.'/config/database.php';
$db = getDB();

// Shpenzimet Cash (U3) - grouped by year to find where the €7 diff is
echo "=== SHPENZIMET CASH BY YEAR ===\n";
$r = $db->query("SELECT YEAR(data_e_pageses) as yr, COUNT(*) as c, SUM(shuma) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' GROUP BY YEAR(data_e_pageses) ORDER BY yr");
$grandTotal = 0; $grandCount = 0;
while ($row = $r->fetch()) {
    echo "  {$row['yr']}: {$row['c']} rows, total={$row['t']}\n";
    $grandTotal += $row['t']; $grandCount += $row['c'];
}
echo "  GRAND TOTAL: {$grandCount} rows, total={$grandTotal}\n\n";

// Shpenzime tjera (T3) - grouped by year
echo "=== SHPENZIME TJERA BY YEAR ===\n";
$r = $db->query("SELECT YEAR(data_e_pageses) as yr, COUNT(*) as c, SUM(shuma) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' GROUP BY YEAR(data_e_pageses) ORDER BY yr");
$grandTotal = 0; $grandCount = 0;
while ($row = $r->fetch()) {
    echo "  {$row['yr']}: {$row['c']} rows, total={$row['t']}\n";
    $grandTotal += $row['t']; $grandCount += $row['c'];
}
echo "  GRAND TOTAL: {$grandCount} rows, total={$grandTotal}\n\n";

// Check for whitespace issues in lloji_i_pageses
echo "=== CASH VARIANTS (exact values) ===\n";
$r = $db->query("SELECT lloji_i_pageses, LENGTH(lloji_i_pageses) as len, COUNT(*) as c, SUM(shuma) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' GROUP BY lloji_i_pageses, LENGTH(lloji_i_pageses)");
while ($row = $r->fetch()) {
    echo "  '{$row['lloji_i_pageses']}' (len={$row['len']}): {$row['c']} rows, total={$row['t']}\n";
}

echo "\n=== SHPENZIM VARIANTS (exact values) ===\n";
$r = $db->query("SELECT lloji_i_transaksionit, LENGTH(lloji_i_transaksionit) as len, COUNT(*) as c, SUM(shuma) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' GROUP BY lloji_i_transaksionit, LENGTH(lloji_i_transaksionit)");
while ($row = $r->fetch()) {
    echo "  '{$row['lloji_i_transaksionit']}' (len={$row['len']}): {$row['c']} rows, total={$row['t']}\n";
}

// Check for rows where shuma has decimal issues
echo "\n=== SMALL AMOUNT ROWS (shuma <= 10, cash) ===\n";
$r = $db->query("SELECT id, data_e_pageses, shuma, lloji_i_transaksionit, pershkrim_i_detajuar FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' AND shuma <= 10 ORDER BY shuma");
while ($row = $r->fetch()) {
    echo "  ID={$row['id']} date={$row['data_e_pageses']} amount={$row['shuma']} type={$row['lloji_i_transaksionit']} desc={$row['pershkrim_i_detajuar']}\n";
}
