<?php
/**
 * Temporary: Migrate fatura_e_derguar data from local export to production
 * Matches by klienti + data + sasia + boca_kth + pagesa + menyra
 * DELETE THIS FILE AFTER USE
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$db = getDB();
$data = json_decode(file_get_contents(__DIR__ . '/_fatura_data.json'), true);
if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Cannot read data file']);
    exit;
}

$updated = 0;
$skipped = 0;
$notFound = 0;

$stmt = $db->prepare("
    UPDATE distribuimi
    SET fatura_e_derguar = ?
    WHERE LOWER(TRIM(klienti)) = LOWER(TRIM(?))
      AND data = ?
      AND CAST(sasia AS SIGNED) = ?
      AND CAST(boca_te_kthyera AS SIGNED) = ?
      AND ABS(pagesa - ?) < 0.01
      AND LOWER(TRIM(menyra_e_pageses)) = LOWER(TRIM(?))
      AND (fatura_e_derguar IS NULL OR fatura_e_derguar = '')
    LIMIT 1
");

foreach ($data as $row) {
    $stmt->execute([
        $row['fatura_e_derguar'],
        $row['klienti'],
        $row['data'],
        (int)$row['sasia'],
        (int)$row['boca_kth'],
        $row['pagesa'],
        $row['menyra_e_pageses']
    ]);
    if ($stmt->rowCount() > 0) {
        $updated++;
    } else {
        $notFound++;
    }
}

// Verify
$finalCount = $db->query("SELECT COUNT(*) FROM distribuimi WHERE fatura_e_derguar IS NOT NULL AND fatura_e_derguar != ''")->fetchColumn();

echo json_encode([
    'success' => true,
    'total_source' => count($data),
    'updated' => $updated,
    'not_found_or_duplicate' => $notFound,
    'final_non_empty_count' => $finalCount
], JSON_PRETTY_PRINT);
