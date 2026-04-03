<?php

// Plugin DB helpers using dbFacile — loaded by LibreNMS before including the plugin.
// Functions: dbFetchRow(), dbFetchRows(), dbInsert(), dbUpdate(), dbDelete(), dbQuery()

function netmap_get_setting(string $key, $default = null)
{
    $row = dbFetchRow(
        'SELECT `value` FROM `plugin_networkmap_settings` WHERE `key` = ?',
        [$key]
    );

    return ($row !== false && $row !== null) ? $row['value'] : $default;
}

function netmap_set_setting(string $key, string $value): void
{
    $affected = dbUpdate(
        ['value' => $value],
        'plugin_networkmap_settings',
        '`key` = ?',
        [$key]
    );

    if ($affected === 0) {
        dbInsert(['key' => $key, 'value' => $value], 'plugin_networkmap_settings');
    }
}

function netmap_get_port_cache(int $port_id): ?array
{
    $row = dbFetchRow(
        'SELECT * FROM `plugin_networkmap_port_cache` WHERE `port_id` = ?',
        [$port_id]
    );

    return ($row !== false && $row !== null) ? $row : null;
}

function netmap_upsert_port_cache(
    int $port_id,
    int $in_octets,
    int $out_octets,
    int $in_bps,
    int $out_bps
): void {
    $affected = dbUpdate(
        ['in_octets' => $in_octets, 'out_octets' => $out_octets, 'in_bps' => $in_bps, 'out_bps' => $out_bps],
        'plugin_networkmap_port_cache',
        '`port_id` = ?',
        [$port_id]
    );

    if ($affected === 0) {
        dbInsert(
            ['port_id' => $port_id, 'in_octets' => $in_octets, 'out_octets' => $out_octets, 'in_bps' => $in_bps, 'out_bps' => $out_bps],
            'plugin_networkmap_port_cache'
        );
    }
}
