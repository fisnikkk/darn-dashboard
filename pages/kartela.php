<?php
/**
 * DARN Dashboard - Kartela e Klientit (Client Ledger)
 * Per-client financial card: DEBI (deliveries) vs KREDI (payments)
 * - DEBI: from distribuimi (except DHURATE)
 * - KREDI auto-mirror: CASH / PO CASH payments (paid on delivery)
 * - KREDI bank: from gjendja_bankare where klienti is assigned
 * - NO PAYMENT: debi only = creates debt
 * - DHURATE: excluded entirely
 * Result: Total Debi - Total Kredi = Borxh (debt) or Avancë (advance)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Selected client (detail view)
$selectedClient = $_GET['klient'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Get all distinct clients from distribuimi
$allClients = $db->query("SELECT DISTINCT MIN(klienti) as k FROM distribuimi WHERE klienti IS NOT NULL AND TRIM(klienti) != '' GROUP BY LOWER(klienti) ORDER BY k")->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// DETAIL VIEW — specific client selected
// ============================================================
if ($selectedClient !== '') {

    // Build date filter
    $dateWhere = '';
    $dateParams = [];
    if ($dateFrom) { $dateWhere .= ' AND data >= ?'; $dateParams[] = $dateFrom; }
    if ($dateTo)   { $dateWhere .= ' AND data <= ?'; $dateParams[] = $dateTo; }

    // 1. DEBI: all deliveries from distribuimi (except DHURATE), skip zero-value rows
    $debiSQL = "
        SELECT d.data, 'debi' as lloji,
               CONCAT(d.sasia, ' boca × ', d.litra, 'L × ', d.cmimi, '€ — ', COALESCE(d.menyra_e_pageses,'')) as pershkrim,
               d.pagesa as debi, 0 as kredi, d.id as ref_id, 'distribuimi' as src,
               0 as e_kontrolluar
        FROM distribuimi d
        WHERE LOWER(d.klienti) = LOWER(?)
          AND LOWER(TRIM(COALESCE(d.menyra_e_pageses,''))) != 'dhurate'
          AND d.pagesa > 0
          {$dateWhere}
    ";

    // 2. Auto KREDI for cash payments (CASH + PO CASH), skip zero-value
    $krediCashSQL = "
        SELECT d.data, 'kredi' as lloji,
               CONCAT('Pagesa cash — ', d.sasia, ' boca') as pershkrim,
               0 as debi, d.pagesa as kredi, d.id as ref_id, 'auto_cash' as src,
               0 as e_kontrolluar
        FROM distribuimi d
        WHERE LOWER(d.klienti) = LOWER(?)
          AND LOWER(TRIM(COALESCE(d.menyra_e_pageses,''))) IN ('cash', 'po (fature te rregullte) cash')
          AND d.pagesa > 0
          {$dateWhere}
    ";

    // 3. Bank KREDI from gjendja_bankare (includes e_kontrolluar for verified indicator)
    $krediBankSQL = "
        SELECT g.data, 'kredi' as lloji,
               CONCAT('Pagesa bankare — ', COALESCE(g.shpjegim,'')) as pershkrim,
               0 as debi, g.kredi as kredi, g.id as ref_id, 'banka' as src,
               COALESCE(g.e_kontrolluar, 0) as e_kontrolluar
        FROM gjendja_bankare g
        WHERE LOWER(g.klienti) = LOWER(?)
          AND g.kredi > 0
          {$dateWhere}
    ";

    // Combine all three with UNION ALL
    $combinedSQL = "({$debiSQL}) UNION ALL ({$krediCashSQL}) UNION ALL ({$krediBankSQL}) ORDER BY data ASC, lloji ASC, ref_id ASC";

    $params = array_merge(
        [$selectedClient], $dateParams,
        [$selectedClient], $dateParams,
        [$selectedClient], $dateParams
    );

    $stmt = $db->prepare($combinedSQL);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    // Fetch client business info from kontrata
    $clientInfoStmt = $db->prepare("SELECT nr_i_kontrates, biznesi, numri_unik, perfaqesuesi,
        nr_telefonit, email, qyteti, rruga, lloji_i_bocave
        FROM kontrata WHERE LOWER(TRIM(biznesi)) = LOWER(?) OR LOWER(TRIM(name_from_database)) = LOWER(?) LIMIT 1");
    $clientInfoStmt->execute([$selectedClient, $selectedClient]);
    $clientInfo = $clientInfoStmt->fetch();

    // Calculate running balance
    $totalDebi = 0;
    $totalKredi = 0;
    $runningBalance = 0;
    foreach ($transactions as &$t) {
        $d = (float)$t['debi'];
        $k = (float)$t['kredi'];
        $totalDebi += $d;
        $totalKredi += $k;
        $runningBalance += ($d - $k);
        $t['gjendja'] = $runningBalance;
    }
    unset($t);

    $gjendja = $totalDebi - $totalKredi;

    ob_start();
    ?>

    <div style="margin-bottom:16px;" class="print-hide">
        <a href="?<?= http_build_query(array_diff_key($_GET, ['klient'=>''])) ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Te gjithe klientet
        </a>
    </div>

    <style>
    .kartela-detail th.num, .kartela-detail td.amount { text-align: right; padding-right: 16px; }
    </style>

    <!-- Summary cards -->
    <div class="summary-grid">
        <div class="summary-card"><div class="label">Klienti</div><div class="value" style="font-size:1rem;"><?= e($selectedClient) ?></div></div>
        <div class="summary-card"><div class="label">Total Debi</div><div class="value" style="color:var(--danger);">&euro; <?= eur($totalDebi) ?></div></div>
        <div class="summary-card"><div class="label">Total Kredi</div><div class="value" style="color:var(--success);">&euro; <?= eur($totalKredi) ?></div></div>
        <div class="summary-card" style="<?= $gjendja > 0.01 ? 'border-left:4px solid var(--danger);' : ($gjendja < -0.01 ? 'border-left:4px solid var(--success);' : '') ?>">
            <div class="label"><?= $gjendja > 0.01 ? 'Borxhi' : ($gjendja < -0.01 ? 'Avanca' : 'Gjendja') ?></div>
            <div class="value" style="color:<?= $gjendja > 0.01 ? 'var(--danger)' : ($gjendja < -0.01 ? 'var(--success)' : 'inherit') ?>;">&euro; <?= eur(abs($gjendja)) ?></div>
        </div>
    </div>

    <!-- Print-only professional header (hidden on screen, visible in PDF) -->
    <div class="print-only kartela-print-header">
        <div class="print-header-top">
            <h2 style="margin:0;font-size:1.3rem;">KARTELA E KLIENTIT</h2>
            <?php
                // Determine actual date range from transactions
                $firstDate = !empty($transactions) ? $transactions[0]['data'] : null;
                $lastDate = !empty($transactions) ? $transactions[count($transactions) - 1]['data'] : null;
            ?>
            <div style="font-size:0.82rem;color:#555;margin-top:4px;">
                <?php if ($firstDate && $lastDate): ?>
                    Periudha: <?= date('d/m/Y', strtotime($firstDate)) ?> — <?= date('d/m/Y', strtotime($lastDate)) ?>
                <?php else: ?>
                    Të gjitha transaksionet
                <?php endif; ?>
            </div>
        </div>
        <div class="print-info-grid">
            <div class="print-info-item">
                <span class="print-info-label">Biznesi</span>
                <span class="print-info-value"><?= e($clientInfo ? ($clientInfo['biznesi'] ?: $selectedClient) : $selectedClient) ?></span>
            </div>
            <?php if (!empty($clientInfo['numri_unik'])): ?>
            <div class="print-info-item">
                <span class="print-info-label">Numri Unik (NIPT)</span>
                <span class="print-info-value"><?= e($clientInfo['numri_unik']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($clientInfo['nr_i_kontrates'])): ?>
            <div class="print-info-item">
                <span class="print-info-label">Nr. Kontratës</span>
                <span class="print-info-value"><?= e($clientInfo['nr_i_kontrates']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($clientInfo['perfaqesuesi'])): ?>
            <div class="print-info-item">
                <span class="print-info-label">Përfaqësuesi</span>
                <span class="print-info-value"><?= e($clientInfo['perfaqesuesi']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($clientInfo['nr_telefonit'])): ?>
            <div class="print-info-item">
                <span class="print-info-label">Nr. Telefonit</span>
                <span class="print-info-value"><?= e($clientInfo['nr_telefonit']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($clientInfo['email'])): ?>
            <div class="print-info-item">
                <span class="print-info-label">Email</span>
                <span class="print-info-value"><?= e($clientInfo['email']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($clientInfo['qyteti']) || !empty($clientInfo['rruga'])): ?>
            <div class="print-info-item">
                <span class="print-info-label">Adresa</span>
                <span class="print-info-value"><?= e(trim(($clientInfo['rruga'] ?: '') . ', ' . ($clientInfo['qyteti'] ?: ''), ', ')) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($clientInfo['lloji_i_bocave'])): ?>
            <div class="print-info-item">
                <span class="print-info-label">Lloji i Bocave</span>
                <span class="print-info-value"><?= e($clientInfo['lloji_i_bocave']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="print-summary-bar">
            <div><strong>Total Debi:</strong> &euro; <?= eur($totalDebi) ?></div>
            <div><strong>Total Kredi:</strong> &euro; <?= eur($totalKredi) ?></div>
            <div><strong><?= $gjendja > 0.01 ? 'Borxhi' : ($gjendja < -0.01 ? 'Avanca' : 'Gjendja') ?>:</strong> &euro; <?= eur(abs($gjendja)) ?></div>
        </div>
    </div>

    <!-- Date filter -->
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header">
            <h3><i class="fas fa-id-card"></i> Kartela — <?= e($selectedClient) ?></h3>
            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="klient" value="<?= e($selectedClient) ?>">
                <label style="font-size:0.82rem;font-weight:600;">Nga:</label>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>" style="padding:5px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;">
                <label style="font-size:0.82rem;font-weight:600;">Deri:</label>
                <input type="date" name="date_to" value="<?= e($dateTo) ?>" style="padding:5px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;">
                <button type="submit" class="btn btn-primary btn-sm">Filtro</button>
                <?php if ($dateFrom || $dateTo): ?>
                <a href="?klient=<?= urlencode($selectedClient) ?>" class="btn btn-outline btn-sm">Pastro</a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="window.print()" style="margin-left:auto;"><i class="fas fa-file-pdf"></i> Shkarko PDF</button>
            </form>
        </div>
        <div class="card-body">
            <div class="table-wrapper">
                <table class="data-table kartela-detail">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th class="print-hide">Përshkrimi</th>
                            <th class="num" style="color:var(--danger);">Debi (&euro;)</th>
                            <th class="num" style="color:var(--success);">Kredi (&euro;)</th>
                            <th class="num" style="font-weight:700;">Gjendja (&euro;)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                        <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">
                            <i class="fas fa-info-circle"></i> Nuk ka transaksione për këtë klient.
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                        <tr style="<?= $t['lloji'] === 'kredi' ? 'background:#f0fdf4;' : '' ?>">
                            <td style="white-space:nowrap;"><?= $t['data'] ?></td>
                            <td class="truncate print-hide" title="<?= e($t['pershkrim']) ?>">
                                <?php if ($t['lloji'] === 'debi'): ?>
                                    <i class="fas fa-truck" style="color:var(--danger);margin-right:4px;"></i>
                                <?php elseif ($t['src'] === 'auto_cash'): ?>
                                    <i class="fas fa-coins" style="color:var(--success);margin-right:4px;"></i>
                                <?php else: ?>
                                    <i class="fas fa-university" style="color:var(--success);margin-right:4px;"></i>
                                    <?php if (!empty($t['e_kontrolluar'])): ?>
                                        <i class="fas fa-check-circle" style="color:#2E7D32;margin-right:4px;font-size:0.8em;" title="E kontrolluar"></i>
                                    <?php else: ?>
                                        <i class="fas fa-exclamation-circle" style="color:#FFA000;margin-right:4px;font-size:0.8em;" title="E pa kontrolluar"></i>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?= e($t['pershkrim']) ?>
                            </td>
                            <td class="amount" style="color:var(--danger);font-weight:<?= (float)$t['debi'] > 0 ? '600' : '400' ?>;">
                                <?= (float)$t['debi'] > 0 ? '&euro; ' . eur($t['debi']) : '' ?>
                            </td>
                            <td class="amount" style="color:var(--success);font-weight:<?= (float)$t['kredi'] > 0 ? '600' : '400' ?>;">
                                <?= (float)$t['kredi'] > 0 ? '&euro; ' . eur($t['kredi']) : '' ?>
                            </td>
                            <td class="amount" style="font-weight:700;color:<?= $t['gjendja'] > 0.01 ? 'var(--danger)' : ($t['gjendja'] < -0.01 ? 'var(--success)' : 'inherit') ?>;">
                                &euro; <?= eur(abs($t['gjendja'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($transactions)): ?>
                    <tfoot>
                        <tr style="font-weight:700;background:#f8fafc;">
                            <td>TOTALI</td>
                            <td class="print-hide" style="font-weight:400;"><?= count($transactions) ?> transaksione</td>
                            <td class="amount" style="color:var(--danger);">&euro; <?= eur($totalDebi) ?></td>
                            <td class="amount" style="color:var(--success);">&euro; <?= eur($totalKredi) ?></td>
                            <td class="amount" style="color:<?= $gjendja > 0.01 ? 'var(--danger)' : ($gjendja < -0.01 ? 'var(--success)' : 'inherit') ?>;">
                                &euro; <?= eur(abs($gjendja)) ?>
                                <div style="font-weight:400;font-size:0.72rem;opacity:0.7;"><?= $gjendja > 0.01 ? 'borxh' : ($gjendja < -0.01 ? 'avancë' : '&nbsp;') ?></div>
                            </td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <p class="print-hide" style="color:var(--text-muted);font-size:0.82rem;margin-top:8px;">
        <i class="fas fa-info-circle"></i> <strong>Debi</strong> = dërgesa nga Distribuimi.
        <strong>Kredi</strong> = pagesa cash (automatike) ose bankare (nga Gjendja Bankare).
        <br>Pagesat DHURATE janë përjashtuar. Statusi NO PAYMENT paraqitet si borxh.
    </p>

    <?php
    $content = ob_get_clean();
    renderLayout('Kartela — ' . $selectedClient, 'kartela', $content);
    exit;
}

// ============================================================
// SUMMARY VIEW — all clients
// ============================================================

// Date filter for summary view
$dateDeri = $_GET['date'] ?? '';

// Multi-select filter for client name
$fKartKlienti = getFilterParam('f_klienti');

$kartWhere = [];
$kartParams = [];

// Date filter applied at SQL level
$dateFilterSQL = '';
$dateFilterParams = [];
if ($dateDeri) {
    $dateFilterSQL = 'AND d.data <= ?';
    $dateFilterParams[] = $dateDeri;
}

if ($fKartKlienti) {
    $placeholders = implode(',', array_fill(0, count($fKartKlienti), '?'));
    $kartHaving = "HAVING LOWER(MIN(d.klienti)) IN ({$placeholders})";
    $kartParams = array_map('strtolower', array_values($fKartKlienti));
} else {
    $kartHaving = '';
}

// Summary per client from distribuimi
$summarySQL = "
    SELECT
        MIN(d.klienti) AS klienti,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(d.menyra_e_pageses,''))) != 'dhurate' THEN d.pagesa ELSE 0 END) AS total_debi,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(d.menyra_e_pageses,''))) IN ('cash', 'po (fature te rregullte) cash') THEN d.pagesa ELSE 0 END) AS kredi_cash
    FROM distribuimi d
    WHERE 1=1 {$dateFilterSQL}
    GROUP BY LOWER(d.klienti)
    {$kartHaving}
    ORDER BY MIN(d.klienti)
";
$stmt = $db->prepare($summarySQL);
$stmt->execute(array_merge($dateFilterParams, $kartParams));
$clientSummaries = $stmt->fetchAll();

// Bank KREDI per client from gjendja_bankare
$bankKredi = [];
$bankSQL = "SELECT LOWER(klienti) as kl, SUM(kredi) as total_kredi FROM gjendja_bankare WHERE klienti IS NOT NULL AND klienti != '' AND kredi > 0" . ($dateDeri ? " AND data <= ?" : "") . " GROUP BY LOWER(klienti)";
$bankStmt = $db->prepare($bankSQL);
$bankStmt->execute($dateDeri ? [$dateDeri] : []);
$bankRows = $bankStmt->fetchAll();
foreach ($bankRows as $br) {
    $bankKredi[$br['kl']] = (float)$br['total_kredi'];
}

// Combine and calculate gjendja
$totals = ['debi' => 0, 'kredi_cash' => 0, 'kredi_bank' => 0, 'gjendja' => 0];
foreach ($clientSummaries as &$cs) {
    $cs['kredi_bank'] = $bankKredi[strtolower($cs['klienti'])] ?? 0;
    $cs['total_kredi'] = (float)$cs['kredi_cash'] + (float)$cs['kredi_bank'];
    $cs['gjendja'] = (float)$cs['total_debi'] - $cs['total_kredi'];
    $totals['debi'] += (float)$cs['total_debi'];
    $totals['kredi_cash'] += (float)$cs['kredi_cash'];
    $totals['kredi_bank'] += (float)$cs['kredi_bank'];
    $totals['gjendja'] += $cs['gjendja'];
}
unset($cs);

// Count clients with debt/advance
$debtCount = count(array_filter($clientSummaries, fn($c) => $c['gjendja'] > 0.01));
$advanceCount = count(array_filter($clientSummaries, fn($c) => $c['gjendja'] < -0.01));
$balancedCount = count($clientSummaries) - $debtCount - $advanceCount;

ob_start();
?>

<style>
.clickable-row { cursor: pointer; transition: background 0.15s; }
.clickable-row:hover { filter: brightness(0.96); }
#kartelaTable th.num, #kartelaTable td.amount { text-align: right; padding-right: 16px; }
#kartelaTable th.num { text-align: right; padding-right: 16px; }
</style>

<div class="summary-grid">
    <div class="summary-card"><div class="label">Klientë</div><div class="value"><?= count($clientSummaries) ?></div></div>
    <div class="summary-card"><div class="label">Me borxh</div><div class="value" style="color:var(--danger);"><?= $debtCount ?></div></div>
    <div class="summary-card"><div class="label">Me avancë</div><div class="value" style="color:var(--success);"><?= $advanceCount ?></div></div>
    <div class="summary-card"><div class="label">Te barazuar</div><div class="value"><?= $balancedCount ?></div></div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-id-card"></i> Kartela e Klientëve (<?= count($clientSummaries) ?>)</h3>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <label style="font-size:0.82rem;font-weight:600;">Deri datën:</label>
            <input type="date" name="date" value="<?= e($dateDeri) ?>" style="padding:5px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;">
            <button type="submit" class="btn btn-primary btn-sm">Apliko</button>
            <?php if ($dateDeri): ?>
            <a href="?" class="btn btn-outline btn-sm">Pastro</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" id="kartelaTable">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="kartelaSortColumn(this, 0)"
                            data-filter="f_klienti" data-filter-values="<?= e(json_encode($allClients, JSON_UNESCAPED_UNICODE)) ?>">
                            Klienti <i class="fas fa-sort"></i>
                        </th>
                        <th class="num server-sort" onclick="kartelaSortColumn(this, 1)" style="color:var(--danger);">
                            Total Debi (&euro;) <i class="fas fa-sort"></i>
                        </th>
                        <th class="num server-sort" onclick="kartelaSortColumn(this, 2)" style="color:var(--success);">
                            Kredi Cash (&euro;) <i class="fas fa-sort"></i>
                        </th>
                        <th class="num server-sort" onclick="kartelaSortColumn(this, 3)" style="color:var(--success);">
                            Kredi Bank (&euro;) <i class="fas fa-sort"></i>
                        </th>
                        <th class="num server-sort" onclick="kartelaSortColumn(this, 4)" style="font-weight:700;">
                            Gjendja (&euro;) <i class="fas fa-sort"></i>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientSummaries as $cs): ?>
                    <tr style="<?= $cs['gjendja'] > 0.01 ? 'background:#fef2f2;' : ($cs['gjendja'] < -0.01 ? 'background:#f0fdf4;' : '') ?>"
                        onclick="window.open('?klient=<?= urlencode($cs['klienti']) ?>', '_blank')"
                        class="clickable-row">
                        <td><a href="?klient=<?= urlencode($cs['klienti']) ?>" target="_blank" style="color:inherit;text-decoration:none;font-weight:500;"><?= e($cs['klienti']) ?></a></td>
                        <td class="amount" style="color:var(--danger);"><?= eur($cs['total_debi']) ?></td>
                        <td class="amount" style="color:var(--success);"><?= eur($cs['kredi_cash']) ?></td>
                        <td class="amount" style="color:var(--success);"><?= eur($cs['kredi_bank']) ?></td>
                        <td class="amount" style="font-weight:700;color:<?= $cs['gjendja'] > 0.01 ? 'var(--danger)' : ($cs['gjendja'] < -0.01 ? 'var(--success)' : 'inherit') ?>;">
                            <?= eur(abs($cs['gjendja'])) ?><?php if ($cs['gjendja'] > 0.01): ?> <span style="font-weight:400;font-size:0.72rem;opacity:0.65;">borxh</span><?php elseif ($cs['gjendja'] < -0.01): ?> <span style="font-weight:400;font-size:0.72rem;opacity:0.65;">avancë</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;background:#f8fafc;">
                        <td>TOTALI <?php if ($dateDeri): ?><span style="font-weight:400;font-size:0.78rem;opacity:0.7;">deri <?= date('d/m/Y', strtotime($dateDeri)) ?></span><?php endif; ?></td>
                        <td class="amount" style="color:var(--danger);"><?= eur($totals['debi']) ?></td>
                        <td class="amount" style="color:var(--success);"><?= eur($totals['kredi_cash']) ?></td>
                        <td class="amount" style="color:var(--success);"><?= eur($totals['kredi_bank']) ?></td>
                        <td class="amount" style="color:<?= $totals['gjendja'] > 0.01 ? 'var(--danger)' : ($totals['gjendja'] < -0.01 ? 'var(--success)' : 'inherit') ?>;">
                            <?= eur(abs($totals['gjendja'])) ?> <span style="font-weight:400;font-size:0.72rem;opacity:0.65;"><?= $totals['gjendja'] > 0.01 ? 'borxh' : ($totals['gjendja'] < -0.01 ? 'avancë' : '') ?></span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<p style="color:var(--text-muted);font-size:0.82rem;margin-top:8px;">
    <i class="fas fa-info-circle"></i> Kliko mbi emrin e klientit për të parë kartelën e detajuar.
    <br><strong>Debi</strong> = dërgesa nga Distribuimi. <strong>Kredi Cash</strong> = pagesa cash (automatike).
    <strong>Kredi Bank</strong> = pagesa bankare (nga Gjendja Bankare ku është caktuar klienti).
    <br>Pagesat DHURATE janë përjashtuar. Gjendja pozitive = borxh, negative = avancë.
</p>

<script>
function kartelaSortColumn(th, colIdx) {
    const table = document.getElementById('kartelaTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const icon = th.querySelector('i');
    const asc = icon.classList.contains('fa-sort-down') || icon.classList.contains('fa-sort');
    // Reset all sort icons
    th.closest('tr').querySelectorAll('th.server-sort > i.fas').forEach(i => { i.className = 'fas fa-sort'; });
    icon.className = 'fas ' + (asc ? 'fa-sort-up' : 'fa-sort-down');
    rows.sort((a, b) => {
        const ta = a.cells[colIdx]?.textContent?.trim() || '';
        const tb = b.cells[colIdx]?.textContent?.trim() || '';
        const na = parseFloat(ta.replace(/[^0-9.\-]/g, ''));
        const nb = parseFloat(tb.replace(/[^0-9.\-]/g, ''));
        if (!isNaN(na) && !isNaN(nb)) return asc ? na - nb : nb - na;
        return asc ? ta.localeCompare(tb) : tb.localeCompare(ta);
    });
    rows.forEach(r => tbody.appendChild(r));
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Kartela', 'kartela', $content);
