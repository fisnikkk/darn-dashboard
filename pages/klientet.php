<?php
/**
 * DARN Dashboard - Klientët (Client Master List)
 * Full CRUD: inline editing, add, delete
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();
$rows = $db->query("SELECT * FROM klientet ORDER BY emri ASC")->fetchAll();
$total = count($rows);

$bashkepunimJSON = json_encode(['po', 'jo']);

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card"><div class="label">Total Klientë</div><div class="value"><?= num($total) ?></div></div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Lista e Klientëve</h3>
        <button class="btn btn-primary btn-sm" onclick="openModal('addKlient')"><i class="fas fa-plus"></i> Shto klient</button>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table" data-table="klientet">
                <thead>
                    <tr>
                        <th>Emri</th><th>Bashkëpunim</th><th>Data kontratës</th><th class="num">Stoku</th>
                        <th>Kontakti</th><th>Adresa</th><th>Telefoni</th><th>Telefoni 2</th>
                        <th>Regjistruar në emër</th><th>Nr. unik identifikues</th><th>Koment</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td class="editable" data-field="emri" style="font-weight:600;"><?= e($r['emri']) ?></td>
                        <td class="editable" data-field="bashkepunim" data-type="select" data-options="<?= e($bashkepunimJSON) ?>"><?= e($r['bashkepunim']) ?></td>
                        <td class="editable" data-field="data_e_kontrates" data-type="date"><?= $r['data_e_kontrates'] ?></td>
                        <td class="num editable" data-field="stoku" data-type="number"><?= (int)$r['stoku'] ?></td>
                        <td class="editable" data-field="kontakti"><?= e($r['kontakti']) ?></td>
                        <td class="editable" data-field="adresa"><?= e($r['adresa']) ?></td>
                        <td class="editable" data-field="telefoni"><?= e($r['telefoni']) ?></td>
                        <td class="editable" data-field="telefoni_2"><?= e($r['telefoni_2']) ?></td>
                        <td class="editable" data-field="i_regjistruar_ne_emer"><?= e($r['i_regjistruar_ne_emer']) ?></td>
                        <td class="editable" data-field="numri_unik_identifikues"><?= e($r['numri_unik_identifikues']) ?></td>
                        <td class="editable truncate" data-field="koment" title="<?= e($r['koment']) ?>"><?= e($r['koment']) ?></td>
                        <td><button class="btn btn-danger btn-sm" onclick="deleteRow('klientet',<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Client Modal -->
<div class="modal-overlay" id="addKlient">
    <div class="modal">
        <div class="modal-header"><h3>Shto klient të ri</h3><button class="btn btn-outline btn-sm" onclick="closeModal('addKlient')">&times;</button></div>
        <form class="ajax-form" action="/api/insert.php" method="POST">
            <input type="hidden" name="_table" value="klientet">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label>Emri *</label><input type="text" name="emri" required></div>
                    <div class="form-group"><label>Bashkëpunim</label>
                        <select name="bashkepunim"><option value="po">Po</option><option value="jo">Jo</option></select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Data kontratës</label><input type="date" name="data_e_kontrates"></div>
                    <div class="form-group"><label>Stoku</label><input type="number" name="stoku" value="0" min="0"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Kontakti</label><input type="text" name="kontakti"></div>
                    <div class="form-group"><label>Telefoni</label><input type="text" name="telefoni"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Telefoni 2</label><input type="text" name="telefoni_2"></div>
                    <div class="form-group"><label>Regjistruar në emër</label><input type="text" name="i_regjistruar_ne_emer"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Nr. unik identifikues</label><input type="text" name="numri_unik_identifikues"></div>
                    <div class="form-group"><label>Adresa</label><input type="text" name="adresa"></div>
                    <div class="form-group"><label>Koment</label><input type="text" name="koment"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addKlient')">Anulo</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ruaj</button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
renderLayout('Klientët', 'klientet', $content);
