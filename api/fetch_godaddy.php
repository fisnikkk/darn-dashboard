<?php
/**
 * Fetch from GoDaddy's delivery_report by date range (via HTTP proxy)
 *
 * Actions:
 *   action=preview  → Show what would be imported (dry run)
 *   action=import   → Actually insert into distribuimi
 *   action=status   → Check GoDaddy connection
 *   action=history  → List past GoDaddy imports (batches)
 *   action=undo     → Undo an entire import batch
 *
 * Required params for preview/import:
 *   date_from  (YYYY-MM-DD)
 *   date_to    (YYYY-MM-DD)
 *
 * Required params for undo:
 *   batch_id
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/godaddy.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? 'status';

try {
    $db = getDB();

    switch ($action) {
        case 'status':
            handleStatus();
            break;
        case 'preview':
            handlePreview($db, $input);
            break;
        case 'import':
            handleImport($db, $input);
            break;
        case 'history':
            handleHistory($db);
            break;
        case 'undo':
            handleUndo($db, $input);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Check if GoDaddy connection works
 */
function handleStatus() {
    $result = callGoDaddyAPI(['action' => 'ping']);

    if ($result === null) {
        echo json_encode(['success' => false, 'connected' => false, 'reason' => 'Nuk mund te lidhem me GoDaddy. Kontrollo lidhjen.']);
        return;
    }
    if (!($result['success'] ?? false)) {
        echo json_encode(['success' => false, 'connected' => false, 'reason' => $result['error'] ?? 'Pergjigje e pasakte nga GoDaddy.']);
        return;
    }

    echo json_encode(['success' => true, 'connected' => true, 'total_rows' => $result['total_rows'] ?? 0]);
}

/**
 * Fetch rows from GoDaddy via HTTP proxy
 */
