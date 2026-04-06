<?php
// Token validation — no auth()->check() here, this view is designed for unauthenticated TV/NOC access
$token = request()->query('token', '');

if ($token === '') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Acceso denegado: token requerido';
    exit;
}

$valid_token = netmap_get_setting('tv_token', '');

if ($valid_token === '' || !hash_equals($valid_token, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Acceso denegado: token inválido';
    exit;
}

// Load device data directly from DB (not via API endpoint)
$devices_raw = dbFetchRows('
    SELECT
        d.device_id   AS id,
        COALESCE(ndl.map_label, d.display, d.sysName, d.hostname) AS display_name,
        d.status,
        l.lat,
        l.lng,
        l.location
    FROM devices d
    JOIN locations l ON l.id = d.location_id
    LEFT JOIN plugin_networkmap_device_labels ndl ON ndl.device_id = d.device_id
    WHERE l.lat IS NOT NULL
      AND l.lng  IS NOT NULL
      AND l.lat  <> 0
      AND l.lng  <> 0
', []);

$devices    = [];
$up_count   = 0;
$down_count = 0;

if (is_array($devices_raw)) {
    foreach ($devices_raw as $row) {
        $devices[] = [
            'id'           => (int) $row['id'],
            'display_name' => $row['display_name'],
            'location'     => $row['location'] ?? '',
            'status'       => (int) $row['status'],
            'lat'          => (float) $row['lat'],
            'lng'          => (float) $row['lng'],
        ];
        if ((int) $row['status'] === 1) {
            $up_count++;
        } else {
            $down_count++;
        }
    }
}

$db_error      = ($devices_raw === false);
$devices_json  = json_encode($devices, JSON_UNESCAPED_UNICODE);
$refresh_ms    = max(10, (int) netmap_get_setting('refresh_interval', 60)) * 1000;

// Load links (LLDP + manual) with port data for bps/utilization calculation
$lldp_raw = dbFetchRows('
    SELECT l.id, l.local_device_id, l.remote_device_id,
           \'lldp\' AS type,
           CASE WHEN p.ifOperStatus = \'up\' THEN \'up\' ELSE \'down\' END AS status,
           p.port_id,
           p.ifInOctets,
           p.ifOutOctets,
           p.ifSpeed
    FROM links l
    JOIN ports p ON p.port_id = l.local_port_id
    WHERE l.remote_device_id IS NOT NULL
', []);

$manual_raw = dbFetchRows('
    SELECT ml.id, ml.local_device_id, ml.remote_device_id,
           \'manual\' AS type,
           CASE WHEN p.ifOperStatus = \'up\' THEN \'up\' ELSE \'down\' END AS status,
           p.port_id,
           p.ifInOctets,
           p.ifOutOctets,
           p.ifSpeed
    FROM plugin_networkmap_links ml
    LEFT JOIN ports p ON p.port_id = ml.local_port_id
', []);

$links_arr = [];

if (is_array($lldp_raw)) {
    foreach ($lldp_raw as $row) {
        $port_id  = (int) $row['port_id'];
        $speed    = (int) $row['ifSpeed'];
        [$in_bps, $out_bps] = netmap_calc_bps($port_id, (int) $row['ifInOctets'], (int) $row['ifOutOctets']);
        $util_pct = ($speed > 0 && ($in_bps > 0 || $out_bps > 0))
            ? round(max($in_bps, $out_bps) / $speed * 100, 2)
            : 0.0;
        $links_arr[] = [
            'id'               => 'lldp_' . (int) $row['id'],
            'local_device_id'  => (int) $row['local_device_id'],
            'remote_device_id' => (int) $row['remote_device_id'],
            'type'             => 'lldp',
            'status'           => $row['status'],
            'in_bps'           => $in_bps,
            'out_bps'          => $out_bps,
            'speed_bps'        => $speed,
            'utilization_pct'  => $util_pct,
        ];
    }
}

if (is_array($manual_raw)) {
    foreach ($manual_raw as $row) {
        $port_id  = $row['port_id'] !== null ? (int) $row['port_id'] : null;
        $speed    = (int) ($row['ifSpeed'] ?? 0);
        if ($port_id !== null && $row['ifInOctets'] !== null) {
            [$in_bps, $out_bps] = netmap_calc_bps($port_id, (int) $row['ifInOctets'], (int) $row['ifOutOctets']);
        } else {
            $in_bps = 0; $out_bps = 0;
        }
        $util_pct = ($speed > 0 && ($in_bps > 0 || $out_bps > 0))
            ? round(max($in_bps, $out_bps) / $speed * 100, 2)
            : 0.0;
        $links_arr[] = [
            'id'               => 'manual_' . (int) $row['id'],
            'local_device_id'  => (int) $row['local_device_id'],
            'remote_device_id' => (int) $row['remote_device_id'],
            'type'             => 'manual',
            'status'           => $row['status'] ?? 'up',
            'in_bps'           => $in_bps,
            'out_bps'          => $out_bps,
            'speed_bps'        => $speed,
            'utilization_pct'  => $util_pct,
        ];
    }
}

// Filter hidden links
$hidden_links_raw = netmap_get_setting('hidden_links', '[]');
$hidden_links_tv  = json_decode($hidden_links_raw, true);
if (!is_array($hidden_links_tv)) { $hidden_links_tv = []; }
if (!empty($hidden_links_tv)) {
    $links_arr = array_values(array_filter($links_arr, function ($link) use ($hidden_links_tv) {
        return !in_array($link['id'] ?? '', $hidden_links_tv, true);
    }));
}

// JSON-only soft-refresh endpoint: ?view=tv&token=...&format=json
if (request()->query('format', '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'devices'    => $devices,
        'links'      => $links_arr,
        'up_count'   => $up_count,
        'down_count' => $down_count,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$hidden_links_json  = json_encode($hidden_links_tv, JSON_UNESCAPED_UNICODE);
$link_priorities_raw = netmap_get_setting('link_priorities', '[]');
$link_priorities_json = json_encode(json_decode($link_priorities_raw, true) ?: [], JSON_UNESCAPED_UNICODE);
$tv_api_url = '/plugin/v1/NetworkMap?view=tv&token=' . urlencode($token) . '&format=json';
$zoom_threshold = (int) netmap_get_setting('zoom_threshold_cluster', 18);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NetworkMap TV</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css"
      id="leaflet-css-cdn">
<link rel="stylesheet" href="/plugins/NetworkMap/css/leaflet.min.css"
      id="leaflet-css-local" disabled>
<link rel="stylesheet" href="/plugins/NetworkMap/css/networkmap.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: 100%; height: 100%; background: #111; overflow: hidden; }
#tv-map { width: 100%; height: 100%; }

#tv-status {
    position: fixed;
    top: 16px;
    right: 16px;
    z-index: 1000;
    background: rgba(0, 0, 0, 0.65);
    border-radius: 8px;
    padding: 8px 14px;
    display: flex;
    gap: 20px;
    font-family: monospace;
    font-size: 20px;
    font-weight: bold;
    line-height: 1.4;
}
#tv-status .up   { color: #2ecc71; }
#tv-status .down { color: #e74c3c; }

#tv-timestamp {
    position: fixed;
    bottom: 12px;
    right: 16px;
    z-index: 1000;
    background: rgba(0, 0, 0, 0.55);
    border-radius: 6px;
    padding: 4px 10px;
    color: #ccc;
    font-family: monospace;
    font-size: 13px;
}
</style>
</head>
<body>
<div id="tv-map"></div>

<?php if ($db_error): ?>
<div id="tv-db-error" style="position:fixed;top:0;left:0;width:100%;background:#c0392b;color:#fff;text-align:center;padding:12px;z-index:2000;font-family:monospace;font-size:15px;">
    Error: no se pudo obtener datos de dispositivos
</div>
<?php endif; ?>

<div id="tv-status">
    <span class="up">UP: <?= (int) $up_count ?></span>
    <span class="down">DOWN: <?= (int) $down_count ?></span>
</div>
<div id="tv-timestamp">—</div>

<script>
document.getElementById('leaflet-css-cdn').addEventListener('error', function () {
    document.getElementById('leaflet-css-local').disabled = false;
});
</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>
<script>
if (typeof L === 'undefined') {
    document.write('<script src="/plugins/NetworkMap/js/leaflet.min.js"><\/script>');
}
</script>
<script>
window.netmapConfig = {
    tvMode:          true,
    mapId:           'tv-map',
    tvApiUrl:        <?= json_encode($tv_api_url) ?>,
    refreshInterval: <?= max(10, (int) netmap_get_setting('refresh_interval', 60)) ?>,
    zoomThreshold:   <?= $zoom_threshold ?>,
    hiddenLinks:     <?= $hidden_links_json ?>,
    linkPriorities:  <?= $link_priorities_json ?>,
    onTvDataLoaded: function (data) {
        var upEl   = document.querySelector('#tv-status .up');
        var downEl = document.querySelector('#tv-status .down');
        if (upEl)   { upEl.textContent   = 'UP: '   + (data.up_count   || 0); }
        if (downEl) { downEl.textContent = 'DOWN: ' + (data.down_count || 0); }
        var tsEl = document.getElementById('tv-timestamp');
        if (tsEl) {
            tsEl.textContent = 'Actualizado: ' + new Date().toTimeString().slice(0, 8);
        }
    }
};
</script>
<script src="/plugins/NetworkMap/js/networkmap.js"></script>
<script>
// Update timestamp on initial load (networkmap.js fires onTvDataLoaded only after first fetch)
(function () {
    var tsEl = document.getElementById('tv-timestamp');
    if (tsEl) { tsEl.textContent = 'Actualizado: ' + new Date().toTimeString().slice(0, 8); }
})();
</script>
</body>
</html>
<?php exit; ?>
