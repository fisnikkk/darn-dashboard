<?php
header('Content-Type: application/json');
require_once __DIR__.'/config/database.php';
$db = getDB();
$out = [];

$r = $db->query("SELECT IFNULL(YEAR(data_e_pageses),'NULL') as yr, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' GROUP BY YEAR(data_e_pageses) ORDER BY yr");
while ($row = $r->fetch()) $out['cash_by_year'][] = $row;

$r = $db->query("SELECT IFNULL(YEAR(data_e_pageses),'NULL') as yr, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' GROUP BY YEAR(data_e_pageses) ORDER BY yr");
while ($row = $r->fetch()) $out['tjera_by_year'][] = $row;

$r = $db->query("SELECT MONTH(data_e_pageses) as mo, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' AND YEAR(data_e_pageses)=2026 GROUP BY MONTH(data_e_pageses)");
while ($row = $r->fetch()) $out['cash_2026_by_month'][] = $row;

$r = $db->query("SELECT MONTH(data_e_pageses) as mo, COUNT(*) as c, ROUND(SUM(shuma),2) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' AND YEAR(data_e_pageses)=2026 GROUP BY MONTH(data_e_pageses)");
while ($row = $r->fetch()) $out['tjera_2026_by_month'][] = $row;

echo json_encode($out);
