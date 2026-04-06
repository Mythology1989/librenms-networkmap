# LibreNMS Network Map Plugin — SPEC v1.0

## Resumen del Proyecto

Plugin para LibreNMS que genera un **mapa geográfico interactivo en tiempo real** de la red,
mostrando todos los dispositivos monitorizados con sus enlaces, estado y tráfico.
Se integra como pestaña nativa en LibreNMS y opcionalmente expone una URL de visualización
para pantallas de TV/NOC.

**Nombre:** `NetworkMap` (ruta: `/plugin/v1/NetworkMap`)
**Repositorio:** https://github.com/Mythology1989/librenms-networkmap
**Licencia:** MIT

---

## Stack Tecnológico

| Capa | Tecnología | Motivo |
|------|-----------|--------|
| Backend | PHP (nativo LibreNMS) | Integración directa con DB y auth |
| Base de datos | MariaDB 10.11 (la misma de LibreNMS) | Sin dependencias externas |
| Frontend mapa | Leaflet.js (CDN) | OSM nativo, ligero, sin API key |
| Frontend opcional | Google Maps JS API | Configurable, requiere API key |
| Actualización | AJAX polling (fetch API) | Sin WebSockets, compatible con todos los setups |
| Stream TV | URL dedicada con autorefresh | Sin dependencias adicionales (no RTSP) |

---

## Arquitectura de Archivos

```
/opt/librenms/html/plugins/NetworkMap/
├── NetworkMap.php          # Clase de registro de hooks (menu) — instanciada por Laravel
├── NetworkMap.inc.php      # Vista/router principal — servida por PluginLegacyController
├── map.php                 # Vista principal del mapa
├── tv.php                  # Vista TV/NOC (fullscreen, token auth, sin navbar)
├── config.php              # Panel de configuración admin
├── api/
│   ├── devices.php         # Devuelve dispositivos con lat/lng y estado
│   ├── links.php           # Devuelve enlaces LLDP + manuales con tráfico
│   └── settings.php        # GET/POST configuración del plugin
├── js/
│   ├── networkmap.js       # Toda la lógica del mapa
│   └── leaflet.min.js      # Leaflet local (fallback si CDN falla)
├── css/
│   └── networkmap.css      # Estilos del plugin
├── includes/
│   └── db.php              # Helpers para queries a LibreNMS DB (dbFacile)
└── sql/
    ├── install.sql         # Creación de tablas plugin_networkmap_*
    └── uninstall.sql       # Drop de tablas propias (NUNCA tablas core)
```

---

## Fuentes de Datos (MariaDB LibreNMS)

### Esquema relevante verificado (2026-04-03)

Las coordenadas geográficas **no están en la tabla `devices`**. Están en la tabla
`locations` (PK: `id`), enlazada mediante `devices.location_id`. El JOIN es
obligatorio en todas las queries que necesiten coordenadas.

```sql
-- Tabla locations (campos relevantes)
-- id, location, lat (decimal 10,8), lng (decimal 11,8), fixed_coordinates

-- Tabla devices (campos relevantes)
-- device_id, hostname, sysName, display, location_id, status, uptime, type, os
```

### Query de dispositivos
```sql
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
  COUNT(a.id) as active_alerts
FROM devices d
JOIN locations l ON l.id = d.location_id
LEFT JOIN plugin_networkmap_device_labels ndl ON ndl.device_id = d.device_id
LEFT JOIN alerts a ON a.device_id = d.device_id AND a.state = 1
WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL
GROUP BY d.device_id
```

> Nota: usar `COUNT(a.id)`, no `COUNT(a.alert_id)` — la tabla `alerts` usa `id` como PK.

### Query de enlaces LLDP (automáticos)
```sql
SELECT
  l.id,
  l.local_device_id,
  l.remote_device_id,
  l.local_port_id,
  l.remote_port_id,
  p_local.ifName as local_port,
  p_remote.ifName as remote_port,
  p_local.ifInOctets,
  p_local.ifOutOctets,
  p_local.ifSpeed,
  p_local.ifOperStatus
FROM links l
JOIN ports p_local ON p_local.port_id = l.local_port_id
LEFT JOIN ports p_remote ON p_remote.port_id = l.remote_port_id
WHERE l.remote_device_id IS NOT NULL
```

