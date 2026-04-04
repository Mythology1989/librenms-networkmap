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
        COALESCE(d.display, d.hostname) AS display_name,
        d.status,
        l.lat,
        l.lng
    FROM devices d
    JOIN locations l ON l.id = d.location_id
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

$links_json = json_encode($links_arr, JSON_UNESCAPED_UNICODE);

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

.tv-label {
    font-size: 11px;
    font-weight: bold;
    color: #fff;
    text-shadow: -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000;
    white-space: nowrap;
    pointer-events: none;
    margin-top: 4px;
}

.netmap-link-label {
    font-size: 13px;
    font-weight: bold;
    color: #fff;
    background: rgba(0, 0, 0, 0.85);
    border-radius: 3px;
    padding: 3px 6px;
    white-space: nowrap;
    pointer-events: none;
    transform: translate(-50%, -50%);
    display: inline-block;
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
(function () {
    'use strict';

    var TV_TOKEN   = <?= json_encode($token) ?>;
    var REFRESH_MS = <?= $refresh_ms ?>;
    var JSON_URL   = '/plugin/v1/NetworkMap?view=tv&token=' + encodeURIComponent(TV_TOKEN) + '&format=json';

    var map = L.map('tv-map', {
        minZoom: 2,
        maxZoom: 18,
        zoomControl: false,
        attributionControl: false
    }).setView([28.1, -15.4], 8);

    // Dark tile layer for TV/NOC mode
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        maxZoom: 18,
        subdomains: 'abcd'
    }).addTo(map);

    // Layer groups: links → link labels → devices
    var linkLayer      = L.layerGroup().addTo(map);
    var linkLabelLayer = L.layerGroup().addTo(map);
    var deviceLayer    = L.layerGroup().addTo(map);

    var tvFirstLoad = true;

    // Compact speed format for always-visible link labels: "12M", "450K", "1.2G"
    function formatSpeedCompact(bps) {
        if (bps >= 1e9) { return (bps / 1e9).toFixed(1) + 'G'; }
        if (bps >= 1e6) { return (bps / 1e6).toFixed(0) + 'M'; }
        if (bps >= 1e3) { return (bps / 1e3).toFixed(0) + 'K'; }
        return bps + 'b';
    }

    function escapeHtml(str) {
        if (str == null) { return ''; }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function deviceColor(d) {
        if (d.status === 1) { return '#2ecc71'; }
        if (d.status === 0) { return '#e74c3c'; }
        return '#95a5a6';
    }

    // Link color: manual=blue, down=red, ≥80%=red, ≥50%=orange, up<50%=green
    function linkColor(link) {
        if (link.type === 'manual')                           { return '#3498db'; }
        if (link.status === 'down')                           { return '#e74c3c'; }
        if ((link.utilization_pct || 0) >= 80)               { return '#e74c3c'; }
        if ((link.utilization_pct || 0) >= 50)               { return '#f39c12'; }
        if (link.status === 'up')                             { return '#2ecc71'; }
        return '#95a5a6';
    }

    // Logarithmic weight: 0%→1px, 100%→8px
    function linkWeight(pct) {
        if (!pct || pct <= 0) { return 1; }
        var clamped = Math.min(pct, 100);
        return Math.max(1, Math.min(8, 1 + 7 * Math.log(1 + clamped) / Math.log(101)));
    }

    function renderMap(devices, links) {
        linkLayer.clearLayers();
        linkLabelLayer.clearLayers();
        deviceLayer.clearLayers();

        // Build device coordinate lookup for link rendering
        var deviceCoords = {};
        devices.forEach(function (d) { deviceCoords[d.id] = [d.lat, d.lng]; });

        // Pre-pass: for each canonical device pair, keep the link with highest in_bps.
        var bestLabelLink = {};
        links.forEach(function (link) {
            if (!link.in_bps || link.in_bps <= 0) { return; }
            var a = link.local_device_id, b = link.remote_device_id;
            var pairKey = Math.min(a, b) + '-' + Math.max(a, b);
            if (!bestLabelLink[pairKey] || link.in_bps > bestLabelLink[pairKey].in_bps) {
                bestLabelLink[pairKey] = link;
            }
        });

        // Draw link polylines (below devices)
        links.forEach(function (link) {
            var from = deviceCoords[link.local_device_id];
            var to   = deviceCoords[link.remote_device_id];
            if (!from || !to) { return; }
            var opts = {
                color:       linkColor(link),
                weight:      linkWeight(link.utilization_pct || 0),
                opacity:     0.55,
                interactive: false
            };
            if (link.type === 'manual') { opts.dashArray = '6, 4'; }
            L.polyline([from, to], opts).addTo(linkLayer);

            // Always-visible traffic label at midpoint — only if bps > 0, link is
            // long enough (>100 px) and this link has the highest in_bps for this pair.
            if (link.in_bps > 0) {
                var a2 = link.local_device_id, b2 = link.remote_device_id;
                var pairKey = Math.min(a2, b2) + '-' + Math.max(a2, b2);
                if (bestLabelLink[pairKey] === link) {
                    var fromPx   = map.latLngToContainerPoint(from);
                    var toPx     = map.latLngToContainerPoint(to);
                    var pixelLen = Math.sqrt(Math.pow(fromPx.x - toPx.x, 2) + Math.pow(fromPx.y - toPx.y, 2));
                    if (pixelLen > 100) {
                        var midLat  = (from[0] + to[0]) / 2;
                        var midLng  = (from[1] + to[1]) / 2;
                        var lblHtml = '\u2193' + formatSpeedCompact(link.in_bps) + ' \u2191' + formatSpeedCompact(link.out_bps);
                        var icon    = L.divIcon({
                            className: '',
                            html: '<div class="netmap-link-label">' + lblHtml + '</div>',
                            iconAnchor: [0, 0]
                        });
                        L.marker([midLat, midLng], { icon: icon, interactive: false }).addTo(linkLabelLayer);
                    }
                }
            }
        });

        // Render device circles and labels
        devices.forEach(function (d) {
            L.circleMarker([d.lat, d.lng], {
                radius: 8,
                fillColor: deviceColor(d),
                fillOpacity: 0.85,
                weight: 2,
                color: '#fff',
                interactive: false
            }).addTo(deviceLayer);

            var labelIcon = L.divIcon({
                className: '',
                html: '<div class="tv-label">' + escapeHtml(d.display_name) + '</div>',
                iconAnchor: [0, 0]
            });
            L.marker([d.lat, d.lng], { icon: labelIcon, interactive: false }).addTo(deviceLayer);
        });

        // Fit bounds only on first load — preserve user pan/zoom on refresh
        if (tvFirstLoad && devices.length > 0) {
            var bounds = L.latLngBounds(devices.map(function (d) { return [d.lat, d.lng]; }));
            map.fitBounds(bounds, { padding: [40, 40] });
            tvFirstLoad = false;
        }
    }

    function updateStatus(upCount, downCount) {
        var upEl   = document.querySelector('#tv-status .up');
        var downEl = document.querySelector('#tv-status .down');
        if (upEl)   { upEl.textContent   = 'UP: '   + upCount; }
        if (downEl) { downEl.textContent = 'DOWN: ' + downCount; }
    }

    function updateTimestamp() {
        var now = new Date();
        document.getElementById('tv-timestamp').textContent =
            'Actualizado: ' + now.toTimeString().slice(0, 8);
    }

    function softRefresh() {
        fetch(JSON_URL)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderMap(data.devices || [], data.links || []);
                updateStatus(data.up_count || 0, data.down_count || 0);
                updateTimestamp();
            })
            .catch(function () { /* silent fail — try next interval */ });
    }

    // Initial render from server-side embedded data (no network request on first load)
    var devices = <?= $devices_json ?>;
    var links   = <?= $links_json; ?>;
    renderMap(devices, links);
    updateTimestamp();

    // Soft refresh at configured interval (no page reload — no flash)
    setInterval(softRefresh, REFRESH_MS);
})();
</script>
</body>
</html>
<?php exit; ?>
