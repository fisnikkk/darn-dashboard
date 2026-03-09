<?php
/**
 * Temporary: Export distribuimi count and max ID for backup verification
 * DELETE THIS FILE AFTER USE
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');
$db = getDB();
$count = $db->query('SELECT COUNT(*) FROM distribuimi')->fetchColumn();
$maxId = $db->query('SELECT MAX(id) FROM distribuimi')->fetchColumn();
$minId = $db->query('SELECT MIN(id) FROM distribuimi')->fetchColumn();
echo json_encode([
    'count' => (int)$count,
    'max_id' => (int)$maxId,
    'min_id' => (int)$minId,
    'timestamp' => date('Y-m-d H:i:s')
]);
