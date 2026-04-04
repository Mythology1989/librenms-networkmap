<?php

if (! auth()->check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

// Build optional WHERE NOT IN clause for excluded locations
$excluded_json = netmap_get_setting('excluded_locations', '[]');
$excluded_ids  = json_decode($excluded_json, true);
if (!is_array($excluded_ids)) { $excluded_ids = []; }
// Sanitise: keep only positive integers
$excluded_ids = array_values(array_filter(array_map('intval', $excluded_ids), function ($v) { return $v > 0; }));

$exclusion_sql = '';
if (!empty($excluded_ids)) {
    // Safe: all values are verified integers
    $exclusion_sql = ' AND l.id NOT IN (' . implode(',', $excluded_ids) . ')';
}

$rows = dbFetchRows('
    SELECT
        d.device_id,
        d.hostname,
        d.sysName,
        d.display,
        l.lat,
        l.lng,
        l.location,
        d.status,
        d.uptime,
        d.type,
        d.os,
        COUNT(a.id) AS active_alerts,
        ndl.map_label
    FROM devices d
    JOIN locations l ON l.id = d.location_id
    LEFT JOIN alerts a ON a.device_id = d.device_id AND a.state = 1
    LEFT JOIN plugin_networkmap_device_labels ndl ON ndl.device_id = d.device_id
    WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL' . $exclusion_sql . '
    GROUP BY d.device_id
');

if ($rows === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
    exit;
}

$devices = [];

foreach ($rows as $row) {
    $display_name = !empty($row['map_label'])
        ? $row['map_label']
        : (!empty($row['display'])
            ? $row['display']
            : (!empty($row['sysName']) ? $row['sysName'] : $row['hostname']));

    $devices[] = [
        'id'            => (int) $row['device_id'],
        'hostname'      => $row['hostname'],
        'display_name'  => $display_name,
        'location'      => $row['location'] ?? '',
        'lat'           => (float) $row['lat'],
        'lng'           => (float) $row['lng'],
        'status'        => (int) $row['status'],
        'uptime'        => (int) $row['uptime'],
        'type'          => $row['type'],
        'os'            => $row['os'],
        'active_alerts' => (int) $row['active_alerts'],
        'url'           => '/device/device=' . (int) $row['device_id'] . '/',
    ];
}

echo json_encode(['devices' => $devices]);
exit;
