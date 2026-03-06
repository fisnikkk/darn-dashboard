<?php
ini_set('memory_limit', '512M');
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDB();
    $result = [];

    // 1. Fetch ALL columns of plini_depo row with id=2151
    $stmt = $pdo->prepare("SELECT * FROM plini_depo WHERE id = :id");
    $stmt->execute([':id' => 2151]);
    $result['row_2151'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Fetch ALL plini_depo rows for December 2024
    $stmt = $pdo->prepare("SELECT * FROM plini_depo WHERE data >= '2024-12-01' AND data <= '2024-12-31' ORDER BY data");
    $stmt->execute();
    $result['december_2024_rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result['december_2024_count'] = count($result['december_2024_rows']);

    // 3. Check changelog for any changes to plini_depo row id=2151 (discover schema first)
    $stmt = $pdo->query("DESCRIBE changelog");
    $changelogCols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $result['changelog_columns'] = $changelogCols;

    $stmt = $pdo->prepare("SELECT * FROM changelog WHERE table_name = 'plini_depo' AND row_id = :id");
    $stmt->execute([':id' => 2151]);
    $result['changelog_2151'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result['changelog_2151_count'] = count($result['changelog_2151']);

    // 4. Load oldest snapshot (id=3) and find plini_depo row with id=2151
    $stmt = $pdo->prepare("SELECT * FROM snapshots WHERE id = 3");
    $stmt->execute();
    $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($snapshot) {
        $result['snapshot_3_meta'] = [
            'id' => $snapshot['id'],
            'name' => $snapshot['name'],
            'created_at' => $snapshot['created_at'] ?? null,
        ];

        $snapshotData = json_decode($snapshot['data'], true);
        if ($snapshotData && isset($snapshotData['plini_depo'])) {
            $found = null;
            foreach ($snapshotData['plini_depo'] as $row) {
                if (isset($row['id']) && (int)$row['id'] === 2151) {
                    $found = $row;
                    break;
                }
            }
            $result['snapshot_3_row_2151'] = $found;

            // Also find any row with date 2024-12-16 for comparison
            $sameDate = [];
            foreach ($snapshotData['plini_depo'] as $row) {
                if (isset($row['data']) && substr($row['data'], 0, 10) === '2024-12-16') {
                    $sameDate[] = $row;
                }
            }
            $result['snapshot_3_date_2024_12_16_rows'] = $sameDate;
        } else {
            $result['snapshot_3_plini_depo'] = 'No plini_depo data in snapshot or failed to decode';
        }
    } else {
        $result['snapshot_3'] = 'Snapshot id=3 not found';
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], JSON_PRETTY_PRINT);
}
