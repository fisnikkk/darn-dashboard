<?php
/** Temporary debug: show parsed dates vs text for verification */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$db = getDB();

// Check notes where month is > March (could be wrong year if all created_at = March 2026)
$suspect = $db->query("
    SELECT id, data, SUBSTRING(teksti, 1, 100) as teksti_short, created_at
    FROM notes
    WHERE data IS NOT NULL AND CAST(data AS CHAR) != '0000-00-00'
    AND MONTH(data) > 3
    ORDER BY data DESC
    LIMIT 20
")->fetchAll();

// Mid range to spot-check
$mid = $db->query("
    SELECT id, data, SUBSTRING(teksti, 1, 100) as teksti_short
    FROM notes
    WHERE data IS NOT NULL AND CAST(data AS CHAR) != '0000-00-00'
    ORDER BY id ASC
    LIMIT 5 OFFSET 200
")->fetchAll();

// Unparsed count
$missing = (int)$db->query("SELECT COUNT(*) FROM notes WHERE data IS NULL OR CAST(data AS CHAR) = '0000-00-00'")->fetchColumn();
$total = (int)$db->query("SELECT COUNT(*) FROM notes")->fetchColumn();

echo json_encode(['suspect_months' => $suspect, 'mid_sample' => $mid, 'stats' => ['total' => $total, 'missing' => $missing, 'parsed' => $total - $missing]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
