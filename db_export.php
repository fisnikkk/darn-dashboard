<?php
/**
 * TEMPORARY DATABASE EXPORT SCRIPT
 * Creates a full SQL dump of all tables and views.
 * READ-ONLY — does not modify any data.
 * DELETE THIS FILE AFTER USE.
 */

// Require API key for security
$apiKey = $_GET['api_key'] ?? '';
$expectedKey = getenv('ANDROID_API_KEY') ?: 'darn-android-2026-secure-key';

if ($apiKey !== $expectedKey) {
    http_response_code(403);
    die('Unauthorized');
}

// Load database config
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die('DB connection failed: ' . $e->getMessage());
}

// Set headers for SQL file download
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="darn_dashboard_railway_export_' . date('Y-m-d_His') . '.sql"');
header('Cache-Control: no-cache');

// Output buffer to stream
$output = fopen('php://output', 'w');

// Header
fwrite($output, "-- DARN Dashboard - Railway Database Export\n");
fwrite($output, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($output, "-- Source: Railway (production)\n");
fwrite($output, "-- This is a READ-ONLY export. No data was modified.\n");
fwrite($output, "--\n\n");

fwrite($output, "SET NAMES utf8mb4;\n");
fwrite($output, "SET FOREIGN_KEY_CHECKS = 0;\n");
fwrite($output, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

// Get all tables
$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

foreach ($tables as $tableRow) {
    $table = $tableRow[0];

    fwrite($output, "-- ============================================================\n");
    fwrite($output, "-- Table: {$table}\n");
    fwrite($output, "-- ============================================================\n\n");

    // Get CREATE TABLE statement
    $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
    $createSQL = $createStmt['Create Table'];

    fwrite($output, "DROP TABLE IF EXISTS `{$table}`;\n");
    fwrite($output, $createSQL . ";\n\n");

    // Get row count
    $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    fwrite($output, "-- {$table}: {$count} rows\n");

    if ($count == 0) {
        fwrite($output, "-- (empty table)\n\n");
        continue;
    }

    // Get column names
    $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $columnList = implode('`, `', $columns);

    // Export data in batches of 500 rows
    $batchSize = 500;
    $offset = 0;

    while ($offset < $count) {
        $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) break;

        fwrite($output, "INSERT INTO `{$table}` (`{$columnList}`) VALUES\n");

        $values = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = $pdo->quote($val);
                }
            }
            $values[] = '(' . implode(', ', $vals) . ')';
        }

        fwrite($output, implode(",\n", $values) . ";\n\n");

        $offset += $batchSize;
    }
}

// Export views
fwrite($output, "\n-- ============================================================\n");
fwrite($output, "-- VIEWS\n");
fwrite($output, "-- ============================================================\n\n");

$views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);

foreach ($views as $viewRow) {
    $view = $viewRow[0];

    fwrite($output, "-- View: {$view}\n");
    $createView = $pdo->query("SHOW CREATE VIEW `{$view}`")->fetch(PDO::FETCH_ASSOC);

    fwrite($output, "DROP VIEW IF EXISTS `{$view}`;\n");

    // Clean up the CREATE VIEW statement (remove DEFINER clause for portability)
    $viewSQL = $createView['Create View'];
    $viewSQL = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/', '', $viewSQL);
    // Remove SQL SECURITY DEFINER if present
    $viewSQL = preg_replace('/SQL SECURITY DEFINER\s*/', '', $viewSQL);

    fwrite($output, $viewSQL . ";\n\n");
}

// Footer
fwrite($output, "SET FOREIGN_KEY_CHECKS = 1;\n\n");
fwrite($output, "-- Export complete.\n");

fclose($output);
