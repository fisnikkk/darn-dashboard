<?php
/**
 * DARN Dashboard - Gjendja Bankare (Bank Statement)
 * Copy-paste data + ability to highlight rows as reconciled/verified
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
$allowedSorts = ['data','data_valutes','ora','shpjegim','valuta','debia','kredi','bilanci','deftesa','lloji','klienti','komentet'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'data';
if (!in_array($sortDir, ['ASC','DESC'])) $sortDir = 'DESC';

function sortThGB($col, $label, $currentSort, $currentDir, $class = '') {
    $isActive = ($currentSort === $col);
    $newDir = ($isActive && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($_GET, ['sort' => $col, 'dir' => $newDir, 'page' => 1]);
    $url = '?' . http_build_query($params);
    $icon = $isActive ? ($currentDir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $activeStyle = $isActive ? 'color:var(--primary);font-weight:600;' : '';
    $classes = trim(($class ? $class . ' ' : '') . 'server-sort');
    return "<th class=\"{$classes}\" onclick=\"window.location.href='{$url}';return false;\" style=\"cursor:pointer;user-select:none;{$activeStyle}\">{$label} <i class=\"fas {$icon}\"></i></th>";
}

// Date range filter
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

// Multi-select column filters
$fGbLloji = getFilterParam('f_lloji');
$fGbValuta = getFilterParam('f_valuta');
$fGbShpjegim = getFilterParam('f_shpjegim');
$fGbDeftesa = getFilterParam('f_deftesa');
$fGbKlienti = getFilterParam('f_klienti');

$gbWhere = [];
$gbParams = [];
if ($filterDateFrom) { $gbWhere[] = "data >= ?"; $gbParams[] = $filterDateFrom; }
if ($filterDateTo) { $gbWhere[] = "data <= ?"; $gbParams[] = $filterDateTo; }
if ($fGbLloji) { $fin = buildFilterIn($fGbLloji, 'lloji'); $gbWhere[] = $fin['sql']; $gbParams = array_merge($gbParams, $fin['params']); }
if ($fGbValuta) { $fin = buildFilterIn($fGbValuta, 'valuta'); $gbWhere[] = $fin['sql']; $gbParams = array_merge($gbParams, $fin['params']); }
if ($fGbShpjegim) { $fin = buildFilterIn($fGbShpjegim, 'shpjegim'); $gbWhere[] = $fin['sql']; $gbParams = array_merge($gbParams, $fin['params']); }
if ($fGbDeftesa) { $fin = buildFilterIn($fGbDeftesa, 'deftesa'); $gbWhere[] = $fin['sql']; $gbParams = array_merge($gbParams, $fin['params']); }
if ($fGbKlienti) { $fin = buildFilterIn($fGbKlienti, 'klienti'); $gbWhere[] = $fin['sql']; $gbParams = array_merge($gbParams, $fin['params']); }
$gbWhereSQL = $gbWhere ? 'WHERE ' . implode(' AND ', $gbWhere) : '';

$cntStmt = $db->prepare("SELECT COUNT(*) FROM gjendja_bankare {$gbWhereSQL}");
$cntStmt->execute($gbParams);
$totalRows = $cntStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);
$rowsStmt = $db->prepare("SELECT * FROM gjendja_bankare {$gbWhereSQL} ORDER BY {$sortCol} {$sortDir}, id DESC LIMIT {$perPage} OFFSET {$offset}");
$rowsStmt->execute($gbParams);
$rows = $rowsStmt->fetchAll();

// Summary cards respect the active filters (date range + column filters)
$sumStmt = $db->prepare("SELECT COALESCE(SUM(debia),0) FROM gjendja_bankare {$gbWhereSQL}");
$sumStmt->execute($gbParams);
$totalDebi = $sumStmt->fetchColumn();
$sumStmt = $db->prepare("SELECT COALESCE(SUM(kredi),0) FROM gjendja_bankare {$gbWhereSQL}");
$sumStmt->execute($gbParams);
$totalKredi = $sumStmt->fetchColumn();
$depWhere = $gbWhereSQL ? $gbWhereSQL . " AND UPPER(shpjegim) LIKE '%DEPONIM%'" : "WHERE UPPER(shpjegim) LIKE '%DEPONIM%'";
$sumStmt = $db->prepare("SELECT COALESCE(SUM(kredi),0) FROM gjendja_bankare {$depWhere}");
$sumStmt->execute($gbParams);
$deponime = $sumStmt->fetchColumn();

$llojet = $db->query("SELECT DISTINCT lloji FROM gjendja_bankare WHERE lloji IS NOT NULL ORDER BY lloji")->fetchAll(PDO::FETCH_COLUMN);
$llojiJSON = json_encode($llojet);
$gbValutat = $db->query("SELECT DISTINCT valuta FROM gjendja_bankare WHERE valuta IS NOT NULL AND valuta != '' ORDER BY valuta")->fetchAll(PDO::FETCH_COLUMN);
$gbShpjegimVals = $db->query("SELECT DISTINCT shpjegim FROM gjendja_bankare WHERE shpjegim IS NOT NULL AND shpjegim != '' ORDER BY shpjegim")->fetchAll(PDO::FETCH_COLUMN);
$gbDeftesaVals = $db->query("SELECT DISTINCT deftesa FROM gjendja_bankare WHERE deftesa IS NOT NULL AND deftesa != '' ORDER BY deftesa")->fetchAll(PDO::FETCH_COLUMN);
$gbKlientetVals = $db->query("SELECT DISTINCT klienti FROM gjendja_bankare WHERE klienti IS NOT NULL AND klienti != '' ORDER BY klienti")->fetchAll(PDO::FETCH_COLUMN);
// Client names from distribuimi for the klienti datalist/select
$distKlientet = $db->query("SELECT DISTINCT MIN(klienti) as k FROM distribuimi GROUP BY LOWER(klienti) ORDER BY k")->fetchAll(PDO::FETCH_COLUMN);
$distKlientetJSON = json_encode($distKlientet, JSON_UNESCAPED_UNICODE);
// Merge both sources for the column filter dropdown (useful before clients are assigned)
$allKlientetFilter = array_values(array_unique(array_merge($gbKlientetVals, $distKlientet)));
sort($allKlientetFilter);

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card"><div class="label">Total Debi (Dalje)</div><div class="value">&euro; <?= eur($totalDebi) ?></div></div>
    <div class="summary-card"><div class="label">Total Kredi (Hyrje)</div><div class="value">&euro; <?= eur($totalKredi) ?></div></div>
    <div class="summary-card"><div class="label">Deponime ne bankë</div><div class="value">&euro; <?= eur($deponime) ?></div></div>
</div>

<div class="card">
    <div class="card-header"><h3>Gjendja Bankare (<?= num($totalRows) ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openModal('pasteBank')" style="margin-right:6px;"><i class="fas fa-paste"></i> Ngjit nga Excel</button>
        <button class="btn btn-primary btn-sm" onclick="openModal('addBank')"><i class="fas fa-plus"></i> Shto</button>
    </div>
    <div class="card-body">
        <div style="padding:10px 20px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
            <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <?php // Preserve existing filter params
                foreach ($_GET as $k => $v) {
                    if (in_array($k, ['date_from','date_to','page'])) continue;
                    if (is_array($v)) { foreach ($v as $vv) echo '<input type="hidden" name="' . e($k) . '[]" value="' . e($vv) . '">'; }
                    else echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
                } ?>
                <div class="form-group" style="min-width:auto;margin-bottom:0;">
                    <label style="font-size:0.78rem;margin-bottom:2px;">Data nga</label>
                    <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>" style="padding:5px 8px;font-size:0.82rem;">
                </div>
                <div class="form-group" style="min-width:auto;margin-bottom:0;">
                    <label style="font-size:0.78rem;margin-bottom:2px;">Data deri</label>
                    <input type="date" name="date_to" value="<?= e($filterDateTo) ?>" style="padding:5px 8px;font-size:0.82rem;">
                </div>
                <div class="form-group" style="min-width:auto;margin-bottom:0;">
                    <label style="font-size:0.78rem;margin-bottom:2px;">Rreshta</label>
                    <select name="per_page" style="width:70px;padding:5px 8px;font-size:0.82rem;">
                        <option value="100" <?= $perPage==100?'selected':'' ?>>100</option>
                        <option value="500" <?= $perPage==500?'selected':'' ?>>500</option>
                        <option value="1000" <?= $perPage==1000?'selected':'' ?>>1000</option>
                        <option value="5000" <?= $perPage==5000?'selected':'' ?>>5000</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtro</button>
                <?php if ($filterDateFrom || $filterDateTo): ?>
                <a href="?<?= http_build_query(array_diff_key($_GET, array_flip(['date_from','date_to','page']))) ?>" class="btn btn-outline btn-sm">Pastro datat</a>
                <?php endif; ?>
            </form>
            <span style="color:var(--text-muted);font-size:0.78rem;margin-left:auto;">
                <i class="fas fa-info-circle"></i> Kliko <strong>✓</strong> për ta markuar si të kontrolluar
            </span>
        </div>
        <div class="table-wrapper">
            <table class="data-table" data-table="gjendja_bankare" data-server-sort="true">
                <thead>
                    <tr>
                        <th>✓</th>
                        <?= sortThGB('data', 'Data', $sortCol, $sortDir) ?>
                        <?= sortThGB('data_valutes', 'Data Valutës', $sortCol, $sortDir) ?>
                        <?= sortThGB('ora', 'Ora', $sortCol, $sortDir) ?>
                        <?= withFilter(sortThGB('shpjegim', 'Shpjegim', $sortCol, $sortDir), 'f_shpjegim', $gbShpjegimVals) ?>
                        <?= withFilter(sortThGB('valuta', 'Valuta', $sortCol, $sortDir), 'f_valuta', $gbValutat) ?>
                        <?= sortThGB('debia', 'Debi', $sortCol, $sortDir, 'num') ?>
                        <?= sortThGB('kredi', 'Kredi', $sortCol, $sortDir, 'num') ?>
                        <?= sortThGB('bilanci', 'Bilanci', $sortCol, $sortDir, 'num') ?>
                        <?= withFilter(sortThGB('deftesa', 'Dëftesa', $sortCol, $sortDir), 'f_deftesa', $gbDeftesaVals) ?>
                        <?= withFilter(sortThGB('lloji', 'Lloji', $sortCol, $sortDir), 'f_lloji', $llojet) ?>
                        <?= withFilter(sortThGB('klienti', 'Klienti', $sortCol, $sortDir), 'f_klienti', $allKlientetFilter) ?>
                        <?= sortThGB('komentet', 'Komentet', $sortCol, $sortDir) ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>" class="<?= $r['e_kontrolluar'] ? 'verified' : '' ?>">
                        <td>
                            <button class="btn btn-sm <?= $r['e_kontrolluar'] ? 'btn-success' : 'btn-outline' ?>" 
                                    onclick="toggleHighlight(<?= $r['id'] ?>, 'gjendja_bankare')" title="Marko si te kontrolluar">
                                <i class="fas fa-check"></i>
                            </button>
                        </td>
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="editable" data-field="data_valutes" data-type="date"><?= $r['data_valutes'] ?></td>
                        <td class="editable" data-field="ora"><?= $r['ora'] ?></td>
                        <td class="editable truncate" data-field="shpjegim" title="<?= e($r['shpjegim']) ?>"><?= e($r['shpjegim']) ?></td>
                        <td class="editable" data-field="valuta"><?= e($r['valuta']) ?></td>
                        <td class="amount editable" data-field="debia" data-type="number"><?= $r['debia'] ? eur($r['debia']) : '' ?></td>
                        <td class="amount editable" data-field="kredi" data-type="number"><?= $r['kredi'] ? eur($r['kredi']) : '' ?></td>
                        <td class="amount" style="font-weight:600;"><?= eur($r['bilanci']) ?></td>
                        <td class="editable" data-field="deftesa"><?= e($r['deftesa']) ?></td>
                        <td class="editable truncate" data-field="lloji" data-type="select" data-options="<?= e($llojiJSON) ?>" title="<?= e($r['lloji']) ?>"><?= e($r['lloji']) ?></td>
                        <td class="editable truncate" data-field="klienti" data-type="select" data-allow-custom="true" data-options="<?= e($distKlientetJSON) ?>" title="<?= e($r['klienti']) ?>"><?= e($r['klienti']) ?></td>
                        <td class="editable truncate" data-field="komentet" title="<?= e($r['komentet']) ?>"><?= e($r['komentet']) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('gjendja_bankare',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
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

<div class="modal-overlay" id="addBank">
    <div class="modal">
        <div class="modal-header"><h3>Shto transaksion bankar</h3><button class="btn btn-outline btn-sm" onclick="closeModal('addBank')">&times;</button></div>
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="gjendja_bankare">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label>Data *</label><input type="date" name="data" required value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group"><label>Data Valutës</label><input type="date" name="data_valutes" value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group"><label>Ora</label><input type="time" name="ora"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Shpjegim *</label><input type="text" name="shpjegim" required></div>
                    <div class="form-group"><label>Valuta</label><input type="text" name="valuta" value="EUR"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Debi (€)</label><input type="number" name="debia" step="0.01" id="bank_debi"></div>
                    <div class="form-group"><label>Kredi (€)</label><input type="number" name="kredi" step="0.01" id="bank_kredi"></div>
                    <div class="form-group">
                        <label>Bilanci (€) <small style="color:var(--text-muted);">auto: +kredi -debi</small></label>
                        <input type="number" name="bilanci" step="0.01" id="bank_bilanci">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Dëftesa</label><input type="text" name="deftesa"></div>
                    <div class="form-group"><label>Lloji</label>
                        <input type="text" name="lloji" list="llojiList" placeholder="Shkruaj ose zgjidh...">
                        <datalist id="llojiList">
                        <?php foreach ($llojet as $l): ?><option value="<?= e($l) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Klienti</label>
                        <input type="text" name="klienti" list="klientetList" placeholder="Zgjidhni klientin...">
                        <datalist id="klientetList">
                        <?php foreach ($distKlientet as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group"><label>Komentet</label><input type="text" name="komentet" placeholder="Shto koment..."></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addBank')">Anulo</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button>
            </div>
        </form>
    </div>
</div>

<!-- Paste from Excel Modal -->
<div class="modal-overlay" id="pasteBank">
    <div class="modal" style="max-width:720px;">
        <div class="modal-header"><h3><i class="fas fa-paste"></i> Ngjit të dhëna nga Excel</h3><button class="btn btn-outline btn-sm" onclick="closeModal('pasteBank')">&times;</button></div>
        <div class="modal-body">
            <p style="color:var(--text-muted);font-size:0.82rem;margin-bottom:12px;">
                Kopjo rreshtat nga Excel dhe ngjiti ketu. Kolonat duhet te jene ne kete rend:<br>
                <strong>Data | Data Valutes | Ora | Shpjegim | Valuta | Debi | Kredi | Bilanci | Deftesa | Lloji | Klienti | Komentet</strong>
            </p>
            <textarea id="pasteArea" rows="10" style="width:100%;font-family:monospace;font-size:0.82rem;padding:10px;border:1px solid var(--border);border-radius:6px;resize:vertical;" placeholder="Ngjit ketu te dhenat nga Excel (Ctrl+V)..."></textarea>
            <div id="pastePreview" style="margin-top:10px;font-size:0.82rem;color:var(--text-muted);"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('pasteBank')">Anulo</button>
            <button type="button" class="btn btn-primary" onclick="submitPastedData()"><i class="fas fa-save"></i> Ruaj te gjitha</button>
        </div>
    </div>
</div>

<script>
// Auto-calculate bilanci for new bank transactions: previous bilanci + kredi - debi
(function() {
    const debiEl = document.getElementById('bank_debi');
    const krediEl = document.getElementById('bank_kredi');
    const bilanciEl = document.getElementById('bank_bilanci');
    const prevBilanci = <?= json_encode((float)$db->query("SELECT COALESCE(bilanci, 0) FROM gjendja_bankare ORDER BY data DESC, id DESC LIMIT 1")->fetchColumn()) ?>;

    function recalcBilanci() {
        const d = parseFloat(debiEl.value) || 0;
        const k = parseFloat(krediEl.value) || 0;
        bilanciEl.value = (prevBilanci + k - d).toFixed(2);
    }
    [debiEl, krediEl].forEach(el => el.addEventListener('input', recalcBilanci));
    // Set initial bilanci to previous balance
    bilanciEl.value = prevBilanci.toFixed(2);
})();
</script>

<script>
document.getElementById('pasteArea').addEventListener('input', function() {
    const lines = this.value.trim().split('\n').filter(l => l.trim());
    document.getElementById('pastePreview').textContent = lines.length + ' rreshta te gjetura';
});

function parseNumber(str) {
    if (!str || str.trim() === '') return null;
    // Handle European format: 1.234,56 -> 1234.56
    let s = str.trim().replace(/[^\d.,-]/g, '');
    if (s.includes(',') && s.includes('.')) {
        if (s.lastIndexOf(',') > s.lastIndexOf('.')) {
            s = s.replace(/\./g, '').replace(',', '.');
        } else {
            s = s.replace(/,/g, '');
        }
    } else if (s.includes(',')) {
        s = s.replace(',', '.');
    }
    const n = parseFloat(s);
    return isNaN(n) ? null : n;
}

function parseDate(str) {
    if (!str || str.trim() === '') return null;
    const s = str.trim();
    // Try DD/MM/YYYY or DD.MM.YYYY or DD-MM-YYYY
    const m = s.match(/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})$/);
    if (m) {
        const d = m[1].padStart(2,'0'), mo = m[2].padStart(2,'0'), y = m[3];
        if (+mo >= 1 && +mo <= 12 && +d >= 1 && +d <= 31) return y + '-' + mo + '-' + d;
    }
    // Try YYYY-MM-DD (already correct)
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    // Reject non-date strings to avoid inserting malformed data
    return null;
}

function submitPastedData() {
    const text = document.getElementById('pasteArea').value.trim();
    if (!text) { showToast('Nuk ka te dhena', 'error'); return; }

    const lines = text.split('\n').filter(l => l.trim());
    const rows = [];

    for (const line of lines) {
        const cols = line.split('\t');
        if (cols.length < 4) continue; // Need at least data + shpjegim

        rows.push({
            data: parseDate(cols[0]),
            data_valutes: parseDate(cols[1]),
            ora: (cols[2] || '').trim(),
            shpjegim: (cols[3] || '').trim(),
            valuta: (cols[4] || '').trim(),
            debia: parseNumber(cols[5]),
            kredi: parseNumber(cols[6]),
            bilanci: parseNumber(cols[7]),
            deftesa: (cols[8] || '').trim(),
            lloji: (cols[9] || '').trim() || null,
            klienti: (cols[10] || '').trim() || null,
            komentet: (cols[11] || '').trim() || null
        });
    }

    if (!rows.length) { showToast('Nuk u gjet asnje rresht valid', 'error'); return; }

    fetch('/api/bulk_insert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table: 'gjendja_bankare', rows })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message);
            location.reload();
        } else {
            showToast('Gabim: ' + (data.error || ''), 'error');
        }
    })
    .catch(() => showToast('Gabim ne server', 'error'));
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Gjendja Bankare', 'gjendja_bankare', $content);
