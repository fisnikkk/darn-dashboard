<?php
/**
 * Parse dates from note text and populate the data column.
 * Patterns handled:
 *   "3 mars 11727.64"          => 2025-03-03 (Albanian month name)
 *   "30 prill 2025 19988.47"   => 2025-04-30 (with explicit year)
 *   "30.03.2022"               => 2022-03-30 (DD.MM.YYYY)
 *   "02.09.2022"               => 2022-09-02 (DD.MM.YYYY)
 *   "11/09/2022"               => 2022-09-11 (DD/MM/YYYY)
 *   "27/09/2022"               => 2022-09-27 (DD/MM/YYYY)
 *
 * Year priority for Albanian month patterns:
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

// Pattern 1: <day> <albanian_month> [optional_year]
$patternAlb = '/\b(\d{1,2})\s+(' . $monthPattern . ')(?:\s+(20[2-9]\d))?\b/iu';

// Pattern 2: DD.MM.YYYY or DD/MM/YYYY (with optional spaces around separators)
$patternNumeric = '/\b(\d{1,2})[.\/]\s*(\d{1,2})[.\/]\s*(\d{4})\b/';

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

    $date = null;

    // Try Albanian month pattern first
    if (preg_match($patternAlb, $text, $m)) {
        $day = (int)$m[1];
        $monthName = mb_strtolower($m[2]);
        $month = $months[$monthName] ?? null;

        if ($month && $day >= 1 && $day <= 31) {
            // Check if text contains explicit year (e.g., "prill 2025")
            if (!empty($m[3])) {
                $year = (int)$m[3];
            } else {
                // Infer year from created_at
                $year = (int)date('Y');
                if ($row['created_at']) {
                    $createdYear = (int)date('Y', strtotime($row['created_at']));
                    $year = $createdYear;
                }

                // If resulting date would be in the future, go back 1 year
                $candidateDate = sprintf('%04d-%02d-%02d', $year, $month, min($day, 28));
                if (new DateTime($candidateDate) > $today) {
                    $year--;
                }
            }

            // Clamp day if needed
            if (!checkdate($month, $day, $year)) {
                $maxDay = (int)(new DateTime("$year-$month-01"))->format('t');
                $day = min($day, $maxDay);
            }

            if (checkdate($month, $day, $year)) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
    }

    // Try numeric date pattern (DD.MM.YYYY or DD/MM/YYYY)
    if (!$date && preg_match($patternNumeric, $text, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];

        // Clamp day if needed
        if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && $year >= 2020 && $year <= 2030) {
            if (!checkdate($month, $day, $year)) {
                $maxDay = (int)(new DateTime("$year-$month-01"))->format('t');
                $day = min($day, $maxDay);
            }
            if (checkdate($month, $day, $year)) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
    }

    if ($date) {
        $stmt = $db->prepare("UPDATE notes SET data = ? WHERE id = ?");
        $stmt->execute([$date, $row['id']]);
        $updated++;
    }
}

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'total_checked' => $total,
    'message' => "U përditësuan {$updated} nga {$total} shënime pa datë."
]);
