<?php
/**
 * DARN Dashboard - Email (Gmail SMTP) Configuration
 *
 * Reads from environment variables (Railway) or .env/.env.php files (Plesk/GoDaddy).
 * Ensure database.php is loaded first so putenv() has been called.
 */

// Helper: get env var from multiple sources
function getEmailEnv($key) {
    // 1. getenv() — works on Railway and after putenv()
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;

    // 2. $_ENV / $_SERVER — some hosts populate these
    if (!empty($_ENV[$key])) return $_ENV[$key];
    if (!empty($_SERVER[$key])) return $_SERVER[$key];

    return '';
}

return [
    'gmail_email' => getEmailEnv('GMAIL_EMAIL'),
    'gmail_app_password' => getEmailEnv('GMAIL_APP_PASSWORD'),
    'sender_name' => 'Darn Group L.L.C',
];
