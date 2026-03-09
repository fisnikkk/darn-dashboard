<?php
/**
 * Temporary: Revert Klienti='Rozafa' back to empty in gjendja_bankare
 * DELETE THIS FILE AFTER USE
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$db = getDB();

// Count affected rows first
$count = $db->query("SELECT COUNT(*) FROM gjendja_bankare WHERE klienti = 'Rozafa'")->fetchColumn();

if ($count == 0) {
    echo json_encode(['success' => true, 'message' => 'No rows with Rozafa found, nothing to revert']);
    exit;
}

// Revert to empty
$stmt = $db->prepare("UPDATE gjendja_bankare SET klienti = '' WHERE klienti = 'Rozafa'");
$stmt->execute();
$affected = $stmt->rowCount();

echo json_encode([
    'success' => true,
    'message' => "Reverted {$affected} rows from 'Rozafa' back to empty",
    'rows_affected' => $affected
]);
