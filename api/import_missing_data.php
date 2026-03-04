<?php
/**
 * One-time import: Fill missing data from Excel JSONs.
 * 1. Add 1 missing plini_depo row (Edorita, null date/kg)
 * 2. Fill shpenzimet invoice columns (data_e_fatures, shuma_fatures, lloji_fatures)
 * 3. Fill kontrata PDA column (sipas_skenimit_pda)
 * POST to run. GET to preview.
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$db = getDB();
$results = [];

// 1. Add missing plini_depo row
$exists = $db->query("SELECT COUNT(*) FROM plini_depo WHERE furnitori = 'Edorita' AND data IS NULL")->fetchColumn();
if (!$exists) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $db->exec("INSERT INTO plini_depo (furnitori, menyra_e_pageses, kg, data) VALUES ('Edorita', 'Pa fature', NULL, NULL)");
        $results['plini_depo'] = 'Inserted 1 missing row (Edorita)';
    } else {
        $results['plini_depo'] = 'Will insert 1 missing row (Edorita, null date/kg)';
    }
} else {
    $results['plini_depo'] = 'Row already exists, skipping';
}

// 2. Fill shpenzimet invoice columns from Excel
$jsonPath = __DIR__ . '/../temp_excel_shpenzimet.json';
if (file_exists($jsonPath)) {
    $data = json_decode(file_get_contents($jsonPath), true);
    $toUpdate = [];
    foreach ($data as $row) {
        $df = $row['data_e_fatures'] ?? null;
        $sf = $row['shuma_fatures'] ?? null;
        $lf = $row['lloji_fatures'] ?? null;
        if (!$df && !$sf && !$lf) continue;
        // Skip non-date values like "Cash", "Bank" in date field
        if ($df && !preg_match('/^\d{4}-\d{2}-\d{2}/', $df)) $df = null;
        // Skip non-numeric values in shuma_fatures
        if ($sf && !is_numeric($sf)) $sf = null;
        if (!$df && !$sf && !$lf) continue;
        // Match by date + shuma + arsyetimi
        $toUpdate[] = [
            'data' => $row['data_e_pageses'] ?? null,
            'shuma' => $row['shuma'] ?? null,
            'arsyetimi' => $row['arsyetimi'] ?? null,
            'data_e_fatures' => $df,
            'shuma_fatures' => $sf,
            'lloji_fatures' => $lf,
        ];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $toUpdate) {
        $updated = 0;
        $stmt = $db->prepare("UPDATE shpenzimet SET data_e_fatures = ?, shuma_fatures = ?, lloji_fatures = ? WHERE data_e_pageses = ? AND shuma = ? AND arsyetimi = ? LIMIT 1");
        foreach ($toUpdate as $u) {
            $stmt->execute([$u['data_e_fatures'], $u['shuma_fatures'], $u['lloji_fatures'], $u['data'], $u['shuma'], $u['arsyetimi']]);
            if ($stmt->rowCount() > 0) $updated++;
        }
        $results['shpenzimet'] = "Updated {$updated} of " . count($toUpdate) . " rows with invoice data";
    } else {
        $results['shpenzimet'] = count($toUpdate) . " rows have invoice data to fill";
    }
} else {
    $results['shpenzimet'] = 'Excel JSON not found';
}

// 3. Fill kontrata PDA column from Excel
$jsonPath = __DIR__ . '/../temp_excel_kontrata.json';
if (file_exists($jsonPath)) {
    $data = json_decode(file_get_contents($jsonPath), true);
    $toUpdate = [];
    foreach ($data as $row) {
        $pda = $row['Sipas skenimit me PDA (lpg cylinder info (responses)'] ?? ($row['sipas_skenimit_pda'] ?? null);
        if (!$pda) continue;
        $biznesi = $row['Biznesi'] ?? ($row['biznesi'] ?? null);
        if (!$biznesi) continue;
        $toUpdate[] = ['biznesi' => $biznesi, 'pda' => $pda];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $toUpdate) {
        $updated = 0;
        $stmt = $db->prepare("UPDATE kontrata SET sipas_skenimit_pda = ? WHERE biznesi = ? AND (sipas_skenimit_pda IS NULL OR sipas_skenimit_pda = '') LIMIT 1");
        foreach ($toUpdate as $u) {
            $stmt->execute([$u['pda'], $u['biznesi']]);
            if ($stmt->rowCount() > 0) $updated++;
        }
        $results['kontrata_pda'] = "Updated {$updated} of " . count($toUpdate) . " rows with PDA data";
    } else {
        $results['kontrata_pda'] = count($toUpdate) . " rows have PDA data to fill";
    }
} else {
    $results['kontrata_pda'] = 'Excel JSON not found';
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
