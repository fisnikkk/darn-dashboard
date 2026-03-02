<?php
/**
 * DARN Dashboard - Depo (Product Accessories Stock)
 * Mirrors Excel "Depo" sheet:
 *   Columns: Data, Produkti, Sasia, Çmimi, Total të shitura, Stoku aktual
 *   Col F (Total të shitura) = SUMIF(shitje_produkteve.produkti, match_key, cilindra_sasia)
 *   Col G (Stoku aktual) = SUM(depo.sasia for product) - total_sold
 *   Special: Cilinder 10kg has -620 manual adjustment in Excel
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Multi-select column filters
$fDepoProdukti = getFilterParam('f_produkti');

$depoWhere = [];
$depoParams = [];
if ($fDepoProdukti) { $fin = buildFilterIn($fDepoProdukti, 'produkti'); $depoWhere[] = $fin['sql']; $depoParams = array_merge($depoParams, $fin['params']); }
$depoWhereSQL = $depoWhere ? 'WHERE ' . implode(' AND ', $depoWhere) : '';

// Distinct products for filter
$depoProduktet = $db->query("SELECT DISTINCT produkti FROM depo WHERE produkti IS NOT NULL ORDER BY produkti")->fetchAll(PDO::FETCH_COLUMN);

// All depo entries (with filters)
$depoStmt = $db->prepare("SELECT * FROM depo {$depoWhereSQL} ORDER BY data ASC, id ASC");
$depoStmt->execute($depoParams);
$rows = $depoStmt->fetchAll();

// Mapping from depo product names to shitje_produkteve search keys
// Based on Excel SUMIF formulas — uses LIKE '%key%' for flexible matching
$searchKeyMap = [
    'cilinder 10kg' => 'cilinder',
    'rregullator i thjeshte' => 'rregullator i thjeshte',
    'rregullator me ore' => 'rregullator me ore',
    'gyp 1 m' => 'gyp 1 m',
    'gyp 0.5 m' => 'kabel 0.5 m',
    'gyp 0.75 m' => 'gyp 0.75 m',
];

// Auto-detect: for any depo product NOT in searchKeyMap, try matching by name
$allDepoProducts = $db->query("SELECT DISTINCT LOWER(produkti) as prod FROM depo WHERE produkti IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($allDepoProducts as $prod) {
    if (!isset($searchKeyMap[$prod])) {
        $searchKeyMap[$prod] = $prod;
    }
}

// Total sold per product from shitje_produkteve using LIKE matching
// Each search key is matched with LIKE '%key%' to mirror Excel SUMIF behavior
$soldByProduct = [];
$soldStmt = $db->prepare("SELECT COALESCE(SUM(cilindra_sasia), 0) FROM shitje_produkteve WHERE LOWER(produkti) LIKE ?");
foreach (array_unique(array_values($searchKeyMap)) as $searchKey) {
    $soldStmt->execute(['%' . strtolower($searchKey) . '%']);
    $soldByProduct[$searchKey] = (int)$soldStmt->fetchColumn();
}

// Manual adjustments from Excel (hardcoded in formulas like G2=D2-F2-620)
// -620 = cylinders already in the field before tracking started (historical offset)
// NOTE: If this number needs updating, change it here
$manualAdjustments = [
    'cilinder 10kg' => -620,
];

// Product summary grouped by produkti
$productSummary = $db->query("
    SELECT MIN(produkti) as produkti, SUM(sasia) as total_sasia
    FROM depo
    GROUP BY LOWER(produkti)
    ORDER BY MIN(produkti)
")->fetchAll();

// Compute sold and stock for each product
foreach ($productSummary as &$p) {
    $key = strtolower($p['produkti']);
    $searchKey = $searchKeyMap[$key] ?? $key;
    $p['total_sold'] = (int)($soldByProduct[$searchKey] ?? 0);
    $adjustment = $manualAdjustments[$key] ?? 0;
    $p['stoku_aktual'] = (int)$p['total_sasia'] - $p['total_sold'] + $adjustment;
    $p['has_formula'] = isset($searchKeyMap[$key]);
}
unset($p);

$totalSasia = array_sum(array_column($productSummary, 'total_sasia'));
$totalSold = array_sum(array_column($productSummary, 'total_sold'));

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Produkte unikë</div>
        <div class="value"><?= count($productSummary) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total hyrje stoku</div>
        <div class="value"><?= num($totalSasia) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total të shitura</div>
        <div class="value"><?= num($totalSold) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total lëvizje</div>
        <div class="value"><?= num(count($rows)) ?></div>
    </div>
</div>

<!-- Product Stock Summary -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-warehouse"></i> Gjendja e Stokut - Depo</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="clientSortColumn(this, 0)" style="cursor:pointer;user-select:none;">Produkti <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 1)" style="cursor:pointer;user-select:none;">Sasia totale (hyrje) <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 2)" style="cursor:pointer;user-select:none;color:var(--primary);">Total të shitura <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 3)" style="cursor:pointer;user-select:none;font-weight:700;">Stoku aktual <i class="fas fa-sort"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productSummary as $p): ?>
                    <tr>
                        <td style="font-weight:600;"><?= e($p['produkti']) ?></td>
                        <td class="num"><?= num($p['total_sasia']) ?></td>
                        <td class="num" style="color:var(--primary);font-weight:600;">
                            <?= $p['has_formula'] ? num($p['total_sold']) : '-' ?>
                        </td>
                        <td class="num" style="font-weight:700;color:<?= $p['stoku_aktual'] > 0 ? 'var(--success)' : ($p['stoku_aktual'] < 0 ? 'var(--danger)' : 'inherit') ?>;">
                            <?= $p['has_formula'] ? num($p['stoku_aktual']) : '-' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- All Stock Entries -->
<div class="card">
    <div class="card-header">
        <h3>Hyrjet e stokut</h3>
        <button class="btn btn-primary btn-sm" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Shto</button>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="depo">
                <thead>
                    <tr>
                        <th class="server-sort" onclick="clientSortColumn(this, 0)" style="cursor:pointer;user-select:none;">Data <i class="fas fa-sort"></i></th>
                        <th class="server-sort" data-filter="f_produkti" data-filter-values="<?= e(json_encode($depoProduktet, JSON_UNESCAPED_UNICODE)) ?>" onclick="clientSortColumn(this, 1)" style="cursor:pointer;user-select:none;">Produkti <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 2)" style="cursor:pointer;user-select:none;">Sasia <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 3)" style="cursor:pointer;user-select:none;">Çmimi <i class="fas fa-sort"></i></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?: '-' ?></td>
                        <td class="editable" data-field="produkti"><?= e($r['produkti']) ?></td>
                        <td class="num editable" data-field="sasia" data-type="number"><?= (int)$r['sasia'] ?></td>
                        <td class="amount editable" data-field="cmimi" data-type="number"><?= $r['cmimi'] ? '&euro; ' . eur($r['cmimi']) : '-' ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('depo',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
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
        <div class="modal-header"><h3>Shto hyrje stoku</h3><button class="btn btn-outline btn-sm" onclick="closeModal('addModal')">&times;</button></div>
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="depo">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label>Data</label><input type="date" name="data" value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group">
                        <label>Produkti *</label>
                        <input type="text" name="produkti" required list="prodList">
                        <datalist id="prodList">
                            <?php foreach ($productSummary as $p): ?><option value="<?= e($p['produkti']) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Sasia *</label><input type="number" name="sasia" required></div>
                    <div class="form-group"><label>Çmimi</label><input type="number" name="cmimi" step="0.01"></div>
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
    th.closest('tr').querySelectorAll('th.server-sort i.fas').forEach(i => { i.className = 'fas fa-sort'; });
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
renderLayout('Depo', 'depo', $content);
