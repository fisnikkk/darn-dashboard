<?php
/**
 * DARN Dashboard - Pasqyra (Overview)
 * Mirrors the summary rows 1-4 from Distribuimi sheet exactly
 * All formulas match Excel cell-by-cell (verified Feb 2026)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/layout.php';

$db = getDB();

// ============================================================
// Summary aggregations matching Excel Distribuimi rows 1-4
// ============================================================

// Row A3: Total Blerje (Total gas purchases from Plini Depo)
$totalBlerje = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara), 0) FROM plini_depo")->fetchColumn();

// Row B3: Totali i shitjeve (Total sales from Distribuimi)
$totalShitje = $db->query("SELECT COALESCE(SUM(pagesa), 0) FROM distribuimi")->fetchColumn();

// Row C3: Fitimi Bruto (Gross profit = sales - purchases)
$fitimibruto = $totalShitje - $totalBlerje;

// Row D3: Blerje me fature
$blerjeFature = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara), 0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'")->fetchColumn();

// Row E3: Blerje pa fature
$blerjePaFature = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara), 0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'pa fature'")->fetchColumn();

// Payment breakdown from Distribuimi (G3, H3, I3, J3, K3 — explicit SUMIF categories)
$payments = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'bank' THEN pagesa ELSE 0 END), 0) as bank,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) banke' THEN pagesa ELSE 0 END), 0) as fature_banke,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'no payment' THEN pagesa ELSE 0 END), 0) as no_payment
    FROM distribuimi
")->fetch();

// Excel O3: Dhurate = RESIDUAL (total sales minus all categorized payment types)
// Excel formula: =B3-G3-H3-I3-J3-K3  (NOT a SUMIF on "dhurate" rows!)
// This captures the gap between total sales and explicitly categorized payments
$dhurateResidual = $totalShitje - $payments['cash'] - $payments['fature_cash']
    - $payments['fature_banke'] - $payments['bank'] - $payments['no_payment'];

// Expenses breakdown
$shpenzimPlin = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit)) = 'pagesa per plin'")->fetchColumn();
$shpenzimTjera = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit)) = 'shpenzim'")->fetchColumn();

// Excel U3: ALL cash expenses (including bank deposits) — used in Pare cash formula
$shpenzimCashAll = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'")->fetchColumn();

// Cash expenses from 2022-08-28 onwards (for Pare cash formula matching Excel)
$shpenzimCashFrom = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash' AND data_e_pageses >= '2022-08-28'")->fetchColumn();

// Payments from 2022-08-28 onwards (for Pare cash formula)
$paymentsFrom = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash
    FROM distribuimi
    WHERE data >= '2022-08-28'
")->fetch();
// Cash expenses excluding bank deposits — for reference display only
$shpenzimCashPaDeponime = $db->query("
    SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet
    WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'
    AND LOWER(TRIM(lloji_i_transaksionit)) NOT IN ('deponim i pazarit', 'deponim nga banka ne arke per pagese te plinit ne famgas')
")->fetchColumn();

// Bank balance = SUM of all debits + credits (matches Excel M3: =SUM(F)+SUM(G))
$bankBalance = $db->query("SELECT COALESCE(SUM(debia), 0) + COALESCE(SUM(kredi), 0) FROM gjendja_bankare")->fetchColumn() ?: 0;

// Product sales cash
$produkteCash = $db->query("SELECT COALESCE(SUM(totali), 0) FROM shitje_produkteve WHERE LOWER(TRIM(menyra_pageses)) = 'cash'")->fetchColumn();

// Excel L3: Pare cash vetem nga plini = G3 + H3 - U3 (ALL-TIME, no date filter)
$pareCashPlin = $payments['cash'] + $payments['fature_cash'] - $shpenzimCashAll;

// Excel P3: Total pare cash nga plin dhe produkte
$totalPareCash = $pareCashPlin + $produkteCash;

// Excel Q3: Total pare cash + banke = P3 + M3
$totalPareCashBanke = $totalPareCash + $bankBalance;

// Excel R3: Fitimi Neto = C3 - T3 - O3
// C3=Fitimi Bruto, T3=Shpenzime tjera, O3=Dhurate (residual)
$fitimiNeto = $fitimibruto - $shpenzimTjera - $dhurateResidual;

// Excel F3: Sasia gazit me fature (EUR) = D3 - SUM(H3:J3)
// Invoiced purchases minus invoiced/bank sales = remaining invoice capacity
$gasitMeFatureEur = $blerjeFature - ($payments['fature_cash'] + $payments['fature_banke'] + $payments['bank']);

// Litra calculations — matching Excel Litrat row 1 formulas
// B1: =SUMIF('Plini depo'!H,"Me fature",'Plini depo'!D) — sasia_ne_litra
$litraBlera = $db->query("SELECT COALESCE(SUM(sasia_ne_litra), 0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'")->fetchColumn();
// D1: =SUMIF(L,"Po (Fature te rregullte) cash",Z)+SUMIF(L,"bank",Z)+SUMIF(L,"Po (Fature te rregullte) banke",Z)
$litraFaturuara = $db->query("
    SELECT COALESCE(SUM(litrat_e_konvertuara), 0) FROM distribuimi
    WHERE LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke')
")->fetchColumn();
// F1: =SUM(X:X) — litrat_total (column X), NOT litrat_e_konvertuara (column Z)
$litraShitura = $db->query("SELECT COALESCE(SUM(litrat_total), 0) FROM distribuimi")->fetchColumn();

// Boca stats
$bocaTerren = $db->query("SELECT COALESCE(SUM(sasia) - SUM(boca_te_kthyera), 0) FROM distribuimi")->fetchColumn();
$totalKliente = $db->query("SELECT COUNT(DISTINCT LOWER(klienti)) FROM distribuimi")->fetchColumn();

// Deponime ne banke — Excel starts at row 42 (date >= 2021-10-23), skipping 2 early DEPONIM rows (€695)
$deponime = $db->query("SELECT COALESCE(SUM(kredi), 0) FROM gjendja_bankare WHERE UPPER(shpjegim) LIKE '%DEPONIM%' AND data >= '2021-10-23'")->fetchColumn();

// Sasia e blere me fature ne kg (from Plini Depo)
$blereMeFatureKg = $db->query("SELECT COALESCE(SUM(kg), 0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses)) = 'me fature'")->fetchColumn();

// Shpenzimet me fature = SUMIF(fatura_e_rregullte = "Fature e rregullte", shuma)
$shpenzimetMeFature = $db->query("SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet WHERE LOWER(TRIM(fatura_e_rregullte)) = 'fature e rregullte'")->fetchColumn();

// Pagesat me fature cash per boca dhe gaz = produkteCash + fature_cash + 281.9
$pagesatMeFatureCash = $produkteCash + $payments['fature_cash'] + 281.9;

// Mund te deponohen = pagesatMeFatureCash - deponime - shpenzimetMeFature
$mundTeDeponohen = $pagesatMeFatureCash - $deponime - $shpenzimetMeFature;

// Babi Cash (Excel Distribuimi L4, M4, N4)
// L4: Cash + invoice-cash from 2022-08-29 minus cash expenses from Excel row 217 (id>=10319) + manual adj
$babiPayments = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash
    FROM distribuimi
    WHERE data >= '2022-08-29'
")->fetch();
// Excel SUMIF starts at Shpenzimet row 217 (date >= 2022-08-29)
// Include NULL/empty dates too — they belong to the range but have missing date values
$babiExpenses = $db->query("
    SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet
    WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'
    AND (data_e_pageses >= '2022-08-29' OR data_e_pageses IS NULL OR data_e_pageses = '0000-00-00')
")->fetchColumn();
$babiManual = 281.9; // Manual adjustments from Excel: 66.4 + 16.6 + 34.7 + 164.2
$babiGasCash = $babiPayments['cash'] + $babiPayments['fature_cash'] - $babiExpenses + $babiManual;

// M4: Product sales cash from 2022-09-07
$babiProductCash = $db->query("
    SELECT COALESCE(SUM(totali), 0) FROM shitje_produkteve
    WHERE LOWER(TRIM(menyra_pageses)) = 'cash' AND data >= '2022-09-07'
")->fetchColumn();

// N4: Total Babi cash = L4 + M4
$babiTotal = $babiGasCash + $babiProductCash;

ob_start();
?>

<!-- Row 2-3 Summary: Exactly matching Excel labels -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total Blerje</div>
        <div class="value">&euro; <?= eur($totalBlerje) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Totali i shitjeve</div>
        <div class="value">&euro; <?= eur($totalShitje) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Fitimi Bruto</div>
        <div class="value <?= $fitimibruto >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($fitimibruto) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Boca total ne terren</div>
        <div class="value"><?= num($bocaTerren) ?></div>
    </div>
</div>

<!-- Pagesat sipas llojit (Row I1 label, Row 2-3 data) -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-coins"></i> Pagesat sipas llojit</h3>
    </div>
    <div class="card-body">
        <table class="data-table summary-table">
            <thead>
                <tr>
                    <th>Cash (Pagesa pa fature)</th>
                    <th>Po (Fature te rregullte) cash</th>
                    <th>Po (Fature te rregullte) banke</th>
                    <th>Bank (ende e papaguar)</th>
                    <th>Te papaguara</th>
                    <th>Dhurate (residual)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="amount" data-label="Cash (Pagesa pa fature)">&euro; <?= eur($payments['cash']) ?></td>
                    <td class="amount" data-label="Fature te rregullte cash">&euro; <?= eur($payments['fature_cash']) ?></td>
                    <td class="amount" data-label="Fature te rregullte banke">&euro; <?= eur($payments['fature_banke']) ?></td>
                    <td class="amount" data-label="Bank (ende e papaguar)">&euro; <?= eur($payments['bank']) ?></td>
                    <td class="amount" data-label="Te papaguara">&euro; <?= eur($payments['no_payment']) ?></td>
                    <td class="amount" data-label="Dhurate (residual)">&euro; <?= eur($dhurateResidual) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Financial summary -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Blerje me fature</div>
        <div class="value">&euro; <?= eur($blerjeFature) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Blerje pa fature</div>
        <div class="value">&euro; <?= eur($blerjePaFature) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Pare cash nga plini</div>
        <div class="value <?= $pareCashPlin >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($pareCashPlin) ?></div>
        <small style="color:var(--text-muted);">Cash + Faturë cash - Shpenzimet cash</small>
    </div>
    <div class="summary-card">
        <div class="label">Pare ne banke</div>
        <div class="value">&euro; <?= eur($bankBalance) ?></div>
    </div>
</div>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Cash nga shitjet e produkteve</div>
        <div class="value">&euro; <?= eur($produkteCash) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total pare cash (plin + produkte)</div>
        <div class="value">&euro; <?= eur($totalPareCash) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Pare cash Labi dhe ne banke</div>
        <div class="value">&euro; <?= eur($totalPareCashBanke) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Shpenzimet per blerje plini</div>
        <div class="value">&euro; <?= eur($shpenzimPlin) ?></div>
    </div>
</div>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Shpenzime tjera</div>
        <div class="value">&euro; <?= eur($shpenzimTjera) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Shpenzimet Cash</div>
        <div class="value">&euro; <?= eur($shpenzimCashAll) ?></div>
        <small style="color:var(--text-muted);">Pa deponime: &euro; <?= eur($shpenzimCashPaDeponime) ?></small>
    </div>
    <div class="summary-card">
        <div class="label">Sasia gazit me fature (EUR)</div>
        <div class="value <?= $gasitMeFatureEur >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($gasitMeFatureEur) ?></div>
        <small style="color:var(--text-muted);">Blerje me fature - Shitje me fature/banke</small>
    </div>
</div>

<!-- Litra Row 1 — uses litrat_e_konvertuara (Excel Column Z) -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-tint"></i> Litrat</h3>
    </div>
    <div class="card-body">
        <table class="data-table summary-table">
            <thead>
                <tr>
                    <th>Total litra te blera (me fature)</th>
                    <th>Total litra te faturuara te klienti</th>
                    <th>Total litra te shitura</th>
                    <th>Litra te blera qe mund te faturohen tek klienti</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="amount" data-label="Litra te blera (me fature)"><?= eur($litraBlera) ?> L</td>
                    <td class="amount" data-label="Litra te faturuara te klienti"><?= eur($litraFaturuara) ?> L</td>
                    <td class="amount" data-label="Litra te shitura"><?= eur($litraShitura) ?> L</td>
                    <td class="amount" data-label="Litra qe mund te faturohen"><?= eur($litraBlera - $litraFaturuara) ?> L</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Deponime ne banke</div>
        <div class="value">&euro; <?= eur($deponime) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total Klient&euml;</div>
        <div class="value"><?= num($totalKliente) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Fitimi Neto</div>
        <div class="value <?= $fitimiNeto >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($fitimiNeto) ?></div>
        <small style="color:var(--text-muted);">Fitimi bruto - Shpenzime tjera - Dhurate</small>
    </div>
</div>

<!-- Fature & Deponime summary -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Sasia e blere me fature ne kg</div>
        <div class="value"><?= eur($blereMeFatureKg) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Pagesat me fature cash per boca dhe gaz</div>
        <div class="value">&euro; <?= eur($pagesatMeFatureCash) ?></div>
        <small style="color:var(--text-muted);">Produkte cash + Faturë cash + 281.9</small>
    </div>
    <div class="summary-card">
        <div class="label">Mund te deponohen keto para ne banke</div>
        <div class="value <?= $mundTeDeponohen >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($mundTeDeponohen) ?></div>
        <small style="color:var(--text-muted);">Shitjet me fature cash - Deponimet - Shpenzimet me fature</small>
    </div>
</div>

<!-- Babi Cash (Excel L4, M4, N4) -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Babi Cash (Gaz)</div>
        <div class="value <?= $babiGasCash >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($babiGasCash) ?></div>
        <small style="color:var(--text-muted);">Cash + Faturë cash (nga 29.08.2022) - Shpenzime cash</small>
    </div>
    <div class="summary-card">
        <div class="label">Babi Cash (Produkte)</div>
        <div class="value">&euro; <?= eur($babiProductCash) ?></div>
        <small style="color:var(--text-muted);">Shitje produkteve cash (nga 07.09.2022)</small>
    </div>
    <div class="summary-card">
        <div class="label">Babi Cash (Total)</div>
        <div class="value <?= $babiTotal >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($babiTotal) ?></div>
    </div>
</div>

<?php
$content = ob_get_clean();
renderLayout('Pasqyra e Përgjithshme', 'overview', $content);
