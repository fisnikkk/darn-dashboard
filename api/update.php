<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['table'], $input['id'], $input['field'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$table = $input['table'];
$id = (int)$input['id'];
$field = $input['field'];
$value = $input['value'];

$allowed = [
    'distribuimi' => ['klienti','data','sasia','boca_te_kthyera','litra','cmimi','pagesa',
                      'menyra_e_pageses','fatura_e_derguar','data_e_fletepageses','koment','litrat_total',
                      'litrat_e_konvertuara'],
    'shpenzimet' => ['data_e_pageses','shuma','arsyetimi','lloji_i_pageses',
                     'lloji_i_transaksionit','pershkrim_i_detajuar','nafta_ne_litra',
                     'numri_i_fatures','fatura_e_rregullte'],
    'plini_depo' => ['nr_i_fatures','data','kg','sasia_ne_litra','cmimi','faturat_e_pranuara',
                     'dalje_pagesat_sipas_bankes','menyra_e_pageses','cash_banke','furnitori','koment'],
    'shitje_produkteve' => ['data','cilindra_sasia','produkti','klienti','adresa','qyteti',
                            'cmimi','totali','menyra_pageses','koment','statusi_i_pageses'],
    'kontrata' => ['nr_i_kontrates','data','biznesi','name_from_database','numri_ne_stok_sipas_kontrates',
                   'bashkepunim','qyteti','rruga','numri_unik','perfaqesuesi','nr_telefonit',
                   'koment','email','ne_grup_njoftues','lloji_i_bocave','bocat_e_paguara',
                   'kontrate_e_vjeter','data_rregullatoret'],
    'gjendja_bankare' => ['data','data_valutes','ora','shpjegim','valuta','debia','kredi','bilanci','deftesa','lloji','e_kontrolluar'],
    'nxemese' => ['klienti','data','te_dhena','te_marra','lloji_i_nxemjes','koment'],
    'klientet' => ['emri','bashkepunim','data_e_kontrates','stoku','kontakti','adresa','telefoni','koment',
                   'i_regjistruar_ne_emer','numri_unik_identifikues','telefoni_2'],
    'stoku_zyrtar' => ['data','kodi','kodi_2','pershkrimi','njesi','sasia','cmimi','vlera'],
    'depo' => ['data','produkti','sasia','cmimi'],
];

if (!isset($allowed[$table]) || !in_array($field, $allowed[$table])) {
    echo json_encode(['success' => false, 'error' => 'Field not allowed']);
    exit;
}

try {
    $db = getDB();
    if ($field === 'e_kontrolluar' && $value === 'toggle') {
        $stmt = $db->prepare("SELECT e_kontrolluar FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $newVal = $stmt->fetchColumn() ? 0 : 1;
        $db->prepare("UPDATE {$table} SET e_kontrolluar = ? WHERE id = ?")->execute([$newVal, $id]);
        echo json_encode(['success' => true, 'verified' => (bool)$newVal]);
        exit;
    }
    if ($value === '' || $value === null) $value = null;
    $db->prepare("UPDATE {$table} SET {$field} = ? WHERE id = ?")->execute([$value, $id]);

    // Auto-recalculate sasia_ne_litra when kg changes in plini_depo
    if ($table === 'plini_depo' && $field === 'kg' && $value !== null) {
        $newLitra = round((float)$value * 1.95, 2);
        $db->prepare("UPDATE plini_depo SET sasia_ne_litra = ? WHERE id = ?")->execute([$newLitra, $id]);
    }

    // Auto-recalculate bilanci when debia or kredi changes in gjendja_bankare
    // Cascades to ALL subsequent rows so the running balance stays correct
    if ($table === 'gjendja_bankare' && in_array($field, ['debia', 'kredi'])) {
        $row = $db->prepare("SELECT data, debia, kredi FROM gjendja_bankare WHERE id = ?");
        $row->execute([$id]);
        $current = $row->fetch();
        // Get the bilanci of the previous row (by date/id order)
        $prevStmt = $db->prepare("SELECT COALESCE(bilanci, 0) FROM gjendja_bankare WHERE (data < ? OR (data = ? AND id < ?)) ORDER BY data DESC, id DESC LIMIT 1");
        $prevStmt->execute([$current['data'], $current['data'], $id]);
        $prevBilanci = (float)$prevStmt->fetchColumn();
        $newBilanci = round($prevBilanci + (float)$current['kredi'] - (float)$current['debia'], 2);
        $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$newBilanci, $id]);

        // Cascade: recalculate bilanci for all rows AFTER the edited row
        $cascadeStmt = $db->prepare("SELECT id, debia, kredi FROM gjendja_bankare WHERE (data > ? OR (data = ? AND id > ?)) ORDER BY data ASC, id ASC");
        $cascadeStmt->execute([$current['data'], $current['data'], $id]);
        $runningBilanci = $newBilanci;
        foreach ($cascadeStmt->fetchAll() as $cRow) {
            $runningBilanci = round($runningBilanci + (float)$cRow['kredi'] - (float)$cRow['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$runningBilanci, $cRow['id']]);
        }
    }

    // Auto-recalculate pagesa & litrat_total when sasia, litra, or cmimi changes in distribuimi
    if ($table === 'distribuimi' && in_array($field, ['sasia', 'litra', 'cmimi'])) {
        $row = $db->prepare("SELECT sasia, litra, cmimi FROM distribuimi WHERE id = ?");
        $row->execute([$id]);
        $cur = $row->fetch();
        $s = (float)($cur['sasia'] ?? 0);
        $l = (float)($cur['litra'] ?? 0);
        $c = (float)($cur['cmimi'] ?? 0);
        $newPagesa = round($s * $l * $c, 2);
        $newLitratTotal = round($s * $l, 2);
        $db->prepare("UPDATE distribuimi SET pagesa = ?, litrat_total = ?, litrat_e_konvertuara = ? WHERE id = ?")->execute([$newPagesa, $newLitratTotal, $newLitratTotal, $id]);
    }

    // Auto-recalculate totali when cilindra_sasia or cmimi changes in shitje_produkteve
    if ($table === 'shitje_produkteve' && in_array($field, ['cilindra_sasia', 'cmimi'])) {
        $row = $db->prepare("SELECT cilindra_sasia, cmimi FROM shitje_produkteve WHERE id = ?");
        $row->execute([$id]);
        $cur = $row->fetch();
        $newTotali = round((float)($cur['cilindra_sasia'] ?? 0) * (float)($cur['cmimi'] ?? 0), 2);
        $db->prepare("UPDATE shitje_produkteve SET totali = ? WHERE id = ?")->execute([$newTotali, $id]);
    }

    // Auto-recalculate vlera when sasia or cmimi changes in stoku_zyrtar
    if ($table === 'stoku_zyrtar' && in_array($field, ['sasia', 'cmimi'])) {
        $row = $db->prepare("SELECT sasia, cmimi FROM stoku_zyrtar WHERE id = ?");
        $row->execute([$id]);
        $cur = $row->fetch();
        $newVlera = round((float)($cur['sasia'] ?? 0) * (float)($cur['cmimi'] ?? 0), 2);
        $db->prepare("UPDATE stoku_zyrtar SET vlera = ? WHERE id = ?")->execute([$newVlera, $id]);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
