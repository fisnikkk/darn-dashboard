<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$table = $_POST['_table'] ?? '';
if (!$table) { echo json_encode(['success' => false, 'error' => 'No table']); exit; }

// Define insert fields per table
$schemas = [
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
    'gjendja_bankare' => ['data','data_valutes','ora','shpjegim','valuta','debia','kredi','bilanci','deftesa','lloji'],
    'nxemese' => ['klienti','data','te_dhena','te_marra','lloji_i_nxemjes','koment'],
    'klientet' => ['emri','bashkepunim','data_e_kontrates','stoku','kontakti','adresa','telefoni','koment',
                   'i_regjistruar_ne_emer','numri_unik_identifikues','telefoni_2'],
    'stoku_zyrtar' => ['data','kodi','kodi_2','pershkrimi','njesi','sasia','cmimi','vlera'],
    'depo' => ['data','produkti','sasia','cmimi'],
];

if (!isset($schemas[$table])) {
    echo json_encode(['success' => false, 'error' => 'Invalid table']);
    exit;
}

try {
    $db = getDB();
    $fields = [];
    $values = [];
    $placeholders = [];
    
    foreach ($schemas[$table] as $col) {
        if (isset($_POST[$col]) && $_POST[$col] !== '' && $_POST[$col] !== '__new__') {
            $fields[] = $col;
            $values[] = $_POST[$col];
            $placeholders[] = '?';
        }
    }
    
    if (empty($fields)) {
        echo json_encode(['success' => false, 'error' => 'No data provided']);
        exit;
    }
    
    // Auto-calculate sasia_ne_litra for plini_depo if kg is provided but litra isn't
    if ($table === 'plini_depo' && !in_array('sasia_ne_litra', $fields) && in_array('kg', $fields)) {
        $kgIdx = array_search('kg', $fields);
        $fields[] = 'sasia_ne_litra';
        $values[] = round((float)$values[$kgIdx] * 1.95, 2);
        $placeholders[] = '?';
    }

    // Auto-calculate pagesa, litrat_total, litrat_e_konvertuara for distribuimi
    if ($table === 'distribuimi') {
        $sasiaIdx = array_search('sasia', $fields);
        $litraIdx = array_search('litra', $fields);
        $cmimiIdx = array_search('cmimi', $fields);
        $sVal = $sasiaIdx !== false ? (float)$values[$sasiaIdx] : 0;
        $lVal = $litraIdx !== false ? (float)$values[$litraIdx] : 0;
        $cVal = $cmimiIdx !== false ? (float)$values[$cmimiIdx] : 0;

        // pagesa = sasia × litra × cmimi (if not provided)
        $pagesaIdx = array_search('pagesa', $fields);
        if ($pagesaIdx === false) {
            $calcPagesa = round($sVal * $lVal * $cVal, 2);
            $fields[] = 'pagesa';
            $values[] = $calcPagesa;
            $placeholders[] = '?';
        }

        // litrat_total = sasia × litra (if not provided)
        $ltIdx = array_search('litrat_total', $fields);
        if ($ltIdx === false) {
            $calcLt = round($sVal * $lVal, 2);
            $fields[] = 'litrat_total';
            $values[] = $calcLt;
            $placeholders[] = '?';
        }

        // litrat_e_konvertuara = same as litrat_total for new entries (if not provided)
        if (!in_array('litrat_e_konvertuara', $fields)) {
            $ltFinalIdx = array_search('litrat_total', $fields);
            $fields[] = 'litrat_e_konvertuara';
            $values[] = $ltFinalIdx !== false ? $values[$ltFinalIdx] : round($sVal * $lVal, 2);
            $placeholders[] = '?';
        }
    }

    // Auto-calculate bilanci for gjendja_bankare if not provided
    if ($table === 'gjendja_bankare') {
        $bilanciIdx = array_search('bilanci', $fields);
        if ($bilanciIdx === false || $values[$bilanciIdx] === null || $values[$bilanciIdx] === '') {
            // Get the latest bilanci from the database
            $prevBilanci = (float)$db->query("SELECT COALESCE(bilanci, 0) FROM gjendja_bankare ORDER BY data DESC, id DESC LIMIT 1")->fetchColumn();
            $krediIdx = array_search('kredi', $fields);
            $debiaIdx = array_search('debia', $fields);
            $k = ($krediIdx !== false) ? (float)$values[$krediIdx] : 0;
            $d = ($debiaIdx !== false) ? (float)$values[$debiaIdx] : 0;
            $calcBilanci = round($prevBilanci + $k - $d, 2);
            if ($bilanciIdx !== false) {
                $values[$bilanciIdx] = $calcBilanci;
            } else {
                $fields[] = 'bilanci';
                $values[] = $calcBilanci;
                $placeholders[] = '?';
            }
        }
    }

    // Auto-calculate totali for shitje_produkteve if not provided
    if ($table === 'shitje_produkteve') {
        $totaliIdx = array_search('totali', $fields);
        if ($totaliIdx === false) {
            $sasiaIdx = array_search('cilindra_sasia', $fields);
            $cmimiIdx = array_search('cmimi', $fields);
            $s = ($sasiaIdx !== false) ? (float)$values[$sasiaIdx] : 0;
            $c = ($cmimiIdx !== false) ? (float)$values[$cmimiIdx] : 0;
            $calcTotal = round($s * $c, 2);
            $fields[] = 'totali';
            $values[] = $calcTotal;
            $placeholders[] = '?';
        }
    }

    // Auto-calculate vlera for stoku_zyrtar if not provided
    if ($table === 'stoku_zyrtar') {
        $vleraIdx = array_search('vlera', $fields);
        if ($vleraIdx === false) {
            $sasiaIdx = array_search('sasia', $fields);
            $cmimiIdx = array_search('cmimi', $fields);
            $s = ($sasiaIdx !== false) ? (float)$values[$sasiaIdx] : 0;
            $c = ($cmimiIdx !== false) ? (float)$values[$cmimiIdx] : 0;
            $fields[] = 'vlera';
            $values[] = round($s * $c, 2);
            $placeholders[] = '?';
        }
    }

    $sql = "INSERT INTO {$table} (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
    $db->prepare($sql)->execute($values);
    $newId = $db->lastInsertId();

    // Log the insert to changelog
    $insertedData = array_combine($fields, $values);
    $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('insert', ?, ?, NULL, NULL, ?)")
        ->execute([$table, (int)$newId, json_encode($insertedData, JSON_UNESCAPED_UNICODE)]);

    // After inserting a gjendja_bankare row, recalculate ALL bilanci from scratch
    // (handles backdated entries where the new row isn't the latest by date)
    if ($table === 'gjendja_bankare') {
        $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
        $running = 0;
        foreach ($all as $r) {
            $running = round($running + (float)$r['kredi'] - (float)$r['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
        }
    }

    echo json_encode(['success' => true, 'id' => $newId, 'reload' => true,
                       'message' => 'U shtua me sukses']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
