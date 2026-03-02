<?php
require_once __DIR__.'/config/database.php';
$db = getDB();

$total = $db->query("SELECT SUM(shuma) as t, COUNT(*) as c FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash'")->fetch();
echo "DB cash shpenzimet: {$total['t']} ({$total['c']} rows)\n\n";

echo "Rows with date AFTER 2026-02-09:\n";
$r = $db->query("SELECT id, data_e_pageses as data, shuma, lloji_i_pageses, pershkrimi FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' AND data_e_pageses > '2026-02-09' ORDER BY data_e_pageses");
$sum = 0;
while ($row = $r->fetch()) {
    $sum += $row['shuma'];
    echo "  ID={$row['id']} date={$row['data']} amount={$row['shuma']} desc={$row['pershkrimi']}\n";
}
echo "Total after Feb 9: {$sum}\n\n";

echo "Case breakdown:\n";
$r2 = $db->query("SELECT lloji_i_pageses, COUNT(*) as c, SUM(shuma) as t FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' GROUP BY lloji_i_pageses");
while ($row = $r2->fetch()) {
    echo "  '{$row['lloji_i_pageses']}': {$row['c']} rows, total={$row['t']}\n";
}
