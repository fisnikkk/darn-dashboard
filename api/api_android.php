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
    } elseif (strpos($query, 'GetBorxhet') !== false) {
        handleGetBorxhet($db);
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

        $row = [
            'klienti'          => $r['klienti'],
            'cash'             => number_format((float)$r['cash'], 2, '.', ''),
            'bank'             => number_format((float)$r['bank'], 2, '.', ''),
            'fature_banke'     => number_format((float)$r['fature_banke'], 2, '.', ''),
            'fature_cash'      => number_format((float)$r['fature_cash'], 2, '.', ''),
            'no_payment'       => number_format((float)$r['no_payment'], 2, '.', ''),
            'dhurate'          => number_format((float)$r['dhurate'], 2, '.', ''),
            'total'            => number_format((float)$r['total'], 2, '.', ''),
            'borxhi_bank_deri' => number_format((float)$r['borxhi_bank_deri'], 2, '.', ''),
            'bashkepunim'      => $r['bashkepunim'] ?? '',
        ];
        $data[] = $row;

        foreach ($totals as $k => &$v) {
            $v += (float)$r[$k];
        }
    }

    // Format totals
    $formattedTotals = [];
    foreach ($totals as $k => $v) {
        $formattedTotals[$k] = number_format($v, 2, '.', '');
    }

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
        $where[] = 'data >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'data <= ?';
        $params[] = $dateTo;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // READ-ONLY SELECT — no data modification
    $sql = "SELECT id, data, sasia, litra, cmimi, pagesa, menyra_e_pageses, koment
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
        ];
    }

    echo json_encode([
        'status'  => '1',
        'message' => count($data) . ' transactions found',
        'data'    => $data,
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
 * Body: { "id": 123, "action": "register_borxh" | "collect_borxh" }
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

    $id     = isset($body['id']) ? (int)$body['id'] : 0;
    $action = $body['action'] ?? '';

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
        $db->beginTransaction();

        // Fetch current row (lock for update)
        $stmt = $db->prepare("SELECT id, klienti, menyra_e_pageses, koment FROM distribuimi WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $db->rollBack();
            echo json_encode(['status' => '0', 'message' => 'Transaction not found (ID: ' . $id . ')']);
            return;
        }

        $currentPayment = strtolower(trim($row['menyra_e_pageses'] ?? ''));
        $currentKoment  = $row['koment'] ?? '';

        // State validation
        if ($action === 'register_borxh') {
            if ($currentPayment === 'bank') {
                $db->rollBack();
                echo json_encode(['status' => '0', 'message' => 'Transaction is already marked as bank (debt). Cannot register again.']);
                return;
            }
            $newPayment = 'bank';
            // Append " - borxh" to comment (preserve existing)
            $newKoment = trim($currentKoment) !== '' ? $currentKoment . ' - borxh' : 'borxh';

        } elseif ($action === 'collect_borxh') {
            if ($currentPayment !== 'bank') {
                $db->rollBack();
                echo json_encode(['status' => '0', 'message' => 'Transaction is not bank (debt). Cannot collect. Current status: ' . $row['menyra_e_pageses']]);
                return;
            }
            $newPayment = 'cash';
            // Remove " - borxh" from comment
            $newKoment = str_replace(' - borxh', '', $currentKoment);
            // Also remove standalone "borxh" if that's all there was
            if (trim($newKoment) === 'borxh') $newKoment = '';
            $newKoment = trim($newKoment);
        }

        // UPDATE only menyra_e_pageses and koment — nothing else
        $updateStmt = $db->prepare("UPDATE distribuimi SET menyra_e_pageses = ?, koment = ? WHERE id = ?");
        $updateStmt->execute([$newPayment, $newKoment, $id]);

        // Log to changelog — payment method change
        $logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('update', 'distribuimi', ?, 'menyra_e_pageses', ?, ?)");
        $logStmt->execute([$id, $row['menyra_e_pageses'], $newPayment]);

        // Log to changelog — comment change
        $logStmt2 = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('update', 'distribuimi', ?, 'koment', ?, ?)");
        $logStmt2->execute([$id, $currentKoment, $newKoment]);

        $db->commit();

        echo json_encode([
            'status'  => '1',
            'message' => $action === 'register_borxh' ? 'Borxhi u regjistrua me sukses' : 'Borxhi u mblodh me sukses',
            'data'    => [
                'id'                    => $id,
                'klienti'               => $row['klienti'],
                'old_menyra_e_pageses'  => $row['menyra_e_pageses'],
                'new_menyra_e_pageses'  => $newPayment,
                'old_koment'            => $currentKoment,
                'new_koment'            => $newKoment,
            ],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $db->rollBack();
        error_log('UpdateBorxhiStatus error: ' . $e->getMessage());
        echo json_encode(['status' => '0', 'message' => 'Server error during update']);
    }
}
