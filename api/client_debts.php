<?php
/**
 * API: Get individual debt transactions for a specific client
 * Used by borxhet page click-to-expand feature
 * GET ?klienti=clientname
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$klienti = $_GET['klienti'] ?? '';
if (!$klienti) {
    echo json_encode(['success' => false, 'error' => 'Missing klienti']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT data, sasia, litra, cmimi, pagesa, menyra_e_pageses, koment
        FROM distribuimi
        WHERE LOWER(TRIM(klienti)) = LOWER(TRIM(?))
        ORDER BY data DESC, id DESC
    ");
    $stmt->execute([$klienti]);
    $rows = $stmt->fetchAll();

    echo json_encode(['success' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
