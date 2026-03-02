<?php
require_once __DIR__.'/config/database.php';
$db = getDB();

// Compare cash shpenzimet by year - to find where the missing €7 row is
echo "=== SHPENZIMET CASH BY YEAR (to compare with production) ===\n";
$r = $db->query("SELECT YEAR(data_e_pageses) as yr, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' GROUP BY YEAR(data_e_pageses) ORDER BY yr");
while ($row = $r->fetch()) {
    echo "  {$row['yr']}: {$row['c']} rows, total={$row['t']}\n";
}

echo "\n=== SHPENZIME TJERA BY YEAR ===\n";
$r = $db->query("SELECT YEAR(data_e_pageses) as yr, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' GROUP BY YEAR(data_e_pageses) ORDER BY yr");
while ($row = $r->fetch()) {
    echo "  {$row['yr']}: {$row['c']} rows, total={$row['t']}\n";
}

// Also show cash by month for 2026 (most likely where diffs are)
echo "\n=== SHPENZIMET CASH 2026 BY MONTH ===\n";
$r = $db->query("SELECT MONTH(data_e_pageses) as mo, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' AND YEAR(data_e_pageses)=2026 GROUP BY MONTH(data_e_pageses) ORDER BY mo");
while ($row = $r->fetch()) {
    echo "  month {$row['mo']}: {$row['c']} rows, total={$row['t']}\n";
}

echo "\n=== SHPENZIME TJERA 2026 BY MONTH ===\n";
$r = $db->query("SELECT MONTH(data_e_pageses) as mo, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' AND YEAR(data_e_pageses)=2026 GROUP BY MONTH(data_e_pageses) ORDER BY mo");
while ($row = $r->fetch()) {
    echo "  month {$row['mo']}: {$row['c']} rows, total={$row['t']}\n";
}

// Show all rows where shuma = 7 (could be the missing row)
echo "\n=== ROWS WHERE SHUMA=7 (cash) ===\n";
$r = $db->query("SELECT id, data_e_pageses, shuma, lloji_i_transaksionit, pershkrim_i_detajuar FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' AND shuma=7");
while ($row = $r->fetch()) {
    echo "  ID={$row['id']} date={$row['data_e_pageses']} amount={$row['shuma']} type={$row['lloji_i_transaksionit']} desc={$row['pershkrim_i_detajuar']}\n";
}

// Show all rows where shuma contains 7 in cents (like X.07, X.70 etc)
echo "\n=== ROWS WHERE SHUMA ENDS IN .00 AND IS EXACTLY 7 ===\n";
$r = $db->query("SELECT id, data_e_pageses, shuma, lloji_i_transaksionit FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' AND (shuma=7 OR shuma=3.50 OR shuma=7.00)");
while ($row = $r->fetch()) {
    echo "  ID={$row['id']} date={$row['data_e_pageses']} amount={$row['shuma']} type={$row['lloji_i_transaksionit']}\n";
}