### Tablas propias del plugin
```sql
CREATE TABLE IF NOT EXISTS `plugin_networkmap_links` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `local_device_id` INT NOT NULL,
  `remote_device_id` INT NOT NULL,
  `local_port_id`   INT DEFAULT NULL,
  `remote_port_id`  INT DEFAULT NULL,
  `label`           VARCHAR(255) DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `plugin_networkmap_settings` (
  `key`   VARCHAR(100) PRIMARY KEY,
  `value` TEXT
);
-- Valores por defecto:
-- map_provider: 'osm'
-- google_api_key: ''
-- refresh_interval: 60
-- zoom_threshold_cluster: 18
-- default_zoom: 'auto'
-- excluded_locations: '[]'
-- hidden_links: '[]'
-- link_priorities: '{}'

CREATE TABLE IF NOT EXISTS `plugin_networkmap_port_cache` (
  `port_id`       INT PRIMARY KEY,
  `in_octets`     BIGINT DEFAULT 0,
  `out_octets`    BIGINT DEFAULT 0,
  `in_bps`        BIGINT DEFAULT 0,
  `out_bps`       BIGINT DEFAULT 0,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `plugin_networkmap_device_labels` (
  `device_id`   INT PRIMARY KEY,
  `map_label`   VARCHAR(255) NOT NULL,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## API Endpoints (PHP)

### `GET /plugin/v1/NetworkMap?api=devices`
Devuelve JSON con todos los dispositivos que tienen coordenadas.

```json
{
  "devices": [
    {
      "id": 12,
      "hostname": "rb4011-atalaya.fibrat.local",
      "display_name": "Atalaya",
      "lat": 28.5123,
      "lng": -16.3456,
      "location": "Atalaya",
      "status": 1,
      "uptime": 1234567,
      "type": "network",
      "os": "routeros",
      "active_alerts": 0,
      "url": "/device/device=12/"
    }
  ]
}
```

### `GET /plugin/v1/NetworkMap?api=links`
Devuelve enlaces LLDP + manuales con tráfico actual.

```json
{
  "links": [
    {
      "id": "lldp_45",
      "type": "lldp",
      "local_device_id": 12,
      "remote_device_id": 7,
      "local_port": "ether1",
      "remote_port": "ether2",
      "status": "up",
      "speed_bps": 1000000000,
      "in_bps": 324000000,
      "out_bps": 41000000,
      "utilization_pct": 36.5
    }
  ]
}
```

### `GET/POST /plugin/v1/NetworkMap?api=settings`
Lectura y escritura de configuración. Solo accesible para admins de LibreNMS.

---

## Comportamiento del Mapa

### Zoom dinámico (clustering geográfico)
- Dispositivos a menos de 150m entre sí se agrupan en un único nodo visual.
- **Zoom < 18**: se muestran nodos agrupados con nombre "Ubicación (N dispositivos)".
- **Zoom >= 18**: se muestran todos los dispositivos individuales.
- El zoom inicial hace `fitBounds` automático con zoom máximo 17 para mostrar clusters.

### Nodos (marcadores)
- **Círculo** coloreado según estado:
  - Verde: UP, sin alertas
  - Naranja: UP, con alertas activas
  - Rojo: DOWN
- **Label** siempre visible con nombre del dispositivo o grupo.

### Enlaces (líneas)
- **Color por utilización:**
  - Verde: interfaz UP, < 50% uso
  - Naranja: interfaz UP, 50-80% uso
  - Rojo: interfaz UP, > 80% uso o interfaz DOWN
  - Azul discontinuo: enlace manual
- **Grosor** logarítmico: 1px (0%) a 8px (100%)
- **Etiqueta permanente** en el punto medio: "↓12M ↑3M" (solo si in_bps > 0 y enlace > 100px)
- **Arcos Bezier** cuando hay múltiples enlaces entre el mismo par de nodos
- **Tooltip en hover:** puertos local/remoto, velocidad, in/out Mbps, % uso

### Popup al hacer click en nodo
```
[Nombre del dispositivo]
Estado: UP  |  Uptime: 14d 3h
Alertas activas: 0
[Ver en LibreNMS]
```

### Popup al hacer click en enlace
```
[local_port] ---- [remote_port]
Velocidad: 1 Gbps
In:  324 Mbps (32.4%)
Out:  41 Mbps  (4.1%)
Estado: UP
```

---

## Vista TV/NOC (tv.php)

URL: `/plugin/v1/NetworkMap?view=tv&token=<token>`

- Fullscreen sin navbar de LibreNMS
- Fondo oscuro (CARTO dark tiles)
- Autorefresh configurable (soft refresh sin reload)
- Esquina superior derecha: contador UP/DOWN
- Esquina inferior derecha: timestamp del último update
- Sin popups ni interacción — solo visualización
- Acceso por token sin requerir login de LibreNMS
- Usa `networkmap.js` con `tvMode:true` — misma lógica que el mapa principal

---

## Panel de Configuración

`/plugin/v1/NetworkMap?view=settings` — solo accesible para admins.

| Campo | Tipo | Default |
|-------|------|---------|
| Proveedor de mapa | select: OSM / Google Maps | OSM |
| Google Maps API Key | text (condicional) | — |
| Intervalo de refresco | select: 10s / 15s / 30s / 60s / 120s | 60s |
| Zoom de clustering | número (10-19) | 18 |
| Token TV | text generado + botón regenerar | — |
| Locations excluidas | checkboxes por location | ninguna |
| Filtro de enlaces | tabla con checkbox + prioridad | todos visibles |
| Nombres en el mapa | tabla editable de map_label por dispositivo | — |

---

## Instalación

```bash
# 1. Clonar en el directorio de plugins de LibreNMS
cd /opt/librenms/html/plugins
git clone https://github.com/Mythology1989/librenms-networkmap NetworkMap

