<?php
/**
 * DARN Dashboard - Stoku Zyrtar (Official Product Inventory)
 * Mirrors Excel "Stoku zyrtar" sheet:
 *   Columns: Kodi, Kodi 2, Përshkrimi, Njësi, Sasia, Çmimi, Vlera
 *   Col J (Stoku momental) = SUMIFS(sasia, kodi, product_code) - running total per product
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Multi-select column filters
$fSzKodi = getFilterParam('f_kodi');
$fSzDest = getFilterParam('f_dest');

$szWhere = [];
$szParams = [];
if ($fSzKodi) { $fin = buildFilterIn($fSzKodi, 'kodi'); $szWhere[] = $fin['sql']; $szParams = array_merge($szParams, $fin['params']); }
if ($fSzDest) { $fin = buildFilterIn($fSzDest, 'kodi_2'); $szWhere[] = $fin['sql']; $szParams = array_merge($szParams, $fin['params']); }
$szWhereSQL = $szWhere ? 'WHERE ' . implode(' AND ', $szWhere) : '';

// Distinct values for filters
$szKodiVals = $db->query("SELECT DISTINCT kodi FROM stoku_zyrtar WHERE kodi IS NOT NULL ORDER BY kodi")->fetchAll(PDO::FETCH_COLUMN);
$szDestVals = $db->query("SELECT DISTINCT kodi_2 FROM stoku_zyrtar WHERE kodi_2 IS NOT NULL AND kodi_2 != '' ORDER BY kodi_2")->fetchAll(PDO::FETCH_COLUMN);

// All entries (with filters)
$szStmt = $db->prepare("SELECT * FROM stoku_zyrtar {$szWhereSQL} ORDER BY data ASC, id ASC");
$szStmt->execute($szParams);
$rows = $szStmt->fetchAll();

// Stoku momental per product code (SUMIFS equivalent)
$stokuPerKodi = $db->query("
    SELECT kodi, SUM(sasia) as stoku_momental
    FROM stoku_zyrtar
    GROUP BY kodi
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Product summary (initial entries = first occurrence of each kodi with positive sasia)
$productSummary = $db->query("
    SELECT kodi,
           MAX(pershkrimi) as pershkrimi,
           MAX(njesi) as njesi,
           SUM(CASE WHEN sasia > 0 THEN sasia ELSE 0 END) as sasia_fillestare,
           MAX(CASE WHEN sasia > 0 THEN cmimi ELSE NULL END) as cmimi,
           MAX(CASE WHEN sasia > 0 THEN vlera ELSE NULL END) as vlera,
           SUM(sasia) as stoku_momental
    FROM stoku_zyrtar
    GROUP BY kodi
    ORDER BY kodi
")->fetchAll();

$totalVlera = array_sum(array_map(fn($r) => (float)($r['vlera'] ?? 0), $productSummary));
$totalStoku = array_sum(array_map(fn($r) => (float)$r['stoku_momental'], $productSummary));

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Produkte unikë</div>
        <div class="value"><?= count($productSummary) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total Vlera</div>
        <div class="value">&euro; <?= eur($totalVlera) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total lëvizje</div>
        <div class="value"><?= num(count($rows)) ?></div>
    </div>
</div>

<!-- Product Summary Card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-boxes"></i> Stoku Momental per Produkt</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="clientSortColumn(this, 0)" style="cursor:pointer;user-select:none;">Kodi <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 1)" style="cursor:pointer;user-select:none;">Përshkrimi <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 2)" style="cursor:pointer;user-select:none;">Njësi <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 3)" style="cursor:pointer;user-select:none;">Sasia fillestare <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;">Çmimi <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;">Vlera <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 6)" style="cursor:pointer;user-select:none;font-weight:700;">Stoku momental <i class="fas fa-sort"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productSummary as $p): ?>
                    <tr>
                        <td style="font-weight:600;font-family:monospace;"><?= e($p['kodi']) ?></td>
                        <td><?= e($p['pershkrimi']) ?></td>
                        <td><?= e($p['njesi']) ?></td>
                        <td class="num"><?= num($p['sasia_fillestare']) ?></td>
                        <td class="amount"><?= $p['cmimi'] ? '&euro; ' . eur($p['cmimi']) : '-' ?></td>
                        <td class="amount"><?= $p['vlera'] ? '&euro; ' . eur($p['vlera']) : '-' ?></td>
                        <td class="num" style="font-weight:700;color:<?= $p['stoku_momental'] > 0 ? 'var(--success)' : ($p['stoku_momental'] < 0 ? 'var(--danger)' : 'inherit') ?>;">
                            <?= num($p['stoku_momental']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- All Stock Movements -->
<div class="card">
    <div class="card-header">
        <h3>Lëvizjet e stokut</h3>
        <button class="btn btn-primary btn-sm" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Shto</button>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="stoku_zyrtar">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="clientSortColumn(this, 0)" style="cursor:pointer;user-select:none;">Data <i class="fas fa-sort"></i></th>
                        <th class="server-sort" data-filter="f_kodi" data-filter-values="<?= e(json_encode($szKodiVals, JSON_UNESCAPED_UNICODE)) ?>" onclick="clientSortColumn(this, 1)" style="cursor:pointer;user-select:none;">Kodi <i class="fas fa-sort"></i></th>
                        <th class="server-sort" data-filter="f_dest" data-filter-values="<?= e(json_encode($szDestVals, JSON_UNESCAPED_UNICODE)) ?>" onclick="clientSortColumn(this, 2)" style="cursor:pointer;user-select:none;">Destinacioni <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 3)" style="cursor:pointer;user-select:none;">Përshkrimi <i class="fas fa-sort"></i></th>
                        <th class="server-sort" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;">Njësi <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;">Sasia <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 6)" style="cursor:pointer;user-select:none;">Çmimi <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 7)" style="cursor:pointer;user-select:none;">Vlera <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 8)" style="cursor:pointer;user-select:none;">Stoku momental <i class="fas fa-sort"></i></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="editable" data-field="kodi" style="font-family:monospace;"><?= e($r['kodi']) ?></td>
                        <td class="editable" data-field="kodi_2"><?= e($r['kodi_2']) ?></td>
                        <td class="editable" data-field="pershkrimi"><?= e($r['pershkrimi']) ?></td>
                        <td class="editable" data-field="njesi"><?= e($r['njesi']) ?></td>
                        <td class="num editable" data-field="sasia" data-type="number"
                            style="font-weight:600;color:<?= $r['sasia'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            <?= number_format((float)$r['sasia'], 0) ?>
                        </td>
                        <td class="amount editable" data-field="cmimi" data-type="number"><?= $r['cmimi'] ? eur($r['cmimi']) : '-' ?></td>
                        <td class="amount editable" data-field="vlera" data-type="number"><?= $r['vlera'] ? '&euro; ' . eur($r['vlera']) : '-' ?></td>
                        <td class="num" style="font-weight:600;color:var(--primary);">
                            <?= isset($stokuPerKodi[$r['kodi']]) ? num($stokuPerKodi[$r['kodi']]) : '-' ?>
                        </td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('stoku_zyrtar',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header"><h3>Shto lëvizje stoku</h3><button class="btn btn-outline btn-sm" onclick="closeModal('addModal')">&times;</button></div>
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="stoku_zyrtar">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label>Data *</label><input type="date" name="data" required value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group">
                        <label>Kodi *</label>
                        <input type="text" name="kodi" required list="kodiList">
                        <datalist id="kodiList">
                            <?php foreach ($productSummary as $p): ?><option value="<?= e($p['kodi']) ?>"><?= e($p['pershkrimi']) ?></option><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Përshkrimi *</label><input type="text" name="pershkrimi" required></div>
                    <div class="form-group"><label>Destinacioni / Kodi 2</label><input type="text" name="kodi_2"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Njësi</label><input type="text" name="njesi" value="COPE"></div>
                    <div class="form-group"><label>Sasia *</label><input type="number" name="sasia" required step="0.01" placeholder="Negative for outgoing"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Çmimi</label><input type="number" name="cmimi" step="0.0001"></div>
                    <div class="form-group"><label>Vlera</label><input type="number" name="vlera" step="0.01"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Anulo</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button>
            </div>
        </form>
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
renderLayout('Stoku Zyrtar', 'stoku_zyrtar', $content);
