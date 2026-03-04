<?php
/**
 * One-time import: Load borxhet notes from Excel JSON into borxhet_notes table.
 * POST to run the import. GET to preview.
 * Safe to run multiple times (uses INSERT ON DUPLICATE KEY UPDATE).
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$jsonPath = __DIR__ . '/../temp_excel_gjendja_e_borxheve.json';
if (!file_exists($jsonPath)) {
    echo json_encode(['error' => 'Excel JSON file not found']);
    exit;
}

$data = json_decode(file_get_contents($jsonPath), true);
if (!$data) {
    echo json_encode(['error' => 'Failed to parse JSON']);
    exit;
}

$db = getDB();

// Ensure table exists
try {
    $db->query("SELECT 1 FROM borxhet_notes LIMIT 1");
} catch (PDOException $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS borxhet_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        klienti VARCHAR(255) NOT NULL UNIQUE,
        klient_bank_cash VARCHAR(100) DEFAULT NULL,
        kush_merr_borxhin VARCHAR(255) DEFAULT NULL,
        koment TEXT DEFAULT NULL
    )");
}

$toImport = [];
foreach ($data as $row) {
    $klienti = strtolower(trim($row['Klienti'] ?? ''));
    $bc = trim($row['Klient me bank apo cash'] ?? '');
    $km = trim($row['Kush e merr borxhin'] ?? '');
    $ko = trim($row['Komment'] ?? '');
    if (!$klienti || (!$bc && !$km && !$ko)) continue;
    $toImport[] = compact('klienti', 'bc', 'km', 'ko');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['preview' => true, 'count' => count($toImport), 'sample' => array_slice($toImport, 0, 10)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// POST = actually import
$stmt = $db->prepare("
    INSERT INTO borxhet_notes (klienti, klient_bank_cash, kush_merr_borxhin, koment)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        klient_bank_cash = IF(VALUES(klient_bank_cash) != '', VALUES(klient_bank_cash), klient_bank_cash),
        kush_merr_borxhin = IF(VALUES(kush_merr_borxhin) != '', VALUES(kush_merr_borxhin), kush_merr_borxhin),
        koment = IF(VALUES(koment) != '', VALUES(koment), koment)
");

$checkStmt = $db->prepare("SELECT id FROM borxhet_notes WHERE klienti = ?");
$logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES (?, 'borxhet_notes', ?, 'import_excel', NULL, ?)");

$imported = 0;
foreach ($toImport as $r) {
    // Check if row exists before upsert
    $checkStmt->execute([$r['klienti']]);
    $existingId = $checkStmt->fetchColumn();
    $actionType = $existingId ? 'update' : 'insert';

    $stmt->execute([$r['klienti'], $r['bc'] ?: null, $r['km'] ?: null, $r['ko'] ?: null]);

    $rowId = $existingId ?: (int)$db->lastInsertId();
    $logStmt->execute([$actionType, (int)$rowId, json_encode(['klienti'=>$r['klienti'], 'klient_bank_cash'=>$r['bc'], 'kush_merr_borxhin'=>$r['km'], 'koment'=>$r['ko']], JSON_UNESCAPED_UNICODE)]);
    $imported++;
}

echo json_encode(['success' => true, 'imported' => $imported], JSON_UNESCAPED_UNICODE);
