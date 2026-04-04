<?php



require_once __DIR__ . '/includes/db.php';

$refresh_interval = (int) netmap_get_setting('refresh_interval', 60);
$map_provider     = netmap_get_setting('map_provider', 'osm');
$google_api_key   = netmap_get_setting('google_api_key', '');
$zoom_threshold   = (int) netmap_get_setting('zoom_threshold_cluster', 13);

?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css"
      id="leaflet-css-cdn">
<link rel="stylesheet" href="/plugins/NetworkMap/css/leaflet.min.css"
      id="leaflet-css-local" disabled>
<script>
// Fallback: if CDN CSS fails to load, enable local copy
document.getElementById('leaflet-css-cdn').addEventListener('error', function() {
    document.getElementById('leaflet-css-local').disabled = false;
});
</script>
<link rel="stylesheet" href="/plugins/NetworkMap/css/networkmap.css">

<div id="netmap-container">
    <div id="netmap-loading">Cargando mapa…</div>
    <div id="netmap"></div>
    <div class="netmap-toolbar">
        <button class="btn" id="netmap-refresh" title="Actualizar">↺</button>
        <a class="btn" href="/plugin/v1/NetworkMap?view=settings" title="Configuración">⚙</a>
    </div>
</div>

<script>
window.netmapConfig = {
    refreshInterval: <?= $refresh_interval ?>,
    mapProvider: <?= json_encode($map_provider) ?>,
    googleApiKey: <?= json_encode($google_api_key) ?>,
    zoomThreshold: <?= $zoom_threshold ?>,
    apiDevices: '/plugin/v1/NetworkMap?api=devices',
    apiLinks:   '/plugin/v1/NetworkMap?api=links',
    apiSettings: '/plugin/v1/NetworkMap?api=settings'
};
</script>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>
<script>
// If CDN failed (L is undefined), load local copy synchronously
if (typeof L === 'undefined') {
    document.write('<script src="/plugins/NetworkMap/js/leaflet.min.js"><\/script>');
}
</script>

<script src="/plugins/NetworkMap/js/networkmap.js"></script>
