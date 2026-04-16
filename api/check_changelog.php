<?php
/**
 * Temporary diagnostic endpoint — checks changelog for gjendja_bankare events
 * that could have reset e_kontrolluar values. DELETE THIS FILE after use.
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Simple auth check
if (($_GET['key'] ?? '') !== 'darn-android-2026-secure-key') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();

// 1. Check for excel imports on gjendja_bankare
$imports = $db->query("
    SELECT action_type, table_name, field_name, old_value, new_value, created_at
    FROM changelog
    WHERE table_name = 'gjendja_bankare'
      AND action_type IN ('excel_import', 'import', 'bulk_insert', 'delete', 'restore', 'snapshot_restore')
    ORDER BY created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Check for revert operations on gjendja_bankare
$reverts = $db->query("
    SELECT action_type, table_name, row_id, field_name, old_value, new_value, created_at
    FROM changelog
    WHERE table_name = 'gjendja_bankare'
      AND action_type = 'revert'
    ORDER BY created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Check for any e_kontrolluar changes (toggles and bulk)
$toggles = $db->query("
    SELECT action_type, row_id, old_value, new_value, created_at
    FROM changelog
    WHERE table_name = 'gjendja_bankare'
      AND field_name = 'e_kontrolluar'
    ORDER BY created_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Check for bulk deletes (like from excel import replace mode)
$deletes = $db->query("
    SELECT action_type, table_name, field_name, COUNT(*) as cnt, MAX(created_at) as last_at
    FROM changelog
    WHERE table_name = 'gjendja_bankare'
    GROUP BY action_type, table_name, field_name
    ORDER BY last_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// 5. Check recent gjendja_bankare activity (last 7 days)
$recent = $db->query("
    SELECT action_type, field_name, COUNT(*) as cnt, MIN(created_at) as first, MAX(created_at) as last
    FROM changelog
    WHERE table_name = 'gjendja_bankare'
      AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY action_type, field_name
    ORDER BY last DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'imports_restores_deletes' => $imports,
    'reverts' => $reverts,
    'e_kontrolluar_changes' => $toggles,
    'action_summary' => $deletes,
    'last_7_days' => $recent,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
