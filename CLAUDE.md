# 🗺️ LibreNMS Network Map Plugin

> Plugin PHP nativo para LibreNMS que muestra un mapa geográfico interactivo
> con estado y tráfico en tiempo real de todos los dispositivos monitorizados.

## Qué es
Plugin PHP que se integra en LibreNMS como pestaña nativa y dibuja un mapa
geográfico interactivo con nodos, enlaces LLDP y tráfico en tiempo real.

## Stack
- Backend: PHP (nativo LibreNMS, sin framework propio)
- DB: MariaDB 10.11 — la misma instancia de LibreNMS (solo lectura en tablas core)
- Frontend: Leaflet.js (CDN + fallback local en `js/leaflet.min.js`)
- Infra: LibreNMS en 10.2.112.2 → `/opt/librenms/html/plugins/NetworkMap/`
- Desarrollo: LXC local, deploy manual por rsync/git pull en producción

## Comandos
```bash
# No hay build. Desarrollo directo en el directorio del plugin.

# Validar sintaxis PHP antes de guardar:
php -l NetworkMap.inc.php
php -l api/devices.php

# Validar queries contra BD de producción (solo lectura):
mysql -u netmap_ro -p'NetmapRO2026!' -h 10.2.112.2 librenms -e "QUERY;"

# Aplicar migraciones SQL (en producción):
mysql -u librenms -p librenms < sql/install.sql
```

## Arquitectura

### Estructura de archivos (NO crear duplicados)
```
NetworkMap/
├── NetworkMap.php       # Clase de registro de hooks (menu, etc.) — instanciada por Laravel al arrancar
├── NetworkMap.inc.php   # Vista/router principal — servida por PluginLegacyController
├── map.php              # Vista principal — lógica de mapa solo en networkmap.js
├── tv.php               # Vista TV/NOC — fullscreen, sin navbar, sin popups
├── api/
│   ├── devices.php      # GET: dispositivos con lat/lng y estado
│   ├── links.php        # GET: enlaces LLDP + manuales con tráfico
│   └── settings.php     # GET/POST: configuración del plugin
├── js/
│   └── networkmap.js    # TODA la lógica del mapa va aquí — no JS inline en PHP
├── css/
│   └── networkmap.css
├── includes/
│   └── db.php           # Helpers de queries — reutilizar, no duplicar
└── sql/
    ├── install.sql      # Creación de tablas plugin_networkmap_*
    └── uninstall.sql    # Drop de tablas propias (NUNCA tablas core)
```

### Tablas propias (las únicas en las que se escribe)
- `plugin_networkmap_links` — enlaces manuales entre dispositivos
- `plugin_networkmap_settings` — configuración del plugin (clave/valor)
- `plugin_networkmap_port_cache` — caché de tasas de tráfico (octets → bps)

### Regla de extensión
ANTES de crear un archivo nuevo:
1. `grep -r "funcion_buscada" .` para verificar que no existe
2. Si existe algo similar → EXTENDER, no crear paralelo
3. Toda lógica JS del mapa → `networkmap.js`. Nada de scripts inline en PHP.

### Patrón de cálculo de tráfico
```
bps = (octets_now - octets_prev) * 8 / seconds_elapsed
```
Guardar snapshot en `plugin_networkmap_port_cache`. Sin esta caché el tráfico
siempre saldrá a 0.

## Reglas críticas
- Auth en `api/*.php`: `auth()->check()` para sesión, `auth()->user()->hasRole('admin')`
  para admin. Verificado en producción 2026-04-03.
- NUNCA hacer `DELETE` ni `UPDATE` en tablas core (`devices`, `ports`, `links`, `alerts`, `locations`)
- Google Maps solo se carga si `map_provider = 'google'` Y hay API key configurada
- El token TV se genera server-side — nunca exponer su lógica de validación en JS
- Leaflet siempre con fallback local: intentar CDN, si falla cargar `js/leaflet.min.js`
- **Todo endpoint API DEBE terminar con `exit;`** después del último `echo json_encode`.
  Sin `exit`, la salida JSON queda capturada por el `ob_start()` del controller y
  aparece envuelta en el HTML de LibreNMS. Con `exit`, se envía directamente al cliente.

