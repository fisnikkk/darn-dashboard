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
$allowedSorts = ['data','nr_i_fatures','kg','cmimi','faturat_e_pranuara','dalje_pagesat_sipas_bankes','menyra_e_pageses','cash_banke','furnitori','koment','sasia_ne_litra'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'data';
if (!in_array($sortDir, ['ASC','DESC'])) $sortDir = 'DESC';

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

$pdWhere = [];
$pdParams = [];
if ($fPdMenyra) { $fin = buildFilterIn($fPdMenyra, 'menyra_e_pageses'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdCash) { $fin = buildFilterIn($fPdCash, 'cash_banke'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdFurn) { $fin = buildFilterIn($fPdFurn, 'furnitori'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdKg) { $fin = buildFilterIn($fPdKg, 'kg'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdCmimi) { $fin = buildFilterIn($fPdCmimi, 'cmimi'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
if ($fPdKoment) { $fin = buildFilterIn($fPdKoment, 'koment'); $pdWhere[] = $fin['sql']; $pdParams = array_merge($pdParams, $fin['params']); }
$pdWhereSQL = $pdWhere ? 'WHERE ' . implode(' AND ', $pdWhere) : '';

$cntStmt = $db->prepare("SELECT COUNT(*) FROM plini_depo {$pdWhereSQL}");
$cntStmt->execute($pdParams);
$totalRows = $cntStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$rowsStmt = $db->prepare("SELECT * FROM plini_depo {$pdWhereSQL} ORDER BY {$sortCol} {$sortDir}, id DESC LIMIT {$perPage} OFFSET {$offset}");
$rowsStmt->execute($pdParams);
$rows = $rowsStmt->fetchAll();

$totalBlerje = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo")->fetchColumn();
$blerjeFature = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='me fature'")->fetchColumn();
$blerjePaFature = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='pa fature'")->fetchColumn();
$totalKg = $db->query("SELECT COALESCE(SUM(kg),0) FROM plini_depo")->fetchColumn();

$menyratPag = ['Me fature', 'Pa fature'];
$cashBanke = ['Cash', 'Banke'];
$furnitoret = $db->query("SELECT DISTINCT furnitori FROM plini_depo WHERE furnitori IS NOT NULL AND furnitori != '' ORDER BY furnitori")->fetchAll(PDO::FETCH_COLUMN);
$pdKgVals = $db->query("SELECT DISTINCT kg FROM plini_depo WHERE kg IS NOT NULL ORDER BY kg")->fetchAll(PDO::FETCH_COLUMN);
$pdCmimiVals = $db->query("SELECT DISTINCT cmimi FROM plini_depo WHERE cmimi IS NOT NULL ORDER BY cmimi")->fetchAll(PDO::FETCH_COLUMN);
$pdKomentVals = $db->query("SELECT DISTINCT koment FROM plini_depo WHERE koment IS NOT NULL AND koment != '' ORDER BY koment LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);

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
                    <input type="number" name="kg" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Çmimi</label>
                    <input type="number" name="cmimi" step="0.0001">
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
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="plini_depo" data-server-sort="true">
                <thead>
                    <tr>
                        <?= sortThPD('data', 'Data', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThPD('kg', 'kg', $sortCol, $sortDir, 'num'), 'f_kg', $pdKgVals) ?>
                        <th class="num">Litra</th>
                        <?= withFilter(sortThPD('cmimi', 'Çmimi', $sortCol, $sortDir, 'num'), 'f_cmimi', $pdCmimiVals) ?>
                        <?= sortThPD('faturat_e_pranuara', 'Faturat', $sortCol, $sortDir, 'num') ?>
                        <?= sortThPD('dalje_pagesat_sipas_bankes', 'Dalje/Banke', $sortCol, $sortDir, 'num') ?>
                        <?= withFilter(sortThPD('menyra_e_pageses', 'Mënyra', $sortCol, $sortDir), 'f_menyra', $menyratPag) ?>
                        <?= withFilter(sortThPD('cash_banke', 'Cash/Banke', $sortCol, $sortDir), 'f_cash', $cashBanke) ?>
                        <?= withFilter(sortThPD('furnitori', 'Furnitori', $sortCol, $sortDir), 'f_furnitori', $furnitoret) ?>
                        <?= withFilter(sortThPD('koment', 'Koment', $sortCol, $sortDir), 'f_koment', $pdKomentVals) ?>
                        <th class="num">Gjendja</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $gjendja = 0;
                    // Need to recalculate running balance - fetch all ordered by date ASC
                    $allForBalance = $db->query("SELECT id, faturat_e_pranuara, dalje_pagesat_sipas_bankes FROM plini_depo ORDER BY data ASC, id ASC")->fetchAll();
                    $balances = [];
                    $running = 0;
                    foreach ($allForBalance as $b) {
                        $running += (float)$b['faturat_e_pranuara'] - (float)$b['dalje_pagesat_sipas_bankes'];
                        $balances[$b['id']] = $running;
                    }
                    
                    foreach ($rows as $r):
                    $litra = $r['sasia_ne_litra'] !== null ? (float)$r['sasia_ne_litra'] : (float)$r['kg'] * 1.95;
                    ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="num editable" data-field="kg" data-type="number"><?= eur($r['kg']) ?></td>
                        <td class="num"><?= eur($litra) ?></td>
                        <td class="num editable" data-field="cmimi" data-type="number"><?= $r['cmimi'] ?></td>
                        <td class="amount editable" data-field="faturat_e_pranuara" data-type="number"><?= eur($r['faturat_e_pranuara']) ?></td>
                        <td class="amount editable" data-field="dalje_pagesat_sipas_bankes" data-type="number"><?= eur($r['dalje_pagesat_sipas_bankes']) ?></td>
                        <td class="editable" data-field="menyra_e_pageses" data-type="select" data-options="<?= e($menyraJSON) ?>"><?= e($r['menyra_e_pageses']) ?></td>
                        <td class="editable" data-field="cash_banke" data-type="select" data-options="<?= e($cbJSON) ?>"><?= e($r['cash_banke']) ?></td>
                        <td class="editable" data-field="furnitori"><?= e($r['furnitori']) ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td class="amount" style="font-weight:600;"><?= eur($balances[$r['id']] ?? 0) ?></td>
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
// Auto-calculate faturat_e_pranuara = kg × cmimi for plini_depo add form
(function() {
    const form = document.querySelector('.ajax-form[action="/api/insert.php"]');
    if (!form) return;
    const kg = form.querySelector('[name="kg"]');
    const cmimi = form.querySelector('[name="cmimi"]');
    const faturat = document.getElementById('pd_faturat');
    if (!kg || !cmimi || !faturat) return;
    function recalcFaturat() {
        const k = parseFloat(kg.value) || 0;
        const c = parseFloat(cmimi.value) || 0;
        if (k && c) faturat.value = (k * c).toFixed(2);
    }
    [kg, cmimi].forEach(el => el.addEventListener('input', recalcFaturat));
})();
</script>

<?php
$content = ob_get_clean();
renderLayout('Plini Depo', 'plini_depo', $content);
