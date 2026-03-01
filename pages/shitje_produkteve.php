<?php
/**
 * DARN Dashboard - Shitje Produkteve (Product Sales)
 * Client field uses dropdown linked to kontrata + distribuimi client names
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;

$totalRows = $db->query("SELECT COUNT(*) FROM shitje_produkteve")->fetchColumn();
$totalPages = ceil($totalRows / $perPage);
$rows = $db->query("SELECT * FROM shitje_produkteve ORDER BY data DESC, id DESC LIMIT {$perPage} OFFSET {$offset}")->fetchAll();

$totalCash = $db->query("SELECT COALESCE(SUM(totali),0) FROM shitje_produkteve WHERE LOWER(TRIM(menyra_pageses)) = 'cash'")->fetchColumn();
$totalAll = $db->query("SELECT COALESCE(SUM(totali),0) FROM shitje_produkteve")->fetchColumn();

// Client list from kontrata (real client registry) + distribuimi
$kontrataClients = $db->query("SELECT DISTINCT name_from_database FROM kontrata WHERE name_from_database IS NOT NULL AND name_from_database != '' ORDER BY name_from_database")->fetchAll(PDO::FETCH_COLUMN);
$distClients = $db->query("SELECT DISTINCT klienti FROM distribuimi WHERE klienti IS NOT NULL AND klienti != '' ORDER BY klienti")->fetchAll(PDO::FETCH_COLUMN);
$allClients = array_unique(array_merge($kontrataClients, $distClients));
sort($allClients);

// Product types from existing data
$produktet = $db->query("SELECT DISTINCT produkti FROM shitje_produkteve WHERE produkti IS NOT NULL ORDER BY produkti")->fetchAll(PDO::FETCH_COLUMN);
$payTypes = $db->query("SELECT DISTINCT menyra_pageses FROM shitje_produkteve WHERE menyra_pageses IS NOT NULL ORDER BY menyra_pageses")->fetchAll(PDO::FETCH_COLUMN);
$payJSON = json_encode($payTypes);

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total Shitje</div>
        <div class="value">&euro; <?= eur($totalAll) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total Cash</div>
        <div class="value">&euro; <?= eur($totalCash) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Transaksione</div>
        <div class="value"><?= num($totalRows) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus-circle"></i> Shto shitje produkti</h3></div>
    <div class="card-body padded">
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="shitje_produkteve">
            <div class="form-row">
                <div class="form-group">
                    <label>Data *</label>
                    <input type="date" name="data" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Sasia (cilindra)</label>
                    <input type="number" name="cilindra_sasia" value="1" min="0">
                </div>
                <div class="form-group">
                    <label>Produkti</label>
                    <input type="text" name="produkti" list="prodList">
                    <datalist id="prodList">
                        <?php foreach ($produktet as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Klienti *</label>
                    <input type="text" name="klienti" required list="klientList" placeholder="Shkruaj ose zgjidh...">
                    <datalist id="klientList">
                        <?php foreach ($allClients as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>Çmimi</label>
                    <input type="number" name="cmimi" step="0.01">
                </div>
                <div class="form-group">
                    <label>Totali (€)</label>
                    <input type="number" name="totali" step="0.01">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Mënyra pagesës</label>
                    <select name="menyra_pageses" id="pagesa-select">
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($payTypes as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?>
                        <option value="__new__">+ Shto të re...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Statusi i pagesës</label>
                    <select name="statusi_i_pageses">
                        <option value="">-- Zgjidh --</option>
                        <option value="Paguar">Paguar</option>
                        <option value="Pa paguar">Pa paguar</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Qyteti</label>
                    <input type="text" name="qyteti">
                </div>
                <div class="form-group">
                    <label>Adresa</label>
                    <input type="text" name="adresa">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Koment</label>
                    <input type="text" name="koment">
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Shitje Produkteve (<?= num($totalRows) ?>)</h3></div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="shitje_produkteve">
                <thead>
                    <tr>
                        <th>Data</th><th class="num">Sasia</th><th>Produkti</th><th>Klienti</th>
                        <th>Adresa</th><th>Qyteti</th><th class="num">Çmimi</th><th class="num">Totali</th>
                        <th>Pagesa</th><th>Koment</th><th>Statusi</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="num editable" data-field="cilindra_sasia" data-type="number"><?= (int)$r['cilindra_sasia'] ?></td>
                        <td class="editable" data-field="produkti"><?= e($r['produkti']) ?></td>
                        <td class="editable" data-field="klienti"><?= e($r['klienti']) ?></td>
                        <td class="editable" data-field="adresa"><?= e($r['adresa']) ?></td>
                        <td class="editable" data-field="qyteti"><?= e($r['qyteti']) ?></td>
                        <td class="amount editable" data-field="cmimi" data-type="number"><?= eur($r['cmimi']) ?></td>
                        <td class="amount editable" data-field="totali" data-type="number" style="font-weight:600;"><?= eur($r['totali']) ?></td>
                        <td class="editable" data-field="menyra_pageses" data-type="select" data-options="<?= e($payJSON) ?>"><?= e($r['menyra_pageses']) ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td class="editable" data-field="statusi_i_pageses" data-type="select" data-options="<?= e(json_encode(['Paguar','Pa paguar'])) ?>"><?= e($r['statusi_i_pageses']) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('shitje_produkteve',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div class="info">Faqja <?= $page ?>/<?= $totalPages ?></div>
            <div class="pages">
                <?php for ($i = max(1,$page-3); $i <= min($totalPages,$page+3); $i++): ?>
                <?= $i === $page ? "<span class='current'>{$i}</span>" : "<a href='?" . http_build_query(array_merge($_GET, ['page' => $i])) . "'>{$i}</a>" ?>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-calculate totali = cilindra_sasia × cmimi for shitje_produkteve add form
(function() {
    const form = document.querySelector('.ajax-form[action="/api/insert.php"]');
    if (!form) return;
    const sasia = form.querySelector('[name="cilindra_sasia"]');
    const cmimi = form.querySelector('[name="cmimi"]');
    const totali = form.querySelector('[name="totali"]');
    function recalcTotal() {
        const s = parseFloat(sasia.value) || 0;
        const c = parseFloat(cmimi.value) || 0;
        totali.value = (s * c).toFixed(2);
    }
    [sasia, cmimi].forEach(el => el.addEventListener('input', recalcTotal));
})();
</script>

<?php
$content = ob_get_clean();
renderLayout('Shitje Produkteve', 'shitje_produkteve', $content);