function fetchGoDaddyRows($dateFrom, $dateTo) {
    $result = callGoDaddyAPI([
        'action' => 'fetch',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);

    if ($result === null) {
        throw new Exception('Nuk mund te lidhem me GoDaddy.');
    }
    if (!($result['success'] ?? false)) {
        throw new Exception($result['error'] ?? 'Gabim nga GoDaddy.');
    }

    return $result['rows'] ?? [];
}

/**
 * Preview: fetch rows from GoDaddy for the date range, show what would be imported
 */
function handlePreview($db, $input) {
    $dateFrom = $input['date_from'] ?? '';
    $dateTo = $input['date_to'] ?? '';
    if (!$dateFrom || !$dateTo) {
        echo json_encode(['success' => false, 'error' => 'Zgjidh datat (nga - deri).']);
        return;
    }

    $gdRows = fetchGoDaddyRows($dateFrom, $dateTo);

    // Check which ones already exist in distribuimi (by GoDaddy's unique row ID)
    // For the fallback (Excel rows without godaddy_id), track how many we've "virtually claimed"
    // so that two identical GoDaddy rows don't both match against one Excel row
    $mapped = [];
    $duplicates = 0;
    $fallbackClaimed = []; // key => count of Excel rows already "used" by earlier GoDaddy rows in this batch
    foreach ($gdRows as $row) {
        $m = mapRow($row);
        $gdId = (int)($row['ID'] ?? 0);

        // Check for duplicate: first by godaddy_id (exact match)
        $exists = false;
        if ($gdId > 0) {
            $dup = $db->prepare("SELECT COUNT(*) FROM distribuimi WHERE godaddy_id = ?");
            $dup->execute([$gdId]);
            $exists = (int)$dup->fetchColumn() > 0;
        }
        if (!$exists) {
            // Fallback: compare count of unclaimed Excel rows vs GoDaddy rows already matched in this batch
            $fallbackKey = $m['klienti'] . '|' . $m['data'] . '|' . $m['sasia'] . '|' . $m['pagesa'];
            $dup2 = $db->prepare("SELECT COUNT(*) FROM distribuimi WHERE godaddy_id IS NULL AND klienti = ? AND data = ? AND sasia = ? AND pagesa = ?");
            $dup2->execute([$m['klienti'], $m['data'], $m['sasia'], $m['pagesa']]);
            $excelCount = (int)$dup2->fetchColumn();
            $alreadyClaimed = $fallbackClaimed[$fallbackKey] ?? 0;
            if ($alreadyClaimed < $excelCount) {
                $exists = true;
                $fallbackClaimed[$fallbackKey] = $alreadyClaimed + 1;
            }
        }

        $m['_duplicate'] = $exists;
        $m['_godaddy_id'] = $gdId;
        if ($exists) $duplicates++;
        $mapped[] = $m;
    }

    echo json_encode([
        'success' => true,
        'total_found' => count($mapped),
        'duplicates' => $duplicates,
        'new_rows' => count($mapped) - $duplicates,
        'rows' => $mapped,
    ]);
}

/**
 * Import: fetch rows from GoDaddy and insert new ones into distribuimi
 */
function handleImport($db, $input) {
    $dateFrom = $input['date_from'] ?? '';
    $dateTo = $input['date_to'] ?? '';
    if (!$dateFrom || !$dateTo) {
        echo json_encode(['success' => false, 'error' => 'Zgjidh datat (nga - deri).']);
        return;
    }

    $gdRows = fetchGoDaddyRows($dateFrom, $dateTo);

    if (empty($gdRows)) {
        echo json_encode(['success' => true, 'message' => 'Asgje nuk u gjet per kete periudhe.', 'inserted' => 0, 'skipped' => 0]);
        return;
    }

    // Generate batch ID: gd_YYYYMMDD_HHMMSS
    $batchId = 'gd_' . date('Ymd_His');

    $db->beginTransaction();
    $inserted = 0;
    $skipped = 0;

    $insertSQL = "INSERT INTO distribuimi (klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, fatura_e_derguar, litrat_total, litrat_e_konvertuara, isCylinder, godaddy_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $db->prepare($insertSQL);

    foreach ($gdRows as $row) {
        $m = mapRow($row);
        $gdId = (int)($row['ID'] ?? 0);

        // Skip duplicates: first by godaddy_id, then fallback for Excel-imported rows
        $isDup = false;
        if ($gdId > 0) {
            $dup = $db->prepare("SELECT COUNT(*) FROM distribuimi WHERE godaddy_id = ?");
            $dup->execute([$gdId]);
            $isDup = (int)$dup->fetchColumn() > 0;
        }
        if (!$isDup && $gdId > 0) {
            // Fallback: find ONE Excel-imported row (godaddy_id IS NULL) with matching fields
            // If found, "claim" it by setting its godaddy_id so the same Excel row can't match twice
            // (prevents skipping a second identical GoDaddy delivery against the same single Excel row)
            $dup2 = $db->prepare("SELECT id FROM distribuimi WHERE godaddy_id IS NULL AND klienti = ? AND data = ? AND sasia = ? AND pagesa = ? LIMIT 1");
            $dup2->execute([$m['klienti'], $m['data'], $m['sasia'], $m['pagesa']]);
            $excelRowId = $dup2->fetchColumn();
            if ($excelRowId) {
                // Claim this Excel row — set its godaddy_id so it won't match again
                $db->prepare("UPDATE distribuimi SET godaddy_id = ? WHERE id = ?")->execute([$gdId, (int)$excelRowId]);
                $isDup = true;
            }
        }
        if ($isDup) {
            $skipped++;
            continue;
        }

        $insertStmt->execute([
            $m['klienti'],
            $m['data'],
            $m['sasia'],
            $m['boca_te_kthyera'],
            $m['litra'],
            $m['cmimi'],
            $m['pagesa'],
            $m['menyra_e_pageses'],
            $m['fatura_e_derguar'],
            $m['litrat_total'],
            $m['litrat_e_konvertuara'],
            $m['isCylinder'],
            $gdId > 0 ? $gdId : null,
        ]);
        $newId = $db->lastInsertId();

        // Log to changelog with batch_id
        $m['_godaddy_id'] = $gdId;
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, batch_id) VALUES ('insert', 'distribuimi', ?, 'godaddy_import', NULL, ?, ?)")
            ->execute([(int)$newId, json_encode($m, JSON_UNESCAPED_UNICODE), $batchId]);

        $inserted++;
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "U importuan {$inserted} rreshta te reja" . ($skipped ? " ({$skipped} ekzistonin tashme)" : ''),
        'inserted' => $inserted,
        'skipped' => $skipped,
        'batch_id' => $batchId,
    ]);
}

/**
 * History: list past GoDaddy import batches
 */
