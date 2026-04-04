# LibreNMS NetworkMap Plugin

Interactive geographic network map plugin for LibreNMS. Displays all monitored
devices as colored nodes on an OpenStreetMap (or Google Maps) base layer, with
real-time LLDP/manual links, traffic utilization, and automatic geographic
clustering.

## Features

- **Real-time device status** — green (UP), orange (UP + alerts), red (DOWN)
- **Link utilization** — color-coded by throughput: green (<50%), orange (50–80%), red (≥80% or DOWN), blue dashed (manual links)
- **Logarithmic link thickness** — 1px at 0% → 8px at 100% utilization
- **Geographic clustering** — devices within 50 m are grouped at low zoom levels; individual nodes appear when zooming in (configurable threshold)
- **Always-visible labels** — device names shown at all zoom levels; groups show "Name (N)" format
- **TV/NOC view** — fullscreen dark-mode display accessible by token, no LibreNMS login required
- **Auto-refresh** — configurable interval (10 / 15 / 30 / 60 / 120 s), soft refresh without page reload
- **Manual links** — add/remove custom links between any two devices
- **Location filter** — exclude specific LibreNMS locations from the map

## Requirements

- LibreNMS ≥ 24.x (tested on 26.x)
- PHP ≥ 8.1
- MariaDB 10.x (same instance as LibreNMS)
- Leaflet 1.9.4 (loaded from CDN with local fallback)

## Installation

```bash
# 1. Clone into the LibreNMS plugins directory
cd /opt/librenms/html/plugins
git clone https://github.com/Mythology1989/librenms-networkmap NetworkMap

# 2. Set permissions
chown -R librenms:librenms NetworkMap/

# 3. Create plugin tables
mysql -u librenms -p librenms < NetworkMap/sql/install.sql

# 4. Activate the plugin
# LibreNMS → Settings → Plugins → NetworkMap → Enable
```

## Configuration

Access the settings panel via the gear icon in the map toolbar or from:
`/plugin/v1/NetworkMap?view=settings`

| Setting | Default | Description |
|---------|---------|-------------|
| Map provider | OSM | OpenStreetMap or Google Maps (requires API key) |
| Refresh interval | 60 s | How often to fetch updated data (10–120 s) |
| Cluster zoom threshold | 13 | Zoom level at which devices expand to individual nodes |
| TV token | (auto) | Token for unauthenticated TV/NOC access |
| Excluded locations | (none) | LibreNMS locations hidden from the map |

## TV/NOC View

Open in any browser (Chromecast, TV display, etc.) without a LibreNMS login:

```
https://your-librenms/plugin/v1/NetworkMap?view=tv&token=<your-token>
```

The token is generated automatically and shown in the settings panel.

## Uninstall

```bash
# Remove plugin tables
mysql -u librenms -p librenms < NetworkMap/sql/uninstall.sql

# Disable in LibreNMS settings, then remove directory
rm -rf /opt/librenms/html/plugins/NetworkMap
```

## File Structure

```
NetworkMap/
├── NetworkMap.php          # Plugin registration (hooks, menu)
├── NetworkMap.inc.php      # Main view/router (served by PluginLegacyController)
├── map.php                 # Main map view
├── tv.php                  # TV/NOC fullscreen view (token auth)
├── config.php              # Admin settings panel
├── api/
│   ├── devices.php         # GET: devices with lat/lng and status
│   ├── links.php           # GET/POST/DELETE: LLDP + manual links with traffic
│   └── settings.php        # GET/POST: plugin configuration
├── includes/
│   └── db.php              # dbFacile helpers and traffic cache functions
├── js/
│   ├── networkmap.js       # All map logic (Leaflet, clustering, rendering)
│   └── leaflet.min.js      # Leaflet 1.9.4 local fallback
├── css/
│   └── networkmap.css      # Plugin styles
└── sql/
    ├── install.sql          # Create plugin_networkmap_* tables
    └── uninstall.sql        # Drop plugin_networkmap_* tables
```

## License

MIT
