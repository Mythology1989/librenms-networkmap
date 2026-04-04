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
  ('zoom_threshold_cluster',  '17'),
  ('default_zoom',            'auto'),
  ('tv_token',                '');

-- ----------------------------------------------------------------------------
-- Table 3: Traffic rate cache (translates SNMP octet counters to bps)
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
