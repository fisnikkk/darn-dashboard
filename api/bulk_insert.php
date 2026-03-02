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

    if ($table === 'gjendja_bankare') {
        $sql = "INSERT INTO gjendja_bankare (data, data_valutes, ora, shpjegim, valuta, debia, kredi, bilanci, deftesa, lloji)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
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

            // Skip rows with no meaningful data (strict check — don't drop valid rows with "0" descriptions)
            if (($shpjegim === null) && $debia === null && $kredi === null) continue;

            // Auto-calculate bilanci if not provided (fallback from previous balance)
            if ($bilanci === null) {
                $d = $debia ?? 0;
                $k = $kredi ?? 0;
                $bilanci = round($prevBilanci + $k - $d, 2);
            }

            $stmt->execute([$data, $dataValutes, $ora, $shpjegim, $valuta, $debia, $kredi, $bilanci, $deftesa, $lloji]);
            $inserted++;

            // Track running balance for subsequent rows
            $prevBilanci = $bilanci;
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

            $stmt->execute([$klienti, $data, $sasia, $boca_te_kthyera, $litra, $cmimi, $pagesa, $menyra_e_pageses, $fatura_e_derguar, $data_e_fletepageses, $koment, $litrat_total, $litrat_e_konvertuara]);
            $inserted++;
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'inserted' => $inserted, 'reload' => true,
                       'message' => "U shtuan {$inserted} rreshta me sukses"]);
} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
