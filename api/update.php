<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['table'], $input['id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Support batch mode: changes: [{field, value}, ...] OR single field/value
$table = $input['table'];
$id = (int)$input['id'];
$changes = [];
if (isset($input['changes']) && is_array($input['changes'])) {
    $changes = $input['changes'];
} elseif (isset($input['field'])) {
    $changes = [['field' => $input['field'], 'value' => $input['value']]];
} else {
    echo json_encode(['success' => false, 'error' => 'Missing field or changes']);
    exit;
}

// For backwards compat, set $field/$value from first change (used in single-field logic)
$field = $changes[0]['field'];
$value = $changes[0]['value'];

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
    'gjendja_bankare' => ['data','data_valutes','ora','shpjegim','valuta','debia','kredi','bilanci','deftesa','lloji','klienti','e_kontrolluar','komentet'],
    'nxemese' => ['klienti','data','te_dhena','te_marra','lloji_i_nxemjes','koment'],
    'klientet' => ['emri','bashkepunim','data_e_kontrates','stoku','kontakti','adresa','telefoni','koment',
                   'i_regjistruar_ne_emer','numri_unik_identifikues','telefoni_2'],
    'stoku_zyrtar' => ['data','kodi','kodi_2','pershkrimi','njesi','sasia','cmimi','vlera'],
    'depo' => ['data','produkti','sasia','cmimi'],
];

if (!isset($allowed[$table])) {
    echo json_encode(['success' => false, 'error' => 'Table not allowed']);
    exit;
}
// Validate all fields in the batch
foreach ($changes as $ch) {
    if (!in_array($ch['field'], $allowed[$table])) {
        echo json_encode(['success' => false, 'error' => 'Field not allowed: ' . $ch['field']]);
        exit;
    }
}

try {
    $db = getDB();

    // Special case: toggle verified (single-field only)
    if ($field === 'e_kontrolluar' && $value === 'toggle') {
        $stmt = $db->prepare("SELECT e_kontrolluar FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $oldVal = $stmt->fetchColumn();
        $newVal = $oldVal ? 0 : 1;
        $db->prepare("UPDATE {$table} SET e_kontrolluar = ? WHERE id = ?")->execute([$newVal, $id]);
        // Log the toggle
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('update', ?, ?, ?, ?, ?)")
            ->execute([$table, $id, 'e_kontrolluar', (string)$oldVal, (string)$newVal]);
        echo json_encode(['success' => true, 'verified' => (bool)$newVal]);
        exit;
    }

    // Wrap everything in a transaction so batch updates are atomic
    $db->beginTransaction();

    // Fetch current row BEFORE any changes (for changelog)
    $stmtCurrent = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmtCurrent->execute([$id]);
    $currentRow = $stmtCurrent->fetch();

    // Track which fields were changed for auto-recalc triggers
    $changedFields = [];
    foreach ($changes as $ch) {
        $f = $ch['field'];
        $v = $ch['value'];
        if ($v === '' || $v === null) $v = null;
        $db->prepare("UPDATE {$table} SET {$f} = ? WHERE id = ?")->execute([$v, $id]);
        $changedFields[] = $f;
        // Log the change
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('update', ?, ?, ?, ?, ?)")
            ->execute([$table, $id, $f, $currentRow[$f] ?? null, $v]);
    }

    // Auto-recalculate sasia_ne_litra when kg changes in plini_depo
    if ($table === 'plini_depo' && in_array('kg', $changedFields)) {
        $cur = $db->prepare("SELECT kg FROM plini_depo WHERE id = ?");
        $cur->execute([$id]);
        $kgVal = $cur->fetchColumn();
        if ($kgVal !== null) {
            $newLitra = round((float)$kgVal * 1.95, 2);
            $db->prepare("UPDATE plini_depo SET sasia_ne_litra = ? WHERE id = ?")->execute([$newLitra, $id]);
        }
    }

    // Auto-recalculate bilanci when debia, kredi, or data changes in gjendja_bankare
    if ($table === 'gjendja_bankare' && array_intersect(['debia', 'kredi', 'data'], $changedFields)) {
        // Recalculate ALL rows from the beginning (safest, handles any ordering)
        $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
        $running = 0;
        foreach ($all as $r) {
            $running = round($running + (float)$r['kredi'] - (float)$r['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
        }
    }

    // Auto-recalculate pagesa & litrat_total when litra or cmimi changes in distribuimi
    // Formula: pagesa = litra × cmimi (litra column = total liters, NOT per-unit)
    if ($table === 'distribuimi' && array_intersect(['litra', 'cmimi'], $changedFields)) {
        $row = $db->prepare("SELECT litra, cmimi FROM distribuimi WHERE id = ?");
        $row->execute([$id]);
        $cur = $row->fetch();
        $l = (float)($cur['litra'] ?? 0);
        $c = (float)($cur['cmimi'] ?? 0);
        $newPagesa = round($l * $c, 2);
        $db->prepare("UPDATE distribuimi SET pagesa = ? WHERE id = ?")->execute([$newPagesa, $id]);
    }

    // Auto-recalculate totali when cilindra_sasia or cmimi changes in shitje_produkteve
    if ($table === 'shitje_produkteve' && array_intersect(['cilindra_sasia', 'cmimi'], $changedFields)) {
        $row = $db->prepare("SELECT cilindra_sasia, cmimi FROM shitje_produkteve WHERE id = ?");
        $row->execute([$id]);
        $cur = $row->fetch();
        $newTotali = round((float)($cur['cilindra_sasia'] ?? 0) * (float)($cur['cmimi'] ?? 0), 2);
        $db->prepare("UPDATE shitje_produkteve SET totali = ? WHERE id = ?")->execute([$newTotali, $id]);
    }

    // Auto-recalculate vlera when sasia or cmimi changes in stoku_zyrtar
    if ($table === 'stoku_zyrtar' && array_intersect(['sasia', 'cmimi'], $changedFields)) {
        $row = $db->prepare("SELECT sasia, cmimi FROM stoku_zyrtar WHERE id = ?");
        $row->execute([$id]);
        $cur = $row->fetch();
        $newVlera = round((float)($cur['sasia'] ?? 0) * (float)($cur['cmimi'] ?? 0), 2);
        $db->prepare("UPDATE stoku_zyrtar SET vlera = ? WHERE id = ?")->execute([$newVlera, $id]);
    }

    $db->commit();
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
