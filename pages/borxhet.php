<?php
/**
 * DARN Dashboard - Borxhet (Debts Report)
 * Mirrors: GJENDJA e borxheve sheet
 * REPORT page (not input) - calculated from Distribuimi using SUMIFS
 * Date range filter controls which transactions are included
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Date range filter
$dateNga = $_GET['date_from'] ?? '';
$dateDeri = $_GET['date'] ?? date('Y-m-d');

// Multi-select column filters
$fBorxhKlienti = getFilterParam('f_klienti');

$borxhWhere = [];
$borxhParams = [];
if ($dateNga) { $borxhWhere[] = 'data >= ?'; $borxhParams[] = $dateNga; }
if ($fBorxhKlienti) { $fin = buildFilterIn($fBorxhKlienti, 'klienti'); $borxhWhere[] = $fin['sql']; $borxhParams = array_merge($borxhParams, $fin['params']); }
$borxhWhereSQL = $borxhWhere ? 'WHERE ' . implode(' AND ', $borxhWhere) : '';

// Distinct clients for filter
$borxhKlientet = $db->query("SELECT DISTINCT MIN(klienti) as k FROM distribuimi WHERE klienti IS NOT NULL AND TRIM(klienti) != '' GROUP BY LOWER(klienti) ORDER BY k")->fetchAll(PDO::FETCH_COLUMN);

// Core query: debt by client and payment type up to the filter date
$stmt = $db->prepare("
    SELECT
        MIN(klienti) AS klienti,
        SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END) AS cash,
        SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'bank' THEN pagesa ELSE 0 END) AS bank,
        SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) banke' THEN pagesa ELSE 0 END) AS fature_banke,
        SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END) AS fature_cash,
        SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'no payment' THEN pagesa ELSE 0 END) AS no_payment,
        SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'dhurate' THEN pagesa ELSE 0 END) AS dhurate,
        SUM(pagesa) AS total,
        -- Borxhi deri datën: Bank payments only up to filter date
        SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'bank' AND data <= ? THEN pagesa ELSE 0 END) AS borxhi_bank_deri
    FROM distribuimi
    {$borxhWhereSQL}
    GROUP BY LOWER(klienti)
    HAVING total > 0
    ORDER BY MIN(klienti)
");
$stmt->execute(array_merge([$dateDeri], $borxhParams));
$debts = $stmt->fetchAll();

// Totals
$totals = ['cash'=>0,'bank'=>0,'fature_banke'=>0,'fature_cash'=>0,'no_payment'=>0,'dhurate'=>0,'total'=>0,'borxhi_bank_deri'=>0];
foreach ($debts as $d) {
    foreach ($totals as $k => &$v) $v += (float)$d[$k];
}

// Clean note value: strip invisible Unicode chars, collapse whitespace, trim
function cleanNote($s) {
    if ($s === null || $s === '') return '';
    // Strip non-breaking spaces, zero-width chars, BOM, soft hyphens
    $s = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}\x{2060}]/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s); // collapse multiple whitespace
    return trim($s);
}

// Case-insensitive array_unique: dedup by lowercase key, keep first occurrence
function array_unique_ci($arr) {
    $seen = [];
    $result = [];
    foreach ($arr as $v) {
        $key = mb_strtolower($v);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $v;
        }
    }
    return array_values($result);
}

// Load per-client notes (3 extra columns)
$notesRaw = $db->query("SELECT klienti, klient_bank_cash, kush_merr_borxhin, koment FROM borxhet_notes")->fetchAll();
$notes = [];
foreach ($notesRaw as $n) { $notes[strtolower($n['klienti'])] = $n; }

// Build filter value arrays for client-side filters
$borxhCashVals = array_values(array_unique(array_map(fn($d) => eur($d['cash']), $debts)));
$borxhBankVals = array_values(array_unique(array_map(fn($d) => eur($d['bank']), $debts)));
$borxhFBankeVals = array_values(array_unique(array_map(fn($d) => eur($d['fature_banke']), $debts)));
$borxhFCashVals = array_values(array_unique(array_map(fn($d) => eur($d['fature_cash']), $debts)));
$borxhNoPagVals = array_values(array_unique(array_map(fn($d) => eur($d['no_payment']), $debts)));
$borxhDhurateVals = array_values(array_unique(array_map(fn($d) => eur($d['dhurate']), $debts)));
$borxhTotalVals = array_values(array_unique(array_map(fn($d) => '€ ' . eur($d['total']), $debts)));
$borxhBorxhiVals = array_values(array_unique(array_map(fn($d) => '€ ' . eur($d['borxhi_bank_deri']), $debts)));
if (!in_array('', $borxhBorxhiVals)) $borxhBorxhiVals[] = '';
sort($borxhCashVals); sort($borxhBankVals); sort($borxhFBankeVals); sort($borxhFCashVals);
sort($borxhNoPagVals); sort($borxhDhurateVals); sort($borxhTotalVals); sort($borxhBorxhiVals);

// Note columns: build filter values ONLY from clients currently in the debts table (dynamic)
$borxhBCNoteSet = [];
$borxhKushSet = [];
$borxhKomentSet = [];
foreach ($debts as $d) {
    $noteKey = strtolower($d['klienti']);
    $note = $notes[$noteKey] ?? ['klient_bank_cash'=>'','kush_merr_borxhin'=>'','koment'=>''];
    $borxhBCNoteSet[] = cleanNote($note['klient_bank_cash'] ?? '');
    $borxhKushSet[] = cleanNote($note['kush_merr_borxhin'] ?? '');
    $borxhKomentSet[] = cleanNote($note['koment'] ?? '');
}
$borxhBCNoteVals = array_unique_ci($borxhBCNoteSet);
$borxhKushVals = array_unique_ci($borxhKushSet);
$borxhKomentVals = array_unique_ci($borxhKomentSet);
// Ensure blank is always an option
if (!in_array('', $borxhBCNoteVals)) $borxhBCNoteVals[] = '';
if (!in_array('', $borxhKushVals)) $borxhKushVals[] = '';
if (!in_array('', $borxhKomentVals)) $borxhKomentVals[] = '';
sort($borxhBCNoteVals); sort($borxhKushVals); sort($borxhKomentVals);

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-balance-scale"></i> Gjendja e Borxheve</h3>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <label style="font-size:0.82rem;font-weight:600;">Periudha:</label>
                <input type="date" name="date_from" value="<?= e($dateNga) ?>" title="Nga data" style="padding:5px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;width:135px;">
                <span style="font-size:0.82rem;">—</span>
                <input type="date" name="date" value="<?= e($dateDeri) ?>" title="Deri në datë" style="padding:5px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;width:135px;">
                <?php foreach ($fBorxhKlienti as $fv): ?>
                <input type="hidden" name="f_klienti[]" value="<?= e($fv) ?>">
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary btn-sm">Apliko</button>
                <?php if ($dateNga): ?>
                <a href="?date=<?= e($dateDeri) ?>" class="btn btn-outline btn-sm" style="font-size:0.78rem;">Pastro periudhën</a>
                <?php endif; ?>
            </form>
            <button class="btn btn-outline btn-sm print-hide" onclick="window.print()"><i class="fas fa-print"></i> Printo</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" id="borxhetTable">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="clientSortColumn(this, 0)" style="cursor:pointer;user-select:none;" data-filter="f_klienti" data-filter-values="<?= e(json_encode($borxhKlientet, JSON_UNESCAPED_UNICODE)) ?>">Klienti <i class="fas fa-sort"></i></th>
                        <th class="num server-sort print-hide" onclick="clientSortColumn(this, 1)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_cash" data-filter-values="<?= e(json_encode($borxhCashVals)) ?>" data-filter-mode="client" data-filter-col="1">Cash <i class="fas fa-sort"></i></th>
                        <th class="num server-sort print-hide" onclick="clientSortColumn(this, 2)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_bank" data-filter-values="<?= e(json_encode($borxhBankVals)) ?>" data-filter-mode="client" data-filter-col="2">Bank <i class="fas fa-sort"></i></th>
                        <th class="num server-sort print-hide" onclick="clientSortColumn(this, 3)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_fbanke" data-filter-values="<?= e(json_encode($borxhFBankeVals)) ?>" data-filter-mode="client" data-filter-col="3">Faturë banke <i class="fas fa-sort"></i></th>
                        <th class="num server-sort print-hide" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_fcash" data-filter-values="<?= e(json_encode($borxhFCashVals)) ?>" data-filter-mode="client" data-filter-col="4">Faturë cash <i class="fas fa-sort"></i></th>
                        <th class="num server-sort print-hide" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_nopag" data-filter-values="<?= e(json_encode($borxhNoPagVals)) ?>" data-filter-mode="client" data-filter-col="5">Pa paguar <i class="fas fa-sort"></i></th>
                        <th class="num server-sort print-hide" onclick="clientSortColumn(this, 6)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_dhurate" data-filter-values="<?= e(json_encode($borxhDhurateVals)) ?>" data-filter-mode="client" data-filter-col="6">Dhuratë <i class="fas fa-sort"></i></th>
                        <th class="num server-sort print-hide" onclick="clientSortColumn(this, 7)" style="cursor:pointer;user-select:none;font-weight:700;" data-filter="f_borxh_total" data-filter-values="<?= e(json_encode($borxhTotalVals)) ?>" data-filter-mode="client" data-filter-col="7">Total <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 8)" style="cursor:pointer;user-select:none;color:var(--danger);font-weight:700;" data-filter="f_borxh_borxhi" data-filter-values="<?= e(json_encode($borxhBorxhiVals)) ?>" data-filter-mode="client" data-filter-col="8">Borxhi Bank deri <?= date('d/m/Y', strtotime($dateDeri)) ?> <i class="fas fa-sort"></i></th>
                        <th class="server-sort print-hide" onclick="clientSortColumn(this, 9)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_bcnote" data-filter-values="<?= e(json_encode($borxhBCNoteVals)) ?>" data-filter-mode="client" data-filter-col="9">Klient me bank apo cash <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 10)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_kush" data-filter-values="<?= e(json_encode($borxhKushVals)) ?>" data-filter-mode="client" data-filter-col="10">Kush e merr borxhin <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 11)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_koment" data-filter-values="<?= e(json_encode($borxhKomentVals)) ?>" data-filter-mode="client" data-filter-col="11">Komment <i class="fas fa-sort"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($debts as $d):
                        $noteKey = strtolower($d['klienti']);
                        $note = $notes[$noteKey] ?? ['klient_bank_cash'=>'','kush_merr_borxhin'=>'','koment'=>''];
                    ?>
                    <tr <?= (float)$d['borxhi_bank_deri'] > 0 ? 'style="background:#fef2f2;"' : '' ?>>
                        <td><a href="#" class="borxh-client-link" data-klienti="<?= e($d['klienti']) ?>" style="color:inherit;text-decoration:none;border-bottom:1px dashed var(--text-muted);"><?= e($d['klienti']) ?></a></td>
                        <td class="amount print-hide"><?= eur($d['cash']) ?></td>
                        <td class="amount print-hide"><?= eur($d['bank']) ?></td>
                        <td class="amount print-hide"><?= eur($d['fature_banke']) ?></td>
                        <td class="amount print-hide"><?= eur($d['fature_cash']) ?></td>
                        <td class="amount print-hide"><?= eur($d['no_payment']) ?></td>
                        <td class="amount print-hide"><?= eur($d['dhurate']) ?></td>
                        <td class="amount print-hide" style="font-weight:700;">&euro; <?= eur($d['total']) ?></td>
                        <td class="amount" style="font-weight:700;color:var(--danger);">&euro; <?= eur($d['borxhi_bank_deri']) ?></td>
                        <td class="borxh-note print-hide" data-klienti="<?= e($noteKey) ?>" data-field="klient_bank_cash" contenteditable="true" style="min-width:80px;"><?= e(cleanNote($note['klient_bank_cash'] ?? '')) ?></td>
                        <td class="borxh-note" data-klienti="<?= e($noteKey) ?>" data-field="kush_merr_borxhin" contenteditable="true" style="min-width:100px;"><?= e(cleanNote($note['kush_merr_borxhin'] ?? '')) ?></td>
                        <td class="borxh-note" data-klienti="<?= e($noteKey) ?>" data-field="koment" contenteditable="true" style="min-width:120px;"><?= e(cleanNote($note['koment'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;background:#f8fafc;">
                        <td>TOTALI</td>
                        <td class="amount print-hide">&euro; <?= eur($totals['cash']) ?></td>
                        <td class="amount print-hide">&euro; <?= eur($totals['bank']) ?></td>
                        <td class="amount print-hide">&euro; <?= eur($totals['fature_banke']) ?></td>
                        <td class="amount print-hide">&euro; <?= eur($totals['fature_cash']) ?></td>
                        <td class="amount print-hide">&euro; <?= eur($totals['no_payment']) ?></td>
                        <td class="amount print-hide">&euro; <?= eur($totals['dhurate']) ?></td>
                        <td class="amount print-hide">&euro; <?= eur($totals['total']) ?></td>
                        <td class="amount" style="color:var(--danger);">&euro; <?= eur($totals['borxhi_bank_deri']) ?></td>
                        <td class="print-hide"></td><td></td><td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Print header (visible only in print) -->
<div class="print-only" style="display:none;margin-bottom:12px;">
    <h2 style="margin:0 0 4px 0;font-size:14pt;">DARN Group — Gjendja e Borxheve</h2>
    <p style="margin:0;font-size:9pt;color:#666;">
        <?php if ($dateNga): ?>Periudha: <?= e($dateNga) ?> — <?= e($dateDeri) ?><?php else: ?>Deri më: <?= e($dateDeri) ?><?php endif; ?>
        &nbsp;|&nbsp; <?= count($debts) ?> klientë
        &nbsp;|&nbsp; Printuar: <?= date('d/m/Y H:i') ?>
    </p>
</div>

<p class="print-hide" style="color:var(--text-muted);font-size:0.82rem;margin-top:8px;">
    <i class="fas fa-info-circle"></i> Ky raport gjenerohet automatikisht nga të dhënat e Distribuimit.
    <?= count($debts) ?> klientë me transaksione.
    <br>Kolonat Bank/Cash, Kush merr borxhin, dhe Koment ruhen automatikisht kur ndryshoni.
    <br>Klikoni mbi emrin e klientit për të parë transaksionet me data.
</p>

<!-- Client Debt Detail Modal -->
<div class="modal-overlay" id="clientDebtModal">
    <div class="modal" style="max-width:900px;width:95%;">
        <div class="modal-header">
            <h3 id="clientDebtTitle">Transaksionet e klientit</h3>
            <button class="btn btn-outline btn-sm" onclick="closeModal('clientDebtModal')">&times;</button>
        </div>
        <div class="modal-body" style="max-height:70vh;overflow-y:auto;">
            <div id="clientDebtLoading" style="text-align:center;padding:30px;color:var(--text-muted);">
                <i class="fas fa-spinner fa-spin"></i> Duke ngarkuar...
            </div>
            <div id="clientDebtContent" style="display:none;">
                <table class="data-table" id="clientDebtTable" style="width:100%;font-size:0.85rem;table-layout:fixed;">
                    <colgroup>
                        <col style="width:18%;">
                        <col style="width:12%;">
                        <col style="width:14%;">
                        <col style="width:14%;">
                        <col style="width:16%;">
                        <col style="width:26%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th class="num">Sasia</th>
                            <th class="num">Litra</th>
                            <th class="num">Çmimi</th>
                            <th class="num">Borxhi</th>
                            <th>Koment</th>
                        </tr>
                    </thead>
                    <tbody id="clientDebtBody"></tbody>
                    <tfoot>
                        <tr style="font-weight:700;border-top:2px solid var(--border);">
                            <td id="clientDebtCount"></td>
                            <td class="amount" id="clientDebtSasia"></td>
                            <td class="amount" id="clientDebtLitra"></td>
                            <td></td>
                            <td class="amount" id="clientDebtPagesa" style="color:var(--danger);"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Click on client name to show debt detail modal
document.querySelectorAll('.borxh-client-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const klienti = this.dataset.klienti;
        const modal = document.getElementById('clientDebtModal');
        const title = document.getElementById('clientDebtTitle');
        const loading = document.getElementById('clientDebtLoading');
        const content = document.getElementById('clientDebtContent');
        const tbody = document.getElementById('clientDebtBody');

        title.textContent = 'Borxhi (Bank): ' + klienti;
        loading.style.display = '';
        content.style.display = 'none';
        tbody.innerHTML = '';
        openModal('clientDebtModal');

        // Pass date filters so popup totals match the borxhi column
        const dateFrom = <?= json_encode($dateNga) ?>;
        const dateTo = <?= json_encode($dateDeri) ?>;
        let url = '/api/client_debts.php?klienti=' + encodeURIComponent(klienti);
        if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
        if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);

        fetch(url)
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                if (!data.success || !data.rows.length) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text-muted);">Nuk ka borxhe (bank) për këtë klient.</td></tr>';
                    document.getElementById('clientDebtCount').textContent = '0 rreshta';
                    document.getElementById('clientDebtSasia').textContent = '';
                    document.getElementById('clientDebtLitra').textContent = '';
                    document.getElementById('clientDebtPagesa').textContent = '';
                    content.style.display = '';
                    return;
                }
                let totalSasia = 0, totalLitra = 0, totalPagesa = 0;
                const fmtNum = (n, dec) => n.toLocaleString('de-DE', {minimumFractionDigits:dec, maximumFractionDigits:dec});
                data.rows.forEach(r => {
                    const s = parseFloat(r.sasia) || 0;
                    const l = parseFloat(r.litra) || 0;
                    const p = parseFloat(r.pagesa) || 0;
                    const c = parseFloat(r.cmimi) || 0;
                    totalSasia += s;
                    totalLitra += l;
                    totalPagesa += p;
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>' + (r.data || '-') + '</td>' +
                        '<td class="amount">' + fmtNum(s, 0) + '</td>' +
                        '<td class="amount">' + fmtNum(l, 2) + '</td>' +
                        '<td class="amount">' + fmtNum(c, 2) + '</td>' +
                        '<td class="amount">&euro; ' + fmtNum(p, 2) + '</td>' +
                        '<td style="white-space:normal;word-break:break-word;overflow:hidden;text-overflow:ellipsis;">' + (r.koment || '') + '</td>';
                    tbody.appendChild(tr);
                });
                document.getElementById('clientDebtCount').textContent = data.rows.length + ' rreshta';
                document.getElementById('clientDebtSasia').textContent = fmtNum(totalSasia, 0);
                document.getElementById('clientDebtLitra').textContent = fmtNum(totalLitra, 2);
                document.getElementById('clientDebtPagesa').innerHTML = '&euro; ' + fmtNum(totalPagesa, 2);
                content.style.display = '';
            })
            .catch(() => {
                loading.innerHTML = '<span style="color:var(--danger);"><i class="fas fa-exclamation-triangle"></i> Gabim gjatë ngarkimit.</span>';
            });
    });
});
</script>

<script>
// Auto-save borxhet notes on blur (contenteditable cells)
document.querySelectorAll('.borxh-note').forEach(cell => {
    cell.addEventListener('blur', function() {
        const klienti = this.dataset.klienti;
        const field = this.dataset.field;
        const value = this.textContent.trim();
        fetch('/api/borxhet_notes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ klienti, field, value })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                this.style.background = '#f0fdf4';
                setTimeout(() => this.style.background = '', 1000);
            }
        });
    });
});
</script>

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

<script>
// Dynamic tfoot totals: recalculate based on visible rows when filters change
(function() {
    const table = document.getElementById('borxhetTable');
    if (!table) return;

    // Amount columns to sum (indices 1-8)
    const sumCols = [1, 2, 3, 4, 5, 6, 7, 8];

    function recalcTotals() {
        const tbody = table.querySelector('tbody');
        const tfoot = table.querySelector('tfoot');
        if (!tbody || !tfoot) return;
        const footCells = tfoot.querySelector('tr').cells;

        const sums = {};
        sumCols.forEach(i => sums[i] = 0);

        tbody.querySelectorAll('tr').forEach(row => {
            if (row.style.display === 'none') return;
            sumCols.forEach(i => {
                const text = row.cells[i]?.textContent?.trim() || '0';
                const num = parseFloat(text.replace(/[^0-9.\-]/g, ''));
                if (!isNaN(num)) sums[i] += num;
            });
        });

        sumCols.forEach(i => {
            const val = sums[i].toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            footCells[i].innerHTML = '&euro; ' + val;
        });
    }

    // Monkey-patch applyClientFilters to also recalculate totals
    if (typeof applyClientFilters === 'function') {
        const _orig = applyClientFilters;
        applyClientFilters = function(tbl) {
            _orig(tbl);
            if (tbl === table || tbl.id === 'borxhetTable') recalcTotals();
        };
    }

    // Also observe for visibility changes (covers table search filter too)
    const observer = new MutationObserver(() => recalcTotals());
    const tbody = table.querySelector('tbody');
    if (tbody) {
        observer.observe(tbody, { attributes: true, attributeFilter: ['style'], subtree: true });
    }
})();
</script>

<?php
$content = ob_get_clean();
renderLayout('Borxhet', 'borxhet', $content);
