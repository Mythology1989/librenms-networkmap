/**
 * NetworkMap — client-side map logic for LibreNMS NetworkMap plugin.
 *
 * Depends on:
 *   - Leaflet 1.9.4 (loaded before this script)
 *   - window.netmapConfig (set by map.php before this script)
 *
 * Features:
 *   - Geographic proximity clustering via Haversine (<50m → group node)
 *   - Link colors by type/utilization: green <50%, orange 50-80%, red ≥80%/DOWN, blue dashed manual
 *   - Always-visible labels; groups show "Name (N)" format
 *   - Zoom-driven re-render without re-fetching data
 */
(function () {
    'use strict';

    // ── Guard: config and Leaflet must be present ────────────────────────
    if (!window.netmapConfig) {
        var loadingGuard = document.getElementById('netmap-loading');
        if (loadingGuard) {
            loadingGuard.textContent = 'Error: configuración del mapa no disponible. Recarga la página.';
            loadingGuard.classList.add('visible');
        }
        return;
    }
    if (typeof L === 'undefined') {
        var loadingGuardL = document.getElementById('netmap-loading');
        if (loadingGuardL) {
            loadingGuardL.textContent = 'Error: Leaflet no pudo cargarse. Comprueba la conexión y recarga.';
            loadingGuardL.classList.add('visible');
        }
        return;
    }

    const config = window.netmapConfig;

    // ── DOM references ───────────────────────────────────────────────────
    const loadingEl  = document.getElementById('netmap-loading');
    const refreshBtn = document.getElementById('netmap-refresh');

    // ── Leaflet map ──────────────────────────────────────────────────────
    let map;
    try {
        map = L.map('netmap', {
            minZoom: 2,
            maxZoom: 18,
            zoomControl: true
        }).setView([28.1, -15.4], 8); // fitBounds will override on first load
    } catch (e) {
        if (loadingEl) {
            loadingEl.textContent = 'Error inicializando el mapa: ' + e.message;
            loadingEl.classList.add('visible');
        }
        return;
    }

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 18
    }).addTo(map);

    // Layer groups — order matters: links bottom, link labels above, devices middle, labels top
    let linkLayer      = L.layerGroup().addTo(map);
    let linkLabelLayer = L.layerGroup().addTo(map);
    let deviceLayer    = L.layerGroup().addTo(map);
    let labelLayer     = L.layerGroup().addTo(map);

    // ── Cached data (populated on each fetch, re-used on zoom changes) ───
    let cachedDevices = [];
    let cachedLinks   = [];

    // Geographic proximity grouping threshold in metres
    const GEO_GROUP_THRESHOLD_M = 150;

    // ── Formatting helpers ───────────────────────────────────────────────

    /**
     * Format uptime seconds → "Xd Yh Zm".
     * @param {number} seconds
     * @returns {string}
     */
    function formatUptime(seconds) {
        const s       = Math.max(0, Math.floor(seconds));
        const days    = Math.floor(s / 86400);
        const hours   = Math.floor((s % 86400) / 3600);
        const minutes = Math.floor((s % 3600) / 60);
        return `${days}d ${hours}h ${minutes}m`;
    }

    /**
     * Format bits-per-second → human-readable string (Gbps / Mbps / Kbps / bps).
     * @param {number} bps
     * @returns {string}
     */
    function formatSpeed(bps) {
        if (bps >= 1e9) { return (bps / 1e9).toFixed(1) + ' Gbps'; }
        if (bps >= 1e6) { return (bps / 1e6).toFixed(1) + ' Mbps'; }
        if (bps >= 1e3) { return (bps / 1e3).toFixed(1) + ' Kbps'; }
        return bps + ' bps';
    }

    // Compact form for always-visible link labels: "12M", "450K", "1.2G"
    function formatSpeedCompact(bps) {
        if (bps >= 1e9) { return (bps / 1e9).toFixed(1) + 'G'; }
        if (bps >= 1e6) { return (bps / 1e6).toFixed(0) + 'M'; }
        if (bps >= 1e3) { return (bps / 1e3).toFixed(0) + 'K'; }
        return bps + 'b';
    }

    /**
     * Minimal HTML escaping to prevent XSS.
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        if (str == null) { return ''; }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Color helpers ────────────────────────────────────────────────────

    /**
     * Device node fill color based on status and active alerts.
     *
     * @param {object} device
     * @returns {string} hex color
     */
    function deviceColor(device) {
        if (device.status === 1 && device.active_alerts === 0) { return '#2ecc71'; }
        if (device.status === 1 && device.active_alerts > 0)   { return '#f39c12'; }
        if (device.status === 0)                                { return '#e74c3c'; }
        return '#95a5a6';
    }

    /**
     * Link line color by type, status and utilization:
     *   - manual               → blue   (#3498db)
     *   - status 'down'        → red    (#e74c3c)
     *   - utilization ≥ 80%    → red    (#e74c3c)
     *   - utilization ≥ 50%    → orange (#f39c12)
     *   - status 'up' < 50%    → green  (#2ecc71)
     *   - unknown              → grey   (#95a5a6)
     *
     * @param {object} link  — must have { type, status, utilization_pct }
     * @returns {string} hex color
     */
    function linkColor(link) {
        if (link.type === 'manual')         { return '#3498db'; }
        if (link.status === 'down')         { return '#e74c3c'; }
        if ((link.utilization_pct || 0) >= 80) { return '#e74c3c'; }
        if ((link.utilization_pct || 0) >= 50) { return '#f39c12'; }
        if (link.status === 'up')           { return '#2ecc71'; }
        return '#95a5a6';
    }

    /**
     * Link weight (px) using logarithmic scale so low-traffic links remain
     * visible and high-traffic links don't dominate the map.
     *   0%   → 1 px
     *   100% → 8 px
     * Formula: 1 + 7 * log(1 + pct) / log(101)
     *
     * @param {number} utilization_pct
     * @returns {number}
     */
    function linkWeight(utilization_pct) {
        if (!utilization_pct || utilization_pct <= 0) { return 1; }
        const clamped = Math.min(utilization_pct, 100);
        return Math.max(1, Math.min(8, 1 + 7 * Math.log(1 + clamped) / Math.log(101)));
    }

    // ── Haversine distance ───────────────────────────────────────────────

    /**
     * Returns the great-circle distance in metres between two GPS coordinates.
     *
     * @param {number} lat1
     * @param {number} lng1
     * @param {number} lat2
     * @param {number} lng2
     * @returns {number} distance in metres
     */
    function haversineM(lat1, lng1, lat2, lng2) {
        const R  = 6371000; // Earth radius in metres
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lng2 - lng1) * Math.PI / 180;
        const a  = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                   Math.cos(φ1) * Math.cos(φ2) *
                   Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // ── Geographic grouping ──────────────────────────────────────────────

    /**
     * Greedily group devices that are within GEO_GROUP_THRESHOLD_M of each
     * other. The first device in each group sets the group's centre.
     *
     * Group name comes from the first device's `location` field (set by
     * api/devices.php). Falls back to display_name if location is absent.
     *
     * @param {Array} devices
     * @returns {{ groups: Array, deviceToGroup: Object }}
     */
    function computeGeoGroups(devices) {
        const groups       = [];
        const deviceToGroup = {};

        devices.forEach(function (device) {
            let assignedGroup = null;
            for (let i = 0; i < groups.length; i++) {
                const g    = groups[i];
                const dist = haversineM(device.lat, device.lng, g.lat, g.lng);
                if (dist <= GEO_GROUP_THRESHOLD_M) {
                    assignedGroup = g;
                    break;
                }
            }

            if (assignedGroup) {
                assignedGroup.devices.push(device);
                deviceToGroup[device.id] = assignedGroup.id;
            } else {
                const gid      = 'g' + groups.length;
                const newGroup = {
                    id:      gid,
                    name:    device.location || device.display_name,
                    lat:     device.lat,
                    lng:     device.lng,
                    devices: [device]
                };
                groups.push(newGroup);
                deviceToGroup[device.id] = gid;
            }
        });

        return { groups, deviceToGroup };
    }

    /**
     * Aggregate links for grouped rendering.
     *
     * - Links within the same group (intra-group) are discarded.
     * - Links between different groups are merged by canonical pair key.
     *   The aggregated entry tracks worst-case utilization, has_down flag,
     *   and whether any member is a manual link.
     *
     * @param {Array}  links
     * @param {Object} deviceToGroup  — { deviceId: groupId }
     * @returns {Array} aggregated link objects
     */
    function aggregateLinks(links, deviceToGroup) {
        const agg = {};

        links.forEach(function (link) {
            const fg = deviceToGroup[link.local_device_id];
            const tg = deviceToGroup[link.remote_device_id];

            if (!fg || !tg || fg === tg) { return; } // skip intra-group

            // Canonical key: always smaller group string first
            const key = fg < tg ? fg + '_' + tg : tg + '_' + fg;

            const pct = link.utilization_pct || 0;

            if (!agg[key]) {
                agg[key] = {
                    from_group:      fg,
                    to_group:        tg,
                    max_utilization: pct,
                    has_down:        link.status === 'down',
                    has_manual:      link.type   === 'manual',
                    count:           1,
                    in_bps:          link.in_bps  || 0,
                    out_bps:         link.out_bps || 0,
                    speed_bps:       link.speed_bps || 0,
                };
            } else {
                const a = agg[key];
                a.max_utilization = Math.max(a.max_utilization, pct);
                if (link.status === 'down')   { a.has_down   = true; }
                if (link.type   === 'manual') { a.has_manual = true; }
                a.count++;
                a.in_bps    = Math.max(a.in_bps,    link.in_bps  || 0);
                a.out_bps   = Math.max(a.out_bps,   link.out_bps || 0);
                a.speed_bps = Math.max(a.speed_bps, link.speed_bps || 0);
            }
        });

        return Object.values(agg);
    }

    // ── Loading overlay ──────────────────────────────────────────────────

    function showLoading() {
        if (loadingEl) { loadingEl.classList.add('visible'); }
    }

    function hideLoading() {
        if (loadingEl) { loadingEl.classList.remove('visible'); }
    }

    // ── Rendering ────────────────────────────────────────────────────────

    let isFirstLoad = true;

    /**
     * Clear all layers and re-render based on current zoom level.
     * Uses cachedDevices / cachedLinks — does NOT fetch from the API.
     *
     * zoom < zoomThreshold  → grouped (geographic cluster) view
     * zoom ≥ zoomThreshold  → individual device view
     */
    function renderAll() {
        linkLayer.clearLayers();
        linkLabelLayer.clearLayers();
        deviceLayer.clearLayers();
        labelLayer.clearLayers();

        if (cachedDevices.length === 0) { return; }

        const zoom    = map.getZoom();
        const grouped = zoom < config.zoomThreshold;

        if (grouped) {
            renderGrouped(cachedDevices, cachedLinks);
        } else {
            renderIndividual(cachedDevices, cachedLinks);
        }
    }

    /**
     * Render devices as geographic group markers with aggregated links.
     * Groups with >1 device show "Name (N)" labels.
     *
     * @param {Array} devices
     * @param {Array} links
     */
    function renderGrouped(devices, links) {
        const { groups, deviceToGroup } = computeGeoGroups(devices);

        // Coordinate lookup by group ID
        const coordMap = {};
        groups.forEach(function (g) { coordMap[g.id] = L.latLng(g.lat, g.lng); });

        // Render group markers
        groups.forEach(function (g) {
            let hasDown = false, hasAlerts = false;
            g.devices.forEach(function (d) {
                if (d.status === 0)          { hasDown   = true; }
                if (d.active_alerts > 0)     { hasAlerts = true; }
            });

            let color = '#2ecc71';
            if (hasDown)        { color = '#e74c3c'; }
            else if (hasAlerts) { color = '#f39c12'; }

            const latLng = coordMap[g.id];
            const radius = g.devices.length > 1 ? 10 : 8;

            const circle = L.circleMarker(latLng, {
                radius,
                fillColor:   color,
                fillOpacity: 0.85,
                weight:      2,
                color:       '#fff'
            });

            // Label: "Name (N)" for multi-device groups
            const labelText = g.devices.length > 1
                ? `${g.name} (${g.devices.length})`
                : g.name;

            // Popup: list all devices in the group
            const deviceListHtml = g.devices.map(function (d) {
                const st = d.status === 1
                    ? '<span class="status-up">UP</span>'
                    : '<span class="status-down">DOWN</span>';
                return `<li>${escapeHtml(d.display_name)}: ${st}</li>`;
            }).join('');

            const popupHtml = `<div class="netmap-popup">
  <h4>${escapeHtml(g.name)}</h4>
  <ul style="padding-left:16px;margin:4px 0;">${deviceListHtml}</ul>
</div>`;

            circle.bindPopup(popupHtml, { className: 'netmap-popup' });
            circle.addTo(deviceLayer);

            const labelIcon = L.divIcon({
                className: '',
                html:      `<div class="netmap-label">${escapeHtml(labelText)}</div>`,
                iconAnchor: [0, 0]
            });
            L.marker(latLng, { icon: labelIcon, interactive: false }).addTo(labelLayer);
        });

        // Render aggregated inter-group links
        const aggLinks = aggregateLinks(links, deviceToGroup);
        aggLinks.forEach(function (a) {
            const from = coordMap[a.from_group];
            const to   = coordMap[a.to_group];
            if (!from || !to) { return; }

            // Synthesize a link-like object for linkColor
            // Manual-only groups get blue; if any member is down → red wins
            const synthLink = {
                type:            (a.has_manual && !a.has_down) ? 'manual' : 'lldp',
                status:          a.has_down ? 'down' : 'up',
                utilization_pct: a.max_utilization,
            };

            const opts = {
                color:   linkColor(synthLink),
                weight:  linkWeight(a.max_utilization),
                opacity: 0.7
            };
            if (a.has_manual) { opts.dashArray = '6, 4'; }

            const line = L.polyline([from, to], opts);

            const typeLabel = a.has_manual ? 'Manual' : 'LLDP';
            const tooltip   = `${a.count} enlace${a.count > 1 ? 's' : ''} | ${typeLabel} | ${synthLink.status.toUpperCase()} | ${a.max_utilization.toFixed(1)}%`;
            line.bindTooltip(tooltip, { className: 'netmap-link-tooltip', sticky: true });
            line.addTo(linkLayer);
        });
    }

    /**
     * Render individual device markers with jitter for same-location devices,
     * and link polylines colored by utilization/type.
     *
     * @param {Array} devices
     * @param {Array} links
     */
    function renderIndividual(devices, links) {
        const deviceCoords = {};

        // Circular jitter for devices sharing the exact same lat/lng (~25m radius)
        const locationGroups = {};
        devices.forEach(function (d) {
            const key = String(d.lat) + ',' + String(d.lng);
            if (!locationGroups[key]) { locationGroups[key] = []; }
            locationGroups[key].push(d.id);
        });

        const jitter = {};
        Object.keys(locationGroups).forEach(function (key) {
            const ids = locationGroups[key];
            if (ids.length < 2) { return; }
            const parts   = key.split(',');
            const baseLat = parseFloat(parts[0]);
            const baseLng = parseFloat(parts[1]);
            const radius  = 0.00025; // ~25m
            const cosLat  = Math.cos(baseLat * Math.PI / 180);
            ids.forEach(function (id, idx) {
                const angle = (2 * Math.PI * idx) / ids.length;
                jitter[id]  = [
                    baseLat + radius * Math.sin(angle),
                    baseLng + radius * Math.cos(angle) / cosLat
                ];
            });
        });

        // Render device circles and labels
        devices.forEach(function (d) {
            const coords = jitter[d.id] || [d.lat, d.lng];
            const latLng = L.latLng(coords[0], coords[1]);
            deviceCoords[d.id] = latLng;

            const circle = L.circleMarker(latLng, {
                radius:      8,
                fillColor:   deviceColor(d),
                fillOpacity: 0.85,
                weight:      2,
                color:       '#fff'
            });

            const statusHtml = d.status === 1
                ? '<span class="status-up">UP &#10003;</span>'
                : '<span class="status-down">DOWN &#10007;</span>';

            const popupHtml = `<div class="netmap-popup">
  <h4>${escapeHtml(d.display_name)}</h4>
  <div>Estado: ${statusHtml}</div>
  <div>Uptime: ${formatUptime(d.uptime)}</div>
  <div>Alertas activas: ${d.active_alerts}</div>
  <a href="${escapeHtml(d.url)}" target="_blank">Ver en LibreNMS &rarr;</a>
</div>`;

            circle.bindPopup(popupHtml, { className: 'netmap-popup' });
            circle.addTo(deviceLayer);

            const labelIcon = L.divIcon({
                className:  '',
                html:       `<div class="netmap-label">${escapeHtml(d.display_name)}</div>`,
                iconAnchor: [0, 0]
            });
            L.marker(latLng, { icon: labelIcon, interactive: false }).addTo(labelLayer);
        });

        // Render link polylines
        links.forEach(function (link) {
            const from = deviceCoords[link.local_device_id];
            const to   = deviceCoords[link.remote_device_id];
            if (!from || !to) { return; }

            const opts = {
                color:   linkColor(link),
                weight:  linkWeight(link.utilization_pct || 0),
                opacity: 0.7
            };
            if (link.type === 'manual') { opts.dashArray = '6, 4'; }

            const line = L.polyline([from, to], opts);

            const statusLabel = (link.status || 'unknown').toUpperCase();
            const typeLabel   = link.type === 'manual' ? 'Manual' : 'LLDP';
            const utilLabel    = (link.utilization_pct > 0)
                ? ` | ${link.utilization_pct.toFixed(1)}%`
                : '';
            const trafficLabel = (link.in_bps > 0 || link.out_bps > 0)
                ? `<br>&#8595;${formatSpeed(link.in_bps)} / &#8593;${formatSpeed(link.out_bps)}`
                : '';
            const tooltipText = `${escapeHtml(link.local_port)} &rarr; ${escapeHtml(link.remote_port)}<br>${typeLabel} | ${formatSpeed(link.speed_bps)} | ${statusLabel}${utilLabel}${trafficLabel}`;
            line.bindTooltip(tooltipText, { className: 'netmap-link-tooltip', sticky: true });

            const statusClass  = link.status === 'up' ? 'status-up' : 'status-down';
            const trafficHtml  = (link.in_bps > 0 || link.out_bps > 0)
                ? `<div>Tráfico: &#8595;${formatSpeed(link.in_bps)} / &#8593;${formatSpeed(link.out_bps)}</div>
  <div>Utilización: ${link.utilization_pct.toFixed(1)}%</div>`
                : '';
            const popupHtml = `<div class="netmap-popup">
  <h4>${escapeHtml(link.local_port)} &#9472;&#9472;&#9472;&#9472; ${escapeHtml(link.remote_port)}</h4>
  <div>Tipo: ${escapeHtml(typeLabel)}</div>
  <div>Velocidad: ${formatSpeed(link.speed_bps)}</div>
  <div>Estado: <span class="${statusClass}">${statusLabel}</span></div>
  ${trafficHtml}</div>`;

            line.bindPopup(popupHtml, { className: 'netmap-popup' });
            line.addTo(linkLayer);

            // Always-visible traffic label at the link midpoint (individual view only).
            // Only render if in_bps > 0, not zoomed out too far, and the link is long
            // enough on screen (>50 px) so labels don't overlap on short links.
            const fromPx   = map.latLngToContainerPoint(from);
            const toPx     = map.latLngToContainerPoint(to);
            const pixelLen = Math.hypot(fromPx.x - toPx.x, fromPx.y - toPx.y);
            if (link.in_bps > 0 && map.getZoom() >= 12 && pixelLen > 50) {
                const midLat = (from.lat + to.lat) / 2;
                const midLng = (from.lng + to.lng) / 2;
                const labelHtml = `\u2193${formatSpeedCompact(link.in_bps)} \u2191${formatSpeedCompact(link.out_bps)}`;
                const icon = L.divIcon({
                    className: '',
                    html: `<div class="netmap-link-label">${labelHtml}</div>`,
                    iconAnchor: [0, 0]
                });
                L.marker([midLat, midLng], { icon: icon, interactive: false }).addTo(linkLabelLayer);
            }
        });
    }

    // ── Scale control + zoom indicator ──────────────────────────────────
    L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(map);

    const zoomIndicatorEl = document.getElementById('netmap-zoom-indicator');
    function updateZoomIndicator() {
        if (zoomIndicatorEl) { zoomIndicatorEl.textContent = 'Zoom: ' + map.getZoom(); }
    }
    updateZoomIndicator();

    // ── Zoom-driven re-render (no re-fetch) ──────────────────────────────
    map.on('zoomend', function () { updateZoomIndicator(); renderAll(); });

    // ── Data fetching ────────────────────────────────────────────────────

    /**
     * Fetch devices and links in parallel, update the cache, and re-render.
     * fitBounds is applied only on the first successful load.
     */
    async function loadData() {
        showLoading();

        try {
            const [devicesResponse, linksResponse] = await Promise.all([
                fetch(config.apiDevices, { credentials: 'same-origin' }),
                fetch(config.apiLinks,   { credentials: 'same-origin' })
            ]);

            if (!devicesResponse.ok) {
                throw new Error(`Devices API returned ${devicesResponse.status}`);
            }
            if (!linksResponse.ok) {
                throw new Error(`Links API returned ${linksResponse.status}`);
            }

            const devicesData = await devicesResponse.json();
            const linksData   = await linksResponse.json();

            cachedDevices = devicesData.devices || [];
            cachedLinks   = linksData.links     || [];

            renderAll();

            if (isFirstLoad && cachedDevices.length > 0) {
                const bounds = L.latLngBounds(
                    cachedDevices.map(function (d) { return [d.lat, d.lng]; })
                );
                map.fitBounds(bounds, { padding: [40, 40], maxZoom: config.zoomThreshold - 1 });
                isFirstLoad = false;
            }
        } catch (err) {
            console.error('[NetworkMap] Error loading data:', err);
        } finally {
            hideLoading();
        }
    }

    // ── Auto-refresh ─────────────────────────────────────────────────────

    const refreshMs = (config.refreshInterval || 60) * 1000;
    setInterval(loadData, refreshMs);

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () { loadData(); });
    }

    // ── Initial load ─────────────────────────────────────────────────────

    loadData();

})();
