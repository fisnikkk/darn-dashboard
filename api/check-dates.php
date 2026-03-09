<?php
/** Temporary debug: show a few parsed dates vs text for verification */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$rows = $db->query("
    SELECT id, data, SUBSTRING(teksti, 1, 80) as teksti_short
    FROM notes
    WHERE data IS NOT NULL AND CAST(data AS CHAR) != '0000-00-00'
    ORDER BY id DESC
    LIMIT 15
")->fetchAll();

$missing = $db->query("
    SELECT id, SUBSTRING(teksti, 1, 80) as teksti_short
    FROM notes
    WHERE data IS NULL OR CAST(data AS CHAR) = '0000-00-00'
    LIMIT 5
")->fetchAll();

echo json_encode(['parsed' => $rows, 'unparsed' => $missing], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
