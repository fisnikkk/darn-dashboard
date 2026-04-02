<?php
date_default_timezone_set('Europe/Belgrade'); // Match GoDaddy timezone

/**
 * Android App API — Compatible with GoDaddy's api_product.php response format
 *
 * The Android Admin App calls these endpoints for invoice generation.
 * This serves data from our Railway DB (distribuimi) instead of GoDaddy's delivery_report.
 *
 * Endpoints (via query string):
 *   ?GetAllClients              — Client list from klientet
 *   ?GetInvoicefromToDate       — Delivery data for invoice from distribuimi
 *   ?get_invoice_number         — Next invoice number
 *   ?update_invoicenumber       — Update invoice counter
 *   ?GetBorxhet                 — Debt summary per client (READ-ONLY)
 *
 * Auth: API key via ?api_key= parameter (set ANDROID_API_KEY env var on Railway)
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- API Key Authentication ---
$apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
$expectedKey = getenv('ANDROID_API_KEY') ?: '';

if ($expectedKey === '' || $apiKey !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['status' => '0', 'message' => 'Invalid API key']);
    exit;
}

// --- Route to handler based on query string ---
$query = $_SERVER['QUERY_STRING'] ?? '';

try {
    $db = getDB();

    if (strpos($query, 'GetInvoicefromToDate') !== false) {
        handleGetInvoiceFromToDate($db);
    } elseif (strpos($query, 'GetAllClients') !== false) {
        handleGetAllClients($db);
    } elseif (strpos($query, 'update_invoicenumber') !== false) {
        handleUpdateInvoiceNumber($db);
    } elseif (strpos($query, 'get_invoice_number') !== false) {
        handleGetInvoiceNumber($db);
    } elseif (strpos($query, 'GetClientTransactions') !== false) {
        handleGetClientTransactions($db);
    } elseif (strpos($query, 'UpdateBorxhiStatus') !== false) {
        handleUpdateBorxhiStatus($db);
    } elseif (strpos($query, 'GetPendingBorxh') !== false) {
        handleGetPendingBorxh($db);
    } elseif (strpos($query, 'ApproveBorxh') !== false) {
        handleApproveBorxh($db);
    } elseif (strpos($query, 'GetBocaPerKlient') !== false) {
        handleGetBocaPerKlient($db);
    } elseif (strpos($query, 'GetNxemesePerKlient') !== false) {
        handleGetNxemesePerKlient($db);
    } elseif (strpos($query, 'InsertProductSale') !== false) {
        handleInsertProductSale($db);
    } elseif (strpos($query, 'getSalesLastReport') !== false) {
        handleGetSalesLastReport($db);
    } elseif (strpos($query, 'GetBorxhCollections') !== false) {
        handleGetBorxhCollections($db);
    } elseif (strpos($query, 'GetBorxhet') !== false) {
        handleGetBorxhet($db);
    } elseif (strpos($query, 'SyncDeliveryToDistribuimi') !== false) {
        handleSyncDeliveryToDistribuimi($db);
    } elseif (strpos($query, 'UpdateNxemeseStock') !== false) {
        handleUpdateNxemeseStock($db);
    } elseif (strpos($query, 'SearchARBK') !== false) {
        handleSearchARBK();
    } elseif (strpos($query, 'RegisterContract') !== false) {
        handleRegisterContract($db);
    } elseif (strpos($query, 'MarkInvoiceRows') !== false) {
        handleMarkInvoiceRows($db);
    } else {
        echo json_encode(['status' => '0', 'message' => 'Unknown endpoint']);
    }
} catch (Exception $e) {
    error_log('api_android.php error: ' . $e->getMessage());
    echo json_encode(['status' => '0', 'message' => 'Server error']);
}


/**
 * GetAllClients — Returns client list matching GoDaddy's Get_Client_List_POJO format
 *
 * Fetches client details (business name, address, fiscal number, email) from GoDaddy's
 * api_product.php?GetAllClients. Falls back to local klientet table if GoDaddy is unreachable.
 *
 * Expected by Android:
 * { "status": "1", "data": [{ "Name": "...", "Bussiness": "...", "Email": "...", ... }] }
 */
