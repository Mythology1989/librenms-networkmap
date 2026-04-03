# 🗺️ LibreNMS Network Map Plugin — TASKS

> Plugin PHP nativo para LibreNMS que muestra un mapa geográfico interactivo
> con estado y tráfico en tiempo real de todos los dispositivos monitorizados.

## Estado: FASE 2 COMPLETADA + COMPATIBILIDAD LibreNMS 26.x
Última actualización: 2026-04-03

---

## Fase 1: Scaffold y datos (sin mapa)

- [x] Crear estructura de directorios del plugin (2026-04-03)
- [x] `sql/install.sql` — tablas `plugin_networkmap_*` (2026-04-03)
- [x] `NetworkMap.php` — punto de entrada, activación en LibreNMS (2026-04-03)
- [x] `includes/db.php` — helpers de conexión y queries reutilizables (2026-04-03)
- [x] `api/devices.php` — devuelve JSON de dispositivos con lat/lng y estado (2026-04-03)
- [x] `api/links.php` — devuelve JSON de enlaces LLDP (sin tráfico aún) (2026-04-03)
- [x] `api/settings.php` — GET/POST de configuración básica (2026-04-03)
- [ ] Verificar que el plugin aparece en LibreNMS → Settings → Plugins sin errores PHP

## Fase 2: Mapa básico (Leaflet + nodos + enlaces)

- [x] `map.php` — carga Leaflet (CDN + fallback local) y `networkmap.js` (2026-04-03)
- [x] `js/networkmap.js` — inicialización del mapa, `fitBounds` automático (2026-04-03)
- [x] Renderizar nodos: círculos coloreados por estado (verde / amarillo / rojo) (2026-04-03)
- [x] Labels con `display_name` siempre visibles (no solo en hover) (2026-04-03)
- [x] Renderizar enlaces LLDP: color por estado de interfaz, grosor básico fijo (2026-04-03)
- [x] Popup de nodo: hostname, uptime formateado, alertas activas, link a LibreNMS (2026-04-03)
- [x] Popup de enlace: puertos local/remoto, velocidad, estado (2026-04-03)
- [x] `css/networkmap.css` — estilos básicos funcionales (2026-04-03)

## Compatibilidad LibreNMS 26.x (2026-04-03)

- [x] Crear `NetworkMap.inc.php` — punto de entrada real para 26.x (2026-04-03)
- [x] `NetworkMap.php` — clase de registro de hooks (menu), URL `/plugin/v1/NetworkMap` (2026-04-03)
- [x] `includes/db.php` — solo dbFacile, sin dbQuery() (update+insert para upsert) (2026-04-03)
- [x] `api/*.php` — auth con `auth()->check()` y `auth()->user()->hasRole('admin')` (2026-04-03)
- [x] `api/devices.php` — añadir `exit;` al final para bypass del ob_start() de PluginLegacyController (2026-04-03)
- [x] `NetworkMap.inc.php` — routing con `request()->query()` en lugar de `$_GET` (2026-04-03)
- [x] `js/networkmap.js` — `credentials: 'same-origin'` en fetch calls (2026-04-03)
- [x] Verificado en producción: `/api=devices` → JSON ✓, `/api=links` → JSON ✓, mapa carga ✓ (2026-04-03)
- [x] Actualizar CLAUDE.md con gotchas definitivos de LibreNMS 26.x (2026-04-03)

## Fase 3: Tráfico real y caché

- [ ] `plugin_networkmap_port_cache` — implementar snapshot de octets
- [ ] Cálculo bps en `api/links.php` con detección de counter wrap (32-bit)
- [ ] Grosor de línea proporcional a % de utilización (escala logarítmica 1px–8px)
- [ ] AJAX polling con intervalo configurable (default 60s, sin recargar página)

## Fase 4: Features avanzadas

- [ ] Clustering de dispositivos en zoom < 13 (configurable)
- [ ] Vista TV (`tv.php`) — fullscreen, dark mode, sin interacción, autorefresh
- [ ] Token TV para acceso sin login (generado server-side)
- [ ] Panel de configuración: proveedor de mapa, intervalo, zoom de clustering, token TV
- [ ] Gestión de enlaces manuales: CRUD en panel de configuración
- [ ] Soporte Google Maps (condicional: solo si API key configurada)

## Fase 5: Pulido y publicación

- [ ] `sql/uninstall.sql` — drop solo de tablas `plugin_networkmap_*`
- [ ] `README.md` con instrucciones de instalación paso a paso
- [ ] Verificar todos los criterios de éxito definidos en `SPEC.md`
- [ ] Primer commit público en GitHub bajo cuenta/organización FibraT

---

## Completadas

### Fase 1 (2026-04-03)
- Estructura de directorios `api/`, `js/`, `css/`, `includes/`, `sql/`
- `sql/install.sql` — tablas plugin_networkmap_links, _settings, _port_cache
- `NetworkMap.php` — router/entry point del plugin
- `includes/db.php` — helpers netmap_get_setting, netmap_set_setting, netmap_get_port_cache, netmap_upsert_port_cache
- `api/devices.php` — endpoint GET con JOIN correcto a locations, query validada en producción
- `api/links.php` — endpoint GET/POST/DELETE, enlaces LLDP + manuales
- `api/settings.php` — GET público, POST admin-only, auto-generación tv_token
- Validación PHP syntax todos los archivos: sin errores
- Validación queries contra BD 10.2.112.2: 48 dispositivos, 109 enlaces LLDP
- Gotcha identificado: alerts.id (no alert_id) — corregido en código y documentado en CLAUDE.md

### Fase 2 (2026-04-03)
- `js/leaflet.min.js` + `css/leaflet.min.css` — Leaflet 1.9.4 descargado como fallback local
- `css/networkmap.css` — estilos para contenedor, labels, tooltips, popups, loading overlay, toolbar
- `map.php` — vista principal: inyecta netmapConfig desde PHP, CDN+fallback Leaflet, carga networkmap.js
- `js/networkmap.js` — lógica completa: OSM tiles, CircleMarker por estado (verde/naranja/rojo/gris),
  DivIcon labels siempre visibles, popups de nodo y enlace, polylines LLDP/manual con color por estado,
  tooltips en hover, Promise.all para fetch paralelo, fitBounds en primera carga, auto-refresh y botón manual
