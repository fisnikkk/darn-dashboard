<?php
/**
 * Temporary debug endpoint: compare snapshot data to find which matches Excel "litrat ne dispozicion me fature" = 54,187.05
 */
ini_set('memory_limit', '512M');
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();

    // Get list of snapshots (without loading data)
    $stmtList = $db->query("SELECT id, name, created_at FROM snapshots ORDER BY created_at ASC");
    $snapshots = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    $target = 54187.05;

    foreach ($snapshots as $snap) {
        // Load one snapshot at a time
        $stmtData = $db->prepare("SELECT snapshot_data FROM snapshots WHERE id = ?");
        $stmtData->execute([$snap['id']]);
        $raw = $stmtData->fetchColumn();
        $data = json_decode($raw, true);
        unset($raw); // free memory

        if (!$data || !isset($data['tables'])) {
            $results[] = [
                'id' => $snap['id'],
                'name' => $snap['name'],
                'created_at' => $snap['created_at'],
                'error' => 'Could not parse snapshot_data or missing tables key'
            ];
            unset($data);
            continue;
        }

        $plini_depo = $data['tables']['plini_depo'] ?? [];
        $distribuimi = $data['tables']['distribuimi'] ?? [];

        // --- ME FATURE calculations ---
        $blera_me_fature = 0.0;
        $blera_me_fature_rows = 0;
        foreach ($plini_depo as $row) {
            $pagesa = strtolower(trim($row['menyra_e_pageses'] ?? ''));
            if ($pagesa === 'me fature') {
                $blera_me_fature += floatval($row['sasia_ne_litra'] ?? 0);
                $blera_me_fature_rows++;
            }
        }

        $leshuar_me_fature = 0.0;
        $leshuar_me_fature_rows = 0;
        $fature_types = [
            'po (fature te rregullte) cash',
            'bank',
            'po (fature te rregullte) banke'
        ];
        foreach ($distribuimi as $row) {
            $pagesa = strtolower(trim($row['menyra_e_pageses'] ?? ''));
            if (in_array($pagesa, $fature_types)) {
                $leshuar_me_fature += floatval($row['litrat_e_konvertuara'] ?? 0);
                $leshuar_me_fature_rows++;
            }
        }

        $litrat_ne_dispozicion = round($blera_me_fature - $leshuar_me_fature, 2);
        $matches_excel = (abs($litrat_ne_dispozicion - $target) < 0.01);

        // --- TOTAL calculations (all payment types) ---
        $total_blera = 0.0;
        foreach ($plini_depo as $row) {
            $total_blera += floatval($row['sasia_ne_litra'] ?? 0);
        }

        $total_shitura = 0.0;
        foreach ($distribuimi as $row) {
            $total_shitura += floatval($row['litrat_e_konvertuara'] ?? 0);
        }

        // --- Distinct payment types ---
        $plini_pagesa_types = [];
        foreach ($plini_depo as $row) {
            $p = strtolower(trim($row['menyra_e_pageses'] ?? ''));
            if (!isset($plini_pagesa_types[$p])) $plini_pagesa_types[$p] = 0;
            $plini_pagesa_types[$p]++;
        }

        $dist_pagesa_types = [];
        foreach ($distribuimi as $row) {
            $p = strtolower(trim($row['menyra_e_pageses'] ?? ''));
            if (!isset($dist_pagesa_types[$p])) $dist_pagesa_types[$p] = 0;
            $dist_pagesa_types[$p]++;
        }

        $results[] = [
            'id' => $snap['id'],
            'name' => $snap['name'],
            'created_at' => $snap['created_at'],
            'plini_depo_row_count' => count($plini_depo),
            'distribuimi_row_count' => count($distribuimi),
            'blera_me_fature' => round($blera_me_fature, 2),
            'blera_me_fature_rows' => $blera_me_fature_rows,
            'leshuar_me_fature' => round($leshuar_me_fature, 2),
            'leshuar_me_fature_rows' => $leshuar_me_fature_rows,
            'litrat_ne_dispozicion_me_fature' => $litrat_ne_dispozicion,
            'matches_excel_54187_05' => $matches_excel,
            'total_blera_all_types' => round($total_blera, 2),
            'total_shitura_all_types' => round($total_shitura, 2),
            'matches_live_blera_1928501' => (abs($total_blera - 1928501) < 1),
            'matches_live_shitura_1646782_60' => (abs($total_shitura - 1646782.60) < 1),
            'plini_depo_payment_types' => $plini_pagesa_types,
            'distribuimi_payment_types' => $dist_pagesa_types
        ];

        // Free memory before next iteration
        unset($data, $plini_depo, $distribuimi, $plini_pagesa_types, $dist_pagesa_types);
    }

    echo json_encode([
        'target_value' => $target,
        'snapshot_count' => count($snapshots),
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
