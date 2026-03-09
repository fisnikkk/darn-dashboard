<?php
/**
 * Fetch from GoDaddy's delivery_report by date range (via HTTP proxy)
 *
 * Actions:
 *   action=preview  → Show what would be imported (dry run)
 *   action=import   → Actually insert into distribuimi
 *   action=status   → Check GoDaddy connection
 *
 * Required params for preview/import:
 *   date_from  (YYYY-MM-DD)
 *   date_to    (YYYY-MM-DD)
 */
require_once __DIR__ . '/../config/database.php';
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

    // Check which ones already exist in distribuimi (by matching key fields)
    $mapped = [];
    $duplicates = 0;
    foreach ($gdRows as $row) {
        $m = mapRow($row);

        // Check for duplicate: same klienti + data + sasia + pagesa
        $dup = $db->prepare("SELECT COUNT(*) FROM distribuimi WHERE klienti = ? AND data = ? AND sasia = ? AND pagesa = ?");
        $dup->execute([$m['klienti'], $m['data'], $m['sasia'], $m['pagesa']]);
        $exists = (int)$dup->fetchColumn() > 0;

        $m['_duplicate'] = $exists;
        $m['_godaddy_id'] = (int)($row['ID'] ?? 0);
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

    $db->beginTransaction();
    $inserted = 0;
    $skipped = 0;

    $insertSQL = "INSERT INTO distribuimi (klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, fatura_e_derguar, litrat_total, litrat_e_konvertuara) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $db->prepare($insertSQL);

    foreach ($gdRows as $row) {
        $m = mapRow($row);

        // Skip duplicates: same klienti + data + sasia + pagesa
        $dup = $db->prepare("SELECT COUNT(*) FROM distribuimi WHERE klienti = ? AND data = ? AND sasia = ? AND pagesa = ?");
        $dup->execute([$m['klienti'], $m['data'], $m['sasia'], $m['pagesa']]);
        if ((int)$dup->fetchColumn() > 0) {
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
        ]);
        $newId = $db->lastInsertId();

        // Log to changelog
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('insert', 'distribuimi', ?, 'godaddy_import', NULL, ?)")
            ->execute([(int)$newId, json_encode($m, JSON_UNESCAPED_UNICODE)]);

        $inserted++;
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "U importuan {$inserted} rreshta te reja" . ($skipped ? " ({$skipped} ekzistonin tashme)" : ''),
        'inserted' => $inserted,
        'skipped' => $skipped,
    ]);
}

/**
 * Map a GoDaddy delivery_report row to distribuimi columns
 */
function mapRow($row) {
    // Parse Volume: "120.0L" → 120.0
    $litra = 0;
    $volume = trim($row['Volume'] ?? '');
    if ($volume !== '') {
        $litra = (float)preg_replace('/[^0-9.\-]/', '', $volume);
    }

    $sasia = (float)($row['DeliveredCylinders'] ?? 0);

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
        'litrat_total'         => round($sasia * $litra, 2),
        'litrat_e_konvertuara' => $litra,
    ];
}
