-- NetworkMap Plugin for LibreNMS
-- Purpose: Creates plugin-owned tables for geographic map visualization,
--          manual link definitions, plugin configuration, and traffic rate cache.
-- Target:  MariaDB 10.11
-- Usage:   mysql -u librenms -p librenms < sql/install.sql

-- ----------------------------------------------------------------------------
-- Table 1: Manual links between devices
-- Stores user-defined links that supplement auto-discovered LLDP topology.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plugin_networkmap_links` (
  `id`               INT          AUTO_INCREMENT PRIMARY KEY,
  `local_device_id`  INT          NOT NULL,
  `remote_device_id` INT          NOT NULL,
  `local_port_id`    INT          DEFAULT NULL,
  `remote_port_id`   INT          DEFAULT NULL,
  `label`            VARCHAR(255) DEFAULT NULL,
  `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 2: Plugin configuration (key/value store)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plugin_networkmap_settings` (
  `key`   VARCHAR(100) PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings — INSERT IGNORE preserves any values already configured.
INSERT IGNORE INTO `plugin_networkmap_settings` (`key`, `value`) VALUES
  ('map_provider',            'osm'),
  ('google_api_key',          ''),
  ('refresh_interval',        '60'),
  ('zoom_threshold_cluster',  '18'),
  ('default_zoom',            'auto'),
  ('tv_token',                ''),
  ('hidden_links',            '[]');

-- ----------------------------------------------------------------------------
-- Table 3: Custom map labels per device (overrides display_name on the map)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plugin_networkmap_device_labels` (
  `device_id`  INT          PRIMARY KEY,
  `map_label`  VARCHAR(255) NOT NULL,
  `updated_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additional default settings
INSERT IGNORE INTO `plugin_networkmap_settings` (`key`, `value`) VALUES
  ('links_filter_initialized', '0'),
  ('link_priorities',          '{}');

-- Visual style profiles (map = normal mode, tv = dark NOC mode)
INSERT IGNORE INTO `plugin_networkmap_settings` (`key`, `value`) VALUES
  ('map_styles', '{"map":{"node_radius":8,"node_color_up":"#2ecc71","node_color_alert":"#f39c12","node_color_down":"#e74c3c","label_size":13,"label_color":"#333333","link_width_min":1,"link_width_max":8,"link_color_low":"#2ecc71","link_color_mid":"#f39c12","link_color_high":"#e74c3c","link_color_manual":"#3498db","trafficlabel_size":12,"trafficlabel_bg":"#000000","trafficlabel_color":"#ffffff"},"tv":{"node_radius":8,"node_color_up":"#00ff88","node_color_alert":"#ffaa00","node_color_down":"#ff3333","label_size":13,"label_color":"#ffffff","link_width_min":1,"link_width_max":8,"link_color_low":"#00cc66","link_color_mid":"#ff8800","link_color_high":"#ff2222","link_color_manual":"#4488ff","trafficlabel_size":12,"trafficlabel_bg":"#1a1a1a","trafficlabel_color":"#00ff88"}}');

-- ----------------------------------------------------------------------------
-- Table 4: Traffic rate cache (translates SNMP octet counters to bps)
-- Updated each polling cycle; one row per monitored port.
-- bps = (octets_now - octets_prev) * 8 / seconds_elapsed
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plugin_networkmap_port_cache` (
  `port_id`    INT       PRIMARY KEY,
  `in_octets`  BIGINT    DEFAULT 0,
  `out_octets` BIGINT    DEFAULT 0,
  `in_bps`     BIGINT    DEFAULT 0,
  `out_bps`    BIGINT    DEFAULT 0,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
