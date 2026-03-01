<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$table = $input['table'] ?? '';
$id = (int)($input['id'] ?? 0);

$allowed = ['distribuimi','shpenzimet','plini_depo','shitje_produkteve',
            'kontrata','gjendja_bankare','nxemese','klientet',
            'stoku_zyrtar','depo'];

if (!in_array($table, $allowed) || !$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $db = getDB();
    $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
