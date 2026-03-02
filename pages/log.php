<?php
/**
 * DARN Dashboard - Changelog / Audit Log
 * Shows all insert, update, delete operations across all tables
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_table (table_name),
        INDEX idx_created (created_at)
    )");
}

// Pagination
$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Filter by table
$filterTable = $_GET['table'] ?? '';

// Build query
$where = '';
$params = [];
if ($filterTable) {
    $where = 'WHERE table_name = ?';
    $params[] = $filterTable;
}

// Get total count
$countSql = "SELECT COUNT(*) FROM changelog {$where}";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

// Fetch rows
$sql = "SELECT * FROM changelog {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Get distinct table names for filter dropdown
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
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.badge-insert { background: rgba(16,185,129,0.15); color: var(--success); }
.badge-update { background: rgba(245,158,11,0.15); color: var(--warning); }
.badge-delete { background: rgba(239,68,68,0.15); color: var(--danger); }
.log-value {
    max-width: 250px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.82rem;
}
.log-value:hover {
    white-space: normal;
    word-break: break-all;
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
    margin-bottom: 8px;
}
</style>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Log i ndryshimeve</h3>
    </div>
    <div class="card-body">
        <div class="log-filters">
            <label style="font-weight:600; font-size:0.85rem;">Filtro sipas tabeles:</label>
            <select onchange="window.location.href='?table='+this.value">
                <option value="">-- Te gjitha --</option>
                <?php foreach ($tables as $t): ?>
                <option value="<?= e($t) ?>" <?= $filterTable === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log-summary">
            Gjithsej: <?= number_format($totalRows) ?> regjistrime
            <?php if ($totalPages > 1): ?> &mdash; Faqja <?= $page ?> / <?= $totalPages ?><?php endif; ?>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Data/Ora</th>
                        <th>Veprimi</th>
                        <th>Tabela</th>
                        <th>Row ID</th>
                        <th>Fusha</th>
                        <th>Vlera e vjeter</th>
                        <th>Vlera e re</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:24px;">Nuk ka regjistrime.</td></tr>
                    <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="white-space:nowrap; font-size:0.82rem;"><?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?></td>
                        <td><span class="badge badge-<?= $r['action_type'] ?>"><?= $r['action_type'] ?></span></td>
                        <td style="font-weight:600;"><?= e($r['table_name']) ?></td>
                        <td class="num"><?= (int)$r['row_id'] ?></td>
                        <td><?= e($r['field_name'] ?? '-') ?></td>
                        <td class="log-value" title="<?= e($r['old_value'] ?? '') ?>"><?= e($r['old_value'] ?? '-') ?></td>
                        <td class="log-value" title="<?= e($r['new_value'] ?? '') ?>"><?= e($r['new_value'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $baseUrl = '?table=' . urlencode($filterTable) . '&page=';
            if ($page > 1): ?>
                <a href="<?= $baseUrl . ($page - 1) ?>">&laquo; Para</a>
            <?php endif;

            // Show page numbers with window around current page
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

<?php
$content = ob_get_clean();
renderLayout('Log i ndryshimeve', 'log', $content);
