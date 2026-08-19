<?php
/**
 * Shared knowledge about WP Travel's data shape.
 *
 * Everything WP-Travel-specific that more than one module needs lives here, so
 * a schema change upstream is a one-file edit rather than a hunt.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Trip {

    /**
     * Post type WP Travel stores trips in.
     *
     * Filterable: the slug has differed between versions, and WP Travel Engine
     * (a different product) uses its own.
     */
    public static function post_type() {
        return apply_filters('wta_trip_post_type', 'itineraries');
    }

    public static function is_available() {
        return post_type_exists(self::post_type());
    }

    /**
     * Taxonomies WP Travel registers against trips.
     *
     * @return array<string, string> slug => human label
     */
    public static function default_taxonomies() {
        return apply_filters('wta_trip_taxonomies', array(
            'travel_locations' => 'Destinations',
            'activity'         => 'Activities',
            'itinerary_types'  => 'Trip Types',
            'travel_keywords'  => 'Keywords',
        ));
    }

    /**
     * Plain string and HTML trip fields, grouped by how they must be sanitised.
     *
     * Keys verified against WP Travel 12.0.0.
     *
     * @return array{text: string[], html: string[], protected: string[]}
     */
    public static function meta_fields() {
        return apply_filters('wta_trip_meta_fields', array(
            // Scalars — prices, durations, codes, coordinates.
            'text' => array(
                'wp_travel_trip_price',
                'wp_travel_trip_duration',
                'wp_travel_trip_duration_night',
                'wp_travel_group_size',
                'wp_travel_trip_code',
                'wp_travel_location',
                'wp_travel_lat',
                'wp_travel_lng',
                'wp_travel_fixed_departure',
                'wp_travel_trip_map_use_lat_lng',
            ),
            // Rich text. wp_kses_post keeps the markup the theme renders.
            'html' => array(
                'wp_travel_overview',
                'wp_travel_outline',
                'wp_travel_trip_include',
                'wp_travel_trip_exclude',
            ),
            // Leading underscore means protected: WordPress refuses to write
            // these over REST without an explicit auth callback.
            'protected' => array(
                '_yoast_wpseo_title',
                '_yoast_wpseo_metadesc',
                '_yoast_wpseo_focuskw',
                '_yoast_wpseo_primary_travel_locations',
                '_yoast_wpseo_primary_activity',
                '_yoast_wpseo_primary_itinerary_types',
            ),
        ));
    }

    /** Serialised day-by-day itinerary. */
    const ITINERARY_META = 'wp_travel_trip_itinerary_data';

    /** Serialised duration mirror the front end actually renders. */
    const DURATION_MIRROR_META = 'wp_travel_trip_duration_formating';

    /**
     * Normalised trip facts, for anything that needs to read a trip without
     * knowing WP Travel's key names.
     */
    public static function facts($post_id) {
        return array(
            'price'      => get_post_meta($post_id, 'wp_travel_trip_price', true),
            'days'       => get_post_meta($post_id, 'wp_travel_trip_duration', true),
            'nights'     => get_post_meta($post_id, 'wp_travel_trip_duration_night', true),
            'group_size' => get_post_meta($post_id, 'wp_travel_group_size', true),
            'code'       => get_post_meta($post_id, 'wp_travel_trip_code', true),
            'location'   => get_post_meta($post_id, 'wp_travel_location', true),
            'overview'   => get_post_meta($post_id, 'wp_travel_overview', true),
        );
    }
}
