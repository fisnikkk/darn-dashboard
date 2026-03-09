<?php
/**
 * Temporary diagnostic - DELETE after debugging
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$pass = getenv('DASHBOARD_PASS');
$user = getenv('DASHBOARD_USER');

$db = getDB();
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Check rate limit status
$stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
$stmt->execute([$ip]);
$attempts = (int)$stmt->fetchColumn();

echo json_encode([
    'user_set' => ($user !== false && $user !== ''),
    'user_length' => $user ? strlen($user) : 0,
    'pass_set' => ($pass !== false && $pass !== ''),
    'pass_length' => $pass ? strlen($pass) : 0,
    'pass_first3' => $pass ? substr($pass, 0, 3) : '',
    'pass_last3' => $pass ? substr($pass, -3) : '',
    'your_ip' => $ip,
    'failed_attempts' => $attempts,
    'locked' => $attempts >= 5,
], JSON_PRETTY_PRINT);
