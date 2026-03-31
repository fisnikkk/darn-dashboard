<?php
/**
 * Borxhet Notes API - Upsert per-client notes
 * Uses INSERT ON DUPLICATE KEY UPDATE since klienti is UNIQUE
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['klienti'], $input['field'], $input['value'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$klienti = strtolower(trim($input['klienti']));
$field = $input['field'];
$value = trim($input['value']);

$allowedFields = ['klient_bank_cash', 'kush_merr_borxhin', 'koment'];
if (!in_array($field, $allowedFields)) {
    echo json_encode(['success' => false, 'error' => 'Field not allowed']);
    exit;
}

try {
    $db = getDB();

    // Get old value before upsert for changelog
    $oldRow = $db->prepare("SELECT id, {$field} FROM borxhet_notes WHERE klienti = ?");
    $oldRow->execute([$klienti]);
    $existing = $oldRow->fetch();
    $oldValue = $existing ? $existing[$field] : null;
    $isUpdate = (bool)$existing;

    $stmt = $db->prepare("
        INSERT INTO borxhet_notes (klienti, {$field})
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE {$field} = VALUES({$field})
    ");
    $stmt->execute([$klienti, $value]);

    // Log to changelog
    $rowId = $existing ? (int)$existing['id'] : (int)$db->lastInsertId();
    if ($isUpdate) {
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, username) VALUES ('update', 'borxhet_notes', ?, ?, ?, ?, ?)")
            ->execute([$rowId, $field, $oldValue, $value, getCurrentUser()]);
    } else {
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, username) VALUES ('insert', 'borxhet_notes', ?, NULL, NULL, ?, ?)")
            ->execute([$rowId, json_encode(['klienti' => $klienti, $field => $value], JSON_UNESCAPED_UNICODE), getCurrentUser()]);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
