<?php
/**
 * Makes WP Travel trip data readable and writable over the REST API.
 *
 * WP Travel stores trips in a mix of plain meta, protected meta, and one
 * PHP-serialised array. None of it is REST-accessible out of the box, which
 * puts the whole trip catalogue out of reach of API clients.
 *
 * Supersedes the standalone iconic-wte-rest-meta.php mu-plugin.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Trip_Meta {

    public function __construct() {
        // Priority 20: the trip post type registers on init, and post type
        // support has to be added after it exists but before REST builds its
        // schema on rest_api_init.
        add_action('init', array($this, 'add_custom_fields_support'), 20);
        add_action('init', array($this, 'register_meta'), 20);
        add_action('rest_api_init', array($this, 'register_itinerary_field'));

        add_action('updated_post_meta', array($this, 'sync_duration'), 10, 3);
        add_action('added_post_meta', array($this, 'sync_duration'), 10, 3);
    }

    /**
     * WP_REST_Posts_Controller only adds the `meta` object to a resource when
     * the post type supports custom-fields. WP Travel registers the trip type
     * without it, so every register_post_meta() call below is inert until this
     * runs — the meta is registered, but never appears in the REST response.
     */
    public function add_custom_fields_support() {
        if (!WTA_Trip::is_available()) {
            return;
        }

        add_post_type_support(WTA_Trip::post_type(), 'custom-fields');
    }

    /**
     * Only users who can edit the trip may write these fields.
     */
    public function auth_callback($allowed, $meta_key, $post_id) {
        return current_user_can('edit_post', $post_id);
    }

    public function register_meta() {
        if (!WTA_Trip::is_available()) {
            return;
        }

        $post_type = WTA_Trip::post_type();
        $fields    = WTA_Trip::meta_fields();

        foreach ($fields['text'] as $key) {
            register_post_meta($post_type, $key, array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
            ));
        }

        foreach ($fields['html'] as $key) {
            register_post_meta($post_type, $key, array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => 'wp_kses_post',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
            ));
        }

        // Protected meta is returned to any reader once show_in_rest is on —
        // auth_callback gates writes, not reads. Only expose keys whose values
        // are already public in the page source.
        foreach ($fields['protected'] as $key) {
            register_post_meta($post_type, $key, array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => array($this, 'auth_callback'),
            ));
        }
    }

    /**
     * The day-by-day itinerary is a PHP-serialised array. Exposing it through
     * register_post_meta() would hand clients a raw serialised string and let a
     * malformed write corrupt the trip, so it gets a proper JSON field instead.
     *
     * GET  /wp-json/wp/v2/itineraries/<id>
     *      -> { "itinerary_days": [ { label, title, desc, date, time } ] }
     *
     * POST /wp-json/wp/v2/itineraries/<id>
     *      { "itinerary_days": [ { "label": "Day 1", "title": "Arrival" } ] }
     */
    public function register_itinerary_field() {
        if (!WTA_Trip::is_available()) {
            return;
        }

        register_rest_field(WTA_Trip::post_type(), 'itinerary_days', array(
            'get_callback'    => array($this, 'get_itinerary_days'),
            'update_callback' => array($this, 'update_itinerary_days'),
            'schema'          => array(
                'description' => 'Day-by-day itinerary blocks.',
                'type'        => 'array',
                'context'     => array('view', 'edit'),
                'items'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'label' => array('type' => 'string'),
                        'title' => array('type' => 'string'),
                        'desc'  => array('type' => 'string'),
                        'date'  => array('type' => 'string'),
                        'time'  => array('type' => 'string'),
                    ),
                ),
            ),
        ));
    }

    public function get_itinerary_days($post) {
        $raw = get_post_meta($post['id'], WTA_Trip::ITINERARY_META, true);

        if (empty($raw) || !is_array($raw)) {
            return array();
        }

        $out = array();
        foreach ($raw as $day) {
            $out[] = array(
                'label' => isset($day['label']) ? (string) $day['label'] : '',
                'title' => isset($day['title']) ? (string) $day['title'] : '',
                'desc'  => isset($day['desc']) ? (string) $day['desc'] : '',
                'date'  => isset($day['date']) ? (string) $day['date'] : '',
                'time'  => isset($day['time']) ? (string) $day['time'] : '',
            );
        }

        return $out;
    }

    public function update_itinerary_days($value, $post) {
        if (!current_user_can('edit_post', $post->ID)) {
            return new WP_Error(
                'wta_forbidden',
                'You are not allowed to edit this trip.',
                array('status' => 403)
            );
        }

        if (!is_array($value)) {
            return new WP_Error(
                'wta_bad_format',
                'itinerary_days must be an array of day objects.',
                array('status' => 400)
            );
        }

        $clean = array();
        foreach (array_values($value) as $i => $day) {
            if (!is_array($day)) {
                continue;
            }

            $clean[$i] = array(
                'label' => isset($day['label']) ? sanitize_text_field($day['label']) : sprintf('Day %d', $i + 1),
                'title' => isset($day['title']) ? sanitize_text_field($day['title']) : '',
                'date'  => isset($day['date']) ? sanitize_text_field($day['date']) : '',
                'time'  => isset($day['time']) ? sanitize_text_field($day['time']) : '',
                'desc'  => isset($day['desc']) ? wp_kses_post($day['desc']) : '',
            );
        }

        update_post_meta($post->ID, WTA_Trip::ITINERARY_META, $clean);

        return true;
    }

    /**
     * WP Travel stores duration twice: as two scalars, and again in a
     * serialised array which is what the front end renders. Writing only the
     * scalars leaves the trip meta strip showing stale values.
     */
    public function sync_duration($meta_id, $post_id, $meta_key) {
        $watched = array('wp_travel_trip_duration', 'wp_travel_trip_duration_night');

        if (!in_array($meta_key, $watched, true)) {
            return;
        }

        if (get_post_type($post_id) !== WTA_Trip::post_type()) {
            return;
        }

        update_post_meta($post_id, WTA_Trip::DURATION_MIRROR_META, array(
            'days'   => (string) get_post_meta($post_id, 'wp_travel_trip_duration', true),
            'nights' => (string) get_post_meta($post_id, 'wp_travel_trip_duration_night', true),
        ));
    }
}
