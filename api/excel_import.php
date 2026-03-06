<?php
/**
 * DARN Dashboard - Excel Import API
 * Handles: (1) parsing uploaded .xlsx/.xlsm to extract sheet info
 *          (2) importing parsed data into database tables
 *
 * Actions:
 *   parse  - multipart upload, returns sheet names + row counts
 *   import - JSON body, imports cached sheet data into a target table
 */
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);
set_time_limit(300);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/SimpleXLSX.php';

use Shuchkin\SimpleXLSX;

header('Content-Type: application/json');

// Upload size limit
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '55M');

// Sheet → table + column mapping
// NOTE: Some Excel sheet names have trailing spaces (e.g. 'Gjendja bankare ')
// The parse logic trims sheet names to match these keys
$sheetConfig = [
    'Distribuimi' => [
        'table' => 'distribuimi',
        'headerRow' => 5,   // Row 5 has headers
        'columns' => [
            2 => 'klienti', 3 => 'data', 4 => 'sasia', 5 => 'boca_te_kthyera',
            // 6=Boca tek biznesi (calc), 7=Boca total (calc)
            8 => 'litra', 9 => 'cmimi', 10 => 'pagesa', 11 => 'menyra_e_pageses',
            // 12=fatura_e_derguar (removed from DB)
            13 => 'data_e_fletepageses', 14 => 'koment',
        ],
    ],
    'Shpenzimet' => [
        'table' => 'shpenzimet',
        'headerRow' => 2,
        'columns' => [
            // cols 0-1 are summary area; data starts col 2
            2 => 'data_e_pageses', 3 => 'shuma', 4 => 'arsyetimi', 5 => 'lloji_i_pageses',
            6 => 'lloji_i_transaksionit', 7 => 'pershkrim_i_detajuar', 8 => 'nafta_ne_litra',
            9 => 'numri_i_fatures',
            // no fatura_e_rregullte in Excel
            10 => 'data_e_fatures', 11 => 'shuma_fatures', 12 => 'lloji_fatures',
        ],
    ],
    'Plini depo' => [
        'table' => 'plini_depo',
        'headerRow' => 2,
        'columns' => [
            0 => 'nr_i_fatures', 1 => 'data', 2 => 'kg', 3 => 'sasia_ne_litra',
            4 => 'cmimi', 5 => 'faturat_e_pranuara', 6 => 'dalje_pagesat_sipas_bankes',
            7 => 'menyra_e_pageses', 8 => 'cash_banke', 9 => 'furnitori', 10 => 'koment',
        ],
    ],
    'Shitje produkteve prej 9 mar' => [
        'table' => 'shitje_produkteve',
        'headerRow' => 1,
        'columns' => [
            0 => 'data', 1 => 'cilindra_sasia', 2 => 'produkti', 3 => 'klienti',
            4 => 'adresa', 5 => 'qyteti', 6 => 'cmimi', 7 => 'totali',
            8 => 'koment', 9 => 'statusi_i_pageses',
            // no menyra_pageses column in Excel
        ],
    ],
    'Kontrata' => [
        'table' => 'kontrata',
        'headerRow' => 1,
        'columns' => [
            0 => 'nr_i_kontrates', 1 => 'data', 2 => 'biznesi', 3 => 'name_from_database',
            4 => 'numri_ne_stok_sipas_kontrates',
            // 5=Sipas Distribuimit (calc), 6=Comparison (calc)
            7 => 'sipas_skenimit_pda', 8 => 'bashkepunim', 9 => 'qyteti',
            10 => 'rruga', 11 => 'numri_unik', 12 => 'perfaqesuesi', 13 => 'nr_telefonit',
            14 => 'koment', 15 => 'email', 16 => 'ne_grup_njoftues',
            17 => 'kontrate_e_vjeter', 18 => 'lloji_i_bocave',
            // 19=Qe sa dite nuk ka marr (calc)
            20 => 'bocat_e_paguara',
            // 21=Help (skip)
            22 => 'data_rregullatoret',
        ],
    ],
    'Gjendja bankare' => [
        'table' => 'gjendja_bankare',
        'headerRow' => 12,  // Row 12 has column headers; data from row 13
        'columns' => [
            0 => 'data', 1 => 'data_valutes', 2 => 'ora', 3 => 'shpjegim',
            4 => 'valuta', 5 => 'debia', 6 => 'kredi', 7 => 'bilanci',
            8 => 'deftesa', 9 => 'lloji',
            // klienti and komentet are NOT in Excel; added manually in dashboard
        ],
    ],
    'NOTES' => [
        'table' => 'notes',
        'headerRow' => 1,
        'columns' => [
            0 => 'data', 1 => 'teksti', 2 => 'barazu_nga',
        ],
    ],
    'Klientet' => [
        'table' => 'klientet',
        'headerRow' => 1,
        'columns' => [
            0 => 'emri', 1 => 'bashkepunim', 2 => 'data_e_kontrates', 3 => 'stoku',
            4 => 'koment', 5 => 'kontakti',
            // 6=I regjistruar ne emer te (skip for now)
            7 => 'numri_unik_identifikues',
            8 => 'adresa', 9 => 'telefoni', 10 => 'telefoni_2',
        ],
    ],
    'Nxemese1' => [
        'table' => 'nxemese',
        'headerRow' => 5,   // Headers at row 5
        'columns' => [
            0 => 'klienti', 1 => 'data', 2 => 'te_dhena', 3 => 'te_marra',
            // 4=Ne stok (calc), 5=Boca total (calc)
            6 => 'lloji_i_nxemjes', 7 => 'koment',
        ],
    ],
    'Stoku zyrtar' => [
        'table' => 'stoku_zyrtar',
        'headerRow' => 3,   // Headers at row 3
        'columns' => [
            0 => 'kodi', 1 => 'kodi_2', 2 => 'pershkrimi', 3 => 'njesi',
            4 => 'sasia', 5 => 'cmimi', 6 => 'vlera',
        ],
    ],
    'Depo' => [
        'table' => 'depo',
        'headerRow' => 1,
        'columns' => [
            // col 0 is empty; data starts at col 1
            1 => 'data', 2 => 'produkti', 3 => 'sasia', 4 => 'cmimi',
        ],
    ],
];

