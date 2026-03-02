<?php
/**
 * DARN Dashboard - Shpenzimet (Expenses)
 * Input form with proper field formats: date, number, dropdown
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, [100, 500, 1000, 5000])) $perPage = 100;
$offset = ($page - 1) * $perPage;

$filterType = $_GET['lloji'] ?? '';
$filterPayment = $_GET['pagesa'] ?? '';
// Multi-select column filters
$fArsyetimi = getFilterParam('f_arsyetimi');
$fLlojiPag = getFilterParam('f_lloji_pag');
$fLlojiTrans = getFilterParam('f_lloji_trans');

// Server-side sorting
$sortCol = $_GET['sort'] ?? 'data_e_pageses';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
$allowedSorts = ['data_e_pageses','shuma','arsyetimi','lloji_i_pageses','lloji_i_transaksionit','pershkrim_i_detajuar','nafta_ne_litra','numri_i_fatures','fatura_e_rregullte'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'data_e_pageses';
if (!in_array($sortDir, ['ASC','DESC'])) $sortDir = 'DESC';

function sortThShp($col, $label, $currentSort, $currentDir, $class = '') {
    $isActive = ($currentSort === $col);
    $newDir = ($isActive && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($_GET, ['sort' => $col, 'dir' => $newDir, 'page' => 1]);
    $url = '?' . http_build_query($params);
    $icon = $isActive ? ($currentDir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $activeStyle = $isActive ? 'color:var(--primary);font-weight:600;' : '';
    $classes = trim(($class ? $class . ' ' : '') . 'server-sort');
    return "<th class=\"{$classes}\" onclick=\"window.location.href='{$url}';return false;\" style=\"cursor:pointer;user-select:none;{$activeStyle}\">{$label} <i class=\"fas {$icon}\"></i></th>";
}

$where = [];
$params = [];
if ($filterType) { $where[] = "LOWER(TRIM(lloji_i_transaksionit)) = LOWER(TRIM(?))"; $params[] = $filterType; }
if ($filterPayment) { $where[] = "LOWER(TRIM(lloji_i_pageses)) = LOWER(TRIM(?))"; $params[] = $filterPayment; }
// Multi-select column filters
if ($fArsyetimi) { $fin = buildFilterIn($fArsyetimi, 'arsyetimi'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fLlojiPag) { $fin = buildFilterIn($fLlojiPag, 'lloji_i_pageses'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
if ($fLlojiTrans) { $fin = buildFilterIn($fLlojiTrans, 'lloji_i_transaksionit'); $where[] = $fin['sql']; $params = array_merge($params, $fin['params']); }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = $db->prepare("SELECT COUNT(*) FROM shpenzimet {$whereSQL}");
$totalRows->execute($params);
$totalRows = $totalRows->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$stmt = $db->prepare("SELECT * FROM shpenzimet {$whereSQL} ORDER BY {$sortCol} {$sortDir}, id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Totals — each card uses only the OTHER filter dimension to avoid contradictions
// Cash/Bank cards: only apply the transaction-type filter (not payment-type, since they ARE the payment breakdown)
// Plin/Shpenzim cards: only apply the payment-type filter (not transaction-type, since they ARE the transaction breakdown)
$cashBankWhere = '';
$cashBankParams = [];
if ($filterType) { $cashBankWhere = "AND LOWER(TRIM(lloji_i_transaksionit)) = LOWER(TRIM(?))"; $cashBankParams[] = $filterType; }

$plinShpenzimWhere = '';
$plinShpenzimParams = [];
if ($filterPayment) { $plinShpenzimWhere = "AND LOWER(TRIM(lloji_i_pageses)) = LOWER(TRIM(?))"; $plinShpenzimParams[] = $filterPayment; }

$totalCashStmt = $db->prepare("SELECT COALESCE(SUM(shuma),0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='cash' {$cashBankWhere}");
$totalCashStmt->execute($cashBankParams);
$totalCash = $totalCashStmt->fetchColumn();
$totalBankeStmt = $db->prepare("SELECT COALESCE(SUM(shuma),0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_pageses))='bank' {$cashBankWhere}");
$totalBankeStmt->execute($cashBankParams);
$totalBanke = $totalBankeStmt->fetchColumn();
$totalPlinStmt = $db->prepare("SELECT COALESCE(SUM(shuma),0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='pagesa per plin' {$plinShpenzimWhere}");
$totalPlinStmt->execute($plinShpenzimParams);
$totalPlin = $totalPlinStmt->fetchColumn();
$totalShpenzimStmt = $db->prepare("SELECT COALESCE(SUM(shuma),0) FROM shpenzimet WHERE LOWER(TRIM(lloji_i_transaksionit))='shpenzim' {$plinShpenzimWhere}");
$totalShpenzimStmt->execute($plinShpenzimParams);
$totalShpenzim = $totalShpenzimStmt->fetchColumn();

// Dropdown options from data
$llojetTrans = $db->query("SELECT DISTINCT lloji_i_transaksionit FROM shpenzimet WHERE lloji_i_transaksionit IS NOT NULL ORDER BY lloji_i_transaksionit")->fetchAll(PDO::FETCH_COLUMN);
$llojetPag = $db->query("SELECT DISTINCT lloji_i_pageses FROM shpenzimet WHERE lloji_i_pageses IS NOT NULL ORDER BY lloji_i_pageses")->fetchAll(PDO::FETCH_COLUMN);
$arsyet = $db->query("SELECT DISTINCT arsyetimi FROM shpenzimet WHERE arsyetimi IS NOT NULL ORDER BY arsyetimi")->fetchAll(PDO::FETCH_COLUMN);

$transJSON = json_encode($llojetTrans);
$pagJSON = json_encode($llojetPag);

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total Shpenzime Cash</div>
        <div class="value">&euro; <?= eur($totalCash) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total Shpenzime Banke</div>
        <div class="value">&euro; <?= eur($totalBanke) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Pagesa per plin</div>
        <div class="value">&euro; <?= eur($totalPlin) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Shpenzime tjera</div>
        <div class="value">&euro; <?= eur($totalShpenzim) ?></div>
    </div>
</div>

<!-- Input Form -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i> Shto shpenzim të ri</h3>
    </div>
    <div class="card-body padded">
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="shpenzimet">
            <div class="form-row">
                <div class="form-group">
                    <label>Data e pagesës *</label>
                    <input type="date" name="data_e_pageses" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Shuma (€) *</label>
                    <input type="number" name="shuma" step="0.01" required>
                </div>
                <div class="form-group" id="arsyet-group">
                    <label>Arsyetimi / Kategoria</label>
                    <select name="arsyetimi" id="arsyet-select">
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($arsyet as $a): ?><option value="<?= e($a) ?>"><?= e($a) ?></option><?php endforeach; ?>
                        <option value="__new__">+ Shto të re...</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Lloji i pagesës *</label>
                    <select name="lloji_i_pageses" required>
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($llojetPag as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Lloji i transaksionit *</label>
                    <select name="lloji_i_transaksionit" required>
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($llojetTrans as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Përshkrim i detajuar</label>
                    <input type="text" name="pershkrim_i_detajuar">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Nafta në litra</label>
                    <input type="number" name="nafta_ne_litra" step="0.01">
                </div>
                <div class="form-group">
                    <label>Numri i faturës</label>
                    <input type="text" name="numri_i_fatures">
                </div>
                <div class="form-group">
                    <label>Faturë e rregullt</label>
                    <select name="fatura_e_rregullte">
                        <option value="">Jo</option>
                        <option value="Po">Po</option>
                    </select>
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj shpenzimin</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-header">
        <h3>Shpenzimet (<?= num($totalRows) ?> rreshta)</h3>
    </div>
    <div class="filters">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;">
            <div class="form-group">
                <label>Lloji transaksionit</label>
                <select name="lloji">
                    <option value="">Të gjitha</option>
                    <?php foreach ($llojetTrans as $l): ?>
                    <option value="<?= e($l) ?>" <?= $filterType === $l ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Pagesa</label>
                <select name="pagesa">
                    <option value="">Të gjitha</option>
                    <?php foreach ($llojetPag as $l): ?>
                    <option value="<?= e($l) ?>" <?= $filterPayment === $l ? 'selected' : '' ?>><?= e($l) ?></option>
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
            <a href="shpenzimet.php" class="btn btn-outline btn-sm">Pastro</a>
        </form>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="shpenzimet" data-server-sort="true">
                <thead>
                    <tr>
                        <?= sortThShp('data_e_pageses', 'Data', $sortCol, $sortDir) ?>
                        <?= sortThShp('shuma', 'Shuma', $sortCol, $sortDir, 'num') ?>
                        <?= withFilter(sortThShp('arsyetimi', 'Arsyetimi', $sortCol, $sortDir), 'f_arsyetimi', $arsyet) ?>
                        <?= withFilter(sortThShp('lloji_i_pageses', 'Lloji pagesës', $sortCol, $sortDir), 'f_lloji_pag', $llojetPag) ?>
                        <?= withFilter(sortThShp('lloji_i_transaksionit', 'Lloji transaksionit', $sortCol, $sortDir), 'f_lloji_trans', $llojetTrans) ?>
                        <?= sortThShp('pershkrim_i_detajuar', 'Përshkrim', $sortCol, $sortDir) ?>
                        <?= sortThShp('nafta_ne_litra', 'Nafta (L)', $sortCol, $sortDir, 'num') ?>
                        <?= sortThShp('numri_i_fatures', 'Nr. Faturës', $sortCol, $sortDir) ?>
                        <?= sortThShp('fatura_e_rregullte', 'Fat. rregullt', $sortCol, $sortDir) ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data_e_pageses" data-type="date"><?= $r['data_e_pageses'] ?></td>
                        <td class="amount editable" data-field="shuma" data-type="number"><?= eur($r['shuma']) ?></td>
                        <td class="editable" data-field="arsyetimi"><?= e($r['arsyetimi']) ?></td>
                        <td class="editable" data-field="lloji_i_pageses" data-type="select" data-options="<?= e($pagJSON) ?>"><?= e($r['lloji_i_pageses']) ?></td>
                        <td class="editable" data-field="lloji_i_transaksionit" data-type="select" data-options="<?= e($transJSON) ?>"><?= e($r['lloji_i_transaksionit']) ?></td>
                        <td class="editable" data-field="pershkrim_i_detajuar"><?= e($r['pershkrim_i_detajuar']) ?></td>
                        <td class="num editable" data-field="nafta_ne_litra" data-type="number"><?= $r['nafta_ne_litra'] ?: '' ?></td>
                        <td class="editable" data-field="numri_i_fatures"><?= e($r['numri_i_fatures']) ?></td>
                        <td class="editable" data-field="fatura_e_rregullte" data-type="select" data-options="<?= e(json_encode(['Po','Jo'])) ?>"><?= e($r['fatura_e_rregullte']) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('shpenzimet',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div class="info">Faqja <?= $page ?>/<?= $totalPages ?> (<?= num($totalRows) ?> rreshta)</div>
            <div class="pages">
                <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">&laquo;</a><?php endif; ?>
                <?php for ($i = max(1,$page-3); $i <= min($totalPages,$page+3); $i++): ?>
                <?= $i === $page ? "<span class='current'>{$i}</span>" : "<a href='?" . http_build_query(array_merge($_GET, ['page' => $i])) . "'>{$i}</a>" ?>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">&raquo;</a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
renderLayout('Shpenzimet', 'shpenzimet', $content);
