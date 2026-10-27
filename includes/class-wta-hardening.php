<?php
/**
 * Closes two doors that hand attackers a head start on a WordPress login:
 * the public list of users in the REST API, and XML-RPC.
 *
 * Both are open by default in WordPress. The user list gives anyone every
 * username (the second half of a password guess), and XML-RPC lets a password
 * be tried hundreds of times in one request. Sites that do not use XML-RPC
 * (Jetpack, the old mobile apps, some pingback tools) lose nothing by closing it.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Hardening {

    public function __construct() {
        add_filter('rest_endpoints', array($this, 'filter_rest_endpoints'));

        if ($this->xmlrpc_closed()) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', '__return_empty_array');
            add_filter('wp_headers', array($this, 'drop_pingback_header'));
        }
    }

    /**
     * Whether XML-RPC is closed. On by default; return false to keep it open.
     *
     * @return bool
     */
    protected function xmlrpc_closed() {
        /**
         * @param bool $close Close XML-RPC. Default true.
         */
        return (bool) apply_filters('wta_hardening_close_xmlrpc', true);
    }

    /**
     * Take the user list and single-user routes out of the REST API for anyone
     * who cannot list users. Editors and admins see them as before.
     *
     * @param array $endpoints Registered REST routes.
     * @return array
     */
    public function filter_rest_endpoints($endpoints) {
        /**
         * @param bool $hide Hide the user routes from visitors. Default true.
         */
        if (!apply_filters('wta_hardening_hide_rest_users', true) || current_user_can('list_users')) {
            return $endpoints;
        }

        foreach (array_keys($endpoints) as $route) {
            if (0 === strpos($route, '/wp/v2/users') && 0 !== strpos($route, '/wp/v2/users/me')) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }

    /**
     * Stop advertising the XML-RPC pingback endpoint.
     *
     * @param array $headers Response headers.
     * @return array
     */
    public function drop_pingback_header($headers) {
        unset($headers['X-Pingback']);

        return $headers;
    }
}
