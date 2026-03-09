<?php
/**
 * DARN Dashboard - Notes
 * Mirrors Excel "NOTES" sheet:
 *   Columns: Data, Teksti (Notes), Barazu nga (Reconciled from)
 *   Journal/log of business notes, reconciliations, and observations
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Babi Cash Total (mirrors Pasqyra / Excel Distribuimi N4 = L4 + M4)
// L4: Cash + Faturë cash from distribuimi (nga 29.08.2022) - Shpenzime cash
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
$babiManual = 281.9; // Manual adjustments from Excel
$babiGasCash = $babiPayments['cash'] + $babiPayments['fature_cash'] - $babiExpenses + $babiManual;
// M4: Product sales cash (nga 07.09.2022)
$babiProductCash = (float)$db->query("
    SELECT COALESCE(SUM(totali), 0) FROM shitje_produkteve
    WHERE LOWER(TRIM(menyra_pageses)) = 'cash' AND data >= '2022-09-07'
")->fetchColumn();
// N4: Total
$babiCashTotal = $babiGasCash + $babiProductCash;

// Sorting — default by insertion order (id DESC)
$sortCol = $_GET['sort'] ?? 'id';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$allowedSort = ['data', 'id'];
if (!in_array($sortCol, $allowedSort)) $sortCol = 'id';

// Date range filter
$filterFrom = $_GET['date_from'] ?? '';
$filterTo = $_GET['date_to'] ?? '';
$whereArr = [];
$params = [];
if ($filterFrom) { $whereArr[] = 'data >= ?'; $params[] = $filterFrom; }
if ($filterTo) { $whereArr[] = 'data <= ?'; $params[] = $filterTo; }

// Text search
$search = $_GET['search'] ?? '';
if ($search) {
    $whereArr[] = '(teksti LIKE ? OR barazu_nga LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$where = $whereArr ? 'WHERE ' . implode(' AND ', $whereArr) : '';

// Distinct barazu_nga values for column filter
$barazuVals = $db->query("SELECT DISTINCT barazu_nga FROM notes WHERE barazu_nga IS NOT NULL AND barazu_nga != '' ORDER BY barazu_nga")->fetchAll(PDO::FETCH_COLUMN);

// Pagination
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, [100, 500, 1000, 5000])) $perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$countStmt = $db->prepare("SELECT COUNT(*) FROM notes {$where}");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

// Count notes with missing dates (for parse button)
$missingDates = (int)$db->query("SELECT COUNT(*) FROM notes WHERE data IS NULL OR data = '' OR data = '0000-00-00'")->fetchColumn();

// Query
$orderSecondary = $sortCol === 'id' ? '' : ', id DESC';
$stmt = $db->prepare("SELECT * FROM notes {$where} ORDER BY {$sortCol} {$sortDir}{$orderSecondary} LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Sort link helper
$nextDir = ($sortDir === 'DESC') ? 'ASC' : 'DESC';
$sortParams = '&per_page=' . $perPage
    . ($search ? '&search=' . urlencode($search) : '')
    . ($filterFrom ? '&date_from=' . urlencode($filterFrom) : '')
    . ($filterTo ? '&date_to=' . urlencode($filterTo) : '');

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total shënime</div>
        <div class="value"><?= num($totalRows) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Babi Cash Total</div>
        <div class="value" id="babiCashValue">&euro; <?= eur($babiCashTotal) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Sipas raportit</div>
        <div class="value"><input type="number" id="babiRaporti" step="0.01" placeholder="Shëno vlerën..." style="width:140px;padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.95rem;text-align:right;"></div>
    </div>
    <div class="summary-card" id="babiDiffCard">
        <div class="label">Diferenca</div>
        <div class="value" id="babiDiff" style="font-size:1.1rem;">-</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-sticky-note"></i> Notes</h3>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Kërko..." style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;width:140px;">
                <input type="date" name="date_from" value="<?= e($filterFrom) ?>" title="Nga data" style="padding:5px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;width:130px;">
                <input type="date" name="date_to" value="<?= e($filterTo) ?>" title="Deri në datë" style="padding:5px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;width:130px;">
                <select name="per_page" style="width:70px;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;">
                    <option value="100" <?= $perPage==100?'selected':'' ?>>100</option>
                    <option value="500" <?= $perPage==500?'selected':'' ?>>500</option>
                    <option value="1000" <?= $perPage==1000?'selected':'' ?>>1000</option>
                    <option value="5000" <?= $perPage==5000?'selected':'' ?>>5000</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Filtro</button>
                <?php if ($search || $filterFrom || $filterTo): ?>
                <a href="?" class="btn btn-outline btn-sm">Pastro</a>
                <?php endif; ?>
            </form>
            <?php if ($missingDates > 0): ?>
            <button class="btn btn-outline btn-sm" id="parseDatesBtn" title="Nxirr datat nga teksti i shënimeve"><i class="fas fa-magic"></i> Nxirr datat (<?= $missingDates ?>)</button>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm" onclick="openModal('addNoteModal')"><i class="fas fa-plus"></i> Shto</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="notes" style="table-layout:fixed;width:100%;">
                <thead>
                    <tr>
                        <th style="width:110px;">
                            <a href="?sort=data&dir=<?= $sortCol === 'data' ? $nextDir : 'DESC' ?><?= $sortParams ?>" style="color:inherit;text-decoration:none;">
                                Data <?php if ($sortCol === 'data'): ?><i class="fas fa-sort-<?= $sortDir === 'ASC' ? 'up' : 'down' ?>"></i><?php endif; ?>
                            </a>
                        </th>
                        <th>Shënim</th>
                        <th style="width:250px;" data-filter="f_barazu" data-filter-values="<?= e(json_encode($barazuVals, JSON_UNESCAPED_UNICODE)) ?>">Barazu nga</th>
                        <th style="width:45px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="4" style="text-align:center;padding:40px;color:var(--text-muted);">
                        <i class="fas fa-info-circle"></i> Nuk ka shënime.
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data" data-type="date"><?= ($r['data'] && $r['data'] !== '0000-00-00') ? e($r['data']) : '-' ?></td>
                        <td class="editable wrap" data-field="teksti" style="white-space:normal;word-break:break-word;"><?= e($r['teksti']) ?></td>
                        <td class="editable wrap" data-field="barazu_nga" style="white-space:normal;word-break:break-word;"><?= e($r['barazu_nga']) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('notes',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<?php
    $pagParams = '&sort=' . $sortCol . '&dir=' . $sortDir . '&per_page=' . $perPage
        . ($search ? '&search=' . urlencode($search) : '')
        . ($filterFrom ? '&date_from=' . urlencode($filterFrom) : '')
        . ($filterTo ? '&date_to=' . urlencode($filterTo) : '');
?>
<div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page - 1 ?><?= $pagParams ?>" class="btn btn-outline btn-sm">&laquo; Para</a>
    <?php endif; ?>
    <span style="font-size:0.82rem;color:var(--text-muted);">Faqja <?= $page ?> / <?= $totalPages ?> (<?= num($totalRows) ?> rreshta)</span>
    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page + 1 ?><?= $pagParams ?>" class="btn btn-outline btn-sm">Tjetër &raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Add Note Modal -->
<div class="modal-overlay" id="addNoteModal">
    <div class="modal">
        <div class="modal-header"><h3>Shto shënim</h3><button class="btn btn-outline btn-sm" onclick="closeModal('addNoteModal')">&times;</button></div>
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="notes">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Data</label>
                        <input type="date" name="data" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;font-size:0.85rem;">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="width:100%;">
                        <label>Shënim *</label>
                        <textarea name="teksti" required rows="5" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;font-size:0.85rem;resize:vertical;"></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="width:100%;">
                        <label>Barazu nga</label>
                        <textarea name="barazu_nga" rows="3" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;font-size:0.85rem;resize:vertical;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addNoteModal')">Anulo</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const babiTotal = <?= $babiCashTotal ?>;
    const input = document.getElementById('babiRaporti');
    const diffEl = document.getElementById('babiDiff');
    const diffCard = document.getElementById('babiDiffCard');
    const STORAGE_KEY = 'notes_babi_raporti';

    function calcDiff() {
        const val = parseFloat(input.value);
        if (isNaN(val)) {
            diffEl.textContent = '-';
            diffCard.style.borderLeft = '';
            return;
        }
        const diff = babiTotal - val;
        const formatted = Math.abs(diff).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (Math.abs(diff) < 0.01) {
            diffEl.innerHTML = '<span style="color:var(--success);"><i class="fas fa-check-circle"></i> &euro; 0.00</span>';
            diffCard.style.borderLeft = '4px solid var(--success)';
        } else {
            const color = diff > 0 ? 'var(--danger)' : 'var(--success)';
            const sign = diff > 0 ? '+' : '-';
            diffEl.innerHTML = '<span style="color:' + color + ';">' + sign + ' &euro; ' + formatted + '</span>';
            diffCard.style.borderLeft = '4px solid ' + color;
        }
    }

    // Restore saved value on page load
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved !== null && saved !== '') {
        input.value = saved;
        calcDiff();
    }

    input.addEventListener('input', function() {
        if (this.value === '') {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, this.value);
        }
        calcDiff();
    });

    // Parse dates button
    const parseBtn = document.getElementById('parseDatesBtn');
    if (parseBtn) {
        parseBtn.addEventListener('click', function() {
            parseBtn.disabled = true;
            parseBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke nxjerrë...';
            fetch('/api/parse-note-dates.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message || ('U përditësuan ' + data.updated + ' shënime'));
                        if (data.updated > 0) {
                            setTimeout(() => location.reload(), 800);
                        } else {
                            parseBtn.innerHTML = '<i class="fas fa-check"></i> Asnjë datë e re';
                            setTimeout(() => { parseBtn.disabled = false; parseBtn.innerHTML = '<i class="fas fa-magic"></i> Nxirr datat'; }, 2000);
                        }
                    } else {
                        showToast(data.error || 'Gabim', 'error');
                        parseBtn.disabled = false;
                        parseBtn.innerHTML = '<i class="fas fa-magic"></i> Nxirr datat';
                    }
                })
                .catch(() => {
                    showToast('Gabim gjatë përpunimit', 'error');
                    parseBtn.disabled = false;
                    parseBtn.innerHTML = '<i class="fas fa-magic"></i> Nxirr datat';
                });
        });
    }
})();
</script>

<?php
$content = ob_get_clean();
renderLayout('Notes', 'notes', $content);
