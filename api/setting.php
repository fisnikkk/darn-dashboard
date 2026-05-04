<?php
/**
 * Dashboard Shared Settings API
 *
 * Replaces per-browser localStorage for multi-user state (e.g. "Sipas raportit"
 * comparison value on notes.php and distribuimi.php). Backed by the
 * `dashboard_settings` table — last write wins.
 *
 * GET  /api/setting.php?key=<setting_key>
 *   → { success, value, updated_at, updated_by }   (value defaults to '' when not set)
 *
 * POST /api/setting.php   body: { key, value }
 *   → { success: true }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

try {
    $db = getDB();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
        if ($key === '') {
            echo json_encode(['success' => false, 'error' => 'Missing key']);
            exit;
        }
        $stmt = $db->prepare("SELECT setting_value, updated_at, updated_by FROM dashboard_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success'    => true,
            'key'        => $key,
            'value'      => $row['setting_value'] ?? '',
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $key = isset($input['key']) ? trim((string)$input['key']) : '';
        $value = $input['value'] ?? '';
        if ($key === '') {
            echo json_encode(['success' => false, 'error' => 'Missing key']);
            exit;
        }
        // Coerce value to string for storage; null becomes empty string
        $value = ($value === null) ? '' : (string)$value;

        $user = function_exists('getCurrentUser') ? (string)getCurrentUser() : null;

        $stmt = $db->prepare("
            INSERT INTO dashboard_settings (setting_key, setting_value, updated_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by    = VALUES(updated_by)
        ");
        $stmt->execute([$key, $value, $user]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
