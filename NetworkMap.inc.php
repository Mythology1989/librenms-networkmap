<?php
require_once __DIR__ . '/includes/db.php';
$api  = request()->query('api',  '');
$view = request()->query('view', '');
if ($api !== '') {
    switch ($api) {
        case 'devices':
            require __DIR__ . '/api/devices.php';
            break;
        case 'links':
            require __DIR__ . '/api/links.php';
            break;
        case 'settings':
            require __DIR__ . '/api/settings.php';
            break;
        default:
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unknown API endpoint']);
            exit;
    }
} elseif ($view === 'tv') {
    require __DIR__ . '/tv.php';
} else {
    require __DIR__ . '/map.php';
}
