<?php
/**
 * API: Get individual debt transactions for a specific client
 * Used by borxhet page click-to-expand feature
 * GET ?klienti=clientname&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

$klienti = $_GET['klienti'] ?? '';
if (!$klienti) {
    echo json_encode(['success' => false, 'error' => 'Missing klienti']);
    exit;
}

$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';

try {
    $db = getDB();

    // Build date conditions to match the borxhet main query
    $where = "LOWER(TRIM(klienti)) = LOWER(TRIM(?)) AND LOWER(TRIM(menyra_e_pageses)) = 'bank'";
    $params = [$klienti];

    if ($dateFrom) {
        $where .= ' AND data >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $where .= ' AND data <= ?';
        $params[] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT data, sasia, litra, cmimi, pagesa, fatura_e_derguar AS koment
        FROM distribuimi
        WHERE {$where}
        ORDER BY data DESC, id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode(['success' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
