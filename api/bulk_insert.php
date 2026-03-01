<?php
/**
 * Bulk insert API for gjendja_bankare (bank statement paste)
 * Accepts JSON array of rows
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$table = $input['table'] ?? '';
$rows = $input['rows'] ?? [];

if ($table !== 'gjendja_bankare') {
    echo json_encode(['success' => false, 'error' => 'Only gjendja_bankare supported']);
    exit;
}

if (empty($rows)) {
    echo json_encode(['success' => false, 'error' => 'No rows provided']);
    exit;
}

$allowedCols = ['data','data_valutes','ora','shpjegim','valuta','debia','kredi','bilanci','deftesa','lloji'];

try {
    $db = getDB();
    $db->beginTransaction();
    $inserted = 0;

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

    $db->commit();
    echo json_encode(['success' => true, 'inserted' => $inserted, 'reload' => true,
                       'message' => "U shtuan {$inserted} rreshta me sukses"]);
} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
