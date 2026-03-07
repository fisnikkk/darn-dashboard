<?php
/**
 * DARN Dashboard - Plini Depo (Gas Depot/Purchases)
 * Input form with dropdowns for payment method, supplier
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, [100, 500, 1000, 5000])) $perPage = 100;
$offset = ($page - 1) * $perPage;

// Server-side sorting
$sortCol = $_GET['sort'] ?? 'data';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
$allowedSorts = ['data','nr_i_fatures','kg','cmimi','faturat_e_pranuara','dalje_pagesat_sipas_bankes','menyra_e_pageses','cash_banke','furnitori','koment','sasia_ne_litra','gjendja'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'data';
if (!in_array($sortDir, ['ASC','DESC'])) $sortDir = 'DESC';

// Map sort column to SQL expression (calculated column uses CTE alias)
$calcSortsPD = ['gjendja' => 'bal.gjendja'];
$sortExpr = isset($calcSortsPD[$sortCol]) ? $calcSortsPD[$sortCol] : "p.{$sortCol}";

function sortThPD($col, $label, $currentSort, $currentDir, $class = '') {
    $isActive = ($currentSort === $col);
    $newDir = ($isActive && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($_GET, ['sort' => $col, 'dir' => $newDir, 'page' => 1]);
    $url = '?' . http_build_query($params);
    $icon = $isActive ? ($currentDir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $activeStyle = $isActive ? 'color:var(--primary);font-weight:600;' : '';
    $classes = trim(($class ? $class . ' ' : '') . 'server-sort');
    return "<th class=\"{$classes}\" onclick=\"window.location.href='{$url}';return false;\" style=\"cursor:pointer;user-select:none;{$activeStyle}\">{$label} <i class=\"fas {$icon}\"></i></th>";
}

// Multi-select column filters
$fPdMenyra = getFilterParam('f_menyra');
$fPdCash = getFilterParam('f_cash');
$fPdFurn = getFilterParam('f_furnitori');
$fPdKg = getFilterParam('f_kg');
$fPdCmimi = getFilterParam('f_cmimi');
$fPdKoment = getFilterParam('f_koment');
$fPdNrFat = getFilterParam('f_nr_fat');
$fPdFaturat = getFilterParam('f_faturat');
$fPdDalje = getFilterParam('f_dalje');
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

$pdWhere = [];
$pdParams = [];
if ($fPdMenyra) { $fin = buildFilterIn($fPdMenyra, 'menyra_e_pageses', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdCash) { $fin = buildFilterIn($fPdCash, 'cash_banke', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdFurn) { $fin = buildFilterIn($fPdFurn, 'furnitori', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdKg) { $fin = buildFilterIn($fPdKg, 'kg', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdCmimi) { $fin = buildFilterIn($fPdCmimi, 'cmimi', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdKoment) { $fin = buildFilterIn($fPdKoment, 'koment', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdNrFat) { $fin = buildFilterIn($fPdNrFat, 'nr_i_fatures', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdFaturat) { $fin = buildFilterIn($fPdFaturat, 'faturat_e_pranuara', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdDalje) { $fin = buildFilterIn($fPdDalje, 'dalje_pagesat_sipas_bankes', 'p'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($filterDateFrom) { $pdWhere[] = "p.data >= ?"; $pdParams[] = $filterDateFrom; }
if ($filterDateTo) { $pdWhere[] = "p.data <= ?"; $pdParams[] = $filterDateTo; }
$pdWhereSQL = $pdWhere ? 'WHERE ' . implode(' AND ', $pdWhere) : '';

$cntStmt = $db->prepare("SELECT COUNT(*) FROM plini_depo p {$pdWhereSQL}");
$cntStmt->execute($pdParams);
$totalRows = $cntStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// Fetch data with running balance via CTE — enables server-side sorting across ALL pages
$pdSQL = "
    WITH balances AS (
        SELECT id,
            SUM(COALESCE(faturat_e_pranuara, 0) - COALESCE(dalje_pagesat_sipas_bankes, 0)) OVER (
                ORDER BY data ASC, id ASC
            ) as gjendja
        FROM plini_depo
    )
    SELECT p.*, bal.gjendja
    FROM plini_depo p
    JOIN balances bal ON bal.id = p.id
    {$pdWhereSQL}
    ORDER BY {$sortExpr} {$sortDir}, p.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$rowsStmt = $db->prepare($pdSQL);
$rowsStmt->execute($pdParams);
$rows = $rowsStmt->fetchAll();

$sumDateWhere = '';
$sumDateParams = [];
if ($filterDateFrom) { $sumDateWhere .= " AND data >= ?"; $sumDateParams[] = $filterDateFrom; }
if ($filterDateTo) { $sumDateWhere .= " AND data <= ?"; $sumDateParams[] = $filterDateTo; }

$stmt = $db->prepare("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo WHERE 1=1 {$sumDateWhere}");
$stmt->execute($sumDateParams);
$totalBlerje = $stmt->fetchColumn();
$stmt = $db->prepare("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='me fature' {$sumDateWhere}");
$stmt->execute($sumDateParams);
$blerjeFature = $stmt->fetchColumn();
$stmt = $db->prepare("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='pa fature' {$sumDateWhere}");
$stmt->execute($sumDateParams);
$blerjePaFature = $stmt->fetchColumn();
$stmt = $db->prepare("SELECT COALESCE(SUM(kg),0) FROM plini_depo WHERE 1=1 {$sumDateWhere}");
$stmt->execute($sumDateParams);
$totalKg = $stmt->fetchColumn();

$menyratPag = ['Me fature', 'Pa fature'];
$cashBanke = ['Cash', 'Banke'];
$furnitoret = $db->query("SELECT DISTINCT furnitori FROM plini_depo WHERE furnitori IS NOT NULL AND furnitori != '' ORDER BY furnitori")->fetchAll(PDO::FETCH_COLUMN);
$pdKgVals = $db->query("SELECT DISTINCT kg FROM plini_depo WHERE kg IS NOT NULL ORDER BY kg")->fetchAll(PDO::FETCH_COLUMN);
$pdCmimiVals = array_values(array_unique(array_map(fn($v) => number_format((float)$v, 2), $db->query("SELECT DISTINCT cmimi FROM plini_depo WHERE cmimi IS NOT NULL ORDER BY cmimi")->fetchAll(PDO::FETCH_COLUMN))));
$pdKomentVals = $db->query("SELECT DISTINCT koment FROM plini_depo WHERE koment IS NOT NULL AND koment != '' ORDER BY koment")->fetchAll(PDO::FETCH_COLUMN);
$pdNrFatVals = $db->query("SELECT DISTINCT nr_i_fatures FROM plini_depo WHERE nr_i_fatures IS NOT NULL AND nr_i_fatures != '' ORDER BY nr_i_fatures")->fetchAll(PDO::FETCH_COLUMN);
$pdFaturatVals = $db->query("SELECT DISTINCT CAST(faturat_e_pranuara AS CHAR) FROM plini_depo WHERE faturat_e_pranuara IS NOT NULL ORDER BY faturat_e_pranuara")->fetchAll(PDO::FETCH_COLUMN);
$pdDaljeVals = $db->query("SELECT DISTINCT CAST(dalje_pagesat_sipas_bankes AS CHAR) FROM plini_depo WHERE dalje_pagesat_sipas_bankes IS NOT NULL ORDER BY dalje_pagesat_sipas_bankes")->fetchAll(PDO::FETCH_COLUMN);

$menyraJSON = json_encode($menyratPag);
$cbJSON = json_encode($cashBanke);

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total Blerje</div>
        <div class="value">&euro; <?= eur($totalBlerje) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Blerje me faturë</div>
        <div class="value">&euro; <?= eur($blerjeFature) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Blerje pa faturë</div>
        <div class="value">&euro; <?= eur($blerjePaFature) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total kg të blera</div>
        <div class="value"><?= eur($totalKg) ?> kg</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i> Shto blerje plini</h3>
    </div>
    <div class="card-body padded">
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="plini_depo">
            <div class="form-row">
                <div class="form-group">
                    <label>Nr. Faturës</label>
                    <input type="text" name="nr_i_fatures">
                </div>
                <div class="form-group">
                    <label>Data *</label>
                    <input type="date" name="data" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>kg *</label>
                    <input type="number" name="kg" step="0.01" required id="pd_kg">
                </div>
                <div class="form-group">
                    <label>Litra <small style="color:var(--text-muted);">auto: kg × 1.95</small></label>
                    <input type="number" name="sasia_ne_litra" step="0.01" id="pd_litra">
                </div>
                <div class="form-group">
                    <label>Çmimi</label>
                    <input type="number" name="cmimi" step="0.01" id="pd_cmimi">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Faturat e pranuara (€) <small style="color:var(--text-muted);">auto: kg × çmimi</small></label>
                    <input type="number" name="faturat_e_pranuara" step="0.01" id="pd_faturat">
                </div>
                <div class="form-group">
                    <label>Dalje/Pagesa sipas bankes (€)</label>
                    <input type="number" name="dalje_pagesat_sipas_bankes" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label>Mënyra e pagesës *</label>
                    <select name="menyra_e_pageses" required>
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($menyratPag as $m): ?>
                        <option value="<?= e($m) ?>"><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Cash/Banke</label>
                    <select name="cash_banke">
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($cashBanke as $cb): ?>
                        <option value="<?= e($cb) ?>"><?= e($cb) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Furnitori</label>
                    <input type="text" name="furnitori" list="furnList">
                    <datalist id="furnList">
                        <?php foreach ($furnitoret as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Koment</label>
                    <input type="text" name="koment">
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Plini Depo (<?= num($totalRows) ?> rreshta)</h3></div>
    <div class="filters">
        <form method="GET" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">
            <?php if ($sortCol !== 'data' || $sortDir !== 'DESC'): ?>
            <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
            <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
            <?php endif; ?>
            <div class="form-group" style="min-width:auto;">
                <label>Data nga</label>
                <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>" style="padding:6px 8px;">
            </div>
            <div class="form-group" style="min-width:auto;">
                <label>Data deri</label>
                <input type="date" name="date_to" value="<?= e($filterDateTo) ?>" style="padding:6px 8px;">
            </div>
            <div class="form-group" style="min-width:auto;">
                <label>Rreshta</label>
                <select name="per_page" style="width:70px;padding:6px 8px;">
                    <option value="100" <?= $perPage==100?'selected':'' ?>>100</option>
                    <option value="500" <?= $perPage==500?'selected':'' ?>>500</option>
                    <option value="1000" <?= $perPage==1000?'selected':'' ?>>1000</option>
                    <option value="5000" <?= $perPage==5000?'selected':'' ?>>5000</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtro</button>
            <a href="plini_depo.php" class="btn btn-outline btn-sm">Pastro</a>
        </form>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="plini_depo" data-server-sort="true">
                <thead>
                    <tr>
                        <?= withFilter(sortThPD('nr_i_fatures', 'Nr. Faturës', $sortCol, $sortDir), 'f_nr_fat', $pdNrFatVals) ?>
                        <?= sortThPD('data', 'Data', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThPD('kg', 'kg', $sortCol, $sortDir, 'num'), 'f_kg', $pdKgVals) ?>
                        <?= sortThPD('sasia_ne_litra', 'Litra', $sortCol, $sortDir, 'num') ?>
                        <?= withFilter(sortThPD('cmimi', 'Çmimi', $sortCol, $sortDir, 'num'), 'f_cmimi', $pdCmimiVals) ?>
                        <?= withFilter(sortThPD('faturat_e_pranuara', 'Faturat e Pranuara', $sortCol, $sortDir, 'num'), 'f_faturat', $pdFaturatVals) ?>
                        <?= withFilter(sortThPD('dalje_pagesat_sipas_bankes', 'Dalje/Banke', $sortCol, $sortDir, 'num'), 'f_dalje', $pdDaljeVals) ?>
                        <?= withFilter(sortThPD('menyra_e_pageses', 'Mënyra', $sortCol, $sortDir), 'f_menyra', $menyratPag) ?>
                        <?= withFilter(sortThPD('cash_banke', 'Cash/Banke', $sortCol, $sortDir), 'f_cash', $cashBanke) ?>
                        <?= withFilter(sortThPD('furnitori', 'Furnitori', $sortCol, $sortDir), 'f_furnitori', $furnitoret) ?>
                        <?= withFilter(sortThPD('koment', 'Koment', $sortCol, $sortDir), 'f_koment', $pdKomentVals) ?>
                        <?= sortThPD('gjendja', 'Gjendja', $sortCol, $sortDir, 'num') ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                    $litra = $r['sasia_ne_litra'] !== null ? (float)$r['sasia_ne_litra'] : (float)$r['kg'] * 1.95;
                    ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="nr_i_fatures"><?= e($r['nr_i_fatures']) ?></td>
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="num editable" data-field="kg" data-type="number"><?= eur($r['kg']) ?></td>
                        <td class="num editable" data-field="sasia_ne_litra" data-type="number"><?= eur($litra) ?></td>
                        <td class="num editable" data-field="cmimi" data-type="number"><?= number_format((float)$r['cmimi'], 2) ?></td>
                        <td class="amount editable" data-field="faturat_e_pranuara" data-type="number"><?= eur($r['faturat_e_pranuara']) ?></td>
                        <td class="amount editable" data-field="dalje_pagesat_sipas_bankes" data-type="number"><?= eur($r['dalje_pagesat_sipas_bankes']) ?></td>
                        <td class="editable" data-field="menyra_e_pageses" data-type="select" data-options="<?= e($menyraJSON) ?>"><?= e($r['menyra_e_pageses']) ?></td>
                        <td class="editable" data-field="cash_banke" data-type="select" data-options="<?= e($cbJSON) ?>"><?= e($r['cash_banke']) ?></td>
                        <td class="editable" data-field="furnitori"><?= e($r['furnitori']) ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td class="amount" style="font-weight:600;"><?= eur($r['gjendja'] ?? 0) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('plini_depo',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div class="info">Faqja <?= $page ?>/<?= $totalPages ?></div>
            <div class="pages">
                <?php for ($i = max(1,$page-3); $i <= min($totalPages,$page+3); $i++): ?>
                <?= $i === $page ? "<span class='current'>{$i}</span>" : "<a href='?" . http_build_query(array_merge($_GET, ['page' => $i])) . "'>{$i}</a>" ?>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-calculate litra = kg × 1.95 and faturat = kg × cmimi for plini_depo add form
(function() {
    const kg = document.getElementById('pd_kg');
    const litra = document.getElementById('pd_litra');
    const cmimi = document.getElementById('pd_cmimi');
    const faturat = document.getElementById('pd_faturat');
    if (!kg || !litra || !cmimi || !faturat) return;

    let litraManual = false;
    litra.addEventListener('input', function() { litraManual = this.value !== ''; });

    function recalc() {
        const k = parseFloat(kg.value) || 0;
        const c = parseFloat(cmimi.value) || 0;
        if (k && !litraManual) litra.value = (k * 1.95).toFixed(2);
        if (k && c) faturat.value = (k * c).toFixed(2);
    }
    kg.addEventListener('input', function() { litraManual = false; recalc(); });
    cmimi.addEventListener('input', recalc);
})();
</script>

<?php
$content = ob_get_clean();
renderLayout('Plini Depo', 'plini_depo', $content);
