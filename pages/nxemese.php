<?php
/**
 * DARN Dashboard - Nxemëse (Heaters)
 * Manual input, stock per client tracking (like cylinders)
 * Mini report: each client's heater inventory
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Stock per client (mini report)
$stokuPerKlient = $db->query("
    SELECT MIN(klienti) as klienti,
        SUM(te_dhena) as dhena,
        SUM(te_marra) as marra,
        SUM(te_dhena) - SUM(te_marra) as ne_stok
    FROM nxemese
    GROUP BY LOWER(klienti)
    HAVING ne_stok != 0
    ORDER BY ne_stok DESC
")->fetchAll();

$totalTerren = $db->query("SELECT COALESCE(SUM(te_dhena) - SUM(te_marra), 0) FROM nxemese")->fetchColumn();

// All transactions
$rows = $db->query("SELECT * FROM nxemese ORDER BY data DESC, id DESC")->fetchAll();

// Per-row running totals: Ne stok (per client) and Total ne terren (global)
$allRowsAsc = $db->query("SELECT id FROM nxemese ORDER BY data ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
$nxRunningPerClient = [];
$nxRunningTotal = [];
if ($allRowsAsc) {
    $runStmt = $db->query("
        SELECT id,
            SUM(te_dhena - te_marra) OVER (
                PARTITION BY LOWER(klienti) ORDER BY data ASC, id ASC
            ) as ne_stok_running,
            SUM(te_dhena - te_marra) OVER (
                ORDER BY data ASC, id ASC
            ) as total_terren_running
        FROM nxemese
    ");
    foreach ($runStmt->fetchAll() as $rr) {
        $nxRunningPerClient[$rr['id']] = (int)$rr['ne_stok_running'];
        $nxRunningTotal[$rr['id']] = (int)$rr['total_terren_running'];
    }
}

// Client list for dropdown
$klientet = $db->query("SELECT DISTINCT klienti FROM nxemese ORDER BY klienti")->fetchAll(PDO::FETCH_COLUMN);
$llojet = $db->query("SELECT DISTINCT lloji_i_nxemjes FROM nxemese WHERE lloji_i_nxemjes IS NOT NULL ORDER BY lloji_i_nxemjes")->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card"><div class="label">Nxemëse total në terren</div><div class="value"><?= num($totalTerren) ?></div></div>
    <div class="summary-card"><div class="label">Klientë me nxemëse</div><div class="value"><?= count($stokuPerKlient) ?></div></div>
</div>

<!-- Mini Report: Stock per client -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-fire"></i> Stoku i nxemëseve sipas klientit</h3></div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr><th>Klienti</th><th class="num">Të dhëna</th><th class="num">Të marra</th><th class="num">Në stok</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($stokuPerKlient as $s): ?>
                    <tr style="<?= $s['ne_stok'] == 0 ? 'color:var(--text-muted);' : '' ?>">
                        <td><?= e($s['klienti']) ?></td>
                        <td class="num"><?= (int)$s['dhena'] ?></td>
                        <td class="num"><?= (int)$s['marra'] ?></td>
                        <td class="num" style="font-weight:700;<?= $s['ne_stok'] > 0 ? 'color:var(--primary);' : 'color:var(--danger);' ?>"><?= (int)$s['ne_stok'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Input Form -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus-circle"></i> Shto lëvizje nxemëse</h3></div>
    <div class="card-body padded">
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="nxemese">
            <div class="form-row">
                <div class="form-group"><label>Klienti *</label>
                    <input type="text" name="klienti" required list="nxKlientList">
                    <datalist id="nxKlientList"><?php foreach ($klientet as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
                </div>
                <div class="form-group"><label>Data *</label><input type="date" name="data" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>Të dhëna</label><input type="number" name="te_dhena" value="0" min="0"></div>
                <div class="form-group"><label>Të marra</label><input type="number" name="te_marra" value="0" min="0"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Lloji i nxemjes</label>
                    <select name="lloji_i_nxemjes" id="nxemje-select">
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($llojet as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
                        <option value="__new__">+ Shto të re...</option>
                    </select>
                </div>
                <div class="form-group"><label>Koment</label><input type="text" name="koment"></div>
                <div class="form-group" style="justify-content:flex-end;"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button></div>
            </div>
        </form>
    </div>
</div>

<!-- Transaction log -->
<div class="card">
    <div class="card-header"><h3>Të gjitha lëvizjet (<?= count($rows) ?>)</h3></div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="nxemese">
                <thead><tr><th>Data</th><th>Klienti</th><th class="num">Dhënë</th><th class="num">Marrë</th><th class="num">Në stok</th><th class="num">Total terren</th><th>Lloji</th><th>Koment</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="editable" data-field="klienti"><?= e($r['klienti']) ?></td>
                        <td class="num editable" data-field="te_dhena" data-type="number"><?= (int)$r['te_dhena'] ?></td>
                        <td class="num editable" data-field="te_marra" data-type="number"><?= (int)$r['te_marra'] ?></td>
                        <td class="num" style="font-weight:600;color:var(--primary);"><?= $nxRunningPerClient[$r['id']] ?? '-' ?></td>
                        <td class="num" style="color:var(--text-muted);"><?= $nxRunningTotal[$r['id']] ?? '-' ?></td>
                        <td class="editable" data-field="lloji_i_nxemjes" data-type="select" data-options="<?= e(json_encode($llojet)) ?>"><?= e($r['lloji_i_nxemjes']) ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('nxemese',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
renderLayout('Nxemëse', 'nxemese', $content);
