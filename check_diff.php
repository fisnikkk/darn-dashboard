<?php
header('Content-Type: text/plain');
require_once __DIR__.'/config/database.php';
$db = getDB();

// Cash by year on production
$r = $db->query("SELECT IFNULL(YEAR(data_e_pageses),'NULL') as yr, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' GROUP BY YEAR(data_e_pageses) ORDER BY yr");
while ($row = $r->fetch()) {
    echo "CASH_YR:" . $row['yr'] . "_C:" . $row['c'] . "_T:" . $row['t'] . "\n";
}

// Cash Feb 2026 detailed - every row
echo "---FEB2026_CASH---\n";
$r = $db->query("SELECT id, data_e_pageses, ROUND(shuma,2) as s FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' AND YEAR(data_e_pageses)=2026 AND MONTH(data_e_pageses)=2 ORDER BY id");
while ($row = $r->fetch()) {
    echo "R:" . $row['id'] . "_" . $row['data_e_pageses'] . "_" . $row['s'] . "\n";
}
