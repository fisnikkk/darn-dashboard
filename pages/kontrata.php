<?php
/**
 * DARN Dashboard - Kontrata (Contracts)
 * Calculated: Col F (boca sipas distribuimit), Col G (comparison), Col T (dite pa marr gaz)
 * Extra: average cylinders per month per client
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, [100, 500, 1000, 5000])) $perPage = 100;
$offset = ($page - 1) * $perPage;
$filterBash = $_GET['bashkepunim'] ?? '';
// Multi-select column filters
$fBashk = getFilterParam('f_bashk');
$fQyteti = getFilterParam('f_qyteti');
$fNameDb = getFilterParam('f_name_db');
$fRruga = getFilterParam('f_rruga');
$fPerfaq = getFilterParam('f_perfaq');
$fLlojiBoca = getFilterParam('f_lloji_boca');
$fGrupNjoft = getFilterParam('f_grup_njoft');
$fStok = getFilterParam('f_stok');
$fPda = getFilterParam('f_pda');
$fKontrateVjeter = getFilterParam('f_kontrate_vjeter');
$fBocatPag = getFilterParam('f_bocat_pag');
$fKoment = getFilterParam('f_koment');

// Server-side sorting
$sortCol = $_GET['sort'] ?? 'nr_i_kontrates';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
$allowedSorts = ['nr_i_kontrates','data','biznesi','name_from_database','numri_ne_stok_sipas_kontrates','bashkepunim','qyteti','rruga','numri_unik','perfaqesuesi','nr_telefonit','email','koment','lloji_i_bocave','ne_grup_njoftues','kontrate_e_vjeter','bocat_e_paguara','data_rregullatoret','sipas_skenimit_pda','calc_boca_dist','calc_diff','calc_dite','calc_avg'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'nr_i_kontrates';
if (!in_array($sortDir, ['ASC','DESC'])) $sortDir = 'DESC';

function sortThKt($col, $label, $currentSort, $currentDir, $class = '') {
    $isActive = ($currentSort === $col);
    $newDir = ($isActive && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($_GET, ['sort' => $col, 'dir' => $newDir, 'page' => 1]);
    $url = '?' . http_build_query($params);
    $icon = $isActive ? ($currentDir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $activeStyle = $isActive ? 'color:var(--primary);font-weight:600;' : '';
    $classes = trim(($class ? $class . ' ' : '') . 'server-sort');
    return "<th class=\"{$classes}\" onclick=\"window.location.href='{$url}';return false;\" style=\"cursor:pointer;user-select:none;{$activeStyle}\">{$label} <i class=\"fas {$icon}\"></i></th>";
}

$whereArr = [];
$params = [];
if ($filterBash) { $whereArr[] = "LOWER(TRIM(bashkepunim)) = LOWER(TRIM(?))"; $params[] = $filterBash; }
if ($fBashk) { $fin = buildFilterIn($fBashk, 'bashkepunim'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fQyteti) { $fin = buildFilterIn($fQyteti, 'qyteti'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fNameDb) { $fin = buildFilterIn($fNameDb, 'name_from_database'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fRruga) { $fin = buildFilterIn($fRruga, 'rruga'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fPerfaq) { $fin = buildFilterIn($fPerfaq, 'perfaqesuesi'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fLlojiBoca) { $fin = buildFilterIn($fLlojiBoca, 'lloji_i_bocave'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fGrupNjoft) { $fin = buildFilterIn($fGrupNjoft, 'ne_grup_njoftues'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fStok) { $fin = buildFilterIn($fStok, 'CAST(numri_ne_stok_sipas_kontrates AS CHAR)'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fPda) { $fin = buildFilterIn($fPda, 'sipas_skenimit_pda'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fKontrateVjeter) { $fin = buildFilterIn($fKontrateVjeter, 'kontrate_e_vjeter'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fBocatPag) { $fin = buildFilterIn($fBocatPag, 'bocat_e_paguara'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fKoment) { $fin = buildFilterIn($fKoment, 'koment'); $whereArr[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
$where = $whereArr ? 'WHERE ' . implode(' AND ', $whereArr) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM kontrata k {$where}");
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// Map sort column to SQL expression (calculated columns are aliases, regular columns need k. prefix)
$calcSortCols = ['calc_boca_dist', 'calc_diff', 'calc_dite', 'calc_avg'];
$sortExpr = in_array($sortCol, $calcSortCols) ? $sortCol : "k.{$sortCol}";

// Main query with LEFT JOINs for calculated columns — enables server-side sorting across ALL pages
$stmt = $db->prepare("
    SELECT k.*,
        COALESCE(boca_sub.boca, 0) as calc_boca_dist,
        (CAST(k.numri_ne_stok_sipas_kontrates AS SIGNED) - COALESCE(boca_sub.boca, 0)) as calc_diff,
        dite_sub.dite as calc_dite,
        dite_sub.avg_month as calc_avg
    FROM kontrata k
    LEFT JOIN (
        SELECT LOWER(klienti) as kl, SUM(sasia) - SUM(boca_te_kthyera) AS boca
        FROM distribuimi GROUP BY LOWER(klienti)
    ) boca_sub ON boca_sub.kl = LOWER(COALESCE(NULLIF(k.name_from_database, ''), k.biznesi))
    LEFT JOIN (
        SELECT LOWER(klienti) as kl,
            DATEDIFF(CURDATE(), MAX(data)) as dite,
            ROUND(SUM(sasia) / GREATEST(TIMESTAMPDIFF(MONTH, MIN(data), MAX(data)), 1), 1) as avg_month
        FROM distribuimi WHERE sasia > 0
        GROUP BY LOWER(klienti)
    ) dite_sub ON dite_sub.kl = LOWER(COALESCE(NULLIF(k.name_from_database, ''), k.biznesi))
    {$where}
    ORDER BY {$sortExpr} {$sortDir}, k.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Collect distinct calculated values for client-side filters from query results
$distValues = [];
$diffValues = [];
$diteValues = [];
$avgValues = [];
foreach ($rows as $r) {
    $bocaDist = (int)$r['calc_boca_dist'];
    $diff = (int)$r['calc_diff'];
    $distValues[(string)$bocaDist] = true;
    $diffValues[(string)$diff] = true;
    $dite = $r['calc_dite'] !== null ? abs((int)$r['calc_dite']) : null;
    $avg = $r['calc_avg'] ?? null;
    if ($dite !== null) $diteValues[(string)$dite . ' ditë'] = true;
    if ($avg !== null) $avgValues[(string)$avg] = true;
}
$distFilterVals = array_keys($distValues);
usort($distFilterVals, function($a, $b) { return (int)$a - (int)$b; });
$diffFilterVals = array_keys($diffValues);
usort($diffFilterVals, function($a, $b) { return (int)$a - (int)$b; });
$diteFilterVals = array_keys($diteValues);
usort($diteFilterVals, function($a, $b) { return (int)$a - (int)$b; });
$avgFilterVals = array_keys($avgValues);
usort($avgFilterVals, function($a, $b) { return (float)$a - (float)$b; });

// Distinct values for column filters (all include blank option for "(Bosh)" filter)
$bashkValues = $db->query("SELECT DISTINCT bashkepunim FROM kontrata WHERE bashkepunim IS NOT NULL AND bashkepunim != '' ORDER BY bashkepunim")->fetchAll(PDO::FETCH_COLUMN);
$qytetValues = $db->query("SELECT DISTINCT qyteti FROM kontrata WHERE qyteti IS NOT NULL AND qyteti != '' ORDER BY qyteti")->fetchAll(PDO::FETCH_COLUMN);
$nameDbVals = $db->query("SELECT DISTINCT name_from_database FROM kontrata WHERE name_from_database IS NOT NULL AND name_from_database != '' ORDER BY name_from_database")->fetchAll(PDO::FETCH_COLUMN);
$rrugaVals = $db->query("SELECT DISTINCT rruga FROM kontrata WHERE rruga IS NOT NULL AND rruga != '' ORDER BY rruga")->fetchAll(PDO::FETCH_COLUMN);
$perfaqVals = $db->query("SELECT DISTINCT perfaqesuesi FROM kontrata WHERE perfaqesuesi IS NOT NULL AND perfaqesuesi != '' ORDER BY perfaqesuesi")->fetchAll(PDO::FETCH_COLUMN);
$llojiBocaVals = $db->query("SELECT DISTINCT lloji_i_bocave FROM kontrata WHERE lloji_i_bocave IS NOT NULL AND lloji_i_bocave != '' ORDER BY lloji_i_bocave")->fetchAll(PDO::FETCH_COLUMN);
$grupNjoftVals = $db->query("SELECT DISTINCT ne_grup_njoftues FROM kontrata WHERE ne_grup_njoftues IS NOT NULL AND ne_grup_njoftues != '' ORDER BY ne_grup_njoftues")->fetchAll(PDO::FETCH_COLUMN);
$stokVals = $db->query("SELECT DISTINCT CAST(numri_ne_stok_sipas_kontrates AS CHAR) as v FROM kontrata WHERE numri_ne_stok_sipas_kontrates IS NOT NULL ORDER BY numri_ne_stok_sipas_kontrates+0")->fetchAll(PDO::FETCH_COLUMN);
$pdaVals = $db->query("SELECT DISTINCT sipas_skenimit_pda FROM kontrata WHERE sipas_skenimit_pda IS NOT NULL AND sipas_skenimit_pda != '' ORDER BY sipas_skenimit_pda")->fetchAll(PDO::FETCH_COLUMN);
$kontrateVjeterVals = $db->query("SELECT DISTINCT kontrate_e_vjeter FROM kontrata WHERE kontrate_e_vjeter IS NOT NULL AND kontrate_e_vjeter != '' ORDER BY kontrate_e_vjeter")->fetchAll(PDO::FETCH_COLUMN);
$bocatPagVals = $db->query("SELECT DISTINCT bocat_e_paguara FROM kontrata WHERE bocat_e_paguara IS NOT NULL AND bocat_e_paguara != '' ORDER BY bocat_e_paguara")->fetchAll(PDO::FETCH_COLUMN);
$komentVals = $db->query("SELECT DISTINCT koment FROM kontrata WHERE koment IS NOT NULL AND koment != '' ORDER BY koment")->fetchAll(PDO::FETCH_COLUMN);
// Add blank option to all filter arrays
foreach ([&$bashkValues, &$qytetValues, &$nameDbVals, &$rrugaVals, &$perfaqVals, &$llojiBocaVals,
          &$grupNjoftVals, &$stokVals, &$pdaVals, &$kontrateVjeterVals, &$bocatPagVals, &$komentVals] as &$arr) {
    if (!in_array('', $arr)) array_unshift($arr, '');
}

// Helper: add client-side filter attributes to a server-sorted th element
function withClientFilter($thHtml, $filterName, $filterValues, $colIdx) {
    $attrs = ' data-filter="' . e($filterName) . '"'
        . ' data-filter-values="' . e(json_encode($filterValues, JSON_UNESCAPED_UNICODE)) . '"'
        . ' data-filter-mode="client"'
        . ' data-filter-col="' . (int)$colIdx . '"';
    return preg_replace('/<th\b/', '<th' . $attrs, $thHtml, 1);
}

ob_start();
?>
<style>
    .data-table[data-table="kontrata"] {
        table-layout: fixed;
        width: 100%;
    }
    .data-table[data-table="kontrata"] td,
    .data-table[data-table="kontrata"] th {
        padding: 3px 4px;
        font-size: 0.78rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .data-table[data-table="kontrata"] th { font-size: 0.72rem; }
    .data-table[data-table="kontrata"] col.col-nr { width: 40px; }
    .data-table[data-table="kontrata"] col.col-data { width: 75px; }
    .data-table[data-table="kontrata"] col.col-emri { width: 130px; }
    .data-table[data-table="kontrata"] col.col-num { width: 45px; }
    .data-table[data-table="kontrata"] col.col-text { width: 90px; }
    .data-table[data-table="kontrata"] col.col-email { width: 120px; }
    .data-table[data-table="kontrata"] col.col-koment { width: 100px; }
    .data-table[data-table="kontrata"] col.col-actions { width: 60px; }
</style>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total Kontrata</div>
        <div class="value"><?= num($totalRows) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Bashkëpunime aktive</div>
        <div class="value"><?= $db->query("SELECT COUNT(*) FROM kontrata WHERE LOWER(TRIM(bashkepunim))='po'")->fetchColumn() ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Kontrata</h3>
        <button class="btn btn-primary btn-sm" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Shto</button>
    </div>
    <div class="filters">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <?php if ($sortCol !== 'nr_i_kontrates' || $sortDir !== 'DESC'): ?>
            <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
            <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
            <?php endif; ?>
            <div class="form-group">
                <label>Bashkëpunim</label>
                <select name="bashkepunim">
                    <option value="">Të gjitha</option>
                    <option value="po" <?= $filterBash==='po'?'selected':'' ?>>Po</option>
                    <option value="jo" <?= $filterBash==='jo'?'selected':'' ?>>Jo</option>
                </select>
            </div>
            <div class="form-group">
                <label>Rreshta/faqe</label>
                <select name="per_page">
                    <option value="100" <?= $perPage==100?'selected':'' ?>>100</option>
                    <option value="500" <?= $perPage==500?'selected':'' ?>>500</option>
                    <option value="1000" <?= $perPage==1000?'selected':'' ?>>1000</option>
                    <option value="5000" <?= $perPage==5000?'selected':'' ?>>5000</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Filtro</button>
            <a href="kontrata.php" class="btn btn-outline btn-sm">Pastro</a>
        </form>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="kontrata" data-server-sort="true">
                <colgroup>
                    <col class="col-nr"><!-- Nr -->
                    <col class="col-data"><!-- Data -->
                    <col class="col-emri"><!-- Emri -->
                    <col class="col-num"><!-- Stok kontrate -->
                    <col class="col-num"><!-- Sipas distribuimit -->
                    <col class="col-num"><!-- Diferenca -->
                    <col class="col-num"><!-- Skenimi PDA -->
                    <col class="col-text"><!-- Bashkepunim -->
                    <col class="col-text"><!-- Qyteti -->
                    <col class="col-text"><!-- Rruga -->
                    <col class="col-text"><!-- Nr Unik -->
                    <col class="col-text"><!-- Perfaqesuesi -->
                    <col class="col-text"><!-- Tel -->
                    <col class="col-email"><!-- Email -->
                    <col class="col-text"><!-- Grup njoftues -->
                    <col class="col-text"><!-- Kontrate e vjeter -->
                    <col class="col-text"><!-- Lloji bocave -->
                    <col class="col-text"><!-- Bocat e paguara -->
                    <col class="col-data"><!-- Data rregullatoret -->
                    <col class="col-num"><!-- Dite pa marre -->
                    <col class="col-num"><!-- Mesatare/muaj -->
                    <col class="col-koment"><!-- Koment -->
                    <col class="col-actions"><!-- Actions -->
                </colgroup>
                <thead>
                    <tr>
                        <?= sortThKt('nr_i_kontrates', 'Nr', $sortCol, $sortDir) ?>
                        <?= sortThKt('data', 'Data', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThKt('name_from_database', 'Emri', $sortCol, $sortDir), 'f_name_db', $nameDbVals) ?>
                        <?= withFilter(sortThKt('numri_ne_stok_sipas_kontrates', 'Stok kontratë', $sortCol, $sortDir, 'num'), 'f_stok', $stokVals) ?>
                        <?= withClientFilter(sortThKt('calc_boca_dist', 'Sipas distribuimit', $sortCol, $sortDir, 'num'), 'f_sipas_dist', $distFilterVals, 4) ?>
                        <?= withClientFilter(sortThKt('calc_diff', 'Diferencë', $sortCol, $sortDir, 'num'), 'f_diferenca', $diffFilterVals, 5) ?>
                        <?= withFilter(sortThKt('sipas_skenimit_pda', 'Skenimi PDA', $sortCol, $sortDir), 'f_pda', $pdaVals) ?>
                        <?= withFilter(sortThKt('bashkepunim', 'Bashkëpunim', $sortCol, $sortDir), 'f_bashk', $bashkValues) ?>
                        <?= withFilter(sortThKt('qyteti', 'Qyteti', $sortCol, $sortDir), 'f_qyteti', $qytetValues) ?>
                        <?= withFilter(sortThKt('rruga', 'Rruga', $sortCol, $sortDir), 'f_rruga', $rrugaVals) ?>
                        <?= sortThKt('numri_unik', 'Nr. Unik', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThKt('perfaqesuesi', 'Përfaqësuesi', $sortCol, $sortDir), 'f_perfaq', $perfaqVals) ?>
                        <?= sortThKt('nr_telefonit', 'Tel.', $sortCol, $sortDir) ?>
                        <?= sortThKt('email', 'Email', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThKt('ne_grup_njoftues', 'Grup njoftues', $sortCol, $sortDir), 'f_grup_njoft', $grupNjoftVals) ?>
                        <?= withFilter(sortThKt('kontrate_e_vjeter', 'Kontratë e vjetër', $sortCol, $sortDir), 'f_kontrate_vjeter', $kontrateVjeterVals) ?>
                        <?= withFilter(sortThKt('lloji_i_bocave', 'Lloji bocave', $sortCol, $sortDir), 'f_lloji_boca', $llojiBocaVals) ?>
                        <?= withFilter(sortThKt('bocat_e_paguara', 'Bocat e paguara', $sortCol, $sortDir), 'f_bocat_pag', $bocatPagVals) ?>
                        <?= sortThKt('data_rregullatoret', 'Data rregullatorët', $sortCol, $sortDir) ?>
                        <?php $thDite = withClientFilter(sortThKt('calc_dite', 'Ditë pa marrë', $sortCol, $sortDir, 'num'), 'f_dite_pamarr', $diteFilterVals, 19);
                        echo str_replace('user-select:none;', 'user-select:none;color:var(--danger);', $thDite); ?>
                        <?= withClientFilter(sortThKt('calc_avg', 'Mesatare/muaj', $sortCol, $sortDir, 'num'), 'f_mesatare', $avgFilterVals, 20) ?>
                        <?= withFilter(sortThKt('koment', 'Koment', $sortCol, $sortDir), 'f_koment', $komentVals) ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $bocaDist = (int)$r['calc_boca_dist'];
                        $diff = (int)$r['calc_diff'];
                        $dite = $r['calc_dite'] !== null ? abs((int)$r['calc_dite']) : null;
                        $avg = $r['calc_avg'] !== null ? $r['calc_avg'] : '-';
                    ?>
                    <tr data-id="<?= $r['id'] ?>" <?= $dite && $dite > 90 ? 'style="background:#fde2e2;"' : ($dite && $dite > 60 ? 'style="background:#fef2f2;"' : ($dite && $dite > 30 ? 'style="background:#fff8f0;"' : '')) ?>>
                        <td><?= $r['nr_i_kontrates'] ?></td>
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="editable" data-field="name_from_database" style="color:var(--primary);font-weight:500;"><?= e($r['name_from_database']) ?></td>
                        <td class="num editable" data-field="numri_ne_stok_sipas_kontrates" data-type="number"><?= (int)$r['numri_ne_stok_sipas_kontrates'] ?></td>
                        <td class="num" style="font-weight:600;color:var(--primary);"><?= (int)$bocaDist ?></td>
                        <td class="num" style="color:<?= $diff > 0 ? 'var(--danger)' : ($diff < 0 ? 'var(--warning)' : 'var(--success)') ?>;font-weight:600;">
                            <?= $diff ?>
                        </td>
                        <td class="editable" data-field="sipas_skenimit_pda"><?= e($r['sipas_skenimit_pda'] ?? '') ?></td>
                        <td class="editable" data-field="bashkepunim" data-type="select" data-options="<?= e(json_encode(['po','jo'])) ?>">
                            <?= e($r['bashkepunim']) ?>
                        </td>
                        <td class="editable" data-field="qyteti"><?= e($r['qyteti']) ?></td>
                        <td class="editable" data-field="rruga"><?= e($r['rruga']) ?></td>
                        <td class="editable" data-field="numri_unik"><?= e($r['numri_unik']) ?></td>
                        <td class="editable" data-field="perfaqesuesi"><?= e($r['perfaqesuesi']) ?></td>
                        <td class="editable" data-field="nr_telefonit"><?= e($r['nr_telefonit']) ?></td>
                        <td class="editable truncate" data-field="email" style="max-width:150px;" title="<?= e($r['email']) ?>"><?= e($r['email']) ?></td>
                        <td class="editable" data-field="ne_grup_njoftues"><?= e($r['ne_grup_njoftues']) ?></td>
                        <td class="editable" data-field="kontrate_e_vjeter"><?= e($r['kontrate_e_vjeter']) ?></td>
                        <td class="editable" data-field="lloji_i_bocave"><?= e($r['lloji_i_bocave']) ?></td>
                        <td class="editable" data-field="bocat_e_paguara"><?= e($r['bocat_e_paguara']) ?></td>
                        <td class="editable" data-field="data_rregullatoret" data-type="date"><?= $r['data_rregullatoret'] ?></td>
                        <td class="num" style="font-weight:600;<?= $dite && $dite > 90 ? 'color:#991b1b;' : ($dite && $dite > 60 ? 'color:#dc2626;' : ($dite && $dite > 30 ? 'color:#f59e0b;' : '')) ?>">
                            <?= $dite !== null ? $dite . ' ditë' : '-' ?>
                        </td>
                        <td class="num"><?= $avg ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('kontrata',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
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

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header"><h3>Shto kontratë</h3><button class="btn btn-outline btn-sm" onclick="closeModal('addModal')">&times;</button></div>
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="kontrata">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label>Nr. Kontratës</label><input type="number" name="nr_i_kontrates"></div>
                    <div class="form-group"><label>Data</label><input type="date" name="data" value="<?= date('Y-m-d') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Emri / Biznesi *</label><input type="text" name="biznesi" required></div>
                    <div class="form-group"><label>Emri (DB)</label><input type="text" name="name_from_database"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Stok kontratë</label><input type="number" name="numri_ne_stok_sipas_kontrates" value="0"></div>
                    <div class="form-group"><label>Bashkëpunim</label><select name="bashkepunim"><option value="po">Po</option><option value="jo">Jo</option></select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Qyteti</label><input type="text" name="qyteti"></div>
                    <div class="form-group"><label>Rruga</label><input type="text" name="rruga"></div>
                    <div class="form-group"><label>Nr. Unik</label><input type="text" name="numri_unik"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Përfaqësuesi</label><input type="text" name="perfaqesuesi"></div>
                    <div class="form-group"><label>Tel.</label><input type="text" name="nr_telefonit"></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Grup njoftues</label><input type="text" name="ne_grup_njoftues"></div>
                    <div class="form-group"><label>Kontratë e vjetër</label><input type="text" name="kontrate_e_vjeter"></div>
                    <div class="form-group"><label>Lloji bocave</label><input type="text" name="lloji_i_bocave"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Bocat e paguara</label><input type="text" name="bocat_e_paguara"></div>
                    <div class="form-group"><label>Data rregullatorët</label><input type="date" name="data_rregullatoret"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Koment</label><input type="text" name="koment"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Anulo</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
renderLayout('Kontrata', 'kontrata', $content);
