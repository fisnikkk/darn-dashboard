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

// Babi Cash Total (same calculation as Notes page)
$babiPayments = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'cash' THEN pagesa ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END), 0) as fature_cash
    FROM distribuimi
    WHERE data >= '2022-08-29'
")->fetch();
$babiExpenses = (float)$db->query("
    SELECT COALESCE(SUM(shuma), 0) FROM shpenzimet
    WHERE LOWER(TRIM(lloji_i_pageses)) = 'cash'
    AND (data_e_pageses >= '2022-08-29' OR data_e_pageses IS NULL OR data_e_pageses = '0000-00-00')
")->fetchColumn();
$babiManual = 281.9;
$babiGasCash = $babiPayments['cash'] + $babiPayments['fature_cash'] - $babiExpenses + $babiManual;
$babiProductCash = (float)$db->query("
    SELECT COALESCE(SUM(totali), 0) FROM shitje_produkteve
    WHERE LOWER(TRIM(menyra_pageses)) = 'cash' AND data >= '2022-09-07'
")->fetchColumn();
$babiCashTotal = $babiGasCash + $babiProductCash;

// Filters
$filterClient = $_GET['klienti'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterPayment = $_GET['payment'] ?? '';
// Multi-select column filters
$fKlienti = getFilterParam('f_klienti');
$fMenyra = getFilterParam('f_menyra');
$fSasia = getFilterParam('f_sasia');
$fBocaKth = getFilterParam('f_boca_kth');
$fLitra = getFilterParam('f_litra');
$fCmimi = getFilterParam('f_cmimi');
$fPagesa = getFilterParam('f_pagesa');
$fDataFp = getFilterParam('f_data_fp');
$fKoment = getFilterParam('f_komentet');
$fLitratTot = getFilterParam('f_litrat_tot');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, [100, 500, 1000, 5000])) $perPage = 100;
$offset = ($page - 1) * $perPage;

// Server-side sorting
$sortCol = $_GET['sort'] ?? 'data';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
$allowedSorts = ['row_nr','klienti','data','sasia','boca_te_kthyera','litra','cmimi','pagesa','menyra_e_pageses','data_e_fletepageses','fatura_e_derguar','litrat_total','updated_at','created_at','boca_running','boca_total'];
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
$filterRowId = trim($_GET['row_id'] ?? '');
$where = [];
$params = [];
if ($filterRowId) { $where[] = "(d.row_nr = ? OR d.id = ?)"; $params[] = $filterRowId; $params[] = $filterRowId; }
if ($filterClient) { $where[] = "d.klienti = ?"; $params[] = $filterClient; }
if ($filterDateFrom) { $where[] = "d.data >= ?"; $params[] = $filterDateFrom; }
if ($filterDateTo) { $where[] = "d.data <= ?"; $params[] = $filterDateTo; }
if ($filterPayment) { $where[] = "LOWER(TRIM(d.menyra_e_pageses)) = LOWER(TRIM(?))"; $params[] = $filterPayment; }
// Multi-select column filters
if ($fKlienti) { $fin = buildFilterIn($fKlienti, 'klienti', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fMenyra) { $fin = buildFilterIn($fMenyra, 'menyra_e_pageses', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fSasia) { $fin = buildFilterIn($fSasia, 'sasia', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fBocaKth) { $fin = buildFilterIn($fBocaKth, 'boca_te_kthyera', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fLitra) { $fin = buildFilterIn($fLitra, 'litra', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fCmimi) { $fin = buildFilterIn($fCmimi, 'cmimi', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fPagesa) { $fin = buildFilterIn($fPagesa, 'pagesa', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fDataFp) { $fin = buildFilterIn($fDataFp, 'data_e_fletepageses', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fKoment) { $fin = buildFilterIn($fKoment, 'fatura_e_derguar', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fLitratTot) { $fin = buildFilterIn($fLitratTot, 'litrat_total', 'd'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total rows
$countSQL = "SELECT COUNT(*) FROM distribuimi d {$whereSQL}";
$stmt = $db->prepare($countSQL);
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// Map sort column to SQL expression (calculated columns use t. prefix)
$calcSortsDist = ['boca_running' => 't.boca_running', 'boca_total' => 't.boca_total'];
$sortExpr = isset($calcSortsDist[$sortCol]) ? $calcSortsDist[$sortCol] : "d.{$sortCol}";

// Fetch data with running totals via CTE — enables server-side sorting across ALL pages
$sql = "
    WITH totals AS (
        SELECT id,
            SUM(sasia - boca_te_kthyera) OVER (
                PARTITION BY LOWER(klienti)
                ORDER BY data ASC, id ASC
            ) as boca_running,
            SUM(sasia - boca_te_kthyera) OVER (
                ORDER BY data ASC, id ASC
            ) as boca_total
        FROM distribuimi
    )
    SELECT d.id, d.row_nr, d.klienti, d.data, d.sasia, d.boca_te_kthyera,
        d.litra, d.cmimi, d.pagesa, d.menyra_e_pageses,
        d.data_e_fletepageses, d.fatura_e_derguar, d.litrat_total,
        t.boca_running, t.boca_total
    FROM distribuimi d
    JOIN totals t ON t.id = d.id
    {$whereSQL}
    ORDER BY {$sortExpr} {$sortDir}, d.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
// Summary cards — respect active filters
$summStmt = $db->prepare("SELECT COUNT(DISTINCT LOWER(klienti)) FROM distribuimi d {$whereSQL}");
$summStmt->execute($params);
$uniqueClients = $summStmt->fetchColumn();

// Stoku total ne terren: ALWAYS global (unfiltered) — per Albulena's requirement
$stokuTotal = $db->query("SELECT COALESCE(SUM(sasia) - SUM(boca_te_kthyera), 0) FROM distribuimi")->fetchColumn();

// Payment types for dropdown
$paymentTypes = ['CASH', 'BANK', 'PO (FATURE TE RREGULLTE) CASH', 'PO (FATURE TE RREGULLTE) BANKE', 'NO PAYMENT', 'DHURATE'];
$paymentJSON = json_encode($paymentTypes);

// Unique clients for filter (from distribuimi + kontrata)
$distClientsAll = $db->query("SELECT DISTINCT klienti FROM distribuimi ORDER BY klienti")->fetchAll(PDO::FETCH_COLUMN);
$kontrataClientsAll = $db->query("SELECT DISTINCT name_from_database FROM kontrata WHERE name_from_database IS NOT NULL AND name_from_database != '' ORDER BY name_from_database")->fetchAll(PDO::FETCH_COLUMN);
$clients = array_unique(array_merge($distClientsAll, $kontrataClientsAll));
sort($clients);

// Distinct values for Excel-like column filters
$distMenyraVals = $db->query("SELECT DISTINCT menyra_e_pageses FROM distribuimi WHERE menyra_e_pageses IS NOT NULL AND menyra_e_pageses != '' ORDER BY menyra_e_pageses")->fetchAll(PDO::FETCH_COLUMN);
$distSasiaVals = $db->query("SELECT DISTINCT CAST(sasia AS CHAR) FROM distribuimi WHERE sasia IS NOT NULL ORDER BY sasia")->fetchAll(PDO::FETCH_COLUMN);
$distBocaKthVals = $db->query("SELECT DISTINCT CAST(boca_te_kthyera AS CHAR) FROM distribuimi WHERE boca_te_kthyera IS NOT NULL ORDER BY boca_te_kthyera")->fetchAll(PDO::FETCH_COLUMN);
$distLitraVals = $db->query("SELECT DISTINCT CAST(litra AS CHAR) FROM distribuimi WHERE litra IS NOT NULL ORDER BY litra")->fetchAll(PDO::FETCH_COLUMN);
$distCmimiVals = $db->query("SELECT DISTINCT CAST(cmimi AS CHAR) FROM distribuimi WHERE cmimi IS NOT NULL ORDER BY cmimi")->fetchAll(PDO::FETCH_COLUMN);
$distPagesaVals = $db->query("SELECT DISTINCT CAST(pagesa AS CHAR) FROM distribuimi WHERE pagesa IS NOT NULL ORDER BY pagesa")->fetchAll(PDO::FETCH_COLUMN);
$distDataFpVals = $db->query("SELECT DISTINCT CAST(data_e_fletepageses AS CHAR) AS d FROM distribuimi WHERE data_e_fletepageses IS NOT NULL AND data_e_fletepageses > '0000-00-00' ORDER BY d")->fetchAll(PDO::FETCH_COLUMN);
$distKomentVals = $db->query("SELECT DISTINCT fatura_e_derguar FROM distribuimi WHERE fatura_e_derguar IS NOT NULL AND fatura_e_derguar != '' ORDER BY fatura_e_derguar")->fetchAll(PDO::FETCH_COLUMN);
$distLitratTotVals = $db->query("SELECT DISTINCT CAST(litrat_total AS CHAR) FROM distribuimi WHERE litrat_total IS NOT NULL ORDER BY litrat_total")->fetchAll(PDO::FETCH_COLUMN);

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
    <div class="summary-card">
        <div class="label">Babi Cash Total</div>
        <div class="value">&euro; <?= eur($babiCashTotal) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Sipas raportit</div>
        <div class="value"><input type="number" id="babiRaportiDist" step="0.01" placeholder="Shëno vlerën..." style="width:140px;padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.95rem;text-align:right;"></div>
    </div>
    <div class="summary-card" id="babiDiffCardDist">
        <div class="label">Diferenca</div>
        <div class="value" id="babiDiffDist" style="font-size:1.1rem;">-</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Distribuimi - Të dhënat</h3>
        <button class="btn btn-sm" onclick="openModal('gdModal'); setTimeout(function(){ gdCheckStatus(); gdLoadHistory(); }, 100);" style="margin-right:6px;background:#059669;color:#fff;border:none;"><i class="fas fa-database"></i> Merr nga GoDaddy</button>
        <button class="btn btn-primary btn-sm" onclick="openModal('pasteDist')" style="margin-right:6px;"><i class="fas fa-paste"></i> Ngjit nga Excel</button>
        <button class="btn btn-primary btn-sm" onclick="openModal('addModal')">
            <i class="fas fa-plus"></i> Shto rresht
        </button>
    </div>
    
    <!-- Filters -->
    <div class="filters">
        <form method="GET" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">
            <?php if ($sortCol !== 'data' || $sortDir !== 'DESC'): ?>
            <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
            <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
            <?php endif; ?>
            <div class="form-group" style="min-width:auto;">
                <label># / ID</label>
                <input type="text" name="row_id" value="<?= e($_GET['row_id'] ?? '') ?>" placeholder="ID..." style="width:60px;padding:6px 8px;">
            </div>
            <div class="form-group" style="min-width:auto;">
                <label>Klienti</label>
                <input type="text" name="klienti" value="<?= e($filterClient) ?>" placeholder="Kërko klient..." list="distFilterKlientList" style="max-width:180px;padding:6px 8px;" autocomplete="off">
                <datalist id="distFilterKlientList">
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= e($c) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group" style="min-width:auto;">
                <label>Data nga</label>
                <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>" style="padding:6px 8px;">
            </div>
            <div class="form-group" style="min-width:auto;">
                <label>Data deri</label>
                <input type="date" name="date_to" value="<?= e($filterDateTo) ?>" style="padding:6px 8px;">
            </div>
            <div class="form-group" style="min-width:auto;">
                <label>Mënyra</label>
                <select name="payment" style="padding:6px 8px;">
                    <option value="">Të gjitha</option>
                    <?php foreach ($paymentTypes as $pt): ?>
                    <option value="<?= e($pt) ?>" <?= $filterPayment === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
                    <?php endforeach; ?>
                </select>
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
                        <?= withFilter(sortTh('sasia', 'Sasia', $sortCol, $sortDir, 'num'), 'f_sasia', $distSasiaVals) ?>
                        <?= withFilter(sortTh('boca_te_kthyera', 'Boca të kthyera', $sortCol, $sortDir, 'num'), 'f_boca_kth', $distBocaKthVals) ?>
                        <?= sortTh('boca_running', 'Boca tek biznesi', $sortCol, $sortDir, 'num') ?>
                        <?= sortTh('boca_total', 'Boca total terren', $sortCol, $sortDir, 'num') ?>
                        <?= withFilter(sortTh('litra', 'Litra', $sortCol, $sortDir, 'num'), 'f_litra', $distLitraVals) ?>
                        <?= withFilter(sortTh('cmimi', 'Çmimi', $sortCol, $sortDir, 'num'), 'f_cmimi', $distCmimiVals) ?>
                        <?= withFilter(sortTh('pagesa', 'Pagesa', $sortCol, $sortDir, 'num'), 'f_pagesa', $distPagesaVals) ?>
                        <?= withFilter(sortTh('menyra_e_pageses', 'Mënyra e pagesës', $sortCol, $sortDir), 'f_menyra', $distMenyraVals) ?>
                        <?= withFilter(sortTh('data_e_fletepageses', 'Data fletëpagesës', $sortCol, $sortDir), 'f_data_fp', $distDataFpVals) ?>
                        <?= withFilter(sortTh('fatura_e_derguar', 'Komentet', $sortCol, $sortDir), 'f_komentet', $distKomentVals) ?>
                        <?= withFilter(sortTh('litrat_total', 'Litrat total', $sortCol, $sortDir, 'num'), 'f_litrat_tot', $distLitratTotVals) ?>
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
                            <?= $r['boca_running'] !== null ? (int)$r['boca_running'] : '-' ?>
                        </td>
                        <td class="num" style="color:var(--text-muted);">
                            <?= $r['boca_total'] !== null ? num($r['boca_total']) : '-' ?>
                        </td>
                        <td class="num editable" data-field="litra" data-type="number"><?= $r['litra'] ?></td>
                        <td class="num editable" data-field="cmimi" data-type="number"><?= $r['cmimi'] ?></td>
                        <td class="amount editable" data-field="pagesa" data-type="number" style="font-weight:600;">&euro; <?= eur($r['pagesa']) ?></td>
                        <td class="editable" data-field="menyra_e_pageses" data-type="select" 
                            data-options="<?= e($paymentJSON) ?>">
                            <?php
                            $badge = match(strtolower(trim($r['menyra_e_pageses'] ?? ''))) {
                                'cash' => 'badge-cash',
                                'bank' => 'badge-bank',
                                'no payment' => 'badge-nopay',
                                'dhurate' => 'badge-dhurate',
                                default => 'badge-fature'
                            };
                            ?>
                            <span class="badge <?= $badge ?>"><?= e($r['menyra_e_pageses']) ?></span>
                        </td>
                        <td class="editable" data-field="data_e_fletepageses" data-type="date"><?= $r['data_e_fletepageses'] ?></td>
                        <td class="editable truncate" data-field="fatura_e_derguar" title="<?= e($r['fatura_e_derguar']) ?>"><?= e($r['fatura_e_derguar']) ?></td>
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
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Data fletëpagesës</label>
                        <input type="date" name="data_e_fletepageses">
                    </div>
                    <div class="form-group">
                        <label>Komentet</label>
                        <input type="text" name="fatura_e_derguar">
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
                <strong>Klienti | Data | Sasia | Boca te kthyera | Litra | Cmimi | Pagesa | Menyra e pageses | Data fletepageses | Komentet</strong>
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
        const data_e_fletepageses = parseDateDist(cols[8]);
        const fatura_e_derguar = (cols[9] || '').trim();

        // Auto-calculate litrat_total = sasia * litra
        const s = sasia ?? 0;
        const l = litra ?? 0;
        const litrat_total = s * l;
        const litrat_e_konvertuara = l;

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
            data_e_fletepageses: data_e_fletepageses,
            fatura_e_derguar: fatura_e_derguar || null,
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

<!-- GoDaddy Import Modal -->
<div class="modal-overlay" id="gdModal">
    <div class="modal" style="max-width:850px;">
        <div class="modal-header">
            <h3><i class="fas fa-database" style="color:#059669;"></i> Merr te dhena nga GoDaddy</h3>
            <button class="btn btn-outline btn-sm" onclick="closeModal('gdModal')">&times;</button>
        </div>
        <div class="modal-body" style="padding:16px;">
            <!-- Connection status -->
            <div id="gdStatus" style="padding:10px 14px;border-radius:6px;background:#f8fafc;border:1px solid var(--border);margin-bottom:14px;font-size:0.85rem;">
                <i class="fas fa-spinner fa-spin"></i> Duke kontrolluar lidhjen me GoDaddy...
            </div>

            <!-- Date range picker -->
            <div id="gdDatePicker" style="display:none;">
                <div style="display:flex;gap:12px;align-items:flex-end;margin-bottom:14px;">
                    <div class="form-group" style="margin:0;">
                        <label>Data nga</label>
                        <input type="date" id="gdDateFrom" style="padding:6px 10px;">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Data deri</label>
                        <input type="date" id="gdDateTo" style="padding:6px 10px;">
                    </div>
                    <button class="btn btn-sm" onclick="gdPreview()" style="background:#0284c7;color:#fff;border:none;height:36px;">
                        <i class="fas fa-search"></i> Kërko
                    </button>
                </div>
            </div>

            <!-- Preview table -->
            <div id="gdPreviewWrap" style="display:none;">
                <div id="gdPreviewInfo" style="font-size:0.85rem;margin-bottom:8px;"></div>
                <div style="max-height:400px;overflow:auto;border:1px solid var(--border);border-radius:6px;">
                    <table class="data-table" style="font-size:0.78rem;margin:0;">
                        <thead>
                            <tr>
                                <th style="width:30px;"></th>
                                <th>Klienti</th>
                                <th>Data</th>
                                <th>Sasia</th>
                                <th>Boca kth.</th>
                                <th>Litra</th>
                                <th>Çmimi</th>
                                <th>Pagesa</th>
                                <th>Mënyra</th>
                                <th>Koment</th>
                            </tr>
                        </thead>
                        <tbody id="gdPreviewBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Results -->
            <div id="gdResults" style="display:none;padding:14px;border-radius:8px;margin-top:12px;"></div>

            <!-- Import History -->
            <div id="gdHistory" style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px;">
                <div style="font-size:0.82rem;font-weight:600;color:var(--text-muted);margin-bottom:8px;">
                    <i class="fas fa-history"></i> Importet e fundit
                </div>
                <div id="gdHistoryList" style="font-size:0.8rem;">
                    <span style="color:var(--text-muted);">Duke ngarkuar...</span>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('gdModal')">Mbyll</button>
            <button type="button" class="btn btn-sm" id="gdImportBtn" onclick="gdImport()" style="background:#059669;color:#fff;border:none;display:none;">
                <i class="fas fa-download"></i> Importo
            </button>
        </div>
    </div>
</div>

<script>
// ── GoDaddy Import ──
function gdCheckStatus() {
    const el = document.getElementById('gdStatus');
    const picker = document.getElementById('gdDatePicker');
    el.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke kontrolluar lidhjen me GoDaddy...';
    el.style.background = '#f8fafc';
    picker.style.display = 'none';
    document.getElementById('gdPreviewWrap').style.display = 'none';
    document.getElementById('gdResults').style.display = 'none';
    document.getElementById('gdImportBtn').style.display = 'none';

    console.log('[GD] Starting status check...');
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '/api/fetch_godaddy.php?action=status&_=' + Date.now(), true);
    xhr.timeout = 15000;
    xhr.onload = function() {
        console.log('[GD] Response:', xhr.status, xhr.responseText.substring(0, 200));
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.connected) {
                el.innerHTML = '<i class="fas fa-check-circle" style="color:#059669;"></i> <strong>Lidhja aktive</strong> — ' + (data.total_rows || 0).toLocaleString() + ' rreshta ne GoDaddy';
                el.style.background = '#f0fdf4';
                picker.style.display = 'block';
            } else {
                el.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> ' + (data.reason || 'Nuk mund te lidhem');
                el.style.background = '#fef2f2';
            }
        } catch(e) {
            console.error('[GD] JSON parse error:', e);
            el.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> Gabim: pergjigje jo valide';
            el.style.background = '#fef2f2';
        }
    };
    xhr.onerror = function() {
        console.error('[GD] Network error');
        el.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> Gabim ne lidhje me serverin';
        el.style.background = '#fef2f2';
    };
    xhr.ontimeout = function() {
        console.error('[GD] Timeout after 15s');
        el.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> Timeout — serveri nuk pergjigjet';
        el.style.background = '#fef2f2';
    };
    xhr.send();
}

function gdPreview() {
    const dateFrom = document.getElementById('gdDateFrom').value;
    const dateTo = document.getElementById('gdDateTo').value;
    if (!dateFrom || !dateTo) { showToast('Zgjidh te dyja datat', 'error'); return; }
    if (dateFrom > dateTo) { showToast('Data "nga" duhet te jete para dates "deri"', 'error'); return; }

    const wrap = document.getElementById('gdPreviewWrap');
    const body = document.getElementById('gdPreviewBody');
    const info = document.getElementById('gdPreviewInfo');
    const importBtn = document.getElementById('gdImportBtn');
    wrap.style.display = 'block';
    body.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Duke kerkuar...</td></tr>';
    info.innerHTML = '';
    importBtn.style.display = 'none';

    fetch('/api/fetch_godaddy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'preview', date_from: dateFrom, date_to: dateTo })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            body.innerHTML = '<tr><td colspan="10" style="color:#dc2626;padding:12px;">' + (data.error || 'Gabim') + '</td></tr>';
            return;
        }

        if (!data.rows || !data.rows.length) {
            body.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--text-muted);">Asgje nuk u gjet per kete periudhe</td></tr>';
            return;
        }

        info.innerHTML = '<strong>' + data.total_found + '</strong> rreshta u gjeten — <strong style="color:#059669;">' + data.new_rows + '</strong> te reja, <strong style="color:#f59e0b;">' + data.duplicates + '</strong> ekzistojne tashme';

        body.innerHTML = data.rows.map(r => {
            const isDup = r._duplicate;
            const style = isDup ? 'opacity:0.4;text-decoration:line-through;' : '';
            const icon = isDup ? '<i class="fas fa-check" style="color:#f59e0b;" title="Ekziston tashme"></i>' : '<i class="fas fa-plus" style="color:#059669;" title="E re"></i>';
            return `<tr style="${style}">
                <td>${icon}</td>
                <td>${r.klienti || '-'}</td>
                <td>${r.data || '-'}</td>
                <td class="num">${r.sasia}</td>
                <td class="num">${r.boca_te_kthyera}</td>
                <td class="num">${r.litra}</td>
                <td class="num">${r.cmimi}</td>
                <td class="num" style="font-weight:600;">&euro; ${parseFloat(r.pagesa).toFixed(2)}</td>
                <td><span class="badge ${r.menyra_e_pageses === 'CASH' ? 'badge-cash' : r.menyra_e_pageses === 'BANK' ? 'badge-bank' : 'badge-fature'}">${r.menyra_e_pageses}</span></td>
                <td>${r.fatura_e_derguar || '-'}</td>
            </tr>`;
        }).join('');

        if (data.new_rows > 0) {
            importBtn.style.display = 'inline-flex';
        }
    })
    .catch(() => {
        body.innerHTML = '<tr><td colspan="10" style="color:#dc2626;padding:12px;">Gabim ne kerkimin e te dhenave</td></tr>';
    });
}

function gdImport() {
    const dateFrom = document.getElementById('gdDateFrom').value;
    const dateTo = document.getElementById('gdDateTo').value;
    if (!dateFrom || !dateTo) return;

    if (!confirm('Importo te dhenat e reja nga GoDaddy per periudhen ' + dateFrom + ' deri ' + dateTo + '?\n\nVetem rreshtat e reja do te shtohen (duplikatet anashkalohen).')) return;

    const btn = document.getElementById('gdImportBtn');
    const results = document.getElementById('gdResults');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke importuar...';
    results.style.display = 'block';
    results.style.background = '#f0fdf4';
    results.style.border = '1px solid #bbf7d0';
    results.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke importuar... Mos e mbyll dritaren.';

    fetch('/api/fetch_godaddy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'import', date_from: dateFrom, date_to: dateTo })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-download"></i> Importo';

        if (data.success) {
            results.style.background = '#f0fdf4';
            results.style.border = '1px solid #bbf7d0';
            results.innerHTML = '<div style="color:#059669;font-weight:600;"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
            if (data.inserted > 0) {
                results.innerHTML += '<div style="margin-top:10px;"><button class="btn btn-primary btn-sm" onclick="location.reload()"><i class="fas fa-redo"></i> Rifresko faqen</button></div>';
                gdLoadHistory();
            }
        } else {
            results.style.background = '#fef2f2';
            results.style.border = '1px solid #fecaca';
            results.innerHTML = '<div style="color:#dc2626;"><i class="fas fa-times-circle"></i> ' + (data.error || 'Gabim') + '</div>';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-download"></i> Importo';
        results.style.background = '#fef2f2';
        results.style.border = '1px solid #fecaca';
        results.innerHTML = '<div style="color:#dc2626;"><i class="fas fa-times-circle"></i> ' + err.message + '</div>';
    });
}

function gdLoadHistory() {
    const list = document.getElementById('gdHistoryList');
    fetch('/api/fetch_godaddy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'history' })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.batches || !data.batches.length) {
            list.innerHTML = '<span style="color:var(--text-muted);">Asnje import ende.</span>';
            return;
        }
        list.innerHTML = data.batches.map(b => {
            const date = new Date(b.imported_at).toLocaleDateString('sq-AL', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
            const range = (b.date_from || '?') + ' — ' + (b.date_to || '?');
            return `<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:#f8fafc;border:1px solid var(--border);border-radius:5px;margin-bottom:4px;">
                <div>
                    <strong>${b.row_count}</strong> rreshta
                    <span style="color:var(--text-muted);margin:0 6px;">|</span>
                    <span style="color:var(--text-muted);">${range}</span>
                    <span style="color:var(--text-muted);margin:0 6px;">|</span>
                    <span style="color:var(--text-muted);font-size:0.75rem;">${date}</span>
                </div>
                <button class="btn btn-sm" onclick="gdUndo('${b.batch_id}', ${b.row_count})" style="background:#dc2626;color:#fff;border:none;padding:3px 10px;font-size:0.75rem;">
                    <i class="fas fa-undo"></i> Kthe
                </button>
            </div>`;
        }).join('');
    })
    .catch(() => {
        list.innerHTML = '<span style="color:#dc2626;">Gabim ne ngarkim.</span>';
    });
}

function gdUndo(batchId, count) {
    if (!confirm('Je i sigurt? Do te fshihen ' + count + ' rreshta qe u importuan nga GoDaddy.\n\nKy veprim nuk mund te kthehet.')) return;

    const list = document.getElementById('gdHistoryList');
    list.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke fshire...';

    fetch('/api/fetch_godaddy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'undo', batch_id: batchId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            gdLoadHistory();
        } else {
            showToast(data.error || 'Gabim', 'error');
            gdLoadHistory();
        }
    })
    .catch(() => {
        showToast('Gabim ne fshirje', 'error');
        gdLoadHistory();
    });
}

// Babi Cash Diferenca calculation (same as Notes page)
(function() {
    var babiCash = <?= json_encode(round($babiCashTotal, 2)) ?>;
    var input = document.getElementById('babiRaportiDist');
    var diff = document.getElementById('babiDiffDist');
    var card = document.getElementById('babiDiffCardDist');

    // Load saved value
    var saved = localStorage.getItem('babiRaportiDist');
    if (saved) { input.value = saved; calcDiff(); }

    input.addEventListener('input', function() {
        localStorage.setItem('babiRaportiDist', this.value);
        calcDiff();
    });

    function calcDiff() {
        var val = parseFloat(input.value);
        if (isNaN(val)) { diff.textContent = '-'; card.style.background = ''; return; }
        var d = babiCash - val;
        diff.textContent = (d >= 0 ? '+' : '') + d.toFixed(2) + ' EUR';
        diff.style.color = Math.abs(d) < 0.01 ? '#16a34a' : '#dc2626';
    }
})();
</script>

<?php
$content = ob_get_clean();
renderLayout('Distribuimi', 'distribuimi', $content);
