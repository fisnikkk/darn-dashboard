<?php
/**
 * Find duplicate rows in distribuimi (same klienti + data).
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    $dupes = $db->query("
        SELECT d.id, d.klienti, d.data, d.sasia, d.litra, d.cmimi, d.pagesa,
               d.menyra_e_pageses, d.boca_te_kthyera, d.litrat_total
        FROM distribuimi d
        INNER JOIN (
            SELECT LOWER(TRIM(klienti)) as kl, data, sasia, pagesa, COUNT(*) as cnt
            FROM distribuimi
            GROUP BY LOWER(TRIM(klienti)), data, sasia, pagesa
            HAVING cnt > 1
        ) dups ON LOWER(TRIM(d.klienti)) = dups.kl AND d.data = dups.data
              AND d.sasia = dups.sasia AND d.pagesa = dups.pagesa
        ORDER BY d.klienti, d.data, d.id
    ")->fetchAll();

    // Group by client+date for readability
    $grouped = [];
    foreach ($dupes as $r) {
        $key = strtolower(trim($r['klienti'])) . '|' . $r['data'];
        $grouped[$key][] = $r;
    }

    echo json_encode([
        'total_duplicate_groups' => count($grouped),
        'total_duplicate_rows' => count($dupes),
        'groups' => $grouped
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
