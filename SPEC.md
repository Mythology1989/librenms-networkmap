# LibreNMS Network Map Plugin — SPEC v1.0

## Resumen del Proyecto

Plugin para LibreNMS que genera un **mapa geográfico interactivo en tiempo real** de la red,
mostrando todos los dispositivos monitorizados con sus enlaces, estado y tráfico.
Se integra como pestaña nativa en LibreNMS y opcionalmente expone una URL de visualización
para pantallas de TV/NOC.

**Nombre propuesto:** `NetworkMap` (ruta: `/plugin/p=NetworkMap`)  
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
├── NetworkMap.php          # Punto de entrada del plugin (requerido por LibreNMS)
├── map.php                 # Vista principal del mapa
├── tv.php                  # Vista TV/NOC (fullscreen, sin navbar)
├── api/
│   ├── devices.php         # Devuelve dispositivos con lat/lng y estado
│   ├── links.php           # Devuelve enlaces LLDP + manuales con tráfico
│   └── settings.php        # GET/POST configuración del plugin
├── js/
│   ├── networkmap.js       # Lógica principal del mapa
│   └── leaflet.min.js      # Leaflet local (fallback si CDN falla)
├── css/
│   └── networkmap.css      # Estilos del plugin
├── includes/
│   └── db.php              # Helpers para queries a LibreNMS DB
└── README.md               # Documentación e instalación
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
  COUNT(a.alert_id) as active_alerts
FROM devices d
JOIN locations l ON l.id = d.location_id
LEFT JOIN alerts a ON a.device_id = d.device_id AND a.state = 1
WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL
GROUP BY d.device_id
```

### Query de enlaces LLDP (automáticos)
```sql
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
```

### Tabla adicional para enlaces manuales
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
```

### Tabla de configuración
```sql
CREATE TABLE IF NOT EXISTS `plugin_networkmap_settings` (
  `key`   VARCHAR(100) PRIMARY KEY,
  `value` TEXT
);
-- Valores por defecto:
-- map_provider: 'osm'
-- google_api_key: ''
-- refresh_interval: 60
-- zoom_threshold_cluster: 13
-- default_zoom: 'auto'
```

### Tabla de caché para tasas de tráfico
```sql
CREATE TABLE IF NOT EXISTS `plugin_networkmap_port_cache` (
  `port_id`       INT PRIMARY KEY,
  `in_octets`     BIGINT DEFAULT 0,
  `out_octets`    BIGINT DEFAULT 0,
  `in_bps`        BIGINT DEFAULT 0,
  `out_bps`       BIGINT DEFAULT 0,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## API Endpoints (PHP)

### `GET /plugin/p=NetworkMap&api=devices`
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

### `GET /plugin/p=NetworkMap&api=links`
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

### `GET/POST /plugin/p=NetworkMap&api=settings`
Lectura y escritura de configuración. Solo accesible para admins de LibreNMS.

### `POST /plugin/p=NetworkMap&api=links&action=add_manual`
Añade enlace manual entre dos dispositivos.

```json
{
  "local_device_id": 12,
  "remote_device_id": 5,
  "local_port_id": 88,
  "remote_port_id": 102,
  "label": "Enlace 60GHz backup"
}
```

---

## Comportamiento del Mapa

### Zoom dinámico (clustering)
- **Zoom < 13** (vista general): Los dispositivos del mismo "nodo lógico" (lat/lng muy próximas, < 200m entre sí) se agrupan en un marcador de cluster. Se muestran los enlaces principales entre clusters.
- **Zoom ≥ 13** (vista detallada): Se muestran todos los dispositivos individuales con sus enlaces.
- El zoom inicial hace `fitBounds` automático para que entren todos los dispositivos en pantalla.

### Nodos (marcadores)
- **Círculo** coloreado según estado:
  - Verde: UP, sin alertas
  - Amarillo: UP, con alertas activas
  - Rojo: DOWN
- **Tamaño** del círculo: fijo (no proporcional al número de puertos — demasiado ruido)
- **Label** con `display_name` del dispositivo siempre visible (no solo en hover)

### Enlaces (líneas)
- **Color:**
  - Verde: interfaz UP
  - Rojo: interfaz DOWN
  - Gris: sin datos de tráfico
- **Grosor** proporcional al tráfico: escala logarítmica de 1px (0%) a 8px (100% uso)
- **Tooltip en hover:** muestra `local_port → remote_port`, velocidad, in/out en Mbps y % uso
- Los enlaces manuales se muestran con línea discontinua para diferenciarlos de los LLDP

### Popup al hacer click en nodo
```
[Hostname]
Estado: UP ✓  |  Uptime: 14d 3h
Alertas activas: 0
Interfaces: 12 activas / 2 caídas
[Ver en LibreNMS →]
```

### Popup al hacer click en enlace
```
[local_port] ──── [remote_port]
Velocidad: 1 Gbps
↓ In:  324 Mbps (32.4%)
↑ Out:  41 Mbps  (4.1%)
Estado: UP
[Ver puerto local →]  [Ver puerto remoto →]
```

---

## Vista TV/NOC (`tv.php`)

URL: `/plugin/p=NetworkMap&view=tv`

- Fullscreen sin navbar de LibreNMS
- Fondo oscuro (dark mode forzado)
- Autorefresh igual que la configuración global
- Muestra esquina inferior derecha: timestamp del último update
- Muestra esquina superior derecha: contador de dispositivos UP/DOWN
- **No hay popups ni interacción** — solo visualización
- URL pública configurable (con token simple) para no requerir login de LibreNMS

> Nota: No se implementa RTSP. El "streaming" para TV es simplemente esta URL
> abierta en el navegador del TV/Chromecast. Es más robusto y sin dependencias.

---

## Panel de Configuración

Accesible desde el propio plugin para usuarios admin. Campos:

| Campo | Tipo | Default |
|-------|------|---------|
| Proveedor de mapa | select: OSM / Google Maps | OSM |
| Google Maps API Key | text | — |
| Intervalo de refresco | select: 15s / 30s / 60s / 120s | 60s |
| Zoom de clustering | número (10-16) | 13 |
| Token TV (acceso sin login) | text generado | — |
| Regenerar token TV | botón | — |

---

## Gestión de Enlaces Manuales

Interfaz simple en el panel de configuración:
- Tabla de enlaces manuales existentes con botón "Eliminar"
- Formulario: selector de dispositivo A → selector de puerto A → selector de dispositivo B → selector de puerto B → label opcional
- Los puertos se cargan via AJAX al seleccionar el dispositivo

---

## Instalación

```bash
# 1. Clonar en el directorio de plugins de LibreNMS
cd /opt/librenms/html/plugins
git clone https://github.com/Mythology1989/librenms-networkmap NetworkMap

