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
        'SELECT port_id, in_octets, out_octets, in_bps, out_bps,
                UNIX_TIMESTAMP(updated_at) AS updated_ts
         FROM `plugin_networkmap_port_cache` WHERE `port_id` = ?',
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

/**
 * Calculate in/out bps for a port using the cached octet snapshot.
 *
 * Formula: bps = (octets_now - octets_prev) * 8 / seconds_elapsed
 *
 * Counter wrap detection: if octets_now < octets_prev (32-bit counter wrap),
 * bps is set to 0 for this cycle and the cache is updated with the new snapshot.
 *
 * @param  int   $port_id
 * @param  int   $in_octets   current ifInOctets from ports table
 * @param  int   $out_octets  current ifOutOctets from ports table
 * @return array [$in_bps, $out_bps]
 */
function netmap_calc_bps(int $port_id, int $in_octets, int $out_octets): array
{
    $cache   = netmap_get_port_cache($port_id);
    $in_bps  = 0;
    $out_bps = 0;

    if ($cache !== null) {
        $prev_in  = (int) $cache['in_octets'];
        $prev_out = (int) $cache['out_octets'];

        if ($in_octets === $prev_in && $out_octets === $prev_out) {
            // Poller hasn't updated octets yet — serve the last computed bps
            // without touching the cache so the timestamp stays as-is.
            return [(int) $cache['in_bps'], (int) $cache['out_bps']];
        }

        $elapsed = time() - (int) $cache['updated_ts'];

        // Only calculate if time has elapsed and no counter wrap detected
        if ($elapsed > 0 && $in_octets >= $prev_in && $out_octets >= $prev_out) {
            $in_bps  = (int) round(($in_octets  - $prev_in)  * 8 / $elapsed);
            $out_bps = (int) round(($out_octets - $prev_out) * 8 / $elapsed);
        }
        // Counter wrap: bps stays 0, cache updated below with new snapshot
    }

    netmap_upsert_port_cache($port_id, $in_octets, $out_octets, $in_bps, $out_bps);
    return [$in_bps, $out_bps];
}