// Date columns per table (need special formatting)
$dateColumns = [
    'distribuimi' => ['data', 'data_e_fletepageses'],
    'shpenzimet' => ['data_e_pageses', 'data_e_fatures'],
    'plini_depo' => ['data'],
    'shitje_produkteve' => ['data'],
    'kontrata' => ['data', 'data_rregullatoret'],
    'gjendja_bankare' => ['data', 'data_valutes'],
    'notes' => ['data'],
    'klientet' => ['data_e_kontrates'],
    'nxemese' => ['data'],
    'stoku_zyrtar' => ['data'],
    'depo' => ['data'],
];

// Numeric columns per table
$numericColumns = [
    'distribuimi' => ['sasia', 'boca_te_kthyera', 'litra', 'cmimi', 'pagesa'],
    'shpenzimet' => ['shuma', 'nafta_ne_litra', 'shuma_fatures'],
    'plini_depo' => ['kg', 'sasia_ne_litra', 'cmimi', 'faturat_e_pranuara', 'dalje_pagesat_sipas_bankes'],
    'shitje_produkteve' => ['cilindra_sasia', 'cmimi', 'totali'],
    'kontrata' => ['nr_i_kontrates', 'numri_ne_stok_sipas_kontrates'],
    'gjendja_bankare' => ['debia', 'kredi', 'bilanci'],
    'nxemese' => ['te_dhena', 'te_marra'],
    'stoku_zyrtar' => ['sasia', 'cmimi', 'vlera'],
    'depo' => ['sasia', 'cmimi'],
];

// Helper: convert Excel date serial to Y-m-d
function excelDateToYmd($value) {
    if (empty($value) || $value === '' || $value === '0') return null;

    // Already a date string like "2024-01-15" or "15/01/2024"
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        return substr($value, 0, 10);
    }
    if (is_string($value) && preg_match('#^\d{1,2}/\d{1,2}/\d{4}#', $value)) {
        $parts = explode('/', $value);
        return $parts[2] . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
    }
    if (is_string($value) && preg_match('#^\d{1,2}\.\d{1,2}\.\d{4}#', $value)) {
        $parts = explode('.', $value);
        return $parts[2] . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
    }

    // Excel serial date number
    if (is_numeric($value) && (float)$value > 1) {
        $unixDate = ($value - 25569) * 86400;
        $d = gmdate('Y-m-d', (int)$unixDate);
        if ($d && $d !== '1970-01-01') return $d;
    }

    return is_string($value) ? $value : null;
}

