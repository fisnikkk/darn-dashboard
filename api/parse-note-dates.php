<?php
/**
 * Parse Albanian dates from note text and populate the data column.
 * Patterns handled:
 *   "3 mars 11727.64" => March 3
 *   "28 shkurt 9803.64" => February 28
 *   "15 janar" => January 15
 * Year is inferred from created_at timestamp.
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$db = getDB();

// Albanian month names => month numbers
$months = [
    'janar' => 1, 'shkurt' => 2, 'mars' => 3, 'prill' => 4,
    'maj' => 5, 'qershor' => 6, 'korrik' => 7, 'gusht' => 8,
    'shtator' => 9, 'tetor' => 10, 'nëntor' => 11, 'nentor' => 11,
    'dhjetor' => 12
];

$monthPattern = implode('|', array_keys($months));

// Match: <day> <albanian_month> (at word boundary)
$pattern = '/\b(\d{1,2})\s+(' . $monthPattern . ')\b/iu';

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

        // Determine year from created_at, fallback to current year
        $year = date('Y');
        if ($row['created_at']) {
            $createdYear = (int)date('Y', strtotime($row['created_at']));
            $createdMonth = (int)date('n', strtotime($row['created_at']));
            $year = $createdYear;

            // If note mentions a month later than created_at month,
            // it likely refers to previous year (e.g., created in Jan, note says "dhjetor")
            if ($month > $createdMonth + 1) {
                $year = $createdYear - 1;
            }
        }

        // Validate the date
        if ($day > 28) {
            // For months with fewer days, clamp
            $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            if ($day > $maxDay) $day = $maxDay;
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
