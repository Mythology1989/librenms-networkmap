# 🗺️ LibreNMS Network Map Plugin — TASKS

> Plugin PHP nativo para LibreNMS que muestra un mapa geográfico interactivo
> con estado y tráfico en tiempo real de todos los dispositivos monitorizados.

## Estado: v1.0 COMPLETADA
Última actualización: 2026-04-06

---

## Fase 1: Scaffold y datos (sin mapa)

- [x] Crear estructura de directorios del plugin (2026-04-03)
- [x] `sql/install.sql` — tablas `plugin_networkmap_*` (2026-04-03)
- [x] `NetworkMap.php` — punto de entrada, activación en LibreNMS (2026-04-03)
- [x] `includes/db.php` — helpers de conexión y queries reutilizables (2026-04-03)
- [x] `api/devices.php` — devuelve JSON de dispositivos con lat/lng y estado (2026-04-03)
- [x] `api/links.php` — devuelve JSON de enlaces LLDP con tráfico (2026-04-03)
- [x] `api/settings.php` — GET/POST de configuración básica (2026-04-03)
- [x] Verificar que el plugin aparece en LibreNMS → Settings → Plugins sin errores PHP (2026-04-05)

## Fase 2: Mapa básico (Leaflet + nodos + enlaces)

- [x] `map.php` — carga Leaflet (CDN + fallback local) y `networkmap.js` (2026-04-03)
- [x] `js/networkmap.js` — inicialización del mapa, `fitBounds` automático (2026-04-03)
- [x] Renderizar nodos: círculos coloreados por estado (verde / naranja / rojo) (2026-04-03)
- [x] Labels con `display_name` siempre visibles (no solo en hover) (2026-04-03)
- [x] Renderizar enlaces LLDP: color por utilización, grosor logarítmico (2026-04-03)
- [x] Popup de nodo: hostname, uptime formateado, alertas activas, link a LibreNMS (2026-04-03)
- [x] Popup de enlace: puertos local/remoto, velocidad, in/out Mbps, % uso (2026-04-03)
- [x] `css/networkmap.css` — estilos básicos funcionales (2026-04-03)

## Fase 3: Tráfico real y caché

- [x] `plugin_networkmap_port_cache` — implementar snapshot de octets (2026-04-03)
- [x] Cálculo bps en `api/links.php` con detección de counter wrap (32-bit) (2026-04-03)
- [x] Grosor de línea proporcional a % de utilización (escala logarítmica 1px–8px) (2026-04-03)
- [x] AJAX polling con intervalo configurable (default 60s, sin recargar página) (2026-04-03)

## Fase 4: Features avanzadas

- [x] Clustering geográfico Haversine — dispositivos a <150m se agrupan (umbral zoom 18) (2026-04-03)
- [x] Vista TV (`tv.php`) — fullscreen, dark mode, sin interacción, autorefresh (2026-04-03)
- [x] Token TV para acceso sin login (generado server-side) (2026-04-03)
- [x] Panel de configuración: proveedor de mapa, intervalo, zoom clustering, token TV (2026-04-03)
- [x] Gestión de enlaces manuales: CRUD en panel de configuración (2026-04-03)
- [x] Soporte Google Maps (condicional: solo si API key configurada) (2026-04-04)
- [x] Filtro de locations en panel de configuración (checkboxes + JSON en settings) (2026-04-03)
- [x] Filtro de enlaces: tabla con checkbox mostrar/ocultar por enlace + prioridad (2026-04-04)
- [x] Nombres personalizados por dispositivo (`plugin_networkmap_device_labels`) (2026-04-04)
- [x] Tablas del panel de configuración ordenables por columna (JS puro) (2026-04-04)
- [x] Refresh mínimo 10s + nota de advertencia de rendimiento (2026-04-04)

## Fase 5: Pulido y publicación

- [x] `sql/uninstall.sql` — drop solo de tablas `plugin_networkmap_*` (2026-04-03)
- [x] `README.md` con instrucciones de instalación paso a paso (2026-04-03)
- [x] Verificar todos los criterios de éxito definidos en `SPEC.md` (2026-04-05)
- [x] Primer commit público en GitHub con tag v1.0 (2026-04-06)

## Compatibilidad LibreNMS 26.x

- [x] `NetworkMap.inc.php` — punto de entrada real para 26.x, routing con `request()->query()` (2026-04-03)
- [x] `NetworkMap.php` — clase pura de registro de hooks (menu), sin lógica de BD (2026-04-03)
- [x] `includes/db.php` — solo dbFacile, sin DB:: facade ni dbQuery() (2026-04-03)
- [x] `api/*.php` — auth con `auth()->check()` y `auth()->user()->hasRole('admin')` (2026-04-03)
- [x] `api/*.php` — todos terminan en `exit;` para bypass del ob_start() de PluginLegacyController (2026-04-03)
- [x] `js/networkmap.js` — fetch con `credentials: 'same-origin'` (2026-04-03)

## Correcciones post-lanzamiento (2026-04-05/06)

- [x] Enlaces Bezier curvos cuando hay múltiples enlaces entre el mismo par de nodos (2026-04-05)
- [x] Etiqueta ↓X ↑Y colocada en vértice real del arco Bezier (t=0.5), no en el punto de control (2026-04-05)
- [x] `tv.php` — refactorizada para cargar `networkmap.js` con `tvMode:true` (sin JS duplicado) (2026-04-05)
- [x] `tv.php` — CARTO dark tiles, sin popups/tooltips, clustering y Bezier idénticos al mapa principal (2026-04-05)
- [x] `tv.php` — corregida clave `zoom_threshold_cluster` (no `zoom_threshold`) (2026-04-05)
- [x] `tv.php` — añadido `l.location` y `d.sysName` al SELECT para paridad de labels (2026-04-06)
- [x] Verificado en producción: labels TV coinciden con mapa principal, clustering al mismo zoom ✓ (2026-04-06)

---

## v1.1 — Panel de estilos (pendiente)

- [ ] Panel de configuración visual: tamaño y color de nodos
- [ ] Panel de configuración visual: grosor y color de enlaces
- [ ] Panel de configuración visual: tamaño y color de labels
- [ ] Perfiles separados para modo mapa y modo TV
