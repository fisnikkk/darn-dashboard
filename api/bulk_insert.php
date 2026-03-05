<?php
/**
 * Bulk insert API for gjendja_bankare and distribuimi (paste from Excel)
 * Accepts JSON: { table: "gjendja_bankare"|"distribuimi", rows: [...] }
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$table = $input['table'] ?? '';
$rows = $input['rows'] ?? [];

$allowedTables = ['gjendja_bankare', 'distribuimi'];
if (!in_array($table, $allowedTables)) {
    echo json_encode(['success' => false, 'error' => 'Table not supported']);
    exit;
}

if (empty($rows)) {
    echo json_encode(['success' => false, 'error' => 'No rows provided']);
    exit;
}

try {
    $db = getDB();
    $db->beginTransaction();
    $inserted = 0;

    // Prepare changelog statement for logging each inserted row
    $logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('insert', ?, ?, 'bulk_paste', NULL, ?)");

    if ($table === 'gjendja_bankare') {
        $sql = "INSERT INTO gjendja_bankare (data, data_valutes, ora, shpjegim, valuta, debia, kredi, bilanci, deftesa, lloji, klienti, komentet)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);

        // Get the latest bilanci for fallback auto-calculation
        $prevBilanci = (float)$db->query("SELECT COALESCE(bilanci, 0) FROM gjendja_bankare ORDER BY data DESC, id DESC LIMIT 1")->fetchColumn();

        foreach ($rows as $row) {
            $data = ($row['data'] ?? '') !== '' ? $row['data'] : null;
            $dataValutes = ($row['data_valutes'] ?? '') !== '' ? $row['data_valutes'] : null;
            $ora = ($row['ora'] ?? '') !== '' ? $row['ora'] : null;
            $shpjegim = ($row['shpjegim'] ?? '') !== '' ? $row['shpjegim'] : null;
            $valuta = ($row['valuta'] ?? '') !== '' ? $row['valuta'] : null;
            $debia = is_numeric($row['debia'] ?? '') ? (float)$row['debia'] : null;
            $kredi = is_numeric($row['kredi'] ?? '') ? (float)$row['kredi'] : null;
            $bilanci = is_numeric($row['bilanci'] ?? '') ? (float)$row['bilanci'] : null;
            $deftesa = ($row['deftesa'] ?? '') !== '' ? $row['deftesa'] : null;
            $lloji = ($row['lloji'] ?? '') !== '' ? $row['lloji'] : null;
            $klienti = ($row['klienti'] ?? '') !== '' ? $row['klienti'] : null;
            $komentet = ($row['komentet'] ?? '') !== '' ? $row['komentet'] : null;

            // Skip rows with no meaningful data (strict check — don't drop valid rows with "0" descriptions)
            if (($shpjegim === null) && $debia === null && $kredi === null) continue;

            // Auto-calculate bilanci if not provided (fallback from previous balance)
            if ($bilanci === null) {
                $d = $debia ?? 0;
                $k = $kredi ?? 0;
                $bilanci = round($prevBilanci + $k - $d, 2);
            }

            $stmt->execute([$data, $dataValutes, $ora, $shpjegim, $valuta, $debia, $kredi, $bilanci, $deftesa, $lloji, $klienti, $komentet]);
            $newId = (int)$db->lastInsertId();
            $inserted++;

            // Log to changelog
            $insertedData = ['data'=>$data, 'data_valutes'=>$dataValutes, 'ora'=>$ora, 'shpjegim'=>$shpjegim, 'valuta'=>$valuta, 'debia'=>$debia, 'kredi'=>$kredi, 'bilanci'=>$bilanci, 'deftesa'=>$deftesa, 'lloji'=>$lloji, 'klienti'=>$klienti, 'komentet'=>$komentet];
            $logStmt->execute([$table, $newId, json_encode($insertedData, JSON_UNESCAPED_UNICODE)]);

            // Track running balance for subsequent rows
            $prevBilanci = $bilanci;
        }

        // Full bilanci recalculation (handles backdated inserts)
        $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
        $running = 0;
        foreach ($all as $r) {
            $running = round($running + (float)$r['kredi'] + (float)$r['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
        }

    } elseif ($table === 'distribuimi') {
        $sql = "INSERT INTO distribuimi (klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, fatura_e_derguar, data_e_fletepageses, koment, litrat_total, litrat_e_konvertuara)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);

        foreach ($rows as $row) {
            $klienti = ($row['klienti'] ?? '') !== '' ? $row['klienti'] : null;
            $data = ($row['data'] ?? '') !== '' ? $row['data'] : null;
            $sasia = is_numeric($row['sasia'] ?? '') ? (float)$row['sasia'] : null;
            $boca_te_kthyera = is_numeric($row['boca_te_kthyera'] ?? '') ? (float)$row['boca_te_kthyera'] : null;
            $litra = is_numeric($row['litra'] ?? '') ? (float)$row['litra'] : null;
            $cmimi = is_numeric($row['cmimi'] ?? '') ? (float)$row['cmimi'] : null;
            $pagesa = is_numeric($row['pagesa'] ?? '') ? (float)$row['pagesa'] : null;
            $menyra_e_pageses = ($row['menyra_e_pageses'] ?? '') !== '' ? $row['menyra_e_pageses'] : null;
            $fatura_e_derguar = ($row['fatura_e_derguar'] ?? '') !== '' ? $row['fatura_e_derguar'] : null;
            $data_e_fletepageses = ($row['data_e_fletepageses'] ?? '') !== '' ? $row['data_e_fletepageses'] : null;
            $koment = ($row['koment'] ?? '') !== '' ? $row['koment'] : null;
            $litrat_total = is_numeric($row['litrat_total'] ?? '') ? (float)$row['litrat_total'] : null;
            $litrat_e_konvertuara = is_numeric($row['litrat_e_konvertuara'] ?? '') ? (float)$row['litrat_e_konvertuara'] : null;

            // Skip rows with no meaningful data
            if ($klienti === null && $sasia === null && $pagesa === null) continue;

            // Server-side formula enforcement (matches insert.php/update.php)
            $s = $sasia ?? 0;
            $l = $litra ?? 0;
            $c = $cmimi ?? 0;
            if ($litrat_total === null) $litrat_total = round($s * $l, 2);
            if ($litrat_e_konvertuara === null) $litrat_e_konvertuara = $l;
            if ($pagesa === null) $pagesa = round($s * $l * $c, 2);

            $stmt->execute([$klienti, $data, $sasia, $boca_te_kthyera, $litra, $cmimi, $pagesa, $menyra_e_pageses, $fatura_e_derguar, $data_e_fletepageses, $koment, $litrat_total, $litrat_e_konvertuara]);
            $newId = (int)$db->lastInsertId();
            $inserted++;

            // Log to changelog
            $insertedData = ['klienti'=>$klienti, 'data'=>$data, 'sasia'=>$sasia, 'boca_te_kthyera'=>$boca_te_kthyera, 'litra'=>$litra, 'cmimi'=>$cmimi, 'pagesa'=>$pagesa, 'menyra_e_pageses'=>$menyra_e_pageses, 'fatura_e_derguar'=>$fatura_e_derguar, 'data_e_fletepageses'=>$data_e_fletepageses, 'koment'=>$koment, 'litrat_total'=>$litrat_total, 'litrat_e_konvertuara'=>$litrat_e_konvertuara];
            $logStmt->execute([$table, $newId, json_encode($insertedData, JSON_UNESCAPED_UNICODE)]);
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'inserted' => $inserted, 'reload' => true,
                       'message' => "U shtuan {$inserted} rreshta me sukses"]);
} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
