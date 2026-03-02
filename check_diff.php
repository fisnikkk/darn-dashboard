<?php
header('Content-Type: text/plain');
require_once __DIR__.'/config/database.php';
$db = getDB();

// Check which of these IDs exist (all €7 cash rows from local DB)
$ids = [10200,10329,10352,10473,10605,11108,11277,11561,11783,11817,12261,12299,12311,12361,12397,12412,12420,12435,12482,12495,12518,12545,12622,12642];
$placeholders = implode(',', $ids);
$r = $db->query("SELECT id FROM shpenzimet WHERE id IN ({$placeholders})");
$found = [];
while ($row = $r->fetch()) $found[] = (int)$row['id'];
$missing = array_diff($ids, $found);
echo "MISSING_IDS:" . implode(',', $missing) . "\n";

// Also check total row count and sum
$t = $db->query("SELECT COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash'")->fetch();
echo "CASH_TOTAL:" . $t['t'] . "_ROWS:" . $t['c'] . "\n";

$t2 = $db->query("SELECT COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim'")->fetch();
echo "TJERA_TOTAL:" . $t2['t'] . "_ROWS:" . $t2['c'] . "\n";

// For the €1 diff in tjera: compare by year
$r = $db->query("SELECT IFNULL(YEAR(data_e_pageses),'NULL') as yr, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' GROUP BY YEAR(data_e_pageses) ORDER BY yr");
while ($row = $r->fetch()) {
    echo "TJERA_YR:" . $row['yr'] . "_C:" . $row['c'] . "_T:" . $row['t'] . "\n";
}