## Gotchas
- **Dos archivos PHP en la raíz del plugin, roles distintos:**
  - `NetworkMap.php` — clase pura instanciada por Laravel al arrancar para registrar
    hooks (menú, etc.). Solo Laravel disponible aquí. Sin DB, sin includes propios.
    URL del menú: `url('/plugin/v1/NetworkMap')`.
  - `NetworkMap.inc.php` — servida por `PluginLegacyController` como vista/router.
    dbFacile y `auth()` están disponibles. Hace `require_once includes/db.php`
    y enruta a `api/` y vistas.
- **DB en api/*.php y includes/db.php: usar SIEMPRE dbFacile.**
  `dbFetchRows()`, `dbFetchRow()`, `dbInsert()`, `dbUpdate()`, `dbDelete()`.
  LibreNMS carga dbFacile antes de incluir el plugin.
  **NO usar** `DB::`, `dbQuery()` ni ninguna facade de Laravel/Illuminate.
- **`$_GET` y `$_SESSION` están disponibles en el contexto legacy** (verificado en
  producción 2026-04-03). Sin embargo, usar `request()->query()` para lectura de
  parámetros URL (más robusto) y `auth()->check()` / `auth()->user()->hasRole('admin')`
  para autenticación (forma correcta en 26.x).
- **`PluginLegacyController` usa `ob_start()`** para capturar la salida del plugin y
  envolverla en la plantilla Blade de LibreNMS. Para respuestas API (JSON), llamar
  siempre `exit` al final para que el JSON se envíe directamente sin la envoltura HTML.
- **LibreNMS hace `chdir('html/')` antes de incluir el plugin.** Usar siempre
  rutas absolutas basadas en `__DIR__` para includes internos; nunca rutas relativas.
- **URLs de plugins:** `/plugin/v1/NombrePlugin` para vistas (registrada como `plugin.legacy`).
  La ruta acepta query string: `/plugin/v1/NetworkMap?api=devices`. NO usar `/plugin/p=`.
- **JS fetch**: incluir `credentials: 'same-origin'` para enviar las cookies de sesión
  de Laravel. Sin esto, `auth()->check()` devuelve false en los API calls del navegador.
- **Las coordenadas lat/lng NO están en la tabla `devices`.** Están en la tabla
  `locations` (PK: `id`), enlazada por `devices.location_id`. El JOIN es
  obligatorio en TODAS las queries que necesiten coordenadas:
  `JOIN locations l ON l.id = d.location_id`
- La tabla `locations` usa `id` como PK, no `location_id`. No confundir con
  el campo `location_id` de la tabla `devices` que es la FK.
- En producción hay 48 dispositivos con coordenadas válidas (verificado 2026-04-03)
- `ifInOctets`/`ifOutOctets` son contadores SNMP acumulados. En equipos viejos son
  32-bit y pueden hacer wrap. Detectar: si `octets_now < octets_prev`, ignorar ese
  ciclo y resetear caché para ese puerto
- Los dispositivos sin `location_id` o con `location_id` apuntando a una location
  sin lat/lng no aparecen en el mapa. No es un bug, es correcto.
- LLDP en la tabla `links` puede tener `remote_device_id = NULL` si el vecino no
  está en LibreNMS. Siempre filtrar con `WHERE l.remote_device_id IS NOT NULL`
- La tabla `alerts` usa `id` como PK, **no** `alert_id`. SPEC.md tiene un error:
  `COUNT(a.alert_id)` falla en producción. Usar `COUNT(a.id)` en su lugar.
  Verificado 2026-04-03. El campo `state = 1` sigue siendo el filtro correcto
  para alertas activas (state=0 = resuelta).

## Convenciones
- Commits: `tipo(scope): mensaje` — feat, fix, chore, docs, refactor
- PHP: PSR-12 básico. Indentación 4 espacios
- JS: ES6+, sin transpilación. Comentar las funciones de cálculo de tráfico

## Auto-actualización
Al completar una tarea o hacer cambios significativos:
1. Actualizar `TASKS.md` marcando tareas completadas con fecha
2. Actualizar `## Arquitectura` si se añaden archivos al plugin
3. Si aparecen nuevos gotchas con LibreNMS, añadirlos a `## Gotchas`
NO actualizar `SPEC.md` — ese solo lo modifica Alejandro desde claude.ai.
