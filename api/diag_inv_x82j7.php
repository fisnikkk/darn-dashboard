<?php
/**
 * TEMPORARY diagnostic: inspect what /api/invoice.php?action=next_number
 * returns on the deployed server, without auth, with token gate.
 * DELETE THIS FILE IMMEDIATELY AFTER USE.
 */
header('Content-Type: application/json');

$token = $_GET['t'] ?? '';
if ($token !== 'darn_check_2026_05_06_x82j7') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
$db = getDB();

// Mirror EXACTLY what api/invoice.php case 'next_number' does
$maxStmt = $db->query("SELECT MAX(invoice_number) FROM invoices");
$maxNum = $maxStmt->fetchColumn();
$fellBackToCounter = false;
if ($maxNum === null || $maxNum === false) {
    $settingStmt = $db->query("SELECT setting_value FROM invoice_settings WHERE setting_key = 'next_invoice_number'");
    $maxNum = ($settingStmt->fetchColumn() ?: 130);
    $fellBackToCounter = true;
}
$nextNumber = intval($maxNum) + 1;

// Also report the actual deployed invoice.php's content fingerprint
$invoicePath = __DIR__ . '/invoice.php';
$mtime = file_exists($invoicePath) ? date('c', filemtime($invoicePath)) : null;
$md5 = file_exists($invoicePath) ? md5_file($invoicePath) : null;
$size = file_exists($invoicePath) ? filesize($invoicePath) : null;

// Read line 25 of deployed invoice.php to confirm it's the new code
$linesNearNextNumber = '';
if (file_exists($invoicePath)) {
    $f = file($invoicePath);
    $linesNearNextNumber = implode('', array_slice($f, 23, 12)); // lines 24-35
}

echo json_encode([
    'next_number_response' => ['success' => true, 'next_number' => $nextNumber],
    'fell_back_to_counter' => $fellBackToCounter,
    'max_invoice_number_in_db' => $maxNum,
    'invoice_settings_counter' => (function() use ($db) {
        return $db->query("SELECT setting_value FROM invoice_settings WHERE setting_key = 'next_invoice_number'")->fetchColumn();
    })(),
    'deployed_file' => [
        'path' => $invoicePath,
        'mtime' => $mtime,
        'size' => $size,
        'md5' => $md5,
    ],
    'deployed_next_number_block' => $linesNearNextNumber,
    'server_time' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
