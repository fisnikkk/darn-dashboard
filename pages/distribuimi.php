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
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
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

$stokuStmt = $db->prepare("SELECT COALESCE(SUM(sasia) - SUM(boca_te_kthyera), 0) FROM distribuimi d {$whereSQL}");
$stokuStmt->execute($params);
$stokuTotal = $stokuStmt->fetchColumn();

// Payment types for dropdown
$paymentTypes = ['Cash', 'Bank', 'Po (Fature te rregullte) cash', 'Po (Fature te rregullte) banke', 'No payment', 'Dhurate'];
$paymentJSON = json_encode($paymentTypes);

// Unique clients for filter (from distribuimi + kontrata)
$distClientsAll = $db->query("SELECT DISTINCT klienti FROM distribuimi ORDER BY klienti")->fetchAll(PDO::FETCH_COLUMN);
$kontrataClientsAll = $db->query("SELECT DISTINCT name_from_database FROM kontrata WHERE name_from_database IS NOT NULL AND name_from_database != '' ORDER BY name_from_database")->fetchAll(PDO::FETCH_COLUMN);
$clients = array_unique(array_merge($distClientsAll, $kontrataClientsAll));
sort($clients);

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
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtro</button>
            <a href="distribuimi.php" class="btn btn-outline btn-sm">Pastro</a>
        </form>
    </div>
    
    <!-- Data Table -->
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="distribuimi" data-server-sort="true">
                <thead>
                    <tr>
                        <?= sortTh('row_nr', '#', $sortCol, $sortDir) ?>
                        <?= sortTh('klienti', 'Klienti', $sortCol, $sortDir) ?>
                        <?= sortTh('data', 'Data', $sortCol, $sortDir) ?>
                        <?= sortTh('sasia', 'Sasia', $sortCol, $sortDir, 'num') ?>
                        <?= sortTh('boca_te_kthyera', 'Boca të kthyera', $sortCol, $sortDir, 'num') ?>
                        <th class="num">Boca tek biznesi</th>
                        <th class="num">Boca total terren</th>
                        <?= sortTh('litra', 'Litra', $sortCol, $sortDir, 'num') ?>
                        <?= sortTh('cmimi', 'Çmimi', $sortCol, $sortDir, 'num') ?>
                        <?= sortTh('pagesa', 'Pagesa', $sortCol, $sortDir, 'num') ?>
                        <?= sortTh('menyra_e_pageses', 'Mënyra e pagesës', $sortCol, $sortDir) ?>
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

<?php
$content = ob_get_clean();
renderLayout('Distribuimi', 'distribuimi', $content);
