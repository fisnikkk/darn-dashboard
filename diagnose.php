<?php
/**
 * DARN Dashboard - Full Diagnostic: Compare Pasqyra values + show ALL changes
 * Run this on production to find what changed.
 */
header('Content-Type: text/plain; charset=utf-8');
require 'config/database.php';
$db = getDB();

echo "============================================================\n";
echo "DARN DIAGNOSTIC REPORT - " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n\n";

// =====================================================
// SECTION 1: ALL CHANGELOG ENTRIES (most recent first)
// =====================================================
echo "=== ALL CHANGELOG ENTRIES ===\n";
try {
    $cols = $db->query("DESCRIBE changelog")->fetchAll();
    $tsCol = null;
    foreach ($cols as $c) {
        if (strpos($c['Type'], 'timestamp') !== false || strpos($c['Type'], 'datetime') !== false) {
            $tsCol = $c['Field'];
            break;
        }
    }
    $orderBy = $tsCol ? "ORDER BY {$tsCol} DESC" : "ORDER BY id DESC";
    $allChanges = $db->query("SELECT * FROM changelog {$orderBy}")->fetchAll();
    echo "Total changelog entries: " . count($allChanges) . "\n\n";

    foreach ($allChanges as $r) {
        $line = '';
        foreach ($r as $k => $v) {
            if (!is_numeric($k)) { // skip numeric indexes
                $display = ($v !== null) ? substr($v, 0, 60) : 'NULL';
                $line .= $k . ': ' . $display . ' | ';
            }
        }
        echo $line . "\n";
    }
} catch (Exception $e) {
    echo "Changelog error: " . $e->getMessage() . "\n";
}

// =====================================================
// SECTION 2: ALL PASQYRA VALUES (matching index.php)
// =====================================================
echo "\n\n=== CURRENT PASQYRA VALUES ===\n";

// Total Blerje
$totalBlerje = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara), 0) FROM plini_depo")->fetchColumn();
echo "Total Blerje: EUR " . number_format($totalBlerje, 2) . "\n";

// Totali i shitjeve
$totalShitje = $db->query("SELECT COALESCE(SUM(pagesa), 0) FROM distribuimi")->fetchColumn();
echo "Totali i shitjeve: EUR " . number_format($totalShitje, 2) . "\n";

// Fitimi Bruto
$fitimibruto = $totalShitje - $totalBlerje;
echo "Fitimi Bruto: EUR " . number_format($fitimibruto, 2) . "\n";

// Blerje me fature
$blerjeFature = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara), 0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'")->fetchColumn();
echo "Blerje me fature: EUR " . number_format($blerjeFature, 2) . "\n";

// Blerje pa fature
$blerjePaFature = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara), 0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'pa fature'")->fetchColumn();
echo "Blerje pa fature: EUR " . number_format($blerjePaFature, 2) . "\n";

// Payment breakdown
$payments = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'bank' THEN pagesa ELSE 0 END), 0) as bank,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) banke' THEN pagesa ELSE 0 END), 0) as fature_banke,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'no payment' THEN pagesa ELSE 0 END), 0) as no_payment
    FROM distribuimi
")->fetch();
echo "Cash (Pagesa pa fature): EUR " . number_format($payments['cash'], 2) . "\n";
echo "Po (Fature te rregullte) cash: EUR " . number_format($payments['fature_cash'], 2) . "\n";
echo "Po (Fature te rregullte) banke: EUR " . number_format($payments['fature_banke'], 2) . "\n";
echo "Bank (ende e papaguar): EUR " . number_format($payments['bank'], 2) . "\n";
echo "Te papaguara (no payment): EUR " . number_format($payments['no_payment'], 2) . "\n";

// Dhurate residual
$dhurateResidual = $totalShitje - $payments['cash'] - $payments['fature_cash']
    - $payments['fature_banke'] - $payments['bank'] - $payments['no_payment'];
echo "Dhurate (residual): EUR " . number_format($dhurateResidual, 2) . "\n";

// Expenses
$shpenzimPlin = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit)) = 'pagesa per plin'")->fetchColumn();
echo "Shpenzimet per blerje plini: EUR " . number_format($shpenzimPlin, 2) . "\n";

$shpenzimTjera = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit)) = 'shpenzim'")->fetchColumn();
echo "Shpenzime tjera: EUR " . number_format($shpenzimTjera, 2) . "\n";

$shpenzimCashAll = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'")->fetchColumn();
echo "Shpenzimet Cash (all): EUR " . number_format($shpenzimCashAll, 2) . "\n";

$shpenzimCashFrom = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash' AND data_e_pageses >= '2022-08-28'")->fetchColumn();
echo "Shpenzimet Cash (from 2022-08-28): EUR " . number_format($shpenzimCashFrom, 2) . "\n";

