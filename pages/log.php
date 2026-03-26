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
    'borxh_deri_daten' => 'Borxhi deri daten', 'shpjegim' => 'Shpjegim',
    'deftesa' => 'Deftesa', 'lloji' => 'Lloji', 'valuta' => 'Valuta', 'ora' => 'Ora',
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
    'gjendja_bankare' => ['shpjegim', 'debia', 'kredi', 'data'],
    'nxemese' => ['klienti', 'data', 'te_dhena', 'te_marra', 'lloji_i_nxemjes'],
    'kontrata' => ['biznesi', 'data'],
    'klientet' => ['klienti'],
    'stoku_zyrtar' => ['produkti', 'sasia'],
    'depo' => ['produkti', 'sasia'],
    'delivery_report' => ['klienti', 'data', 'pagesa', 'menyra_e_pageses'],
];

// Human-friendly value formatting for specific fields
// Transforms raw DB values (0/1, codes, etc.) into readable text
$booleanFields = ['e_kontrolluar', 'fatura_e_derguar', 'fatura_e_rregullte'];
$valueFormatters = [
    // Boolean fields: 0/1 → ✗ Jo / ✓ Po
    'e_kontrolluar' => function($v) {
        if ($v === '1' || $v === 1) return '✓ Po';
        if ($v === '0' || $v === 0) return '✗ Jo';
        return $v;
    },
    'fatura_e_derguar' => function($v) {
        if ($v === '1' || $v === 1) return '✓ Po';
        if ($v === '0' || $v === 0) return '✗ Jo';
        return $v;
    },
    'fatura_e_rregullte' => function($v) {
        if ($v === '1' || $v === 1) return '✓ Po';
        if ($v === '0' || $v === 0) return '✗ Jo';
        return $v;
    },
    // Payment methods
    'menyra_e_pageses' => function($v) {
        $map = ['cash' => 'Cash', 'bank' => 'Bank', 'no_payment' => 'Pa pagese', 'dhurate' => 'Dhurate'];
        return $map[strtolower(trim($v))] ?? $v;
    },
];

// Fields to completely hide from changelog (noise)
$hiddenFields = ['e_kontrolluar', '_row_context'];

// Helper to format a value for display
function formatFieldValue($fieldName, $value, $valueFormatters) {
    if (isset($valueFormatters[$fieldName])) {
        return $valueFormatters[$fieldName]($value);
    }
    return $value;
}

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

