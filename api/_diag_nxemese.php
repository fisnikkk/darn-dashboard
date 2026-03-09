<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');
$db = getDB();

$total = $db->query('SELECT COUNT(*) FROM nxemese')->fetchColumn();
$cols = $db->query('DESCRIBE nxemese')->fetchAll(PDO::FETCH_COLUMN, 0);

// Sample rows
$sample = $db->query('SELECT * FROM nxemese ORDER BY id DESC LIMIT 5')->fetchAll();

// Check for suspicious data
$maxMarra = $db->query('SELECT MAX(te_marra) FROM nxemese')->fetchColumn();
$minStok = null;
try {
    $minStok = $db->query('SELECT MIN(ne_stok) FROM nxemese')->fetchColumn();
} catch (Exception $e) {
    $minStok = 'column_not_exists';
}

// Distinct klienti count
$clientCount = $db->query("SELECT COUNT(DISTINCT klienti) FROM nxemese WHERE klienti IS NOT NULL AND TRIM(klienti) != ''")->fetchColumn();
$emptyClients = $db->query("SELECT COUNT(*) FROM nxemese WHERE klienti IS NULL OR TRIM(klienti) = ''")->fetchColumn();

// Sum check
$sums = $db->query("SELECT SUM(te_dhena) as sum_dhena, SUM(te_marra) as sum_marra, AVG(te_dhena) as avg_dhena, AVG(te_marra) as avg_marra FROM nxemese")->fetch();

echo json_encode([
    'total_rows' => $total,
    'columns' => $cols,
    'distinct_clients' => $clientCount,
    'empty_clients' => $emptyClients,
    'max_te_marra' => $maxMarra,
    'min_ne_stok' => $minStok,
    'sums' => $sums,
    'sample_rows' => $sample
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
