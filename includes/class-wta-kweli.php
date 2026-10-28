<?php
/**
 * Route stops that point at a place in Mukoko Kweli.
 *
 * Kweli is where place information is stored and built; the Nyuchi API serves
 * it. A route stop can carry a Kweli place (its id or slug) and the stop's
 * position then comes from that place instead of being typed in by hand.
 *
 * The fetch happens on the server, once a day per place, so visitors never
 * call the API from their browsers and nothing third-party is loaded on the
 * page. A stop keeps its own latitude and longitude as the fallback: a failed
 * fetch, an unknown place, or a place that sits more than MAX_KM from where the
 * stop already says it is (a swapped latitude and longitude, say) leaves the
 * stop as authored.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Kweli {

    const BASE = 'https://api.nyuchi.com';

    /** Seconds a found place is kept. */
    const TTL = DAY_IN_SECONDS;

    /** Seconds a failed lookup is remembered, so a down API is not hammered. */
    const MISS_TTL = HOUR_IN_SECONDS;

    /** Furthest a Kweli place may sit from a stop's own coordinates and still be trusted. */
    const MAX_KM = 150;

    public function __construct() {
        add_filter('wta_route_stops', array($this, 'resolve_route'), 10, 1);
    }

    /**
     * The API address. Filterable for a staging API.
     *
     * @return string
     */
    public static function base() {
        $base = apply_filters('wta_kweli_base', self::BASE);
        $base = is_string($base) ? esc_url_raw(untrailingslashit($base), array('https')) : '';

        return '' !== $base ? $base : self::BASE;
    }

    /**
     * A place id or slug that is safe to put in a URL path.
     *
     * @param mixed $value Candidate.
     * @return string Empty when it is not one.
     */
    public static function clean_id($value) {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value) ? $value : '';
    }

    /**
     * One place, from cache or the API.
     *
     * @param string $id Place id or slug.
     * @return array{name: string, slug: string, lat: float, lng: float, region: string, country: string}|null
     */
    public static function place($id) {
        $id = self::clean_id($id);

        if ('' === $id) {
            return null;
        }

        $key    = 'wta_kweli_' . md5($id);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        if ('miss' === $cached) {
            return null;
        }

        $place = self::fetch($id);

        set_transient($key, $place ? $place : 'miss', $place ? self::TTL : self::MISS_TTL);

        return $place;
    }

    protected static function fetch($id) {
        $response = wp_remote_get(self::base() . '/v1/places/' . rawurlencode($id), array(
            'timeout'    => 5,
            'headers'    => array('Accept' => 'application/json'),
            'user-agent' => 'NyuchiTravelAddons/' . (defined('WTA_VERSION') ? WTA_VERSION : '1'),
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        return self::parse(json_decode(wp_remote_retrieve_body($response), true));
    }

    /**
     * Reduce a Kweli reply to the few fields a route needs.
     *
     * Kweli stores GeoJSON, so coordinates are [longitude, latitude].
     *
     * @param mixed $body Decoded reply.
     * @return array|null
     */
    public static function parse($body) {
        $place = (is_array($body) && isset($body['place']) && is_array($body['place'])) ? $body['place'] : null;

        if (!$place || (isset($place['isActive']) && false === $place['isActive'])) {
            return null;
        }

        $coords = (isset($place['geo']['coordinates']) && is_array($place['geo']['coordinates'])) ? $place['geo']['coordinates'] : array();

        if (2 !== count($coords) || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            return null;
        }

        $lng = (float) $coords[0];
        $lat = (float) $coords[1];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || (0.0 === $lat && 0.0 === $lng)) {
            return null;
        }

        $address = (isset($place['address']) && is_array($place['address'])) ? $place['address'] : array();

        return array(
            'name'    => isset($place['name']) ? sanitize_text_field($place['name']) : '',
            'slug'    => isset($place['slug']) ? sanitize_title($place['slug']) : '',
            'lat'     => $lat,
            'lng'     => $lng,
            'region'  => isset($address['addressRegion']) ? sanitize_text_field($address['addressRegion']) : '',
            'country' => isset($address['addressCountry']) ? sanitize_text_field($address['addressCountry']) : '',
        );
    }

    /**
     * Great-circle distance in kilometres.
     */
    public static function km($lat1, $lng1, $lat2, $lng2) {
        $r  = 6371.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);
        $a  = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /**
     * Fill in the coordinates of every stop that names a Kweli place.
     *
     * @param array $stops Sanitised route stops.
     * @return array
     */
    public function resolve_route($stops) {
        if (!is_array($stops)) {
            return array();
        }

        foreach ($stops as $i => $stop) {
            if (empty($stop['place'])) {
                continue;
            }

            $place = self::place($stop['place']);

            if (!$place) {
                continue;
            }

            $has_own = (null !== $stop['lat'] && null !== $stop['lng']);

            // A place far from where the stop already says it is, is more likely
            // a data slip (swapped axes) than a different answer. Keep the stop.
            if ($has_own && self::km($stop['lat'], $stop['lng'], $place['lat'], $place['lng']) > self::MAX_KM) {
                continue;
            }

            $stops[$i]['lat'] = $place['lat'];
            $stops[$i]['lng'] = $place['lng'];

            if ('' === (string) $stop['name'] && '' !== $place['name']) {
                $stops[$i]['name'] = $place['name'];
            }

            if ('' === (string) $stop['subtitle'] && '' !== $place['region']) {
                $stops[$i]['subtitle'] = $place['region'];
            }
        }

        return $stops;
    }
}
