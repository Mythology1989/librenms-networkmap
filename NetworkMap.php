<?php

/**
 * NetworkMap plugin registration class.
 *
 * LibreNMS 26.x loads this class during Laravel bootstrap to register plugin
 * hooks. At this point only the Laravel container is available — no dbFacile,
 * no LegacyAuth, no LibreNMS helper functions.
 *
 * Keep this class free of any DB access, file includes, or LibreNMS-specific
 * APIs. Its sole responsibility is declaring hook methods.
 */
class NetworkMap
{
    /**
     * Render the sidebar menu entry for the plugin.
     * LibreNMS calls this when building the navigation menu.
     */
    public function menu(): void
    {
        echo '<li><a href="' . url('/plugin/v1/NetworkMap') . '">'
            . '<i class="fa fa-map-marker"></i> Network Map'
            . '</a></li>';
    }
}
