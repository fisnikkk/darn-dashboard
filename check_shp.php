<?php
require __DIR__.'/config/db.php';

$total = $db->query("SELECT SUM(shuma) as t, COUNT(*) as c FROM shpenzimet WHERE lloji_i_pageses='cash'")->fetch_assoc();
echo "DB cash shpenzimet: {$total['t']} ({$total['c']} rows)\n\n";

echo "Rows with date AFTER 2026-02-09:\n";
$r = $db->query("SELECT id, data, shuma, lloji_i_pageses, pershkrimi FROM shpenzimet WHERE lloji_i_pageses='cash' AND data > '2026-02-09' ORDER BY data");
$sum = 0;
while ($row = $r->fetch_assoc()) {
    $sum += $row['shuma'];
    echo "  ID={$row['id']} date={$row['data']} amount={$row['shuma']} desc={$row['pershkrimi']}\n";
}
echo "Total after Feb 9: {$sum}\n";
