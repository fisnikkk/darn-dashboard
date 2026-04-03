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

    // Count by type (new rows only, exclude duplicates)
    $newCylinders = 0;
    $newNxemese = 0;
    $newProducts = 0;
    foreach ($mapped as $m) {
        if ($m['_duplicate']) continue;
        $type = $m['isCylinder'] ?? '0';
        if ($type === '2') $newNxemese++;
        elseif ($type === '1') $newProducts++;
        else $newCylinders++;
    }

    // Count kontrata added within the date range
    $kontrataCount = 0;
    try {
        $gdBaseUrl = str_replace('dashboard_export.php', '', GD_API_URL);
        $gdUrl = $gdBaseUrl . 'api_product.php?GetAllClients';
        $ch = curl_init($gdUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        curl_close($ch);
        $clientData = json_decode($resp, true);
        if ($clientData && ($clientData['status'] ?? '') === '1' && !empty($clientData['data'])) {
            $fromDate = $dateFrom . ' 00:00:00';
            $toDate = $dateTo . ' 23:59:59';
            foreach ($clientData['data'] as $gc) {
                $ts = $gc['Timestamp'] ?? '';
                if ($ts >= $fromDate && $ts <= $toDate) {
                    $kontrataCount++;
                }
            }
        }
    } catch (Exception $e) {}

    // Count product sales from getSalesLastReport
    $shitjeCount = 0;
    try {
        $salesUrl = str_replace('dashboard_export.php', 'api.php', GD_API_URL) . '?getSalesLastReport';
        $ch = curl_init($salesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        curl_close($ch);
        $salesData = json_decode($resp, true);
        if ($salesData && ($salesData['status'] ?? '') === '1' && !empty($salesData['data'])) {
            $shitjeCount = count($salesData['data']);
        }
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'total_found' => count($mapped),
        'duplicates' => $duplicates,
        'new_rows' => count($mapped) - $duplicates,
        'new_cylinders' => $newCylinders,
        'new_nxemese' => $newNxemese,
        'new_products' => $newProducts,
        'shitje_count' => $shitjeCount,
        'kontrata_count' => $kontrataCount,
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

    // Type filters (default: import all)
    $importCylinders = ($input['import_cylinders'] ?? true) !== false;
    $importNxemese   = ($input['import_nxemese'] ?? true) !== false;
    $importShitje    = ($input['import_shitje'] ?? true) !== false;

    $gdRows = fetchGoDaddyRows($dateFrom, $dateTo);

    if (empty($gdRows)) {
        echo json_encode(['success' => true, 'message' => 'Asgje nuk u gjet per kete periudhe.', 'inserted' => 0, 'skipped' => 0]);
        return;
    }

    // Generate batch ID: gd_YYYYMMDD_HHMMSS
    $batchId = 'gd_' . date('Ymd_His');

    $db->beginTransaction();
    $inserted = 0;
    $insertedNxemese = 0;
    $insertedShitje = 0;
    $skipped = 0;

    // Distribuimi (cylinders, isCylinder=0)
    $insertSQL = "INSERT INTO distribuimi (klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, fatura_e_derguar, litrat_total, litrat_e_konvertuara, isCylinder, godaddy_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $db->prepare($insertSQL);

    // Nxemese (heaters, isCylinder=2)
    $nxemeseSQL = "INSERT INTO nxemese (klienti, data, te_dhena, te_marra, koment) VALUES (?, ?, ?, ?, ?)";
    $nxemeseStmt = $db->prepare($nxemeseSQL);

    // Shitje produkteve — imported from getSalesLastReport below (not from delivery_report)

    foreach ($gdRows as $row) {
        $m = mapRow($row);
        $gdId = (int)($row['ID'] ?? 0);
        $isCylinder = $m['isCylinder'] ?? '0';

        if ($isCylinder === '2' && !$importNxemese) {
            $skipped++;
            continue;
        }
        if ($isCylinder === '1') {
            // Products in delivery_report are skipped — product sales come from
            // getSalesLastReport (product_transaction_info) instead, imported below
            $skipped++;
            continue;
        }
        if ($isCylinder === '0' && !$importCylinders) {
            $skipped++;
            continue;
        }

        if ($isCylinder === '2') {
            // ── HEATER → nxemese table ──
            $nxemeseStmt->execute([
                $m['klienti'],
                $m['data'],
                (int)$m['sasia'],           // te_dhena (delivered)
                (int)$m['boca_te_kthyera'], // te_marra (returned)
                'Import nga GoDaddy',
            ]);
            $insertedNxemese++;

        } else {
            // ── CYLINDER → distribuimi table (same as before) ──

            // Skip duplicates: first by godaddy_id, then fallback for Excel-imported rows
            $isDup = false;
            if ($gdId > 0) {
                $dup = $db->prepare("SELECT COUNT(*) FROM distribuimi WHERE godaddy_id = ?");
                $dup->execute([$gdId]);
                $isDup = (int)$dup->fetchColumn() > 0;
            }
            if (!$isDup && $gdId > 0) {
                $dup2 = $db->prepare("SELECT id FROM distribuimi WHERE godaddy_id IS NULL AND klienti = ? AND data = ? AND sasia = ? AND pagesa = ? LIMIT 1");
                $dup2->execute([$m['klienti'], $m['data'], $m['sasia'], $m['pagesa']]);
                $excelRowId = $dup2->fetchColumn();
                if ($excelRowId) {
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
            $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, batch_id, username) VALUES ('insert', 'distribuimi', ?, 'godaddy_import', NULL, ?, ?, ?)")
                ->execute([(int)$newId, json_encode($m, JSON_UNESCAPED_UNICODE), $batchId, getCurrentUser()]);

            $inserted++;
        }
    }

    // ── Also fetch product SALES from GoDaddy's product_transaction_info ──
    // These come from the "Sell Product" screen (different from delivery_report products)
    if ($importShitje) {
        $salesUrl = str_replace('dashboard_export.php', 'api.php', GD_API_URL) . '?getSalesLastReport';
        $ch = curl_init($salesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $salesResponse = curl_exec($ch);
        curl_close($ch);

        if ($salesResponse) {
            $salesData = json_decode($salesResponse, true);
            if ($salesData && ($salesData['status'] ?? '') === '1' && !empty($salesData['data'])) {
                $salesShitjeSQL = "INSERT INTO shitje_produkteve (data, cilindra_sasia, produkti, klienti, cmimi, totali, menyra_pageses, koment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $salesShitjeStmt = $db->prepare($salesShitjeSQL);

                foreach ($salesData['data'] as $sale) {
                    $saleDate = substr($sale['Time'] ?? $sale['Timestamp'] ?? '', 0, 10); // YYYY-MM-DD

                    // Date range filter — only import sales within the selected period
                    if ($saleDate < $dateFrom || $saleDate > $dateTo) continue;

                    // Dedup: check if same product + client + date + price already exists
                    $dupCheck = $db->prepare("SELECT COUNT(*) FROM shitje_produkteve WHERE data = ? AND LOWER(TRIM(klienti)) = ? AND LOWER(TRIM(produkti)) = ? AND cmimi = ?");
                    $dupCheck->execute([
                        $saleDate,
                        strtolower(trim($sale['ClientName'] ?? '')),
                        strtolower(trim($sale['pro_name'] ?? '')),
                        (float)($sale['UnitPrice'] ?? 0),
                    ]);
                    if ((int)$dupCheck->fetchColumn() > 0) continue;

                    $saleTotal = (float)($sale['UnitPrice'] ?? 0) * (int)($sale['initial_stock'] ?? 1);

                    $salesShitjeStmt->execute([
                        $saleDate,
                        (int)($sale['initial_stock'] ?? 1),
                        trim($sale['pro_name'] ?? ''),
                        trim($sale['ClientName'] ?? ''),
                        (float)($sale['UnitPrice'] ?? 0),
                        $saleTotal,
                        trim($sale['PaymentMethod'] ?? ''),
                        trim($sale['Comment'] ?? ''),
                    ]);
                    $insertedShitje++;
                }
            }
        }
    }

    // ── Sync client details from GoDaddy → kontrata table ──
    $importKontrata = ($input['import_kontrata'] ?? true) !== false;
    $syncedKontrata = 0;
    if ($importKontrata) try {
        $gdBaseUrl = str_replace('dashboard_export.php', '', GD_API_URL);
        $gdClientsUrl = $gdBaseUrl . 'api_product.php?GetAllClients';
        $ch = curl_init($gdClientsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $clientResp = curl_exec($ch);
        curl_close($ch);

        if ($clientResp) {
            $clientData = json_decode($clientResp, true);
            if ($clientData && ($clientData['status'] ?? '') === '1' && !empty($clientData['data'])) {
                $checkStmt = $db->prepare("SELECT id FROM kontrata WHERE LOWER(TRIM(name_from_database)) = ? LIMIT 1");
                $insertStmt = $db->prepare("INSERT INTO kontrata (name_from_database, biznesi, numri_unik, rruga, qyteti, perfaqesuesi, nr_telefonit, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

                foreach ($clientData['data'] as $gc) {
                    $name = trim($gc['Name'] ?? '');
                    if ($name === '') continue;
                    $lower = mb_strtolower($name);

                    $business   = trim($gc['Bussiness'] ?? '') ?: null;
                    $uniqueNum  = trim($gc['Unique_Number'] ?? '') ?: null;
                    $street     = trim($gc['Street'] ?? '') ?: null;
                    $city       = trim($gc['City'] ?? '') ?: null;
                    $rep        = trim($gc['Representative'] ?? '') ?: null;
                    $phone      = trim($gc['PhoneNo'] ?? '') ?: null;
                    $email      = trim($gc['Email'] ?? '') ?: null;

                    $checkStmt->execute([$lower]);
                    if ($checkStmt->fetch()) {
                        // Only update fields where GoDaddy has a value — preserve manual edits
                        $sets = [];
                        $vals = [];
                        if ($business !== null) { $sets[] = 'biznesi = ?'; $vals[] = $business; }
                        if ($uniqueNum !== null) { $sets[] = 'numri_unik = ?'; $vals[] = $uniqueNum; }
                        if ($street !== null) { $sets[] = 'rruga = ?'; $vals[] = $street; }
                        if ($city !== null) { $sets[] = 'qyteti = ?'; $vals[] = $city; }
                        if ($rep !== null) { $sets[] = 'perfaqesuesi = ?'; $vals[] = $rep; }
                        if ($phone !== null) { $sets[] = 'nr_telefonit = ?'; $vals[] = $phone; }
                        if ($email !== null) { $sets[] = 'email = ?'; $vals[] = $email; }
                        if (!empty($sets)) {
                            $vals[] = $lower;
                            $db->prepare("UPDATE kontrata SET " . implode(', ', $sets) . " WHERE LOWER(TRIM(name_from_database)) = ?")->execute($vals);
                        }
                    } else {
                        $insertStmt->execute([$name, $business, $uniqueNum, $street, $city, $rep, $phone, $email]);
                    }
                    $syncedKontrata++;
                }
            }
        }
    } catch (Exception $e) {
        // Silently fail — delivery import already succeeded
    }

    $db->commit();

    // Build response message with counts for each type
    $parts = [];
    if ($inserted > 0) $parts[] = "{$inserted} cilindra";
    if ($insertedNxemese > 0) $parts[] = "{$insertedNxemese} nxemese";
    if ($insertedShitje > 0) $parts[] = "{$insertedShitje} shitje";
    if ($syncedKontrata > 0) $parts[] = "{$syncedKontrata} kontrata";
    $msg = $parts ? 'U importuan: ' . implode(', ', $parts) : 'Asgje e re nuk u gjet';
    if ($skipped > 0) $msg .= " ({$skipped} cilindra ekzistonin tashme)";

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'inserted' => $inserted,
        'inserted_nxemese' => $insertedNxemese,
        'inserted_shitje' => $insertedShitje,
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
