<?php
/**
 * DARN Dashboard - Nxemëse (Heaters)
 * Single-table layout matching Excel Nxemese1 sheet
 * Columns: Klienti, Data, Te dhena, Te marra, Ne stok, Boca total ne terren, Lloji i nxemjes, Koment
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

$totalTerren = $db->query("SELECT COALESCE(SUM(te_dhena) - SUM(te_marra), 0) FROM nxemese")->fetchColumn();
$totalKliente = $db->query("SELECT COUNT(*) FROM (SELECT LOWER(klienti) FROM nxemese GROUP BY LOWER(klienti) HAVING SUM(te_dhena) - SUM(te_marra) > 0) t")->fetchColumn() ?: 0;

// Filters
$filterClient = $_GET['klienti'] ?? '';
$filterLloji = $_GET['lloji'] ?? '';
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
if ($fNxKlienti) { $fin = buildFilterIn($fNxKlienti, 'klienti'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
if ($fNxLloji) { $fin = buildFilterIn($fNxLloji, 'lloji_i_nxemjes'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
if ($fNxDhena) { $fin = buildFilterIn($fNxDhena, 'te_dhena'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
if ($fNxMarra) { $fin = buildFilterIn($fNxMarra, 'te_marra'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
if ($fNxKoment) { $fin = buildFilterIn($fNxKoment, 'koment'); $nxWhere[] = $fin['sql']; $nxParams = array_merge($nxParams, $fin['params']); }
$nxWhereSQL = $nxWhere ? 'WHERE ' . implode(' AND ', $nxWhere) : '';

// All transactions
$nxStmt = $db->prepare("SELECT * FROM nxemese {$nxWhereSQL} ORDER BY {$sortCol} {$sortDir}, id DESC");
$nxStmt->execute($nxParams);
$rows = $nxStmt->fetchAll();

// Per-row running totals (computed on ALL data, not filtered)
$nxRunningPerClient = [];
$nxRunningTotal = [];
$runCheck = $db->query("SELECT COUNT(*) FROM nxemese")->fetchColumn();
if ($runCheck > 0) {
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

// Distinct values for filters & dropdowns
$klientet = $db->query("SELECT DISTINCT klienti FROM nxemese ORDER BY klienti")->fetchAll(PDO::FETCH_COLUMN);
$llojet = $db->query("SELECT DISTINCT lloji_i_nxemjes FROM nxemese WHERE lloji_i_nxemjes IS NOT NULL ORDER BY lloji_i_nxemjes")->fetchAll(PDO::FETCH_COLUMN);
$nxDhenaVals = $db->query("SELECT DISTINCT te_dhena FROM nxemese ORDER BY te_dhena")->fetchAll(PDO::FETCH_COLUMN);
$nxMarraVals = $db->query("SELECT DISTINCT te_marra FROM nxemese ORDER BY te_marra")->fetchAll(PDO::FETCH_COLUMN);
$nxKomentVals = $db->query("SELECT DISTINCT koment FROM nxemese WHERE koment IS NOT NULL AND koment != '' ORDER BY koment LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

<div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:12px;">
        <h3><i class="fas fa-fire"></i> Nxemëse
            <span style="font-weight:400;font-size:0.85rem;color:var(--text-muted);margin-left:8px;">
                <?= count($rows) ?> lëvizje &middot; <strong style="color:var(--primary);"><?= num($totalTerren) ?></strong> në terren
            </span>
        </h3>
        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('nxAddForm').classList.toggle('hidden')">
            <i class="fas fa-plus"></i> Shto lëvizje
        </button>
    </div>

    <!-- Collapsible input form -->
    <div id="nxAddForm" class="hidden" style="border-bottom:1px solid var(--border);padding:16px 20px;background:#f8fafc;">
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="nxemese">
            <div class="form-row" style="align-items:flex-end;">
                <div class="form-group"><label>Klienti *</label>
                    <input type="text" name="klienti" required list="nxKlientList">
                    <datalist id="nxKlientList"><?php foreach ($klientet as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
                </div>
                <div class="form-group"><label>Data *</label><input type="date" name="data" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>Të dhëna</label><input type="number" name="te_dhena" value="0" min="0"></div>
                <div class="form-group"><label>Të marra</label><input type="number" name="te_marra" value="0" min="0"></div>
                <div class="form-group"><label>Lloji i nxemjes</label>
                    <select name="lloji_i_nxemjes" id="nxemje-select">
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($llojet as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
                        <option value="__new__">+ Shto të re...</option>
                    </select>
                </div>
                <div class="form-group"><label>Koment</label><input type="text" name="koment"></div>
                <div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button></div>
            </div>
        </form>
    </div>

    <!-- Filters -->
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

    <!-- Main table (Excel Nxemese1 layout) -->
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="nxemese" data-server-sort="true">
                <thead><tr>
                    <?= withFilter(sortThNx('klienti', 'Klienti', $sortCol, $sortDir), 'f_klienti', $klientet) ?>
                    <?= sortThNx('data', 'Data', $sortCol, $sortDir) ?>
                    <?= withFilter(sortThNx('te_dhena', 'Te dhena', $sortCol, $sortDir, 'num'), 'f_dhena', $nxDhenaVals) ?>
                    <?= withFilter(sortThNx('te_marra', 'Te marra', $sortCol, $sortDir, 'num'), 'f_marra', $nxMarraVals) ?>
                    <th class="num server-sort" onclick="clientSortColumn(this, 4)" style="cursor:pointer;user-select:none;">Ne stok <i class="fas fa-sort"></i></th>
                    <th class="num server-sort" onclick="clientSortColumn(this, 5)" style="cursor:pointer;user-select:none;">Boca total ne terren <i class="fas fa-sort"></i></th>
                    <?= withFilter(sortThNx('lloji_i_nxemjes', 'Lloji i nxemjes', $sortCol, $sortDir), 'f_lloji', $llojet) ?>
                    <?= withFilter(sortThNx('koment', 'Koment', $sortCol, $sortDir), 'f_koment', $nxKomentVals) ?>
                    <th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $neStok = $nxRunningPerClient[$r['id']] ?? null;
                        $rowStyle = ($neStok !== null && $neStok == 0) ? 'color:var(--danger);' : '';
                    ?>
                    <tr data-id="<?= $r['id'] ?>" style="<?= $rowStyle ?>">
                        <td class="editable" data-field="klienti"><?= e($r['klienti']) ?></td>
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="num editable" data-field="te_dhena" data-type="number"><?= (int)$r['te_dhena'] ?></td>
                        <td class="num editable" data-field="te_marra" data-type="number"><?= (int)$r['te_marra'] ?></td>
                        <td class="num" style="font-weight:600;<?= ($neStok !== null && $neStok == 0) ? 'color:var(--danger);' : 'color:var(--primary);' ?>"><?= $neStok ?? '-' ?></td>
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

<p style="color:var(--text-muted);font-size:0.82rem;margin-top:8px;">
    <i class="fas fa-info-circle"></i> Klientët me stok zero shfaqen me ngjyrë të kuqe.
    Klikoni mbi çdo qelizë për ta ndryshuar direkt.
</p>

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
