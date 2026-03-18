<?php
/**
 * GoDaddy HTTP API Connection
 * Fetches data from GoDaddy via dashboard_export.php proxy
 * (GoDaddy blocks remote MySQL, so we use an HTTP proxy instead)
 *
 * Set these in Railway dashboard → Variables:
 *   GODADDY_API_URL = http://testing.darn-group.com/dashboard_export.php
 *   GODADDY_API_KEY = darn-dashboard-2026-secure-key
 */

define('GD_API_URL', getenv('GODADDY_API_URL') ?: 'http://testing.darn-group.com/dashboard_export.php');
define('GD_API_KEY', getenv('GODADDY_API_KEY') ?: 'darn-dashboard-2026-secure-key');

/**
 * Call the GoDaddy export API using curl
 * @param array $params  Query parameters (action, date_from, date_to, etc.)
 * @return array|null    Decoded JSON response, or null on failure
 */
function callGoDaddyAPI($params = []) {
    $params['key'] = GD_API_KEY;
    $url = GD_API_URL . '?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'DARN-Dashboard/1.0',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("GoDaddy API error: HTTP {$httpCode}, curl error: {$error}");
        return null;
    }

    return json_decode($response, true);
}
