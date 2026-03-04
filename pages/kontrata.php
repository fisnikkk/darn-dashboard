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

// Server-side sorting
$sortCol = $_GET['sort'] ?? 'nr_i_kontrates';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
$allowedSorts = ['nr_i_kontrates','data','biznesi','name_from_database','numri_ne_stok_sipas_kontrates','bashkepunim','qyteti','rruga','numri_unik','perfaqesuesi','nr_telefonit','email','koment','lloji_i_bocave','ne_grup_njoftues','kontrate_e_vjeter','bocat_e_paguara','data_rregullatoret','sipas_skenimit_pda'];
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
$where = $whereArr ? 'WHERE ' . implode(' AND ', $whereArr) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM kontrata {$where}");
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$stmt = $db->prepare("SELECT * FROM kontrata {$where} ORDER BY {$sortCol} {$sortDir}, id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Boca tek biznesi per client (from distribuimi) — use LOWER() for case-insensitive matching
$bocaBiznesi = $db->query("SELECT LOWER(klienti) as kl, SUM(sasia)-SUM(boca_te_kthyera) AS boca FROM distribuimi GROUP BY LOWER(klienti)")->fetchAll(PDO::FETCH_KEY_PAIR);

// Days since last delivery per client — use LOWER() for case-insensitive matching
$ditePaMarr = $db->query("SELECT LOWER(klienti) as kl, MAX(data) as last_date, DATEDIFF(CURDATE(), MAX(data)) as dite FROM distribuimi WHERE sasia > 0 GROUP BY LOWER(klienti)")->fetchAll(PDO::FETCH_UNIQUE);

// Average cylinders per month per client — use LOWER() for case-insensitive matching
$avgPerMonth = $db->query("
    SELECT LOWER(klienti) as kl,
        ROUND(SUM(sasia) / GREATEST(TIMESTAMPDIFF(MONTH, MIN(data), MAX(data)), 1), 1) as avg_month
    FROM distribuimi
    WHERE sasia > 0
    GROUP BY LOWER(klienti)
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Collect distinct calculated values for client-side filters (Sipas distribuimit, Diferenca)
$distValues = [];
$diffValues = [];
foreach ($rows as $r) {
    $name = $r['name_from_database'] ?: $r['biznesi'];
    $nameKey = strtolower($name);
    $bocaDist = (int)($bocaBiznesi[$nameKey] ?? 0);
    $diff = (int)$r['numri_ne_stok_sipas_kontrates'] - $bocaDist;
    $distValues[(string)$bocaDist] = true;
    $diffValues[(string)$diff] = true;
}
$distFilterVals = array_keys($distValues);
usort($distFilterVals, function($a, $b) { return (int)$a - (int)$b; });
$diffFilterVals = array_keys($diffValues);
usort($diffFilterVals, function($a, $b) { return (int)$a - (int)$b; });

// Distinct values for column filters
$bashkValues = $db->query("SELECT DISTINCT bashkepunim FROM kontrata WHERE bashkepunim IS NOT NULL AND bashkepunim != '' ORDER BY bashkepunim")->fetchAll(PDO::FETCH_COLUMN);
$qytetValues = $db->query("SELECT DISTINCT qyteti FROM kontrata WHERE qyteti IS NOT NULL AND qyteti != '' ORDER BY qyteti")->fetchAll(PDO::FETCH_COLUMN);
$nameDbVals = $db->query("SELECT DISTINCT name_from_database FROM kontrata WHERE name_from_database IS NOT NULL AND name_from_database != '' ORDER BY name_from_database")->fetchAll(PDO::FETCH_COLUMN);
$rrugaVals = $db->query("SELECT DISTINCT rruga FROM kontrata WHERE rruga IS NOT NULL AND rruga != '' ORDER BY rruga LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
$perfaqVals = $db->query("SELECT DISTINCT perfaqesuesi FROM kontrata WHERE perfaqesuesi IS NOT NULL AND perfaqesuesi != '' ORDER BY perfaqesuesi")->fetchAll(PDO::FETCH_COLUMN);
$llojiBocaVals = $db->query("SELECT DISTINCT lloji_i_bocave FROM kontrata WHERE lloji_i_bocave IS NOT NULL AND lloji_i_bocave != '' ORDER BY lloji_i_bocave")->fetchAll(PDO::FETCH_COLUMN);
$grupNjoftVals = $db->query("SELECT DISTINCT ne_grup_njoftues FROM kontrata WHERE ne_grup_njoftues IS NOT NULL AND ne_grup_njoftues != '' ORDER BY ne_grup_njoftues")->fetchAll(PDO::FETCH_COLUMN);
$stokVals = $db->query("SELECT DISTINCT CAST(numri_ne_stok_sipas_kontrates AS CHAR) as v FROM kontrata WHERE numri_ne_stok_sipas_kontrates IS NOT NULL ORDER BY numri_ne_stok_sipas_kontrates+0")->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

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
                <thead>
                    <tr>
                        <?= sortThKt('nr_i_kontrates', 'Nr', $sortCol, $sortDir) ?>
                        <?= sortThKt('data', 'Data', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThKt('name_from_database', 'Emri', $sortCol, $sortDir), 'f_name_db', $nameDbVals) ?>
                        <?= withFilter(sortThKt('numri_ne_stok_sipas_kontrates', 'Stok kontratë', $sortCol, $sortDir, 'num'), 'f_stok', $stokVals) ?>
                        <th class="num server-sort" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;" data-filter="f_sipas_dist" data-filter-values="<?= e(json_encode($distFilterVals)) ?>" data-filter-mode="client" data-filter-col="4">Sipas distribuimit <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;" data-filter="f_diferenca" data-filter-values="<?= e(json_encode($diffFilterVals)) ?>" data-filter-mode="client" data-filter-col="5">Diferencë <i class="fas fa-sort"></i></th>
                        <?= sortThKt('sipas_skenimit_pda', 'Skenimi PDA', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThKt('bashkepunim', 'Bashkëpunim', $sortCol, $sortDir), 'f_bashk', $bashkValues) ?>
                        <?= withFilter(sortThKt('qyteti', 'Qyteti', $sortCol, $sortDir), 'f_qyteti', $qytetValues) ?>
                        <?= withFilter(sortThKt('rruga', 'Rruga', $sortCol, $sortDir), 'f_rruga', $rrugaVals) ?>
                        <?= sortThKt('numri_unik', 'Nr. Unik', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThKt('perfaqesuesi', 'Përfaqësuesi', $sortCol, $sortDir), 'f_perfaq', $perfaqVals) ?>
                        <?= sortThKt('nr_telefonit', 'Tel.', $sortCol, $sortDir) ?>
                        <?= sortThKt('email', 'Email', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThKt('ne_grup_njoftues', 'Grup njoftues', $sortCol, $sortDir), 'f_grup_njoft', $grupNjoftVals) ?>
                        <?= sortThKt('kontrate_e_vjeter', 'Kontratë e vjetër', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThKt('lloji_i_bocave', 'Lloji bocave', $sortCol, $sortDir), 'f_lloji_boca', $llojiBocaVals) ?>
                        <?= sortThKt('bocat_e_paguara', 'Bocat e paguara', $sortCol, $sortDir) ?>
                        <?= sortThKt('data_rregullatoret', 'Data rregullatorët', $sortCol, $sortDir) ?>
                        <th class="num server-sort" onclick="clientSortColumn(this, 19)" style="cursor:pointer;user-select:none;color:var(--danger)">Ditë pa marrë <i class="fas fa-sort"></i></th>
                        <th class="num server-sort" onclick="clientSortColumn(this, 20)" style="cursor:pointer;user-select:none;">Mesatare/muaj <i class="fas fa-sort"></i></th>
                        <?= sortThKt('koment', 'Koment', $sortCol, $sortDir) ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $name = $r['name_from_database'] ?: $r['biznesi'];
                        $nameKey = strtolower($name);
                        $bocaDist = $bocaBiznesi[$nameKey] ?? 0;
                        $diff = (int)$r['numri_ne_stok_sipas_kontrates'] - $bocaDist;
                        $dite = isset($ditePaMarr[$nameKey]) ? (int)$ditePaMarr[$nameKey]['dite'] : null;
                        $avg = $avgPerMonth[$nameKey] ?? '-';
                    ?>
                    <tr data-id="<?= $r['id'] ?>" <?= $dite && $dite > 90 ? 'style="background:#fef2f2;"' : '' ?>>
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
                        <td class="editable" data-field="email"><?= e($r['email']) ?></td>
                        <td class="editable" data-field="ne_grup_njoftues"><?= e($r['ne_grup_njoftues']) ?></td>
                        <td class="editable" data-field="kontrate_e_vjeter"><?= e($r['kontrate_e_vjeter']) ?></td>
                        <td class="editable" data-field="lloji_i_bocave"><?= e($r['lloji_i_bocave']) ?></td>
                        <td class="editable" data-field="bocat_e_paguara"><?= e($r['bocat_e_paguara']) ?></td>
                        <td class="editable" data-field="data_rregullatoret" data-type="date"><?= $r['data_rregullatoret'] ?></td>
                        <td class="num" style="font-weight:600;<?= $dite && $dite > 90 ? 'color:var(--danger);' : '' ?>">
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
        const va = parseFloat(a.cells[colIdx]?.textContent?.replace(/[^0-9.\-]/g, '') || '0');
        const vb = parseFloat(b.cells[colIdx]?.textContent?.replace(/[^0-9.\-]/g, '') || '0');
        return asc ? va - vb : vb - va;
    });
    rows.forEach(r => tbody.appendChild(r));
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Kontrata', 'kontrata', $content);
