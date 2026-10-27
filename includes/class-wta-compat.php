<?php
/**
 * Compatibility helpers for WP Travel companion plugins.
 *
 * Every helper here exists because a third-party plugin shipped a bug we cannot
 * patch in place, and each should be deleted when the vendor fixes it.
 *
 * Retired:
 *
 * - WT Widgets for Elementor 1.4.7 passed the itinerary day description to
 *   printf() as the format string, so any '%' an editor typed was parsed as a
 *   conversion specifier (a fatal error on PHP 8). Reported as WP Travel ticket
 *   #2051 and fixed upstream in 1.4.8: the description is now an argument, with
 *   its own filter. The runtime guard that escaped '%' has been removed.
 *
 * Still here:
 *
 * - scan_trips() reports day descriptions that would break an affected version.
 *   It stays as a diagnostic for any site still on WT Widgets before 1.4.8.
 * - The single-trip timeline stylesheet, loaded only on a trip.
 * - WT Widgets for Elementor 1.4.9 registers its slick carousel scripts with no
 *   dependencies (the `true` in category-trips-widget.php:54 and
 *   hero-slider-widget.php:59 lands in the version argument, not in_footer),
 *   so slick printed ahead of jQuery and logged "jQuery is not defined" on
 *   every page, whether or not a carousel was used. fix_slick_jquery() adds the
 *   missing dependency.
 * - The home page hero is a muted, looping background video with a poster image
 *   underneath. Phones that refuse to autoplay it (iOS Low Power Mode, data
 *   saver, some in-app browsers) show a native play button that sits behind the
 *   hero text and cannot be tapped. hero_video_fallback() hides that button and,
 *   when playback does not start, removes the video so the poster shows.
 * - WP Travel's TouristTrip schema offers every trip at price 0 (and a bare
 *   eligibleQuantity), and leaves HTML entities in its text. fix_trip_schema()
 *   replaces the offer with the trip's real from-price per person, or drops it
 *   when the trip has no price, and decodes the entities.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Compat {

    /** Upper bound on a single scan, so a large catalogue cannot time out. */
    const SCAN_LIMIT = 500;

    /** Conversion characters PHP's printf family accepts. */
    const CONVERSIONS = 'bcdeEfFgGhHosuxX';

    /** Script handles WT Widgets for Elementor registers for slick. */
    const SLICK_HANDLES = array('slick-min-js', 'slick-init', 'category-trips-slick');

    public function __construct() {
        // WP Travel's own itinerary timeline. A trip rendered without an
        // Elementor template still has to look like the same website.
        add_action('wp_enqueue_scripts', array($this, 'enqueue_timeline_css'));

        // Late, so the vendor has enqueued its scripts; and again just before
        // printing, for any enqueued after wp_enqueue_scripts. Idempotent.
        add_action('wp_enqueue_scripts', array($this, 'fix_slick_jquery'), 100);
        add_action('wp_print_scripts', array($this, 'fix_slick_jquery'), 1);

        add_action('wp_footer', array($this, 'hero_video_fallback'), 99);

        // After WP Travel and any other source has had its say.
        add_filter('wp_travel_trip_schema', array($this, 'fix_trip_schema'), 99, 2);
    }

    /**
     * Trips whose itinerary descriptions would break the vendor widget.
     *
     * Only WT Widgets before 1.4.8 break on these. Used to tell an editor which
     * pages would be broken, and which would quietly render mangled text at
     * HTTP 200 where nothing is logged.
     *
     * @return array<int, array{post_id:int, title:string, day:int, excerpt:string, mode:string}>
     */
    public static function scan_trips() {
        $findings = array();

        if (!WTA_Trip::is_available()) {
            return $findings;
        }

        $trip_ids = get_posts(array(
            'post_type'              => WTA_Trip::post_type(),
            'post_status'            => 'any',
            'posts_per_page'         => self::SCAN_LIMIT,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'update_post_term_cache' => false,
        ));

        if (empty($trip_ids)) {
            return $findings;
        }

        // One meta query for the whole batch rather than one per trip.
        update_meta_cache('post', $trip_ids);

        foreach ($trip_ids as $trip_id) {
            $days = get_post_meta($trip_id, WTA_Trip::ITINERARY_META, true);

            if (empty($days) || !is_array($days)) {
                continue;
            }

            $title = get_the_title($trip_id);

            foreach (array_values($days) as $index => $day) {
                $desc = isset($day['desc']) ? (string) $day['desc'] : '';

                if ('' === $desc || false === strpos($desc, '%')) {
                    continue;
                }

                $findings[] = array(
                    'post_id' => (int) $trip_id,
                    'title'   => (string) $title,
                    'day'     => $index + 1,
                    'excerpt' => self::excerpt($desc),
                    'mode'    => self::classify($desc),
                );
            }
        }

        return $findings;
    }

    /**
     * How the vendor's printf() would fail on this text.
     *
     * The format is parsed here rather than executed: handing user content to
     * printf() to find out whether it is fatal would be the very crash we are
     * trying to report on.
     *
     * @return string 'fatal' | 'corruption'
     */
    protected static function classify($text) {
        $length = strlen($text);
        $valid  = 0;
        $offset = 0;

        while (false !== ($pos = strpos($text, '%', $offset))) {
            $i = $pos + 1;

            // A trailing '%' has nothing to interpret: ValueError.
            if ($i >= $length) {
                return 'fatal';
            }

            // '%%' is an escaped literal, not a specifier.
            if ('%' === $text[$i]) {
                $offset = $i + 1;
                continue;
            }

            $i = self::skip_argnum($text, $i, $length);
            $i = self::skip_flags($text, $i, $length);

            // Width.
            while ($i < $length && ctype_digit($text[$i])) {
                $i++;
            }

            // Precision.
            if ($i < $length && '.' === $text[$i]) {
                $i++;
                while ($i < $length && ctype_digit($text[$i])) {
                    $i++;
                }
            }

            // Anything that is not a conversion character here is the
            // "Unknown format specifier" ValueError — e.g. "about 20%!".
            if ($i >= $length || false === strpos(self::CONVERSIONS, $text[$i])) {
                return 'fatal';
            }

            $valid++;
            $offset = $i + 1;
        }

        // The widget supplies fewer arguments than this many specifiers, so the
        // second one onwards is an ArgumentCountError.
        if ($valid >= 2) {
            return 'fatal';
        }

        // One specifier eats the following characters and prints an unrelated
        // value; zero specifiers means the text held only '%%', which prints as
        // a single '%'. Both reach the visitor as wrong text at HTTP 200.
        return 'corruption';
    }

    /** Positional argument reference, e.g. "%1$s". */
    protected static function skip_argnum($text, $i, $length) {
        $j = $i;

        while ($j < $length && ctype_digit($text[$j])) {
            $j++;
        }

        if ($j > $i && $j < $length && '$' === $text[$j]) {
            return $j + 1;
        }

        return $i;
    }

    /** Sign, space, zero and left-justify flags, plus "'x" custom padding. */
    protected static function skip_flags($text, $i, $length) {
        while ($i < $length) {
            $char = $text[$i];

            if ('-' === $char || '+' === $char || ' ' === $char || '0' === $char) {
                $i++;
                continue;
            }

            // "'x" sets the pad character, so the next byte is consumed too.
            if ("'" === $char) {
                $i += 2;
                continue;
            }

            break;
        }

        return $i;
    }

    /**
     * A short window around the first '%', so the offending phrase is visible
     * without dumping a whole day description into a report.
     */
    protected static function excerpt($text) {
        $plain = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
        $pos   = strpos($plain, '%');

        if (false === $pos) {
            return $plain;
        }

        $start   = max(0, $pos - 40);
        $snippet = substr($plain, $start, 120);

        return ($start > 0 ? '…' : '') . $snippet . (strlen($plain) > $start + 120 ? '…' : '');
    }

    /**
     * Style WP Travel's itinerary timeline on single trips.
     *
     * Loaded only on a trip: these selectors are WP Travel's, and applying them
     * site-wide would let them reach markup that happens to share a class name.
     *
     * Switchable with the compat module, and with the wta_compat_timeline_css
     * filter, because it corrects another plugin's output and should be easy to
     * turn off if WP Travel changes its template.
     *
     * @return void
     */
    public function enqueue_timeline_css() {
        if (!is_singular(WTA_Trip::post_type())) {
            return;
        }

        if (!apply_filters('wta_compat_timeline_css', true)) {
            return;
        }

        wp_enqueue_style(
            'wta-wt-timeline',
            WTA_URL . 'assets/css/wt-timeline.css',
            array(),
            WTA_VERSION
        );
    }

    /**
     * Give WT Widgets' slick scripts the jQuery dependency they forgot.
     *
     * Touches only the handles in SLICK_HANDLES, only when they exist and only
     * when 'jquery' is missing from their dependencies. WordPress then prints
     * jQuery first and moves the script to wherever jQuery is printed.
     * Switchable with the wta_compat_slick_jquery_fix filter; delete it when
     * the vendor declares the dependency itself.
     *
     * @return void
     */
    public function fix_slick_jquery() {
        if (!apply_filters('wta_compat_slick_jquery_fix', true)) {
            return;
        }

        $scripts = wp_scripts();

        foreach (self::SLICK_HANDLES as $handle) {
            $script = isset($scripts->registered[$handle]) ? $scripts->registered[$handle] : null;

            if (!$script || in_array('jquery', (array) $script->deps, true)) {
                continue;
            }

            $script->deps[] = 'jquery';
        }
    }

    /** Selector for the background videos the fallback watches. */
    const HERO_VIDEO_SELECTOR = 'video.ie-hero-media';

    /**
     * Script that keeps a hero background video from showing a dead play button.
     *
     * Reduced motion and data saver skip the video outright. Otherwise it asks
     * the browser to play and, if that is refused or nothing is playing after
     * 2.5 seconds, removes the video so the image beneath it shows.
     *
     * @return string
     */
    public static function hero_video_js() {
        $selector = wp_json_encode(self::HERO_VIDEO_SELECTOR);

        return '(function(){var vs=document.querySelectorAll(' . $selector . ');if(!vs.length){return;}'
            . 'var m=window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches;'
            . 'var c=navigator.connection||{};var skip=m||c.saveData===true;'
            . 'Array.prototype.forEach.call(vs,function(v){'
            . 'var drop=function(){if(v.parentNode){v.parentNode.removeChild(v);}};'
            . 'if(skip){drop();return;}'
            . 'v.muted=true;v.setAttribute("playsinline","");v.removeAttribute("controls");'
            . 'var p=v.play&&v.play();if(p&&p.catch){p.catch(drop);}'
            . 'setTimeout(function(){if(v.paused||v.readyState<3){drop();}},2500);'
            . '});})();';
    }

    /**
     * Print the hero video fallback on the front end.
     *
     * The CSS hides the native start-playback button on iOS and stops the video
     * from taking taps meant for the hero. Switchable with the
     * wta_compat_hero_video_fallback filter.
     *
     * @return void
     */
    public function hero_video_fallback() {
        if (is_admin() || !apply_filters('wta_compat_hero_video_fallback', true)) {
            return;
        }

        $selector = self::HERO_VIDEO_SELECTOR;

        echo '<style id="wta-hero-video-css">' . $selector . '{pointer-events:none}'
            . $selector . '::-webkit-media-controls,'
            . $selector . '::-webkit-media-controls-start-playback-button,'
            . $selector . '::-webkit-media-controls-overlay-play-button{display:none!important;-webkit-appearance:none}</style>' . "\n";
        echo '<script id="wta-hero-video">' . self::hero_video_js() . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * The from-price of a trip, per person sharing, or 0 when it has none.
     *
     * Reads the same post meta the trip cards show, so the schema and the page
     * agree. When that is empty, falls back to the lowest positive price in
     * WP Travel's pricing tables.
     *
     * @param int $trip_id Trip ID.
     * @return float
     */
    public static function trip_from_price($trip_id) {
        $meta = get_post_meta($trip_id, 'wp_travel_trip_price', true);

        if ('' !== $meta && is_numeric($meta) && (float) $meta > 0) {
            return (float) $meta;
        }

        global $wpdb;

        $pricings  = $wpdb->prefix . 'wt_pricings';
        $relations = $wpdb->prefix . 'wt_price_category_relation';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($relations !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($relations)))
            || $pricings !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($pricings)))) {
            // phpcs:enable
            return 0.0;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(CAST(r.regular_price AS DECIMAL(12,2)))
               FROM {$relations} r
               INNER JOIN {$pricings} p ON p.id = r.pricing_id
              WHERE p.trip_id = %d AND CAST(r.regular_price AS DECIMAL(12,2)) > 0",
            $trip_id
        ));
        // phpcs:enable

        return $found ? (float) $found : 0.0;
    }

    /**
     * Correct WP Travel's TouristTrip schema.
     *
     * - offers: replaced by one Offer carrying the from-price per person, or
     *   removed when the trip has no price. A zero offer reads as "free".
     * - HTML entities in any text (day titles, answers) are decoded, because
     *   JSON-LD is not HTML and "&amp;" would be read literally.
     *
     * Switchable with the wta_compat_trip_schema_fix filter.
     *
     * @param array $schema  TouristTrip structure.
     * @param int   $trip_id Trip ID.
     * @return array
     */
    public function fix_trip_schema($schema, $trip_id = 0) {
        if (!is_array($schema) || !apply_filters('wta_compat_trip_schema_fix', true)) {
            return $schema;
        }

        $schema = self::decode_entities($schema);

        $currency = '';

        if (isset($schema['offers']['priceCurrency']) && is_string($schema['offers']['priceCurrency'])) {
            $currency = $schema['offers']['priceCurrency'];
        }

        unset($schema['offers']);

        $price = self::trip_from_price((int) $trip_id);

        if ($price <= 0) {
            return $schema;
        }

        $currency = '' !== $currency ? $currency : 'USD';

        $schema['offers'] = array(
            '@type'              => 'Offer',
            'price'              => $price,
            'priceCurrency'      => $currency,
            'availability'       => 'https://schema.org/InStock',
            'priceSpecification' => array(
                '@type'             => 'UnitPriceSpecification',
                'price'             => $price,
                'minPrice'          => $price,
                'priceCurrency'     => $currency,
                'referenceQuantity' => array(
                    '@type'    => 'QuantitativeValue',
                    'value'    => 1,
                    'unitText' => 'person sharing',
                ),
            ),
            'seller'             => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo('name'),
                'url'   => home_url('/'),
            ),
        );

        return $schema;
    }

    /**
     * Decode HTML entities in every string of a structure.
     *
     * @param mixed $value Array or scalar.
     * @return mixed
     */
    protected static function decode_entities($value) {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::decode_entities($item);
            }

            return $value;
        }

        if (is_string($value) && false !== strpos($value, '&')) {
            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $value;
    }
}
