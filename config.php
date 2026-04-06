<?php
// Admin-only page
if (! auth()->check() || ! auth()->user()->hasRole('admin')) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Acceso denegado: se requiere rol administrador.</div>';
    return;  // NOTE: use return, not exit — let the Blade template finish rendering
}

// Load current settings
$setting_map_provider      = netmap_get_setting('map_provider',           'osm');
$setting_google_api_key    = netmap_get_setting('google_api_key',         '');
$setting_refresh_interval  = (int) netmap_get_setting('refresh_interval', 60);
$setting_zoom_threshold    = (int) netmap_get_setting('zoom_threshold_cluster', 18);
$setting_tv_token          = netmap_get_setting('tv_token', '');
$excluded_json             = netmap_get_setting('excluded_locations', '[]');
$excluded_ids              = json_decode($excluded_json, true);
if (!is_array($excluded_ids)) { $excluded_ids = []; }
$excluded_ids = array_map('intval', $excluded_ids);

$hidden_links_json         = netmap_get_setting('hidden_links', '[]');
$hidden_links_arr          = json_decode($hidden_links_json, true);
if (!is_array($hidden_links_arr)) { $hidden_links_arr = []; }

$link_priorities_json      = netmap_get_setting('link_priorities', '{}');
$link_priorities_arr       = json_decode($link_priorities_json, true);
if (!is_array($link_priorities_arr)) { $link_priorities_arr = []; }

$map_styles_json           = netmap_get_setting('map_styles', '{}');
$map_styles_arr            = json_decode($map_styles_json, true);
if (!is_array($map_styles_arr)) { $map_styles_arr = []; }

// Style defaults (used for PHP-side field rendering and reset buttons)
$style_defaults = [
    'map' => [
        'node_radius'       => 8,    'node_color_up'    => '#2ecc71',
        'node_color_alert'  => '#f39c12', 'node_color_down' => '#e74c3c',
        'label_size'        => 13,   'label_color'      => '#333333',
        'link_width_min'    => 1,    'link_width_max'   => 8,
        'link_color_low'    => '#2ecc71', 'link_color_mid'  => '#f39c12',
        'link_color_high'   => '#e74c3c', 'link_color_manual' => '#3498db',
        'trafficlabel_size' => 12,   'trafficlabel_bg'  => '#000000',
        'trafficlabel_color'=> '#ffffff',
    ],
    'tv'  => [
        'node_radius'       => 8,    'node_color_up'    => '#00ff88',
        'node_color_alert'  => '#ffaa00', 'node_color_down' => '#ff3333',
        'label_size'        => 13,   'label_color'      => '#ffffff',
        'link_width_min'    => 1,    'link_width_max'   => 8,
        'link_color_low'    => '#00cc66', 'link_color_mid'  => '#ff8800',
        'link_color_high'   => '#ff2222', 'link_color_manual' => '#4488ff',
        'trafficlabel_size' => 12,   'trafficlabel_bg'  => '#1a1a1a',
        'trafficlabel_color'=> '#00ff88',
    ],
];

// Merge stored values over defaults for each profile
foreach (['map', 'tv'] as $_p) {
    $map_styles_arr[$_p] = array_merge(
        $style_defaults[$_p],
        isset($map_styles_arr[$_p]) && is_array($map_styles_arr[$_p]) ? $map_styles_arr[$_p] : []
    );
}

// Helper to get a style field value for a profile, escaped for HTML
function nm_sv($styles, $profile, $field) {
    return htmlspecialchars((string)($styles[$profile][$field] ?? ''), ENT_QUOTES, 'UTF-8');
}

// Auto-init: on first load, hide all LLDP links (user enables what they want)
$links_filter_initialized  = netmap_get_setting('links_filter_initialized', '0');
if ($links_filter_initialized === '0') {
    if (empty($hidden_links_arr)) {
        $all_lldp_ids = dbFetchRows('SELECT id FROM links WHERE remote_device_id IS NOT NULL', []);
        if (is_array($all_lldp_ids)) {
            $hidden_links_arr = array_map(function ($r) { return 'lldp_' . (int) $r['id']; }, $all_lldp_ids);
            netmap_set_setting('hidden_links', json_encode($hidden_links_arr));
        }
    }
    netmap_set_setting('links_filter_initialized', '1');
}

// Auto-generate TV token if missing
if ($setting_tv_token === '' || $setting_tv_token === null) {
    $setting_tv_token = bin2hex(random_bytes(16));
    netmap_set_setting('tv_token', $setting_tv_token);
}

