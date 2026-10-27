<?php
/**
 * Loads the SafariBookings reviews script on the public site.
 *
 * It was loaded through Cloudflare Zaraz as an inline snippet that inserted the
 * script on every page. This does the same from WordPress, so Zaraz can be
 * switched off. It loads asynchronously in the footer and is not printed in
 * wp-admin, feeds or the Elementor editor.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_SafariBookings {

    const HANDLE = 'wta-safaribookings';

    const SRC = 'https://s3.amazonaws.com/z_437er23a/c0f496499.js';

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
    }

    /**
     * The script address. Filterable so a site can point it at its own widget.
     *
     * @return string
     */
    public static function src() {
        $src = apply_filters('wta_safaribookings_src', self::SRC);

        return is_string($src) ? esc_url_raw($src, array('https')) : '';
    }

    /**
     * Whether to load the script on this request.
     *
     * @return bool
     */
    public static function should_load() {
        if (is_admin() || is_feed() || is_customize_preview()) {
            return false;
        }

        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->preview) && \Elementor\Plugin::$instance->preview->is_preview_mode()) {
            return false;
        }

        return '' !== self::src();
    }

    public function enqueue() {
        if (!self::should_load()) {
            return;
        }

        wp_enqueue_script(self::HANDLE, self::src(), array(), null, array('in_footer' => true, 'strategy' => 'async')); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party script, no version.
    }
}
