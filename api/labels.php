<?php

if (! auth()->check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

if (! auth()->user()->hasRole('admin')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden: admin access required']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -------------------------------------------------------------------------
// GET — return all custom labels { device_id: map_label }
// -------------------------------------------------------------------------
if ($method === 'GET') {
    $rows = dbFetchRows('SELECT device_id, map_label FROM plugin_networkmap_device_labels', []);
    if ($rows === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Database query failed']);
        exit;
    }
    $labels = [];
    foreach ($rows as $row) {
        $labels[(int) $row['device_id']] = $row['map_label'];
    }
    echo json_encode(['labels' => $labels]);
    exit;
}

// -------------------------------------------------------------------------
// POST — upsert or delete a label for one device
// Body: { device_id: int, map_label: string }
// Empty map_label → delete the row
// -------------------------------------------------------------------------
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (! is_array($body) || ! isset($body['device_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing device_id']);
        exit;
    }

    $device_id = (int) $body['device_id'];
    $map_label = isset($body['map_label']) ? trim((string) $body['map_label']) : '';

    if ($device_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id must be a positive integer']);
        exit;
    }

    if ($map_label === '') {
        dbDelete('plugin_networkmap_device_labels', '`device_id` = ?', [$device_id]);
        echo json_encode(['success' => true, 'deleted' => true]);
        exit;
    }

    $existing = dbFetchRow('SELECT device_id FROM plugin_networkmap_device_labels WHERE device_id = ?', [$device_id]);
    if ($existing) {
        dbUpdate(['map_label' => $map_label], 'plugin_networkmap_device_labels', '`device_id` = ?', [$device_id]);
    } else {
        dbInsert(['device_id' => $device_id, 'map_label' => $map_label], 'plugin_networkmap_device_labels');
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;
