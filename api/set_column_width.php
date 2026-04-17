<?php
/**
 * Save a single column width for a page (drag-to-resize feature).
 * Shared across all users — the next visitor sees whatever was last saved.
 * POST JSON: { page: "kontrata", col_index: 4, width_px: 80 }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['page'], $input['col_index'], $input['width_px'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$page = preg_replace('/[^a-z0-9_]/i', '', (string)$input['page']);
if ($page === '') {
    echo json_encode(['success' => false, 'error' => 'Invalid page']);
    exit;
}

$ok = setColumnWidth($page, (int)$input['col_index'], (int)$input['width_px']);
echo json_encode(['success' => $ok]);
