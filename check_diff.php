<?php
header('Content-Type: text/plain');
require_once __DIR__.'/config/database.php';
$db = getDB();

// Shpenzime tjera 2026 - every row to compare with local
echo "---TJERA_2026---\n";
$r = $db->query("SELECT id, data_e_pageses, ROUND(shuma,2) as s, pershkrim_i_detajuar as d FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' AND YEAR(data_e_pageses)=2026 ORDER BY id");
while ($row = $r->fetch()) {
    echo "R:" . $row['id'] . "_" . $row['data_e_pageses'] . "_" . $row['s'] . "_" . substr($row['d'],0,30) . "\n";
}
