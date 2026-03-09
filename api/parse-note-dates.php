<?php
/**
 * Parse Albanian dates from note text and populate the data column.
 * Patterns handled:
 *   "3 mars 11727.64" => March 3 (year from context)
 *   "28 shkurt 9803.64" => February 28
 *   "30 prill 2025 19988.47" => April 30, 2025 (explicit year)
 *   "15 janar" => January 15
 *
 * Year priority:
 *   1. Explicit year in text after month name (e.g., "prill 2025")
 *   2. created_at year, adjusted to avoid future dates
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$db = getDB();

// Re-parse mode: if ?reparse=1, clear all parsed dates and redo everything
$reparse = isset($_GET['reparse']) && $_GET['reparse'] === '1';
if ($reparse) {
    $db->exec("UPDATE notes SET data = NULL");
}

// Albanian month names => month numbers
$months = [
    'janar' => 1, 'shkurt' => 2, 'mars' => 3, 'prill' => 4,
    'maj' => 5, 'qershor' => 6, 'korrik' => 7, 'gusht' => 8,
    'shtator' => 9, 'tetor' => 10, 'nëntor' => 11, 'nentor' => 11,
    'dhjetor' => 12
];

$monthPattern = implode('|', array_keys($months));

// Match: <day> <albanian_month> [optional_year]
// Captures: (1) day, (2) month name, (3) optional 4-digit year after month
$pattern = '/\b(\d{1,2})\s+(' . $monthPattern . ')(?:\s+(20[2-9]\d))?\b/iu';

$today = new DateTime();

// Only process notes with no date set
$rows = $db->query("
    SELECT id, teksti, created_at
    FROM notes
    WHERE data IS NULL OR CAST(data AS CHAR) = '' OR CAST(data AS CHAR) = '0000-00-00'
")->fetchAll();

$updated = 0;
$total = count($rows);

foreach ($rows as $row) {
    $text = $row['teksti'] ?? '';
    if (!$text) continue;

    if (preg_match($pattern, $text, $m)) {
        $day = (int)$m[1];
        $monthName = mb_strtolower($m[2]);
        $month = $months[$monthName] ?? null;

        if (!$month || $day < 1 || $day > 31) continue;

        // 1. Check if text contains explicit year (e.g., "prill 2025")
        if (!empty($m[3])) {
            $year = (int)$m[3];
        } else {
            // 2. Infer year from created_at
            $year = (int)date('Y');
            if ($row['created_at']) {
                $createdYear = (int)date('Y', strtotime($row['created_at']));
                $year = $createdYear;
            }

            // 3. If resulting date would be in the future, go back 1 year
            $candidateDate = sprintf('%04d-%02d-%02d', $year, $month, min($day, 28));
            if (new DateTime($candidateDate) > $today) {
                $year--;
            }
        }

        // Validate the date — clamp day if needed
        if (!checkdate($month, $day, $year)) {
            $maxDay = (int)(new DateTime("$year-$month-01"))->format('t');
            $day = min($day, $maxDay);
        }

        if (checkdate($month, $day, $year)) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $stmt = $db->prepare("UPDATE notes SET data = ? WHERE id = ?");
            $stmt->execute([$date, $row['id']]);
            $updated++;
        }
    }
}

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'total_checked' => $total,
    'message' => "U përditësuan {$updated} nga {$total} shënime pa datë."
]);