function handleHistory($db) {
    $stmt = $db->query("
        SELECT batch_id,
               COUNT(*) as row_count,
               MIN(created_at) as imported_at,
               MIN(JSON_UNQUOTE(JSON_EXTRACT(new_value, '$.data'))) as date_from,
               MAX(JSON_UNQUOTE(JSON_EXTRACT(new_value, '$.data'))) as date_to
        FROM changelog
        WHERE field_name = 'godaddy_import'
          AND batch_id IS NOT NULL
          AND reverted = 0
        GROUP BY batch_id
        ORDER BY MIN(created_at) DESC
        LIMIT 20
    ");
    $batches = $stmt->fetchAll();

    // Also find imports without batch_id (the first 33 rows)
    $orphan = $db->query("
        SELECT COUNT(*) as row_count,
               MIN(created_at) as imported_at,
               MIN(JSON_UNQUOTE(JSON_EXTRACT(new_value, '$.data'))) as date_from,
               MAX(JSON_UNQUOTE(JSON_EXTRACT(new_value, '$.data'))) as date_to
        FROM changelog
        WHERE field_name = 'godaddy_import'
          AND batch_id IS NULL
          AND reverted = 0
    ")->fetch();

    if ($orphan && (int)$orphan['row_count'] > 0) {
        $batches[] = [
            'batch_id' => '_orphan',
            'row_count' => $orphan['row_count'],
            'imported_at' => $orphan['imported_at'],
            'date_from' => $orphan['date_from'],
            'date_to' => $orphan['date_to'],
        ];
    }

    echo json_encode(['success' => true, 'batches' => $batches]);
}

/**
 * Undo: delete all distribuimi rows from a specific import batch
 */
function handleUndo($db, $input) {
    $batchId = $input['batch_id'] ?? '';
    if (!$batchId) {
        echo json_encode(['success' => false, 'error' => 'batch_id mungon.']);
        return;
    }

    // Find all row IDs from this batch
    if ($batchId === '_orphan') {
        $stmt = $db->query("SELECT row_id FROM changelog WHERE field_name = 'godaddy_import' AND batch_id IS NULL AND reverted = 0");
    } else {
        $stmt = $db->prepare("SELECT row_id FROM changelog WHERE field_name = 'godaddy_import' AND batch_id = ? AND reverted = 0");
        $stmt->execute([$batchId]);
    }
    $rowIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rowIds)) {
        echo json_encode(['success' => false, 'error' => 'Ky import nuk u gjet ose eshte kthyer tashme.']);
        return;
    }

    $db->beginTransaction();

    // Delete rows from distribuimi
    $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
    $db->prepare("DELETE FROM distribuimi WHERE id IN ($placeholders)")->execute($rowIds);

    // Mark changelog entries as reverted
    if ($batchId === '_orphan') {
        $db->exec("UPDATE changelog SET reverted = 1 WHERE field_name = 'godaddy_import' AND batch_id IS NULL AND reverted = 0");
    } else {
        $db->prepare("UPDATE changelog SET reverted = 1 WHERE field_name = 'godaddy_import' AND batch_id = ? AND reverted = 0")
            ->execute([$batchId]);
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'U hoqen ' . count($rowIds) . ' rreshta nga distribuimi.',
        'removed' => count($rowIds),
    ]);
}

/**
 * Map a GoDaddy delivery_report row to distribuimi columns
 */
function mapRow($row) {
    // Parse Volume: "120.0L" → 120.0 (this is TOTAL liters, not per-unit)
    $volumeTotal = 0;
    $volume = trim($row['Volume'] ?? '');
    if ($volume !== '') {
        $volumeTotal = (float)preg_replace('/[^0-9.\-]/', '', $volume);
    }

    $sasia = (float)($row['DeliveredCylinders'] ?? 0);

    // Convert total volume to per-unit litra (dashboard expects per-unit)
    // e.g. Volume=90.0L with 3 cylinders → litra=30 per cylinder
    $litra = ($sasia > 0) ? round($volumeTotal / $sasia, 2) : $volumeTotal;

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
        'cmimi'                => (float)($row['PricePer1L'] ?? 0),
        'pagesa'               => (float)($row['TotalPrice'] ?? 0),
        'menyra_e_pageses'     => $menyra,
        'fatura_e_derguar'     => trim($row['Comment'] ?? ''),
        'litrat_total'         => round($volumeTotal, 2),
        'litrat_e_konvertuara' => $litra,
        'isCylinder'           => $row['isCylinder'] ?? '0',
    ];
}