// Build query — exclude hidden fields from results entirely
$where = [];
$params = [];
if ($filterTable) { $where[] = 'table_name = ?'; $params[] = $filterTable; }
if ($filterAction) { $where[] = 'action_type = ?'; $params[] = $filterAction; }
// Hide noisy fields like e_kontrolluar from update/revert entries
if (!empty($hiddenFields)) {
    $hfPlaceholders = implode(',', array_fill(0, count($hiddenFields), '?'));
    $where[] = "NOT (action_type IN ('update','revert') AND field_name IN ({$hfPlaceholders}))";
    $params = array_merge($params, $hiddenFields);
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM changelog {$whereSQL}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("SELECT * FROM changelog {$whereSQL} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rawRows = $stmt->fetchAll();

// Group consecutive update/revert entries for the same row (same table+row_id+timestamp)
// into a single "grouped" entry with multiple field changes
$rows = [];
$groupMap = []; // key => index in $rows
foreach ($rawRows as $r) {
    $type = $r['action_type'];
    if (in_array($type, ['update', 'revert'])) {
        $groupKey = "{$type}:{$r['table_name']}:{$r['row_id']}:{$r['created_at']}";
        if (isset($groupMap[$groupKey])) {
            // Add this field change to existing group
            $rows[$groupMap[$groupKey]]['_grouped_fields'][] = $r;
            continue;
        }
        $r['_grouped_fields'] = [$r];
        $groupMap[$groupKey] = count($rows);
    }
    $rows[] = $r;
}

$tables = $db->query("SELECT DISTINCT table_name FROM changelog ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

// Action type counts for stats bar
$statsStmt = $db->prepare("SELECT action_type, COUNT(*) as cnt FROM changelog {$whereSQL} GROUP BY action_type");
$statsStmt->execute($params);
$actionCounts = [];
while ($s = $statsStmt->fetch()) { $actionCounts[$s['action_type']] = (int)$s['cnt']; }

// Fetch row context for update/revert entries (so we can show WHICH row was changed)
$rowContext = []; // keyed by "table_name:row_id"
$contextNeeded = []; // group by table => [id1, id2, ...]
foreach ($rows as $r) {
    if (in_array($r['action_type'], ['update', 'revert']) && $r['row_id']) {
        $contextNeeded[$r['table_name']][$r['row_id']] = true;
    }
}
// Step 1: Try to fetch from the live database tables
foreach ($contextNeeded as $tbl => $ids) {
    $keys = $keyFields[$tbl] ?? [];
    if (empty($keys)) continue;
    $idList = array_keys($ids);
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    $cols = array_map(function($k) { return "`{$k}`"; }, $keys);
    $colSQL = 'id, ' . implode(', ', $cols);
    try {
        $ctxStmt = $db->prepare("SELECT {$colSQL} FROM `{$tbl}` WHERE id IN ({$placeholders})");
        $ctxStmt->execute($idList);
        while ($ctx = $ctxStmt->fetch()) {
            $rowContext["{$tbl}:{$ctx['id']}"] = $ctx;
        }
    } catch (PDOException $e) {
        // Table might not exist or columns changed — skip silently
    }
}
// Step 2: For rows not found in DB (deleted), get context from changelog's own JSON data
foreach ($contextNeeded as $tbl => $ids) {
    foreach (array_keys($ids) as $rid) {
        $ctxKey = "{$tbl}:{$rid}";
        if (isset($rowContext[$ctxKey])) continue; // already found in DB

        // Step 2a: Look for _row_context entries (used for delivery_report and other remote tables)
        try {
            $ctxStmt = $db->prepare("SELECT new_value FROM changelog WHERE table_name = ? AND row_id = ? AND field_name = '_row_context' ORDER BY created_at DESC LIMIT 1");
            $ctxStmt->execute([$tbl, $rid]);
            $ctxRow = $ctxStmt->fetch();
            if ($ctxRow) {
                $data = json_decode($ctxRow['new_value'] ?? '{}', true);
                if ($data && is_array($data)) {
                    $rowContext[$ctxKey] = $data;
                    continue;
                }
            }
        } catch (PDOException $e) {}

        // Step 2b: Look for an insert or delete changelog entry that has JSON for this row
        try {
            $fbStmt = $db->prepare("SELECT action_type, old_value, new_value FROM changelog WHERE table_name = ? AND row_id = ? AND action_type IN ('insert','delete') ORDER BY created_at DESC LIMIT 1");
            $fbStmt->execute([$tbl, $rid]);
            $fb = $fbStmt->fetch();
            if ($fb) {
                $json = ($fb['action_type'] === 'delete') ? $fb['old_value'] : $fb['new_value'];
                $data = json_decode($json ?? '{}', true);
                if ($data && is_array($data)) {
                    $rowContext[$ctxKey] = $data;
                }
            }
        } catch (PDOException $e) {}
    }
}

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

/* Row context pills (which row was changed) */
.log-context {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 6px;
}
.log-context-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.73rem;
    background: #f1f5f9;
    color: var(--text);
    border: 1px solid #e2e8f0;
}
.log-context-pill strong {
    font-weight: 600;
    color: var(--text-muted);
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.2px;
    margin-right: 3px;
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
    margin-bottom: 4px;
}
.log-diff:last-child { margin-bottom: 0; }
.log-diff-field {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text);
    letter-spacing: 0.2px;
}
.log-diff-field::after {
    content: ':';
}
.log-old {
    display: inline-block;
    padding: 3px 10px;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 4px;
    font-size: 0.82rem;
    text-decoration: line-through;
    text-decoration-color: rgba(153, 27, 27, 0.4);
    max-width: 300px;
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
    font-size: 0.82rem;
    font-weight: 600;
    max-width: 300px;
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
                // Skip hidden fields (e.g. e_kontrolluar checkbox toggles) — only for non-grouped entries
                if (!isset($r['_grouped_fields']) && in_array($r['field_name'] ?? '', $hiddenFields) && in_array($r['action_type'], ['update', 'revert'])) continue;

                // For grouped entries, check if ALL fields are reverted
                $isReverted = !empty($r['reverted']);
                if (isset($r['_grouped_fields'])) {
                    $isReverted = true;
                    foreach ($r['_grouped_fields'] as $gf) {
                        if (empty($gf['reverted'])) { $isReverted = false; break; }
                    }
                }
                $type = $r['action_type'];
                $tableName = $r['table_name'];
                $tableLabel = $tableLabels[$tableName] ?? $tableName;
                $tableIcon = $tableIcons[$tableName] ?? 'fa-database';
                $actionLabel = $actionLabels[$type] ?? $type;
                $canUndoUpdate = ($type === 'update' && !$isReverted);
                $canUndoDelete = ($type === 'delete' && !$isReverted);
                $canUndoInsert = ($type === 'insert' && !$isReverted && in_array($r['field_name'] ?? '', ['bulk_paste', 'import_excel', 'import_script', null, '']));
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
                    <?php
                    $groupCount = isset($r['_grouped_fields']) ? count($r['_grouped_fields']) : 0;
                    if ($groupCount > 1): ?>
                    <span class="log-badge" style="background:#e2e8f0;color:var(--text);"><?= $groupCount ?> fusha</span>
                    <?php endif; ?>
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
                    // Show row context: which row is this?
                    $ctxKey = "{$tableName}:{$r['row_id']}";
                    $ctx = $rowContext[$ctxKey] ?? null;
                    $ctxKeys = $keyFields[$tableName] ?? [];
                    if ($ctx && $ctxKeys):
                    ?>
                    <div class="log-context">
                        <?php foreach ($ctxKeys as $ck):
                            if (!isset($ctx[$ck]) || $ctx[$ck] === '' || $ctx[$ck] === null) continue;
                            $clabel = $fieldLabels[$ck] ?? $ck;
                            $cval = $ctx[$ck];
                            if (mb_strlen((string)$cval) > 40) $cval = mb_substr((string)$cval, 0, 37) . '...';
                        ?>
                        <span class="log-context-pill"><strong><?= e($clabel) ?></strong><?= e($cval) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php
                    // Render all field changes (grouped or single)
                    $fieldChanges = $r['_grouped_fields'] ?? [$r];
                    foreach ($fieldChanges as $fc):
                        $rawFieldName = $fc['field_name'] ?? '';
                        // Skip hidden fields within a group
                        if (in_array($rawFieldName, $hiddenFields)) continue;
                        $field = $fieldLabels[$rawFieldName] ?? $rawFieldName;
                        $old = $fc['old_value'] ?? '';
                        $new = $fc['new_value'] ?? '';
                        $oldFormatted = formatFieldValue($rawFieldName, $old, $valueFormatters);
                        $newFormatted = formatFieldValue($rawFieldName, $new, $valueFormatters);
                        $oldDisplay = mb_strlen($oldFormatted) > 80 ? mb_substr($oldFormatted, 0, 77) . '...' : $oldFormatted;
                        $newDisplay = mb_strlen($newFormatted) > 80 ? mb_substr($newFormatted, 0, 77) . '...' : $newFormatted;
                        if ($old === '' || $old === null) $oldDisplay = '(bosh)';
                        if ($new === '' || $new === null) $newDisplay = '(bosh)';
                    ?>
                    <div class="log-diff">
                        <span class="log-diff-field"><?= e($field) ?></span>
                        <span class="log-old" title="<?= e($old) ?>"><?= e($oldDisplay) ?></span>
                        <i class="fas fa-long-arrow-alt-right log-arrow"></i>
                        <span class="log-new" title="<?= e($new) ?>"><?= e($newDisplay) ?></span>
                    </div>
                    <?php endforeach; ?>

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
                            $val = formatFieldValue($k, $data[$k], $valueFormatters);
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
                                $dv = formatFieldValue($dk, $dv, $valueFormatters);
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
                            $val = formatFieldValue($k, $data[$k], $valueFormatters);
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
                                $dv = formatFieldValue($dk, $dv, $valueFormatters);
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

                <!-- Footer: undo/redo buttons -->
                <?php if ($canUndoUpdate || $canUndoDelete || $canUndoInsert): ?>
                <div class="log-footer">
                    <?php if ($canUndoUpdate): ?>
                    <button class="log-undo" onclick="revertChange('<?= e($r['table_name']) ?>', <?= (int)$r['row_id'] ?>, this)" title="Kthe ndryshimin">
                        <i class="fas fa-undo"></i> Kthe
                    </button>
                    <?php elseif ($canUndoDelete): ?>
                    <button class="log-undo log-undo-delete" onclick="restoreDelete('<?= e($r['table_name']) ?>', <?= (int)$r['row_id'] ?>, <?= (int)$r['id'] ?>, this)" title="Rikthe rreshtin e fshire">
                        <i class="fas fa-trash-restore"></i> Rikthe
                    </button>
                    <?php elseif ($canUndoInsert): ?>
                    <button class="log-undo" style="color:#dc2626;" onclick="revertInsert('<?= e($r['table_name']) ?>', <?= (int)$r['row_id'] ?>, <?= (int)$r['id'] ?>, this)" title="Fshi rreshtin e shtuar">
                        <i class="fas fa-undo"></i> Kthe (fshi)
                    </button>
                    <?php endif; ?>
                </div>
                <?php elseif ($isReverted && $type === 'update'): ?>
                <div class="log-footer" style="justify-content:flex-end;">
                    <span class="log-reverted-tag"><i class="fas fa-check"></i> u rikthye</span>
                    <button class="log-undo" style="color:#059669;margin-left:8px;" onclick="redoChange('<?= e($r['table_name']) ?>', <?= (int)$r['row_id'] ?>, <?= isset($r['_grouped_fields']) ? e(json_encode(array_map(fn($gf) => (int)$gf['id'], $r['_grouped_fields']))) : '[' . (int)$r['id'] . ']' ?>, this)" title="Ri-apliko ndryshimin">
                        <i class="fas fa-redo"></i> Ri-apliko
                    </button>
                </div>
                <?php elseif ($isReverted): ?>
                <div class="log-footer" style="justify-content:flex-end;">
                    <span class="log-reverted-tag"><i class="fas fa-check"></i> u rikthye</span>
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

function revertInsert(table, rowId, changelogId, btn) {
    if (!confirm('Fshi rreshtin #' + rowId + ' qe u shtua? Kjo veprim do te fshije rreshtin nga tabela ' + table + '.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    fetch('/api/revert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table: table, id: rowId, action: 'revert_insert', changelog_id: changelogId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.closest('.log-entry').classList.add('reverted');
            btn.outerHTML = '<span class="log-reverted-tag"><i class="fas fa-check"></i> u fshi</span>';
            showToast(data.message || 'Shtimi u kthye me sukses', 'success');
        } else {
            showToast(data.error || 'Gabim', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo"></i> Kthe (fshi)';
        }
    })
    .catch(() => {
        showToast('Gabim rrjeti', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-undo"></i> Kthe (fshi)';
    });
}

function redoChange(table, rowId, changelogIds, btn) {
    if (!confirm('Ri-apliko ndryshimin? Kjo do te ri-vendose vlerat e reja.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    fetch('/api/revert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table: table, id: rowId, action: 'redo', changelog_ids: changelogIds })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.closest('.log-entry').classList.remove('reverted');
            btn.closest('.log-footer').innerHTML = '<button class="log-undo" onclick="revertChange(\'' + table + '\', ' + rowId + ', this)" title="Kthe ndryshimin"><i class="fas fa-undo"></i> Kthe</button>';
            showToast(data.message || 'U ri-aplikua me sukses', 'success');
        } else {
            showToast(data.error || 'Gabim', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-redo"></i> Ri-apliko';
        }
    })
    .catch(() => {
        showToast('Gabim rrjeti', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-redo"></i> Ri-apliko';
    });
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Log i ndryshimeve', 'log', $content);
