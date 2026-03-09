<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

$table = $_GET['table'] ?? '';
$allowed = ['distribuimi', 'shpenzimet', 'plini_depo', 'shitje_produkteve', 'gjendja_bankare'];
if (!in_array($table, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid table']);
    exit;
}

try {
    $db = getDB();
    $row = $db->query("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 1")->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'No rows found']);
        exit;
    }
    echo json_encode(['success' => true, 'row' => $row]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
