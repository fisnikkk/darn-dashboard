<?php
/**
 * One-time: Import filesystem JSON snapshots into the database.
 * Call this once after deploying the new DB-based snapshot system.
 * Safe to call multiple times (skips already-imported snapshots).
 */
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

try {
    $db = getDB();
    $snapshotDir = __DIR__ . '/../snapshots';
    $imported = 0;
    $skipped = 0;

    if (is_dir($snapshotDir)) {
        $files = glob($snapshotDir . '/*.json');
        foreach ($files as $f) {
            $name = basename($f, '.json');
            $check = $db->prepare("SELECT COUNT(*) FROM snapshots WHERE name = ?");
            $check->execute([$name]);
            if ((int)$check->fetchColumn() > 0) {
                $skipped++;
                continue;
            }
            $jsonData = file_get_contents($f);
            $data = json_decode($jsonData, true);
            $createdAt = $data['created_at'] ?? date('Y-m-d H:i:s');
            $sizeBytes = strlen($jsonData);
            $stmt = $db->prepare("INSERT INTO snapshots (name, created_at, snapshot_data, size_bytes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $createdAt, $jsonData, $sizeBytes]);
            $imported++;
        }
    }

    echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
