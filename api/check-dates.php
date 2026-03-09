<?php
/** Temporary debug: show parsed dates vs text for verification */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$db = getDB();

// Recent notes
$recent = $db->query("
    SELECT id, data, SUBSTRING(teksti, 1, 80) as teksti_short, created_at
    FROM notes
    WHERE data IS NOT NULL AND CAST(data AS CHAR) != '0000-00-00'
    ORDER BY id DESC
    LIMIT 10
")->fetchAll();

// Oldest notes with dates
$oldest = $db->query("
    SELECT id, data, SUBSTRING(teksti, 1, 80) as teksti_short, created_at
    FROM notes
    WHERE data IS NOT NULL AND CAST(data AS CHAR) != '0000-00-00'
    ORDER BY id ASC
    LIMIT 10
")->fetchAll();

// Unparsed
$missing = $db->query("
    SELECT id, SUBSTRING(teksti, 1, 80) as teksti_short
    FROM notes
    WHERE data IS NULL OR CAST(data AS CHAR) = '0000-00-00'
    LIMIT 10
")->fetchAll();

echo json_encode(['recent' => $recent, 'oldest' => $oldest, 'unparsed' => $missing], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