// Load all devices (for manual link form + label editor)
$all_devices = dbFetchRows('
    SELECT d.device_id,
           COALESCE(d.display, d.sysName, d.hostname) AS display_name
    FROM devices d
    ORDER BY display_name ASC
', []);
if ($all_devices === false) { $all_devices = []; }

// Load existing device labels
$device_labels_rows = dbFetchRows('SELECT device_id, map_label FROM plugin_networkmap_device_labels', []);
if ($device_labels_rows === false) { $device_labels_rows = []; }
$device_labels_map  = [];
foreach ($device_labels_rows as $dlr) {
    $device_labels_map[(int) $dlr['device_id']] = $dlr['map_label'];
}

// Load existing manual links with device names
$manual_links = dbFetchRows('
    SELECT
        ml.id,
        ml.local_device_id,
        ml.remote_device_id,
        ml.label,
        ml.created_at,
        COALESCE(da.display, da.sysName, da.hostname) AS local_name,
        COALESCE(db_.display, db_.sysName, db_.hostname) AS remote_name
    FROM plugin_networkmap_links ml
    JOIN devices da  ON da.device_id  = ml.local_device_id
    JOIN devices db_ ON db_.device_id = ml.remote_device_id
    ORDER BY ml.id DESC
', []);
if ($manual_links === false) { $manual_links = []; }

// Load locations with coordinates and device counts
$locations_list = dbFetchRows('
    SELECT l.id, l.location, l.lat, l.lng,
           COUNT(d.device_id) AS device_count
    FROM locations l
    LEFT JOIN devices d ON d.location_id = l.id
    WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL
      AND l.lat <> 0 AND l.lng <> 0
    GROUP BY l.id
    ORDER BY device_count DESC, l.location ASC
', []);
if ($locations_list === false) { $locations_list = []; }
?>

<div class="panel panel-default">
  <div class="panel-heading"><h3 class="panel-title">Configuración del mapa</h3></div>
  <div class="panel-body">
    <form id="netmap-settings-form">

      <!-- Map provider -->
      <div class="form-group">
        <label for="nm-map-provider">Proveedor de mapa</label>
        <select class="form-control" id="nm-map-provider" name="map_provider">
          <option value="osm" <?= $setting_map_provider === 'osm' ? 'selected' : '' ?>>OpenStreetMap</option>
          <option value="google" <?= $setting_map_provider === 'google' ? 'selected' : '' ?>>Google Maps</option>
        </select>
      </div>

      <!-- Google Maps API key (shown only when Google Maps is selected) -->
      <div class="form-group" id="nm-google-apikey-group"
           style="display:<?= $setting_map_provider === 'google' ? '' : 'none' ?>;">
        <label for="nm-google-api-key">API Key de Google Maps</label>
        <input type="text" class="form-control" id="nm-google-api-key" name="google_api_key"
               value="<?= htmlspecialchars($setting_google_api_key, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="AIza…" autocomplete="off">
        <p class="help-block">Requerida para usar Google Maps. Sin ella se usará OpenStreetMap como fallback.</p>
      </div>

      <!-- Refresh interval -->
      <div class="form-group">
        <label for="nm-refresh">Intervalo de actualización (segundos)</label>
        <select class="form-control" id="nm-refresh" name="refresh_interval">
          <?php foreach ([10, 15, 30, 60, 120] as $v): ?>
          <option value="<?= $v ?>" <?= $setting_refresh_interval === $v ? 'selected' : '' ?>><?= $v ?>s</option>
          <?php endforeach; ?>
        </select>
        <p class="help-block">
          <strong>Nota:</strong> Intervalos de 10–15 s generan consultas frecuentes a la base de datos.
          Usar solo si el servidor soporta la carga adicional.
        </p>
      </div>

      <!-- Zoom threshold cluster -->
      <div class="form-group">
        <label for="nm-zoom">Zoom mínimo para clustering (desagrupar por encima de este zoom)</label>
        <select class="form-control" id="nm-zoom" name="zoom_threshold_cluster">
          <?php for ($z = 10; $z <= 16; $z++): ?>
          <option value="<?= $z ?>" <?= $setting_zoom_threshold === $z ? 'selected' : '' ?>><?= $z ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <!-- TV Token -->
      <div class="form-group">
        <label>Token de acceso TV</label>
        <div class="input-group">
          <input type="text" class="form-control" id="nm-tv-token"
                 value="<?= htmlspecialchars($setting_tv_token, ENT_QUOTES, 'UTF-8') ?>" readonly>
          <span class="input-group-btn">
            <button type="button" class="btn btn-default" id="nm-regen-token">Regenerar</button>
          </span>
        </div>
        <p class="help-block">
          URL de acceso TV:
          <code>/plugin/v1/NetworkMap?view=tv&amp;token=<span id="nm-tv-token-url"><?= htmlspecialchars($setting_tv_token, ENT_QUOTES, 'UTF-8') ?></span></code>
        </p>
      </div>

      <button type="submit" class="btn btn-primary">Guardar configuración</button>
      <span id="nm-save-msg" style="margin-left:10px;display:none;"></span>
    </form>
  </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading"><h3 class="panel-title">Enlaces manuales</h3></div>
  <div class="panel-body">

    <table class="table table-condensed" id="nm-links-table">
      <thead>
        <tr><th>ID</th><th>Origen</th><th>Destino</th><th>Etiqueta</th><th>Creado</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (empty($manual_links)): ?>
        <tr><td colspan="6" class="text-muted text-center">No hay enlaces manuales.</td></tr>
        <?php else: ?>
        <?php foreach ($manual_links as $link): ?>
        <tr id="nm-link-<?= (int)$link['id'] ?>">
          <td><?= (int)$link['id'] ?></td>
          <td><?= htmlspecialchars($link['local_name'],  ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($link['remote_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($link['label'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($link['created_at'],  ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <button class="btn btn-danger btn-xs nm-delete-link"
                    data-id="<?= (int)$link['id'] ?>">Eliminar</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <h4>Añadir enlace manual</h4>
    <form id="nm-add-link-form" class="form-inline" style="margin-top:10px;">
      <div class="form-group" style="margin-right:8px;">
        <label class="sr-only" for="nm-local-dev">Dispositivo A</label>
        <select class="form-control" id="nm-local-dev" name="local_device_id" required>
          <option value="">— Dispositivo A —</option>
          <?php foreach ($all_devices as $d): ?>
          <option value="<?= (int)$d['device_id'] ?>">
            <?= htmlspecialchars($d['display_name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-right:8px;">
        <label class="sr-only" for="nm-remote-dev">Dispositivo B</label>
        <select class="form-control" id="nm-remote-dev" name="remote_device_id" required>
          <option value="">— Dispositivo B —</option>
          <?php foreach ($all_devices as $d): ?>
          <option value="<?= (int)$d['device_id'] ?>">
            <?= htmlspecialchars($d['display_name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-right:8px;">
        <label class="sr-only" for="nm-link-label">Etiqueta (opcional)</label>
        <input type="text" class="form-control" id="nm-link-label"
               name="label" placeholder="Etiqueta (opcional)" maxlength="255">
      </div>
      <button type="submit" class="btn btn-success">Añadir enlace</button>
      <span id="nm-add-link-msg" style="margin-left:10px;display:none;"></span>
    </form>
  </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading"><h3 class="panel-title">Filtro de enlaces</h3></div>
  <div class="panel-body">
    <p class="text-muted" style="margin-bottom:10px;">
      Desmarca "Mostrar" para ocultar un enlace del mapa principal y de la vista TV.
    </p>
    <div id="nm-lf-loading" class="text-muted">Cargando enlaces…</div>
    <form id="nm-links-filter-form" style="display:none;">
      <div style="margin-bottom:8px;">
        <button type="button" class="btn btn-default btn-xs" id="nm-lf-enable-all">Activar todos</button>
        <button type="button" class="btn btn-default btn-xs" id="nm-lf-disable-all" style="margin-left:4px;">Desactivar todos</button>
      </div>
      <table class="table table-condensed table-bordered" style="margin-bottom:10px;" id="nm-lf-table">
        <thead>
          <tr>
            <th data-sort="0">ID</th>
            <th data-sort="1">Origen</th>
            <th data-sort="2">Destino</th>
            <th data-sort="3">Puerto local</th>
            <th data-sort="4" data-sort-type="number">Velocidad</th>
            <th data-sort="5" data-sort-type="number">Tráfico&nbsp;(↓/↑)</th>
            <th data-sort="6" data-sort-type="number">Prioridad</th>
            <th>Mostrar</th>
          </tr>
        </thead>
        <tbody id="nm-lf-tbody"></tbody>
      </table>
      <button type="submit" class="btn btn-warning">Guardar filtro de enlaces</button>
      <span id="nm-lf-msg" style="margin-left:10px;display:none;"></span>
    </form>
  </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading"><h3 class="panel-title">Nombres en el mapa</h3></div>
  <div class="panel-body">
    <p class="text-muted" style="margin-bottom:10px;">
      Nombre personalizado que aparece en el mapa para cada dispositivo.
      Deja el campo vacío para usar el nombre por defecto (<code>display → sysName → hostname</code>).
    </p>
    <table class="table table-condensed table-bordered" id="nm-names-table">
      <thead>
        <tr>
          <th data-sort="0" data-sort-type="number">ID</th>
          <th data-sort="1">Hostname</th>
          <th data-sort="2">Nombre en el mapa</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($all_devices)): ?>
        <tr><td colspan="4" class="text-muted text-center">No hay dispositivos con coordenadas.</td></tr>
        <?php else: ?>
        <?php foreach ($all_devices as $d): ?>
        <tr>
          <td><?= (int) $d['device_id'] ?></td>
          <td><?= htmlspecialchars($d['display_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <input type="text" class="form-control input-sm nm-label-input"
                   data-device-id="<?= (int) $d['device_id'] ?>"
                   value="<?= htmlspecialchars($device_labels_map[(int)$d['device_id']] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   maxlength="255" placeholder="<?= htmlspecialchars($d['display_name'], ENT_QUOTES, 'UTF-8') ?>">
          </td>
          <td>
            <button type="button" class="btn btn-primary btn-xs nm-save-label"
                    data-device-id="<?= (int) $d['device_id'] ?>">Guardar</button>
            <span class="nm-label-msg" data-device-id="<?= (int) $d['device_id'] ?>"
                  style="margin-left:6px;display:none;"></span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading"><h3 class="panel-title">Locations en el mapa</h3></div>
  <div class="panel-body">
    <p class="text-muted" style="margin-bottom:10px;">
      Locations con coordenadas válidas. Marca "Excluir" para ocultar una location del mapa.
    </p>
    <form id="nm-locations-form">
    <table class="table table-condensed table-bordered" style="margin-bottom:10px;" id="nm-locations-table">
      <thead>
        <tr>
          <th data-sort="0" data-sort-type="number">ID</th>
          <th data-sort="1">Nombre</th>
          <th data-sort="2" data-sort-type="number">Lat</th>
          <th data-sort="3" data-sort-type="number">Lng</th>
          <th data-sort="4" data-sort-type="number">Dispositivos</th>
          <th>Excluir</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($locations_list)): ?>
        <tr><td colspan="6" class="text-muted text-center">No hay locations con coordenadas.</td></tr>
        <?php else: ?>
        <?php foreach ($locations_list as $loc): ?>
        <tr>
          <td><?= (int)$loc['id'] ?></td>
          <td><?= htmlspecialchars($loc['location'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($loc['lat'],      ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($loc['lng'],      ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int)$loc['device_count'] ?></td>
          <td class="text-center">
            <input type="checkbox" name="excluded_location" value="<?= (int)$loc['id'] ?>"
              <?= in_array((int)$loc['id'], $excluded_ids, true) ? 'checked' : '' ?>>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    <button type="submit" class="btn btn-warning">Guardar filtro de locations</button>
    <span id="nm-loc-msg" style="margin-left:10px;display:none;"></span>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Panel: Estilos del mapa                                                 -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="panel panel-default">
  <div class="panel-heading"><h3 class="panel-title">Estilos del mapa</h3></div>
  <div class="panel-body">

    <!-- Profile tabs -->
    <ul class="nav nav-tabs" id="nm-styles-tabs" style="margin-bottom:16px;">
      <li class="active"><a href="#nm-styles-map" data-toggle="tab">Mapa normal</a></li>
      <li><a href="#nm-styles-tv" data-toggle="tab">Modo TV</a></li>
    </ul>

    <div class="tab-content">

      <?php foreach (['map' => 'Mapa normal', 'tv' => 'Modo TV'] as $prof => $profLabel): ?>
      <div class="tab-pane <?= $prof === 'map' ? 'active' : '' ?>" id="nm-styles-<?= $prof ?>">

        <!-- SVG preview -->
        <div style="margin-bottom:14px;">
          <strong>Vista previa</strong>
          <svg id="nm-preview-<?= $prof ?>" width="240" height="80"
               style="display:block;border:1px solid #ddd;border-radius:4px;margin-top:6px;background:#f8f8f8;">
            <!-- link line -->
            <line id="nm-prev-link-<?= $prof ?>" x1="30" y1="40" x2="210" y2="40"
                  stroke="<?= nm_sv($map_styles_arr, $prof, 'link_color_low') ?>"
                  stroke-width="<?= nm_sv($map_styles_arr, $prof, 'link_width_min') ?>"/>
            <!-- node A -->
            <circle id="nm-prev-nodeA-<?= $prof ?>" cx="30" cy="40"
                    r="<?= nm_sv($map_styles_arr, $prof, 'node_radius') ?>"
                    fill="<?= nm_sv($map_styles_arr, $prof, 'node_color_up') ?>"/>
            <!-- node B (alert) -->
            <circle id="nm-prev-nodeB-<?= $prof ?>" cx="120" cy="40"
                    r="<?= nm_sv($map_styles_arr, $prof, 'node_radius') ?>"
                    fill="<?= nm_sv($map_styles_arr, $prof, 'node_color_alert') ?>"/>
            <!-- node C (down) -->
            <circle id="nm-prev-nodeC-<?= $prof ?>" cx="210" cy="40"
                    r="<?= nm_sv($map_styles_arr, $prof, 'node_radius') ?>"
                    fill="<?= nm_sv($map_styles_arr, $prof, 'node_color_down') ?>"/>
            <!-- label -->
            <text id="nm-prev-label-<?= $prof ?>" x="30" y="62"
                  font-size="<?= nm_sv($map_styles_arr, $prof, 'label_size') ?>"
                  fill="<?= nm_sv($map_styles_arr, $prof, 'label_color') ?>"
                  text-anchor="middle" font-weight="bold">Router</text>
            <!-- traffic label -->
            <rect id="nm-prev-tlbg-<?= $prof ?>" x="95" y="22" width="50" height="16" rx="2"
                  fill="<?= nm_sv($map_styles_arr, $prof, 'trafficlabel_bg') ?>"/>
            <text id="nm-prev-tl-<?= $prof ?>" x="120" y="34"
                  font-size="<?= nm_sv($map_styles_arr, $prof, 'trafficlabel_size') ?>"
                  fill="<?= nm_sv($map_styles_arr, $prof, 'trafficlabel_color') ?>"
                  text-anchor="middle" font-weight="bold">↓12M ↑3M</text>
          </svg>
        </div>

        <form class="nm-styles-form" data-profile="<?= $prof ?>">
          <div class="row">

            <!-- Nodos -->
            <div class="col-sm-6">
              <h5 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:4px;">Nodos</h5>
              <div class="form-group">
                <label>Radio del círculo (px)</label>
                <input type="number" class="form-control input-sm nm-style-field"
                       name="node_radius" min="4" max="20" step="1"
                       value="<?= nm_sv($map_styles_arr, $prof, 'node_radius') ?>">
              </div>
              <div class="form-group">
                <label>Color nodo UP</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="node_color_up"
                       value="<?= nm_sv($map_styles_arr, $prof, 'node_color_up') ?>">
              </div>
              <div class="form-group">
                <label>Color nodo UP con alertas</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="node_color_alert"
                       value="<?= nm_sv($map_styles_arr, $prof, 'node_color_alert') ?>">
              </div>
              <div class="form-group">
                <label>Color nodo DOWN</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="node_color_down"
                       value="<?= nm_sv($map_styles_arr, $prof, 'node_color_down') ?>">
              </div>
            </div>

            <!-- Labels -->
            <div class="col-sm-6">
              <h5 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:4px;">Etiquetas de dispositivo</h5>
              <div class="form-group">
                <label>Tamaño fuente (px)</label>
                <input type="number" class="form-control input-sm nm-style-field"
                       name="label_size" min="10" max="20" step="1"
                       value="<?= nm_sv($map_styles_arr, $prof, 'label_size') ?>">
              </div>
              <div class="form-group">
                <label>Color texto</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="label_color"
                       value="<?= nm_sv($map_styles_arr, $prof, 'label_color') ?>">
              </div>
            </div>

          </div><!-- /.row -->

          <div class="row" style="margin-top:8px;">

            <!-- Enlaces -->
            <div class="col-sm-6">
              <h5 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:4px;">Enlaces</h5>
              <div class="form-group">
                <label>Grosor mínimo (px)</label>
                <input type="number" class="form-control input-sm nm-style-field"
                       name="link_width_min" min="1" max="4" step="1"
                       value="<?= nm_sv($map_styles_arr, $prof, 'link_width_min') ?>">
              </div>
              <div class="form-group">
                <label>Grosor máximo (px)</label>
                <input type="number" class="form-control input-sm nm-style-field"
                       name="link_width_max" min="4" max="12" step="1"
                       value="<?= nm_sv($map_styles_arr, $prof, 'link_width_max') ?>">
              </div>
              <div class="form-group">
                <label>Color enlace &lt;50% uso</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="link_color_low"
                       value="<?= nm_sv($map_styles_arr, $prof, 'link_color_low') ?>">
              </div>
              <div class="form-group">
                <label>Color enlace 50–80% uso</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="link_color_mid"
                       value="<?= nm_sv($map_styles_arr, $prof, 'link_color_mid') ?>">
              </div>
              <div class="form-group">
                <label>Color enlace &gt;80% o DOWN</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="link_color_high"
                       value="<?= nm_sv($map_styles_arr, $prof, 'link_color_high') ?>">
              </div>
              <div class="form-group">
                <label>Color enlace manual</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="link_color_manual"
                       value="<?= nm_sv($map_styles_arr, $prof, 'link_color_manual') ?>">
              </div>
            </div>

            <!-- Etiquetas velocidad -->
            <div class="col-sm-6">
              <h5 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:4px;">Etiqueta de velocidad (↓/↑)</h5>
              <div class="form-group">
                <label>Tamaño fuente (px)</label>
                <input type="number" class="form-control input-sm nm-style-field"
                       name="trafficlabel_size" min="9" max="16" step="1"
                       value="<?= nm_sv($map_styles_arr, $prof, 'trafficlabel_size') ?>">
              </div>
              <div class="form-group">
                <label>Color fondo</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="trafficlabel_bg"
                       value="<?= nm_sv($map_styles_arr, $prof, 'trafficlabel_bg') ?>">
              </div>
              <div class="form-group">
                <label>Color texto</label>
                <input type="color" class="form-control input-sm nm-style-field"
                       name="trafficlabel_color"
                       value="<?= nm_sv($map_styles_arr, $prof, 'trafficlabel_color') ?>">
              </div>
            </div>

          </div><!-- /.row -->

          <div style="margin-top:12px;">
            <button type="submit" class="btn btn-primary btn-sm">Guardar estilos (<?= htmlspecialchars($profLabel, ENT_QUOTES, 'UTF-8') ?>)</button>
            <button type="button" class="btn btn-default btn-sm nm-styles-reset" data-profile="<?= $prof ?>"
                    style="margin-left:8px;">Restablecer defaults</button>
            <span class="nm-styles-msg" data-profile="<?= $prof ?>"
                  style="margin-left:10px;display:none;"></span>
          </div>

        </form>
      </div><!-- /.tab-pane -->
      <?php endforeach; ?>

    </div><!-- /.tab-content -->
  </div>
</div>

<script>
(function() {
    'use strict';

    var API_SETTINGS = '/plugin/v1/NetworkMap?api=settings';
    var API_LINKS    = '/plugin/v1/NetworkMap?api=links';
    var API_DEVICES  = '/plugin/v1/NetworkMap?api=devices';
    var API_LABELS   = '/plugin/v1/NetworkMap?api=labels';

    var linkPrioritiesObj = <?= json_encode($link_priorities_arr) ?>;

    function escapeHtml(str) {
        if (str == null) { return ''; }
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function fmtBps(bps) {
        if (!bps || bps <= 0) { return '—'; }
        if (bps >= 1e9) { return (bps / 1e9).toFixed(1) + ' Gbps'; }
        if (bps >= 1e6) { return (bps / 1e6).toFixed(1) + ' Mbps'; }
        if (bps >= 1e3) { return (bps / 1e3).toFixed(1) + ' Kbps'; }
        return bps + ' bps';
    }

    function getCsrfToken() {
        var match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        return match ? decodeURIComponent(match[1]) : '';
    }

    var hiddenLinksArr = <?= json_encode($hidden_links_arr) ?>;

    // ── Link filter ───────────────────────────────────────────────────────

    (function loadLinkFilter() {
        Promise.all([
            fetch(API_DEVICES, { credentials: 'same-origin' }).then(function(r) { return r.json(); }),
            fetch(API_LINKS,   { credentials: 'same-origin' }).then(function(r) { return r.json(); })
        ]).then(function(results) {
            var devMap = {};
            (results[0].devices || []).forEach(function(d) {
                devMap[d.id] = d.display_name || ('device_' + d.id);
            });
            var links = results[1].links || [];

            // Sort by priority ascending (unset = 999), then by id
            links.sort(function(a, b) {
                var pa = (linkPrioritiesObj[a.id] !== undefined) ? linkPrioritiesObj[a.id] : 999;
                var pb = (linkPrioritiesObj[b.id] !== undefined) ? linkPrioritiesObj[b.id] : 999;
                if (pa !== pb) { return pa - pb; }
                return String(a.id).localeCompare(String(b.id));
            });

            var tbody = document.getElementById('nm-lf-tbody');
            tbody.innerHTML = '';
            if (links.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center">No hay enlaces.</td></tr>';
            } else {
                links.forEach(function(link) {
                    var isHidden  = hiddenLinksArr.indexOf(link.id) !== -1;
                    var priority  = (linkPrioritiesObj[link.id] !== undefined) ? linkPrioritiesObj[link.id] : '';
                    var traffic   = (link.in_bps > 0 || link.out_bps > 0)
                        ? '\u2193' + fmtBps(link.in_bps) + ' / \u2191' + fmtBps(link.out_bps)
                        : '—';
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td><code>' + escapeHtml(link.id) + '</code></td>' +
                        '<td>' + escapeHtml(devMap[link.local_device_id]  || String(link.local_device_id))  + '</td>' +
                        '<td>' + escapeHtml(devMap[link.remote_device_id] || String(link.remote_device_id)) + '</td>' +
                        '<td>' + escapeHtml(link.local_port || '—') + '</td>' +
                        '<td data-sort-val="' + (link.speed_bps || 0) + '">' + escapeHtml(fmtBps(link.speed_bps)) + '</td>' +
                        '<td data-sort-val="' + (link.in_bps || 0) + '">' + escapeHtml(traffic) + '</td>' +
                        '<td class="text-center" data-sort-val="' + (priority !== '' ? priority : 999) + '">' +
                          '<input type="number" name="link_priority" min="1" max="99" style="width:60px;"' +
                          ' data-link-id="' + escapeHtml(link.id) + '"' +
                          ' value="' + escapeHtml(String(priority)) + '" placeholder="—">' +
                        '</td>' +
                        '<td class="text-center"><input type="checkbox" name="link_visible"' +
                        ' value="' + escapeHtml(link.id) + '"' + (isHidden ? '' : ' checked') + '></td>';
                    tbody.appendChild(tr);
                });
            }
            document.getElementById('nm-lf-loading').style.display = 'none';
            document.getElementById('nm-links-filter-form').style.display = '';
            makeTableSortable('nm-lf-table');
        }).catch(function() {
            document.getElementById('nm-lf-loading').textContent = 'Error al cargar enlaces.';
        });
    })();

    document.getElementById('nm-lf-enable-all').addEventListener('click', function() {
        document.querySelectorAll('input[name="link_visible"]').forEach(function(el) { el.checked = true; });
    });

    document.getElementById('nm-lf-disable-all').addEventListener('click', function() {
        document.querySelectorAll('input[name="link_visible"]').forEach(function(el) { el.checked = false; });
    });

    document.getElementById('nm-links-filter-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var unchecked = document.querySelectorAll('input[name="link_visible"]:not(:checked)');
        var hidden    = Array.from(unchecked).map(function(el) { return el.value; });

        // Collect priorities
        var priorities = {};
        document.querySelectorAll('input[name="link_priority"]').forEach(function(el) {
            var val = el.value.trim();
            if (val !== '' && !isNaN(parseInt(val, 10))) {
                priorities[el.getAttribute('data-link-id')] = parseInt(val, 10);
            }
        });

        var msgEl = document.getElementById('nm-lf-msg');
        msgEl.style.display = '';
        msgEl.textContent = 'Guardando…';
        msgEl.style.color = '#555';

        Promise.all([
            fetch(API_SETTINGS, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ key: 'hidden_links', value: JSON.stringify(hidden) })
            }),
            fetch(API_SETTINGS, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ key: 'link_priorities', value: JSON.stringify(priorities) })
            })
        ])
        .then(function(responses) {
            return Promise.all(responses.map(function(r) { return r.json(); }));
        })
        .then(function(results) {
            var ok = results.every(function(d) { return d.success; });
            if (ok) {
                msgEl.textContent = '✓ Filtro guardado';
                msgEl.style.color = '#27ae60';
                hiddenLinksArr    = hidden;
                linkPrioritiesObj = priorities;
            } else {
                msgEl.textContent = '✗ ' + (results[0].error || results[1].error || 'Error');
                msgEl.style.color = '#c0392b';
            }
            setTimeout(function() { msgEl.style.display = 'none'; }, 3000);
        })
        .catch(function() {
            msgEl.textContent = '✗ Error de red';
            msgEl.style.color = '#c0392b';
        });
    });

    // ── Google Maps API key field visibility ──────────────────────────────

    document.getElementById('nm-map-provider').addEventListener('change', function () {
        var grp = document.getElementById('nm-google-apikey-group');
        if (grp) { grp.style.display = (this.value === 'google') ? '' : 'none'; }
    });

    // ── Settings form ────────────────────────────────────────────────────

    document.getElementById('netmap-settings-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var settings = {
            map_provider:           document.getElementById('nm-map-provider').value,
            google_api_key:         document.getElementById('nm-google-api-key').value,
            refresh_interval:       document.getElementById('nm-refresh').value,
            zoom_threshold_cluster: document.getElementById('nm-zoom').value
        };
        var msgEl = document.getElementById('nm-save-msg');
        msgEl.style.display = '';
        msgEl.textContent = 'Guardando…';
        msgEl.style.color = '#555';

        var promises = Object.keys(settings).map(function(key) {
            return fetch(API_SETTINGS, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ key: key, value: settings[key] })
            });
        });

        Promise.all(promises)
            .then(function(responses) {
                var allOk = responses.every(function(r) { return r.ok; });
                msgEl.textContent = allOk ? '✓ Guardado' : '✗ Error al guardar';
                msgEl.style.color = allOk ? '#27ae60' : '#c0392b';
                setTimeout(function() { msgEl.style.display = 'none'; }, 3000);
            })
            .catch(function() {
                msgEl.textContent = '✗ Error de red';
                msgEl.style.color = '#c0392b';
            });
    });

    // ── TV token regeneration ─────────────────────────────────────────────

    document.getElementById('nm-regen-token').addEventListener('click', function() {
        fetch(API_SETTINGS, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify({ action: 'regenerate_tv_token' })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.tv_token) {
                document.getElementById('nm-tv-token').value = data.tv_token;
                document.getElementById('nm-tv-token-url').textContent = data.tv_token;
            }
        });
    });

    // ── Delete manual link ────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.nm-delete-link');
        if (!btn) { return; }
        var id = btn.getAttribute('data-id');
        if (!id || !confirm('¿Eliminar enlace #' + id + '?')) { return; }
        fetch(API_LINKS + '&id=' + encodeURIComponent(id), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-XSRF-TOKEN': getCsrfToken() }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var row = document.getElementById('nm-link-' + id);
                if (row) { row.parentNode.removeChild(row); }
            }
        });
    });

    // ── Location filter ───────────────────────────────────────────────────

    document.getElementById('nm-locations-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var checked = document.querySelectorAll('input[name="excluded_location"]:checked');
        var ids = Array.from(checked).map(function(el) { return parseInt(el.value, 10); });
        var msgEl = document.getElementById('nm-loc-msg');
        msgEl.style.display = '';
        msgEl.textContent = 'Guardando…';
        msgEl.style.color = '#555';

        fetch(API_SETTINGS, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify({ key: 'excluded_locations', value: JSON.stringify(ids) })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                msgEl.textContent = '✓ Filtro guardado';
                msgEl.style.color = '#27ae60';
            } else {
                msgEl.textContent = '✗ ' + (data.error || 'Error');
                msgEl.style.color = '#c0392b';
            }
            setTimeout(function() { msgEl.style.display = 'none'; }, 3000);
        })
        .catch(function() {
            msgEl.textContent = '✗ Error de red';
            msgEl.style.color = '#c0392b';
        });
    });

    // ── Device label save ─────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.nm-save-label');
        if (!btn) { return; }
        var deviceId = parseInt(btn.getAttribute('data-device-id'), 10);
        var input    = document.querySelector('.nm-label-input[data-device-id="' + deviceId + '"]');
        var msgEl    = document.querySelector('.nm-label-msg[data-device-id="' + deviceId + '"]');
        if (!input || !msgEl) { return; }
        var label = input.value.trim();
        msgEl.style.display = '';
        msgEl.textContent   = 'Guardando…';
        msgEl.style.color   = '#555';
        fetch(API_LABELS, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify({ device_id: deviceId, map_label: label })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                msgEl.textContent = '✓';
                msgEl.style.color = '#27ae60';
            } else {
                msgEl.textContent = '✗ ' + (data.error || 'Error');
                msgEl.style.color = '#c0392b';
            }
            setTimeout(function() { msgEl.style.display = 'none'; }, 2500);
        })
        .catch(function() {
            msgEl.textContent = '✗ Error de red';
            msgEl.style.color = '#c0392b';
        });
    });

    // ── Add manual link ───────────────────────────────────────────────────

    document.getElementById('nm-add-link-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var localId  = parseInt(document.getElementById('nm-local-dev').value,  10);
        var remoteId = parseInt(document.getElementById('nm-remote-dev').value, 10);
        var label    = document.getElementById('nm-link-label').value.trim();
        var msgEl    = document.getElementById('nm-add-link-msg');

        if (!localId || !remoteId || localId === remoteId) {
            msgEl.style.display = '';
            msgEl.textContent = '✗ Selecciona dos dispositivos distintos';
            msgEl.style.color = '#c0392b';
            return;
        }

        var body = { action: 'add_manual', local_device_id: localId, remote_device_id: remoteId };
        if (label) { body.label = label; }

        fetch(API_LINKS, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                msgEl.style.display = '';
                msgEl.textContent = '✓ Enlace añadido (recarga la página para verlo)';
                msgEl.style.color = '#27ae60';
                document.getElementById('nm-add-link-form').reset();
            } else {
                msgEl.style.display = '';
                msgEl.textContent = '✗ ' + (data.error || 'Error desconocido');
                msgEl.style.color = '#c0392b';
            }
        });
    });

    // ── Sortable tables ──────────────────────────────────────────────────

    function makeTableSortable(tableId) {
        var table = document.getElementById(tableId);
        if (!table) { return; }
        var headers = table.querySelectorAll('thead th[data-sort]');
        headers.forEach(function(th) {
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            th.setAttribute('data-label', th.textContent.trim());
            th.addEventListener('click', function() {
                var col  = parseInt(th.getAttribute('data-sort'), 10);
                var type = th.getAttribute('data-sort-type') || 'string';
                var dir  = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';

                headers.forEach(function(h) {
                    h.removeAttribute('data-dir');
                    h.textContent = h.getAttribute('data-label');
                });
                th.setAttribute('data-dir', dir);
                th.textContent = th.getAttribute('data-label') + (dir === 'asc' ? ' ↑' : ' ↓');

                var tbody = table.querySelector('tbody');
                var rows  = Array.from(tbody.querySelectorAll('tr'));
                if (rows.length <= 1) { return; }

                rows.sort(function(a, b) {
                    var ac = a.cells[col], bc = b.cells[col];
                    var av = ac ? (ac.getAttribute('data-sort-val') || ac.textContent.trim()) : '';
                    var bv = bc ? (bc.getAttribute('data-sort-val') || bc.textContent.trim()) : '';
                    if (type === 'number') {
                        av = parseFloat(av) || 0;
                        bv = parseFloat(bv) || 0;
                        return dir === 'asc' ? av - bv : bv - av;
                    }
                    return dir === 'asc'
                        ? av.localeCompare(bv, 'es', { sensitivity: 'base' })
                        : bv.localeCompare(av, 'es', { sensitivity: 'base' });
                });
                rows.forEach(function(row) { tbody.appendChild(row); });
            });
        });
    }

    makeTableSortable('nm-names-table');
    makeTableSortable('nm-locations-table');

    // ── Map styles panel ──────────────────────────────────────────────────

    var STYLE_DEFAULTS = {
        'map': {
            node_radius: 8, node_color_up: '#2ecc71', node_color_alert: '#f39c12',
            node_color_down: '#e74c3c', label_size: 13, label_color: '#333333',
            link_width_min: 1, link_width_max: 8, link_color_low: '#2ecc71',
            link_color_mid: '#f39c12', link_color_high: '#e74c3c',
            link_color_manual: '#3498db', trafficlabel_size: 12,
            trafficlabel_bg: '#000000', trafficlabel_color: '#ffffff'
        },
        'tv': {
            node_radius: 8, node_color_up: '#00ff88', node_color_alert: '#ffaa00',
            node_color_down: '#ff3333', label_size: 13, label_color: '#ffffff',
            link_width_min: 1, link_width_max: 8, link_color_low: '#00cc66',
            link_color_mid: '#ff8800', link_color_high: '#ff2222',
            link_color_manual: '#4488ff', trafficlabel_size: 12,
            trafficlabel_bg: '#1a1a1a', trafficlabel_color: '#00ff88'
        }
    };

    function updateStylePreview(prof) {
        var pane = document.getElementById('nm-styles-' + prof);
        if (!pane) { return; }
        var get = function(name) {
            var el = pane.querySelector('.nm-style-field[name="' + name + '"]');
            return el ? el.value : null;
        };
        var nodeA   = document.getElementById('nm-prev-nodeA-' + prof);
        var nodeB   = document.getElementById('nm-prev-nodeB-' + prof);
        var nodeC   = document.getElementById('nm-prev-nodeC-' + prof);
        var link    = document.getElementById('nm-prev-link-' + prof);
        var label   = document.getElementById('nm-prev-label-' + prof);
        var tlbg    = document.getElementById('nm-prev-tlbg-' + prof);
        var tl      = document.getElementById('nm-prev-tl-' + prof);

        if (nodeA) { nodeA.setAttribute('r', get('node_radius') || 8); nodeA.setAttribute('fill', get('node_color_up') || ''); }
        if (nodeB) { nodeB.setAttribute('r', get('node_radius') || 8); nodeB.setAttribute('fill', get('node_color_alert') || ''); }
        if (nodeC) { nodeC.setAttribute('r', get('node_radius') || 8); nodeC.setAttribute('fill', get('node_color_down') || ''); }
        if (link)  { link.setAttribute('stroke', get('link_color_low') || ''); link.setAttribute('stroke-width', get('link_width_min') || 1); }
        if (label) { label.setAttribute('font-size', get('label_size') || 13); label.setAttribute('fill', get('label_color') || ''); }
        if (tlbg)  { tlbg.setAttribute('fill', get('trafficlabel_bg') || ''); }
        if (tl)    { tl.setAttribute('font-size', get('trafficlabel_size') || 12); tl.setAttribute('fill', get('trafficlabel_color') || ''); }
    }

    // Real-time preview on input change
    ['map', 'tv'].forEach(function(prof) {
        var pane = document.getElementById('nm-styles-' + prof);
        if (!pane) { return; }
        pane.addEventListener('input', function(e) {
            if (e.target.classList.contains('nm-style-field')) {
                updateStylePreview(prof);
            }
        });
    });

    // Save styles form
    document.querySelectorAll('.nm-styles-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var prof    = form.getAttribute('data-profile');
            var msgEl   = form.querySelector('.nm-styles-msg[data-profile="' + prof + '"]');
            var fields  = form.querySelectorAll('.nm-style-field');
            var data    = {};
            fields.forEach(function(el) {
                var v = el.value;
                if (el.type === 'number') {
                    data[el.name] = parseFloat(v) || 0;
                } else {
                    data[el.name] = v;
                }
            });

            msgEl.style.display = '';
            msgEl.textContent   = 'Guardando…';
            msgEl.style.color   = '#555';

            // Fetch current styles, merge this profile, then save the full object
            fetch(API_SETTINGS, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(current) {
                var full = {};
                try { full = JSON.parse((current.settings && current.settings.map_styles) || '{}'); } catch(x) {}
                if (typeof full !== 'object' || full === null) { full = {}; }
                full[prof] = data;
                return fetch(API_SETTINGS, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
                    body: JSON.stringify({ key: 'map_styles', value: JSON.stringify(full) })
                }).then(function(r) { return r.json(); });
            })
            .then(function(res) {
                if (res.success) {
                    msgEl.textContent = '✓ Estilos guardados';
                    msgEl.style.color = '#27ae60';
                } else {
                    msgEl.textContent = '✗ ' + (res.error || 'Error');
                    msgEl.style.color = '#c0392b';
                }
                setTimeout(function() { msgEl.style.display = 'none'; }, 3000);
            })
            .catch(function() {
                msgEl.textContent = '✗ Error de red';
                msgEl.style.color = '#c0392b';
                setTimeout(function() { msgEl.style.display = 'none'; }, 3000);
            });
        });
    });

    // Reset to defaults buttons
    document.querySelectorAll('.nm-styles-reset').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var prof  = btn.getAttribute('data-profile');
            var pane  = document.getElementById('nm-styles-' + prof);
            var defs  = STYLE_DEFAULTS[prof];
            if (!pane || !defs) { return; }
            pane.querySelectorAll('.nm-style-field').forEach(function(el) {
                if (defs[el.name] !== undefined) {
                    el.value = defs[el.name];
                }
            });
            updateStylePreview(prof);
        });
    });

})();
</script>
