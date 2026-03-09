<?php
/**
 * GoDaddy HTTP API Connection
 * Fetches data from adaptive.darn-group.com/dashboard_export.php
 * (GoDaddy blocks remote MySQL, so we use an HTTP proxy instead)
 *
 * Set these in Railway dashboard → Variables:
 *   GODADDY_API_URL = http://adaptive.darn-group.com/dashboard_export.php
 *   GODADDY_API_KEY = darn-dashboard-2026-secure-key
 */

define('GD_API_URL', getenv('GODADDY_API_URL') ?: 'http://adaptive.darn-group.com/dashboard_export.php');
define('GD_API_KEY', getenv('GODADDY_API_KEY') ?: 'darn-dashboard-2026-secure-key');

/**
 * Call the GoDaddy export API
 * @param array $params  Query parameters (action, date_from, date_to, etc.)
 * @return array|null    Decoded JSON response, or null on failure
 */
function callGoDaddyAPI($params = []) {
    $params['key'] = GD_API_KEY;
    $url = GD_API_URL . '?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    return $data;
}
