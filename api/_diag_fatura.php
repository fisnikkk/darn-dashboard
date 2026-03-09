<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');
$db = getDB();
$total = $db->query('SELECT COUNT(*) FROM distribuimi')->fetchColumn();
$nonEmpty = $db->query("SELECT COUNT(*) FROM distribuimi WHERE fatura_e_derguar IS NOT NULL AND fatura_e_derguar != ''")->fetchColumn();
$komentNonEmpty = $db->query("SELECT COUNT(*) FROM distribuimi WHERE koment IS NOT NULL AND koment != ''")->fetchColumn();
$samples = $db->query("SELECT id, klienti, fatura_e_derguar FROM distribuimi WHERE fatura_e_derguar IS NOT NULL AND fatura_e_derguar != '' LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode([
    'total' => $total,
    'fatura_e_derguar_non_empty' => $nonEmpty,
    'koment_non_empty' => $komentNonEmpty,
    'samples' => $samples
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