$shpenzimCashPaDeponime = $db->query("
    SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet
    WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'
    AND LOWER(TRIM(lloji_i_transaksionit)) NOT IN ('deponim i pazarit', 'deponim nga banka ne arke per pagese te plinit ne famgas')
")->fetchColumn();
echo "Shpenzimet Cash (pa deponime): EUR " . number_format($shpenzimCashPaDeponime, 2) . "\n";

// Payments from 2022-08-28
$paymentsFrom = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash
    FROM distribuimi
    WHERE data >= '2022-08-28'
")->fetch();
echo "Distribuimi Cash from 2022-08-28: EUR " . number_format($paymentsFrom['cash'], 2) . "\n";
echo "Distribuimi Fature cash from 2022-08-28: EUR " . number_format($paymentsFrom['fature_cash'], 2) . "\n";

// Pare cash nga plini
$pareCashPlin = $paymentsFrom['cash'] + $paymentsFrom['fature_cash'] - $shpenzimCashFrom + 281.9;
echo "Pare cash nga plini: EUR " . number_format($pareCashPlin, 2) . "\n";

// Bank balance
$bankBalance = $db->query("SELECT COALESCE(bilanci, 0) FROM gjendja_bankare ORDER BY data DESC, id DESC LIMIT 1")->fetchColumn() ?: 0;
echo "Pare ne banke: EUR " . number_format($bankBalance, 2) . "\n";

// Product sales cash
$produkteCash = $db->query("SELECT COALESCE(SUM(totali), 0) FROM shitje_produkteve WHERE LOWER(TRIM(menyra_pageses)) = 'cash'")->fetchColumn();
echo "Cash nga shitjet e produkteve: EUR " . number_format($produkteCash, 2) . "\n";

// Total pare cash
$totalPareCash = $pareCashPlin + $produkteCash;
echo "Total pare cash (plin + produkte): EUR " . number_format($totalPareCash, 2) . "\n";

// Total pare cash + banke
$totalPareCashBanke = $totalPareCash + $bankBalance;
echo "Total pare cash + banke: EUR " . number_format($totalPareCashBanke, 2) . "\n";

// Fitimi Neto
$fitimiNeto = $fitimibruto - $shpenzimTjera - $dhurateResidual;
echo "Fitimi Neto: EUR " . number_format($fitimiNeto, 2) . "\n";

// Sasia gazit me fature
$gasitMeFatureEur = $blerjeFature - ($payments['fature_cash'] + $payments['fature_banke'] + $payments['bank']);
echo "Sasia gazit me fature (EUR): EUR " . number_format($gasitMeFatureEur, 2) . "\n";

// Litra
$litraBlera = $db->query("SELECT COALESCE(SUM(sasia_ne_litra), 0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'")->fetchColumn();
echo "Total litra te blera (me fature): " . number_format($litraBlera, 2) . " L\n";

$litraFaturuara = $db->query("
    SELECT COALESCE(SUM(litrat_e_konvertuara), 0) FROM distribuimi
    WHERE LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke')
")->fetchColumn();
echo "Total litra te faturuara te klienti: " . number_format($litraFaturuara, 2) . " L\n";

$litraShitura = $db->query("SELECT COALESCE(SUM(litrat_e_konvertuara), 0) FROM distribuimi")->fetchColumn();
echo "Total litra te shitura: " . number_format($litraShitura, 2) . " L\n";

echo "Litra qe mund te faturohen: " . number_format($litraBlera - $litraFaturuara, 2) . " L\n";

// Boca
$bocaTerren = $db->query("SELECT COALESCE(SUM(sasia) - SUM(boca_te_kthyera), 0) FROM distribuimi")->fetchColumn();
echo "Boca total ne terren: " . number_format($bocaTerren, 0) . "\n";

$totalKliente = $db->query("SELECT COUNT(DISTINCT LOWER(klienti)) FROM distribuimi")->fetchColumn();
echo "Total Kliente: " . $totalKliente . "\n";

// Deponime
$deponime = $db->query("SELECT COALESCE(SUM(kredi), 0) FROM gjendja_bankare WHERE UPPER(shpjegim) LIKE '%DEPONIM%'")->fetchColumn();
echo "Deponime ne banke: EUR " . number_format($deponime, 2) . "\n";

// =====================================================
// SECTION 3: CHANGES THAT AFFECT PASQYRA VALUES
// =====================================================
echo "\n\n=== CHANGELOG ENTRIES AFFECTING PASQYRA CALCULATIONS ===\n";
echo "(Changes to: distribuimi.pagesa, distribuimi.menyra_e_pageses, distribuimi.sasia, distribuimi.litra, distribuimi.cmimi,\n";
echo " plini_depo.faturat_e_pranuara, plini_depo.menyra_e_pageses, shpenzimet.shuma, shpenzimet.lloji_*,\n";
echo " gjendja_bankare.bilanci/kredi/debia, shitje_produkteve.totali/menyra_pageses)\n\n";

try {
    $impactFields = $db->query("
        SELECT * FROM changelog
        WHERE (table_name = 'distribuimi' AND field_name IN ('pagesa','menyra_e_pageses','sasia','litra','cmimi','klienti','data','litrat_e_konvertuara','litrat_total','boca_te_kthyera'))
           OR (table_name = 'plini_depo' AND field_name IN ('faturat_e_pranuara','menyra_e_pageses','kg','sasia_ne_litra','cmimi'))
           OR (table_name = 'shpenzimet' AND field_name IN ('shuma','lloji_i_pageses','lloji_i_transaksionit','data_e_pageses'))
           OR (table_name = 'gjendja_bankare' AND field_name IN ('bilanci','kredi','debia'))
           OR (table_name = 'shitje_produkteve' AND field_name IN ('totali','menyra_pageses','cmimi','cilindra_sasia'))
           OR action_type IN ('insert', 'delete')
        ORDER BY id DESC
    ")->fetchAll();

    echo "Found " . count($impactFields) . " relevant entries:\n\n";
    foreach ($impactFields as $r) {
        $line = '';
        foreach ($r as $k => $v) {
            if (!is_numeric($k)) {
                $display = ($v !== null) ? substr($v, 0, 80) : 'NULL';
                $line .= $k . ': ' . $display . ' | ';
            }
        }
        echo $line . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =====================================================
// SECTION 4: CHECK FOR UNCATEGORIZED PAYMENT METHODS
// =====================================================
echo "\n\n=== DISTRIBUIMI PAYMENT METHOD BREAKDOWN (all distinct values) ===\n";
$methods = $db->query("
    SELECT LOWER(TRIM(menyra_e_pageses)) as method,
           COUNT(*) as cnt,
           SUM(pagesa) as total_pagesa
    FROM distribuimi
    GROUP BY LOWER(TRIM(menyra_e_pageses))
    ORDER BY total_pagesa DESC
")->fetchAll();
foreach ($methods as $m) {
    echo "  '" . $m['method'] . "' => " . $m['cnt'] . " rows, EUR " . number_format($m['total_pagesa'], 2) . "\n";
}
$sumAll = array_sum(array_column($methods, 'total_pagesa'));
echo "  SUM of all: EUR " . number_format($sumAll, 2) . "\n";

// =====================================================
// SECTION 5: PLINI DEPO PAYMENT METHOD BREAKDOWN
// =====================================================
echo "\n=== PLINI DEPO PAYMENT METHOD BREAKDOWN ===\n";
$methods2 = $db->query("
    SELECT LOWER(TRIM(menyra_e_pageses)) as method,
           COUNT(*) as cnt,
           SUM(faturat_e_pranuara) as total
    FROM plini_depo
    GROUP BY LOWER(TRIM(menyra_e_pageses))
    ORDER BY total DESC
")->fetchAll();
foreach ($methods2 as $m) {
    echo "  '" . $m['method'] . "' => " . $m['cnt'] . " rows, EUR " . number_format($m['total'], 2) . "\n";
}

// =====================================================
// SECTION 6: SHPENZIMET BREAKDOWN
// =====================================================
echo "\n=== SHPENZIMET LLOJI I TRANSAKSIONIT BREAKDOWN ===\n";
$types = $db->query("
    SELECT LOWER(TRIM(lloji_i_transaksionit)) as lloji,
           COUNT(*) as cnt,
           SUM(shuma) as total
    FROM shpenzimet
    GROUP BY LOWER(TRIM(lloji_i_transaksionit))
    ORDER BY total DESC
")->fetchAll();
foreach ($types as $t) {
    echo "  '" . $t['lloji'] . "' => " . $t['cnt'] . " rows, EUR " . number_format($t['total'], 2) . "\n";
}

echo "\n=== SHPENZIMET LLOJI I PAGESES BREAKDOWN ===\n";
$types2 = $db->query("
    SELECT LOWER(TRIM(lloji_i_pageses)) as lloji,
           COUNT(*) as cnt,
           SUM(shuma) as total
    FROM shpenzimet
    GROUP BY LOWER(TRIM(lloji_i_pageses))
    ORDER BY total DESC
")->fetchAll();
foreach ($types2 as $t) {
    echo "  '" . $t['lloji'] . "' => " . $t['cnt'] . " rows, EUR " . number_format($t['total'], 2) . "\n";
}

// =====================================================
// SECTION 7: ROW COUNTS
// =====================================================
echo "\n=== ROW COUNTS ===\n";
echo "Distribuimi: " . $db->query("SELECT COUNT(*) FROM distribuimi")->fetchColumn() . "\n";
echo "Plini depo: " . $db->query("SELECT COUNT(*) FROM plini_depo")->fetchColumn() . "\n";
echo "Shpenzimet: " . $db->query("SELECT COUNT(*) FROM shpenzimet")->fetchColumn() . "\n";
echo "Shitje produkteve: " . $db->query("SELECT COUNT(*) FROM shitje_produkteve")->fetchColumn() . "\n";
echo "Gjendja bankare: " . $db->query("SELECT COUNT(*) FROM gjendja_bankare")->fetchColumn() . "\n";
echo "Kontrata: " . $db->query("SELECT COUNT(*) FROM kontrata")->fetchColumn() . "\n";

echo "\n=== DONE ===\n";
