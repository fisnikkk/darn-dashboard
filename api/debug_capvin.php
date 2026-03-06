<?php
/**
 * Debug: Kartela verification for "Capvin 13 Ramiz Sadiku"
 * Temporary diagnostic script - do NOT commit.
 *
 * Checks:
 * 1. All distribuimi rows matching this client (case-insensitive)
 * 2. Breakdown by menyra_e_pageses, including zero-pagesa rows
 * 3. gjendja_bankare rows assigned to this client
 * 4. Kartela totals: Debi, Kredi Cash, Kredi Bank, Gjendja
 */
ini_set("memory_limit", "256M");
require_once __DIR__ . "/../config/database.php";
header("Content-Type: application/json");

try {
    $db = getDB();
    $client = "Capvin 13 Ramiz Sadiku";

    // 1. All distribuimi rows for this client
    $allRows = $db->prepare("
        SELECT id, klienti, data, sasia, litra, cmimi, pagesa,
               menyra_e_pageses, fatura_e_derguar
        FROM distribuimi
        WHERE LOWER(klienti) = LOWER(?)
        ORDER BY data ASC, id ASC
    ");
    $allRows->execute([$client]);
    $rows = $allRows->fetchAll();

    $totalCount = count($rows);
    $totalPagesa = 0.0;
    $zeroRows = [];
    $breakdownByMenyra = [];

    foreach ($rows as $r) {
        $p = (float)$r["pagesa"];
        $m = trim($r["menyra_e_pageses"] ?? "");
        $totalPagesa += $p;

        if (!isset($breakdownByMenyra[$m])) {
            $breakdownByMenyra[$m] = ["count" => 0, "sum_pagesa" => 0.0];
        }
        $breakdownByMenyra[$m]["count"]++;
        $breakdownByMenyra[$m]["sum_pagesa"] += $p;

        if ($p == 0) {
            $zeroRows[] = [
                "id"               => (int)$r["id"],
                "data"             => $r["data"],
                "sasia"            => $r["sasia"],
                "litra"            => $r["litra"],
                "cmimi"            => $r["cmimi"],
                "pagesa"           => $p,
                "menyra_e_pageses" => $m,
            ];
        }
    }

    $breakdownFormatted = [];
    foreach ($breakdownByMenyra as $m => $info) {
        $breakdownFormatted[] = [
            "menyra_e_pageses" => $m === "" ? "(empty)" : $m,
            "row_count"        => $info["count"],
            "sum_pagesa"       => round($info["sum_pagesa"], 2),
        ];
    }

    // 2. gjendja_bankare rows for this client
    $bankStmt = $db->prepare("
        SELECT id, data, lloji, klienti, debi, kredi, shpjegim, komentet
        FROM gjendja_bankare
        WHERE LOWER(klienti) = LOWER(?)
        ORDER BY data ASC, id ASC
    ");
    $bankStmt->execute([$client]);
    $bankRows = $bankStmt->fetchAll();

    $bankKrediTotal = 0.0;
    foreach ($bankRows as $br) {
        $bankKrediTotal += (float)$br["kredi"];
    }

    // 3. Kartela calculation (matching kartela.php logic exactly)

    // Total Debi: all non-dhurate rows WHERE pagesa > 0
    $debiStmt = $db->prepare("
        SELECT COALESCE(SUM(pagesa), 0) AS total_debi,
               COUNT(*) AS debi_row_count
        FROM distribuimi
        WHERE LOWER(klienti) = LOWER(?)
          AND LOWER(TRIM(COALESCE(menyra_e_pageses, \"\"))) != \"dhurate\"
          AND pagesa > 0
    ");
    $debiStmt->execute([$client]);
    $debiResult = $debiStmt->fetch();
    $totalDebi = round((float)$debiResult["total_debi"], 2);

    // Total Kredi Cash: cash + po cash WHERE pagesa > 0
    $krediCashStmt = $db->prepare("
        SELECT COALESCE(SUM(pagesa), 0) AS kredi_cash,
               COUNT(*) AS kredi_cash_row_count
        FROM distribuimi
        WHERE LOWER(klienti) = LOWER(?)
          AND LOWER(TRIM(COALESCE(menyra_e_pageses, \"\"))) IN (\"cash\", \"po (fature te rregullte) cash\")
          AND pagesa > 0
    ");
    $krediCashStmt->execute([$client]);
    $krediCashResult = $krediCashStmt->fetch();
    $krediCash = round((float)$krediCashResult["kredi_cash"], 2);

    // Total Kredi Bank: from gjendja_bankare WHERE kredi > 0
    $krediBankStmt = $db->prepare("
        SELECT COALESCE(SUM(kredi), 0) AS kredi_bank,
               COUNT(*) AS kredi_bank_row_count
        FROM gjendja_bankare
        WHERE LOWER(klienti) = LOWER(?)
          AND kredi > 0
    ");
    $krediBankStmt->execute([$client]);
    $krediBankResult = $krediBankStmt->fetch();
    $krediBank = round((float)$krediBankResult["kredi_bank"], 2);

    $totalKredi = round($krediCash + $krediBank, 2);
    $gjendja = round($totalDebi - $totalKredi, 2);

    // 4. Sanity check: Debi WITHOUT pagesa > 0 filter
    $debiAllStmt = $db->prepare("
        SELECT COALESCE(SUM(pagesa), 0) AS total_debi_all,
               COUNT(*) AS row_count
        FROM distribuimi
        WHERE LOWER(klienti) = LOWER(?)
          AND LOWER(TRIM(COALESCE(menyra_e_pageses, \"\"))) != \"dhurate\"
    ");
    $debiAllStmt->execute([$client]);
    $debiAllResult = $debiAllStmt->fetch();

    echo json_encode([
        "client" => $client,
        "timestamp" => date("Y-m-d H:i:s"),

        "section_1_distribuimi_overview" => [
            "total_rows" => $totalCount,
            "total_pagesa_all_rows" => round($totalPagesa, 2),
            "breakdown_by_menyra" => $breakdownFormatted,
        ],

        "section_2_zero_pagesa_rows" => [
            "count" => count($zeroRows),
            "note" => "These rows are SKIPPED by Kartela (pagesa > 0 filter)",
            "rows" => $zeroRows,
        ],

        "section_3_gjendja_bankare" => [
            "row_count" => count($bankRows),
            "total_kredi" => round($bankKrediTotal, 2),
            "rows" => $bankRows,
        ],

        "section_4_kartela_calculation" => [
            "total_debi" => $totalDebi,
            "total_debi_row_count" => (int)$debiResult["debi_row_count"],
            "kredi_cash" => $krediCash,
            "kredi_cash_row_count" => (int)$krediCashResult["kredi_cash_row_count"],
            "kredi_bank" => $krediBank,
            "kredi_bank_row_count" => (int)$krediBankResult["kredi_bank_row_count"],
            "total_kredi" => $totalKredi,
            "gjendja_borxhi" => $gjendja,
            "gjendja_label" => $gjendja > 0.01 ? "borxh" : ($gjendja < -0.01 ? "avance" : "barazuar"),
        ],

        "section_5_sanity_check" => [
            "debi_without_pagesa_filter" => round((float)$debiAllResult["total_debi_all"], 2),
            "debi_without_filter_row_count" => (int)$debiAllResult["row_count"],
            "debi_with_pagesa_gt0_filter" => $totalDebi,
            "difference" => round((float)$debiAllResult["total_debi_all"] - $totalDebi, 2),
            "note" => "If difference is 0, zero-pagesa rows have no impact on the total",
        ],

        "dashboard_expected" => [
            "Total Debi" => "EUR " . number_format($totalDebi, 2),
            "Total Kredi" => "EUR " . number_format($totalKredi, 2),
            "Borxhi" => "EUR " . number_format(abs($gjendja), 2),
        ],

    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString(),
    ], JSON_PRETTY_PRINT);
}
