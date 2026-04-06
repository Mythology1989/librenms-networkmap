<?php

// db.php is already loaded by NetworkMap.inc.php; no require needed here.

if (! auth()->check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -------------------------------------------------------------------------
// Route: GET — return all settings (no admin required)
// -------------------------------------------------------------------------
if ($method === 'GET') {
    $defaults = [
        'map_provider'           => 'osm',
        'google_api_key'         => '',
        'refresh_interval'       => '60',
        'zoom_threshold_cluster' => '13',
        'default_zoom'           => 'auto',
        'tv_token'               => '',
    ];

    $settings = [];
    foreach ($defaults as $key => $default) {
        $settings[$key] = netmap_get_setting($key, $default);
    }

    // Auto-generate tv_token if absent or empty.
    if ($settings['tv_token'] === '' || $settings['tv_token'] === null) {
        $token = bin2hex(random_bytes(16));
        netmap_set_setting('tv_token', $token);
        $settings['tv_token'] = $token;
    }

    echo json_encode(['settings' => $settings], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// Route: POST — update one setting or perform an action (admin only)
// -------------------------------------------------------------------------
if ($method === 'POST') {
    // userlevel 10 = admin in LibreNMS legacy
    if (! auth()->user()->hasRole('admin')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: admin access required']);
        exit;
    }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (! is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    // Action: regenerate_tv_token
    if (isset($body['action']) && $body['action'] === 'regenerate_tv_token') {
        $token = bin2hex(random_bytes(16));
        netmap_set_setting('tv_token', $token);
        echo json_encode(['tv_token' => $token], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Standard key/value update
    $allowed_keys = [
        'map_provider',
        'google_api_key',
        'refresh_interval',
        'zoom_threshold_cluster',
        'default_zoom',
        'excluded_locations',
        'hidden_links',
        'link_priorities',
        'map_styles',
    ];

    if (! isset($body['key']) || ! isset($body['value'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: key, value']);
        exit;
    }

    $key   = $body['key'];
    $value = $body['value'];

    if (! in_array($key, $allowed_keys, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown or read-only setting key: ' . $key]);
        exit;
    }

    if (! is_string($value)) {
        http_response_code(400);
        echo json_encode(['error' => 'Setting value must be a string']);
        exit;
    }

    netmap_set_setting($key, $value);
    echo json_encode(['success' => true, 'key' => $key, 'value' => $value], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// Unsupported method
// -------------------------------------------------------------------------
http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'Method not allowed']);
exit;
