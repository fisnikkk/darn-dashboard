<?php
/**
 * DARN Dashboard - Litrat (Liters tracking)
 * Monthly aggregation matching Excel "Litrat" sheet exactly:
 *   Col C = Total litra te BLERE per muaj (plini_depo sasia_ne_litra)
 *   Col D = Total litra te shitur per muaj (distribuimi litrat_e_konvertuara = Excel Column Z)
 *   Col E = Litrat blera me fature (plini_depo WHERE menyra_e_pageses='Me fature')
 *   Col F = Litrat leshuar me fature (distribuimi WHERE fature payment methods)
 *   Col G = Litrat mbetur me fature = E - F
 *   Col H = Litrat ne dispozicion me fature (all-time cumulative E - F)
 *   Col I = Boca te shperndara (distribuimi sasia)
 *   Col J = Shitjet totale per muaj (distribuimi pagesa, stored from Excel)
 *   Col K = Kthim litrave per boce = (D - C) / I
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// All distribuimi aggregations in a single query (case-insensitive payment matching)
// Col D: litrat_e_konvertuara (total liters sold)
// Col F: litrat_e_konvertuara WHERE fature payment method (liters invoiced)
// Col I: sasia (bottles distributed)
// Col J: pagesa (sales total from Excel)
$distByMonth = [];
foreach ($db->query("
    SELECT DATE_FORMAT(data, '%Y-%m') as m,
        COALESCE(SUM(litrat_e_konvertuara), 0) as litraShitura,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) IN ('po (fature te rregullte) cash','bank','po (fature te rregullte) banke') THEN litrat_e_konvertuara ELSE 0 END), 0) as litraFaturuara,
        COALESCE(SUM(sasia), 0) as bocaShperndara,
        COALESCE(SUM(pagesa), 0) as shitje
    FROM distribuimi WHERE data IS NOT NULL
    GROUP BY DATE_FORMAT(data, '%Y-%m')
    ORDER BY m DESC
")->fetchAll() as $r) {
    $distByMonth[$r['m']] = $r;
}

// Plini depo liters - total and with-invoice breakdown (single query)
// Col C: all liters bought
// Col E: liters bought with invoice (menyra_e_pageses = 'Me fature')
$pliniByMonth = [];
foreach ($db->query("
    SELECT DATE_FORMAT(data, '%Y-%m') as m,
        COALESCE(SUM(sasia_ne_litra), 0) as litraBlera,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'me fature' THEN sasia_ne_litra ELSE 0 END), 0) as litraBleraMeFature
    FROM plini_depo WHERE data IS NOT NULL
    GROUP BY DATE_FORMAT(data, '%Y-%m')
")->fetchAll() as $r) {
    $pliniByMonth[$r['m']] = $r;
}

$allMonths = array_unique(array_merge(array_keys($distByMonth), array_keys($pliniByMonth)));
rsort($allMonths);

$data = [];
foreach ($allMonths as $m) {
    if (!$m) continue;
    $d = $distByMonth[$m] ?? [];
    $p = $pliniByMonth[$m] ?? [];
    $litraBleraMeFature = (float)($p['litraBleraMeFature'] ?? 0);
    $litraFaturuara = (float)($d['litraFaturuara'] ?? 0);
    $litraBlera = (float)($p['litraBlera'] ?? 0);
    $litraShitura = (float)($d['litraShitura'] ?? 0);
    $bocaShperndara = (float)($d['bocaShperndara'] ?? 0);

    $data[] = [
        'm' => $m,
        'litraBlera' => $litraBlera,                          // Col C
        'litraShitura' => $litraShitura,                       // Col D
        'litraBleraMeFature' => $litraBleraMeFature,           // Col E
        'litraFaturuara' => $litraFaturuara,                   // Col F
        'litraMbeturMeFature' => $litraBleraMeFature - $litraFaturuara,  // Col G = E-F
        'bocaShperndara' => $bocaShperndara,                   // Col I
        'shitje' => (float)($d['shitje'] ?? 0),               // Col J
        'kthimPerBoce' => $bocaShperndara > 0                  // Col K = (D-C)/I
            ? ($litraShitura - $litraBlera) / $bocaShperndara : 0,
    ];
}

// Col H: per-row cumulative (running total oldest-first of E - F)
$totBleraMeFature = array_sum(array_column($data, 'litraBleraMeFature'));
$totFaturuara = array_sum(array_column($data, 'litraFaturuara'));
$litratNeDispozicion = $totBleraMeFature - $totFaturuara;

// Calculate per-row cumulative Col H (oldest first)
$sortedForCum = $data;
usort($sortedForCum, fn($a, $b) => strcmp($a['m'], $b['m']));
$cumBalance = 0;
$cumMap = [];
foreach ($sortedForCum as $row) {
    $cumBalance += $row['litraBleraMeFature'] - $row['litraFaturuara'];
    $cumMap[$row['m']] = $cumBalance;
}
foreach ($data as &$d) {
    $d['litratNeDispozicionCum'] = $cumMap[$d['m']] ?? 0;
}
unset($d);

$monthNames = ['01'=>'Janar','02'=>'Shkurt','03'=>'Mars','04'=>'Prill','05'=>'Maj','06'=>'Qershor',
               '07'=>'Korrik','08'=>'Gusht','09'=>'Shtator','10'=>'Tetor','11'=>'Nëntor','12'=>'Dhjetor'];

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card"><div class="label">Litrat në dispozicion me faturë</div><div class="value"><?= eur($litratNeDispozicion) ?> L</div></div>
    <div class="summary-card"><div class="label">Total Litra të blera</div><div class="value"><?= eur(array_sum(array_column($data,'litraBlera'))) ?> L</div></div>
    <div class="summary-card"><div class="label">Total Litra të shitura</div><div class="value"><?= eur(array_sum(array_column($data,'litraShitura'))) ?> L</div></div>
    <div class="summary-card"><div class="label">Total Shitjet</div><div class="value">&euro; <?= eur(array_sum(array_column($data,'shitje'))) ?></div></div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-tint"></i> Litrat - Raport mujor</h3></div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="clientSortColumn(this, 0)" style="cursor:pointer;user-select:none;">Muaji <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 1)" style="cursor:pointer;user-select:none;">Litra të blera (C) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 2)" style="cursor:pointer;user-select:none;">Litra të shitura (D) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 3)" style="cursor:pointer;user-select:none;">Blera me faturë (E) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;">Lëshuar me faturë (F) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;">Mbetur me faturë (G) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 6)" style="cursor:pointer;user-select:none;">Dispozicion me faturë (H) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 7)" style="cursor:pointer;user-select:none;">Boca shpërndara (I) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 8)" style="cursor:pointer;user-select:none;">Shitjet (J) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 9)" style="cursor:pointer;user-select:none;">Kthim/Bocë (K) <i class="fas fa-sort"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $d):
                    $parts = explode('-', $d['m']);
                    $label = ($monthNames[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
                    ?>
                    <tr>
                        <td style="font-weight:600;" data-sort-value="<?= $d['m'] ?>-01"><?= $label ?></td>
                        <td class="amount"><?= eur($d['litraBlera']) ?></td>
                        <td class="amount"><?= eur($d['litraShitura']) ?></td>
                        <td class="amount"><?= eur($d['litraBleraMeFature']) ?></td>
                        <td class="amount"><?= eur($d['litraFaturuara']) ?></td>
                        <td class="amount" style="color:<?= $d['litraMbeturMeFature'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            <?= eur($d['litraMbeturMeFature']) ?>
                        </td>
                        <td class="amount" style="font-weight:600;color:<?= $d['litratNeDispozicionCum'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            <?= eur($d['litratNeDispozicionCum']) ?>
                        </td>
                        <td class="num"><?= num($d['bocaShperndara']) ?></td>
                        <td class="amount">&euro; <?= eur($d['shitje']) ?></td>
                        <td class="amount"><?= number_format($d['kthimPerBoce'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;background:#f8fafc;">
                        <td>TOTALI</td>
                        <td class="amount"><?= eur(array_sum(array_column($data,'litraBlera'))) ?></td>
                        <td class="amount"><?= eur(array_sum(array_column($data,'litraShitura'))) ?></td>
                        <td class="amount"><?= eur($totBleraMeFature) ?></td>
                        <td class="amount"><?= eur($totFaturuara) ?></td>
                        <td class="amount" style="color:<?= $litratNeDispozicion >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            <?= eur($litratNeDispozicion) ?>
                        </td>
                        <td class="amount" style="font-weight:600;color:<?= $litratNeDispozicion >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            <?= eur($litratNeDispozicion) ?>
                        </td>
                        <td class="num"><?= num(array_sum(array_column($data,'bocaShperndara'))) ?></td>
                        <td class="amount">&euro; <?= eur(array_sum(array_column($data,'shitje'))) ?></td>
                        <td class="amount">-</td>
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
    th.closest('tr').querySelectorAll('th.server-sort i.fas').forEach(i => { i.className = 'fas fa-sort'; });
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
renderLayout('Litrat', 'litrat', $content);
