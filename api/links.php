<?php

if (! auth()->check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $lldp_rows = dbFetchRows('
        SELECT
            l.id,
            l.local_device_id,
            l.remote_device_id,
            l.local_port_id,
            l.remote_port_id,
            p_local.ifInOctets,
            p_local.ifOutOctets,
            p_local.ifSpeed,
            p_local.ifOperStatus,
            p_local.ifAlias
        FROM links l
        JOIN ports p_local ON p_local.port_id = l.local_port_id
        WHERE l.remote_device_id IS NOT NULL
    ');

    $manual_rows = dbFetchRows('
        SELECT
            ml.id,
            ml.local_device_id,
            ml.remote_device_id,
            ml.local_port_id,
            ml.remote_port_id,
            ml.label,
            p_local.ifAlias,
            p_local.ifSpeed,
            p_local.ifOperStatus,
            p_remote.ifAlias AS remote_ifAlias
        FROM plugin_networkmap_links ml
        LEFT JOIN ports p_local  ON p_local.port_id  = ml.local_port_id
        LEFT JOIN ports p_remote ON p_remote.port_id = ml.remote_port_id
    ');

    if ($lldp_rows === false || $manual_rows === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Database query failed']);
        exit;
    }

    $links = [];

    foreach ($lldp_rows as $row) {
        $local_port = !empty($row['ifAlias']) ? $row['ifAlias'] : 'port_' . $row['local_port_id'];

        $links[] = [
            'id'               => 'lldp_' . (int) $row['id'],
            'type'             => 'lldp',
            'local_device_id'  => (int) $row['local_device_id'],
            'remote_device_id' => (int) $row['remote_device_id'],
            'local_port'       => $local_port,
            'remote_port'      => 'port_' . (int) $row['remote_port_id'],
            'status'           => ($row['ifOperStatus'] === 'up') ? 'up' : 'down',
            'speed_bps'        => (int) $row['ifSpeed'],
            'in_bps'           => 0,
            'out_bps'          => 0,
            'utilization_pct'  => 0.0,
        ];
    }

    foreach ($manual_rows as $row) {
        $local_port  = !empty($row['ifAlias'])
            ? $row['ifAlias']
            : ($row['local_port_id'] !== null ? 'port_' . (int) $row['local_port_id'] : null);
        $remote_port = !empty($row['remote_ifAlias'])
            ? $row['remote_ifAlias']
            : ($row['remote_port_id'] !== null ? 'port_' . (int) $row['remote_port_id'] : null);

        $links[] = [
            'id'               => 'manual_' . (int) $row['id'],
            'type'             => 'manual',
            'local_device_id'  => (int) $row['local_device_id'],
            'remote_device_id' => (int) $row['remote_device_id'],
            'local_port'       => $local_port,
            'remote_port'      => $remote_port,
            'label'            => $row['label'],
            'status'           => ($row['ifOperStatus'] === 'up') ? 'up' : 'down',
            'speed_bps'        => (int) $row['ifSpeed'],
            'in_bps'           => 0,
            'out_bps'          => 0,
            'utilization_pct'  => 0.0,
        ];
    }

    echo json_encode(['links' => $links]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body) || ! is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or empty JSON body']);
        exit;
    }

    if (($body['action'] ?? '') !== 'add_manual') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    $local_id  = isset($body['local_device_id'])  ? (int) $body['local_device_id']  : 0;
    $remote_id = isset($body['remote_device_id']) ? (int) $body['remote_device_id'] : 0;

    if ($local_id <= 0 || $remote_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'local_device_id and remote_device_id must be positive integers']);
        exit;
    }

    $new_id = dbInsert([
        'local_device_id'  => $local_id,
        'remote_device_id' => $remote_id,
        'local_port_id'    => isset($body['local_port_id'])  ? (int) $body['local_port_id']  : null,
        'remote_port_id'   => isset($body['remote_port_id']) ? (int) $body['remote_port_id'] : null,
        'label'            => isset($body['label'])          ? (string) $body['label']       : null,
    ], 'plugin_networkmap_links');

    if ($new_id === null || $new_id === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to insert manual link']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => (int) $new_id]);
    exit;
}

if ($method === 'DELETE') {
    $link_id = (int) request()->query('id', 0);

    if ($link_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id must be a positive integer']);
        exit;
    }

    $affected = dbDelete('plugin_networkmap_links', '`id` = ?', [$link_id]);

    if ($affected === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete manual link']);
        exit;
    }

    if ($affected === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Manual link not found']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
