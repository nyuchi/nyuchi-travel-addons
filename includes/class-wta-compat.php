<?php
/**
 * Compatibility guards for defects in WP Travel companion plugins.
 *
 * This is a stopgap. Every guard here exists because a third-party plugin ships
 * a bug we cannot patch in place — editing the vendor's file would be undone by
 * the next update. Each guard should be deleted the moment the vendor ships a
 * fix; none of them is intended as a permanent part of this plugin.
 *
 * Current guards:
 *
 * - WT Widgets for Elementor 1.4.7, inc/widgets/trip-outline-widget.php:1741.
 *   The itinerary day description is passed to printf() as the format string
 *   instead of as an argument, so every '%' an editor types is parsed as a
 *   conversion specifier. On PHP 8.3 that is a fatal error, not a warning.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Compat {

    /** Gate for the trip-outline printf guard. */
    const OPTION_PRINTF_GUARD = 'wta_compat_wt_widgets_printf';

    /**
     * Filter the buggy printf() call routes its format string through.
     *
     * The misspelling of "itineraries" is the vendor's, and is reproduced here
     * verbatim — correcting it would silently detach the guard.
     */
    const PRINTF_FILTER = 'wp_travel_ititneraries_trip_outline_title_tab';

    /**
     * The genuine title call on line 1726 passes this literal through the same
     * filter as the description. It must survive untouched or the title loses
     * its placeholder and renders empty.
     */
    const TITLE_PLACEHOLDER = '%s';

    /** Upper bound on a single scan, so a large catalogue cannot time out. */
    const SCAN_LIMIT = 500;

    /** Conversion characters PHP's printf family accepts. */
    const CONVERSIONS = 'bcdeEfFgGhHosuxX';

    public function __construct() {
        if (!self::guard_enabled()) {
            return;
        }

        // Priority 9: ahead of the default 10, so a theme or site filter hooked
        // at 10 sees the already-escaped string and cannot reintroduce a raw '%'
        // after we have run.
        add_filter(self::PRINTF_FILTER, array($this, 'escape_printf_format'), 9, 1);

        // WP Travel's own itinerary timeline. A trip rendered without an
        // Elementor template still has to look like the same website.
        add_action('wp_enqueue_scripts', array($this, 'enqueue_timeline_css'));
    }

    public static function guard_enabled() {
        return (bool) get_option(self::OPTION_PRINTF_GUARD, 1);
    }

    /**
     * Neutralise percent signs so printf() prints them instead of consuming
     * them as specifiers.
     *
     * Doubling is safe for content: printf collapses '%%' back to a single '%'.
     * It is not safe for the title placeholder, which is the one value passing
     * through this filter that is genuinely meant to be a format string.
     *
     * @param mixed $text
     * @return mixed
     */
    public function escape_printf_format($text) {
        if (!is_string($text) || self::TITLE_PLACEHOLDER === $text) {
            return $text;
        }

        return str_replace('%', '%%', $text);
    }

    /**
     * Trips whose itinerary descriptions would break the vendor widget.
     *
     * Used to tell an editor which pages are already broken, and which are
     * quietly rendering mangled text at HTTP 200 where nothing is logged.
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
     * Switchable with the compatibility guards, because it is the same kind of
     * thing - correcting another plugin's output - and should be as easy to
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
}
