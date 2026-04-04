-- NetworkMap Plugin for LibreNMS — Uninstall
-- Purpose: Drops only the plugin-owned tables. Never touches LibreNMS core tables.
-- Usage:   mysql -u librenms -p librenms < sql/uninstall.sql

DROP TABLE IF EXISTS `plugin_networkmap_port_cache`;
DROP TABLE IF EXISTS `plugin_networkmap_device_labels`;
DROP TABLE IF EXISTS `plugin_networkmap_links`;
DROP TABLE IF EXISTS `plugin_networkmap_settings`;
