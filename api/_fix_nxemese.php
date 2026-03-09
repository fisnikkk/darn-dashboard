<?php
/**
 * One-time migration: restore correct nxemese data
 * DELETE after use
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

// Simple auth token to prevent accidental runs
if (($_GET['token'] ?? '') !== 'fix_nxemese_2026') {
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

$data = json_decode(file_get_contents(__DIR__ . '/_nxemese_data.json'), true);
if (!$data || !is_array($data)) {
    echo json_encode(['error' => 'Could not read data file']);
    exit;
}

try {
    $db = getDB();
    $db->beginTransaction();

    // Clear corrupted data
    $db->exec('DELETE FROM nxemese');

    // Insert correct rows
    $stmt = $db->prepare("INSERT INTO nxemese (klienti, data, te_dhena, te_marra, lloji_i_nxemjes, koment) VALUES (?, ?, ?, ?, ?, ?)");
    $inserted = 0;
    foreach ($data as $row) {
        $stmt->execute([
            $row['klienti'],
            $row['data'],
            $row['te_dhena'] ?? 0,
            $row['te_marra'] ?? 0,
            $row['lloji_i_nxemjes'],
            $row['koment']
        ]);
        $inserted++;
    }

    $db->commit();

    // Verify
    $count = $db->query('SELECT COUNT(*) FROM nxemese')->fetchColumn();
    $sample = $db->query('SELECT id, klienti, data, te_dhena, te_marra FROM nxemese LIMIT 3')->fetchAll();

    echo json_encode([
        'success' => true,
        'inserted' => $inserted,
        'verified_count' => $count,
        'sample' => $sample
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
