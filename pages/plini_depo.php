<?php
/**
 * DARN Dashboard - Plini Depo (Gas Depot/Purchases)
 * Input form with dropdowns for payment method, supplier
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;

$totalRows = $db->query("SELECT COUNT(*) FROM plini_depo")->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$rows = $db->query("SELECT * FROM plini_depo ORDER BY data DESC, id DESC LIMIT {$perPage} OFFSET {$offset}")->fetchAll();

$totalBlerje = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo")->fetchColumn();
$blerjeFature = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='me fature'")->fetchColumn();
$blerjePaFature = $db->query("SELECT COALESCE(SUM(faturat_e_pranuara),0) FROM plini_depo WHERE LOWER(TRIM(menyra_e_pageses))='pa fature'")->fetchColumn();
$totalKg = $db->query("SELECT COALESCE(SUM(kg),0) FROM plini_depo")->fetchColumn();

$menyratPag = ['Me fature', 'Pa fature'];
$cashBanke = ['Cash', 'Banke'];
$furnitoret = $db->query("SELECT DISTINCT furnitori FROM plini_depo WHERE furnitori IS NOT NULL AND furnitori != '' ORDER BY furnitori")->fetchAll(PDO::FETCH_COLUMN);

$menyraJSON = json_encode($menyratPag);
$cbJSON = json_encode($cashBanke);

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total Blerje</div>
        <div class="value">&euro; <?= eur($totalBlerje) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Blerje me faturë</div>
        <div class="value">&euro; <?= eur($blerjeFature) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Blerje pa faturë</div>
        <div class="value">&euro; <?= eur($blerjePaFature) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total kg të blera</div>
        <div class="value"><?= eur($totalKg) ?> kg</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i> Shto blerje plini</h3>
    </div>
    <div class="card-body padded">
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="plini_depo">
            <div class="form-row">
                <div class="form-group">
                    <label>Nr. Faturës</label>
                    <input type="text" name="nr_i_fatures">
                </div>
                <div class="form-group">
                    <label>Data *</label>
                    <input type="date" name="data" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>kg *</label>
                    <input type="number" name="kg" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Çmimi</label>
                    <input type="number" name="cmimi" step="0.0001">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Faturat e pranuara (€)</label>
                    <input type="number" name="faturat_e_pranuara" step="0.01">
                </div>
                <div class="form-group">
                    <label>Dalje/Pagesa sipas bankes (€)</label>
                    <input type="number" name="dalje_pagesat_sipas_bankes" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label>Mënyra e pagesës *</label>
                    <select name="menyra_e_pageses" required>
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($menyratPag as $m): ?>
                        <option value="<?= e($m) ?>"><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Cash/Banke</label>
                    <select name="cash_banke">
                        <option value="">-- Zgjidh --</option>
                        <?php foreach ($cashBanke as $cb): ?>
                        <option value="<?= e($cb) ?>"><?= e($cb) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Furnitori</label>
                    <input type="text" name="furnitori" list="furnList">
                    <datalist id="furnList">
                        <?php foreach ($furnitoret as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?>
                    </datalist>
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
    <div class="card-header"><h3>Plini Depo (<?= num($totalRows) ?> rreshta)</h3></div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="plini_depo">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th class="num">kg</th>
                        <th class="num">Litra</th>
                        <th class="num">Çmimi</th>
                        <th class="num">Faturat</th>
                        <th class="num">Dalje/Banke</th>
                        <th>Mënyra</th>
                        <th>Cash/Banke</th>
                        <th>Furnitori</th>
                        <th>Koment</th>
                        <th class="num">Gjendja</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $gjendja = 0;
                    // Need to recalculate running balance - fetch all ordered by date ASC
                    $allForBalance = $db->query("SELECT id, faturat_e_pranuara, dalje_pagesat_sipas_bankes FROM plini_depo ORDER BY data ASC, id ASC")->fetchAll();
                    $balances = [];
                    $running = 0;
                    foreach ($allForBalance as $b) {
                        $running += (float)$b['faturat_e_pranuara'] - (float)$b['dalje_pagesat_sipas_bankes'];
                        $balances[$b['id']] = $running;
                    }
                    
                    foreach ($rows as $r):
                    $litra = $r['sasia_ne_litra'] !== null ? (float)$r['sasia_ne_litra'] : (float)$r['kg'] * 1.95;
                    ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="data" data-type="date"><?= $r['data'] ?></td>
                        <td class="num editable" data-field="kg" data-type="number"><?= eur($r['kg']) ?></td>
                        <td class="num"><?= eur($litra) ?></td>
                        <td class="num editable" data-field="cmimi" data-type="number"><?= $r['cmimi'] ?></td>
                        <td class="amount editable" data-field="faturat_e_pranuara" data-type="number"><?= eur($r['faturat_e_pranuara']) ?></td>
                        <td class="amount editable" data-field="dalje_pagesat_sipas_bankes" data-type="number"><?= eur($r['dalje_pagesat_sipas_bankes']) ?></td>
                        <td class="editable" data-field="menyra_e_pageses" data-type="select" data-options="<?= e($menyraJSON) ?>"><?= e($r['menyra_e_pageses']) ?></td>
                        <td class="editable" data-field="cash_banke" data-type="select" data-options="<?= e($cbJSON) ?>"><?= e($r['cash_banke']) ?></td>
                        <td class="editable" data-field="furnitori"><?= e($r['furnitori']) ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td class="amount" style="font-weight:600;"><?= eur($balances[$r['id']] ?? 0) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('plini_depo',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
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

<?php
$content = ob_get_clean();
renderLayout('Plini Depo', 'plini_depo', $content);
