<?php
/**
 * DARN Dashboard - Borxhet (Debts Report)
 * Mirrors: GJENDJA e borxheve sheet
 * REPORT page (not input) - calculated from Distribuimi using SUMIFS
 * Date filter in cell I1/M1 controls debt visibility
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Date filter (mirrors Excel cell M1)
$dateDeri = $_GET['date'] ?? date('Y-m-d');

// Multi-select column filters
$fBorxhKlienti = getFilterParam('f_klienti');

$borxhWhere = [];
$borxhParams = [];
if ($fBorxhKlienti) { $fin = buildFilterIn($fBorxhKlienti, 'klienti'); $borxhWhere[] = $fin['sql']; $borxhParams = array_merge($borxhParams, $fin['params']); }
$borxhWhereSQL = $borxhWhere ? 'WHERE ' . implode(' AND ', $borxhWhere) : '';

// Distinct clients for filter
$borxhKlientet = $db->query("SELECT DISTINCT MIN(klienti) as k FROM distribuimi GROUP BY LOWER(klienti) ORDER BY k")->fetchAll(PDO::FETCH_COLUMN);

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

// Load per-client notes (3 extra columns)
$notesRaw = $db->query("SELECT klienti, klient_bank_cash, kush_merr_borxhin, koment FROM borxhet_notes")->fetchAll();
$notes = [];
foreach ($notesRaw as $n) { $notes[$n['klienti']] = $n; }

// Build filter value arrays for client-side filters
$borxhCashVals = array_values(array_unique(array_map(fn($d) => eur($d['cash']), $debts)));
$borxhBankVals = array_values(array_unique(array_map(fn($d) => eur($d['bank']), $debts)));
$borxhFBankeVals = array_values(array_unique(array_map(fn($d) => eur($d['fature_banke']), $debts)));
$borxhFCashVals = array_values(array_unique(array_map(fn($d) => eur($d['fature_cash']), $debts)));
$borxhNoPagVals = array_values(array_unique(array_map(fn($d) => eur($d['no_payment']), $debts)));
$borxhDhurateVals = array_values(array_unique(array_map(fn($d) => eur($d['dhurate']), $debts)));
$borxhTotalVals = array_values(array_unique(array_map(fn($d) => eur($d['total']), $debts)));
$borxhBorxhiVals = array_values(array_unique(array_map(fn($d) => eur($d['borxhi_bank_deri']), $debts)));
sort($borxhCashVals); sort($borxhBankVals); sort($borxhFBankeVals); sort($borxhFCashVals);
sort($borxhNoPagVals); sort($borxhDhurateVals); sort($borxhTotalVals); sort($borxhBorxhiVals);

$borxhBCNoteVals = array_values(array_unique(array_filter(array_map(fn($n) => $n['klient_bank_cash'] ?? '', $notes))));
$borxhKushVals = array_values(array_unique(array_filter(array_map(fn($n) => $n['kush_merr_borxhin'] ?? '', $notes))));
$borxhKomentVals = array_values(array_unique(array_filter(array_map(fn($n) => $n['koment'] ?? '', $notes))));
sort($borxhBCNoteVals); sort($borxhKushVals); sort($borxhKomentVals);

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-balance-scale"></i> Gjendja e Borxheve</h3>
        <form method="GET" style="display:flex;gap:12px;align-items:center;">
            <label style="font-size:0.82rem;font-weight:600;">Borxhi deri datën:</label>
            <input type="date" name="date" value="<?= e($dateDeri) ?>" style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;">
            <button type="submit" class="btn btn-primary btn-sm">Apliko</button>
        </form>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="clientSortColumn(this, 0)" style="cursor:pointer;user-select:none;" data-filter="f_klienti" data-filter-values="<?= e(json_encode($borxhKlientet, JSON_UNESCAPED_UNICODE)) ?>">Klienti <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 1)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_cash" data-filter-values="<?= e(json_encode($borxhCashVals)) ?>" data-filter-mode="client" data-filter-col="1">Cash <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 2)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_bank" data-filter-values="<?= e(json_encode($borxhBankVals)) ?>" data-filter-mode="client" data-filter-col="2">Bank <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 3)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_fbanke" data-filter-values="<?= e(json_encode($borxhFBankeVals)) ?>" data-filter-mode="client" data-filter-col="3">Faturë banke <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_fcash" data-filter-values="<?= e(json_encode($borxhFCashVals)) ?>" data-filter-mode="client" data-filter-col="4">Faturë cash <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_nopag" data-filter-values="<?= e(json_encode($borxhNoPagVals)) ?>" data-filter-mode="client" data-filter-col="5">Pa paguar <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 6)" style="cursor:pointer;user-select:none;" data-filter="f_borxh_dhurate" data-filter-values="<?= e(json_encode($borxhDhurateVals)) ?>" data-filter-mode="client" data-filter-col="6">Dhuratë <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 7)" style="cursor:pointer;user-select:none;font-weight:700;" data-filter="f_borxh_total" data-filter-values="<?= e(json_encode($borxhTotalVals)) ?>" data-filter-mode="client" data-filter-col="7">Total <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 8)" style="cursor:pointer;user-select:none;color:var(--danger);font-weight:700;" data-filter="f_borxh_borxhi" data-filter-values="<?= e(json_encode($borxhBorxhiVals)) ?>" data-filter-mode="client" data-filter-col="8">Borxhi Bank deri <?= date('d/m/Y', strtotime($dateDeri)) ?> <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 9)" style="cursor:pointer;user-select:none;" <?php if ($borxhBCNoteVals): ?>data-filter="f_borxh_bcnote" data-filter-values="<?= e(json_encode($borxhBCNoteVals)) ?>" data-filter-mode="client" data-filter-col="9"<?php endif; ?>>Klient me bank apo cash <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 10)" style="cursor:pointer;user-select:none;" <?php if ($borxhKushVals): ?>data-filter="f_borxh_kush" data-filter-values="<?= e(json_encode($borxhKushVals)) ?>" data-filter-mode="client" data-filter-col="10"<?php endif; ?>>Kush e merr borxhin <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 11)" style="cursor:pointer;user-select:none;" <?php if ($borxhKomentVals): ?>data-filter="f_borxh_koment" data-filter-values="<?= e(json_encode($borxhKomentVals)) ?>" data-filter-mode="client" data-filter-col="11"<?php endif; ?>>Komment <i class="fas fa-sort"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($debts as $d):
                        $noteKey = strtolower($d['klienti']);
                        $note = $notes[$noteKey] ?? ['klient_bank_cash'=>'','kush_merr_borxhin'=>'','koment'=>''];
                    ?>
                    <tr <?= (float)$d['borxhi_bank_deri'] > 0 ? 'style="background:#fef2f2;"' : '' ?>>
                        <td><?= e($d['klienti']) ?></td>
                        <td class="amount"><?= eur($d['cash']) ?></td>
                        <td class="amount"><?= eur($d['bank']) ?></td>
                        <td class="amount"><?= eur($d['fature_banke']) ?></td>
                        <td class="amount"><?= eur($d['fature_cash']) ?></td>
                        <td class="amount"><?= eur($d['no_payment']) ?></td>
                        <td class="amount"><?= eur($d['dhurate']) ?></td>
                        <td class="amount" style="font-weight:700;">&euro; <?= eur($d['total']) ?></td>
                        <td class="amount" style="font-weight:700;color:var(--danger);">&euro; <?= eur($d['borxhi_bank_deri']) ?></td>
                        <td class="borxh-note" data-klienti="<?= e($noteKey) ?>" data-field="klient_bank_cash" contenteditable="true" style="min-width:80px;"><?= e($note['klient_bank_cash']) ?></td>
                        <td class="borxh-note" data-klienti="<?= e($noteKey) ?>" data-field="kush_merr_borxhin" contenteditable="true" style="min-width:100px;"><?= e($note['kush_merr_borxhin']) ?></td>
                        <td class="borxh-note" data-klienti="<?= e($noteKey) ?>" data-field="koment" contenteditable="true" style="min-width:120px;"><?= e($note['koment']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;background:#f8fafc;">
                        <td>TOTALI</td>
                        <td class="amount">&euro; <?= eur($totals['cash']) ?></td>
                        <td class="amount">&euro; <?= eur($totals['bank']) ?></td>
                        <td class="amount">&euro; <?= eur($totals['fature_banke']) ?></td>
                        <td class="amount">&euro; <?= eur($totals['fature_cash']) ?></td>
                        <td class="amount">&euro; <?= eur($totals['no_payment']) ?></td>
                        <td class="amount">&euro; <?= eur($totals['dhurate']) ?></td>
                        <td class="amount">&euro; <?= eur($totals['total']) ?></td>
                        <td class="amount" style="color:var(--danger);">&euro; <?= eur($totals['borxhi_bank_deri']) ?></td>
                        <td></td><td></td><td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<p style="color:var(--text-muted);font-size:0.82rem;margin-top:8px;">
    <i class="fas fa-info-circle"></i> Ky raport gjenerohet automatikisht nga të dhënat e Distribuimit.
    <?= count($debts) ?> klientë me transaksione.
    <br>Kolonat Bank/Cash, Kush merr borxhin, dhe Koment ruhen automatikisht kur ndryshoni.
</p>

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

<?php
$content = ob_get_clean();
renderLayout('Borxhet', 'borxhet', $content);