function handleGetAllClients($db) {
    // Try fetching client details from GoDaddy (primary source for business info)
    require_once __DIR__ . '/../config/godaddy.php';
    $gdBaseUrl = str_replace('dashboard_export.php', '', GD_API_URL);
    $gdUrl = $gdBaseUrl . 'api_product.php?GetAllClients';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $gdUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'DARN-Dashboard/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $httpCode === 200) {
        $gdData = json_decode($response, true);
        if ($gdData && isset($gdData['status']) && $gdData['status'] === '1' && !empty($gdData['data'])) {
            // GoDaddy returned valid client data — pass it through directly
            echo json_encode($gdData, JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    // Fallback: use local klientet table if GoDaddy is unreachable
    $stmt = $db->query("
        SELECT
            d.klienti,
            k.i_regjistruar_ne_emer,
            k.adresa,
            k.telefoni,
            k.numri_unik_identifikues,
            k.email,
            k.kontakti,
            k.created_at
        FROM (SELECT DISTINCT klienti FROM distribuimi ORDER BY klienti ASC) d
        LEFT JOIN klientet k ON LOWER(TRIM(k.emri)) = LOWER(TRIM(d.klienti))
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'Timestamp'      => $r['created_at'] ?? date('Y-m-d H:i:s'),
            'Name'           => $r['klienti'] ?? '',
            'Bussiness'      => $r['i_regjistruar_ne_emer'] ?? '',
            'City'           => '',
            'Street'         => $r['adresa'] ?? '',
            'Unique_Number'  => $r['numri_unik_identifikues'] ?? '',
            'Representative' => $r['kontakti'] ?? '',
            'PhoneNo'        => $r['telefoni'] ?? '',
            'Email'          => $r['email'] ?? '',
        ];
    }

    echo json_encode([
        'status' => '1',
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
}


/**
 * GetInvoicefromToDate — Returns delivery data matching Get_Client_List_for_Invoice_POJO format
 *
 * Params: clientName, start_date, end_date, isType
 *
 * Expected by Android:
 * {
 *   "status": "1",
 *   "message": "success",
 *   "TotalDeliveredCylinders": "50",
 *   "TotalReturnedCylinders": "10",
 *   "data": [{
 *     "Timestamp": "...", "Client": "...", "pro_name": "...", "Date": "...",
 *     "DeliveredCylinders": "5", "ReturnedCylinders": "2", "Volume": "120.0L",
 *     "PricePer1L": "125.50", "TotalPrice": "15000.00", "PaymentMethod": "CASH",
 *     "Comment": "", "Distributor": "", "ID": "123", "isCylinder": "1"
 *   }]
 * }
 */
function handleGetInvoiceFromToDate($db) {
    $clientName = $_GET['clientName'] ?? '';
    $startDate  = $_GET['start_date'] ?? '';
    $endDate    = $_GET['end_date'] ?? '';
    $isType     = $_GET['isType'] ?? '0'; // 0=Cylinder, 1=Product, 2=Both/All, 3=Heater(Nxemese)

    if (empty($clientName) || empty($startDate) || empty($endDate)) {
        echo json_encode(['status' => '0', 'message' => 'Missing parameters']);
        return;
    }

    // Auto-sync DISABLED — was causing duplicate rows in distribuimi after Excel imports
    // autoSyncFromGoDaddy($db, $startDate, $endDate);

    // Build query with isType filter
    // isType: 0=Cylinder (isCylinder=0), 1=Product (isCylinder=1), 2=All, 3=Heater (isCylinder=2)
    $sql = "
        SELECT id, klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa,
               menyra_e_pageses, fatura_e_derguar, created_at, IFNULL(isCylinder, '0') AS isCylinder
        FROM distribuimi
        WHERE LOWER(TRIM(klienti)) = LOWER(TRIM(?))
          AND data >= ?
          AND data <= ?
    ";
    $params = [$clientName, $startDate, $endDate];

    if ($isType === '0') {
        // Cylinders only — isCylinder=0 or NULL (all existing data before the column was added)
        $sql .= " AND (isCylinder = '0' OR isCylinder IS NULL)";
    } elseif ($isType === '1') {
        // Products only
        $sql .= " AND isCylinder = '1'";
    } elseif ($isType === '3') {
        // Heaters (Nxemese) only
        $sql .= " AND isCylinder = '2'";
    }
    // isType=2 means "All" — no filter needed

    $sql .= " ORDER BY data ASC, id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode([
            'status' => '0',
            'message' => 'No data found for this client in the selected date range',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $totalDelivered = 0;
    $totalReturned = 0;
    $data = [];

    foreach ($rows as $r) {
        $sasia = (float)($r['sasia'] ?? 0);
        $returned = (float)($r['boca_te_kthyera'] ?? 0);
        $litra = (float)($r['litra'] ?? 0);
        $cmimi = (float)($r['cmimi'] ?? 0);
        $pagesa = (float)($r['pagesa'] ?? 0);

        $totalDelivered += $sasia;
        $totalReturned += $returned;

        // Format Volume as "120.0L" to match GoDaddy format
        $volumeStr = number_format($litra, 1, '.', '') . 'L';

        // Map payment method to match GoDaddy naming
        $payment = strtoupper(trim($r['menyra_e_pageses'] ?? ''));
        // Normalize: our DB has various formats, GoDaddy uses simple CASH/BANK/NO PAYMENT/DHURATE
        if (strpos($payment, 'CASH') !== false) {
            // "CASH" or "PO (FATURE TE RREGULLTE) CASH" → send as stored (Android displays as-is)
            $paymentMethod = $r['menyra_e_pageses'] ?? 'CASH';
        } elseif (strpos($payment, 'BANK') !== false) {
            $paymentMethod = $r['menyra_e_pageses'] ?? 'BANK';
        } elseif (strpos($payment, 'NO PAYMENT') !== false) {
            $paymentMethod = 'NO PAYMENT';
        } elseif (strpos($payment, 'DHURATE') !== false) {
            $paymentMethod = 'DHURATE';
        } else {
            $paymentMethod = $r['menyra_e_pageses'] ?? '';
        }

        // Set product name based on isCylinder value
        $rowIsCylinder = (int)($r['isCylinder'] ?? 0);
        $proName = match ($rowIsCylinder) {
            2 => 'NXEMESE',
            1 => 'PRODUKT',
            default => 'GAS I LENGET (L)',
        };

        $data[] = [
            'Timestamp'          => $r['created_at'] ?? $r['data'] ?? '',
            'Client'             => $r['klienti'] ?? '',
            'pro_name'           => $proName,
            'Date'               => $r['data'] ?? '',
            'DeliveredCylinders' => (string)(int)$sasia,
            'ReturnedCylinders'  => (string)(int)$returned,
            'Volume'             => $volumeStr,
            'PricePer1L'         => number_format($cmimi, 2, '.', ''),
            'TotalPrice'         => number_format($pagesa, 2, '.', ''),
            'PaymentMethod'      => $paymentMethod,
            'Comment'            => $r['fatura_e_derguar'] ?? '',
            'Distributor'        => '',
            'ID'                 => (int)$r['id'],
            'isCylinder'         => $rowIsCylinder,
        ];
    }

    echo json_encode([
        'status'                  => '1',
        'message'                 => 'Data fetched successfully',
        'TotalDeliveredCylinders' => (string)(int)$totalDelivered,
        'TotalReturnedCylinders'  => (string)(int)$totalReturned,
        'data'                    => $data,
    ], JSON_UNESCAPED_UNICODE);
}


/**
 * Auto-sync latest data from GoDaddy for a date range (best-effort, non-blocking)
 * This ensures Railway DB has the freshest data before serving invoice requests.
 * Silently fails if GoDaddy is unreachable — serves from existing DB data instead.
 */
function autoSyncFromGoDaddy($db, $dateFrom, $dateTo) {
    // Include GoDaddy config (for callGoDaddyAPI)
    $godaddyConfig = __DIR__ . '/../config/godaddy.php';
    if (!file_exists($godaddyConfig)) return;

    require_once $godaddyConfig;

    try {
        $result = callGoDaddyAPI([
            'action'    => 'fetch',
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);

        if (!$result || !($result['success'] ?? false)) return;

        $rows = $result['rows'] ?? [];
        if (empty($rows)) return;

        $insertSQL = "INSERT INTO distribuimi (klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, fatura_e_derguar, litrat_total, litrat_e_konvertuara, isCylinder) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $db->prepare($insertSQL);
        $dupCheck = $db->prepare("SELECT COUNT(*) FROM distribuimi WHERE klienti = ? AND data = ? AND sasia = ? AND pagesa = ?");

        foreach ($rows as $row) {
            $m = mapGoDaddyRow($row);

            // Skip if already exists (same duplicate logic as fetch_godaddy.php)
            $dupCheck->execute([$m['klienti'], $m['data'], $m['sasia'], $m['pagesa']]);
            if ((int)$dupCheck->fetchColumn() > 0) continue;

            $insertStmt->execute([
                $m['klienti'], $m['data'], $m['sasia'], $m['boca_te_kthyera'],
                $m['litra'], $m['cmimi'], $m['pagesa'], $m['menyra_e_pageses'],
                $m['fatura_e_derguar'], $m['litrat_total'], $m['litrat_e_konvertuara'],
                $m['isCylinder'],
            ]);
        }
    } catch (Exception $e) {
        // Silently fail — serve from existing data
        error_log('api_android auto-sync failed: ' . $e->getMessage());
    }
}


/**
 * Map a GoDaddy row to distribuimi format (same logic as fetch_godaddy.php mapRow)
 */
function mapGoDaddyRow($row) {
    $litra = 0;
    $volume = trim($row['Volume'] ?? '');
    if ($volume !== '') {
        $litra = (float)preg_replace('/[^0-9.\-]/', '', $volume);
    }

    $sasia = (float)($row['DeliveredCylinders'] ?? 0);

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
        'litrat_total'         => round($sasia * $litra, 2),
        'litrat_e_konvertuara' => $litra,
        'isCylinder'           => $row['isCylinder'] ?? '0',
    ];
}


/**
 * get_invoice_number — Returns next invoice number matching InvNumber POJO format
 *
 * Expected by Android: { "inv_number": "131" }
 */
function handleGetInvoiceNumber($db) {
    $stmt = $db->query("SELECT setting_value FROM invoice_settings WHERE setting_key = 'next_invoice_number'");
    $val = $stmt->fetchColumn();

    echo json_encode([
        'inv_number' => $val ?: '1',
    ]);
}


/**
 * update_invoicenumber — Updates invoice counter, returns new value
 *
 * Param: nextInv (the new next invoice number)
 * Expected by Android: { "inv_number": "132" }
 */
function handleUpdateInvoiceNumber($db) {
    $nextInv = $_GET['nextInv'] ?? '';

    if (empty($nextInv) || !is_numeric($nextInv)) {
        echo json_encode(['status' => '0', 'message' => 'Invalid invoice number']);
        return;
    }

    $stmt = $db->prepare("UPDATE invoice_settings SET setting_value = ? WHERE setting_key = 'next_invoice_number'");
    $stmt->execute([$nextInv]);

    echo json_encode([
        'inv_number' => $nextInv,
    ]);
}


/**
 * MarkInvoiceRows — Mark CASH rows as "PO (FATURE TE RREGULLTE) CASH" after invoice generation
 *
 * Called by the Android app after creating an invoice PDF.
 * Same logic as api/invoice.php lines 201-222.
 *
 * Params: client, date_from, date_to, invoice_number, isType (optional)
 */
function handleMarkInvoiceRows($db) {
    $client = $_GET['client'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $invoiceNum = $_GET['invoice_number'] ?? '';
    $isType = $_GET['isType'] ?? '0';

    if (empty($client) || empty($dateFrom) || empty($dateTo) || empty($invoiceNum)) {
        echo json_encode(['status' => '0', 'message' => 'Missing parameters']);
        return;
    }

    // Find CASH rows for this client/date range
    $sql = "SELECT id FROM distribuimi
            WHERE LOWER(TRIM(klienti)) = LOWER(TRIM(?))
              AND data >= ? AND data <= ?
              AND LOWER(TRIM(menyra_e_pageses)) = 'cash'";
    $params = [$client, $dateFrom, $dateTo];

    // Apply type filter (same as GetInvoicefromToDate)
    if ($isType === '0') {
        $sql .= " AND (isCylinder = '0' OR isCylinder IS NULL)";
    } elseif ($isType === '1') {
        $sql .= " AND isCylinder = '1'";
    } elseif ($isType === '3') {
        $sql .= " AND isCylinder = '2'";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $cashIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $updated = 0;
    if (!empty($cashIds)) {
        $placeholders = implode(',', array_fill(0, count($cashIds), '?'));
        $upd = $db->prepare("UPDATE distribuimi SET menyra_e_pageses = 'PO (FATURE TE RREGULLTE) CASH' WHERE id IN ({$placeholders})");
        $upd->execute($cashIds);
        $updated = $upd->rowCount();

        // Log to changelog
        $batchId = 'inv_' . $invoiceNum;
        $logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, batch_id, username) VALUES ('update', 'distribuimi', ?, 'menyra_e_pageses', 'CASH', 'PO (FATURE TE RREGULLTE) CASH', ?, ?)");
        foreach ($cashIds as $rid) {
            $logStmt->execute([$rid, $batchId, getCurrentUser()]);
        }
    }

    echo json_encode([
        'status' => '1',
        'message' => $updated > 0 ? "{$updated} rows updated to PO FATURE" : 'No CASH rows to update',
        'inv_number' => $invoiceNum,
        'updated' => $updated,
    ]);
}


/**
 * GetBorxhet — Returns debt summary per client (READ-ONLY)
 *
 * Same calculation as pages/borxhet.php: GROUP BY client, SUM by payment method.
 * ZERO INSERT/UPDATE/DELETE — only SELECT queries with prepared statements.
 *
 * Params (all optional via GET):
 *   date_from    — Start date (YYYY-MM-DD). If omitted, no lower bound.
 *   date_to      — End date (YYYY-MM-DD). Defaults to today.
 *   payment_type — Filter: "cash", "bank", "fature_banke", "fature_cash", "no_payment", "dhurate"
 *   client_type  — Filter by klientet.bashkepunim: "po" or "jo"
 */
function handleGetBorxhet($db) {
    $dateFrom    = !empty($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo      = !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
    $paymentType = !empty($_GET['payment_type']) ? $_GET['payment_type'] : '';
    $clientType  = !empty($_GET['client_type'])  ? $_GET['client_type']  : '';
    $client      = !empty($_GET['client'])      ? trim($_GET['client']) : '';

    // Build WHERE clause
    $where = [];
    $params = [];

    // borxhi_bank_deri always uses date_to
    $borxhiDateParam = $dateTo;

    // date_from filter (matches borxhet.php logic)
    if ($dateFrom !== '') {
        $where[] = 'd.data >= ?';
        $params[] = $dateFrom;
    }

    // Client type filter via LEFT JOIN to klientet
    // Always LEFT JOIN (so clients not in klientet table still appear)
    // Only filter by bashkepunim when client_type is specified
    $joinKlientet = 'LEFT JOIN klientet k ON LOWER(TRIM(k.emri)) = LOWER(TRIM(d.klienti))';
    if ($clientType !== '' && in_array($clientType, ['po', 'jo'])) {
        $where[] = 'LOWER(TRIM(COALESCE(k.bashkepunim, \'\'))) = ?';
        $params[] = $clientType;
    }

    // Optional: filter by specific client name
    if ($client !== '') {
        $where[] = 'LOWER(TRIM(d.klienti)) = LOWER(TRIM(?))';
        $params[] = $client;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Core aggregation query — READ-ONLY SELECT
    $sql = "
        SELECT
            MIN(d.klienti) AS klienti,
            MIN(COALESCE(k.bashkepunim, '')) AS bashkepunim,
            SUM(CASE WHEN LOWER(TRIM(d.menyra_e_pageses)) = 'cash' THEN d.pagesa ELSE 0 END) AS cash,
            SUM(CASE WHEN LOWER(TRIM(d.menyra_e_pageses)) = 'bank' THEN d.pagesa ELSE 0 END) AS bank,
            SUM(CASE WHEN LOWER(TRIM(d.menyra_e_pageses)) = 'po (fature te rregullte) banke' THEN d.pagesa ELSE 0 END) AS fature_banke,
            SUM(CASE WHEN LOWER(TRIM(d.menyra_e_pageses)) = 'po (fature te rregullte) cash' THEN d.pagesa ELSE 0 END) AS fature_cash,
            SUM(CASE WHEN LOWER(TRIM(d.menyra_e_pageses)) = 'no payment' THEN d.pagesa ELSE 0 END) AS no_payment,
            SUM(CASE WHEN LOWER(TRIM(d.menyra_e_pageses)) = 'dhurate' THEN d.pagesa ELSE 0 END) AS dhurate,
            SUM(d.pagesa) AS total,
            SUM(CASE WHEN LOWER(TRIM(d.menyra_e_pageses)) = 'bank' AND d.data <= ? THEN d.pagesa ELSE 0 END) AS borxhi_bank_deri
        FROM distribuimi d
        {$joinKlientet}
        {$whereSQL}
        GROUP BY LOWER(d.klienti)
        HAVING total > 0
        ORDER BY MIN(d.klienti)
    ";

    // borxhi date param comes first in the query (the ? in the CASE WHEN)
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$borxhiDateParam], $params));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // bashkepunim now comes from the LEFT JOIN query directly (no separate lookup needed)

    // Build response
    $data = [];
    $totals = [
        'cash' => 0, 'bank' => 0, 'fature_banke' => 0, 'fature_cash' => 0,
        'no_payment' => 0, 'dhurate' => 0, 'total' => 0, 'borxhi_bank_deri' => 0
    ];

    foreach ($rows as $r) {
        // Payment type post-filter: skip rows where chosen type has zero amount
        if ($paymentType !== '') {
            $fieldMap = [
                'cash' => 'cash', 'bank' => 'bank',
                'fature_banke' => 'fature_banke', 'fature_cash' => 'fature_cash',
                'no_payment' => 'no_payment', 'dhurate' => 'dhurate'
            ];
            if (isset($fieldMap[$paymentType]) && (float)$r[$fieldMap[$paymentType]] == 0) {
                continue;
            }
        }

        // When a payment type filter is active, override "total" to show only that type's amount
        // so "Total" reflects the filtered payment type, not the full turnover
        $rowTotal = (float)$r['total'];
        if ($paymentType !== '' && isset($fieldMap[$paymentType])) {
            $rowTotal = (float)$r[$fieldMap[$paymentType]];
        }

        $row = [
            'klienti'          => $r['klienti'],
            'cash'             => number_format((float)$r['cash'], 2, '.', ''),
            'bank'             => number_format((float)$r['bank'], 2, '.', ''),
            'fature_banke'     => number_format((float)$r['fature_banke'], 2, '.', ''),
            'fature_cash'      => number_format((float)$r['fature_cash'], 2, '.', ''),
            'no_payment'       => number_format((float)$r['no_payment'], 2, '.', ''),
            'dhurate'          => number_format((float)$r['dhurate'], 2, '.', ''),
            'total'            => number_format($rowTotal, 2, '.', ''),
            'borxhi_bank_deri' => number_format((float)$r['borxhi_bank_deri'], 2, '.', ''),
            'bashkepunim'      => $r['bashkepunim'] ?? '',
        ];
        $data[] = $row;

        // Use the adjusted total for summary totals too
        foreach ($totals as $k => &$v) {
            if ($k === 'total') {
                $v += $rowTotal;
            } else {
                $v += (float)$r[$k];
            }
        }
    }

    // Format totals
    $formattedTotals = [];
    foreach ($totals as $k => $v) {
        $formattedTotals[$k] = number_format($v, 2, '.', '');
    }

    // Calculate borxhi_mbledhur (collected debts) from changelog
    // These are transactions that were changed from BANK → CASH via collect_borxh
    // Only count non-reverted entries, and respect same date/client filters as main query
    $borxhiWhere = [
        "c.table_name = 'distribuimi'",
        "c.field_name = 'menyra_e_pageses'",
        "LOWER(TRIM(c.old_value)) = 'bank'",
        "LOWER(TRIM(c.new_value)) = 'cash'",
        "c.reverted = 0"
    ];
    $borxhiParams = [];

    // Respect date filters (filter by the distribuimi row's date, same as main query)
    if ($dateFrom !== '') {
        $borxhiWhere[] = 'd.data >= ?';
        $borxhiParams[] = $dateFrom;
    }
    // date_to: main query doesn't filter by date_to on distribuimi, so we don't either

    // Respect client filter
    if ($client !== '') {
        $borxhiWhere[] = 'LOWER(TRIM(d.klienti)) = LOWER(TRIM(?))';
        $borxhiParams[] = $client;
    }

    $borxhiWhereSQL = 'WHERE ' . implode(' AND ', $borxhiWhere);
    $borxhiMbledhurStmt = $db->prepare("
        SELECT COALESCE(SUM(d.pagesa), 0)
        FROM changelog c
        JOIN distribuimi d ON d.id = c.row_id
        {$borxhiWhereSQL}
    ");
    $borxhiMbledhurStmt->execute($borxhiParams);
    $borxhiMbledhur = (float)$borxhiMbledhurStmt->fetchColumn();
    $formattedTotals['borxhi_mbledhur'] = number_format($borxhiMbledhur, 2, '.', '');

    // Cash from borxhe = same as borxhi_mbledhur (cash that originated from debt collection)
    $formattedTotals['cash_from_borxhe'] = $formattedTotals['borxhi_mbledhur'];

    // Cash from shitje = total cash minus cash from collected borxhe
    $cashFromShitje = $totals['cash'] - $borxhiMbledhur;
    $formattedTotals['cash_from_shitje'] = number_format(max(0, $cashFromShitje), 2, '.', '');

    echo json_encode([
        'status'  => '1',
        'message' => 'Data fetched successfully',
        'totals'  => $formattedTotals,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
}


/**
 * GetClientTransactions — Returns individual distribuimi rows for a specific client (READ-ONLY)
 *
 * ZERO INSERT/UPDATE/DELETE — only SELECT with prepared statements.
 *
 * Params (via GET):
 *   client_name   — (required) Client name
 *   status_filter — "bank" (only debt transactions) or "not_bank" (non-debt transactions)
 *   date_from     — (optional) Start date YYYY-MM-DD
 *   date_to       — (optional) End date YYYY-MM-DD
 */
function handleGetClientTransactions($db) {
    $clientName   = $_GET['client_name'] ?? '';
    $statusFilter = $_GET['status_filter'] ?? '';
    $dateFrom     = !empty($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo       = !empty($_GET['date_to'])   ? $_GET['date_to']   : '';

    if (trim($clientName) === '') {
        echo json_encode(['status' => '0', 'message' => 'client_name is required']);
        return;
    }

    // LEJE BORXHIN: status_filter="cash" → query GoDaddy's delivery_report directly
    // (No auto-sync — Lena's requirement #3: nothing gets synced automatically)
    if ($statusFilter === 'cash') {
        handleGetClientTransactionsFromGoDaddy($db, $clientName, $dateFrom, $dateTo);
        return;
    }

    // MERR BORXHIN: status_filter="bank" → query distribuimi first (live system has BANK there)
    // If no results in distribuimi, fall back to GoDaddy (test system without auto-sync)
    // This ensures both live and test environments work correctly

    // Other filters: query distribuimi
    $where = ['LOWER(TRIM(klienti)) = ?'];
    $params = [strtolower(trim($clientName))];

    // Status filter
    if ($statusFilter === 'bank') {
        $where[] = "LOWER(TRIM(menyra_e_pageses)) = 'bank'";
    } elseif ($statusFilter === 'not_bank') {
        $where[] = "LOWER(TRIM(menyra_e_pageses)) != 'bank'";
    }

    // Date filters
    if ($dateFrom !== '') {
        $where[] = 'data > ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'data <= ?';
        $params[] = $dateTo;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // READ-ONLY SELECT — no data modification
    $sql = "SELECT id, data, sasia, litra, cmimi, pagesa, menyra_e_pageses, koment, IFNULL(isCylinder, '0') AS isCylinder
            FROM distribuimi
            {$whereSQL}
            ORDER BY data DESC, id DESC
            LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format numeric fields
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'id'                => (int)$r['id'],
            'data'              => $r['data'],
            'sasia'             => (int)$r['sasia'],
            'litra'             => number_format((float)$r['litra'], 2, '.', ''),
            'cmimi'             => number_format((float)$r['cmimi'], 4, '.', ''),
            'pagesa'            => number_format((float)$r['pagesa'], 2, '.', ''),
            'menyra_e_pageses'  => $r['menyra_e_pageses'] ?? '',
            'koment'            => $r['koment'] ?? '',
            'isCylinder'        => $r['isCylinder'] ?? '0',
        ];
    }

    // Also fetch total BANK amount for this client (remaining debt)
    // This is always returned regardless of status_filter so the app can show the total
    $bankTotalStmt = $db->prepare("SELECT COALESCE(SUM(pagesa), 0) AS bank_total FROM distribuimi WHERE LOWER(TRIM(klienti)) = ? AND LOWER(TRIM(menyra_e_pageses)) = 'bank'");
    $bankTotalStmt->execute([strtolower(trim($clientName))]);
    $bankTotalRow = $bankTotalStmt->fetch(PDO::FETCH_ASSOC);
    $bankTotal = number_format((float)($bankTotalRow['bank_total'] ?? 0), 2, '.', '');

    // If BANK filter returned no results from distribuimi, try GoDaddy as fallback
    // (test environment without auto-sync has BANK records on GoDaddy instead)
    if ($statusFilter === 'bank' && empty($data)) {
        handleGetClientTransactionsFromGoDaddy($db, $clientName, $dateFrom, $dateTo, 'bank');
        return;
    }

    echo json_encode([
        'status'     => '1',
        'message'    => count($data) . ' transactions found',
        'bank_total' => $bankTotal,
        'data'       => $data,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Helper: Fetch client transactions from GoDaddy's delivery_report for Leje Borxhin.
 * Queries GoDaddy API (action=fetch) and filters by client name + PaymentMethod=CASH.
 * Maps delivery_report columns to the same JSON format as the distribuimi query,
 * so the Android app needs zero changes.
 */
function handleGetClientTransactionsFromGoDaddy($db, $clientName, $dateFrom, $dateTo, $paymentFilter = 'cash') {
    require_once __DIR__ . '/../config/godaddy.php';

    // Need dates for the GoDaddy fetch API
    if ($dateTo === '') {
        $dateTo = date('Y-m-d');
    }
    if ($dateFrom === '') {
        $dateFrom = $dateTo;
    }

    $result = callGoDaddyAPI([
        'action'    => 'fetch',
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
    ]);

    if (!$result || !($result['success'] ?? false)) {
        echo json_encode([
            'status'     => '0',
            'message'    => 'Could not reach GoDaddy server. Try again later.',
            'bank_total' => '0.00',
            'data'       => [],
        ]);
        return;
    }

    $rows = $result['rows'] ?? [];
    $data = [];

    foreach ($rows as $row) {
        // Filter by client name (case-insensitive)
        if (strtolower(trim($row['Client'] ?? '')) !== strtolower(trim($clientName))) {
            continue;
        }

        // Filter by payment method (CASH for Leje Borxhin, BANK for Merr Borxhin)
        $payment = strtoupper(trim($row['PaymentMethod'] ?? ''));
        if ($payment !== strtoupper($paymentFilter)) {
            continue;
        }

        // Date filter: use Timestamp (with time) so deliveries after day closure still show
        // Day closure passes exact datetime, so we compare against Timestamp not just Date
        $rowTimestamp = $row['Timestamp'] ?? '';
        if ($dateFrom !== '' && $rowTimestamp !== '' && $rowTimestamp <= $dateFrom) {
            continue;
        }

        // Map delivery_report columns to the expected response format
        // (same field names as distribuimi query so Android app needs no changes)
        $data[] = [
            'id'                => (int)$row['ID'],
            'data'              => $row['Date'] ?? '',
            'sasia'             => (int)($row['DeliveredCylinders'] ?? 0),
            'litra'             => number_format((float)($row['Volume'] ?? 0), 2, '.', ''),
            'cmimi'             => number_format((float)($row['PricePer1L'] ?? 0), 4, '.', ''),
            'pagesa'            => number_format((float)($row['TotalPrice'] ?? 0), 2, '.', ''),
            'menyra_e_pageses'  => $row['PaymentMethod'] ?? '',
            'koment'            => trim($row['Comment'] ?? ''),
            'isCylinder'        => $row['isCylinder'] ?? '0',
        ];
    }

    // Sort by date DESC, ID DESC (matching distribuimi behavior)
    usort($data, function($a, $b) {
        $cmp = strcmp($b['data'], $a['data']);
        return $cmp !== 0 ? $cmp : ($b['id'] - $a['id']);
    });

    // Calculate bank_total from filtered data
    $bankTotal = 0.0;
    foreach ($data as $item) {
        $bankTotal += (float)$item['pagesa'];
    }

    // Limit to 200
    $data = array_slice($data, 0, 200);

    echo json_encode([
        'status'     => '1',
        'message'    => count($data) . ' transactions found',
        'bank_total' => number_format($bankTotal, 2, '.', ''),
        'data'       => $data,
    ], JSON_UNESCAPED_UNICODE);
}


/**
 * UpdateBorxhiStatus — Change payment status on a specific distribuimi transaction.
 *
 * SAFETY:
 *   - Only 2 fields ever changed: menyra_e_pageses and koment
 *   - Only 2 actions allowed: "register_borxh" and "collect_borxh"
 *   - Server validates current state before updating
 *   - All changes wrapped in DB transaction (atomic)
 *   - All changes logged to changelog table
 *   - API key auth required (handled by parent router)
 *
 * Method: POST (JSON body)
 * Body: { "id": 123, "action": "register_borxh" | "collect_borxh", "comment": "optional", "user": "optional" }
 */
function handleUpdateBorxhiStatus($db) {
    // Only accept POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => '0', 'message' => 'POST method required']);
        return;
    }

    // Parse JSON body
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        echo json_encode(['status' => '0', 'message' => 'Invalid JSON body']);
        return;
    }

    $id          = isset($body['id']) ? (int)$body['id'] : 0;
    $action      = $body['action'] ?? '';
    $userComment = isset($body['comment']) ? trim($body['comment']) : '';
    $userName    = isset($body['user'])    ? trim($body['user'])    : '';

    // Validate inputs
    if ($id <= 0) {
        echo json_encode(['status' => '0', 'message' => 'Valid transaction ID required']);
        return;
    }
    if (!in_array($action, ['register_borxh', 'collect_borxh'])) {
        echo json_encode(['status' => '0', 'message' => 'Invalid action. Use register_borxh or collect_borxh']);
        return;
    }

    try {
        // Build the comment that would be applied (for display in approval screen)
        $komentForPending = $userComment;
        if ($userName !== '') {
            $komentForPending .= ($komentForPending !== '' ? ' ' : '') . '(nga: ' . $userName . ')';
        }

        if ($action === 'register_borxh') {
            // ═══════════════════════════════════════════════════════════════
            // LEJE BORXHIN — validate against GoDaddy's delivery_report
            // The ID coming from the client app is a delivery_report.ID
            // ═══════════════════════════════════════════════════════════════
            require_once __DIR__ . '/../config/godaddy.php';

            $gdResult = callGoDaddyAPI([
                'action' => 'fetch_by_id',
                'id'     => $id,
            ]);

            if (!$gdResult || !($gdResult['success'] ?? false)) {
                echo json_encode(['status' => '0', 'message' => 'Could not verify record on GoDaddy. Try again later.']);
                return;
            }

            $gdRow = $gdResult['row'];
            $currentPayment = strtoupper(trim($gdRow['PaymentMethod'] ?? ''));

            if ($currentPayment !== 'CASH') {
                echo json_encode(['status' => '0', 'message' => 'Vetem transaksionet CASH mund te regjistrohen si borxh. Aktuale: ' . $gdRow['PaymentMethod']]);
                return;
            }

            // Check for existing request (pending OR approved) to prevent duplicates
            $newPayment = 'BANK';
            $pendingCheck = $db->prepare("SELECT id FROM pending_borxh WHERE distribuimi_id = ? AND source_table = 'delivery_report' AND new_menyra_e_pageses = ? AND status IN ('pending', 'approved')");
            $pendingCheck->execute([$id, $newPayment]);
            if ($pendingCheck->fetch()) {
                echo json_encode(['status' => '0', 'message' => 'Ky transaksion eshte regjistruar tashme si borxh.']);
                return;
            }

            // INSERT into pending_borxh with source_table='delivery_report'
            $isCylinder = $gdRow['isCylinder'] ?? '0';
            $insertStmt = $db->prepare("INSERT INTO pending_borxh (distribuimi_id, source_table, klienti, old_menyra_e_pageses, new_menyra_e_pageses, pagesa, data_e_shitjes, koment, requested_by, isCylinder) VALUES (?, 'delivery_report', ?, ?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->execute([
                $id,
                $gdRow['Client'],
                $gdRow['PaymentMethod'],
                $newPayment,
                (float)($gdRow['TotalPrice'] ?? 0),
                $gdRow['Date'],
                $komentForPending,
                $userName,
                $isCylinder
            ]);

            echo json_encode([
                'status'  => '1',
                'message' => 'Kerkesa u dergua per miratim. Prisni aprovimin nga admini.',
                'data'    => [
                    'id'                    => $id,
                    'klienti'               => $gdRow['Client'],
                    'old_menyra_e_pageses'  => $gdRow['PaymentMethod'],
                    'new_menyra_e_pageses'  => $newPayment,
                    'pending'               => true,
                ],
            ], JSON_UNESCAPED_UNICODE);

        } elseif ($action === 'collect_borxh') {
            // ═══════════════════════════════════════════════════════════════
            // MERR BORXHIN — try distribuimi first, then GoDaddy delivery_report
            // ═══════════════════════════════════════════════════════════════
            $sourceTable = 'distribuimi';
            $stmt = $db->prepare("SELECT id, klienti, data, menyra_e_pageses, koment, pagesa, IFNULL(isCylinder, '0') AS isCylinder FROM distribuimi WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || strtolower(trim($row['menyra_e_pageses'] ?? '')) !== 'bank') {
                // Not found in distribuimi or not BANK — try GoDaddy delivery_report
                require_once __DIR__ . '/../config/godaddy.php';
                $gdResult = callGoDaddyAPI(['action' => 'fetch_by_id', 'id' => $id]);

                if ($gdResult && ($gdResult['success'] ?? false)) {
                    $gdRow = $gdResult['row'];
                    $gdPayment = strtoupper(trim($gdRow['PaymentMethod'] ?? ''));
                    if ($gdPayment === 'BANK') {
                        $sourceTable = 'delivery_report';
                        $row = [
                            'id'                => (int)$gdRow['ID'],
                            'klienti'           => $gdRow['Client'],
                            'data'              => $gdRow['Date'] ?? '',
                            'menyra_e_pageses'  => $gdRow['PaymentMethod'],
                            'koment'            => $gdRow['Comment'] ?? '',
                            'pagesa'            => $gdRow['TotalPrice'] ?? '0',
                            'isCylinder'        => $gdRow['isCylinder'] ?? '0',
                        ];
                    }
                }
            }

            if (!$row) {
                echo json_encode(['status' => '0', 'message' => 'Transaction not found (ID: ' . $id . ')']);
                return;
            }

            $currentPayment = strtolower(trim($row['menyra_e_pageses'] ?? ''));
            if ($currentPayment !== 'bank') {
                echo json_encode(['status' => '0', 'message' => 'Transaction is not bank (debt). Cannot collect. Current status: ' . $row['menyra_e_pageses']]);
                return;
            }

            $newPayment = 'cash';

            // Check for existing request (pending OR approved) to prevent duplicates
            $pendingCheck = $db->prepare("SELECT id FROM pending_borxh WHERE distribuimi_id = ? AND source_table = ? AND new_menyra_e_pageses = ? AND status IN ('pending', 'approved')");
            $pendingCheck->execute([$id, $sourceTable, $newPayment]);
            if ($pendingCheck->fetch()) {
                echo json_encode(['status' => '0', 'message' => 'Ky transaksion eshte mbledhur tashme.']);
                return;
            }

            // INSERT into pending_borxh
            $isCylinder = $row['isCylinder'] ?? '0';
            $insertStmt = $db->prepare("INSERT INTO pending_borxh (distribuimi_id, source_table, klienti, old_menyra_e_pageses, new_menyra_e_pageses, pagesa, data_e_shitjes, koment, requested_by, isCylinder) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->execute([
                $id,
                $sourceTable,
                $row['klienti'],
                $row['menyra_e_pageses'],
                $newPayment,
                $row['pagesa'],
                $row['data'],
                $komentForPending,
                $userName,
                $isCylinder
            ]);

            echo json_encode([
                'status'  => '1',
                'message' => 'Kerkesa u dergua per miratim. Prisni aprovimin nga admini.',
                'data'    => [
                    'id'                    => $id,
                    'klienti'               => $row['klienti'],
                    'old_menyra_e_pageses'  => $row['menyra_e_pageses'],
                    'new_menyra_e_pageses'  => $newPayment,
                    'pending'               => true,
                ],
            ], JSON_UNESCAPED_UNICODE);
        }

    } catch (Exception $e) {
        error_log('UpdateBorxhiStatus error: ' . $e->getMessage());
        echo json_encode(['status' => '0', 'message' => 'Server error during update']);
    }
}

/**
 * GetPendingBorxh — Returns list of pending borxh approval requests.
 * Method: GET
 * Query params: status (optional, default 'pending'), limit (optional, default 100)
 */
function handleGetPendingBorxh($db) {
    $status = $_GET['status'] ?? 'pending';
    $limit  = min((int)($_GET['limit'] ?? 100), 500);

    // Validate status
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'])) {
        $status = 'pending';
    }

    $sql = "SELECT p.*, d.sasia, d.litra, d.cmimi, d.menyra_e_pageses AS current_payment
            FROM pending_borxh p
            LEFT JOIN distribuimi d ON d.id = p.distribuimi_id AND p.source_table = 'distribuimi'";

    $params = [];
    if ($status !== 'all') {
        $sql .= " WHERE p.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY p.requested_at DESC LIMIT " . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count pending for badge
    $countStmt = $db->query("SELECT COUNT(*) FROM pending_borxh WHERE status = 'pending'");
    $pendingCount = (int)$countStmt->fetchColumn();

    echo json_encode([
        'status'        => '1',
        'message'       => count($items) . ' kerkesa u gjeten',
        'pending_count' => $pendingCount,
        'data'          => $items,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * ApproveBorxh — Approve or reject a pending borxh request.
 * On approve: performs the actual distribuimi update + changelog logging.
 * On reject: marks the pending request as rejected.
 *
 * Method: POST (JSON body)
 * Body: { "pending_id": 123, "decision": "approve" | "reject", "user": "admin name", "reason": "optional for reject" }
 */
function handleApproveBorxh($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => '0', 'message' => 'POST method required']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        echo json_encode(['status' => '0', 'message' => 'Invalid JSON body']);
        return;
    }

    $pendingId = isset($body['pending_id']) ? (int)$body['pending_id'] : 0;
    $decision  = $body['decision'] ?? '';
    $adminUser = isset($body['user']) ? trim($body['user']) : '';
    $reason    = isset($body['reason']) ? trim($body['reason']) : '';

    if ($pendingId <= 0) {
        echo json_encode(['status' => '0', 'message' => 'Valid pending_id required']);
        return;
    }
    if (!in_array($decision, ['approve', 'reject'])) {
        echo json_encode(['status' => '0', 'message' => 'Invalid decision. Use approve or reject']);
        return;
    }

    try {
        $db->beginTransaction();

        // Fetch the pending request (lock it)
        $pStmt = $db->prepare("SELECT * FROM pending_borxh WHERE id = ? FOR UPDATE");
        $pStmt->execute([$pendingId]);
        $pending = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$pending) {
            $db->rollBack();
            echo json_encode(['status' => '0', 'message' => 'Pending request not found (ID: ' . $pendingId . ')']);
            return;
        }

        if ($pending['status'] !== 'pending') {
            $db->rollBack();
            echo json_encode(['status' => '0', 'message' => 'Request has already been ' . $pending['status']]);
            return;
        }

        if ($decision === 'reject') {
            // Just mark as rejected
            $nowBelgrade = date('Y-m-d H:i:s');
            $rejectStmt = $db->prepare("UPDATE pending_borxh SET status = 'rejected', approved_by = ?, approved_at = ?, reject_reason = ? WHERE id = ?");
            $rejectStmt->execute([$adminUser, $nowBelgrade, $reason, $pendingId]);
            $db->commit();

            echo json_encode([
                'status'  => '1',
                'message' => 'Kerkesa u refuzua',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // APPROVE — branch based on source_table
        $distId      = (int)$pending['distribuimi_id'];
        $sourceTable = $pending['source_table'] ?? 'distribuimi';
        $newPayment  = $pending['new_menyra_e_pageses'];

        if ($sourceTable === 'delivery_report') {
            // ═══════════════════════════════════════════════════════════════
            // LEJE BORXHIN APPROVAL — update GoDaddy's delivery_report
            // ═══════════════════════════════════════════════════════════════
            require_once __DIR__ . '/../config/godaddy.php';

            // Re-validate current state from GoDaddy
            $gdResult = callGoDaddyAPI(['action' => 'fetch_by_id', 'id' => $distId]);
            if (!$gdResult || !($gdResult['success'] ?? false)) {
                $db->rollBack();
                echo json_encode(['status' => '0', 'message' => 'Cannot reach GoDaddy to verify record. Try again later.']);
                return;
            }

            $gdRow = $gdResult['row'];
            $currentPayment = strtoupper(trim($gdRow['PaymentMethod'] ?? ''));

            // Validate current state matches expected direction
            // Leje (CASH→BANK): current must be CASH, not already BANK
            // Merr (BANK→CASH): current must be BANK, not already CASH
            if ($currentPayment === strtoupper($newPayment)) {
                $db->rollBack();
                echo json_encode(['status' => '0', 'message' => 'Record is already ' . $currentPayment . ' on GoDaddy. No change needed.']);
                return;
            }

            // Call GoDaddy to update PaymentMethod
            $updateResult = callGoDaddyAPI([
                'action'      => 'update_payment',
                'id'          => $distId,
                'new_payment' => $newPayment,
            ]);

            if (!$updateResult || !($updateResult['success'] ?? false)) {
                $db->rollBack();
                $error = $updateResult['error'] ?? 'Unknown error';
                echo json_encode(['status' => '0', 'message' => 'GoDaddy update failed: ' . $error]);
                return;
            }

            // Store row context for the log page (delivery_report doesn't exist in local DB)
            $contextJson = json_encode([
                'klienti'           => $gdRow['Client'] ?? $pending['klienti'],
                'data'              => $gdRow['Date'] ?? '',
                'pagesa'            => $gdRow['TotalPrice'] ?? '',
                'menyra_e_pageses'  => $newPayment,
            ], JSON_UNESCAPED_UNICODE);
            $ctxStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, username) VALUES ('update', 'delivery_report', ?, '_row_context', NULL, ?, ?)");
            $ctxStmt->execute([$distId, $contextJson, getCurrentUser()]);

            // Log to changelog (table_name = 'delivery_report' for audit trail)
            $logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, username) VALUES ('update', 'delivery_report', ?, 'PaymentMethod', ?, ?, ?)");
            $logStmt->execute([$distId, $gdRow['PaymentMethod'], $newPayment, getCurrentUser()]);

            // Log approval action
            $approvalLog = 'Approved by ' . $adminUser . ', requested by ' . ($pending['requested_by'] ?? 'unknown');
            $userLogStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, username) VALUES ('update', 'delivery_report', ?, 'borxh_koment', ?, ?, ?)");
            $userLogStmt->execute([$distId, $pending['requested_by'] ?? '', $approvalLog, getCurrentUser()]);

            // Sync local distribuimi row if it was already imported (godaddy_id = delivery_report ID)
            $localSync = $db->prepare("UPDATE distribuimi SET menyra_e_pageses = ? WHERE godaddy_id = ?");
            $localSync->execute([$newPayment, $distId]);

            // Mark pending request as approved (use PHP date, not MySQL NOW(), to match Belgrade timezone)
            $nowBelgrade = date('Y-m-d H:i:s');
            $approveStmt = $db->prepare("UPDATE pending_borxh SET status = 'approved', approved_by = ?, approved_at = ? WHERE id = ?");
            $approveStmt->execute([$adminUser, $nowBelgrade, $pendingId]);

            $db->commit();

            echo json_encode([
                'status'  => '1',
                'message' => 'Borxhi u miratua — PaymentMethod u ndryshua ne GoDaddy',
                'data'    => [
                    'id'                    => $distId,
                    'klienti'               => $pending['klienti'],
                    'old_menyra_e_pageses'  => $gdRow['PaymentMethod'],
                    'new_menyra_e_pageses'  => $newPayment,
                ],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // ═══════════════════════════════════════════════════════════════
        // MERR BORXHIN APPROVAL — update distribuimi (UNCHANGED logic)
        // ═══════════════════════════════════════════════════════════════

        // Fetch current distribuimi row (locked)
        $dStmt = $db->prepare("SELECT id, klienti, menyra_e_pageses, koment FROM distribuimi WHERE id = ? FOR UPDATE");
        $dStmt->execute([$distId]);
        $row = $dStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $db->rollBack();
            echo json_encode(['status' => '0', 'message' => 'Original transaction not found (distribuimi ID: ' . $distId . ')']);
            return;
        }

        $currentPayment = strtolower(trim($row['menyra_e_pageses'] ?? ''));
        $currentKoment  = $row['koment'] ?? '';
        $action         = ($newPayment === 'bank') ? 'register_borxh' : 'collect_borxh';

        // Re-validate state (row may have changed since the request was made)
        if ($action === 'register_borxh' && $currentPayment === 'bank') {
            $db->rollBack();
            echo json_encode(['status' => '0', 'message' => 'Transaction is already bank. State has changed since request.']);
            return;
        }
        if ($action === 'collect_borxh' && $currentPayment !== 'bank') {
            $db->rollBack();
            echo json_encode(['status' => '0', 'message' => 'Transaction is no longer bank. State has changed since request.']);
            return;
        }

        // Build new koment
        if ($action === 'register_borxh') {
            $newKoment = trim($currentKoment) !== '' ? $currentKoment . ' - borxh' : 'borxh';
        } else {
            $newKoment = preg_replace('/\s*-?\s*borxh(\s*\|.*)?$/i', '', $currentKoment);
            $newKoment = trim($newKoment);
        }

        // Store the requester's comment + approver in borxh_koment (separate column)
        $reqComment = $pending['koment'] ?? '';
        $borxhKoment = '';
        if ($reqComment !== '') {
            $borxhKoment = ($pending['requested_by'] ?? '') . ': ' . $reqComment
                         . ' (aprovuar nga ' . $adminUser . ')';
        }

        // UPDATE distribuimi
        $updateStmt = $db->prepare("UPDATE distribuimi SET menyra_e_pageses = ?, koment = ?, borxh_koment = ? WHERE id = ?");
        $updateStmt->execute([$newPayment, $newKoment, $borxhKoment ?: null, $distId]);

        // Log to changelog — payment method change
        $logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, username) VALUES ('update', 'distribuimi', ?, 'menyra_e_pageses', ?, ?, ?)");
        $logStmt->execute([$distId, $row['menyra_e_pageses'], $newPayment, getCurrentUser()]);

        // Log to changelog — comment change
        $logStmt2 = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, username) VALUES ('update', 'distribuimi', ?, 'koment', ?, ?, ?)");
        $logStmt2->execute([$distId, $currentKoment, $newKoment, getCurrentUser()]);

        // Log approval action
        $approvalLog = 'Approved by ' . $adminUser . ', requested by ' . ($pending['requested_by'] ?? 'unknown');
        $userLogStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, username) VALUES ('update', 'distribuimi', ?, 'borxh_koment', ?, ?, ?)");
        $userLogStmt->execute([$distId, $pending['requested_by'] ?? '', $approvalLog, getCurrentUser()]);

        // Mark pending request as approved (use PHP date, not MySQL NOW(), to match Belgrade timezone)
        $nowBelgrade = date('Y-m-d H:i:s');
        $approveStmt = $db->prepare("UPDATE pending_borxh SET status = 'approved', approved_by = ?, approved_at = ? WHERE id = ?");
        $approveStmt->execute([$adminUser, $nowBelgrade, $pendingId]);

        $db->commit();

        echo json_encode([
            'status'  => '1',
            'message' => $action === 'register_borxh' ? 'Borxhi u miratua dhe u regjistrua' : 'Borxhi u miratua dhe u mblodh',
            'data'    => [
                'id'                    => $distId,
                'klienti'               => $row['klienti'],
                'old_menyra_e_pageses'  => $row['menyra_e_pageses'],
                'new_menyra_e_pageses'  => $newPayment,
            ],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $db->rollBack();
        error_log('ApproveBorxh error: ' . $e->getMessage());
        echo json_encode(['status' => '0', 'message' => 'Server error during approval']);
    }
}


/**
 * getSalesLastReport — Returns product sales data for the Android Sales Report screen.
 *
 * Reads from shitje_produkteve table, maps DB column names to Android POJO field names.
 * Returns payment totals (cash, bank, no_payment) and individual transaction rows.
 *
 * Expected by Android (SalesReportPojo):
 * {
 *   "status": "1",
 *   "stock_loaded": "994",
 *   "cash_payment": "450.00",
 *   "bank_payment": "280.00",
 *   "no_payment": "10.50",
 *   "data": [{ "ID": "1", "pro_name": "...", "ClientName": "...", ... }]
 * }
 */
function handleGetSalesLastReport($db) {
    $stmt = $db->query("
        SELECT id, data, cilindra_sasia, produkti, klienti, cmimi, totali,
               menyra_pageses, koment, statusi_i_pageses, created_at
        FROM shitje_produkteve
        ORDER BY data DESC, id DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cashTotal = 0;
    $bankTotal = 0;
    $noPaymentTotal = 0;
    $data = [];

    foreach ($rows as $r) {
        $total = (float)($r['totali'] ?? 0);
        $payment = strtolower(trim($r['menyra_pageses'] ?? ''));

        // Aggregate by payment method
        if (strpos($payment, 'cash') !== false) {
            $cashTotal += $total;
        } elseif (strpos($payment, 'bank') !== false) {
            $bankTotal += $total;
        } else {
            $noPaymentTotal += $total;
        }

        // Map DB fields → Android Datum POJO fields
        $data[] = [
            'Timestamp'         => $r['created_at'] ?? '',
            'ID'                => (string)$r['id'],
            'pro_name'          => $r['produkti'] ?? '',
            'category'          => '',
            'ClientName'        => $r['klienti'] ?? '',
            'UnitPrice'         => number_format((float)($r['cmimi'] ?? 0), 2, '.', ''),
            'TransactionStatus' => $r['statusi_i_pageses'] ?? '',
            'PaymentMethod'     => strtoupper($r['menyra_pageses'] ?? ''),
            'Comment'           => $r['koment'] ?? '',
            'Distributor'       => '',
            'Time'              => $r['data'] ?? '',
            'initial_stock'     => (string)(int)($r['cilindra_sasia'] ?? 0),
        ];
    }

    echo json_encode([
        'status'       => '1',
        'message'      => count($data) . ' sales records found',
        'stock_loaded' => (string)count($data),
        'cash_payment' => number_format($cashTotal, 2, '.', ''),
        'bank_payment' => number_format($bankTotal, 2, '.', ''),
        'no_payment'   => number_format($noPaymentTotal, 2, '.', ''),
        'data'         => $data,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * InsertProductSale — Inserts a product sale into shitje_produkteve table
 *
 * Called by the Client App's "Sell Product" screen when driver presses SELL.
 * Maps the app's fields to the dashboard's shitje_produkteve columns.
 *
 * Parameters (GET):
 *   pro_name       — Product name           → produkti
 *   ClientName     — Client name            → klienti
 *   initial_stock  — Quantity               → cilindra_sasia
 *   UnitPrice      — Price per unit         → cmimi
 *   PaymentMethod  — CASH/BANK/NO PAYMENT   → menyra_pageses
 *   Comment        — Comment                → koment
 *   Time           — Date/time              → data
 *   category       — Category (unused but accepted)
 *   TransactionStatus — Status              → statusi_i_pageses
 *
 * Returns: { "status": "1", "message": "Product sale inserted successfully", "id": "123" }
 */
function handleInsertProductSale($db) {
    // Auto-sync DISABLED — dashboard gets product sales via "Merr nga GoDaddy" only
    echo json_encode(['status' => '1', 'message' => 'Product sale auto-sync disabled (manual import only)', 'id' => '0']);
    return;

    /* --- Original auto-sync code (disabled) ---
    $produkti         = $_GET['pro_name'] ?? '';
    $klienti          = $_GET['ClientName'] ?? '';
    $cilindra_sasia   = (int)($_GET['initial_stock'] ?? 0);
    $cmimi            = (float)($_GET['UnitPrice'] ?? 0);
    $menyra_pageses   = $_GET['PaymentMethod'] ?? '';
    $koment           = $_GET['Comment'] ?? '';
    $timeStr          = $_GET['Time'] ?? '';
    $statusi          = $_GET['TransactionStatus'] ?? '';

    // Validate required fields
    if (empty($produkti) || empty($klienti)) {
        echo json_encode(['status' => '0', 'message' => 'Product name and client name are required']);
        return;
    }

    // Parse date from the app's format (MM/dd/yyyy HH:mm:ss) to database format (YYYY-MM-DD)
    $data = null;
    if (!empty($timeStr)) {
        $parsed = DateTime::createFromFormat('m/d/Y H:i:s', $timeStr);
        if ($parsed) {
            $data = $parsed->format('Y-m-d');
        } else {
            $parsed = DateTime::createFromFormat('Y-m-d', $timeStr);
            if ($parsed) {
                $data = $parsed->format('Y-m-d');
            } else {
                $data = date('Y-m-d');
            }
        }
    } else {
        $data = date('Y-m-d');
    }

    // Calculate total
    $totali = $cmimi * $cilindra_sasia;

    $stmt = $db->prepare("
        INSERT INTO shitje_produkteve
            (data, cilindra_sasia, produkti, klienti, cmimi, totali, menyra_pageses, koment, statusi_i_pageses)
        VALUES
            (:data, :cilindra_sasia, :produkti, :klienti, :cmimi, :totali, :menyra_pageses, :koment, :statusi)
    ");

    $stmt->execute([
        ':data'            => $data,
        ':cilindra_sasia'  => $cilindra_sasia,
        ':produkti'        => $produkti,
        ':klienti'         => $klienti,
        ':cmimi'           => $cmimi,
        ':totali'          => $totali,
        ':menyra_pageses'  => $menyra_pageses,
        ':koment'          => $koment,
        ':statusi'         => $statusi,
    ]);

    $newId = $db->lastInsertId();

    echo json_encode([
        'status'  => '1',
        'message' => 'Product sale inserted successfully',
        'id'      => (string)$newId,
    ]);
    --- End of disabled auto-sync code --- */
}

/**
 * GetBocaPerKlient — Cylinders in the field per client (READ-ONLY)
 *
 * Calculates: SUM(sasia) - SUM(boca_te_kthyera) per client from distribuimi table.
 * Same formula used on dashboard homepage, distribuimi page, and kontrata page.
 *
 * Params (GET, all optional):
 *   client — partial match filter on client name
 *
 * Response: { status, message, totals: { boca_total_ne_terren, total_clients }, data: [{ klienti, boca }] }
 */
function handleGetBocaPerKlient($db) {
    $client = !empty($_GET['client']) ? trim($_GET['client']) : '';

    // Build WHERE clause for optional client filter
    $where = [];
    $params = [];

    if ($client !== '') {
        $where[] = 'LOWER(TRIM(klienti)) LIKE ?';
        $params[] = '%' . strtolower(trim($client)) . '%';
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Per-client cylinder count (READ-ONLY)
    $sql = "
        SELECT
            MIN(klienti) AS klienti,
            SUM(sasia) - SUM(boca_te_kthyera) AS boca
        FROM distribuimi
        {$whereSQL}
        GROUP BY LOWER(klienti)
        HAVING boca != 0
        ORDER BY MIN(klienti)
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Global total — same formula as dashboard index.php
    $globalStmt = $db->query("SELECT COALESCE(SUM(sasia) - SUM(boca_te_kthyera), 0) FROM distribuimi");
    $bocaTotalNeTerren = (int)$globalStmt->fetchColumn();

    // Build response
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'klienti' => $r['klienti'],
            'boca'    => (string)(int)$r['boca'],
        ];
    }

    echo json_encode([
        'status'  => '1',
        'message' => count($data) . ' kliente me boca ne terren',
        'totals'  => [
            'boca_total_ne_terren' => (string)$bocaTotalNeTerren,
            'total_clients'        => (string)count($data),
        ],
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * GetNxemesePerKlient — Heaters in the field per client (READ-ONLY)
 *
 * Calculates heater stock per client directly from GoDaddy's delivery_report
 * (isCylinder=2). This is the single source of truth — no separate nxemese
 * table needed, and data stays consistent with GoDaddy DB imports.
 *
 * Formula: SUM(DeliveredCylinders) - SUM(ReturnedCylinders) per Client
 *
 * Params (GET, all optional):
 *   client — partial match filter on client name
 *
 * Response: { status, message, totals: { nxemese_total_ne_terren, total_clients }, data: [{ klienti, ne_terren }] }
 */
function handleGetNxemesePerKlient($db) {
    $client = !empty($_GET['client']) ? trim($_GET['client']) : '';

    // Primary: GoDaddy (always up to date from app deliveries)
    // Fallback: local nxemese table (updated via "Merr nga GoDaddy")
    $godaddyUrl = 'http://testing.darn-group.com/new_api_action.php?GetNxemeseStock';
    if ($client !== '') {
        $godaddyUrl .= '&client=' . urlencode($client);
    }

    $ch = curl_init($godaddyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || !$response) {
        handleGetNxemesePerKlientFallback($db, $client);
        return;
    }

    $godaddyData = json_decode($response, true);
    if (!$godaddyData || $godaddyData['status'] !== '1') {
        handleGetNxemesePerKlientFallback($db, $client);
        return;
    }

    $rows = $godaddyData['data'] ?? [];
    $nxemeseTotalNeTerren = (int)($godaddyData['totals']['nxemese_total_ne_terren'] ?? 0);

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'klienti'   => $r['klienti'],
            'ne_terren' => (string)(int)$r['ne_terren'],
        ];
    }
    echo json_encode([
        'status'  => '1',
        'message' => count($data) . ' kliente me nxemese ne terren',
        'totals'  => [
            'nxemese_total_ne_terren' => (string)$nxemeseTotalNeTerren,
            'total_clients'           => (string)count($data),
        ],
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
}


/**
 * Fallback: read from local nxemese table if GoDaddy DB connection fails
 */
function handleGetNxemesePerKlientFallback($db, $client) {
    $where = [];
    $params = [];
    if ($client !== '') {
        $where[] = 'LOWER(TRIM(klienti)) LIKE ?';
        $params[] = '%' . strtolower(trim($client)) . '%';
    }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT MIN(klienti) AS klienti, SUM(te_dhena) - SUM(te_marra) AS ne_terren FROM nxemese {$whereSQL} GROUP BY LOWER(klienti) HAVING ne_terren != 0 ORDER BY MIN(klienti)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $globalStmt = $db->query("SELECT COALESCE(SUM(te_dhena) - SUM(te_marra), 0) FROM nxemese");
    $nxemeseTotalNeTerren = (int)$globalStmt->fetchColumn();
    $data = [];
    foreach ($rows as $r) {
        $data[] = ['klienti' => $r['klienti'], 'ne_terren' => (string)(int)$r['ne_terren']];
    }
    echo json_encode([
        'status' => '1', 'message' => count($data) . ' kliente (fallback nga nxemese tabela)',
        'totals' => ['nxemese_total_ne_terren' => (string)$nxemeseTotalNeTerren, 'total_clients' => (string)count($data)],
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * SearchARBK — Proxy for ARBK business lookup via arbk.micro-devs.com
 *
 * Parameters:
 *   search_type = name | nui | fiscal
 *   search_term = the search query
 *
 * Returns: { status, message, data: [{ emri, emri_tregtar, nui, komuna, lloji_biznesit, statusi }] }
 */
function handleSearchARBK() {
    $searchType = trim($_GET['search_type'] ?? 'name');
    $searchTerm = trim($_GET['search_term'] ?? '');

    if ($searchTerm === '') {
        echo json_encode(['status' => '0', 'message' => 'search_term is required']);
        return;
    }

    // Map search_type to micro-devs API endpoint + parameter
    $typeMap = [
        'name'   => ['endpoint' => 'search-by-name',          'param' => 'name'],
        'nui'    => ['endpoint' => 'search-by-nui',            'param' => 'nui'],
        'fiscal' => ['endpoint' => 'search-by-fiscal-number',  'param' => 'fiscal_number'],
    ];

    if (!isset($typeMap[$searchType])) {
        echo json_encode(['status' => '0', 'message' => 'Invalid search_type. Use: name, nui, or fiscal']);
        return;
    }

    $arbkApiKey = getenv('ARBK_API_KEY') ?: 'arbk_nMNlAR8dh7JGcbVHSUmeEJc5VtnILla1wdDMKx5OBzD4ndioutghSK9vwLsc';
    if ($arbkApiKey === '') {
        echo json_encode(['status' => '0', 'message' => 'ARBK API key not configured on server']);
        return;
    }

    $endpoint = $typeMap[$searchType]['endpoint'];
    $param    = $typeMap[$searchType]['param'];
    $url      = 'https://arbk.micro-devs.com/api/v1/business/' . $endpoint . '?' . $param . '=' . urlencode($searchTerm);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $arbkApiKey,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        $errMsg = $curlError ?: "ARBK API returned HTTP $httpCode";
        error_log("SearchARBK error: $errMsg (url: $url)");
        echo json_encode(['status' => '0', 'message' => 'ARBK service unavailable: ' . $errMsg]);
        return;
    }

    $arbkData = json_decode($response, true);
    if (!is_array($arbkData)) {
        echo json_encode(['status' => '0', 'message' => 'Invalid response from ARBK API']);
        return;
    }

    // Normalize: micro-devs API response structure:
    //   { success, count, data: [{ business_name, trade_name, unique_identification_number,
    //     fiscal_number, status (bool), data: { teDhenatBiznesit: { NUI, Komuna, Adresa,
    //     Telefoni, Email, LlojiBiznesit, StatusiARBK, ... }, perfaqesuesit: [...] } }] }
    $results = [];
    $items = $arbkData['data'] ?? [];
    if (!is_array($items)) $items = [];
    // If single result, wrap in array
    if (!empty($items) && !isset($items[0])) $items = [$items];

    foreach ($items as $item) {
        if (!is_array($item)) continue;

        $details = $item['data']['teDhenatBiznesit'] ?? [];
        $reps    = $item['data']['perfaqesuesit'] ?? [];

        // Get representative name (first one)
        $repName = '';
        if (!empty($reps) && is_array($reps[0])) {
            $repName = trim(($reps[0]['Emri'] ?? '') . ' ' . ($reps[0]['Mbiemri'] ?? ''));
        }

        // Get NUI from nested data (more reliable) or top-level
        $nui = $details['NUI'] ?? $item['unique_identification_number'] ?? '';

        // Get status text from nested data (e.g., "Regjistruar", "Shuar-05/03/2018")
        $statusText = $details['StatusiARBK'] ?? '';
        if ($statusText === '' && isset($item['status'])) {
            $statusText = $item['status'] ? 'Aktiv' : 'Joaktiv';
        }

        $results[] = [
            'emri'            => $item['business_name'] ?? $details['EmriBiznesit'] ?? '',
            'emri_tregtar'    => $item['trade_name'] ?? $details['EmriTregtar'] ?? '',
            'nui'             => $nui,
            'komuna'          => $details['Komuna'] ?? '',
            'lloji_biznesit'  => $details['LlojiBiznesit'] ?? '',
            'statusi'         => $statusText,
            'adresa'          => $details['Adresa'] ?? '',
            'telefoni'        => $details['Telefoni'] ?? '',
            'email'           => $details['Email'] ?? '',
            'numri_fiskal'    => $details['NumriFiskal'] ?? $item['fiscal_number'] ?? '',
            'perfaqesuesi'    => $repName,
        ];
    }

    echo json_encode([
        'status'  => '1',
        'message' => count($results) . ' results found',
        'data'    => $results,
    ], JSON_UNESCAPED_UNICODE);
}


/**
 * RegisterContract — Save full contract data to kontrata + klientet tables
 *
 * POST body (JSON):
 *   biznesi, name_from_database, numri_unik, qyteti, rruga,
 *   perfaqesuesi, nr_telefonit, email, bashkepunim, koment
 *
 * Smart UPSERT for klientet: only updates NULL/empty fields, never overwrites existing data.
 */
function handleRegisterContract($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => '0', 'message' => 'POST method required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['status' => '0', 'message' => 'Invalid JSON body']);
        return;
    }

    $biznesi           = trim($input['biznesi'] ?? '');
    $nameFromDb        = trim($input['name_from_database'] ?? '');
    $numriUnik         = trim($input['numri_unik'] ?? '');
    $qyteti            = trim($input['qyteti'] ?? '');
    $rruga             = trim($input['rruga'] ?? '');
    $perfaqesuesi      = trim($input['perfaqesuesi'] ?? '');
    $nrTelefonit       = trim($input['nr_telefonit'] ?? '');
    $email             = trim($input['email'] ?? '');
    $bashkepunim       = trim($input['bashkepunim'] ?? 'Po');
    $koment            = trim($input['koment'] ?? '');

    // At least one name field is required
    if ($biznesi === '' && $nameFromDb === '') {
        echo json_encode(['status' => '0', 'message' => 'biznesi or name_from_database is required']);
        return;
    }

    // If one is empty, use the other
    if ($nameFromDb === '') $nameFromDb = $biznesi;
    if ($biznesi === '') $biznesi = $nameFromDb;

    $db->beginTransaction();

    try {
        // 1. UPSERT into kontrata — update existing or insert new
        $checkKontrata = $db->prepare("SELECT id FROM kontrata WHERE LOWER(TRIM(name_from_database)) = LOWER(TRIM(?)) LIMIT 1");
        $checkKontrata->execute([$nameFromDb]);
        $existingKontrata = $checkKontrata->fetch(PDO::FETCH_ASSOC);

        if ($existingKontrata) {
            // UPDATE existing kontrata — only set non-empty fields
            $sets = [];
            $vals = [];
            $fields = [
                'biznesi' => $biznesi, 'numri_unik' => $numriUnik,
                'qyteti' => $qyteti, 'rruga' => $rruga,
                'perfaqesuesi' => $perfaqesuesi, 'nr_telefonit' => $nrTelefonit,
                'email' => $email, 'bashkepunim' => $bashkepunim, 'koment' => $koment,
            ];
            foreach ($fields as $col => $val) {
                if ($val !== '') {
                    $sets[] = "$col = ?";
                    $vals[] = $val;
                }
            }
            if (!empty($sets)) {
                $vals[] = $existingKontrata['id'];
                $db->prepare("UPDATE kontrata SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            }
            $contractId = $existingKontrata['id'];
        } else {
            // INSERT new kontrata row
            $stmt = $db->prepare("
                INSERT INTO kontrata
                    (biznesi, name_from_database, numri_unik, qyteti, rruga,
                     perfaqesuesi, nr_telefonit, email, bashkepunim, koment, data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
            ");
            $stmt->execute([
                $biznesi, $nameFromDb, $numriUnik, $qyteti, $rruga,
                $perfaqesuesi, $nrTelefonit, $email, $bashkepunim, $koment,
            ]);
            $contractId = $db->lastInsertId();
        }

        // 2. SMART UPSERT into klientet — only fill empty/NULL fields, never overwrite existing data
        $adresa = trim($qyteti . ($rruga ? ', ' . $rruga : ''));

        // Check if client already exists
        $checkStmt = $db->prepare("SELECT id FROM klientet WHERE LOWER(TRIM(emri)) = LOWER(TRIM(?))");
        $checkStmt->execute([$nameFromDb]);
        $existingClient = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingClient) {
            // UPDATE only NULL or empty fields — never overwrite existing data
            $updateStmt = $db->prepare("
                UPDATE klientet SET
                    numri_unik_identifikues = CASE WHEN (numri_unik_identifikues IS NULL OR TRIM(numri_unik_identifikues) = '') THEN ? ELSE numri_unik_identifikues END,
                    adresa                  = CASE WHEN (adresa IS NULL OR TRIM(adresa) = '') THEN ? ELSE adresa END,
                    telefoni                = CASE WHEN (telefoni IS NULL OR TRIM(telefoni) = '') THEN ? ELSE telefoni END,
                    email                   = CASE WHEN (email IS NULL OR TRIM(email) = '') THEN ? ELSE email END,
                    kontakti                = CASE WHEN (kontakti IS NULL OR TRIM(kontakti) = '') THEN ? ELSE kontakti END,
                    bashkepunim             = CASE WHEN (bashkepunim IS NULL OR TRIM(bashkepunim) = '') THEN ? ELSE bashkepunim END,
                    i_regjistruar_ne_emer   = CASE WHEN (i_regjistruar_ne_emer IS NULL OR TRIM(i_regjistruar_ne_emer) = '') THEN ? ELSE i_regjistruar_ne_emer END,
                    data_e_kontrates        = CASE WHEN data_e_kontrates IS NULL THEN CURDATE() ELSE data_e_kontrates END
                WHERE id = ?
            ");
            $updateStmt->execute([
                $numriUnik, $adresa, $nrTelefonit, $email,
                $perfaqesuesi, $bashkepunim, $biznesi,
                $existingClient['id'],
            ]);
        } else {
            // INSERT new client
            $insertStmt = $db->prepare("
                INSERT INTO klientet
                    (emri, numri_unik_identifikues, adresa, telefoni, email,
                     kontakti, bashkepunim, i_regjistruar_ne_emer, data_e_kontrates)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
            ");
            $insertStmt->execute([
                $nameFromDb, $numriUnik, $adresa, $nrTelefonit, $email,
                $perfaqesuesi, $bashkepunim, $biznesi,
            ]);
        }

        $db->commit();

        echo json_encode([
            'status'      => '1',
            'message'     => 'Contract registered successfully',
            'contract_id' => (string)$contractId,
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        error_log('RegisterContract error: ' . $e->getMessage());
        echo json_encode(['status' => '0', 'message' => 'Failed to register contract: ' . $e->getMessage()]);
    }
}


/**
 * GetBorxhCollections — Returns list of approved borxh collection records.
 *
 * Used by Delivery Status screen to show WHO collected borxh from WHICH client.
 * Data comes from pending_borxh table (approved collect_borxh records).
 *
 * ZERO INSERT/UPDATE/DELETE — only SELECT with prepared statements.
 *
 * Params (via GET):
 *   requested_by — (optional) Filter by seller/collector name (for Client App per-user view)
 *   date_from    — (optional) Start date YYYY-MM-DD (filters by approved_at)
 *   date_to      — (optional) End date YYYY-MM-DD (filters by approved_at)
 *   limit        — (optional) Max rows, default 200
 */
function handleGetBorxhCollections($db) {
    $requestedBy = isset($_GET['requested_by']) ? trim($_GET['requested_by']) : '';
    $dateFrom    = !empty($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo      = !empty($_GET['date_to'])   ? $_GET['date_to']   : '';
    $isCylinderFilter = isset($_GET['isCylinder']) ? $_GET['isCylinder'] : '';
    $limit       = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 200;
    if ($limit <= 0) $limit = 200;

    // Only show collections (approved, new payment = cash)
    // For distribuimi source: verify row is still cash (exclude reversed)
    // For delivery_report source: include directly (no distribuimi row to check)
    $where  = ["pb.status = 'approved'", "pb.new_menyra_e_pageses = 'cash'",
               "(pb.source_table = 'delivery_report' OR LOWER(TRIM(d.menyra_e_pageses)) = 'cash')"];
    $params = [];

    // Filter by isCylinder type (0=cylinder, 2=heater)
    if ($isCylinderFilter !== '') {
        $where[]  = "IFNULL(pb.isCylinder, '0') = ?";
        $params[] = $isCylinderFilter;
    }

    // Filter by collector (seller) name
    if ($requestedBy !== '') {
        $where[]  = 'LOWER(TRIM(pb.requested_by)) = ?';
        $params[] = strtolower(trim($requestedBy));
    }

    // Date filters on approval date
    if ($dateFrom !== '') {
        $where[]  = 'pb.approved_at >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]  = 'DATE(pb.approved_at) <= ?';
        $params[] = $dateTo;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT
                pb.id,
                pb.distribuimi_id,
                pb.klienti,
                pb.pagesa,
                pb.data_e_shitjes,
                pb.requested_by,
                pb.requested_at,
                pb.approved_by,
                pb.approved_at,
                pb.koment
            FROM pending_borxh pb
            LEFT JOIN distribuimi d ON d.id = pb.distribuimi_id AND pb.source_table = 'distribuimi'
            {$whereSQL}
            ORDER BY pb.approved_at DESC
            LIMIT {$limit}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the data
    $data = [];
    $totalCollected = 0;
    foreach ($rows as $r) {
        $amount = (float)($r['pagesa'] ?? 0);
        $totalCollected += $amount;
        $data[] = [
            'id'              => (int)$r['id'],
            'distribuimi_id'  => (int)$r['distribuimi_id'],
            'klienti'         => $r['klienti'] ?? '',
            'pagesa'          => number_format($amount, 2, '.', ''),
            'data_e_shitjes'  => $r['data_e_shitjes'] ?? '',
            'requested_by'    => $r['requested_by'] ?? '',
            'requested_at'    => $r['requested_at'] ?? '',
            'approved_by'     => $r['approved_by'] ?? '',
            'approved_at'     => $r['approved_at'] ?? '',
            'koment'          => $r['koment'] ?? '',
        ];
    }

    echo json_encode([
        'status'          => '1',
        'message'         => count($data) . ' borxh collections found',
        'total_collected'  => number_format($totalCollected, 2, '.', ''),
        'data'            => $data,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * SyncDeliveryToDistribuimi — Auto-sync a delivery from GoDaddy to distribuimi.
 *
 * Called by GoDaddy PHP (new_api_action.php) after InsertDeliveryReport succeeds.
 * Inserts a row into distribuimi with godaddy_id for deduplication.
 * Skips heaters (isCylinder=2).
 *
 * POST body (JSON):
 *   godaddy_id, Client, Date, DeliveredCylinders, ReturnedCylinders,
 *   Volume, PricePer1L, TotalPrice, PaymentMethod, Comment, isCylinder
 */
function handleSyncDeliveryToDistribuimi($db) {
    // Auto-sync DISABLED — Lena imports manually via "Merr nga GoDaddy" button.
    // App deliveries should NOT auto-appear in dashboard distribuimi.
    echo json_encode(['status' => '1', 'message' => 'Auto-sync disabled (manual import only)']);
    return;

    /* --- Original auto-sync code (disabled) ---
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => '0', 'message' => 'POST method required']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        echo json_encode(['status' => '0', 'message' => 'Invalid JSON body']);
        return;
    }

    $godaddyId   = (int)($body['godaddy_id'] ?? 0);
    $isCylinder  = trim($body['isCylinder'] ?? '0');

    // Skip heaters — they use nxemese table, not distribuimi
    if ($isCylinder === '2') {
        echo json_encode(['status' => '1', 'message' => 'Heater skipped (uses nxemese table)']);
        return;
    }

    if ($godaddyId <= 0) {
        echo json_encode(['status' => '0', 'message' => 'godaddy_id required']);
        return;
    }

    // Map GoDaddy fields to distribuimi columns (same logic as fetch_godaddy.php mapRow)
    $klienti   = trim($body['Client'] ?? '');
    $data      = trim($body['Date'] ?? date('Y-m-d'));
    $sasia     = (float)($body['DeliveredCylinders'] ?? 0);
    $kthyera   = (float)($body['ReturnedCylinders'] ?? 0);
    $cmimi     = (float)($body['PricePer1L'] ?? 0);
    $pagesa    = (float)($body['TotalPrice'] ?? 0);
    $comment   = trim($body['Comment'] ?? '');

    // Parse Volume: "120.0L" → 120.0 (total liters)
    $volumeTotal = 0;
    $volume = trim($body['Volume'] ?? '');
    if ($volume !== '') {
        $volumeTotal = (float)preg_replace('/[^0-9.\-]/', '', $volume);
    }

    // Convert total volume to per-unit litra (dashboard expects per-unit)
    $litra = ($sasia > 0) ? round($volumeTotal / $sasia, 2) : $volumeTotal;

    // Map payment method
    $payment = strtoupper(trim($body['PaymentMethod'] ?? ''));
    $paymentMap = [
        'CASH' => 'CASH',
        'BANK' => 'BANK',
        'NOPAYMENT' => 'NO PAYMENT',
        'NO PAYMENT' => 'NO PAYMENT',
        'GIFT' => 'DHURATE',
        'DHURATE' => 'DHURATE',
    ];
    $menyra = $paymentMap[$payment] ?? $payment;

    // INSERT with ON DUPLICATE KEY UPDATE (godaddy_id is UNIQUE)
    $stmt = $db->prepare("
        INSERT INTO distribuimi
            (klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa,
             menyra_e_pageses, fatura_e_derguar, litrat_total, litrat_e_konvertuara,
             isCylinder, godaddy_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            klienti = VALUES(klienti),
            data = VALUES(data),
            sasia = VALUES(sasia),
            boca_te_kthyera = VALUES(boca_te_kthyera),
            litra = VALUES(litra),
            cmimi = VALUES(cmimi),
            pagesa = VALUES(pagesa),
            menyra_e_pageses = VALUES(menyra_e_pageses),
            fatura_e_derguar = VALUES(fatura_e_derguar),
            isCylinder = VALUES(isCylinder)
    ");

    $litratTotal = round($volumeTotal, 2);
    $litratKonv  = $litra;

    $stmt->execute([
        $klienti, $data, $sasia, $kthyera, $litra, $cmimi, $pagesa,
        $menyra, $comment, $litratTotal, $litratKonv,
        $isCylinder, $godaddyId
    ]);

    echo json_encode([
        'status'  => '1',
        'message' => 'Synced delivery to distribuimi (godaddy_id=' . $godaddyId . ')',
    ], JSON_UNESCAPED_UNICODE);
    --- End of disabled auto-sync code --- */
}

/**
 * UpdateNxemeseStock — Sync heater delivery/return to nxemese table.
 * Called by GoDaddy PHP after heater delivery or return via Kthe ne Magazine.
 *
 * POST body (JSON):
 *   action:    "deliver" or "return"
 *   klienti:   Client name
 *   sasia:     Quantity (default 1)
 */
function handleUpdateNxemeseStock($db) {
    // Auto-sync DISABLED — dashboard gets nxemese data via "Merr nga GoDaddy" only
    echo json_encode(['status' => '1', 'message' => 'Nxemese auto-sync disabled (manual import only)']);
    return;

    /* --- Original auto-sync code (disabled) ---
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => '0', 'message' => 'POST method required']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        echo json_encode(['status' => '0', 'message' => 'Invalid JSON body']);
        return;
    }

    $action  = $body['action'] ?? '';
    $klienti = trim($body['klienti'] ?? '');
    $sasia   = (int)($body['sasia'] ?? 1);

    if ($klienti === '' || !in_array($action, ['deliver', 'return'])) {
        echo json_encode(['status' => '0', 'message' => 'klienti and action (deliver/return) required']);
        return;
    }

    $today = date('Y-m-d');

    if ($action === 'deliver') {
        // Heater given to client
        $stmt = $db->prepare("INSERT INTO nxemese (klienti, data, te_dhena, te_marra, koment) VALUES (?, ?, ?, 0, 'Auto-sync nga app')");
        $stmt->execute([$klienti, $today, $sasia]);
    } else {
        // Heater returned from client
        $stmt = $db->prepare("INSERT INTO nxemese (klienti, data, te_dhena, te_marra, koment) VALUES (?, ?, 0, ?, 'Auto-sync nga app')");
        $stmt->execute([$klienti, $today, $sasia]);
    }

    echo json_encode([
        'status'  => '1',
        'message' => 'Nxemese stock updated: ' . $action . ' ' . $sasia . ' for ' . $klienti,
    ], JSON_UNESCAPED_UNICODE);
    --- End of disabled auto-sync code --- */
}

