<?php
/**
 * Borxhet Notes API - Upsert per-client notes
 * Uses INSERT ON DUPLICATE KEY UPDATE since klienti is UNIQUE
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['klienti'], $input['field'], $input['value'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$klienti = strtolower(trim($input['klienti']));
$field = $input['field'];
$value = $input['value'];

$allowedFields = ['klient_bank_cash', 'kush_merr_borxhin', 'koment'];
if (!in_array($field, $allowedFields)) {
    echo json_encode(['success' => false, 'error' => 'Field not allowed']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO borxhet_notes (klienti, {$field})
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE {$field} = VALUES({$field})
    ");
    $stmt->execute([$klienti, $value]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