// Helper: clean numeric value
function cleanNumeric($value) {
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) return (float)$value;
    // Remove thousand separators and convert comma decimals
    $cleaned = preg_replace('/[^\d.\-,]/', '', (string)$value);
    $cleaned = str_replace(',', '.', $cleaned);
    return is_numeric($cleaned) ? (float)$cleaned : null;
}

// Helper: clean string
function cleanString($value) {
    if ($value === null) return null;
    $s = trim((string)$value);
    return $s === '' ? null : $s;
}

// ============================================================
// ACTION: PARSE - upload and analyze the Excel file
// ============================================================
if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'parse') {
    $uploadedFile = $_FILES['file'];

    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Upload gabim: ' . $uploadedFile['error']]);
        exit;
    }

    // Save to temp location
    $tmpPath = sys_get_temp_dir() . '/darn_import_' . session_id() . '_' . time() . '.xlsx';
    if (!move_uploaded_file($uploadedFile['tmp_name'], $tmpPath)) {
        echo json_encode(['success' => false, 'error' => 'Nuk mund të ruhet skedari']);
        exit;
    }

    // Parse with SimpleXLSX
    $xlsx = SimpleXLSX::parse($tmpPath);
    if (!$xlsx) {
        @unlink($tmpPath);
        echo json_encode(['success' => false, 'error' => 'Gabim në lexim: ' . SimpleXLSX::parseError()]);
        exit;
    }

    $sheetNames = $xlsx->sheetNames();
    $result = [];

    // Build a lookup: trimmed sheet name → config key
    // Handles Excel sheets with trailing spaces (e.g. "Gjendja bankare ", "Depo ")
    $sheetNameMap = []; // idx => configKey
    foreach ($sheetNames as $idx => $name) {
        $trimmed = trim($name);
        if (isset($sheetConfig[$trimmed])) {
            $sheetNameMap[$idx] = $trimmed;
        } elseif (isset($sheetConfig[$name])) {
            $sheetNameMap[$idx] = $name;
        }
    }

    foreach ($sheetNameMap as $idx => $configKey) {
        $name = $configKey; // use the config key as the canonical name
        $config = $sheetConfig[$configKey];
        $allRows = $xlsx->rows($idx);
        $dataRows = count($allRows) - $config['headerRow']; // rows after header
        if ($dataRows < 0) $dataRows = 0;

        // Count only non-empty data rows
        $nonEmptyRows = 0;
        for ($i = $config['headerRow']; $i < count($allRows); $i++) {
            $row = $allRows[$i];
            $hasData = false;
            foreach ($config['columns'] as $colIdx => $field) {
                if (isset($row[$colIdx]) && trim((string)$row[$colIdx]) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if ($hasData) $nonEmptyRows++;
        }

        $result[$name] = [
            'table' => $config['table'],
            'rows' => $nonEmptyRows,
            'headerRow' => $config['headerRow'],
        ];
    }

    // Cache parsed data for import step
    $cacheFile = sys_get_temp_dir() . '/darn_import_cache_' . md5($tmpPath) . '.json';
    $cacheData = [];
    foreach ($result as $sheetName => $info) {
        $config = $sheetConfig[$sheetName];
        // Find the sheet index (may have trailing space in Excel)
        $idx = false;
        foreach ($sheetNames as $sIdx => $sName) {
            if (trim($sName) === $sheetName || $sName === $sheetName) {
                $idx = $sIdx;
                break;
            }
        }
        if ($idx === false) continue;
        $allRows = $xlsx->rows($idx);
        $parsedRows = [];

        for ($i = $config['headerRow']; $i < count($allRows); $i++) {
            $row = $allRows[$i];
            $record = [];
            $hasData = false;

            foreach ($config['columns'] as $colIdx => $field) {
                $val = isset($row[$colIdx]) ? $row[$colIdx] : null;

                // Format based on column type
                $table = $config['table'];
                if (isset($dateColumns[$table]) && in_array($field, $dateColumns[$table])) {
                    $val = excelDateToYmd($val);
                } elseif (isset($numericColumns[$table]) && in_array($field, $numericColumns[$table])) {
                    $val = cleanNumeric($val);
                } else {
                    $val = cleanString($val);
                }

                $record[$field] = $val;
                if ($val !== null && $val !== '') $hasData = true;
            }

            if ($hasData) $parsedRows[] = $record;
        }

        $cacheData[$sheetName] = [
            'table' => $config['table'],
            'rows' => $parsedRows,
        ];
    }

    // Save cache
    file_put_contents($cacheFile, json_encode($cacheData, JSON_UNESCAPED_UNICODE));

    // Store cache path in a known location
    $cachePointer = sys_get_temp_dir() . '/darn_import_pointer.txt';
    file_put_contents($cachePointer, $cacheFile);

    // Clean up xlsx file
    @unlink($tmpPath);

    echo json_encode(['success' => true, 'sheets' => $result]);
    exit;
}

// ============================================================
// ACTION: IMPORT - insert cached data into a database table
// ============================================================
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

if ($action === 'import') {
    $sheetName = $input['sheet'] ?? '';
    $tableName = $input['table'] ?? '';
    $mode = $input['mode'] ?? 'replace';

    if (!$sheetName || !$tableName) {
        echo json_encode(['success' => false, 'error' => 'Mungon sheet ose table']);
        exit;
    }

    // Load cached data
    $cachePointer = sys_get_temp_dir() . '/darn_import_pointer.txt';
    if (!file_exists($cachePointer)) {
        echo json_encode(['success' => false, 'error' => 'Nuk ka të dhëna të analizuara. Ngarko skedarin përsëri.']);
        exit;
    }
    $cacheFile = trim(file_get_contents($cachePointer));
    if (!file_exists($cacheFile)) {
        echo json_encode(['success' => false, 'error' => 'Cache e skaduar. Ngarko skedarin përsëri.']);
        exit;
    }

    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (!isset($cacheData[$sheetName])) {
        echo json_encode(['success' => false, 'error' => "Sheet '{$sheetName}' nuk u gjet në cache"]);
        exit;
    }

    $rows = $cacheData[$sheetName]['rows'];
    if (empty($rows)) {
        echo json_encode(['success' => true, 'imported' => 0, 'deleted' => 0, 'message' => 'Asnjë rresht për importim']);
        exit;
    }

    try {
        $db = getDB();
        $db->beginTransaction();
        $deleted = 0;

        // If replace mode, delete existing data
        if ($mode === 'replace') {
            $stmt = $db->query("SELECT COUNT(*) FROM {$tableName}");
            $deleted = (int)$stmt->fetchColumn();
            $db->exec("DELETE FROM {$tableName}");
            // Reset auto-increment
            $db->exec("ALTER TABLE {$tableName} AUTO_INCREMENT = 1");
        }

        // Insert rows
        $imported = 0;
        $errors = [];

        // Get the column list from first row
        $firstRow = $rows[0];
        $columns = array_keys($firstRow);

        // Build prepared statement
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = "INSERT INTO {$tableName} (" . implode(',', $columns) . ") VALUES {$placeholders}";
        $stmt = $db->prepare($sql);

        foreach ($rows as $idx => $row) {
            try {
                $values = [];
                foreach ($columns as $col) {
                    $val = $row[$col] ?? null;
                    // Convert empty strings to NULL for numeric fields
                    if ($val === '' || $val === null) {
                        $values[] = null;
                    } else {
                        $values[] = $val;
                    }
                }
                $stmt->execute($values);
                $imported++;
            } catch (PDOException $e) {
                $errors[] = "Row " . ($idx + 1) . ": " . $e->getMessage();
                if (count($errors) > 10) break; // Stop after 10 errors
            }
        }

        // Special handling for gjendja_bankare: recalculate bilanci
        if ($tableName === 'gjendja_bankare') {
            $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
            $running = 0;
            foreach ($all as $r) {
                $running = round($running + (float)$r['kredi'] + (float)$r['debia'], 2);
                $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
            }
        }

        // Log import action to changelog
        $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('excel_import', ?, 0, ?, NULL, ?)")
            ->execute([$tableName, $mode, json_encode(['imported' => $imported, 'deleted' => $deleted, 'errors' => count($errors)], JSON_UNESCAPED_UNICODE)]);

        $db->commit();

        $response = [
            'success' => true,
            'imported' => $imported,
            'deleted' => $deleted,
            'errors' => $errors,
        ];
        if ($errors) {
            $response['warning'] = count($errors) . ' rreshta me gabime';
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Veprim i pavlefshëm']);
