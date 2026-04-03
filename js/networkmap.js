/**
 * NetworkMap — client-side map logic for LibreNMS NetworkMap plugin.
 *
 * Depends on:
 *   - Leaflet 1.9.4 (loaded before this script)
 *   - window.netmapConfig (set by map.php before this script)
 *
 * Phase 2 scope: OSM tiles, device markers with labels, LLDP/manual link
 * lines, popups, tooltips, auto-refresh.
 */
(function () {
    'use strict';

    const config = window.netmapConfig;

    // ── DOM references ───────────────────────────────────────────────────
    const loadingEl = document.getElementById('netmap-loading');
    const refreshBtn = document.getElementById('netmap-refresh');

    // ── Leaflet map & layer groups ───────────────────────────────────────
    const map = L.map('netmap', {
        minZoom: 2,
        maxZoom: 18,
        zoomControl: true
    }).setView([28.1, -15.4], 8); // sensible default; fitBounds will override

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 18
    }).addTo(map);

    // Layer groups so we can clear/redraw easily.
    // Order matters: layers added later render on top.
    // Links go first (bottom), then device circles, then labels (top).
    let linkLayer = L.layerGroup().addTo(map);
    let deviceLayer = L.layerGroup().addTo(map);
    let labelLayer = L.layerGroup().addTo(map);

    // ── Formatting helpers ───────────────────────────────────────────────

    /**
     * Format an uptime value (in seconds) to a human-readable string.
     * Returns "Xd Yh Zm" — e.g. 14d 3h 22m, 0d 0h 5m.
     *
     * @param {number} seconds - uptime in seconds
     * @returns {string}
     */
    function formatUptime(seconds) {
        const s = Math.max(0, Math.floor(seconds));
        const days = Math.floor(s / 86400);
        const hours = Math.floor((s % 86400) / 3600);
        const minutes = Math.floor((s % 3600) / 60);
        return `${days}d ${hours}h ${minutes}m`;
    }

    /**
     * Format a speed in bits-per-second to a human-readable string.
     * Chooses the most appropriate unit: Gbps, Mbps, or Kbps.
     *
     * @param {number} bps - speed in bits per second
     * @returns {string}
     */
    function formatSpeed(bps) {
        if (bps >= 1e9) {
            return (bps / 1e9).toFixed(1) + ' Gbps';
        }
        if (bps >= 1e6) {
            return (bps / 1e6).toFixed(1) + ' Mbps';
        }
        if (bps >= 1e3) {
            return (bps / 1e3).toFixed(1) + ' Kbps';
        }
        return bps + ' bps';
    }

    /**
     * Determine the fill color for a device node based on its operational
     * status and active alert count.
     *
     * Color logic:
     *   - status 1, no alerts  -> green  (#2ecc71)  — healthy
     *   - status 1, alerts > 0 -> orange (#f39c12)  — up but degraded
     *   - status 0             -> red    (#e74c3c)  — down
     *   - anything else        -> grey   (#95a5a6)  — unknown / unmonitored
     *
     * @param {object} device - device object from the API
     * @returns {string} hex color
     */
    function deviceColor(device) {
        if (device.status === 1 && device.active_alerts === 0) {
            return '#2ecc71';
        }
        if (device.status === 1 && device.active_alerts > 0) {
            return '#f39c12';
        }
        if (device.status === 0) {
            return '#e74c3c';
        }
        return '#95a5a6';
    }

    /**
     * Determine the color for a link line based on its status.
     *
     * @param {string} status - 'up', 'down', or anything else
     * @returns {string} hex color
     */
    function linkColor(status) {
        if (status === 'up') {
            return '#2ecc71';
        }
        if (status === 'down') {
            return '#e74c3c';
        }
        return '#95a5a6';
    }

    // ── Loading overlay ──────────────────────────────────────────────────

    function showLoading() {
        if (loadingEl) {
            loadingEl.classList.add('visible');
        }
    }

    function hideLoading() {
        if (loadingEl) {
            loadingEl.classList.remove('visible');
        }
    }

    // ── Rendering ────────────────────────────────────────────────────────

    /**
     * Render device CircleMarkers and DivIcon labels onto the map.
     * Returns a lookup map { deviceId -> latLng } for link rendering.
     *
     * @param {Array} devices
     * @returns {Object} deviceCoords - { id: L.LatLng }
     */
    function renderDevices(devices) {
        const deviceCoords = {};

        devices.forEach(function (d) {
            const latLng = L.latLng(d.lat, d.lng);
            deviceCoords[d.id] = latLng;

            // Circle marker
            const circle = L.circleMarker(latLng, {
                radius: 8,
                fillColor: deviceColor(d),
                fillOpacity: 0.85,
                weight: 2,
                color: '#fff'
            });

            // Status text for popup
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

            // Always-visible label below the circle
            const labelIcon = L.divIcon({
                className: '', // no default leaflet styling
                html: `<div class="netmap-label">${escapeHtml(d.display_name)}</div>`,
                iconAnchor: [0, 0]
            });

            const labelMarker = L.marker(latLng, {
                icon: labelIcon,
                interactive: false // labels are not clickable
            });

            labelMarker.addTo(labelLayer);
        });

        return deviceCoords;
    }

    /**
     * Render link polylines between devices.
     *
     * @param {Array} links
     * @param {Object} deviceCoords - { deviceId: L.LatLng }
     */
    function renderLinks(links, deviceCoords) {
        links.forEach(function (link) {
            const from = deviceCoords[link.local_device_id];
            const to = deviceCoords[link.remote_device_id];

            // Skip links where either device is missing from the loaded set
            if (!from || !to) {
                return;
            }

            const polylineOpts = {
                color: linkColor(link.status),
                weight: 2,
                opacity: 0.7
            };

            // Manual links are drawn dashed
            if (link.type === 'manual') {
                polylineOpts.dashArray = '6, 4';
            }

            const line = L.polyline([from, to], polylineOpts);

            // Tooltip on hover
            const statusLabel = (link.status || 'unknown').toUpperCase();
            const tooltipText = `${escapeHtml(link.local_port)} &rarr; ${escapeHtml(link.remote_port)}\n${formatSpeed(link.speed_bps)} | ${statusLabel}`;

            line.bindTooltip(tooltipText, {
                className: 'netmap-link-tooltip',
                sticky: true
            });

            // Popup on click
            const statusClass = link.status === 'up' ? 'status-up' : 'status-down';
            const popupHtml = `<div class="netmap-popup">
  <h4>${escapeHtml(link.local_port)} &#9472;&#9472;&#9472;&#9472; ${escapeHtml(link.remote_port)}</h4>
  <div>Velocidad: ${formatSpeed(link.speed_bps)}</div>
  <div>Estado: <span class="${statusClass}">${statusLabel}</span></div>
</div>`;

            line.bindPopup(popupHtml, { className: 'netmap-popup' });
            line.addTo(linkLayer);
        });
    }

    // ── HTML escaping ────────────────────────────────────────────────────

    /**
     * Minimal HTML escaping to prevent XSS from device names / port labels.
     *
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        if (str == null) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Data fetching & refresh ──────────────────────────────────────────

    let isFirstLoad = true;

    /**
     * Fetch devices and links from the API in parallel, clear existing
     * layers, and re-render the map.
     */
    async function loadData() {
        showLoading();

        try {
            const [devicesResponse, linksResponse] = await Promise.all([
                fetch(config.apiDevices, {credentials: 'same-origin'}),
                fetch(config.apiLinks, {credentials: 'same-origin'})
            ]);

            if (!devicesResponse.ok) {
                throw new Error(`Devices API returned ${devicesResponse.status}`);
            }
            if (!linksResponse.ok) {
                throw new Error(`Links API returned ${linksResponse.status}`);
            }

            const devicesData = await devicesResponse.json();
            const linksData = await linksResponse.json();

            const devices = devicesData.devices || [];
            const links = linksData.links || [];

            // Clear existing layers
            deviceLayer.clearLayers();
            labelLayer.clearLayers();
            linkLayer.clearLayers();

            // Render devices first (to build coord lookup), then links
            const deviceCoords = renderDevices(devices);
            renderLinks(links, deviceCoords);

            // Fit bounds on first load to encompass all device coordinates
            if (isFirstLoad && devices.length > 0) {
                const bounds = L.latLngBounds(
                    devices.map(function (d) { return [d.lat, d.lng]; })
                );
                map.fitBounds(bounds, { padding: [40, 40] });
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

    // Manual refresh button
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            loadData();
        });
    }

    // ── Initial load ─────────────────────────────────────────────────────

    loadData();

})();
