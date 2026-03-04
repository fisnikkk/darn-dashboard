<?php
/**
 * DARN Dashboard - Monthly Profit
 * P&L by month: sales, gas supply payments, expenses (with 21 subcategories), net/gross profit
 * Mirrors the Monthly profit Excel sheet formulas exactly:
 *   Col C = SUMIFS(Distribuimi!K:K, ...) → Sales from distribuimi (stored pagesa)
 *   Col D = SUMIFS(Shpenzimet!D:D, ..., "Pagesa per plin") → Supply payments from shpenzimet
 *   Col E = SUMIFS(Shpenzimet!D:D, ..., "Shpenzim") → Other expenses from shpenzimet
 *   Col F = C-D-E → Monthly profit (net)
 *   Cols H-AB = Individual expense subcategories (21 categories from arsyetimi)
 *   Col AC = C-D → Gross profit
 *   Col AD = Net profit EXCLUDING investments
 *   Col AE = AD/C → Profit % (excludes investments)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// The 21 expense subcategories matching Excel columns H-AB
$expenseCategories = [
    'nafte_kombit'       => ['label' => 'Nafte kombit',       'keys' => ['nafte kombit', 'nafte e kombit', 'alba petrol', 'dea gas', 'veda oil']],
    'nafte_mercedes'     => ['label' => 'Nafte Mercedes',     'keys' => ['nafte mercedes', 'nafte kombit mercedes']],
    'paga'               => ['label' => 'Paga',               'keys' => ['paga']],
    'taksa'              => ['label' => 'Taksa',              'keys' => ['taksa', 'regjistrim per ta barazuar']],
    'investim_firme'     => ['label' => 'Investim ne firme',  'keys' => ['investim ne firme', 'investime ne firme', 'deponim i pazarit']],
    'qera_pumpa'         => ['label' => 'Qera te pumpa',      'keys' => ['qera te pumpa', 'qera e pumpes']],
    'internet'           => ['label' => 'Internet',           'keys' => ['internet']],
    'autolarje'          => ['label' => 'Autolarje',          'keys' => ['autolarje']],
    'pagese_instalime'   => ['label' => 'Pagese per instalime','keys' => ['pagese per instalime']],
    'investim_kombi'     => ['label' => 'Investime ne kombi', 'keys' => ['investime ne kombi']],
    'investim_kombi_ri'  => ['label' => 'Investim kombi te ri','keys' => ['investime ne kombi te ri', 'investim ne kombi te ri']],
    'material_shpenzues' => ['label' => 'Material shpenzues', 'keys' => ['material shpenzues', 'blerja e kapakve', 'blerja e keseve', 'kthim plini', 'blerja e gasit(2620x.1.11= 2908.2  date 11/03/2024)(4700 lit),6 € peshorja  -verda petroll']],
    'telefon'            => ['label' => 'Telefon mbushje',    'keys' => ['telefon mbushje', 'telefon']],
    'shpenzime_ditore'   => ['label' => 'Shpenzime ditore',   'keys' => ['shpenzime ditore', 'navigacion', 'parking', 'barazim', 'borxh i klientit', 'borxh labi', 'borxh lena']],
    'rryma'              => ['label' => 'Rryma',              'keys' => ['rryma']],
    'shpenzime_puntore'  => ['label' => 'Shpenzime per puntore','keys' => ['shpenzime per puntore']],
    'vaga'               => ['label' => 'Vaga',               'keys' => ['vaga']],
    'pastrim_kombit'     => ['label' => 'Pastrim i kombit',   'keys' => ['pastrim i kombit', 'pastrim kombit', 'larja e kombit']],
    'marketing'          => ['label' => 'Marketing',          'keys' => ['marketing']],
    'besa_security'      => ['label' => 'Besa Security',      'keys' => ['besa security']],
    'investim_kombi_3'   => ['label' => 'Investime ne kombi 3','keys' => ['investime ne kombi 3']],
];

// Investment categories (excluded from Profit %)
// Note: 'pagese per instalime' is NOT an investment — Excel formula AD4 subtracts it
// like any other expense (column P is included in the SUM that gets subtracted)
$investmentKeys = [
    'investim ne firme', 'investime ne firme', 'deponim i pazarit',
    'investime ne kombi', 'investime ne kombi te ri', 'investim ne kombi te ri',
    'investime ne kombi 3'
];

// Build the CASE WHEN SQL for all subcategories
$caseClauses = [];
foreach ($expenseCategories as $id => $cat) {
    $conditions = [];
    foreach ($cat['keys'] as $key) {
        $conditions[] = "LOWER(TRIM(arsyetimi)) = " . $db->quote(strtolower($key));
    }
    $cond = implode(' OR ', $conditions);
    $caseClauses[] = "COALESCE(SUM(CASE WHEN LOWER(TRIM(lloji_i_transaksionit)) = 'shpenzim' AND ({$cond}) THEN shuma ELSE 0 END), 0) as `{$id}`";
}
$caseSQL = implode(",\n        ", $caseClauses);

// Build investment CASE
$invConditions = [];
foreach ($investmentKeys as $key) {
    $invConditions[] = "LOWER(TRIM(arsyetimi)) = " . $db->quote(strtolower($key));
}
$invCond = implode(' OR ', $invConditions);

// Monthly sales from distribuimi (Excel Col C: pagesa from Excel)
$salesByMonth = [];
foreach ($db->query("SELECT DATE_FORMAT(data, '%Y-%m') as m, SUM(pagesa) as total FROM distribuimi WHERE data IS NOT NULL GROUP BY DATE_FORMAT(data, '%Y-%m')")->fetchAll() as $r) {
    $salesByMonth[$r['m']] = (float)$r['total'];
}

// Monthly expenses breakdown from shpenzimet — main aggregates + all 21 subcategories in one query
$expensesByMonth = [];
foreach ($db->query("
    SELECT DATE_FORMAT(data_e_pageses, '%Y-%m') as m,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(lloji_i_transaksionit)) = 'pagesa per plin' THEN shuma ELSE 0 END), 0) as blerje,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(lloji_i_transaksionit)) = 'shpenzim' THEN shuma ELSE 0 END), 0) as shpenzime,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(lloji_i_transaksionit)) = 'shpenzim'
            AND ({$invCond}) THEN shuma ELSE 0 END), 0) as investime,
        {$caseSQL}
    FROM shpenzimet WHERE data_e_pageses IS NOT NULL
    GROUP BY DATE_FORMAT(data_e_pageses, '%Y-%m')
")->fetchAll() as $r) {
    $expensesByMonth[$r['m']] = $r;
}

// Collect all months
$allMonths = array_unique(array_merge(array_keys($salesByMonth), array_keys($expensesByMonth)));
rsort($allMonths);

$data = [];
foreach ($allMonths as $m) {
    if (!$m) continue;
    $shitje = $salesByMonth[$m] ?? 0;
    $blerje = (float)($expensesByMonth[$m]['blerje'] ?? 0);
    $shpenzime = (float)($expensesByMonth[$m]['shpenzime'] ?? 0);
    $investime = (float)($expensesByMonth[$m]['investime'] ?? 0);
    $fitimibruto = $shitje - $blerje;
    $fitimineto = $shitje - $blerje - $shpenzime;
    $fitiminetoExclInv = $fitimineto + $investime;
    $profitPct = $shitje > 0 ? ($fitiminetoExclInv / $shitje) * 100 : 0;

    $row = [
        'muaji' => $m,
        'shitje' => $shitje,
        'blerje' => $blerje,
        'shpenzime' => $shpenzime,
        'investime' => $investime,
        'fitimi_bruto' => $fitimibruto,
        'fitimi_neto' => $fitimineto,
        'fitimi_neto_excl_inv' => $fitiminetoExclInv,
        'profit_pct' => $profitPct,
    ];
    // Add all 21 subcategory values
    foreach ($expenseCategories as $id => $cat) {
        $row[$id] = (float)($expensesByMonth[$m][$id] ?? 0);
    }
    $data[] = $row;
}

// Calculate running balance (Excel Col G: cumulative profit, oldest first)
$sorted = $data;
usort($sorted, fn($a, $b) => strcmp($a['muaji'], $b['muaji']));
$runningBalance = 0;
$balanceMap = [];
foreach ($sorted as $row) {
    $runningBalance += $row['fitimi_neto'];
    $balanceMap[$row['muaji']] = $runningBalance;
}
foreach ($data as &$d) {
    $d['bilanci'] = $balanceMap[$d['muaji']] ?? 0;
}
unset($d);

// Totals
$totShitje = array_sum(array_column($data, 'shitje'));
$totBlerje = array_sum(array_column($data, 'blerje'));
$totShpenzime = array_sum(array_column($data, 'shpenzime'));
$totInvestime = array_sum(array_column($data, 'investime'));
$totBruto = array_sum(array_column($data, 'fitimi_bruto'));
$totNeto = array_sum(array_column($data, 'fitimi_neto'));
$totNetoExclInv = array_sum(array_column($data, 'fitimi_neto_excl_inv'));
$totPct = $totShitje > 0 ? ($totNetoExclInv / $totShitje) * 100 : 0;
$catTotals = [];
foreach ($expenseCategories as $id => $cat) {
    $catTotals[$id] = array_sum(array_column($data, $id));
}

$monthNames = ['01'=>'Janar','02'=>'Shkurt','03'=>'Mars','04'=>'Prill','05'=>'Maj','06'=>'Qershor',
               '07'=>'Korrik','08'=>'Gusht','09'=>'Shtator','10'=>'Tetor','11'=>'Nëntor','12'=>'Dhjetor'];

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card"><div class="label">Total Shitje</div><div class="value">&euro; <?= eur($totShitje) ?></div></div>
    <div class="summary-card"><div class="label">Total Furnizimi</div><div class="value">&euro; <?= eur($totBlerje) ?></div></div>
    <div class="summary-card"><div class="label">Fitimi Bruto</div><div class="value <?= $totBruto >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($totBruto) ?></div></div>
    <div class="summary-card"><div class="label">Fitimi Neto</div><div class="value <?= $totNeto >= 0 ? 'positive' : 'negative' ?>">&euro; <?= eur($totNeto) ?></div></div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-chart-line"></i> Monthly Profit</h3></div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="clientSortColumn(this, 0)" style="cursor:pointer;user-select:none;">Muaji <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 1)" style="cursor:pointer;user-select:none;">Shitje <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 2)" style="cursor:pointer;user-select:none;">Furnizimi <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 3)" style="cursor:pointer;user-select:none;">Shpenzimet <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;">Fitimi Neto <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;">Bilanci <i class="fas fa-sort"></i></th>
                        <?php $colIdx = 6; foreach ($expenseCategories as $id => $cat): ?>
                        <th class="num server-sort" onclick="clientSortColumn(this, <?= $colIdx ?>)" style="cursor:pointer;user-select:none;font-size:0.7rem;"><?= $cat['label'] ?> <i class="fas fa-sort"></i></th>
                        <?php $colIdx++; endforeach; ?>
                        <th class="num server-sort" onclick="clientSortColumn(this, <?= $colIdx++ ?>)" style="cursor:pointer;user-select:none;">Fitimi Bruto <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, <?= $colIdx++ ?>)" style="cursor:pointer;user-select:none;">Neto pa inv. <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, <?= $colIdx++ ?>)" style="cursor:pointer;user-select:none;">Profit % <i class="fas fa-sort"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $d):
                    $parts = explode('-', $d['muaji']);
                    $label = ($monthNames[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
                    ?>
                    <tr>
                        <td style="font-weight:600;white-space:nowrap;" data-sort-value="<?= $d['muaji'] ?>-01"><?= $label ?></td>
                        <td class="amount">&euro; <?= eur($d['shitje']) ?></td>
                        <td class="amount">&euro; <?= eur($d['blerje']) ?></td>
                        <td class="amount">&euro; <?= eur($d['shpenzime']) ?></td>
                        <td class="amount" style="font-weight:700;color:<?= $d['fitimi_neto'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            &euro; <?= eur($d['fitimi_neto']) ?>
                        </td>
                        <td class="amount">&euro; <?= eur($d['bilanci']) ?></td>
                        <?php foreach ($expenseCategories as $id => $cat): ?>
                        <td class="amount" style="font-size:0.75rem;"><?= $d[$id] > 0 ? eur($d[$id]) : '' ?></td>
                        <?php endforeach; ?>
                        <td class="amount" style="font-weight:600;color:<?= $d['fitimi_bruto'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            &euro; <?= eur($d['fitimi_bruto']) ?>
                        </td>
                        <td class="amount" style="font-weight:600;color:<?= $d['fitimi_neto_excl_inv'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            &euro; <?= eur($d['fitimi_neto_excl_inv']) ?>
                        </td>
                        <td class="amount" style="color:<?= $d['profit_pct'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            <?= number_format($d['profit_pct'], 1) ?>%
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;background:#f8fafc;">
                        <td>TOTALI</td>
                        <td class="amount">&euro; <?= eur($totShitje) ?></td>
                        <td class="amount">&euro; <?= eur($totBlerje) ?></td>
                        <td class="amount">&euro; <?= eur($totShpenzime) ?></td>
                        <td class="amount" style="color:<?= $totNeto >= 0 ? 'var(--success)' : 'var(--danger)' ?>">&euro; <?= eur($totNeto) ?></td>
                        <td class="amount">&euro; <?= eur($runningBalance) ?></td>
                        <?php foreach ($expenseCategories as $id => $cat): ?>
                        <td class="amount" style="font-size:0.75rem;"><?= $catTotals[$id] > 0 ? eur($catTotals[$id]) : '' ?></td>
                        <?php endforeach; ?>
                        <td class="amount" style="color:<?= $totBruto >= 0 ? 'var(--success)' : 'var(--danger)' ?>">&euro; <?= eur($totBruto) ?></td>
                        <td class="amount" style="color:<?= $totNetoExclInv >= 0 ? 'var(--success)' : 'var(--danger)' ?>">&euro; <?= eur($totNetoExclInv) ?></td>
                        <td class="amount" style="color:<?= $totPct >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= number_format($totPct, 1) ?>%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
function clientSortColumn(th, colIdx) {
    const table = th.closest('table');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const icon = th.querySelector('i');
    const asc = icon.classList.contains('fa-sort-down') || icon.classList.contains('fa-sort');
    th.closest('tr').querySelectorAll('th.server-sort > i.fas').forEach(i => { i.className = 'fas fa-sort'; });
    icon.className = 'fas ' + (asc ? 'fa-sort-up' : 'fa-sort-down');
    rows.sort((a, b) => {
        const cellA = a.cells[colIdx];
        const cellB = b.cells[colIdx];
        const ta = cellA?.getAttribute('data-sort-value') || cellA?.textContent?.trim() || '';
        const tb = cellB?.getAttribute('data-sort-value') || cellB?.textContent?.trim() || '';
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
renderLayout('Monthly Profit', 'monthly_profit', $content);
