<?php
/**
 * Conditional asset loading.
 *
 * WP Travel, WP Travel Pro and a few neighbours enqueue their booking, map and
 * account bundles on every page, so a page with no booking form still pays for
 * React, the booking widget and a ~1 MB Google Maps script. This module takes
 * those assets back off the queue on pages that cannot use them.
 *
 * It only ever dequeues. Nothing is deregistered, so a plugin that enqueues one
 * of these handles on a page we did not anticipate still gets it, and turning
 * the module off restores the previous behaviour exactly.
 *
 * Off by default: it changes what the front end loads, so it is switched on
 * deliberately from the Modules tab once the pages have been checked.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Assets {

    /**
     * Rules for what to take off the queue. A rule matches a queued handle when
     * its handle pattern matches the handle, or any of its src fragments occurs
     * in the registered src. Matching on src as well as handle means the rules
     * do not depend on handle names, which differ between vendor releases.
     *
     * @return array<string, array{handles: string[], src: string[]}>
     */
    public static function rules() {
        return apply_filters('wta_assets_rules', array(
            'booking_widget' => array(
                'handles' => array('/^wp-travel-.*(booking|checkout|cart)/'),
                'src'     => array('frontend-booking-widget'),
            ),
            'google_maps' => array(
                'handles' => array('/^(wp-travel-)?(google-?map|gmap)/'),
                'src'     => array('maps.googleapis.com/maps/api/js', 'maps.google.com/maps/api/js'),
            ),
            'wp_travel_pro' => array(
                'handles' => array('/^wp-travel-pro/'),
                'src'     => array('/wp-travel-pro/'),
            ),
            'sign_in_with_google' => array(
                'handles' => array('/^googlesitekit-.*sign-in-with-google/'),
                'src'     => array('accounts.google.com/gsi/client'),
            ),
        ));
    }

    /**
     * Shortcode and slug fragments that mark a page as using WP Travel's
     * account, checkout or booking screens.
     *
     * @return string[]
     */
    public static function keep_shortcodes() {
        return apply_filters('wta_assets_keep_shortcodes', array(
            'wp_travel_',
            'wp-travel-',
            'wptravel_',
        ));
    }

    /**
     * Request paths that always keep the full asset set.
     *
     * @return string[] Lower-case paths without slashes.
     */
    public static function keep_paths() {
        return apply_filters('wta_assets_keep_paths', array(
            'my-bookings',
            'thank-you',
            'checkout',
            'cart',
            'dashboard',
        ));
    }

    /** @var string[] Handles removed on this request, for diagnostics. */
    private $pruned = array();

    public function __construct() {
        if (is_admin()) {
            return;
        }

        // Very late so we see everything enqueued on wp_enqueue_scripts, then
        // again in the footer for assets a shortcode or widget enqueued while
        // the page body rendered.
        add_action('wp_enqueue_scripts', array($this, 'prune'), PHP_INT_MAX - 10);
        add_action('wp_print_footer_scripts', array($this, 'prune'), 1);
    }

    /**
     * Whether this request is one that needs the booking, map and account
     * assets.
     *
     * Errs towards keeping them: a missed page costs some kilobytes, a wrongly
     * stripped one costs a broken booking form.
     */
    public static function needs_full_assets() {
        // Builders and previews run their own scripts against these handles.
        if (self::is_builder_request()) {
            return true;
        }

        $trip_type = WTA_Trip::post_type();

        if (is_singular($trip_type) || is_post_type_archive($trip_type)) {
            return true;
        }

        $taxonomies = array_keys((array) WTA_Trip::default_taxonomies());
        if ($taxonomies && is_tax($taxonomies)) {
            return true;
        }

        if (is_search() && $trip_type === get_query_var('post_type')) {
            return true;
        }

        foreach (self::wp_travel_page_checks() as $check) {
            if (function_exists($check) && call_user_func($check)) {
                return true;
            }
        }

        if (self::path_is_kept()) {
            return true;
        }

        if (is_singular() && self::content_uses_wp_travel(get_post())) {
            return true;
        }

        return (bool) apply_filters('wta_assets_needed', false);
    }

    /**
     * WP Travel's own page predicates. Probed with function_exists() because
     * they come and go between releases.
     *
     * @return string[]
     */
    protected static function wp_travel_page_checks() {
        return array(
            'wp_travel_is_checkout_page',
            'wp_travel_is_cart_page',
            'wp_travel_is_dashboard_page',
            'wp_travel_is_account_page',
            'wptravel_is_checkout_page',
            'wptravel_is_cart_page',
            'wptravel_is_dashboard_page',
            'wptravel_is_account_page',
        );
    }

    protected static function is_builder_request() {
        if (is_customize_preview() || is_preview()) {
            return true;
        }

        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->preview)) {
            $preview = \Elementor\Plugin::$instance->preview;
            if (is_object($preview) && method_exists($preview, 'is_preview_mode') && $preview->is_preview_mode()) {
                return true;
            }
        }

        return false;
    }

    protected static function path_is_kept() {
        $request = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path    = (string) wp_parse_url($request, PHP_URL_PATH);
        $parts   = array_filter(explode('/', strtolower($path)), 'strlen');

        return (bool) array_intersect($parts, array_map('strtolower', self::keep_paths()));
    }

    /**
     * @param WP_Post|null $post
     */
    protected static function content_uses_wp_travel($post) {
        if (!$post instanceof WP_Post) {
            return false;
        }

        foreach (self::keep_shortcodes() as $needle) {
            if (false !== strpos($post->post_content, $needle)) {
                return true;
            }
        }

        // Elementor keeps its content in meta, not post_content.
        $elementor = get_post_meta($post->ID, '_elementor_data', true);
        if (is_string($elementor) && '' !== $elementor) {
            foreach (self::keep_shortcodes() as $needle) {
                if (false !== strpos($elementor, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Dequeue matching scripts and styles.
     */
    public function prune() {
        if (self::needs_full_assets()) {
            return;
        }

        $rules = self::rules();

        foreach (array(wp_scripts(), wp_styles()) as $deps) {
            $this->prune_queue($deps, $rules);
        }

        if ($this->pruned) {
            do_action('wta_assets_pruned', $this->pruned);
        }
    }

    /**
     * @param WP_Dependencies $deps
     * @param array           $rules
     */
    protected function prune_queue($deps, array $rules) {
        $matched = array();

        foreach ($deps->queue as $handle) {
            if ($this->matches($deps, $handle, $rules)) {
                $matched[] = $handle;
            }
        }

        if (!$matched) {
            return;
        }

        // Dependencies the matches pulled in (React, wp-element, ...) leave with
        // them: WordPress only prints what the queue, or a queued handle's
        // dependency chain, still reaches. A dependency something else
        // enqueued itself is deliberately left alone.
        foreach ($matched as $handle) {
            $this->remove($deps, $handle);
        }
    }

    protected function remove($deps, $handle) {
        if (in_array($handle, $deps->queue, true)) {
            $deps->dequeue($handle);
            $this->pruned[] = $handle;
        }
    }

    /**
     * @param WP_Dependencies $deps
     */
    protected function matches($deps, $handle, array $rules) {
        $src = isset($deps->registered[$handle]) ? (string) $deps->registered[$handle]->src : '';

        foreach ($rules as $rule) {
            foreach ((array) ($rule['handles'] ?? array()) as $pattern) {
                if (1 === @preg_match($pattern, $handle)) {
                    return true;
                }
            }

            if ('' !== $src) {
                foreach ((array) ($rule['src'] ?? array()) as $fragment) {
                    if ('' !== $fragment && false !== strpos($src, $fragment)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
