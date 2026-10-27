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
}
