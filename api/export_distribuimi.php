<?php
/**
 * Export all distribuimi rows as JSON
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();
    $rows = $db->query("SELECT * FROM distribuimi ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
