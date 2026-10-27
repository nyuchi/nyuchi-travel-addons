<?php
/**
 * Cookie consent banner and Google Consent Mode v2 signalling.
 *
 * The banner is the Mzizi cookie-consent design (role=dialog, a fixed bottom
 * bar, Accept / Decline / Manage, rounded-full buttons) ported to dependency
 * free JavaScript and CSS, with the Iconic brand tokens. Mzizi's component is
 * presentational only: the storage, the categories and the signalling here are
 * this module's own.
 *
 * Everything that depends on the visitor happens in the browser, so a full
 * page cache (Varnish, Cloudflare) serves one identical page to everybody:
 *
 *  - wp_head, priority 1: Consent Mode defaults, all denied but the
 *    functionality and security storage, before any tag can read them. It also
 *    applies a stored, current choice at once so a returning visitor's tags do
 *    not start denied.
 *  - wp_footer: the settings as a small inline JSON document. The script
 *    (assets/js/wta-consent.js, deferred) and the stylesheet
 *    (assets/css/wta-consent.css) do the rest.
 *
 * Nothing is printed in wp-admin, feeds, the Customizer or the Elementor
 * editor and preview. The module is off until it is switched on.
 *
 * It does not inject Google Tag Manager. GTM is already loaded by Site Kit or
 * Elementor and has to read Consent Mode (see the README).
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Consent {

    const OPTION = 'wta_consent_settings';

    /** Name of the first-party cookie (and the localStorage mirror key). */
    const COOKIE = 'wta_consent';

    /** Categories a visitor can switch. "Necessary" is always on. */
    const CATEGORIES = array('analytics', 'marketing');

    const POSITIONS = array('bottom', 'bottom-left', 'bottom-right');

    /** Transient holding the last live-page scan for other consent tools. */
    const SCAN_TRANSIENT = 'wta_consent_scan';

    public function __construct() {
        add_action('wp_head', array($this, 'print_defaults'), 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_footer', array($this, 'print_config'), 5);
        add_filter('script_loader_tag', array($this, 'filter_script_tag'), 10, 3);
        add_shortcode('wta_cookie_settings', array(__CLASS__, 'shortcode'));
    }

    /* ------------------------------------------------------------ settings */

    /**
     * Defaults. The wording is neutral and makes no legal promise.
     * Filterable so a theme or mu-plugin can set it once for every site.
     *
     * @return array<string, mixed>
     */
    public static function defaults() {
        return apply_filters('wta_consent_defaults', array(
            'text'                => 'We use cookies to keep this site working and, only with your permission, to understand how it is used and to measure our marketing. Decline keeps only the necessary cookies.',
            'privacy_url'         => '/privacy-policy/',
            'privacy_label'       => 'Privacy policy',
            'position'            => 'bottom',
            'lifetime_days'       => 180,
            'policy_version'      => '1',
            'necessary_desc'      => 'These keep the site working, for example remembering this choice. They cannot be switched off.',
            'analytics_on'        => 1,
            'analytics_desc'      => 'Help us understand how the site is used, such as which pages are visited, so we can improve it.',
            'marketing_on'        => 1,
            'marketing_desc'      => 'Let us and our partners measure our advertising and show relevant ads on other sites.',
            'regions_analytics'   => '',
            'regions_marketing'   => '',
            'meta_pixel_id'       => '',
            'log_enabled'         => 0,
            'log_respect_dnt'     => 1,
        ));
    }

    /**
     * Saved settings over the defaults. A blank saved text value falls back to
     * the default.
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

        return apply_filters('wta_consent_settings', $settings);
    }

    /**
     * Clean a settings array, from the admin form or anywhere else. A missing
     * key keeps its default, so a partial array is safe.
     *
     * @param array $input Raw (slashed) values.
     * @return array<string, mixed>
     */
    public static function sanitize($input) {
        $input    = is_array($input) ? $input : array();
        $input    = array_filter($input, 'is_scalar');
        $defaults = self::defaults();
        $out      = array();

        $text = function ($key, $max, $multiline = false) use ($input, $defaults) {
            $raw   = isset($input[$key]) ? wp_unslash((string) $input[$key]) : '';
            $value = $multiline ? sanitize_textarea_field($raw) : sanitize_text_field($raw);

            return '' === $value ? $defaults[$key] : self::truncate($value, $max);
        };

        $flag = function ($key) use ($input, $defaults) {
            if (!isset($input[$key])) {
                return (int) $defaults[$key];
            }

            return filter_var($input[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        };

        $out['text']           = $text('text', 600, true);
        $out['privacy_label']  = $text('privacy_label', 40);
        $out['necessary_desc'] = $text('necessary_desc', 300, true);
        $out['analytics_desc'] = $text('analytics_desc', 300, true);
        $out['marketing_desc'] = $text('marketing_desc', 300, true);

        $out['privacy_url'] = self::sanitize_privacy_url(isset($input['privacy_url']) ? wp_unslash((string) $input['privacy_url']) : '');

        $position        = isset($input['position']) ? sanitize_key($input['position']) : '';
        $out['position'] = in_array($position, self::POSITIONS, true) ? $position : $defaults['position'];

        $days                 = isset($input['lifetime_days']) ? absint($input['lifetime_days']) : 0;
        $out['lifetime_days'] = $days > 0 ? min(395, $days) : (int) $defaults['lifetime_days'];

        $version               = isset($input['policy_version']) ? preg_replace('/[^A-Za-z0-9._-]/', '', wp_unslash((string) $input['policy_version'])) : '';
        $out['policy_version'] = '' === $version ? (string) $defaults['policy_version'] : substr($version, 0, 20);

        foreach (array('analytics_on', 'marketing_on', 'log_enabled', 'log_respect_dnt') as $key) {
            $out[$key] = $flag($key);
        }

        $out['regions_analytics'] = self::sanitize_regions(isset($input['regions_analytics']) ? wp_unslash((string) $input['regions_analytics']) : '');
        $out['regions_marketing'] = self::sanitize_regions(isset($input['regions_marketing']) ? wp_unslash((string) $input['regions_marketing']) : '');

        $pixel                = isset($input['meta_pixel_id']) ? preg_replace('/\D/', '', (string) $input['meta_pixel_id']) : '';
        $out['meta_pixel_id'] = (strlen($pixel) >= 5 && strlen($pixel) <= 20) ? $pixel : '';

        return $out;
    }

    /**
     * The privacy policy link: a path on this site or an http(s) URL.
     * Anything else (a script URL, a protocol-relative URL) becomes the default.
     */
    public static function sanitize_privacy_url($value) {
        $value   = trim((string) $value);
        $default = '/privacy-policy/';

        if ('' === $value) {
            return $default;
        }

        if ('/' === $value[0] && 0 !== strpos($value, '//') && !preg_match('#[\s\\\\]#', $value)) {
            return esc_url_raw($value) ?: $default;
        }

        if (preg_match('#^https?://#i', $value)) {
            return esc_url_raw($value, array('http', 'https')) ?: $default;
        }

        return $default;
    }

    /**
     * ISO 3166 country or subdivision codes, as Google's "region" parameter
     * takes them (ES, GB, US-CA). Invalid entries are dropped.
     *
     * @param string|array $value Comma or space separated.
     * @return string Normalised, ", " separated.
     */
    public static function sanitize_regions($value) {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);
        $codes = array();

        foreach ($parts as $part) {
            $code = strtoupper(trim((string) $part));

            if (preg_match('/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $code)) {
                $codes[$code] = $code;
            }
        }

        return implode(', ', array_values($codes));
    }

    /** @return string[] */
    public static function regions($key) {
        $settings = self::settings();

        $clean = isset($settings[$key]) ? self::sanitize_regions((string) $settings[$key]) : '';

        return '' !== $clean ? explode(', ', $clean) : array();
    }

    private static function truncate($text, $max) {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /** The policy page link as an absolute URL. */
    public static function privacy_url() {
        $url = self::settings()['privacy_url'];

        return (is_string($url) && '' !== $url && '/' === $url[0]) ? home_url($url) : (string) $url;
    }

    /* --------------------------------------------------------------- gating */

    /**
     * Whether this request may carry the banner: a public front-end page that
     * is not an editor, a preview or a feed.
     */
    public static function should_output() {
        $output = true;

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_customize_preview() || (defined('REST_REQUEST') && REST_REQUEST)) {
            $output = false;
        } elseif (self::is_elementor_editing()) {
            $output = false;
        } elseif (is_robots() || is_trackback()) {
            $output = false;
        }

        return (bool) apply_filters('wta_consent_should_output', $output);
    }

    /** Elementor's editor and its preview iframe. */
    public static function is_elementor_editing() {
        // Read-only routing flags set by Elementor itself.
        if (isset($_GET['elementor-preview']) || (isset($_GET['action']) && 'elementor' === sanitize_key(wp_unslash($_GET['action'])))) {
            return true;
        }

        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance)) {
            $plugin = \Elementor\Plugin::$instance;

            if (isset($plugin->editor) && is_object($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) {
                return true;
            }

            if (isset($plugin->preview) && is_object($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode()) {
                return true;
            }
        }

        return false;
    }

    /* ----------------------------------------------------------------- head */

    /**
     * The Consent Mode v2 JavaScript. Denied until the visitor chooses, with
     * functionality and security storage granted.
     *
     * @return string
     */
    public static function defaults_js() {
        $base = array(
            'ad_storage'            => 'denied',
            'ad_user_data'          => 'denied',
            'ad_personalization'    => 'denied',
            'analytics_storage'     => 'denied',
            'functionality_storage' => 'granted',
            'security_storage'      => 'granted',
        );

        $base_call = $base + array('wait_for_update' => 500);
        $js        = 'window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};';
        $js       .= "gtag('consent','default'," . self::js_object($base_call) . ');';

        // Regions that start granted. Google applies the most specific entry.
        $analytics_regions = self::regions('regions_analytics');
        $marketing_regions = self::regions('regions_marketing');
        $groups            = array();

        foreach ($analytics_regions as $code) {
            $groups[$code]['analytics_storage'] = 'granted';
        }

        foreach ($marketing_regions as $code) {
            $groups[$code]['ad_storage']         = 'granted';
            $groups[$code]['ad_user_data']       = 'granted';
            $groups[$code]['ad_personalization'] = 'granted';
        }

        foreach ($groups as $code => $signals) {
            $js .= "gtag('consent','default'," . self::js_object($signals + array('region' => array($code))) . ');';
        }

        // A returning visitor with a current choice: apply it before any tag
        // runs, so tags do not start denied and wait for the deferred script.
        $version = wp_json_encode((string) self::settings()['policy_version']);
        $js     .= 'try{var m=document.cookie.match(/(?:^|; )' . self::COOKIE . '=([^;]*)/);'
            . 'if(m){var c=JSON.parse(decodeURIComponent(m[1]));'
            . 'if(c&&String(c.version)===' . $version . '){'
            . "gtag('consent','update',{analytics_storage:c.analytics?'granted':'denied',"
            . "ad_storage:c.marketing?'granted':'denied',ad_user_data:c.marketing?'granted':'denied',"
            . "ad_personalization:c.marketing?'granted':'denied'});}}}catch(e){}";

        return $js;
    }

    private static function js_object(array $data) {
        return wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    public function print_defaults() {
        if (!self::should_output()) {
            return;
        }

        wp_print_inline_script_tag(self::defaults_js(), array('id' => 'wta-consent-defaults'));
    }

    /* ----------------------------------------------------------- assets/config */

    public function enqueue() {
        if (!self::should_output()) {
            return;
        }

        wp_enqueue_style('wta-consent', WTA_URL . 'assets/css/wta-consent.css', array(), WTA_VERSION);
        wp_enqueue_script('wta-consent', WTA_URL . 'assets/js/wta-consent.js', array(), WTA_VERSION, true);
    }

    /** The admin preview loads the same files. */
    public static function enqueue_preview() {
        wp_enqueue_style('wta-consent', WTA_URL . 'assets/css/wta-consent.css', array(), WTA_VERSION);
        wp_enqueue_script('wta-consent', WTA_URL . 'assets/js/wta-consent.js', array(), WTA_VERSION, true);
    }

    /**
     * Defer the script, and gate scripts other code asked to gate.
     *
     * @param string $tag    The script tag.
     * @param string $handle Script handle.
     * @param string $src    Script URL.
     * @return string
     */
    public function filter_script_tag($tag, $handle, $src = '') {
        if ('wta-consent' === $handle && false === strpos($tag, ' defer')) {
            return str_replace('<script ', '<script defer ', $tag);
        }

        if (!self::should_output()) {
            return $tag;
        }

        /**
         * Script handles to hold back until a category is granted.
         *
         * @param array<string, string> $handles Handle => analytics|marketing.
         */
        $handles = apply_filters('wta_consent_gated_handles', array());

        if (is_array($handles) && isset($handles[$handle]) && in_array($handles[$handle], self::CATEGORIES, true)) {
            return self::gate_tag($tag, $handles[$handle]);
        }

        return $tag;
    }

    /**
     * Turn the opening tag of an executable script into a held one.
     *
     * @param string $tag      One or more script tags.
     * @param string $category analytics or marketing.
     */
    public static function gate_tag($tag, $category) {
        return (string) preg_replace_callback('/<script\b([^>]*)>/i', function ($m) use ($category) {
            $attrs = preg_replace('/\s(type|data-wta-consent)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $m[1]);

            return '<script type="text/plain" data-wta-consent="' . esc_attr($category) . '"' . $attrs . '>';
        }, $tag, 1);
    }

    /**
     * The script as a held tag that the banner script runs once the category
     * is granted. See wta_consent_script().
     *
     * @param string $category      analytics or marketing.
     * @param string $src_or_inline A URL, or JavaScript source.
     * @param array  $attrs         Extra attributes, name => value (true for boolean).
     * @return string The tag, or '' for a category that needs no consent.
     */
    public static function script_tag($category, $src_or_inline, $attrs = array()) {
        if (!in_array($category, self::CATEGORIES, true)) {
            return '';
        }

        $src_or_inline = trim((string) $src_or_inline);
        $is_src        = (bool) preg_match('#^(https?:)?//[^\s;]+$#i', $src_or_inline) || (bool) preg_match('#^/[^\s;/][^\s;]*$#', $src_or_inline);
        $extra         = '';

        foreach ((array) $attrs as $name => $value) {
            $name = strtolower((string) $name);

            if (!preg_match('/^[a-z][a-z0-9_:-]*$/', $name) || in_array($name, array('type', 'src', 'data-wta-consent'), true) || 0 === strpos($name, 'on')) {
                continue;
            }

            $extra .= true === $value ? ' ' . $name : ' ' . $name . '="' . esc_attr((string) $value) . '"';
        }

        if ($is_src) {
            $html = '<script type="text/plain" data-wta-consent="' . esc_attr($category) . '" src="' . esc_url($src_or_inline) . '"' . $extra . '></script>';
        } else {
            $body = str_ireplace('</script', '<\/script', $src_or_inline);
            $html = '<script type="text/plain" data-wta-consent="' . esc_attr($category) . '"' . $extra . '>' . $body . '</script>';
        }

        /**
         * The held script tag.
         *
         * @param string $html          The tag.
         * @param string $category      analytics or marketing.
         * @param string $src_or_inline What was passed in.
         */
        return (string) apply_filters('wta_consent_script_html', $html, $category, $src_or_inline);
    }

    /**
     * What the browser script needs: wording, categories, lifetime, version.
     *
     * @return array<string, mixed>
     */
    public static function client_config() {
        $s = self::settings();

        $categories = array(
            array('id' => 'necessary', 'label' => 'Necessary', 'description' => $s['necessary_desc'], 'required' => true),
        );

        foreach (self::CATEGORIES as $id) {
            if (!empty($s[$id . '_on'])) {
                $categories[] = array('id' => $id, 'label' => ucfirst($id), 'description' => $s[$id . '_desc'], 'required' => false);
            }
        }

        $config = array(
            'version'    => (string) $s['policy_version'],
            'lifetime'   => (int) $s['lifetime_days'],
            'cookie'     => self::COOKIE,
            'position'   => $s['position'],
            'text'       => $s['text'],
            'privacy'    => array('url' => self::privacy_url(), 'label' => $s['privacy_label']),
            'categories' => $categories,
            'labels'     => self::labels(),
            'pixel'      => (string) $s['meta_pixel_id'],
            'log'        => array(
                'enabled' => !empty($s['log_enabled']),
                'url'     => rest_url(WTA_Consent_Log::ROUTE_NS . WTA_Consent_Log::ROUTE),
                'dnt'     => !empty($s['log_respect_dnt']),
            ),
        );

        return apply_filters('wta_consent_client_config', $config);
    }

    /** Button and heading text, filterable for translation. */
    public static function labels() {
        return apply_filters('wta_consent_labels', array(
            'dialog'   => 'Cookie consent',
            'accept'   => 'Accept all',
            'decline'  => 'Decline',
            'manage'   => 'Manage',
            'title'    => 'Cookie settings',
            'save'     => 'Save choices',
            'back'     => 'Back',
            'always'   => 'Always on',
            'saved'    => 'Your cookie choices were saved.',
        ));
    }

    public function print_config() {
        if (!self::should_output()) {
            return;
        }

        $json = wp_json_encode(self::client_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        // A JSON document, not executable script, so no escaping beyond the
        // encoder's tag/ampersand flags is needed.
        echo '<script type="application/json" id="wta-consent-config">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /* ------------------------------------------------------------ shortcode */

    /**
     * [wta_cookie_settings label="Cookie settings"]: a button that reopens the
     * choices. Works with JavaScript only; without it the link has no effect.
     */
    public static function shortcode($atts = array()) {
        $atts = shortcode_atts(array('label' => 'Cookie settings', 'class' => ''), (array) $atts, 'wta_cookie_settings');
        $class = trim('wta-consent-settings-link ' . preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $atts['class']));

        return '<button type="button" class="' . esc_attr($class) . '" data-wta-consent-open>' . esc_html($atts['label']) . '</button>';
    }

    /* ------------------------------------------------------------- conflicts */

    /**
     * Other consent tools that would show a second banner, from what can be
     * detected: active plugins, and (given a page's HTML) scripts and
     * markers in it.
     *
     * @param string $html Markup of a front-end page, or ''.
     * @return array<int, array{id: string, label: string, how: string, fix: string}>
     */
    public static function detect_conflicts($html = '') {
        $active = (array) get_option('active_plugins', array());

        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }

        $tools = array(
            'usercentrics' => array('Usercentrics', array(), array('usercentrics', 'uc-block'), 'Remove the Usercentrics script from the page (Cloudflare Zaraz, a tag manager or the theme) and switch the CMP off in the Usercentrics admin.'),
            'zaraz'        => array('Cloudflare Zaraz', array(), array('/cdn-cgi/zaraz/'), 'In the Cloudflare dashboard open Zaraz, switch off its consent modal and remove the tools it loads once this banner is live.'),
            'cookiebot'    => array('Cookiebot', array('cookiebot/'), array('consent.cookiebot.com', 'cookiebot.com/uc.js'), 'Deactivate the Cookiebot plugin or remove its script.'),
            'complianz'    => array('Complianz', array('complianz-gdpr/', 'complianz-gdpr-premium/'), array('complianz'), 'Deactivate Complianz, or switch its banner off.'),
            'cookieyes'    => array('CookieYes', array('cookie-law-info/'), array('cookieyes', 'cookie-law-info'), 'Deactivate the CookieYes plugin or switch its banner off.'),
            'cookie_notice' => array('Cookie Notice', array('cookie-notice/'), array('cookie-notice'), 'Deactivate the Cookie Notice plugin.'),
            'gdpr_cc'      => array('GDPR Cookie Compliance', array('gdpr-cookie-compliance/'), array('moove_gdpr'), 'Deactivate the GDPR Cookie Compliance plugin.'),
            'borlabs'      => array('Borlabs Cookie', array('borlabs-cookie/'), array('borlabs-cookie'), 'Deactivate Borlabs Cookie.'),
            'rcb'          => array('Real Cookie Banner', array('real-cookie-banner/', 'real-cookie-banner-pro/'), array('real-cookie-banner'), 'Deactivate Real Cookie Banner.'),
        );

        $found = array();

        foreach ($tools as $id => $tool) {
            list($label, $folders, $needles, $fix) = $tool;
            $how = '';

            foreach ($active as $plugin) {
                foreach ($folders as $folder) {
                    if (0 === strpos((string) $plugin, $folder)) {
                        $how = 'Plugin active: ' . $plugin;
                        break 2;
                    }
                }
            }

            if ('' === $how && '' !== $html) {
                foreach ($needles as $needle) {
                    if (false !== stripos($html, $needle)) {
                        $how = 'Found "' . $needle . '" in the home page';
                        break;
                    }
                }
            }

            if ('' !== $how) {
                $found[] = array('id' => $id, 'label' => $label, 'how' => $how, 'fix' => $fix);
            }
        }

        return apply_filters('wta_consent_conflicts', $found);
    }

    /**
     * Fetch the live home page (bypassing the page cache with a query
     * argument) and keep what was found for the admin screen.
     *
     * @return array{time: int, ok: bool, error: string, conflicts: array}
     */
    public static function scan_live_page() {
        $url      = add_query_arg('wta_consent_scan', (string) time(), home_url('/'));
        $response = wp_remote_get($url, array(
            'timeout'     => 8,
            'redirection' => 3,
            'headers'     => array('Cache-Control' => 'no-cache'),
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            $result = array(
                'time'      => time(),
                'ok'        => false,
                'error'     => is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code($response),
                'conflicts' => array(),
            );
        } else {
            $result = array(
                'time'      => time(),
                'ok'        => true,
                'error'     => '',
                'conflicts' => self::detect_conflicts((string) wp_remote_retrieve_body($response)),
            );
        }

        set_transient(self::SCAN_TRANSIENT, $result, HOUR_IN_SECONDS);

        return $result;
    }

    /** @return array|null The last scan. */
    public static function last_scan() {
        $scan = get_transient(self::SCAN_TRANSIENT);

        return is_array($scan) ? $scan : null;
    }

    /* ---------------------------------------------------------------- status */

    /**
     * Everything about the module in one array, for the consent-status ability.
     *
     * @return array<string, mixed>
     */
    public static function status() {
        $enabled = class_exists('WP_Travel_Addons') ? WP_Travel_Addons::module_enabled('consent') : false;
        $s       = self::settings();
        $scan    = self::last_scan();
        $conflicts = $scan && !empty($scan['ok']) ? $scan['conflicts'] : self::detect_conflicts();

        $categories = array('necessary');

        foreach (self::CATEGORIES as $id) {
            if (!empty($s[$id . '_on'])) {
                $categories[] = $id;
            }
        }

        $warnings = array();

        if ($enabled && !empty($conflicts)) {
            $warnings[] = 'Another consent tool is present (' . implode(', ', wp_list_pluck($conflicts, 'label')) . '), so visitors may see two banners.';
        }

        if ($enabled && '' !== $s['meta_pixel_id'] && empty($s['marketing_on'])) {
            $warnings[] = 'A Meta Pixel ID is set but the marketing category is off, so the pixel can never load.';
        }

        return array(
            'module_enabled' => $enabled,
            'settings'       => array(
                'policy_version'   => (string) $s['policy_version'],
                'lifetime_days'    => (int) $s['lifetime_days'],
                'position'         => $s['position'],
                'categories'       => $categories,
                'privacy_url'      => self::privacy_url(),
                'meta_pixel_id'    => (string) $s['meta_pixel_id'],
                'regions_analytics' => self::regions('regions_analytics'),
                'regions_marketing' => self::regions('regions_marketing'),
                'logging'          => !empty($s['log_enabled']),
                'respect_dnt'      => !empty($s['log_respect_dnt']),
            ),
            'consent_mode'   => array(
                'default'         => 'denied',
                'denied_signals'  => array('ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage'),
                'granted_signals' => array('functionality_storage', 'security_storage'),
                'wait_for_update' => 500,
            ),
            'scripts'        => array(
                'js'  => WTA_URL . 'assets/js/wta-consent.js',
                'css' => WTA_URL . 'assets/css/wta-consent.css',
            ),
            'records'        => WTA_Consent_Log::summary(30),
            'conflicts'      => array_values(array_map(function ($c) {
                return array('id' => $c['id'], 'label' => $c['label'], 'how' => $c['how']);
            }, $conflicts)),
            'warnings'       => $warnings,
        );
    }
}

/**
 * A script held back until the visitor grants a category.
 *
 * Echo the result where the script would go. The consent banner runs it, as an
 * ordinary script, once the category is granted; until then (and for visitors
 * who decline) it never executes. The Consent module has to be switched on.
 *
 * @param string $category      analytics or marketing.
 * @param string $src_or_inline A URL, or JavaScript source.
 * @param array  $attrs         Extra attributes, name => value (true for boolean).
 * @return string
 */
function wta_consent_script($category, $src_or_inline, $attrs = array()) {
    return WTA_Consent::script_tag($category, $src_or_inline, $attrs);
}
