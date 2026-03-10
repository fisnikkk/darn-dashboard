<?php
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
 * Expected by Android:
 * { "status": "1", "data": [{ "Name": "...", "Bussiness": "...", "Email": "...", ... }] }
 */
function handleGetAllClients($db) {
    // Get real client names from distribuimi (primary source — always clean)
    // Then LEFT JOIN klientet for business info (email, address, fiscal number, etc.)
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
    $isType     = $_GET['isType'] ?? '0'; // 0=Cylinder, 1=Product, 2=Both

    if (empty($clientName) || empty($startDate) || empty($endDate)) {
        echo json_encode(['status' => '0', 'message' => 'Missing parameters']);
        return;
    }

    // Auto-sync: fetch latest data from GoDaddy for this date range before querying
    autoSyncFromGoDaddy($db, $startDate, $endDate);

    // Query distribuimi for this client and date range
    $stmt = $db->prepare("
        SELECT id, klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa,
               menyra_e_pageses, fatura_e_derguar, created_at
        FROM distribuimi
        WHERE LOWER(TRIM(klienti)) = LOWER(TRIM(?))
          AND data >= ?
          AND data <= ?
        ORDER BY data ASC, id ASC
    ");
    $stmt->execute([$clientName, $startDate, $endDate]);
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

        $data[] = [
            'Timestamp'          => $r['created_at'] ?? $r['data'] ?? '',
            'Client'             => $r['klienti'] ?? '',
            'pro_name'           => 'GAS I LENGET (L)',
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
            'isCylinder'         => 0,
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

        $insertSQL = "INSERT INTO distribuimi (klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, fatura_e_derguar, litrat_total, litrat_e_konvertuara) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
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
