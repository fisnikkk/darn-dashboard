<?php
/**
 * GoDaddy → Railway Sync Endpoint
 *
 * Reads new rows from GoDaddy's delivery_report table and inserts them
 * into Railway's distribuimi table. READ-ONLY from GoDaddy — never writes.
 *
 * Sync modes:
 *   - action=sync        → Run incremental sync (new rows only)
 *   - action=status      → Check connection status and last sync info
 *   - action=preview     → Preview what would be synced (dry run)
 *   - action=history     → Get sync history log
 *
 * Column mapping (delivery_report → distribuimi):
 *   Client           → klienti
 *   Date             → data
 *   DeliveredCylinders → sasia
 *   ReturnedCylinders → boca_te_kthyera
 *   Volume           → litra  (strip "L" suffix, parse float)
 *   PricePer1L       → cmimi
 *   TotalPrice       → pagesa
 *   PaymentMethod    → menyra_e_pageses  (CASH→CASH, BANK→BANK)
 *   Comment          → fatura_e_derguar
 *   ID               → godaddy_id  (unique tracking key)
 *
 * Computed on insert:
 *   litrat_total          = sasia × litra
 *   litrat_e_konvertuara  = litra (per-unit)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/godaddy.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? 'status';

try {
    $db = getDB(); // Railway DB
    $gd = getGoDaddyDB(); // GoDaddy DB (may be null)

    switch ($action) {
        case 'status':
            handleStatus($db, $gd);
            break;
        case 'preview':
            handlePreview($db, $gd, $input);
            break;
        case 'sync':
            handleSync($db, $gd, $input);
            break;
        case 'history':
            handleHistory($db);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ──────────────────────────────────────────────────────────
// STATUS: Check connection + counts
// ──────────────────────────────────────────────────────────
function handleStatus($db, $gd) {
    $result = [
        'success' => true,
        'railway_connected' => true,
        'godaddy_connected' => ($gd !== null),
        'godaddy_configured' => (GD_DB_PASS !== ''),
    ];

    // Railway distribuimi stats
    $result['railway_total'] = (int)$db->query("SELECT COUNT(*) FROM distribuimi")->fetchColumn();
    $result['railway_synced'] = (int)$db->query("SELECT COUNT(*) FROM distribuimi WHERE godaddy_id IS NOT NULL")->fetchColumn();

    // Last sync info
    $lastSync = $db->query("SELECT * FROM godaddy_sync_log ORDER BY synced_at DESC LIMIT 1")->fetch();
    $result['last_sync'] = $lastSync ?: null;

    if ($gd) {
        try {
            $result['godaddy_total'] = (int)$gd->query("SELECT COUNT(*) FROM delivery_report")->fetchColumn();
            // Count how many GoDaddy rows are NOT yet in Railway
            $existingIds = $db->query("SELECT godaddy_id FROM distribuimi WHERE godaddy_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            if ($existingIds) {
                $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                $stmt = $gd->prepare("SELECT COUNT(*) FROM delivery_report WHERE ID NOT IN ({$placeholders})");
                $stmt->execute($existingIds);
                $result['godaddy_pending'] = (int)$stmt->fetchColumn();
            } else {
                $result['godaddy_pending'] = $result['godaddy_total'];
            }
        } catch (PDOException $e) {
            $result['godaddy_error'] = $e->getMessage();
        }
    }

    echo json_encode($result);
}

// ──────────────────────────────────────────────────────────
// PREVIEW: Dry-run showing what would be synced
// ──────────────────────────────────────────────────────────
function handlePreview($db, $gd, $input) {
    if (!$gd) {
        echo json_encode(['success' => false, 'error' => 'GoDaddy nuk eshte e lidhur. Vendos kredencialet ne Railway.']);
        return;
    }

    $limit = min((int)($input['limit'] ?? 20), 100);

    // Get IDs already synced
    $existingIds = $db->query("SELECT godaddy_id FROM distribuimi WHERE godaddy_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

    // Fetch new rows from GoDaddy
    if ($existingIds) {
        $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
        $stmt = $gd->prepare("SELECT * FROM delivery_report WHERE ID NOT IN ({$placeholders}) ORDER BY ID ASC LIMIT {$limit}");
        $stmt->execute($existingIds);
    } else {
        $stmt = $gd->query("SELECT * FROM delivery_report ORDER BY ID ASC LIMIT {$limit}");
    }
    $gdRows = $stmt->fetchAll();

    // Map them to show what would be inserted
    $preview = [];
    foreach ($gdRows as $row) {
        $mapped = mapGoDaddyRow($row);
        $preview[] = [
            'godaddy_id' => $row['ID'],
            'klienti' => $mapped['klienti'],
            'data' => $mapped['data'],
            'sasia' => $mapped['sasia'],
            'boca_te_kthyera' => $mapped['boca_te_kthyera'],
            'litra' => $mapped['litra'],
            'cmimi' => $mapped['cmimi'],
            'pagesa' => $mapped['pagesa'],
            'menyra_e_pageses' => $mapped['menyra_e_pageses'],
            'fatura_e_derguar' => $mapped['fatura_e_derguar'],
        ];
    }

    // Get total pending count
    if ($existingIds) {
        $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
        $countStmt = $gd->prepare("SELECT COUNT(*) FROM delivery_report WHERE ID NOT IN ({$placeholders})");
        $countStmt->execute($existingIds);
        $totalPending = (int)$countStmt->fetchColumn();
    } else {
        $totalPending = (int)$gd->query("SELECT COUNT(*) FROM delivery_report")->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'total_pending' => $totalPending,
        'showing' => count($preview),
        'rows' => $preview,
    ]);
}

// ──────────────────────────────────────────────────────────
// SYNC: Actually insert new rows
// ──────────────────────────────────────────────────────────
function handleSync($db, $gd, $input) {
    if (!$gd) {
        echo json_encode(['success' => false, 'error' => 'GoDaddy nuk eshte e lidhur. Vendos kredencialet ne Railway.']);
        return;
    }

    $batchSize = min((int)($input['batch_size'] ?? 500), 2000);

    // Get IDs already synced
    $existingIds = $db->query("SELECT godaddy_id FROM distribuimi WHERE godaddy_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

    // Fetch new rows from GoDaddy
    if ($existingIds) {
        $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
        $stmt = $gd->prepare("SELECT * FROM delivery_report WHERE ID NOT IN ({$placeholders}) ORDER BY ID ASC LIMIT {$batchSize}");
        $stmt->execute($existingIds);
    } else {
        $stmt = $gd->query("SELECT * FROM delivery_report ORDER BY ID ASC LIMIT {$batchSize}");
    }
    $gdRows = $stmt->fetchAll();

    if (empty($gdRows)) {
        // Log it
        $db->prepare("INSERT INTO godaddy_sync_log (synced_at, rows_fetched, rows_inserted, rows_skipped, status) VALUES (NOW(), 0, 0, 0, 'no_new_rows')")
            ->execute();
        echo json_encode(['success' => true, 'message' => 'Asgje per te sinkronizuar — te gjitha rreshtat jane tashme ne dashboard.', 'inserted' => 0, 'skipped' => 0]);
        return;
    }

    $db->beginTransaction();
    $inserted = 0;
    $skipped = 0;
    $errors = [];

    $insertSQL = "INSERT INTO distribuimi (klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, fatura_e_derguar, litrat_total, litrat_e_konvertuara, godaddy_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $db->prepare($insertSQL);

    foreach ($gdRows as $row) {
        $mapped = mapGoDaddyRow($row);

        // Double-check: skip if this godaddy_id already exists (race condition safety)
        $exists = $db->prepare("SELECT COUNT(*) FROM distribuimi WHERE godaddy_id = ?");
        $exists->execute([$row['ID']]);
        if ($exists->fetchColumn() > 0) {
            $skipped++;
            continue;
        }

        try {
            $insertStmt->execute([
                $mapped['klienti'],
                $mapped['data'],
                $mapped['sasia'],
                $mapped['boca_te_kthyera'],
                $mapped['litra'],
                $mapped['cmimi'],
                $mapped['pagesa'],
                $mapped['menyra_e_pageses'],
                $mapped['fatura_e_derguar'],
                $mapped['litrat_total'],
                $mapped['litrat_e_konvertuara'],
                (int)$row['ID'],
            ]);
            $newId = $db->lastInsertId();

            // Log insert to changelog
            $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('insert', 'distribuimi', ?, 'godaddy_sync', NULL, ?)")
                ->execute([(int)$newId, json_encode($mapped, JSON_UNESCAPED_UNICODE)]);

            $inserted++;
        } catch (PDOException $e) {
            // Duplicate key or other error — skip this row
            $skipped++;
            $errors[] = "GoDaddy ID {$row['ID']}: " . $e->getMessage();
        }
    }

    $db->commit();

    // Log the sync
    $status = empty($errors) ? 'success' : 'partial';
    $errorMsg = empty($errors) ? null : implode("\n", array_slice($errors, 0, 10));
    $db->prepare("INSERT INTO godaddy_sync_log (synced_at, rows_fetched, rows_inserted, rows_skipped, status, error_message) VALUES (NOW(), ?, ?, ?, ?, ?)")
        ->execute([count($gdRows), $inserted, $skipped, $status, $errorMsg]);

    // Get remaining count
    $existingIds = $db->query("SELECT godaddy_id FROM distribuimi WHERE godaddy_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $remainingPlaceholders = implode(',', array_fill(0, count($existingIds), '?'));
    $remainingStmt = $gd->prepare("SELECT COUNT(*) FROM delivery_report WHERE ID NOT IN ({$remainingPlaceholders})");
    $remainingStmt->execute($existingIds);
    $remaining = (int)$remainingStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => "U sinkronizuan {$inserted} rreshta te reja" . ($skipped ? " ({$skipped} u anashkaluan)" : ''),
        'inserted' => $inserted,
        'skipped' => $skipped,
        'remaining' => $remaining,
        'errors' => $errors ? array_slice($errors, 0, 5) : null,
    ]);
}

// ──────────────────────────────────────────────────────────
// HISTORY: Sync log
// ──────────────────────────────────────────────────────────
function handleHistory($db) {
    $rows = $db->query("SELECT * FROM godaddy_sync_log ORDER BY synced_at DESC LIMIT 20")->fetchAll();
    echo json_encode(['success' => true, 'history' => $rows]);
}

// ──────────────────────────────────────────────────────────
// COLUMN MAPPING: delivery_report → distribuimi
// ──────────────────────────────────────────────────────────
function mapGoDaddyRow($row) {
    // Parse Volume: "120.0L" → 120.0, or just numeric
    $litra = 0;
    $volume = trim($row['Volume'] ?? '');
    if ($volume !== '') {
        $litra = (float)preg_replace('/[^0-9.\-]/', '', $volume);
    }

    $sasia = (float)($row['DeliveredCylinders'] ?? 0);
    $cmimi = (float)($row['PricePer1L'] ?? 0);

    // Map payment method
    $payment = strtoupper(trim($row['PaymentMethod'] ?? ''));
    $menyra = match ($payment) {
        'CASH' => 'CASH',
        'BANK' => 'BANK',
        'NO PAYMENT', 'NOPAYMENT' => 'NO PAYMENT',
        'DHURATE', 'GIFT' => 'DHURATE',
        default => $payment ?: 'CASH',
    };

    return [
        'klienti'              => trim($row['Client'] ?? ''),
        'data'                 => $row['Date'] ?? null,
        'sasia'                => $sasia,
        'boca_te_kthyera'      => (float)($row['ReturnedCylinders'] ?? 0),
        'litra'                => $litra,
        'cmimi'                => $cmimi,
        'pagesa'               => (float)($row['TotalPrice'] ?? 0),
        'menyra_e_pageses'     => $menyra,
        'fatura_e_derguar'     => trim($row['Comment'] ?? ''),
        'litrat_total'         => round($sasia * $litra, 2),
        'litrat_e_konvertuara' => $litra,
    ];
}
