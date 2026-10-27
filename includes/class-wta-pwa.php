<?php
/**
 * Progressive web app: web app manifest, service worker and offline page.
 *
 * WP Travel ships its own PWA (a manifest inside its plugin folder and a
 * physical /sw.js in the web root). It has three faults this module fixes:
 *
 *  - Its worker caches "/" at install and answers cache-first, so a returning
 *    visitor can be shown a stale homepage indefinitely.
 *  - It has no offline page and no error handling.
 *  - /sw.js is a static file that stays behind when the setting is switched
 *    off, so old browsers keep running it (see WTA_PWA_Legacy).
 *
 * This module serves everything from the plugin, writing nothing to the web
 * root:
 *
 *  - /wta-manifest.webmanifest  the manifest, built from the settings
 *  - /wta-sw.js                 the service worker (assets/js/wta-sw.js)
 *  - /wta-offline               the page shown when the network is down
 *
 * Requests are matched on parse_request, so there are no rewrite rules to
 * flush. On a site without pretty permalinks, or with the endpoint style set
 * to "query", the same three are served at /?wta_pwa=manifest|sw|offline,
 * which always reaches WordPress even when a web server answers a missing
 * static-looking file with its own 404.
 *
 * Off by default. Switching it on while WP Travel's own PWA is still enabled
 * would register two manifests and two workers, so in that case this module
 * prints nothing and registers nothing, and an admin notice says why.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_PWA {

    const OPTION = 'wta_pwa_settings';

    /** Set the first time the service worker is served; see WTA_PWA_Legacy. */
    const SERVED_OPTION = 'wta_pwa_served';

    /** kind => path served on a site with pretty permalinks. */
    const ENDPOINTS = array(
        'manifest' => 'wta-manifest.webmanifest',
        'sw'       => 'wta-sw.js',
        'offline'  => 'wta-offline',
    );

    const DISPLAY_MODES = array('standalone', 'minimal-ui', 'fullscreen', 'browser');

    public function __construct() {
        add_action('parse_request', array($this, 'maybe_serve'), 1);
        add_action('wp_head', array($this, 'print_head_tags'), 2);
        add_action('wp_footer', array($this, 'print_registration'), 20);
        add_action('admin_notices', array($this, 'conflict_notice'));
    }

    /* ------------------------------------------------------------ settings */

    /**
     * Defaults, taken from the site itself. Filterable so a theme or mu-plugin
     * can set a brand once instead of every site saving it by hand.
     *
     * @return array<string, mixed>
     */
    public static function defaults() {
        $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $name = '' !== $name ? $name : 'Site';

        return apply_filters('wta_pwa_defaults', array(
            'name'             => $name,
            'short_name'       => self::truncate($name, 12),
            'description'      => wp_specialchars_decode((string) get_bloginfo('description'), ENT_QUOTES),
            'theme_color'      => '#1A1917',
            'background_color' => '#FFFFFF',
            'start_url'        => '/',
            'display'          => 'standalone',
            'endpoint_style'   => 'auto',
            'icon_192'         => 0,
            'icon_512'         => 0,
            'icon_maskable'    => 0,
            'offline_title'    => "You're offline",
            'offline_message'  => "We can't reach the network right now. Check your connection and try again. Pages you open will load as soon as you're back online.",
            'offline_image'    => 0,
            'cache_version'    => 1,
        ));
    }

    /**
     * Saved settings over the defaults. A blank saved text value falls back
     * to the default, so clearing a field restores it rather than shipping an
     * empty manifest name.
     *
     * @return array<string, mixed>
     */
    public static function settings() {
        $defaults = self::defaults();
        $stored   = get_option(self::OPTION, array());
        $stored   = is_array($stored) ? $stored : array();
        $settings = $defaults;

        foreach ($defaults as $key => $default) {
            if (isset($stored[$key]) && '' !== $stored[$key]) {
                $settings[$key] = $stored[$key];
            }
        }

        return apply_filters('wta_pwa_settings', $settings);
    }

    /**
     * Clean a settings array, from the admin form or anywhere else.
     *
     * @param array $input Raw values.
     * @return array<string, mixed>
     */
    public static function sanitize($input) {
        $input    = is_array($input) ? $input : array();
        $input    = array_filter($input, 'is_scalar');
        $defaults = self::defaults();
        $out      = array();

        $text = function ($key, $max) use ($input, $defaults) {
            $value = isset($input[$key]) ? sanitize_text_field(wp_unslash((string) $input[$key])) : '';

            return '' === $value ? $defaults[$key] : self::truncate($value, $max);
        };

        $out['name']            = $text('name', 45);
        $out['short_name']      = $text('short_name', 12);
        $out['description']     = $text('description', 300);
        $out['offline_title']   = $text('offline_title', 80);

        $message = isset($input['offline_message']) ? sanitize_textarea_field(wp_unslash((string) $input['offline_message'])) : '';
        $out['offline_message'] = '' === $message ? $defaults['offline_message'] : self::truncate($message, 400);

        foreach (array('theme_color', 'background_color') as $key) {
            $color      = isset($input[$key]) ? sanitize_hex_color(trim((string) $input[$key])) : null;
            $out[$key]  = $color ? strtoupper($color) : $defaults[$key];
        }

        $display        = isset($input['display']) ? sanitize_key($input['display']) : '';
        $out['display'] = in_array($display, self::DISPLAY_MODES, true) ? $display : $defaults['display'];

        $style                 = isset($input['endpoint_style']) ? sanitize_key($input['endpoint_style']) : '';
        $out['endpoint_style'] = in_array($style, array('auto', 'pretty', 'query'), true) ? $style : 'auto';

        $out['start_url'] = self::sanitize_start_url(isset($input['start_url']) && is_scalar($input['start_url']) ? wp_unslash((string) $input['start_url']) : '');

        foreach (array('icon_192', 'icon_512', 'icon_maskable', 'offline_image') as $key) {
            $id         = isset($input[$key]) ? absint($input[$key]) : 0;
            $out[$key]  = ($id && wp_attachment_is_image($id)) ? $id : 0;
        }

        $version               = isset($input['cache_version']) ? absint($input['cache_version']) : 1;
        $out['cache_version']  = max(1, min(99999, $version));

        return $out;
    }

    /**
     * A start URL is a path on this site. Anything else (another host, a
     * protocol-relative URL, a script URL) is replaced with "/".
     */
    public static function sanitize_start_url($value) {
        $value = trim($value);

        if ('' === $value) {
            return '/';
        }

        $home = wp_parse_url(home_url('/'));

        if (preg_match('#^https?://#i', $value)) {
            $parts = wp_parse_url($value);

            if (empty($parts['host']) || empty($home['host']) || strtolower($parts['host']) !== strtolower($home['host'])) {
                return '/';
            }

            $path  = isset($parts['path']) ? $parts['path'] : '/';
            $value = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');

            // Make the path relative to the WordPress home, not the host.
            $base = isset($home['path']) ? rtrim($home['path'], '/') : '';

            if ('' !== $base && 0 === strpos($value, $base)) {
                $value = substr($value, strlen($base));
            }
        }

        if ('' === $value || '/' !== $value[0] || 0 === strpos($value, '//') || preg_match('#[\s\\\\]#', $value)) {
            return '/';
        }

        return esc_url_raw($value) ?: '/';
    }

    private static function truncate($text, $max) {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /* --------------------------------------------------------------- state */

    /**
     * Whether WP Travel's own PWA is switched on. Two manifests and two
     * workers at the same scope fight over the page, so this module steps
     * aside while it is.
     */
    public static function wp_travel_pwa_active() {
        $settings = get_option('wp_travel_settings', array());
        $active   = is_array($settings) && isset($settings['enable_pwa']) && 'yes' === $settings['enable_pwa'];

        return (bool) apply_filters('wta_pwa_wp_travel_active', $active);
    }

    /** Whether this request should carry the manifest and register the worker. */
    public static function should_output() {
        return !is_admin() && !self::wp_travel_pwa_active();
    }

    /* ----------------------------------------------------------- endpoints */

    /**
     * Public URL of an endpoint.
     *
     * @param string $kind manifest, sw or offline.
     */
    public static function endpoint_url($kind) {
        if (!isset(self::ENDPOINTS[$kind])) {
            return '';
        }

        $style  = self::settings()['endpoint_style'];
        $pretty = 'pretty' === $style || ('auto' === $style && '' !== (string) get_option('permalink_structure'));

        if ($pretty) {
            return home_url('/' . self::ENDPOINTS[$kind]);
        }

        return add_query_arg('wta_pwa', $kind, home_url('/'));
    }

    /**
     * Which endpoint a request is for, or ''.
     *
     * @param WP $wp The WordPress environment.
     */
    public static function request_kind($wp) {
        // Read-only routing; no state is changed from this value.
        if (isset($_GET['wta_pwa'])) {
            $kind = sanitize_key(wp_unslash($_GET['wta_pwa']));

            return isset(self::ENDPOINTS[$kind]) ? $kind : '';
        }

        $path = isset($wp->request) ? trim((string) $wp->request, '/') : '';

        return (string) array_search($path, self::ENDPOINTS, true);
    }

    public function maybe_serve($wp) {
        $kind = self::request_kind($wp);

        if ('' === $kind) {
            return;
        }

        if ('sw' === $kind && !get_option(self::SERVED_OPTION)) {
            update_option(self::SERVED_OPTION, 1, false);
        }

        self::send(self::response($kind));
    }

    /**
     * Build an endpoint's response without sending it.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function response($kind) {
        switch ($kind) {
            case 'manifest':
                $body = wp_json_encode(self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                return array(
                    'status'  => 200,
                    'headers' => array(
                        'Content-Type'           => 'application/manifest+json; charset=utf-8',
                        // Short enough that a changed name or icon shows up the
                        // same day; the ETag makes the revalidation cheap.
                        'Cache-Control'          => 'public, max-age=3600',
                        'ETag'                   => '"' . md5($body) . '"',
                        'X-Content-Type-Options' => 'nosniff',
                    ),
                    'body'    => (string) $body,
                );

            case 'sw':
                $scope = self::scope_path();

                return array(
                    'status'  => 200,
                    'headers' => array(
                        'Content-Type'           => 'application/javascript; charset=utf-8',
                        // A stale worker script is the original bug. The browser
                        // must revalidate it on every update check.
                        'Cache-Control'          => 'no-cache, no-store, must-revalidate, max-age=0',
                        'Service-Worker-Allowed' => $scope,
                        'X-Content-Type-Options' => 'nosniff',
                    ),
                    'body'    => self::service_worker_js(),
                );

            case 'offline':
                return array(
                    'status'  => 200,
                    'headers' => array(
                        'Content-Type'           => 'text/html; charset=utf-8',
                        'Cache-Control'          => 'no-cache, max-age=0',
                        'X-Robots-Tag'           => 'noindex',
                        'X-Content-Type-Options' => 'nosniff',
                    ),
                    'body'    => self::offline_html(),
                );
        }

        return array('status' => 404, 'headers' => array(), 'body' => '');
    }

    /**
     * Send a response and stop. Honours If-None-Match for the manifest.
     *
     * @param array $response From self::response().
     */
    public static function send($response) {
        $status = $response['status'];

        if (isset($response['headers']['ETag']) && isset($_SERVER['HTTP_IF_NONE_MATCH'])
            && trim(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'])) === $response['headers']['ETag']) {
            $status = 304;
        }

        status_header($status);

        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }

        if (304 !== $status && !(isset($_SERVER['REQUEST_METHOD']) && 'HEAD' === $_SERVER['REQUEST_METHOD'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput -- JSON, JavaScript or a page escaped as it was built.
            echo $response['body'];
        }

        exit;
    }

    /** Path of the WordPress home, with slashes, for scope: "/" or "/sub/". */
    public static function scope_path() {
        $path = wp_parse_url(home_url('/'), PHP_URL_PATH);

        return is_string($path) && '' !== $path ? trailingslashit($path) : '/';
    }

    /* ------------------------------------------------------------ manifest */

    /** @return array<string, mixed> */
    public static function manifest() {
        $s     = self::settings();
        $scope = home_url('/');
        $icons = array();

        foreach (self::icon_report() as $icon) {
            if ($icon['ok']) {
                $icons[] = array(
                    'src'     => $icon['url'],
                    'sizes'   => $icon['sizes'],
                    'type'    => $icon['type'],
                    'purpose' => $icon['purpose'],
                );
            }
        }

        $manifest = array(
            'id'               => $scope,
            'name'             => $s['name'],
            'short_name'       => $s['short_name'],
            'description'      => $s['description'],
            'start_url'        => home_url($s['start_url']),
            'scope'            => $scope,
            'display'          => $s['display'],
            'theme_color'      => $s['theme_color'],
            'background_color' => $s['background_color'],
            'lang'             => str_replace('_', '-', get_bloginfo('language')),
            'categories'       => apply_filters('wta_pwa_categories', array('travel')),
            'icons'            => $icons,
        );

        if ('' === $manifest['description']) {
            unset($manifest['description']);
        }

        return apply_filters('wta_pwa_manifest', $manifest);
    }

    /* --------------------------------------------------------------- icons */

    /**
     * What each icon slot resolves to, and whether it is usable.
     *
     * An explicit media-library choice wins. With none, the 192 and 512 slots
     * fall back to the WordPress site icon, which WordPress itself crops to
     * both sizes. The maskable slot never falls back: a maskable icon needs
     * its artwork inside the central safe zone, which only a person can judge.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function icon_report() {
        $s     = self::settings();
        $slots = array(
            'icon_192'      => array('label' => '192 px icon', 'min' => 192, 'purpose' => 'any', 'required' => true),
            'icon_512'      => array('label' => '512 px icon', 'min' => 512, 'purpose' => 'any', 'required' => true),
            'icon_maskable' => array('label' => 'Maskable 512 px icon', 'min' => 512, 'purpose' => 'maskable', 'required' => false),
        );
        $report = array();

        foreach ($slots as $slot => $meta) {
            $entry = array(
                'slot'     => $slot,
                'label'    => $meta['label'],
                'required' => $meta['required'],
                'purpose'  => $meta['purpose'],
                'source'   => 'none',
                'url'      => '',
                'sizes'    => '',
                'type'     => '',
                'ok'       => false,
                'problems' => array(),
            );

            $id = (int) $s[$slot];

            if ($id) {
                $entry = self::check_attachment($entry, $id, $meta['min']);
            } elseif ('any' === $meta['purpose'] && function_exists('has_site_icon') && has_site_icon()) {
                $url = get_site_icon_url($meta['min']);

                if ($url) {
                    $entry['source'] = 'site_icon';
                    $entry['url']    = $url;
                    $entry['sizes']  = $meta['min'] . 'x' . $meta['min'];
                    $entry['type']   = self::mime_for_url($url);
                    $entry['ok']     = true;
                }
            }

            if ('none' === $entry['source']) {
                $entry['problems'][] = $meta['required']
                    ? 'No icon set. Browsers will not offer to install the app without a ' . $meta['min'] . ' px icon.'
                    : 'No maskable icon set. Android will shrink the regular icon inside a white circle instead.';
            }

            $report[] = $entry;
        }

        return $report;
    }

    private static function check_attachment($entry, $id, $min) {
        $entry['source'] = 'attachment';
        $url             = wp_get_attachment_url($id);

        if (!$url || !wp_attachment_is_image($id)) {
            $entry['source']     = 'none';
            $entry['problems'][] = 'The chosen file is missing or is not an image.';

            return $entry;
        }

        $meta   = wp_get_attachment_metadata($id);
        $width  = is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : 0;
        $height = is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : 0;
        $mime   = (string) get_post_mime_type($id);

        $entry['url']   = $url;
        $entry['sizes'] = $width && $height ? $width . 'x' . $height : '';
        $entry['type']  = $mime;

        if (!$width || !$height) {
            $entry['problems'][] = 'The image dimensions are unknown. Re-upload the file.';
        } elseif ($width !== $height) {
            $entry['problems'][] = sprintf('The image is %dx%d. An icon must be square.', $width, $height);
        } elseif ($width < $min) {
            $entry['problems'][] = sprintf('The image is %dx%d. It must be at least %d px.', $width, $height, $min);
        }

        if (!in_array($mime, array('image/png', 'image/webp', 'image/jpeg'), true)) {
            $entry['problems'][] = 'Use a PNG (or WebP) file. ' . $mime . ' icons are not reliably accepted.';
        }

        $entry['ok'] = empty($entry['problems']);

        return $entry;
    }

    private static function mime_for_url($url) {
        $type = wp_check_filetype(strtok($url, '?'));

        return $type['type'] ? $type['type'] : 'image/png';
    }

    /* -------------------------------------------------------- service worker */

    /**
     * Path fragments (lower case) that the worker never touches. Matched
     * against the URL path, so "/wp-admin" also covers admin-ajax.php.
     *
     * @return string[]
     */
    public static function exclude_paths() {
        $paths = apply_filters('wta_pwa_exclude_paths', array(
            '/wp-admin',
            '/wp-login.php',
            '/wp-json',
            '/admin-ajax.php',
            '/wp-cron.php',
            '/xmlrpc.php',
            '/wp-comments-post.php',
            '/wp-signup.php',
            '/wp-activate.php',
            '/feed',
            '/wta-sw.js',
            '/wta-manifest.webmanifest',
            'enquiry',
            'enquire',
            'checkout',
            'cart',
            'basket',
            'my-account',
            'my-bookings',
            'booking',
            'thank-you',
            'dashboard',
        ));

        return array_values(array_unique(array_map('strtolower', array_filter((array) $paths, 'is_string'))));
    }

    /** @return array<string, array{maxEntries: int, maxBytes: int, maxAgeSeconds: int}> */
    public static function cache_limits() {
        return apply_filters('wta_pwa_cache_limits', array(
            'images' => array('maxEntries' => 60, 'maxBytes' => 1500000, 'maxAgeSeconds' => 30 * DAY_IN_SECONDS),
            'fonts'  => array('maxEntries' => 20, 'maxBytes' => 500000, 'maxAgeSeconds' => 90 * DAY_IN_SECONDS),
            'assets' => array('maxEntries' => 80, 'maxBytes' => 1000000, 'maxAgeSeconds' => 7 * DAY_IN_SECONDS),
        ));
    }

    /**
     * Identifies this build of the worker. It changes with the plugin
     * version, the cache version setting and the offline page content, so any
     * of them invalidates every cache a visitor holds.
     */
    public static function build_id() {
        $s    = self::settings();
        $hash = substr(md5(wp_json_encode(array($s['offline_title'], $s['offline_message'], (int) $s['offline_image'], $s['theme_color'], $s['background_color']))), 0, 6);
        $id   = (defined('WTA_VERSION') ? WTA_VERSION : '0') . '-v' . (int) $s['cache_version'] . '-' . $hash;

        return preg_replace('/[^A-Za-z0-9._-]/', '', $id);
    }

    /** @return array<string, mixed> */
    public static function worker_config() {
        $s       = self::settings();
        $precache = array();

        if (!empty($s['offline_image'])) {
            $image = wp_get_attachment_url((int) $s['offline_image']);

            if ($image) {
                $precache[] = $image;
            }
        }

        return array(
            'build'          => self::build_id(),
            'scope'          => self::scope_path(),
            'offlineUrl'     => self::endpoint_url('offline'),
            'offlineTitle'   => $s['offline_title'],
            'offlineMessage' => $s['offline_message'],
            'precache'       => array_values(array_filter((array) apply_filters('wta_pwa_precache', $precache), 'is_string')),
            'exclude'        => self::exclude_paths(),
            'fontHosts'      => array_values((array) apply_filters('wta_pwa_font_hosts', array('fonts.gstatic.com'))),
            'limits'         => self::cache_limits(),
        );
    }

    /** The worker script with this site's configuration filled in. */
    public static function service_worker_js() {
        $file   = (defined('WTA_DIR') ? WTA_DIR : plugin_dir_path(dirname(__FILE__))) . 'assets/js/wta-sw.js';
        $script = is_readable($file) ? file_get_contents($file) : false;
        $marker = '/*WTA_CONFIG*/ {}';

        if (false === $script || false === strpos($script, $marker)) {
            // Never serve a broken worker: this one does nothing and the site
            // behaves as if there were no PWA.
            return "self.addEventListener('install', function () { self.skipWaiting(); });\n";
        }

        $config = wp_json_encode(self::worker_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return str_replace($marker, $config, $script);
    }

    /* ---------------------------------------------------------- offline page */

    public static function offline_html() {
        $s        = self::settings();
        $bg       = $s['background_color'];
        $fg       = self::readable_on($bg);
        $accent   = $s['theme_color'];
        $button   = self::readable_on($accent);
        $image    = !empty($s['offline_image']) ? wp_get_attachment_url((int) $s['offline_image']) : '';
        $language = get_bloginfo('language');

        ob_start();
        ?>
<!doctype html>
<html lang="<?php echo esc_attr($language); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?php echo esc_attr($accent); ?>">
<title><?php echo esc_html($s['offline_title'] . ' - ' . $s['name']); ?></title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:<?php echo esc_html($bg); ?>;color:<?php echo esc_html($fg); ?>;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;line-height:1.5}
main{max-width:30rem;padding:2rem 1.5rem;text-align:center}
img{max-width:100%;height:auto;border-radius:12px;margin-bottom:1.5rem}
h1{font-size:1.75rem;margin:0 0 .75rem}
p{margin:0 0 1.5rem}
button{font:inherit;font-weight:600;padding:.75rem 1.75rem;border:0;border-radius:999px;background:<?php echo esc_html($accent); ?>;color:<?php echo esc_html($button); ?>;cursor:pointer}
button:focus-visible{outline:3px solid <?php echo esc_html($fg); ?>;outline-offset:2px}
</style>
</head>
<body>
<main>
<?php if ($image) : ?>
<img src="<?php echo esc_url($image); ?>" alt="">
<?php endif; ?>
<h1><?php echo esc_html($s['offline_title']); ?></h1>
<p><?php echo esc_html($s['offline_message']); ?></p>
<button type="button" id="retry">Try again</button>
</main>
<script>
document.getElementById('retry').addEventListener('click', function () { location.reload(); });
window.addEventListener('online', function () { location.reload(); });
</script>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    /** Black or white, whichever reads better on the given hex colour. */
    public static function readable_on($hex) {
        $hex = ltrim((string) $hex, '#');

        if (3 === strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (6 !== strlen($hex) || !ctype_xdigit($hex)) {
            return '#1A1917';
        }

        $luminance = (0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2))) / 255;

        return $luminance > 0.6 ? '#1A1917' : '#FFFFFF';
    }

    /* ------------------------------------------------------------ front end */

    public function print_head_tags() {
        if (!self::should_output() || is_feed() || is_customize_preview()) {
            return;
        }

        $s = self::settings();

        echo '<link rel="manifest" href="' . esc_url(self::endpoint_url('manifest')) . '">' . "\n";
        echo '<meta name="theme-color" content="' . esc_attr($s['theme_color']) . '">' . "\n";

        // WordPress prints its own apple-touch-icon whenever a site icon is
        // set, so a second one would only compete with it.
        if (!has_site_icon()) {
            $apple = '';

            foreach (self::icon_report() as $icon) {
                if ($icon['ok'] && 'any' === $icon['purpose'] && ('' === $apple || 'icon_192' === $icon['slot'])) {
                    $apple = $icon['url'];
                }
            }

            if ('' !== $apple) {
                echo '<link rel="apple-touch-icon" href="' . esc_url($apple) . '">' . "\n";
            }
        }

        echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr($s['short_name']) . '">' . "\n";
    }

    /**
     * The registration script. Printed only when enabled, never in wp-admin,
     * and not for signed-in users: their pages are personal and they have no
     * use for an offline shell.
     */
    public function print_registration() {
        if (!self::should_output() || is_feed() || is_customize_preview()) {
            return;
        }

        if (is_user_logged_in() && !apply_filters('wta_pwa_register_logged_in', false)) {
            return;
        }

        $script = sprintf(
            "if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register(%s, { scope: %s }).catch(function () {}); }); }",
            wp_json_encode(self::endpoint_url('sw'), JSON_UNESCAPED_SLASHES),
            wp_json_encode(self::scope_path(), JSON_UNESCAPED_SLASHES)
        );

        wp_print_inline_script_tag($script, array('id' => 'wta-pwa-register'));
    }

    /* ----------------------------------------------------------- admin notice */

    public function conflict_notice() {
        if (!current_user_can('manage_options') || !self::wp_travel_pwa_active()) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>Nyuchi Travel Addons PWA is paused.</strong> '
            . 'WP Travel\'s own PWA is still switched on (WP Travel settings, PWA), so this site would have two manifests and two service workers. '
            . 'Switch WP Travel\'s PWA off to let the Travel Addons PWA take over; until then it prints no manifest and registers no worker.</p></div>';
    }

    /* ---------------------------------------------------------------- status */

    /**
     * Everything about the PWA setup in one array, for the admin screen and
     * the pwa-status ability.
     *
     * @return array<string, mixed>
     */
    public static function status() {
        $enabled = class_exists('WP_Travel_Addons') ? WP_Travel_Addons::module_enabled('pwa') : false;
        $wt      = self::wp_travel_pwa_active();
        $icons   = self::icon_report();
        $icons_ok = true;

        foreach ($icons as $icon) {
            if ($icon['required'] && !$icon['ok']) {
                $icons_ok = false;
            }
        }

        $warnings = array();

        if ($enabled && $wt) {
            $warnings[] = 'WP Travel\'s own PWA is still on, so this module prints no manifest and registers no service worker.';
        }

        if ($enabled && !$icons_ok) {
            $warnings[] = 'A required icon is missing or invalid, so browsers will not offer to install the app.';
        }

        $legacy = WTA_PWA_Legacy::status();

        if (in_array($legacy['state'], array('not_writable', 'error'), true)) {
            $warnings[] = 'The old WP Travel /sw.js is still in place and could not be replaced.';
        }

        return array(
            'module_enabled'        => $enabled,
            'active'                => $enabled && !$wt,
            'wp_travel_pwa_enabled' => $wt,
            'manifest_url'          => self::endpoint_url('manifest'),
            'service_worker_url'    => self::endpoint_url('sw'),
            'offline_url'           => self::endpoint_url('offline'),
            'cache_build'           => self::build_id(),
            'icons_ok'              => $icons_ok,
            'icons'                 => $icons,
            'legacy_service_worker' => $legacy,
            'warnings'              => $warnings,
        );
    }
}
