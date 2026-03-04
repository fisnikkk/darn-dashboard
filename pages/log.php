<?php
/**
 * DARN Dashboard - Changelog / Audit Log
 * Visual changelog with diff view, data pills, and undo/restore buttons
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
    'cmimi' => 'Cmimi', 'pagesa' => 'Pagesa', 'menyra_e_pageses' => 'Menyra e pageses',
    'fatura_e_derguar' => 'Fatura e derguar', 'koment' => 'Koment', 'koment1' => 'Koment',
    'litrat_total' => 'Litrat total', 'litrat_e_konvertuara' => 'Litrat e konvertuara',
    'boca_te_kthyera' => 'Boca te kthyera', 'boca_tek_biznesi' => 'Boca tek biznesi',
    'te_dhena' => 'Te dhena', 'te_marra' => 'Te marra', 'lloji_i_nxemjes' => 'Lloji i nxemjes',
    'shuma' => 'Shuma', 'pershkrimi' => 'Pershkrimi', 'kategoria' => 'Kategoria',
    'fatura_e_rregullte' => 'Fatura', 'kg' => 'Kg', 'sasia_ne_litra' => 'Litra',
    'furnitori' => 'Furnitori', 'lloji_i_transaksionit' => 'Lloji i transaksionit',
    'debia' => 'Debia', 'kredi' => 'Kredi', 'bilanci' => 'Bilanci',
    'cilindra_sasia' => 'Sasia', 'totali' => 'Totali', 'produkti' => 'Produkti',
    'e_kontrolluar' => 'E kontrolluar', 'data_e_fletepageses' => 'Data e fletepageses',
    'komentet' => 'Koment', 'cash' => 'Cash', 'bank' => 'Bank',
    'fature_bank' => 'Fature bank', 'fature_cash' => 'Fature cash',
    'no_payment' => 'Pa pagese', 'dhurate' => 'Dhurate', 'total' => 'Total',
    'borxh_deri_daten' => 'Borxhi deri daten',
    'nr_i_fatures' => 'Nr. Fatures', 'faturat_e_pranuara' => 'Faturat e pranuara',
    'dalje_pagesat_sipas_bankes' => 'Dalje/Banke', 'cash_banke' => 'Cash/Banke',
    'biznesi' => 'Biznesi', 'name_from_database' => 'Emri DB',
    'numri_ne_stok_sipas_kontrates' => 'Stoku kontrate', 'row_nr' => 'Nr.',
];

// Human-readable table names
$tableLabels = [
    'distribuimi' => 'Distribuimi', 'shpenzimet' => 'Shpenzimet', 'plini_depo' => 'Plini Depo',
    'shitje_produkteve' => 'Shitje Produkteve', 'kontrata' => 'Kontrata',
    'gjendja_bankare' => 'Gjendja Bankare', 'nxemese' => 'Nxemese',
    'klientet' => 'Klientet', 'stoku_zyrtar' => 'Stoku Zyrtar', 'depo' => 'Depo',
    'borxhet_notes' => 'Borxhet (Shenime)',
    'snapshot' => 'Snapshot',
];

// Table icons
$tableIcons = [
    'distribuimi' => 'fa-truck', 'shpenzimet' => 'fa-receipt', 'plini_depo' => 'fa-gas-pump',
    'kontrata' => 'fa-file-contract', 'shitje_produkteve' => 'fa-shopping-cart',
    'gjendja_bankare' => 'fa-university', 'nxemese' => 'fa-fire',
    'klientet' => 'fa-users', 'stoku_zyrtar' => 'fa-boxes', 'depo' => 'fa-warehouse',
    'borxhet_notes' => 'fa-sticky-note', 'snapshot' => 'fa-camera',
];

// Albanian action labels
$actionLabels = [
    'insert' => 'Shtim', 'update' => 'Ndryshim', 'delete' => 'Fshirje',
    'revert' => 'Kthim', 'restore' => 'Rikthim',
];

// Key fields to show for insert/delete summaries
$keyFields = [
    'distribuimi' => ['klienti', 'data', 'sasia', 'pagesa', 'menyra_e_pageses'],
    'shpenzimet' => ['pershkrimi', 'shuma', 'data', 'kategoria'],
    'plini_depo' => ['furnitori', 'kg', 'data', 'menyra_e_pageses'],
    'shitje_produkteve' => ['klienti', 'produkti', 'cilindra_sasia', 'data'],
    'gjendja_bankare' => ['pershkrimi', 'debia', 'kredi', 'data'],
    'nxemese' => ['klienti', 'data', 'te_dhena', 'te_marra', 'lloji_i_nxemjes'],
    'kontrata' => ['biznesi', 'data'],
    'klientet' => ['klienti'],
    'stoku_zyrtar' => ['produkti', 'sasia'],
    'depo' => ['produkti', 'sasia'],
];

// Relative time helper
function relativeTime($datetime) {
    $now = time();
    $ts = strtotime($datetime);
    $diff = $now - $ts;
    if ($diff < 60) return 'tani';
    if ($diff < 3600) return (int)($diff / 60) . ' min. me pare';
    if ($diff < 86400) return (int)($diff / 3600) . ' ore me pare';
    if ($diff < 172800) return 'dje';
    if ($diff < 604800) return (int)($diff / 86400) . ' dite me pare';
    if ($diff < 2592000) return (int)($diff / 604800) . ' jave me pare';
    return date('d/m/Y', $ts);
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

// Action type counts for stats bar
$statsStmt = $db->prepare("SELECT action_type, COUNT(*) as cnt FROM changelog {$whereSQL} GROUP BY action_type");
$statsStmt->execute($params);
$actionCounts = [];
while ($s = $statsStmt->fetch()) { $actionCounts[$s['action_type']] = (int)$s['cnt']; }

ob_start();
?>

<style>
/* ─── Filters ─── */
.log-filters {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
}
.log-filters select {
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.82rem;
    background: #fff;
}
.log-filters label {
    font-weight: 600;
    font-size: 0.8rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* ─── Stats bar ─── */
.log-stats {
    display: flex;
    gap: 8px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
    align-items: center;
    background: #f8fafc;
}
.log-stat {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: transform 0.1s;
}
.log-stat:hover { transform: scale(1.05); }
.log-stat-insert { background: rgba(16,185,129,0.12); color: #059669; }
.log-stat-update { background: rgba(245,158,11,0.12); color: #d97706; }
.log-stat-delete { background: rgba(239,68,68,0.12); color: #dc2626; }
.log-stat-revert { background: rgba(99,102,241,0.12); color: #4f46e5; }
.log-stat-restore { background: rgba(139,92,246,0.12); color: #7c3aed; }
.log-stat .num { font-weight: 700; font-size: 0.82rem; }
.log-stats-total {
    margin-left: auto;
    font-size: 0.8rem;
    color: var(--text-muted);
}

/* ─── Log entries ─── */
.log-list { padding: 8px; }
.log-entry {
    border: 1px solid var(--border);
    border-left: 4px solid #cbd5e1;
    border-radius: 8px;
    margin-bottom: 8px;
    background: #fff;
    transition: box-shadow 0.15s;
    overflow: hidden;
}
.log-entry:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.log-entry.reverted { opacity: 0.4; }
.log-entry.type-insert { border-left-color: #10b981; }
.log-entry.type-update { border-left-color: #f59e0b; }
.log-entry.type-delete { border-left-color: #ef4444; }
.log-entry.type-revert { border-left-color: #6366f1; }
.log-entry.type-restore { border-left-color: #8b5cf6; }

/* Header row */
.log-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    flex-wrap: wrap;
}
.log-table-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text);
    background: #f1f5f9;
    padding: 2px 10px;
    border-radius: 6px;
}
.log-table-tag i { color: var(--text-muted); font-size: 0.72rem; }
.log-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    min-width: 52px;
    text-align: center;
}
.log-badge-insert { background: rgba(16,185,129,0.15); color: #059669; }
.log-badge-update { background: rgba(245,158,11,0.15); color: #d97706; }
.log-badge-delete { background: rgba(239,68,68,0.15); color: #dc2626; }
.log-badge-revert { background: rgba(99,102,241,0.15); color: #4f46e5; }
.log-badge-restore { background: rgba(139,92,246,0.15); color: #7c3aed; }
.log-row-id {
    font-size: 0.75rem;
    color: var(--text-muted);
    font-family: monospace;
}
.log-source-tag {
    font-size: 0.68rem;
    padding: 1px 7px;
    border-radius: 8px;
    font-weight: 600;
}
.log-source-paste { background: #e0e7ff; color: #4338ca; }
.log-source-import { background: #fce7f3; color: #be185d; }
.log-time {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--text-muted);
    white-space: nowrap;
    cursor: default;
}
.log-reverted-tag {
    font-size: 0.68rem;
    color: var(--text-muted);
    font-style: italic;
    background: #f1f5f9;
    padding: 1px 8px;
    border-radius: 8px;
}

/* Body: diff view for updates */
.log-body {
    padding: 0 14px 10px 14px;
}
.log-diff {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.log-diff-field {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    min-width: 100%;
    margin-bottom: 2px;
}
.log-old {
    display: inline-block;
    padding: 3px 10px;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 4px;
    font-size: 0.8rem;
    font-family: monospace;
    text-decoration: line-through;
    text-decoration-color: rgba(153, 27, 27, 0.4);
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.log-arrow {
    color: var(--text-muted);
    font-size: 0.85rem;
}
.log-new {
    display: inline-block;
    padding: 3px 10px;
    background: #f0fdf4;
    color: #166534;
    border-radius: 4px;
    font-size: 0.8rem;
    font-family: monospace;
    font-weight: 600;
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Body: data pills for inserts/deletes */
.log-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.log-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 0.78rem;
    line-height: 1.4;
}
.log-pill strong {
    font-weight: 600;
    margin-right: 2px;
}
.log-pills-insert .log-pill { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.log-pills-delete .log-pill { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.log-pills-restore .log-pill { background: #f5f3ff; color: #5b21b6; border: 1px solid #ddd6fe; }

/* Expandable details */
.log-details-toggle {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.72rem;
    color: var(--primary);
    cursor: pointer;
    border: none;
    background: none;
    padding: 2px 4px;
    margin-top: 4px;
    border-radius: 4px;
}
.log-details-toggle:hover { background: #eff6ff; }
.log-details-toggle i { font-size: 0.65rem; transition: transform 0.15s; }
.log-details-toggle.open i { transform: rotate(90deg); }
.log-details {
    display: none;
    margin-top: 8px;
    padding: 10px 12px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px solid var(--border);
}
.log-details.open { display: block; }
.log-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 6px 16px;
}
.log-detail-item {
    font-size: 0.78rem;
    line-height: 1.4;
    padding: 2px 0;
    overflow: hidden;
    text-overflow: ellipsis;
}
.log-detail-item .dlabel {
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.2px;
}
.log-detail-item .dvalue {
    color: var(--text);
    word-break: break-word;
}

/* Footer with undo button */
.log-footer {
    display: flex;
    justify-content: flex-end;
    padding: 0 14px 10px 14px;
}
.log-undo {
    padding: 4px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.75rem;
    background: #fff;
    cursor: pointer;
    color: var(--text-muted);
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s;
}
.log-undo:hover { border-color: var(--primary); color: var(--primary); background: #eff6ff; }
.log-undo-delete { border-color: rgba(239,68,68,0.4); color: #dc2626; }
.log-undo-delete:hover { border-color: #ef4444; background: #fef2f2; color: #dc2626; }

/* Pagination */
.log-pagination {
    display: flex;
    gap: 6px;
    align-items: center;
    justify-content: center;
    padding: 16px;
    flex-wrap: wrap;
}
.log-pagination a, .log-pagination span {
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.82rem;
    text-decoration: none;
    color: var(--text);
}
.log-pagination a:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
.log-pagination .current { background: var(--primary); color: #fff; border-color: var(--primary); }

/* Snapshot restore entry */
.log-snapshot {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
}
.log-snapshot code {
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.78rem;
}
</style>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Log i ndryshimeve</h3>
    </div>

    <!-- Filters -->
    <div class="log-filters">
        <label>Tabela:</label>
        <select onchange="window.location.href='?table='+this.value+'&action=<?= e($filterAction) ?>'">
            <option value="">Te gjitha</option>
            <?php foreach ($tables as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterTable === $t ? 'selected' : '' ?>><?= e($tableLabels[$t] ?? $t) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Veprimi:</label>
        <select onchange="window.location.href='?table=<?= e($filterTable) ?>&action='+this.value">
            <option value="">Te gjitha</option>
            <option value="insert" <?= $filterAction === 'insert' ? 'selected' : '' ?>>Shtim</option>
            <option value="update" <?= $filterAction === 'update' ? 'selected' : '' ?>>Ndryshim</option>
            <option value="delete" <?= $filterAction === 'delete' ? 'selected' : '' ?>>Fshirje</option>
            <option value="revert" <?= $filterAction === 'revert' ? 'selected' : '' ?>>Kthim</option>
            <option value="restore" <?= $filterAction === 'restore' ? 'selected' : '' ?>>Rikthim</option>
        </select>
    </div>

    <!-- Stats bar -->
    <div class="log-stats">
        <?php
        $statOrder = ['insert' => 'shtuar', 'update' => 'ndryshuar', 'delete' => 'fshire', 'revert' => 'kthyer', 'restore' => 'rikthyer'];
        foreach ($statOrder as $sType => $sLabel):
            $cnt = $actionCounts[$sType] ?? 0;
            if ($cnt === 0) continue;
        ?>
        <a href="?table=<?= e($filterTable) ?>&action=<?= $sType ?>" class="log-stat log-stat-<?= $sType ?>">
            <span class="num"><?= number_format($cnt) ?></span> <?= $sLabel ?>
        </a>
        <?php endforeach; ?>
        <span class="log-stats-total">
            <?= number_format($totalRows) ?> total
            <?php if ($totalPages > 1): ?>&mdash; Faqja <?= $page ?>/<?= $totalPages ?><?php endif; ?>
        </span>
    </div>

    <!-- Log entries -->
    <div class="log-list">
        <?php if (empty($rows)): ?>
            <div style="text-align:center; color:var(--text-muted); padding:40px 16px;">
                <i class="fas fa-inbox" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                Nuk ka regjistrime.
            </div>
        <?php else: ?>
            <?php foreach ($rows as $idx => $r):
                $isReverted = !empty($r['reverted']);
                $type = $r['action_type'];
                $tableName = $r['table_name'];
                $tableLabel = $tableLabels[$tableName] ?? $tableName;
                $tableIcon = $tableIcons[$tableName] ?? 'fa-database';
                $actionLabel = $actionLabels[$type] ?? $type;
                $canUndoUpdate = ($type === 'update' && !$isReverted);
                $canUndoDelete = ($type === 'delete' && !$isReverted);
                $exactTime = date('d/m/Y H:i:s', strtotime($r['created_at']));
                $relTime = relativeTime($r['created_at']);

                // Source tag for inserts
                $sourceTag = '';
                $fieldName = $r['field_name'] ?? null;
                if ($type === 'insert') {
                    if ($fieldName === 'bulk_paste') $sourceTag = '<span class="log-source-tag log-source-paste">Ngjit nga Excel</span>';
                    elseif ($fieldName === 'import_script') $sourceTag = '<span class="log-source-tag log-source-import">Import</span>';
                    elseif ($fieldName === 'import_excel') $sourceTag = '<span class="log-source-tag log-source-import">Import Excel</span>';
                }
            ?>
            <div class="log-entry type-<?= $type ?> <?= $isReverted ? 'reverted' : '' ?>">
                <!-- Header -->
                <div class="log-header">
                    <span class="log-table-tag"><i class="fas <?= $tableIcon ?>"></i> <?= e($tableLabel) ?></span>
                    <span class="log-badge log-badge-<?= $type ?>"><?= e($actionLabel) ?></span>
                    <?php if ($r['row_id']): ?>
                    <span class="log-row-id">#<?= (int)$r['row_id'] ?></span>
                    <?php endif; ?>
                    <?= $sourceTag ?>
                    <?php if ($isReverted): ?>
                        <span class="log-reverted-tag"><i class="fas fa-check"></i> kthyer</span>
                    <?php endif; ?>
                    <span class="log-time" title="<?= $exactTime ?>"><?= e($relTime) ?></span>
                </div>

                <!-- Body: different layout per action type -->
                <div class="log-body">
                <?php if ($type === 'update' || $type === 'revert'): ?>
                    <?php
                    $field = $fieldLabels[$r['field_name']] ?? $r['field_name'];
                    $old = $r['old_value'] ?? '';
                    $new = $r['new_value'] ?? '';
                    $oldDisplay = mb_strlen($old) > 80 ? mb_substr($old, 0, 77) . '...' : $old;
                    $newDisplay = mb_strlen($new) > 80 ? mb_substr($new, 0, 77) . '...' : $new;
                    if ($old === '' || $old === null) $oldDisplay = '(bosh)';
                    if ($new === '' || $new === null) $newDisplay = '(bosh)';
                    ?>
                    <div class="log-diff">
                        <span class="log-diff-field"><?= e($field) ?></span>
                        <span class="log-old" title="<?= e($old) ?>"><?= e($oldDisplay) ?></span>
                        <i class="fas fa-long-arrow-alt-right log-arrow"></i>
                        <span class="log-new" title="<?= e($new) ?>"><?= e($newDisplay) ?></span>
                    </div>

                <?php elseif ($type === 'insert'): ?>
                    <?php
                    $data = json_decode($r['new_value'] ?? '{}', true);
                    if ($data && is_array($data)):
                        $keys = $keyFields[$tableName] ?? array_slice(array_keys($data), 0, 5);
                    ?>
                    <div class="log-pills log-pills-insert">
                        <?php foreach ($keys as $k):
                            if (!isset($data[$k]) || $data[$k] === '' || $data[$k] === null) continue;
                            $label = $fieldLabels[$k] ?? $k;
                            $val = $data[$k];
                            if (mb_strlen($val) > 50) $val = mb_substr($val, 0, 47) . '...';
                        ?>
                        <span class="log-pill"><strong><?= e($label) ?>:</strong> <?= e($val) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($data) > count($keys)): ?>
                    <button class="log-details-toggle" onclick="toggleDetails(<?= (int)$r['id'] ?>, this)">
                        <i class="fas fa-chevron-right"></i> Shiko te gjitha (<?= count($data) ?> fusha)
                    </button>
                    <div class="log-details" id="details-<?= (int)$r['id'] ?>">
                        <div class="log-details-grid">
                            <?php foreach ($data as $dk => $dv):
                                if ($dv === '' || $dv === null || $dv === '0' || $dv === 0) continue;
                                $dlabel = $fieldLabels[$dk] ?? $dk;
                                if (mb_strlen((string)$dv) > 80) $dv = mb_substr((string)$dv, 0, 77) . '...';
                            ?>
                            <div class="log-detail-item">
                                <span class="dlabel"><?= e($dlabel) ?></span><br>
                                <span class="dvalue"><?= e($dv) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                <?php elseif ($type === 'delete'): ?>
                    <?php
                    $data = json_decode($r['old_value'] ?? '{}', true);
                    if ($data && is_array($data)):
                        $keys = $keyFields[$tableName] ?? array_slice(array_keys($data), 0, 5);
                    ?>
                    <div class="log-pills log-pills-delete">
                        <?php foreach ($keys as $k):
                            if (!isset($data[$k]) || $data[$k] === '' || $data[$k] === null) continue;
                            $label = $fieldLabels[$k] ?? $k;
                            $val = $data[$k];
                            if (mb_strlen($val) > 50) $val = mb_substr($val, 0, 47) . '...';
                        ?>
                        <span class="log-pill"><strong><?= e($label) ?>:</strong> <?= e($val) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($data) > count($keys)): ?>
                    <button class="log-details-toggle" onclick="toggleDetails(<?= (int)$r['id'] ?>, this)">
                        <i class="fas fa-chevron-right"></i> Shiko te gjitha (<?= count($data) ?> fusha)
                    </button>
                    <div class="log-details" id="details-<?= (int)$r['id'] ?>">
                        <div class="log-details-grid">
                            <?php foreach ($data as $dk => $dv):
                                if ($dv === '' || $dv === null) continue;
                                $dlabel = $fieldLabels[$dk] ?? $dk;
                                if (mb_strlen((string)$dv) > 80) $dv = mb_substr((string)$dv, 0, 77) . '...';
                            ?>
                            <div class="log-detail-item">
                                <span class="dlabel"><?= e($dlabel) ?></span><br>
                                <span class="dvalue"><?= e($dv) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                <?php elseif ($type === 'restore'): ?>
                    <?php
                    $data = json_decode($r['new_value'] ?? '{}', true);
                    $snapName = $data['snapshot'] ?? 'i panjohur';
                    $tableCount = isset($data['tables']) ? count($data['tables']) : 0;
                    ?>
                    <div class="log-snapshot">
                        <i class="fas fa-camera" style="color:#8b5cf6;"></i>
                        U rikthye snapshot <code><?= e($snapName) ?></code>
                        <span style="color:var(--text-muted);">(<?= $tableCount ?> tabela)</span>
                    </div>
                <?php endif; ?>
                </div>

                <!-- Footer: undo/restore buttons -->
                <?php if ($canUndoUpdate || $canUndoDelete): ?>
                <div class="log-footer">
                    <?php if ($canUndoUpdate): ?>
                    <button class="log-undo" onclick="revertChange('<?= e($r['table_name']) ?>', <?= (int)$r['row_id'] ?>, this)" title="Kthe ndryshimin">
                        <i class="fas fa-undo"></i> Kthe
                    </button>
                    <?php elseif ($canUndoDelete): ?>
                    <button class="log-undo log-undo-delete" onclick="restoreDelete('<?= e($r['table_name']) ?>', <?= (int)$r['row_id'] ?>, <?= (int)$r['id'] ?>, this)" title="Rikthe rreshtin e fshire">
                        <i class="fas fa-trash-restore"></i> Rikthe
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="log-pagination">
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

<script>
function toggleDetails(id, btn) {
    var el = document.getElementById('details-' + id);
    if (!el) return;
    el.classList.toggle('open');
    btn.classList.toggle('open');
    btn.querySelector('i').style.transform = el.classList.contains('open') ? 'rotate(90deg)' : '';
}

function revertChange(table, rowId, btn) {
    if (!confirm('Kthe ndryshimin e fundit per kete rresht?')) return;
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
            btn.outerHTML = '<span class="log-reverted-tag"><i class="fas fa-check"></i> kthyer</span>';
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
    if (!confirm('Rikthe rreshtin e fshire? Do te ri-insertohet ne tabelen ' + table + '.')) return;
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
            btn.outerHTML = '<span class="log-reverted-tag"><i class="fas fa-check"></i> u rikthye</span>';
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