# 2. Crear tablas adicionales
mysql -u librenms -p librenms < NetworkMap/sql/install.sql

# 3. Dar permisos
chown -R librenms:librenms NetworkMap/

# 4. Activar en LibreNMS
# LibreNMS → Settings → Plugins → NetworkMap → Enable
```

---

## Criterios de Éxito

- [ ] Plugin aparece en el menú de LibreNMS sin errores PHP
- [ ] Mapa carga con todos los dispositivos que tienen lat/lng en LibreNMS
- [ ] Enlaces LLDP se dibujan automáticamente sin configuración manual
- [ ] Color y grosor de enlace refleja tráfico real (verificar contra datos de LibreNMS)
- [ ] Popup de nodo muestra uptime y alertas reales
- [ ] Popup de enlace muestra in/out en Mbps y % uso
- [ ] Refresco automático funciona sin recargar la página
- [ ] Vista TV accesible sin login con token
- [ ] Panel de configuración guarda/lee correctamente (OSM ↔ Google Maps)
- [ ] Añadir y eliminar enlace manual funciona y aparece en el mapa
- [ ] Clustering visible al hacer zoom out

---

## Fuera de Alcance (v1.0)

- Historial de tráfico / gráficas temporales (eso es para Grafana)
- Exportar mapa como imagen
- Edición de posición GPS desde el mapa (se hace desde LibreNMS nativo)
- Notificaciones push en el mapa
- Submapas por zona (se consigue con zoom + clustering)

---

## Notas de Implementación para Claude Code

1. **Auth:** Usar el sistema de auth de LibreNMS. Los endpoints `api/*.php` deben verificar sesión activa con `require_once '../../../includes/defaults.inc.php'` y el patrón estándar de plugins LibreNMS.

2. **No tocar tablas core de LibreNMS.** Solo lectura en `devices`, `ports`, `links`, `alerts`, `locations`. Escritura solo en las tablas propias del plugin (`plugin_networkmap_*`).

3. **Leaflet desde CDN** con fallback local. Google Maps solo se carga si está configurado y hay API key.

4. **El cálculo de bps** se hace desde `ifInOctets`/`ifOutOctets` (contadores acumulados). El backend debe guardar el valor anterior y el timestamp para calcular la tasa actual: `bps = (octets_now - octets_prev) * 8 / seconds_elapsed`. Guardar en `plugin_networkmap_port_cache`.

5. **Versión LibreNMS en producción:** instalación con MariaDB 10.11 en Debian 12.

6. **Validación de queries:** disponible una conexión de solo lectura a la BD de producción:
   - Host: 10.2.112.2
   - Usuario: netmap_ro
   - Password: NetmapRO2026!
   - Base de datos: librenms
   - Comando: `mysql -u netmap_ro -p'NetmapRO2026!' -h 10.2.112.2 librenms -e "QUERY;"`
