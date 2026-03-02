<?php
/**
 * DARN Dashboard - Distribuimi (Distribution/Deliveries)
 * Core page - mirrors the Distribuimi Excel sheet
 * 
 * Key columns:
 *   G: Boca tek biznesi = running SUMIF of sasia - kthyera per client
 *   H: Boca total ne terren = running SUM of all sasia - all kthyera
 *   K: Pagesa = stored from Excel (originally computed as sasia × litra × cmimi)
 *   X: Litrat total = stored from Excel (originally computed as sasia × litra)
 *
 * pagesa and litrat_total are stored directly in DB — NOT recomputed.
 * All other columns are editable (especially Menyra e pageses for finance reconciliation)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Filters
$filterClient = $_GET['klienti'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterPayment = $_GET['payment'] ?? '';
// Multi-select column filters
$fKlienti = getFilterParam('f_klienti');
$fMenyra = getFilterParam('f_menyra');
$fStatusi = getFilterParam('f_fatura');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, [100, 500, 1000, 5000])) $perPage = 100;
$offset = ($page - 1) * $perPage;

// Server-side sorting
$sortCol = $_GET['sort'] ?? 'data';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
$allowedSorts = ['row_nr','klienti','data','sasia','boca_te_kthyera','litra','cmimi','pagesa','menyra_e_pageses','fatura_e_derguar','data_e_fletepageses','koment','litrat_total'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'data';
if (!in_array($sortDir, ['ASC','DESC'])) $sortDir = 'DESC';

// Helper: generate sortable column header with onclick navigation
function sortTh($col, $label, $currentSort, $currentDir, $class = '') {
    $isActive = ($currentSort === $col);
    $newDir = ($isActive && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($_GET, ['sort' => $col, 'dir' => $newDir, 'page' => 1]);
    $url = '?' . http_build_query($params);
    $icon = $isActive ? ($currentDir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $activeStyle = $isActive ? 'color:var(--primary);font-weight:600;' : '';
    $classes = trim(($class ? $class . ' ' : '') . 'server-sort');
    return "<th class=\"{$classes}\" onclick=\"window.location.href='{$url}';return false;\" style=\"cursor:pointer;user-select:none;{$activeStyle}\">{$label} <i class=\"fas {$icon}\"></i></th>";
}

// Build WHERE clause
$where = [];
$params = [];
if ($filterClient) { $where[] = "LOWER(TRIM(d.klienti)) LIKE LOWER(TRIM(?))"; $params[] = "%{$filterClient}%"; }
if ($filterDateFrom) { $where[] = "d.data >= ?"; $params[] = $filterDateFrom; }
if ($filterDateTo) { $where[] = "d.data <= ?"; $params[] = $filterDateTo; }
if ($filterPayment) { $where[] = "LOWER(TRIM(d.menyra_e_pageses)) = LOWER(TRIM(?))"; $params[] = $filterPayment; }
// Multi-select column filters
if ($fKlienti) { $fin = buildFilterIn($fKlienti, 'klienti', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fMenyra) { $fin = buildFilterIn($fMenyra, 'menyra_e_pageses', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fStatusi) { $fin = buildFilterIn($fStatusi, 'fatura_e_derguar', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total rows
$countSQL = "SELECT COUNT(*) FROM distribuimi d {$whereSQL}";
$stmt = $db->prepare($countSQL);
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// Fetch data with calculated columns
$sql = "
    SELECT
        d.id,
        d.row_nr,
        d.klienti,
        d.data,
        d.sasia,
        d.boca_te_kthyera,
        d.litra,
        d.cmimi,
        d.pagesa,
        d.menyra_e_pageses,
        d.fatura_e_derguar,
        d.data_e_fletepageses,
        d.koment,
        d.litrat_total
    FROM distribuimi d
    {$whereSQL}
    ORDER BY d.{$sortCol} {$sortDir}, d.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Get boca tek biznesi AND boca total ne terren as per-row running totals
$ids = array_column($rows, 'id');
$bocaPerRow = [];
$bocaTotalPerRow = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $bocaStmt = $db->prepare("
        SELECT sub.id, sub.boca_running, sub.boca_total FROM (
            SELECT id,
                SUM(sasia - boca_te_kthyera) OVER (
                    PARTITION BY LOWER(klienti)
                    ORDER BY data ASC, id ASC
                ) as boca_running,
                SUM(sasia - boca_te_kthyera) OVER (
                    ORDER BY data ASC, id ASC
                ) as boca_total
            FROM distribuimi
        ) sub WHERE sub.id IN ({$placeholders})
    ");
    $bocaStmt->execute($ids);
    foreach ($bocaStmt->fetchAll() as $b) {
        $bocaPerRow[$b['id']] = $b['boca_running'];
        $bocaTotalPerRow[$b['id']] = $b['boca_total'];
    }
}
// Summary cards — respect active filters
$summStmt = $db->prepare("SELECT COUNT(DISTINCT LOWER(klienti)) FROM distribuimi d {$whereSQL}");
$summStmt->execute($params);
$uniqueClients = $summStmt->fetchColumn();

// Stoku total ne terren: ALWAYS global (unfiltered) — per Albulena's requirement
$stokuTotal = $db->query("SELECT COALESCE(SUM(sasia) - SUM(boca_te_kthyera), 0) FROM distribuimi")->fetchColumn();

// Payment types for dropdown
$paymentTypes = ['Cash', 'Bank', 'Po (Fature te rregullte) cash', 'Po (Fature te rregullte) banke', 'No payment', 'Dhurate'];
$paymentJSON = json_encode($paymentTypes);

// Unique clients for filter (from distribuimi + kontrata)
$distClientsAll = $db->query("SELECT DISTINCT klienti FROM distribuimi ORDER BY klienti")->fetchAll(PDO::FETCH_COLUMN);
$kontrataClientsAll = $db->query("SELECT DISTINCT name_from_database FROM kontrata WHERE name_from_database IS NOT NULL AND name_from_database != '' ORDER BY name_from_database")->fetchAll(PDO::FETCH_COLUMN);
$clients = array_unique(array_merge($distClientsAll, $kontrataClientsAll));
sort($clients);

// Distinct values for Excel-like column filters
$distMenyraVals = $db->query("SELECT DISTINCT menyra_e_pageses FROM distribuimi WHERE menyra_e_pageses IS NOT NULL AND menyra_e_pageses != '' ORDER BY menyra_e_pageses")->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

<!-- Summary row matching Excel top section -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total Transaksione</div>
        <div class="value"><?= num($totalRows) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Stoku total ne terren</div>
        <div class="value"><?= num($stokuTotal) ?> boca</div>
    </div>
    <div class="summary-card">
        <div class="label">Klientë unikë</div>
        <div class="value"><?= num($uniqueClients) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Distribuimi - Të dhënat</h3>
        <button class="btn btn-primary btn-sm" onclick="openModal('pasteDist')" style="margin-right:6px;"><i class="fas fa-paste"></i> Ngjit nga Excel</button>
        <button class="btn btn-primary btn-sm" onclick="openModal('addModal')">
            <i class="fas fa-plus"></i> Shto rresht
        </button>
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
                <input type="text" name="klienti" value="<?= e($filterClient) ?>" placeholder="Kërko klient..." list="clientList">
                <datalist id="clientList">
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= e($c) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label>Data nga</label>
                <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>">
            </div>
            <div class="form-group">
                <label>Data deri</label>
                <input type="date" name="date_to" value="<?= e($filterDateTo) ?>">
            </div>
            <div class="form-group">
                <label>Mënyra e pagesës</label>
                <select name="payment">
                    <option value="">Të gjitha</option>
                    <?php foreach ($paymentTypes as $pt): ?>
                    <option value="<?= e($pt) ?>" <?= $filterPayment === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
                    <?php endforeach; ?>
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
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtro</button>
            <a href="distribuimi.php" class="btn btn-outline btn-sm">Pastro</a>
        </form>
    </div>
    
    <!-- Bulk Action Bar -->
    <div id="bulkBar" style="display:none;padding:10px 14px;background:#eff6ff;border-bottom:1px solid var(--border);display:none;align-items:center;gap:12px;flex-wrap:wrap;">
        <span id="bulkCount" style="font-weight:600;font-size:0.85rem;"></span>
        <select id="bulkPayment" style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;">
            <option value="">-- Ndrysho Menyren --</option>
            <?php foreach ($paymentTypes as $pt): ?><option value="<?= e($pt) ?>"><?= e($pt) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary btn-sm" onclick="applyBulkPayment()"><i class="fas fa-check"></i> Apliko</button>
        <button class="btn btn-outline btn-sm" onclick="clearBulkSelection()"><i class="fas fa-times"></i> Anulo</button>
    </div>

    <!-- Data Table -->
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="distribuimi" data-server-sort="true">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="selectAll" title="Zgjidh te gjitha"></th>
                        <?= sortTh('row_nr', '#', $sortCol, $sortDir) ?>
                        <?= withFilter(sortTh('klienti', 'Klienti', $sortCol, $sortDir), 'f_klienti', $distClientsAll) ?>
                        <?= sortTh('data', 'Data', $sortCol, $sortDir) ?>
                        <?= sortTh('sasia', 'Sasia', $sortCol, $sortDir, 'num') ?>
                        <?= sortTh('boca_te_kthyera', 'Boca të kthyera', $sortCol, $sortDir, 'num') ?>
                        <th class="num">Boca tek biznesi</th>
                        <th class="num">Boca total terren</th>
                        <?= sortTh('litra', 'Litra', $sortCol, $sortDir, 'num') ?>
                        <?= sortTh('cmimi', 'Çmimi', $sortCol, $sortDir, 'num') ?>
                        <?= sortTh('pagesa', 'Pagesa', $sortCol, $sortDir, 'num') ?>
                        <?= withFilter(sortTh('menyra_e_pageses', 'Mënyra e pagesës', $sortCol, $sortDir), 'f_menyra', $distMenyraVals) ?>
                        <?= sortTh('fatura_e_derguar', 'Fatura e dërguar', $sortCol, $sortDir) ?>
                        <?= sortTh('data_e_fletepageses', 'Data fletëpagesës', $sortCol, $sortDir) ?>
                        <th>Koment</th>
                        <?= sortTh('litrat_total', 'Litrat total', $sortCol, $sortDir, 'num') ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td><input type="checkbox" class="row-select" value="<?= $r['id'] ?>"></td>
                        <td><?= $r['row_nr'] ?: $r['id'] ?></td>
                        <td class="editable" data-field="klienti"><?= e($r['klienti']) ?></td>
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="num editable" data-field="sasia" data-type="number"><?= (int)$r['sasia'] ?></td>
                        <td class="num editable" data-field="boca_te_kthyera" data-type="number"><?= (int)$r['boca_te_kthyera'] ?></td>
                        <td class="num" style="font-weight:600;color:var(--primary);">
                            <?= isset($bocaPerRow[$r['id']]) ? (int)$bocaPerRow[$r['id']] : '-' ?>
                        </td>
                        <td class="num" style="color:var(--text-muted);">
                            <?= isset($bocaTotalPerRow[$r['id']]) ? num($bocaTotalPerRow[$r['id']]) : '-' ?>
                        </td>
                        <td class="num editable" data-field="litra" data-type="number"><?= $r['litra'] ?></td>
                        <td class="num editable" data-field="cmimi" data-type="number"><?= $r['cmimi'] ?></td>
                        <td class="amount" style="font-weight:600;">&euro; <?= eur($r['pagesa']) ?></td>
                        <td class="editable" data-field="menyra_e_pageses" data-type="select" 
                            data-options="<?= e($paymentJSON) ?>">
                            <?php
                            $badge = match($r['menyra_e_pageses']) {
                                'Cash' => 'badge-cash',
                                'Bank' => 'badge-bank',
                                'No payment' => 'badge-nopay',
                                'Dhurate' => 'badge-dhurate',
                                default => 'badge-fature'
                            };
                            ?>
                            <span class="badge <?= $badge ?>"><?= e($r['menyra_e_pageses']) ?></span>
                        </td>
                        <td class="editable" data-field="fatura_e_derguar"><?= e($r['fatura_e_derguar']) ?></td>
                        <td class="editable" data-field="data_e_fletepageses" data-type="date"><?= $r['data_e_fletepageses'] ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td class="num"><?= eur($r['litrat_total']) ?></td>
                        <td>
                            <button class="btn btn-danger btn-sm" onclick="deleteRow('distribuimi', <?= $r['id'] ?>)" title="Fshij">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="pagination">
            <div class="info">
                Faqja <?= $page ?> nga <?= $totalPages ?> (<?= num($totalRows) ?> rreshta total)
            </div>
            <div class="pages">
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo; Para</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 3);
                $end = min($totalPages, $page + 3);
                for ($i = $start; $i <= $end; $i++):
                ?>
                <?php if ($i === $page): ?>
                <span class="current"><?= $i ?></span>
                <?php else: ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Tjetra &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add New Row Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Shto transaksion të ri</h3>
            <button class="btn btn-outline btn-sm" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="distribuimi">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Klienti *</label>
                        <input type="text" name="klienti" required list="clientList2">
                        <datalist id="clientList2">
                            <?php foreach ($clients as $c): ?>
                            <option value="<?= e($c) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label>Data *</label>
                        <input type="date" name="data" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Sasia (boca)</label>
                        <input type="number" name="sasia" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Boca të kthyera</label>
                        <input type="number" name="boca_te_kthyera" value="0" min="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Litra</label>
                        <input type="number" name="litra" step="0.01" value="18">
                    </div>
                    <div class="form-group">
                        <label>Çmimi</label>
                        <input type="number" name="cmimi" step="0.0001" value="0.75">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Pagesa (€) <small style="color:var(--text-muted);">auto: sasia × litra × çmimi</small></label>
                        <input type="number" name="pagesa" id="dist_pagesa" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>Litrat total <small style="color:var(--text-muted);">auto: sasia × litra</small></label>
                        <input type="number" name="litrat_total" id="dist_litrat_total" step="0.01" value="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Mënyra e pagesës *</label>
                        <select name="menyra_e_pageses" required>
                            <?php foreach ($paymentTypes as $pt): ?>
                            <option value="<?= e($pt) ?>"><?= e($pt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fatura e dërguar</label>
                        <input type="text" name="fatura_e_derguar">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Data fletëpagesës</label>
                        <input type="date" name="data_e_fletepageses">
                    </div>
                    <div class="form-group">
                        <label>Koment</label>
                        <input type="text" name="koment">
                    </div>
                </div>
<script>
// Auto-calculate pagesa and litrat_total when sasia/litra/cmimi change
(function() {
    const form = document.querySelector('#addModal form');
    if (!form) return;
    const sasia = form.querySelector('[name="sasia"]');
    const litra = form.querySelector('[name="litra"]');
    const cmimi = form.querySelector('[name="cmimi"]');
    const pagesa = form.querySelector('[name="pagesa"]');
    const litratTotal = form.querySelector('[name="litrat_total"]');
    function recalc() {
        const s = parseFloat(sasia.value) || 0;
        const l = parseFloat(litra.value) || 0;
        const c = parseFloat(cmimi.value) || 0;
        pagesa.value = (s * l * c).toFixed(2);
        litratTotal.value = (s * l).toFixed(2);
    }
    [sasia, litra, cmimi].forEach(el => el.addEventListener('input', recalc));
    recalc();
})();
</script>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Anulo</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button>
            </div>
        </form>
    </div>
</div>

<!-- Paste from Excel Modal -->
<div class="modal-overlay" id="pasteDist">
    <div class="modal" style="max-width:720px;">
        <div class="modal-header"><h3><i class="fas fa-paste"></i> Ngjit të dhëna nga Excel</h3><button class="btn btn-outline btn-sm" onclick="closeModal('pasteDist')">&times;</button></div>
        <div class="modal-body">
            <p style="color:var(--text-muted);font-size:0.82rem;margin-bottom:12px;">
                Kopjo rreshtat nga Excel dhe ngjiti ketu. Kolonat duhet te jene ne kete rend:<br>
                <strong>Klienti | Data | Sasia | Boca te kthyera | Litra | Cmimi | Pagesa | Menyra e pageses | Fatura e derguar | Data fletepageses | Koment</strong>
            </p>
            <textarea id="pasteAreaDist" rows="10" style="width:100%;font-family:monospace;font-size:0.82rem;padding:10px;border:1px solid var(--border);border-radius:6px;resize:vertical;" placeholder="Ngjit ketu te dhenat nga Excel (Ctrl+V)..."></textarea>
            <div id="pastePreviewDist" style="margin-top:10px;font-size:0.82rem;color:var(--text-muted);"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('pasteDist')">Anulo</button>
            <button type="button" class="btn btn-primary" onclick="submitPastedDistData()"><i class="fas fa-save"></i> Ruaj te gjitha</button>
        </div>
    </div>
</div>

<script>
document.getElementById('pasteAreaDist').addEventListener('input', function() {
    const lines = this.value.trim().split('\n').filter(l => l.trim());
    document.getElementById('pastePreviewDist').textContent = lines.length + ' rreshta te gjetura';
});

function parseNumberDist(str) {
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

function parseDateDist(str) {
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

function submitPastedDistData() {
    const text = document.getElementById('pasteAreaDist').value.trim();
    if (!text) { showToast('Nuk ka te dhena', 'error'); return; }

    const lines = text.split('\n').filter(l => l.trim());
    const rows = [];

    for (const line of lines) {
        const cols = line.split('\t');
        if (cols.length < 2) continue; // Need at least klienti + data

        const klienti = (cols[0] || '').trim();
        const data = parseDateDist(cols[1]);
        const sasia = parseNumberDist(cols[2]);
        const boca_te_kthyera = parseNumberDist(cols[3]);
        const litra = parseNumberDist(cols[4]);
        const cmimi = parseNumberDist(cols[5]);
        let pagesa = parseNumberDist(cols[6]);
        const menyra_e_pageses = (cols[7] || '').trim();
        const fatura_e_derguar = (cols[8] || '').trim();
        const data_e_fletepageses = parseDateDist(cols[9]);
        const koment = (cols[10] || '').trim();

        // Auto-calculate litrat_total = sasia * litra
        const s = sasia ?? 0;
        const l = litra ?? 0;
        const litrat_total = s * l;
        const litrat_e_konvertuara = litrat_total;

        // If pagesa is empty, auto-calculate: pagesa = sasia * litra * cmimi
        if (pagesa === null) {
            const c = cmimi ?? 0;
            pagesa = s * l * c;
        }

        rows.push({
            klienti: klienti || null,
            data: data,
            sasia: sasia,
            boca_te_kthyera: boca_te_kthyera,
            litra: litra,
            cmimi: cmimi,
            pagesa: pagesa,
            menyra_e_pageses: menyra_e_pageses || null,
            fatura_e_derguar: fatura_e_derguar || null,
            data_e_fletepageses: data_e_fletepageses,
            koment: koment || null,
            litrat_total: litrat_total,
            litrat_e_konvertuara: litrat_e_konvertuara
        });
    }

    if (!rows.length) { showToast('Nuk u gjet asnje rresht valid', 'error'); return; }

    fetch('/api/bulk_insert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table: 'distribuimi', rows })
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

<script>
// Bulk selection for changing payment type on multiple rows
(function() {
    const selectAll = document.getElementById('selectAll');
    const bulkBar = document.getElementById('bulkBar');
    const bulkCount = document.getElementById('bulkCount');
    if (!selectAll || !bulkBar) return;

    function updateBulkBar() {
        const checked = document.querySelectorAll('.row-select:checked');
        if (checked.length > 0) {
            bulkBar.style.display = 'flex';
            bulkCount.textContent = checked.length + ' rreshta te zgjedhur';
        } else {
            bulkBar.style.display = 'none';
        }
    }

    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.row-select').forEach(cb => cb.checked = this.checked);
        updateBulkBar();
    });

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('row-select')) updateBulkBar();
    });
})();

function clearBulkSelection() {
    document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    document.getElementById('bulkBar').style.display = 'none';
}

function applyBulkPayment() {
    const newPayment = document.getElementById('bulkPayment').value;
    if (!newPayment) { showToast('Zgjidhni menyre pagese', 'error'); return; }
    const ids = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => parseInt(cb.value));
    if (!ids.length) return;
    if (!confirm('Ndrysho menyren e pageses per ' + ids.length + ' rreshta ne "' + newPayment + '"?')) return;

    // Send batch updates
    const promises = ids.map(id =>
        fetch('/api/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ table: 'distribuimi', id, changes: [{ field: 'menyra_e_pageses', value: newPayment }] })
        }).then(r => r.json())
    );

    Promise.all(promises).then(results => {
        const ok = results.filter(r => r.success).length;
        showToast(ok + '/' + ids.length + ' u ndryshuan me sukses');
        setTimeout(() => location.reload(), 500);
    }).catch(() => showToast('Gabim ne server', 'error'));
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Distribuimi', 'distribuimi', $content);
