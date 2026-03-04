<?php
/**
 * DARN Dashboard - Nxemëse (Heaters)
 * Manual input, stock per client tracking (like cylinders)
 * Mini report: each client's heater inventory
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Stock per client (mini report)
$stokuPerKlient = $db->query("
    SELECT MIN(klienti) as klienti,
        SUM(te_dhena) as dhena,
        SUM(te_marra) as marra,
        SUM(te_dhena) - SUM(te_marra) as ne_stok
    FROM nxemese
    GROUP BY LOWER(klienti)
    HAVING ne_stok != 0
    ORDER BY ne_stok DESC
")->fetchAll();

$totalTerren = $db->query("SELECT COALESCE(SUM(te_dhena) - SUM(te_marra), 0) FROM nxemese")->fetchColumn();

// Filters
$filterClient = $_GET['klienti'] ?? '';
$filterLloji = $_GET['lloji'] ?? '';
// Multi-select column filters
$fNxKlienti = getFilterParam('f_klienti');
$fNxLloji = getFilterParam('f_lloji');
$fNxDhena = getFilterParam('f_dhena');
$fNxMarra = getFilterParam('f_marra');
$fNxKoment = getFilterParam('f_koment');

// Server-side sorting
$sortCol = $_GET['sort'] ?? 'data';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
$allowedSorts = ['data','klienti','te_dhena','te_marra','lloji_i_nxemjes','koment'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'data';
if (!in_array($sortDir, ['ASC','DESC'])) $sortDir = 'DESC';

function sortThNx($col, $label, $currentSort, $currentDir, $class = '') {
    $isActive = ($currentSort === $col);
    $newDir = ($isActive && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($_GET, ['sort' => $col, 'dir' => $newDir]);
    $url = '?' . http_build_query($params);
    $icon = $isActive ? ($currentDir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $activeStyle = $isActive ? 'color:var(--primary);font-weight:600;' : '';
    $classes = trim(($class ? $class . ' ' : '') . 'server-sort');
    return "<th class=\"{$classes}\" onclick=\"window.location.href='{$url}';return false;\" style=\"cursor:pointer;user-select:none;{$activeStyle}\">{$label} <i class=\"fas {$icon}\"></i></th>";
}

// Build WHERE clause
$nxWhere = [];
$nxParams = [];
if ($filterClient) { $nxWhere[] = "LOWER(TRIM(klienti)) LIKE LOWER(TRIM(?))"; $nxParams[] = "%{$filterClient}%"; }
if ($filterLloji) { $nxWhere[] = "LOWER(TRIM(lloji_i_nxemjes)) = LOWER(TRIM(?))"; $nxParams[] = $filterLloji; }
// Multi-select column filters
if ($fNxKlienti) { $fin = buildFilterIn($fNxKlienti, 'klienti'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
if ($fNxLloji) { $fin = buildFilterIn($fNxLloji, 'lloji_i_nxemjes'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
if ($fNxDhena) { $fin = buildFilterIn($fNxDhena, 'te_dhena'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
if ($fNxMarra) { $fin = buildFilterIn($fNxMarra, 'te_marra'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
if ($fNxKoment) { $fin = buildFilterIn($fNxKoment, 'koment'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
$nxWhereSQL = $nxWhere ? 'WHERE ' . implode(' AND ', $nxWhere) : '';

// All transactions (with filters)
$nxStmt = $db->prepare("SELECT * FROM nxemese {$nxWhereSQL} ORDER BY {$sortCol} {$sortDir}, id DESC");
$nxStmt->execute($nxParams);
$rows = $nxStmt->fetchAll();

// Per-row running totals: Ne stok (per client) and Total ne terren (global)
$allRowsAsc = $db->query("SELECT id FROM nxemese ORDER BY data ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
$nxRunningPerClient = [];
$nxRunningTotal = [];
if ($allRowsAsc) {
    $runStmt = $db->query("
        SELECT id,
            SUM(te_dhena - te_marra) OVER (
                PARTITION BY LOWER(klienti) ORDER BY data ASC, id ASC
            ) as ne_stok_running,
            SUM(te_dhena - te_marra) OVER (
                ORDER BY data ASC, id ASC
            ) as total_terren_running
        FROM nxemese
    ");
    foreach ($runStmt->fetchAll() as $rr) {
        $nxRunningPerClient[$rr['id']] = (int)$rr['ne_stok_running'];
        $nxRunningTotal[$rr['id']] = (int)$rr['total_terren_running'];
    }
}

// Client list for dropdown
$klientet = $db->query("SELECT DISTINCT klienti FROM nxemese ORDER BY klienti")->fetchAll(PDO::FETCH_COLUMN);
$llojet = $db->query("SELECT DISTINCT lloji_i_nxemjes FROM nxemese WHERE lloji_i_nxemjes IS NOT NULL ORDER BY lloji_i_nxemjes")->fetchAll(PDO::FETCH_COLUMN);
$nxDhenaVals = $db->query("SELECT DISTINCT te_dhena FROM nxemese ORDER BY te_dhena")->fetchAll(PDO::FETCH_COLUMN);
$nxMarraVals = $db->query("SELECT DISTINCT te_marra FROM nxemese ORDER BY te_marra")->fetchAll(PDO::FETCH_COLUMN);
$nxKomentVals = $db->query("SELECT DISTINCT koment FROM nxemese WHERE koment IS NOT NULL AND koment != '' ORDER BY koment LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card"><div class="label">Nxemëse total në terren</div><div class="value"><?= num($totalTerren) ?></div></div>
    <div class="summary-card"><div class="label">Klientë me nxemëse</div><div class="value"><?= count($stokuPerKlient) ?></div></div>
</div>

<!-- Section: Stoku sipas klientit -->
<div class="section-header">
    <i class="fas fa-users"></i>
    <h2>Stoku sipas klientit</h2>
    <div class="section-line"></div>
</div>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-fire"></i> Nxemëse në terren sipas klientit</h3></div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr><th>Klienti</th><th class="num">Te dhena</th><th class="num">Te marra</th><th class="num">Ne stok</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($stokuPerKlient as $s): ?>
                    <tr style="<?= $s['ne_stok'] == 0 ? 'color:var(--text-muted);' : '' ?>">
                        <td><?= e($s['klienti']) ?></td>
                        <td class="num"><?= (int)$s['dhena'] ?></td>
                        <td class="num"><?= (int)$s['marra'] ?></td>
                        <td class="num" style="font-weight:700;<?= $s['ne_stok'] > 0 ? 'color:var(--primary);' : 'color:var(--danger);' ?>"><?= (int)$s['ne_stok'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Section: Shto levizje -->
<div class="section-header">
    <i class="fas fa-plus-circle"></i>
    <h2>Shto Lëvizje</h2>
    <div class="section-line"></div>
</div>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus-circle"></i> Shto lëvizje nxemëse</h3></div>
    <div class="card-body padded">
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="nxemese">
            <div class="form-row">
                <div class="form-group"><label>Klienti *</label>
                    <input type="text" name="klienti" required list="nxKlientList">
                    <datalist id="nxKlientList"><?php foreach ($klientet as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
                </div>
                <div class="form-group"><label>Data *</label><input type="date" name="data" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>Të dhëna</label><input type="number" name="te_dhena" value="0" min="0"></div>
                <div class="form-group"><label>Të marra</label><input type="number" name="te_marra" value="0" min="0"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Lloji i nxemjes</label>
                    <select name="lloji_i_nxemjes" id="nxemje-select">
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($llojet as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
                        <option value="__new__">+ Shto të re...</option>
                    </select>
                </div>
                <div class="form-group"><label>Koment</label><input type="text" name="koment"></div>
                <div class="form-group" style="justify-content:flex-end;"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button></div>
            </div>
        </form>
    </div>
</div>

<!-- Section: Te gjitha levizjet -->
<div class="section-header">
    <i class="fas fa-list"></i>
    <h2>Të gjitha lëvizjet</h2>
    <div class="section-line"></div>
</div>
<div class="card">
    <div class="card-header"><h3>Të gjitha lëvizjet (<?= count($rows) ?>)</h3></div>
    <div class="filters">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <?php if ($sortCol !== 'data' || $sortDir !== 'DESC'): ?>
            <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
            <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
            <?php endif; ?>
            <div class="form-group">
                <label>Klienti</label>
                <input type="text" name="klienti" value="<?= e($filterClient) ?>" placeholder="Kërko klient..." list="nxFilterList">
                <datalist id="nxFilterList"><?php foreach ($klientet as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-group">
                <label>Lloji</label>
                <select name="lloji">
                    <option value="">Të gjitha</option>
                    <?php foreach ($llojet as $l): ?>
                    <option value="<?= e($l) ?>" <?= $filterLloji === $l ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtro</button>
            <a href="nxemese.php" class="btn btn-outline btn-sm">Pastro</a>
        </form>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="nxemese" data-server-sort="true">
                <thead><tr>
                    <?= sortThNx('data', 'Data', $sortCol, $sortDir) ?>
                    <?= withFilter(sortThNx('klienti', 'Klienti', $sortCol, $sortDir), 'f_klienti', $klientet) ?>
                    <?= withFilter(sortThNx('te_dhena', 'Te dhena', $sortCol, $sortDir, 'num'), 'f_dhena', $nxDhenaVals) ?>
                    <?= withFilter(sortThNx('te_marra', 'Te marra', $sortCol, $sortDir, 'num'), 'f_marra', $nxMarraVals) ?>
                    <th class="num server-sort" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;">Ne stok <i class="fas fa-sort"></i></th>
                    <th class="num server-sort" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;">Boca total ne terren <i class="fas fa-sort"></i></th>
                    <?= withFilter(sortThNx('lloji_i_nxemjes', 'Lloji i nxemjes', $sortCol, $sortDir), 'f_lloji', $llojet) ?>
                    <?= withFilter(sortThNx('koment', 'Koment', $sortCol, $sortDir), 'f_koment', $nxKomentVals) ?>
                    <th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="editable" data-field="klienti"><?= e($r['klienti']) ?></td>
                        <td class="num editable" data-field="te_dhena" data-type="number"><?= (int)$r['te_dhena'] ?></td>
                        <td class="num editable" data-field="te_marra" data-type="number"><?= (int)$r['te_marra'] ?></td>
                        <td class="num" style="font-weight:600;color:var(--primary);"><?= $nxRunningPerClient[$r['id']] ?? '-' ?></td>
                        <td class="num" style="color:var(--text-muted);"><?= $nxRunningTotal[$r['id']] ?? '-' ?></td>
                        <td class="editable" data-field="lloji_i_nxemjes" data-type="select" data-options="<?= e(json_encode($llojet)) ?>"><?= e($r['lloji_i_nxemjes']) ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('nxemese',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
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
renderLayout('Nxemëse', 'nxemese', $content);
