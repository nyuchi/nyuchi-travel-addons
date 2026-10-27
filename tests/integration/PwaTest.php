<?php
/**
 * The PWA module: defaults, manifest, service worker text, output and the
 * rule that it registers nothing while it is off.
 *
 * @package WPTravelAddons\Tests
 */

class PwaTest extends WP_UnitTestCase {

    public function tear_down() {
        delete_option(WTA_PWA::OPTION);
        delete_option('wp_travel_settings');
        delete_option('wta_module_pwa');
        delete_option('site_icon');
        wp_set_current_user(0);

        parent::tear_down();
    }

    /** Hook callbacks on $hook whose object is an instance of $class. */
    protected function callbacks_of($hook, $class) {
        global $wp_filter;
        $found = array();

        if (!isset($wp_filter[$hook])) {
            return $found;
        }

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof $class) {
                    $found[] = $callback['function'][1];
                }
            }
        }

        return $found;
    }

    protected function icon_attachment($width, $height, $mime = 'image/png') {
        $id = self::factory()->attachment->create(array(
            'post_mime_type' => $mime,
            'post_title'     => 'icon',
            'file'           => 'icon-' . $width . '.png',
        ));
        update_post_meta($id, '_wp_attachment_metadata', array('width' => $width, 'height' => $height, 'file' => 'icon-' . $width . '.png'));

        return $id;
    }

    protected function manifest() {
        $response = WTA_PWA::response('manifest');
        $decoded  = json_decode($response['body'], true);
        $this->assertIsArray($decoded, 'The manifest must be valid JSON.');

        return $decoded;
    }

    /* ---------------------------------------------------------------- off */

    public function test_the_module_is_off_by_default() {
        $map = WP_Travel_Addons::module_map();

        $this->assertSame(0, $map['pwa']['default']);
        $this->assertFalse(WP_Travel_Addons::module_enabled('pwa'));
        $this->assertNull(WP_Travel_Addons::instance()->module('pwa'));
    }

    public function test_while_off_nothing_is_registered_but_the_legacy_clean_up() {
        foreach (array('wp_head', 'wp_footer', 'admin_notices', 'parse_request') as $hook) {
            $this->assertSame(array(), $this->callbacks_of($hook, 'WTA_PWA'), "WTA_PWA must not hook {$hook} while off.");
        }

        $this->assertContains('maybe_run', $this->callbacks_of('admin_init', 'WTA_PWA_Legacy'));
        $this->assertNotNull(WP_Travel_Addons::instance()->module('pwa_legacy'));

        ob_start();
        do_action('wp_head');
        do_action('wp_footer');
        $output = ob_get_clean();

        $this->assertStringNotContainsString('manifest', $output);
        $this->assertStringNotContainsString('serviceWorker', $output);
    }

    /* ----------------------------------------------------------- defaults */

    public function test_defaults_come_from_the_site_and_are_filterable() {
        update_option('blogname', 'Iconic Expeditions');
        update_option('blogdescription', 'Safaris');

        $defaults = WTA_PWA::defaults();
        $this->assertSame('Iconic Expeditions', $defaults['name']);
        $this->assertSame('Iconic Expe', substr($defaults['short_name'], 0, 11));
        $this->assertLessThanOrEqual(12, strlen($defaults['short_name']));
        $this->assertSame('standalone', $defaults['display']);
        $this->assertSame('/', $defaults['start_url']);
        $this->assertNotSame('#DB4938', $defaults['theme_color'], 'WP Travel\'s old red is not the default.');

        $filter = function ($d) {
            $d['theme_color'] = '#123456';

            return $d;
        };
        add_filter('wta_pwa_defaults', $filter);
        $this->assertSame('#123456', WTA_PWA::settings()['theme_color']);
        remove_filter('wta_pwa_defaults', $filter);
    }

    public function test_a_blank_saved_value_falls_back_to_the_default() {
        update_option(WTA_PWA::OPTION, array('name' => '', 'theme_color' => '#AA0000'));

        $settings = WTA_PWA::settings();
        $this->assertSame(WTA_PWA::defaults()['name'], $settings['name']);
        $this->assertSame('#AA0000', $settings['theme_color']);
    }

    public function test_sanitize_rejects_bad_values() {
        $clean = WTA_PWA::sanitize(array(
            'name'             => '<b>Trips</b>',
            'short_name'       => 'A very long short name',
            'theme_color'      => 'red',
            'background_color' => '#abc',
            'display'          => 'sideways',
            'start_url'        => 'https://evil.example/phish',
            'endpoint_style'   => 'wild',
            'cache_version'    => '-5',
            'icon_192'         => '999999',
        ));

        $this->assertSame('Trips', $clean['name']);
        $this->assertSame(12, strlen($clean['short_name']));
        $this->assertSame(WTA_PWA::defaults()['theme_color'], $clean['theme_color']);
        $this->assertSame('#ABC', $clean['background_color']);
        $this->assertSame('standalone', $clean['display']);
        $this->assertSame('/', $clean['start_url']);
        $this->assertSame('auto', $clean['endpoint_style']);
        $this->assertSame(5, $clean['cache_version']);
        $this->assertSame(0, $clean['icon_192'], 'An ID that is not an image attachment is dropped.');
    }

    public function test_start_url_accepts_only_paths_on_this_site() {
        $this->assertSame('/trips/', WTA_PWA::sanitize_start_url('/trips/'));
        $this->assertSame('/trips/?a=1', WTA_PWA::sanitize_start_url(home_url('/trips/?a=1')));
        $this->assertSame('/', WTA_PWA::sanitize_start_url('//evil.example'));
        $this->assertSame('/', WTA_PWA::sanitize_start_url('javascript:alert(1)'));
        $this->assertSame('/', WTA_PWA::sanitize_start_url('trips'));
        $this->assertSame('/', WTA_PWA::sanitize_start_url(''));
    }

    /* ----------------------------------------------------------- manifest */

    public function test_the_manifest_is_valid_json_with_the_right_headers() {
        $response = WTA_PWA::response('manifest');

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('application/manifest+json', $response['headers']['Content-Type']);
        $this->assertStringContainsString('max-age=3600', $response['headers']['Cache-Control']);
        $this->assertSame('"' . md5($response['body']) . '"', $response['headers']['ETag']);

        $manifest = $this->manifest();
        foreach (array('id', 'name', 'short_name', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons') as $key) {
            $this->assertArrayHasKey($key, $manifest);
        }
        $this->assertSame(home_url('/'), $manifest['scope']);
    }

    public function test_the_manifest_uses_the_saved_settings() {
        update_option(WTA_PWA::OPTION, WTA_PWA::sanitize(array(
            'name'             => 'Iconic Expeditions',
            'short_name'       => 'Iconic',
            'theme_color'      => '#0A5C36',
            'background_color' => '#F5F0E6',
            'start_url'        => '/trips/',
            'display'          => 'minimal-ui',
        )));

        $manifest = $this->manifest();
        $this->assertSame('Iconic Expeditions', $manifest['name']);
        $this->assertSame('Iconic', $manifest['short_name']);
        $this->assertSame('#0A5C36', $manifest['theme_color']);
        $this->assertSame('#F5F0E6', $manifest['background_color']);
        $this->assertSame(home_url('/trips/'), $manifest['start_url']);
        $this->assertSame('minimal-ui', $manifest['display']);
    }

    public function test_the_manifest_lists_valid_icons_with_their_real_sizes() {
        $icon192 = $this->icon_attachment(192, 192);
        $icon512 = $this->icon_attachment(512, 512);
        $mask    = $this->icon_attachment(512, 512);
        update_option(WTA_PWA::OPTION, array('icon_192' => $icon192, 'icon_512' => $icon512, 'icon_maskable' => $mask));

        $icons = $this->manifest()['icons'];

        $this->assertCount(3, $icons);
        $this->assertSame(array('192x192', '512x512', '512x512'), wp_list_pluck($icons, 'sizes'));
        $this->assertSame(array('any', 'any', 'maskable'), wp_list_pluck($icons, 'purpose'));
        $this->assertTrue(WTA_PWA::status()['icons_ok']);
    }

    public function test_invalid_icons_are_left_out_and_reported() {
        $small  = $this->icon_attachment(100, 100);
        $wide   = $this->icon_attachment(512, 256);
        update_option(WTA_PWA::OPTION, array('icon_192' => $small, 'icon_512' => $wide));

        $this->assertSame(array(), $this->manifest()['icons']);

        $status = WTA_PWA::status();
        $this->assertFalse($status['icons_ok']);
        $by_slot = array();
        foreach ($status['icons'] as $icon) {
            $by_slot[$icon['slot']] = $icon;
        }
        $this->assertStringContainsString('at least 192', implode(' ', $by_slot['icon_192']['problems']));
        $this->assertStringContainsString('square', implode(' ', $by_slot['icon_512']['problems']));
        $this->assertNotEmpty($by_slot['icon_maskable']['problems'], 'A missing maskable icon is warned about.');
        $this->assertFalse($by_slot['icon_maskable']['required']);
    }

    public function test_missing_icons_warn_and_the_manifest_has_no_icons() {
        $status = WTA_PWA::status();

        $this->assertFalse($status['icons_ok']);
        $this->assertSame(array(), $this->manifest()['icons']);
    }

    public function test_the_site_icon_fills_the_192_and_512_slots_but_not_the_maskable_one() {
        $site_icon = $this->icon_attachment(512, 512);
        update_option('site_icon', $site_icon);

        $report = array();
        foreach (WTA_PWA::icon_report() as $icon) {
            $report[$icon['slot']] = $icon;
        }

        $this->assertSame('site_icon', $report['icon_192']['source']);
        $this->assertSame('site_icon', $report['icon_512']['source']);
        $this->assertSame('none', $report['icon_maskable']['source']);
        $this->assertTrue(WTA_PWA::status()['icons_ok']);
    }

    /* ----------------------------------------------------------- endpoints */

    public function test_endpoint_urls_follow_the_permalink_setting() {
        $this->assertSame(home_url('/?wta_pwa=sw'), WTA_PWA::endpoint_url('sw'), 'Plain permalinks use the query form.');

        $this->set_permalink_structure('/%postname%/');
        $this->assertSame(home_url('/wta-sw.js'), WTA_PWA::endpoint_url('sw'));
        $this->assertSame(home_url('/wta-manifest.webmanifest'), WTA_PWA::endpoint_url('manifest'));
        $this->assertSame(home_url('/wta-offline'), WTA_PWA::endpoint_url('offline'));

        update_option(WTA_PWA::OPTION, array('endpoint_style' => 'query'));
        $this->assertSame(home_url('/?wta_pwa=manifest'), WTA_PWA::endpoint_url('manifest'));

        $this->set_permalink_structure('');
    }

    public function test_requests_are_matched_by_path_or_query() {
        $wp          = new stdClass();
        $wp->request = 'wta-sw.js';
        $this->assertSame('sw', WTA_PWA::request_kind($wp));

        $wp->request = 'wta-manifest.webmanifest/';
        $this->assertSame('manifest', WTA_PWA::request_kind($wp));

        $wp->request = 'about-us';
        $this->assertSame('', WTA_PWA::request_kind($wp));

        $_GET['wta_pwa'] = 'offline';
        $this->assertSame('offline', WTA_PWA::request_kind($wp));
        $_GET['wta_pwa'] = 'bogus';
        $this->assertSame('', WTA_PWA::request_kind($wp));
        unset($_GET['wta_pwa']);
    }

    /* ----------------------------------------------------- service worker */

    public function test_the_worker_is_served_uncached_with_root_scope() {
        $response = WTA_PWA::response('sw');

        $this->assertStringStartsWith('application/javascript', $response['headers']['Content-Type']);
        $this->assertSame('/', $response['headers']['Service-Worker-Allowed']);
        $this->assertStringContainsString('no-cache', $response['headers']['Cache-Control']);
        $this->assertStringContainsString('no-store', $response['headers']['Cache-Control']);
        $this->assertStringContainsString('max-age=0', $response['headers']['Cache-Control']);
    }

    protected function worker_js() {
        return WTA_PWA::response('sw')['body'];
    }

    protected function worker_config() {
        $this->assertSame(1, preg_match('/const CONFIG = (\{.*?\});\n/s', $this->worker_js(), $m), 'The config is inlined.');

        return json_decode($m[1], true);
    }

    public function test_the_worker_never_touches_private_or_dynamic_urls() {
        $js     = $this->worker_js();
        $config = $this->worker_config();

        foreach (array('/wp-admin', '/wp-login.php', '/wp-json', '/admin-ajax.php', 'enquiry', 'checkout', 'cart') as $part) {
            $this->assertContains($part, $config['exclude'], "{$part} is excluded.");
        }

        $this->assertStringContainsString("request.method !== 'GET'", $js, 'Non-GET requests are left alone.');
        $this->assertMatchesRegularExpression('#/nonce\|preview#', $js, 'Nonce and preview URLs are left alone.');
        $this->assertStringContainsString('isExcluded(url, request)', $js);
    }

    public function test_the_exclusions_are_filterable() {
        $filter = function ($paths) {
            $paths[] = '/members-only';

            return $paths;
        };
        add_filter('wta_pwa_exclude_paths', $filter);
        $this->assertContains('/members-only', $this->worker_config()['exclude']);
        remove_filter('wta_pwa_exclude_paths', $filter);
    }

    public function test_pages_are_never_stored_and_the_network_always_comes_first() {
        $js = $this->worker_js();

        $this->assertSame(1, preg_match('/async function networkFirstNavigation\(request\) \{(.*?)\n\}\n/s', $js, $m));
        $body = $m[1];

        $this->assertStringContainsString('await fetch(request)', $body);
        $this->assertStringNotContainsString('.put(', $body, 'A navigation response is never written to a cache.');
        $this->assertStringNotContainsString('cache.match(request', $body, 'A navigation is never answered from a cache while the network works.');
        $this->assertStringContainsString('offlineFallback()', $body);
        $this->assertStringContainsString('skipWaiting()', $js);
        $this->assertStringContainsString('clients.claim()', $js);
        $this->assertStringNotContainsString("cache.add('/')", $js, 'The homepage is not precached (the WP Travel bug).');
    }

    public function test_static_assets_use_the_right_strategies_with_limits() {
        $js     = $this->worker_js();
        $config = $this->worker_config();

        $this->assertStringContainsString('staleWhileRevalidate(event, CACHE_ASSETS', $js);
        $this->assertStringContainsString("path.indexOf('/wp-content/') === -1", $js);
        $this->assertStringContainsString('cacheFirst(request, CACHE_FONTS', $js);
        $this->assertStringContainsString('cacheFirst(request, CACHE_IMAGES', $js);

        foreach (array('images', 'fonts', 'assets') as $kind) {
            $this->assertGreaterThan(0, $config['limits'][$kind]['maxEntries']);
            $this->assertGreaterThan(0, $config['limits'][$kind]['maxBytes']);
            $this->assertGreaterThan(0, $config['limits'][$kind]['maxAgeSeconds']);
        }
    }

    public function test_cache_names_change_with_the_release_and_the_cache_version() {
        $before = $this->worker_config()['build'];
        $this->assertStringContainsString(WTA_VERSION, $before);

        update_option(WTA_PWA::OPTION, array('cache_version' => 2));
        $after = $this->worker_config()['build'];

        $this->assertNotSame($before, $after);
        $this->assertStringContainsString('wta-pwa-', $this->worker_js());
        $this->assertStringContainsString("k.indexOf(PREFIX) === 0 && CURRENT.indexOf(k) === -1", $this->worker_js(), 'Old caches are deleted on activate.');
    }

    public function test_the_served_worker_is_valid_javascript() {
        if (!is_executable('/usr/bin/node') && !shell_exec('command -v node')) {
            $this->markTestSkipped('node is not available.');
        }

        $file = tempnam(sys_get_temp_dir(), 'wtasw') . '.js';
        file_put_contents($file, $this->worker_js());
        exec('node --check ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        unlink($file);

        $this->assertSame(0, $code, implode("\n", $out));
    }

    /* -------------------------------------------------------- offline page */

    public function test_the_offline_page_shows_the_configured_text_escaped() {
        update_option(WTA_PWA::OPTION, array('offline_title' => 'No signal <script>', 'offline_message' => 'Back soon & safe'));

        $response = WTA_PWA::response('offline');
        $this->assertSame(200, $response['status']);
        $this->assertSame('noindex', $response['headers']['X-Robots-Tag']);
        $this->assertStringContainsString('No signal &lt;script&gt;', $response['body']);
        $this->assertStringContainsString('Back soon &amp; safe', $response['body']);
        $this->assertStringNotContainsString('<script>No', $response['body']);
    }

    /* ---------------------------------------------------------- front end */

    public function test_the_head_carries_manifest_theme_colour_and_touch_icon() {
        update_option(WTA_PWA::OPTION, array('theme_color' => '#0A5C36', 'icon_192' => $this->icon_attachment(192, 192)));

        ob_start();
        (new WTA_PWA())->print_head_tags();
        $head = ob_get_clean();

        $this->assertStringContainsString('<link rel="manifest" href="' . esc_url(WTA_PWA::endpoint_url('manifest')) . '">', $head);
        $this->assertStringContainsString('<meta name="theme-color" content="#0A5C36">', $head);
        $this->assertStringContainsString('<link rel="apple-touch-icon"', $head);
    }

    public function test_the_registration_script_checks_for_support_and_skips_signed_in_users() {
        $pwa = new WTA_PWA();

        ob_start();
        $pwa->print_registration();
        $script = ob_get_clean();

        $this->assertStringContainsString("'serviceWorker' in navigator", $script);
        $this->assertStringContainsString('navigator.serviceWorker.register(', $script);
        $this->assertStringContainsString('wta_pwa=sw', $script);

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        ob_start();
        $pwa->print_registration();
        $this->assertSame('', ob_get_clean(), 'Signed-in users are not registered.');
    }

    public function test_nothing_is_printed_while_wp_travel_s_own_pwa_is_on() {
        update_option('wp_travel_settings', array('enable_pwa' => 'yes'));
        $pwa = new WTA_PWA();

        ob_start();
        $pwa->print_head_tags();
        $pwa->print_registration();
        $this->assertSame('', ob_get_clean());

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        ob_start();
        $pwa->conflict_notice();
        $notice = ob_get_clean();
        $this->assertStringContainsString('PWA is paused', $notice);

        $status = WTA_PWA::status();
        $this->assertTrue($status['wp_travel_pwa_enabled']);
        $this->assertFalse($status['active']);
    }

    public function test_nothing_is_printed_in_wp_admin() {
        set_current_screen('dashboard');
        $pwa = new WTA_PWA();

        ob_start();
        $pwa->print_head_tags();
        $pwa->print_registration();
        $this->assertSame('', ob_get_clean());

        set_current_screen('front');
    }

    /* ----------------------------------------------------------- ability */

    public function test_the_status_ability_is_read_only_and_needs_manage_options() {
        if (!function_exists('wp_get_ability')) {
            $this->markTestSkipped('The Abilities API needs WordPress 6.9 or newer.');
        }

        $ability = wp_get_ability('nyuchi-travel/pwa-status');
        $this->assertInstanceOf(WP_Ability::class, $ability);

        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $this->setExpectedIncorrectUsage('WP_Ability::execute');
        $this->assertWPError($ability->execute(array()), 'An editor may not read site configuration.');

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $status = $ability->execute(array());

        $this->assertIsArray($status);
        $this->assertFalse($status['module_enabled']);
        $this->assertSame(WTA_PWA::endpoint_url('manifest'), $status['manifest_url']);
        $this->assertSame(WTA_PWA::endpoint_url('sw'), $status['service_worker_url']);
        $this->assertArrayHasKey('state', $status['legacy_service_worker']);
        $this->assertCount(3, $status['icons']);
    }

    /* -------------------------------------------------------------- admin */

    public function test_the_pwa_tab_renders_the_settings_and_the_legacy_state() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $_GET['tab'] = 'pwa';

        $this->assertArrayHasKey('pwa', WTA_Admin::tabs());

        ob_start();
        (new WTA_Admin(WP_Travel_Addons::instance()))->render();
        $html = ob_get_clean();
        unset($_GET['tab']);

        $this->assertStringContainsString('name="wta_pwa[name]"', $html);
        $this->assertStringContainsString('name="module_pwa"', $html);
        $this->assertStringContainsString('name="wta_pwa_nonce"', $html);
        $this->assertStringContainsString('Old WP Travel service worker', $html);
    }
}
