<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$client = $_GET['c'] ?? 'Capvin 13 Ramiz Sadiku';

echo "=== KARTELA VERIFICATION: {$client} ===\n\n";

// 1. DEBI: all deliveries except DHURATE, pagesa > 0
$debi = $db->prepare("
    SELECT data, sasia, litra, cmimi, pagesa, menyra_e_pageses
    FROM distribuimi
    WHERE LOWER(klienti) = LOWER(?)
      AND LOWER(TRIM(COALESCE(menyra_e_pageses,''))) != 'dhurate'
      AND pagesa > 0
    ORDER BY data ASC
");
$debi->execute([$client]);
$debiRows = $debi->fetchAll();

echo "--- DEBI (distribuimi deliveries) ---\n";
$totalDebi = 0;
foreach ($debiRows as $r) {
    $totalDebi += (float)$r['pagesa'];
    echo sprintf("  %s | %s boca x %sL x %s€ = €%.2f | %s\n",
        $r['data'], $r['sasia'], $r['litra'], $r['cmimi'], $r['pagesa'], $r['menyra_e_pageses']);
}
echo "TOTAL DEBI: €" . number_format($totalDebi, 2) . " (" . count($debiRows) . " rows)\n\n";

// 2. Auto KREDI (cash + po cash)
$krediCash = $db->prepare("
    SELECT data, sasia, pagesa, menyra_e_pageses
    FROM distribuimi
    WHERE LOWER(klienti) = LOWER(?)
      AND LOWER(TRIM(COALESCE(menyra_e_pageses,''))) IN ('cash', 'po (fature te rregullte) cash')
      AND pagesa > 0
    ORDER BY data ASC
");
$krediCash->execute([$client]);
$cashRows = $krediCash->fetchAll();

echo "--- KREDI AUTO (cash payments) ---\n";
$totalCashKredi = 0;
foreach ($cashRows as $r) {
    $totalCashKredi += (float)$r['pagesa'];
    echo sprintf("  %s | %s boca = €%.2f | %s\n", $r['data'], $r['sasia'], $r['pagesa'], $r['menyra_e_pageses']);
}
echo "TOTAL CASH KREDI: €" . number_format($totalCashKredi, 2) . " (" . count($cashRows) . " rows)\n\n";

// 3. Bank KREDI from gjendja_bankare
$krediBank = $db->prepare("
    SELECT data, kredi, shpjegim
    FROM gjendja_bankare
    WHERE LOWER(klienti) = LOWER(?)
      AND kredi > 0
    ORDER BY data ASC
");
$krediBank->execute([$client]);
$bankRows = $krediBank->fetchAll();

echo "--- KREDI BANK (gjendja_bankare) ---\n";
$totalBankKredi = 0;
foreach ($bankRows as $r) {
    $totalBankKredi += (float)$r['kredi'];
    echo sprintf("  %s | €%.2f | %s\n", $r['data'], $r['kredi'], $r['shpjegim']);
}
echo "TOTAL BANK KREDI: €" . number_format($totalBankKredi, 2) . " (" . count($bankRows) . " rows)\n\n";

// Summary
$totalKredi = $totalCashKredi + $totalBankKredi;
$borxh = $totalDebi - $totalKredi;
echo "=== SUMMARY ===\n";
echo "Total DEBI:  €" . number_format($totalDebi, 2) . "\n";
echo "Total KREDI: €" . number_format($totalKredi, 2) . " (cash: €" . number_format($totalCashKredi, 2) . " + bank: €" . number_format($totalBankKredi, 2) . ")\n";
echo "BORXHI:      €" . number_format($borxh, 2) . "\n";
echo "\nExpected:    €9,605.65\n";
echo "Match:       " . (abs($borxh - 9605.65) < 0.01 ? "YES ✓" : "NO ✗ (diff: €" . number_format($borxh - 9605.65, 2) . ")") . "\n";

// Also check: all payment methods for this client
echo "\n--- PAYMENT METHOD BREAKDOWN ---\n";
$methods = $db->prepare("
    SELECT COALESCE(menyra_e_pageses, '(NULL)') as method, COUNT(*) as cnt, SUM(pagesa) as total
    FROM distribuimi
    WHERE LOWER(klienti) = LOWER(?)
    GROUP BY menyra_e_pageses
    ORDER BY total DESC
");
$methods->execute([$client]);
foreach ($methods->fetchAll() as $m) {
    echo sprintf("  %-40s %d rows, €%.2f\n", $m['method'], $m['cnt'], $m['total']);
}