# 2. Dar permisos
chown -R librenms:librenms NetworkMap/

# 3. Crear tablas adicionales
mysql -u librenms -p librenms < NetworkMap/sql/install.sql

# 4. Activar en LibreNMS
# LibreNMS → Settings → Plugins → NetworkMap → Enable
```

---

## Criterios de Éxito

- [x] Plugin aparece en el menú de LibreNMS sin errores PHP
- [x] Mapa carga con todos los dispositivos que tienen lat/lng en LibreNMS
- [x] Enlaces LLDP se dibujan automáticamente sin configuración manual
- [x] Color y grosor de enlace refleja tráfico real
- [x] Popup de nodo muestra uptime y alertas reales
- [x] Popup de enlace muestra in/out en Mbps y % uso
- [x] Refresco automático funciona sin recargar la página
- [x] Vista TV accesible sin login con token
- [x] Panel de configuración guarda/lee correctamente (OSM ↔ Google Maps)
- [x] Añadir y eliminar enlace manual funciona y aparece en el mapa
- [x] Clustering visible al hacer zoom out

---

## Fuera de Alcance (v1.0)

- Historial de tráfico / gráficas temporales (eso es para Grafana)
- Exportar mapa como imagen
- Edición de posición GPS desde el mapa (se hace desde LibreNMS nativo)
- Notificaciones push en el mapa
- Panel de estilos configurable (colores, tamaños) — previsto para v1.1
- Solapamiento de enlaces entre pares distintos — limitación conocida de v1.0

---

## Notas técnicas para desarrollo futuro

1. **Auth en `api/*.php`:** `auth()->check()` para sesión, `auth()->user()->hasRole('admin')` para admin. Todos los endpoints deben terminar en `exit;`.

2. **DB:** Solo dbFacile — `dbFetchRows()`, `dbFetchRow()`, `dbInsert()`, `dbUpdate()`, `dbDelete()`. No usar `DB::` facade de Laravel.

3. **Parámetros GET:** usar `request()->query('param', 'default')`, no `$_GET`.

4. **URLs del plugin:** `/plugin/v1/NetworkMap?param=valor`. No usar `/plugin/p=NetworkMap`.

5. **Cálculo de tráfico:** `bps = (octets_now - octets_prev) * 8 / seconds_elapsed`. Guardar snapshot en `plugin_networkmap_port_cache`. El poller de LibreNMS actualiza los octets cada ~5 minutos.

6. **Nombres de dispositivos:** jerarquía `COALESCE(map_label, display, sysName, hostname)`. Incluir siempre `sysName` o los dispositivos sin `display` mostrarán IPs.
