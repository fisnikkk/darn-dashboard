<?php
/**
 * DARN Dashboard - Changelog / Audit Log
 * Human-readable descriptions + Undo buttons
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Auto-create changelog table if it doesn't exist
try {
    $db->query("SELECT 1 FROM changelog LIMIT 1");
} catch (PDOException $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS changelog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action_type VARCHAR(20) NOT NULL,
        table_name VARCHAR(64) NOT NULL,
        row_id INT,
        field_name VARCHAR(64),
        old_value TEXT,
        new_value TEXT,
        reverted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_table (table_name),
        INDEX idx_created (created_at)
    )");
}

// Human-readable field names
$fieldLabels = [
    'klienti' => 'Klienti', 'data' => 'Data', 'sasia' => 'Sasia', 'litra' => 'Litra',
    'cmimi' => 'Çmimi', 'pagesa' => 'Pagesa', 'menyra_e_pageses' => 'Mënyra e pagesës',
    'fatura_e_derguar' => 'Fatura e dërguar', 'koment' => 'Koment', 'koment1' => 'Koment',
    'litrat_total' => 'Litrat total', 'litrat_e_konvertuara' => 'Litrat e konvertuara',
    'boca_te_kthyera' => 'Boca të kthyera', 'boca_tek_biznesi' => 'Boca tek biznesi',
    'te_dhena' => 'Të dhëna', 'te_marra' => 'Të marra', 'lloji_i_nxemjes' => 'Lloji i nxemjes',
    'shuma' => 'Shuma', 'pershkrimi' => 'Përshkrimi', 'kategoria' => 'Kategoria',
    'fatura_e_rregullte' => 'Fatura', 'kg' => 'Kg', 'sasia_ne_litra' => 'Litra',
    'furnitori' => 'Furnitori', 'lloji_i_transaksionit' => 'Lloji i transaksionit',
    'debia' => 'Debia', 'kredi' => 'Kredi', 'bilanci' => 'Bilanci',
    'cilindra_sasia' => 'Sasia', 'totali' => 'Totali', 'produkti' => 'Produkti',
    'e_kontrolluar' => 'E kontrolluar', 'data_e_fletepageses' => 'Data e fletëpagesës',
    'komentet' => 'Koment', 'cash' => 'Cash', 'bank' => 'Bank',
    'fature_bank' => 'Faturë bank', 'fature_cash' => 'Faturë cash',
    'no_payment' => 'Pa pagesë', 'dhurate' => 'Dhuratë', 'total' => 'Total',
    'borxh_deri_daten' => 'Borxhi deri datën',
];

// Human-readable table names
$tableLabels = [
    'distribuimi' => 'Distribuimi', 'shpenzimet' => 'Shpenzimet', 'plini_depo' => 'Plini Depo',
    'shitje_produkteve' => 'Shitje Produkteve', 'kontrata' => 'Kontrata',
    'gjendja_bankare' => 'Gjendja Bankare', 'nxemese' => 'Nxemëse',
    'klientet' => 'Klientët', 'stoku_zyrtar' => 'Stoku Zyrtar', 'depo' => 'Depo',
    'borxhet_notes' => 'Borxhet (Shënime)',
    'snapshot' => 'Snapshot',
];

// Key fields to show for insert/delete summaries
$keyFields = [
    'distribuimi' => ['klienti', 'data', 'sasia', 'pagesa', 'menyra_e_pageses'],
    'shpenzimet' => ['pershkrimi', 'shuma', 'data', 'kategoria'],
    'plini_depo' => ['furnitori', 'kg', 'data'],
    'shitje_produkteve' => ['klienti', 'produkti', 'cilindra_sasia', 'data'],
    'gjendja_bankare' => ['pershkrimi', 'debia', 'kredi', 'data'],
    'nxemese' => ['klienti', 'data', 'te_dhena', 'te_marra', 'lloji_i_nxemjes'],
    'kontrata' => ['klienti', 'data'],
    'klientet' => ['klienti'],
    'stoku_zyrtar' => ['produkti', 'sasia'],
    'depo' => ['produkti', 'sasia'],
];

function describeChange($r, $fieldLabels, $tableLabels, $keyFields) {
    $table = $tableLabels[$r['table_name']] ?? $r['table_name'];
    $type = $r['action_type'];
    $rowId = $r['row_id'];

    if ($type === 'update') {
        $field = $fieldLabels[$r['field_name']] ?? $r['field_name'];
        $old = $r['old_value'] ?? '-';
        $new = $r['new_value'] ?? '-';
        if (strlen($old) > 60) $old = mb_substr($old, 0, 57) . '...';
        if (strlen($new) > 60) $new = mb_substr($new, 0, 57) . '...';
        return "<strong>{$table}</strong> #{$rowId} &mdash; Ndryshoi <em>{$field}</em> nga <code>" . e($old) . "</code> në <code>" . e($new) . "</code>";
    }

    if ($type === 'insert') {
        $data = json_decode($r['new_value'] ?? '{}', true);
        // Determine source for display
        $fieldName = $r['field_name'] ?? null;
        $sourceTag = '';
        if ($fieldName === 'bulk_paste') $sourceTag = ' <span style="font-size:0.72rem;background:#e0e7ff;color:#4338ca;padding:1px 6px;border-radius:8px;">Ngjit nga Excel</span>';
        elseif ($fieldName === 'import_script') $sourceTag = ' <span style="font-size:0.72rem;background:#fce7f3;color:#be185d;padding:1px 6px;border-radius:8px;">Import</span>';
        elseif ($fieldName === 'import_excel') $sourceTag = ' <span style="font-size:0.72rem;background:#fce7f3;color:#be185d;padding:1px 6px;border-radius:8px;">Import Excel</span>';

        if ($data && is_array($data)) {
            $keys = $keyFields[$r['table_name']] ?? array_slice(array_keys($data), 0, 4);
            $parts = [];
            foreach ($keys as $k) {
                if (isset($data[$k]) && $data[$k] !== '' && $data[$k] !== null) {
                    $label = $fieldLabels[$k] ?? $k;
                    $val = $data[$k];
                    if (strlen($val) > 40) $val = mb_substr($val, 0, 37) . '...';
                    $parts[] = "<em>{$label}</em>: " . e($val);
                }
            }
            $summary = $parts ? ' &mdash; ' . implode(', ', $parts) : '';
            return "<strong>{$table}</strong> #{$rowId} &mdash; Rresht i ri shtuar{$sourceTag}{$summary}";
        }
        return "<strong>{$table}</strong> #{$rowId} &mdash; Rresht i ri shtuar{$sourceTag}";
    }

    if ($type === 'delete') {
        $data = json_decode($r['old_value'] ?? '{}', true);
        if ($data && is_array($data)) {
            $keys = $keyFields[$r['table_name']] ?? array_slice(array_keys($data), 0, 4);
            $parts = [];
            foreach ($keys as $k) {
                if (isset($data[$k]) && $data[$k] !== '' && $data[$k] !== null) {
                    $label = $fieldLabels[$k] ?? $k;
                    $val = $data[$k];
                    if (strlen($val) > 40) $val = mb_substr($val, 0, 37) . '...';
                    $parts[] = "<em>{$label}</em>: " . e($val);
                }
            }
            $summary = $parts ? ' &mdash; ' . implode(', ', $parts) : '';
            return "<strong>{$table}</strong> #{$rowId} &mdash; Rreshti u fshi{$summary}";
        }
        return "<strong>{$table}</strong> #{$rowId} &mdash; Rreshti u fshi";
    }

    if ($type === 'revert') {
        $field = $fieldLabels[$r['field_name']] ?? $r['field_name'];
        $old = $r['old_value'] ?? '-';
        $new = $r['new_value'] ?? '-';
        if (strlen($old) > 60) $old = mb_substr($old, 0, 57) . '...';
        if (strlen($new) > 60) $new = mb_substr($new, 0, 57) . '...';
        return "<strong>{$table}</strong> #{$rowId} &mdash; U kthye <em>{$field}</em> nga <code>" . e($old) . "</code> në <code>" . e($new) . "</code>";
    }

    if ($type === 'restore') {
        $data = json_decode($r['new_value'] ?? '{}', true);
        $snapName = $data['snapshot'] ?? 'i panjohur';
        $tableCount = isset($data['tables']) ? count($data['tables']) : 0;
        return "<strong>Snapshot</strong> &mdash; U rikthye snapshot <code>" . e($snapName) . "</code> ({$tableCount} tabela)";
    }

    return "<strong>{$table}</strong> #{$rowId} &mdash; {$type}";
}

// Pagination
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Filter by table
$filterTable = $_GET['table'] ?? '';
$filterAction = $_GET['action'] ?? '';

// Build query
$where = [];
$params = [];
if ($filterTable) { $where[] = 'table_name = ?'; $params[] = $filterTable; }
if ($filterAction) { $where[] = 'action_type = ?'; $params[] = $filterAction; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM changelog {$whereSQL}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("SELECT * FROM changelog {$whereSQL} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$tables = $db->query("SELECT DISTINCT table_name FROM changelog ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

<style>
.log-filters {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.log-filters select {
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.85rem;
    background: #fff;
}
.log-entry {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}
.log-entry:last-child { border-bottom: none; }
.log-entry:hover { background: #f8fafc; }
.log-entry.reverted { opacity: 0.45; }
.log-time {
    white-space: nowrap;
    font-size: 0.78rem;
    color: var(--text-muted);
    min-width: 130px;
    padding-top: 2px;
}
.log-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    min-width: 52px;
    text-align: center;
}
.log-badge-insert { background: rgba(16,185,129,0.15); color: var(--success); }
.log-badge-update { background: rgba(245,158,11,0.15); color: var(--warning); }
.log-badge-delete { background: rgba(239,68,68,0.15); color: var(--danger); }
.log-badge-revert { background: rgba(99,102,241,0.15); color: #6366f1; }
.log-badge-restore { background: rgba(139,92,246,0.15); color: #8b5cf6; }
.log-desc {
    flex: 1;
    font-size: 0.85rem;
    line-height: 1.5;
}
.log-desc code {
    background: #f1f5f9;
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 0.8rem;
}
.log-undo {
    padding: 3px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.75rem;
    background: #fff;
    cursor: pointer;
    color: var(--text-muted);
    white-space: nowrap;
}
.log-undo:hover { border-color: var(--primary); color: var(--primary); }
.log-reverted-tag {
    font-size: 0.7rem;
    color: var(--text-muted);
    font-style: italic;
}
.pagination {
    display: flex;
    gap: 6px;
    align-items: center;
    justify-content: center;
    margin-top: 16px;
    flex-wrap: wrap;
}
.pagination a, .pagination span {
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.82rem;
    text-decoration: none;
    color: var(--text);
}
.pagination a:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
.pagination .current { background: var(--primary); color: #fff; border-color: var(--primary); }
.log-summary {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 4px;
}
</style>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Log i ndryshimeve</h3>
    </div>
    <div class="card-body">
        <div class="log-filters">
            <label style="font-weight:600; font-size:0.85rem;">Tabela:</label>
            <select onchange="window.location.href='?table='+this.value+'&action=<?= e($filterAction) ?>'">
                <option value="">Të gjitha</option>
                <?php foreach ($tables as $t): ?>
                <option value="<?= e($t) ?>" <?= $filterTable === $t ? 'selected' : '' ?>><?= e($tableLabels[$t] ?? $t) ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-weight:600; font-size:0.85rem;">Veprimi:</label>
            <select onchange="window.location.href='?table=<?= e($filterTable) ?>&action='+this.value">
                <option value="">Të gjitha</option>
                <option value="insert" <?= $filterAction === 'insert' ? 'selected' : '' ?>>Insert</option>
                <option value="update" <?= $filterAction === 'update' ? 'selected' : '' ?>>Update</option>
                <option value="delete" <?= $filterAction === 'delete' ? 'selected' : '' ?>>Delete</option>
                <option value="revert" <?= $filterAction === 'revert' ? 'selected' : '' ?>>Revert</option>
                <option value="restore" <?= $filterAction === 'restore' ? 'selected' : '' ?>>Restore</option>
            </select>
        </div>
        <div class="log-summary">
            <?= number_format($totalRows) ?> regjistrime
            <?php if ($totalPages > 1): ?> &mdash; Faqja <?= $page ?> / <?= $totalPages ?><?php endif; ?>
        </div>

        <?php if (empty($rows)): ?>
            <div style="text-align:center; color:var(--text-muted); padding:32px;">Nuk ka regjistrime.</div>
        <?php else: ?>
            <?php foreach ($rows as $r):
                $isReverted = !empty($r['reverted']);
                $desc = describeChange($r, $fieldLabels, $tableLabels, $keyFields);
                $canUndoUpdate = ($r['action_type'] === 'update' && !$isReverted);
                $canUndoDelete = ($r['action_type'] === 'delete' && !$isReverted);
            ?>
            <div class="log-entry <?= $isReverted ? 'reverted' : '' ?>">
                <div class="log-time"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></div>
                <span class="log-badge log-badge-<?= $r['action_type'] ?>"><?= $r['action_type'] ?></span>
                <div class="log-desc">
                    <?= $desc ?>
                    <?php if ($isReverted): ?>
                        <span class="log-reverted-tag">(kthyer)</span>
                    <?php endif; ?>
                </div>
                <?php if ($canUndoUpdate): ?>
                    <button class="log-undo" onclick="revertChange('<?= e($r['table_name']) ?>', <?= (int)$r['row_id'] ?>, this)" title="Kthe ndryshimin">
                        <i class="fas fa-undo"></i> Kthe
                    </button>
                <?php elseif ($canUndoDelete): ?>
                    <button class="log-undo" onclick="restoreDelete('<?= e($r['table_name']) ?>', <?= (int)$r['row_id'] ?>, <?= (int)$r['id'] ?>, this)" title="Rikthe rreshtin e fshirë" style="border-color:#ef4444;color:#ef4444;">
                        <i class="fas fa-trash-restore"></i> Rikthe
                    </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $baseUrl = '?table=' . urlencode($filterTable) . '&action=' . urlencode($filterAction) . '&page=';
            if ($page > 1): ?>
                <a href="<?= $baseUrl . ($page - 1) ?>">&laquo; Para</a>
            <?php endif;
            $start = max(1, $page - 3);
            $end = min($totalPages, $page + 3);
            if ($start > 1): ?>
                <a href="<?= $baseUrl . 1 ?>">1</a>
                <?php if ($start > 2): ?><span>...</span><?php endif;
            endif;
            for ($i = $start; $i <= $end; $i++):
                if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= $baseUrl . $i ?>"><?= $i ?></a>
                <?php endif;
            endfor;
            if ($end < $totalPages):
                if ($end < $totalPages - 1): ?><span>...</span><?php endif; ?>
                <a href="<?= $baseUrl . $totalPages ?>"><?= $totalPages ?></a>
            <?php endif;
            if ($page < $totalPages): ?>
                <a href="<?= $baseUrl . ($page + 1) ?>">Pas &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function revertChange(table, rowId, btn) {
    if (!confirm('Kthe ndryshimin e fundit për këtë rresht?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    fetch('/api/revert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table: table, id: rowId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.closest('.log-entry').classList.add('reverted');
            btn.outerHTML = '<span class="log-reverted-tag">(kthyer)</span>';
            showToast(data.message || 'U kthye me sukses', 'success');
        } else {
            showToast(data.error || 'Gabim', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo"></i> Kthe';
        }
    })
    .catch(() => {
        showToast('Gabim rrjeti', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-undo"></i> Kthe';
    });
}

function restoreDelete(table, rowId, changelogId, btn) {
    if (!confirm('Rikthe rreshtin e fshirë? Do të ri-insertohet në tabelën ' + table + '.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    fetch('/api/revert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table: table, id: rowId, action: 'restore_delete', changelog_id: changelogId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.closest('.log-entry').classList.add('reverted');
            btn.outerHTML = '<span class="log-reverted-tag">(u rikthye)</span>';
            showToast(data.message || 'Rreshti u rikthye me sukses', 'success');
        } else {
            showToast(data.error || 'Gabim', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-restore"></i> Rikthe';
        }
    })
    .catch(() => {
        showToast('Gabim rrjeti', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-restore"></i> Rikthe';
    });
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Log i ndryshimeve', 'log', $content);
