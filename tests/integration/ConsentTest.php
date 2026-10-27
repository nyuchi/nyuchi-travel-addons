<?php
/**
 * The consent module: off by default, nothing in the admin or the editor,
 * Consent Mode defaults before any tag, settings, held scripts, the Meta Pixel
 * setting, the logging endpoint, the ability and the privacy tools.
 *
 * @package WPTravelAddons\Tests
 */

class ConsentTest extends WP_UnitTestCase {

    const UUID = '3f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90';

    public function set_up() {
        parent::set_up();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->drop_table();
    }

    /** DDL is not rolled back with the test's transaction, so the table is dropped by hand. */
    protected function drop_table() {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS ' . WTA_Consent_Log::table()); // phpcs:ignore WordPress.DB.PreparedSQL
        delete_option(WTA_Consent_Log::DB_OPTION);
    }

    public function tear_down() {
        delete_option(WTA_Consent::OPTION);
        delete_option('wta_module_consent');
        delete_option(WTA_Consent_Log::DB_OPTION);
        delete_transient(WTA_Consent::SCAN_TRANSIENT);
        delete_transient('wta_cl_' . WTA_Consent_Log::ip_hash('203.0.113.7'));
        unset($_GET['elementor-preview'], $_SERVER['REMOTE_ADDR']);
        wp_set_current_user(0);
        set_current_screen('front');
        WTA_Test_HTTP::reset();
        $this->drop_table();

        parent::tear_down();
    }

    /** Hook callbacks on $hook whose object is an instance of $class. */
    protected function callbacks_of($hook, $class) {
        global $wp_filter;
        $found = array();

        if (!isset($wp_filter[$hook])) {
            return $found;
        }

        foreach ($wp_filter[$hook]->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof $class) {
                    $found[] = $callback['function'][1];
                }
            }
        }

        return $found;
    }

    protected function head() {
        ob_start();
        (new WTA_Consent())->print_defaults();

        return ob_get_clean();
    }

    protected function footer() {
        ob_start();
        (new WTA_Consent())->print_config();

        return ob_get_clean();
    }

    protected function config_from_footer() {
        $html = $this->footer();
        $this->assertMatchesRegularExpression('#<script type="application/json" id="wta-consent-config">(.*)</script>#s', $html);
        preg_match('#id="wta-consent-config">(.*)</script>#s', $html, $m);
        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, 'The inline config must be valid JSON.');

        return $decoded;
    }

    protected function rest($body, $headers = array()) {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);

        $request = new WP_REST_Request('POST', '/wta/v1/consent');
        $request->set_header('content-type', 'application/json');
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        $request->set_body(wp_json_encode($body));

        return rest_do_request($request);
    }

    protected function valid_body($extra = array()) {
        return array_merge(array('id' => self::UUID, 'version' => '1', 'analytics' => true, 'marketing' => false), $extra);
    }

    protected function enable_logging() {
        update_option('wta_module_consent', 1);
        update_option(WTA_Consent::OPTION, array('log_enabled' => 1));
    }

    protected function rows() {
        global $wpdb;

        return WTA_Consent_Log::table_exists() ? $wpdb->get_results('SELECT * FROM ' . WTA_Consent_Log::table()) : array(); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /* ---------------------------------------------------------------- off */

    public function test_the_module_is_off_by_default() {
        $map = WP_Travel_Addons::module_map();

        $this->assertSame(0, $map['consent']['default']);
        $this->assertFalse(WP_Travel_Addons::module_enabled('consent'));
        $this->assertNull(WP_Travel_Addons::instance()->module('consent'));
    }

    public function test_while_off_the_front_end_hooks_are_not_registered() {
        foreach (array('wp_head', 'wp_footer', 'wp_enqueue_scripts', 'script_loader_tag') as $hook) {
            $this->assertSame(array(), $this->callbacks_of($hook, 'WTA_Consent'), "WTA_Consent must not hook {$hook} while off.");
        }

        $this->assertFalse(shortcode_exists('wta_cookie_settings'));
        $this->assertNotNull(WP_Travel_Addons::instance()->module('consent_log'), 'The privacy tools and purge stay registered.');
    }

    public function test_the_tab_exists() {
        $this->assertArrayHasKey('consent', WTA_Admin::tabs());
    }

    /* ------------------------------------------------------ where it prints */

    public function test_nothing_is_printed_in_the_admin() {
        set_current_screen('dashboard');
        $this->assertTrue(is_admin());

        $this->assertSame('', $this->head());
        $this->assertSame('', $this->footer());
        $this->assertFalse(WTA_Consent::should_output());
    }

    public function test_nothing_is_printed_in_the_elementor_editor_or_preview() {
        $this->assertTrue(WTA_Consent::should_output(), 'A plain front-end request is allowed.');

        $_GET['elementor-preview'] = '123';
        $this->assertFalse(WTA_Consent::should_output());
        $this->assertSame('', $this->head());
        $this->assertSame('', $this->footer());
    }

    public function test_nothing_is_enqueued_when_the_request_may_not_output() {
        set_current_screen('dashboard');
        (new WTA_Consent())->enqueue();

        $this->assertFalse(wp_style_is('wta-consent', 'enqueued'));
        $this->assertFalse(wp_script_is('wta-consent', 'enqueued'));
    }

    public function test_the_style_and_one_deferred_script_are_enqueued_on_the_front_end() {
        $consent = new WTA_Consent();
        $consent->enqueue();

        $this->assertTrue(wp_style_is('wta-consent', 'enqueued'));
        $this->assertTrue(wp_script_is('wta-consent', 'enqueued'));

        $tag = apply_filters('script_loader_tag', '<script src="x.js"></script>', 'wta-consent', 'x.js');
        $this->assertStringContainsString('<script defer ', $tag);

        $this->assertFileExists(WTA_DIR . 'assets/js/wta-consent.js');
        $this->assertFileExists(WTA_DIR . 'assets/css/wta-consent.css');
    }

    /* ------------------------------------------------------- Consent Mode */

    public function test_the_defaults_are_printed_in_the_head_first() {
        $consent = new WTA_Consent();

        $this->assertSame(1, has_action('wp_head', array($consent, 'print_defaults')));

        $head = $this->head();
        $this->assertStringContainsString('id="wta-consent-defaults"', $head);
        $this->assertStringContainsString("gtag('consent','default'", $head);
    }

    public function test_the_defaults_deny_everything_but_functionality_and_security() {
        $js = WTA_Consent::defaults_js();

        $this->assertStringContainsString('"ad_storage":"denied"', $js);
        $this->assertStringContainsString('"ad_user_data":"denied"', $js);
        $this->assertStringContainsString('"ad_personalization":"denied"', $js);
        $this->assertStringContainsString('"analytics_storage":"denied"', $js);
        $this->assertStringContainsString('"functionality_storage":"granted"', $js);
        $this->assertStringContainsString('"security_storage":"granted"', $js);
        $this->assertStringContainsString('"wait_for_update":500', $js);
        $this->assertStringNotContainsString('"region"', $js, 'Denied everywhere by default.');
    }

    public function test_gtag_is_defined_before_the_default_call_and_the_default_comes_before_any_update() {
        $js = WTA_Consent::defaults_js();

        $define  = strpos($js, 'window.gtag=');
        $default = strpos($js, "gtag('consent','default'");
        $update  = strpos($js, "gtag('consent','update'");

        $this->assertNotFalse($define);
        $this->assertLessThan($default, $define);
        $this->assertLessThan($update, $default);
    }

    public function test_regional_defaults_are_a_setting() {
        update_option(WTA_Consent::OPTION, array('regions_analytics' => 'us, US-CA', 'regions_marketing' => 'za'));
        $js = WTA_Consent::defaults_js();

        $this->assertStringContainsString('{"analytics_storage":"granted","region":["US"]}', $js);
        $this->assertStringContainsString('"region":["US-CA"]', $js);
        $this->assertStringContainsString('{"ad_storage":"granted","ad_user_data":"granted","ad_personalization":"granted","region":["ZA"]}', $js);
        $this->assertLessThan(strpos($js, '"region"'), strpos($js, '"wait_for_update":500'), 'The global default comes first.');
    }

    public function test_the_head_snippet_behaves_in_a_browser_like_sandbox() {
        if (!shell_exec('command -v node')) {
            $this->markTestSkipped('node is not available.');
        }

        $file = tempnam(sys_get_temp_dir(), 'wtahead') . '.js';
        file_put_contents($file, WTA_Consent::defaults_js());
        exec('node ' . escapeshellarg(WTA_DIR . 'tests/js/head-snippet.test.js') . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        unlink($file);

        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_the_browser_script_is_valid_and_its_pure_functions_pass() {
        if (!shell_exec('command -v node')) {
            $this->markTestSkipped('node is not available.');
        }

        exec('node --check ' . escapeshellarg(WTA_DIR . 'assets/js/wta-consent.js') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        $out = array();
        exec('node ' . escapeshellarg(WTA_DIR . 'tests/js/consent-core.test.js') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    /* ------------------------------------------------------------ settings */

    public function test_defaults_are_neutral_and_match_the_brief() {
        $s = WTA_Consent::settings();

        $this->assertSame('/privacy-policy/', $s['privacy_url']);
        $this->assertSame(180, $s['lifetime_days']);
        $this->assertSame('1', $s['policy_version']);
        $this->assertSame('bottom', $s['position']);
        $this->assertSame(0, $s['log_enabled']);
        $this->assertSame('', $s['meta_pixel_id']);
        $this->assertDoesNotMatchRegularExpression('/compl(y|iant|iance)|guarantee|legal/i', $s['text'], 'The wording makes no legal promise.');
    }

    public function test_sanitising_clamps_and_whitelists() {
        $out = WTA_Consent::sanitize(array(
            'text'              => "  Cookies <b>here</b>\n",
            'privacy_url'       => 'javascript:alert(1)',
            'position'          => 'top',
            'lifetime_days'     => '9999',
            'policy_version'    => 'v 2<script>',
            'analytics_on'      => '0',
            'marketing_on'      => '1',
            'regions_analytics' => 'es, GB; xx1, US-CA',
            'meta_pixel_id'     => '12ab34 5678901',
            'log_enabled'       => '1',
            'nested'            => array('x'),
        ));

        $this->assertSame('Cookies here', $out['text']);
        $this->assertSame('/privacy-policy/', $out['privacy_url']);
        $this->assertSame('bottom', $out['position']);
        $this->assertSame(395, $out['lifetime_days']);
        $this->assertSame('v2script', $out['policy_version']);
        $this->assertSame(0, $out['analytics_on']);
        $this->assertSame(1, $out['marketing_on']);
        $this->assertSame('ES, GB, US-CA', $out['regions_analytics']);
        $this->assertSame('12345678901', $out['meta_pixel_id']);
        $this->assertSame(1, $out['log_enabled']);
        $this->assertArrayNotHasKey('nested', $out);
    }

    public function test_sanitising_blank_values_restore_defaults_and_partial_input_is_safe() {
        $out = WTA_Consent::sanitize(array('text' => '', 'lifetime_days' => '0', 'policy_version' => ''));
        $d   = WTA_Consent::defaults();

        $this->assertSame($d['text'], $out['text']);
        $this->assertSame(180, $out['lifetime_days']);
        $this->assertSame('1', $out['policy_version']);
        $this->assertSame(1, $out['analytics_on'], 'A missing flag keeps its default.');
        $this->assertSame(1, $out['log_respect_dnt']);
        $this->assertSame(0, $out['log_enabled']);
    }

    public function test_the_privacy_url_takes_a_path_or_an_http_address_only() {
        $this->assertSame('/legal/privacy/', WTA_Consent::sanitize_privacy_url('/legal/privacy/'));
        $this->assertSame('https://example.com/privacy', WTA_Consent::sanitize_privacy_url('https://example.com/privacy'));
        $this->assertSame('/privacy-policy/', WTA_Consent::sanitize_privacy_url('//evil.example/x'));
        $this->assertSame('/privacy-policy/', WTA_Consent::sanitize_privacy_url('data:text/html,x'));
        $this->assertSame('/privacy-policy/', WTA_Consent::sanitize_privacy_url(''));
        $this->assertSame(home_url('/legal/'), (function () {
            update_option(WTA_Consent::OPTION, array('privacy_url' => '/legal/'));

            return WTA_Consent::privacy_url();
        })());
    }

    /* ------------------------------------------------------- client config */

    public function test_the_footer_carries_the_settings_as_json() {
        update_option(WTA_Consent::OPTION, array('policy_version' => '7', 'lifetime_days' => 90, 'position' => 'bottom-left'));
        $config = $this->config_from_footer();

        $this->assertSame('7', $config['version']);
        $this->assertSame(90, $config['lifetime']);
        $this->assertSame('wta_consent', $config['cookie']);
        $this->assertSame('bottom-left', $config['position']);
        $this->assertSame(array('necessary', 'analytics', 'marketing'), wp_list_pluck($config['categories'], 'id'));
        $this->assertTrue($config['categories'][0]['required']);
        $this->assertSame(home_url('/privacy-policy/'), $config['privacy']['url']);
    }

    public function test_a_category_can_be_hidden() {
        update_option(WTA_Consent::OPTION, array('marketing_on' => 0));

        $this->assertSame(array('necessary', 'analytics'), wp_list_pluck(WTA_Consent::client_config()['categories'], 'id'));
    }

    public function test_the_inline_json_cannot_close_its_own_script_tag() {
        add_filter('wta_consent_client_config', function ($config) {
            $config['text'] = '</script><script>alert(1)</script> & more';

            return $config;
        });

        $html = $this->footer();

        $this->assertSame(1, substr_count($html, '</script>'), 'Only the closing tag of the config element.');
        $this->assertSame('</script><script>alert(1)</script> & more', $this->config_from_footer()['text']);
    }

    public function test_the_shortcode_renders_a_button_the_script_binds_to() {
        new WTA_Consent();

        $html = do_shortcode('[wta_cookie_settings label="Cookie <b>settings</b>"]');

        $this->assertStringContainsString('<button type="button"', $html);
        $this->assertStringContainsString('data-wta-consent-open', $html);
        $this->assertStringContainsString('Cookie &lt;b&gt;settings&lt;/b&gt;', $html);
    }

    /* ------------------------------------------------------ held scripts */

    public function test_the_helper_holds_an_external_script_until_consent() {
        $html = wta_consent_script('analytics', 'https://example.com/a.js?x=1&y=2', array('async' => true, 'data-x' => 'a"b', 'onload' => 'evil()', 'type' => 'module'));

        $this->assertStringStartsWith('<script type="text/plain" data-wta-consent="analytics" src="https://example.com/a.js?x=1&#038;y=2"', $html);
        $this->assertStringContainsString(' async', $html);
        $this->assertStringContainsString('data-x="a&quot;b"', $html);
        $this->assertStringNotContainsString('onload', $html);
        $this->assertStringNotContainsString('module', $html);
        $this->assertStringEndsWith('></script>', $html);
    }

    public function test_the_helper_holds_inline_code_and_cannot_be_broken_out_of() {
        $html = wta_consent_script('marketing', "fbq('init', 1); </script><script>alert(1)");

        $this->assertStringStartsWith('<script type="text/plain" data-wta-consent="marketing">', $html);
        $this->assertSame(1, substr_count(strtolower($html), '</script>'));
        $this->assertStringContainsString('<\/script', $html);
    }

    public function test_the_helper_returns_nothing_for_a_category_that_needs_no_consent() {
        $this->assertSame('', wta_consent_script('necessary', 'https://example.com/a.js'));
        $this->assertSame('', wta_consent_script('advertising', 'https://example.com/a.js'));
    }

    public function test_the_helper_output_is_filterable() {
        add_filter('wta_consent_script_html', function ($html, $category) {
            return '<!-- ' . $category . ' -->' . $html;
        }, 10, 2);

        $this->assertStringStartsWith('<!-- analytics -->', wta_consent_script('analytics', '/a.js'));
    }

    public function test_an_enqueued_script_can_be_held_back_by_handle() {
        new WTA_Consent();
        add_filter('wta_consent_gated_handles', function () {
            return array('some-tracker' => 'marketing', 'bad' => 'bogus');
        });

        $held = apply_filters('script_loader_tag', "<script type='text/javascript' src='https://example.com/t.js' id='some-tracker-js'></script>", 'some-tracker', 'https://example.com/t.js');

        $this->assertStringContainsString('type="text/plain"', $held);
        $this->assertStringContainsString('data-wta-consent="marketing"', $held);
        $this->assertStringNotContainsString("type='text/javascript'", $held);
        $this->assertStringContainsString("src='https://example.com/t.js'", $held);

        $plain = "<script src='https://example.com/other.js'></script>";
        $this->assertSame($plain, apply_filters('script_loader_tag', $plain, 'other', 'https://example.com/other.js'));
        $this->assertSame($plain, apply_filters('script_loader_tag', $plain, 'bad', 'x'), 'An unknown category is ignored.');
    }

    public function test_handles_are_not_held_back_in_the_admin() {
        new WTA_Consent();
        add_filter('wta_consent_gated_handles', function () {
            return array('some-tracker' => 'analytics');
        });
        set_current_screen('dashboard');

        $tag = "<script src='https://example.com/t.js'></script>";
        $this->assertSame($tag, apply_filters('script_loader_tag', $tag, 'some-tracker', 'x'));
    }

    /* ---------------------------------------------------------- Meta Pixel */

    public function test_the_pixel_is_off_until_an_id_is_set() {
        $this->assertSame('', $this->config_from_footer()['pixel']);
        $this->assertStringNotContainsString('fbevents', $this->head() . $this->footer());

        update_option(WTA_Consent::OPTION, array('meta_pixel_id' => '1234567890'));
        $this->assertSame('1234567890', $this->config_from_footer()['pixel']);

        update_option(WTA_Consent::OPTION, array('meta_pixel_id' => 'abc'));
        $this->assertSame('', WTA_Consent::sanitize(array('meta_pixel_id' => 'abc'))['meta_pixel_id']);
    }

    public function test_the_pixel_loads_only_on_marketing_consent_and_honours_revocation() {
        // Formatting-proof: quotes and whitespace are normalised first. The
        // behaviour itself was exercised in a browser; see the pull request.
        $js = preg_replace('/\s+/', ' ', str_replace('"', "'", file_get_contents(WTA_DIR . 'assets/js/wta-consent.js')));

        $this->assertStringContainsString('https://connect.facebook.net/en_US/fbevents.js', $js);
        $this->assertStringContainsString("window.fbq('consent', 'revoke')", $js);
        $this->assertStringContainsString("window.fbq('consent', 'grant')", $js);
        $this->assertMatchesRegularExpression('/if \(s && s\.marketing\) \{ if \(!pixelLoaded\) \{ loadPixel\(cfg\.pixel\);/', $js, 'loadPixel is only reached for a marketing grant.');
        $this->assertSame(1, substr_count($js, 'loadPixel(cfg.pixel)'), 'One call site.');
        $this->assertStringContainsString('if (!cfg.pixel) { return; }', $js, 'No ID, no pixel.');
    }

    /* ----------------------------------------------------------------- REST */

    public function test_the_endpoint_is_off_while_logging_is_off() {
        update_option('wta_module_consent', 1);

        $this->assertSame(404, $this->rest($this->valid_body())->get_status());
    }

    public function test_a_valid_choice_is_stored_without_personal_data() {
        $this->enable_logging();

        $response = $this->rest($this->valid_body(), array('user-agent' => 'curl/8.0'));
        $this->assertSame(201, $response->get_status());

        $chrome = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
        $this->assertSame(201, $this->rest($this->valid_body(array('id' => '9a1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c91', 'marketing' => true)), array('user-agent' => $chrome))->get_status());

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame(self::UUID, $rows[0]->consent_id);
        $this->assertSame('1', $rows[0]->version);
        $this->assertSame('custom', $rows[0]->choice);
        $this->assertSame('all', $rows[1]->choice);
        $this->assertSame('Chrome', $rows[1]->ua_family);
        $this->assertSame(16, strlen($rows[0]->ip_hash));
        $this->assertStringNotContainsString('203.0.113.7', wp_json_encode($rows));
        $this->assertSame(WTA_Consent_Log::ip_hash('203.0.113.7'), $rows[0]->ip_hash);
        $this->assertSame('Other', $rows[0]->ua_family);
        $this->assertNotContains('curl', array_values((array) $rows[0]), 'No full user agent is stored.');
    }

    /**
     * @dataProvider invalid_bodies
     */
    public function test_invalid_input_is_rejected_and_nothing_is_stored($body) {
        $this->enable_logging();

        $response = $this->rest($body);

        $this->assertSame(400, $response->get_status());
        $this->assertCount(0, $this->rows());
    }

    public static function invalid_bodies() {
        $ok = array('id' => self::UUID, 'version' => '1', 'analytics' => true, 'marketing' => false);

        return array(
            'missing id'          => array(array_diff_key($ok, array('id' => 1))),
            'not a uuid'          => array(array_merge($ok, array('id' => 'abc'))),
            'sql in id'           => array(array_merge($ok, array('id' => "' OR 1=1 --"))),
            'long version'        => array(array_merge($ok, array('version' => str_repeat('1', 21)))),
            'version with markup' => array(array_merge($ok, array('version' => '<b>'))),
            'string boolean'      => array(array_merge($ok, array('analytics' => 'true'))),
            'number boolean'      => array(array_merge($ok, array('marketing' => 1))),
            'missing marketing'   => array(array_diff_key($ok, array('marketing' => 1))),
        );
    }

    public function test_a_request_from_another_site_is_refused() {
        $this->enable_logging();

        $response = $this->rest($this->valid_body(), array('origin' => 'https://evil.example'));
        $this->assertSame(403, $response->get_status());
        $this->assertCount(0, $this->rows());

        $same = $this->rest($this->valid_body(), array('origin' => home_url()));
        $this->assertSame(201, $same->get_status());
    }

    public function test_do_not_track_is_honoured_and_can_be_switched_off() {
        $this->enable_logging();
        $response = $this->rest($this->valid_body(), array('dnt' => '1'));
        $this->assertSame(202, $response->get_status());
        $this->assertFalse($response->get_data()['logged']);
        $this->assertCount(0, $this->rows());

        // Table creation ends the test's transaction on SQLite, so the setting
        // is changed through its filter rather than a second update_option().
        add_filter('wta_consent_settings', function ($settings) {
            $settings['log_respect_dnt'] = 0;

            return $settings;
        });
        $this->assertSame(201, $this->rest($this->valid_body(), array('dnt' => '1'))->get_status());
        $this->assertCount(1, $this->rows());
    }

    public function test_the_rate_limit_is_per_hashed_address() {
        $this->enable_logging();

        for ($i = 0; $i < WTA_Consent_Log::RATE_LIMIT; $i++) {
            $this->assertSame(201, $this->rest($this->valid_body())->get_status(), 'Request ' . ($i + 1));
        }

        $limited = $this->rest($this->valid_body());
        $this->assertSame(429, $limited->get_status());
        $this->assertCount(WTA_Consent_Log::RATE_LIMIT, $this->rows());

        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $this->assertSame(201, $this->rest($this->valid_body())->get_status(), 'Another address is not limited.');
        delete_transient('wta_cl_' . WTA_Consent_Log::ip_hash('198.51.100.9'));
    }

    public function test_the_hash_is_truncated_salted_and_stable() {
        $a = WTA_Consent_Log::ip_hash('203.0.113.7');

        $this->assertSame($a, WTA_Consent_Log::ip_hash('203.0.113.7'));
        $this->assertNotSame($a, WTA_Consent_Log::ip_hash('203.0.113.8'));
        $this->assertSame(16, strlen($a));
        $this->assertNotSame(substr(hash('sha256', '203.0.113.7'), 0, 16), $a, 'Not a bare hash of the address.');
    }

    public function test_the_user_agent_is_reduced_to_a_family() {
        $this->assertSame('Edge', WTA_Consent_Log::ua_family('Mozilla/5.0 Chrome/130 Safari/537.36 Edg/130.0'));
        $this->assertSame('Firefox', WTA_Consent_Log::ua_family('Mozilla/5.0 (X11) Gecko/20100101 Firefox/130.0'));
        $this->assertSame('Safari', WTA_Consent_Log::ua_family('Mozilla/5.0 (Macintosh) AppleWebKit/605 Version/17 Safari/605.1.15'));
        $this->assertSame('Other', WTA_Consent_Log::ua_family('curl/8.0'));
    }

    public function test_the_daily_purge_removes_records_past_13_months() {
        global $wpdb;
        $this->enable_logging();
        WTA_Consent_Log::install();

        WTA_Consent_Log::record(self::UUID, '1', true, true, 'aaaaaaaaaaaaaaaa', 'Chrome');
        WTA_Consent_Log::record('4f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90', '1', false, false, 'bbbbbbbbbbbbbbbb', 'Chrome');
        $wpdb->update(WTA_Consent_Log::table(), array('created_at' => gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS)), array('consent_id' => self::UUID));

        $this->assertSame(1, WTA_Consent_Log::purge());
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('necessary', $rows[0]->choice);
    }

    public function test_the_purge_is_scheduled_only_while_logging_is_on() {
        wp_clear_scheduled_hook(WTA_Consent_Log::CRON);

        $this->enable_logging();
        WTA_Consent_Log::sync_schedule();
        $this->assertNotFalse(wp_next_scheduled(WTA_Consent_Log::CRON));

        update_option(WTA_Consent::OPTION, array('log_enabled' => 0));
        WTA_Consent_Log::sync_schedule();
        $this->assertFalse(wp_next_scheduled(WTA_Consent_Log::CRON));
    }

    /* ------------------------------------------------------- privacy tools */

    public function test_the_exporter_and_eraser_are_registered() {
        $this->assertArrayHasKey('nyuchi-travel-addons-consent', apply_filters('wp_privacy_personal_data_exporters', array()));
        $this->assertArrayHasKey('nyuchi-travel-addons-consent', apply_filters('wp_privacy_personal_data_erasers', array()));
    }

    public function test_export_and_erase_work_on_the_ids_a_site_maps_to_an_email() {
        $this->enable_logging();
        WTA_Consent_Log::install();
        WTA_Consent_Log::record(self::UUID, '1', true, false, 'aaaaaaaaaaaaaaaa', 'Firefox');
        WTA_Consent_Log::record('4f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90', '1', true, true, 'bbbbbbbbbbbbbbbb', 'Chrome');

        add_filter('wta_consent_ids_for_email', function ($ids, $email) {
            return 'visitor@example.com' === $email ? array(self::UUID, 'not-a-uuid') : $ids;
        }, 10, 2);

        $none = WTA_Consent_Log::export('other@example.com');
        $this->assertSame(array(), $none['data']);
        $this->assertTrue($none['done']);

        $export = WTA_Consent_Log::export('visitor@example.com');
        $this->assertCount(1, $export['data']);
        $values = wp_list_pluck($export['data'][0]['data'], 'value', 'name');
        $this->assertSame(self::UUID, $values['Consent ID']);
        $this->assertSame('Allowed', $values['Analytics cookies']);
        $this->assertSame('Declined', $values['Marketing cookies']);
        $this->assertSame('Firefox', $values['Browser']);
        $this->assertArrayNotHasKey('IP', $values);

        $this->assertSame(0, WTA_Consent_Log::erase('other@example.com')['items_removed']);
        $this->assertCount(2, $this->rows());

        $erased = WTA_Consent_Log::erase('visitor@example.com');
        $this->assertSame(1, $erased['items_removed']);
        $this->assertFalse($erased['items_retained']);
        $this->assertTrue($erased['done']);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('4f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90', $rows[0]->consent_id);
    }

    /* ------------------------------------------------------------ conflicts */

    public function test_conflicting_consent_plugins_are_detected_from_the_active_list() {
        add_filter('pre_option_active_plugins', function () {
            return array(WTA_TEST_PLUGIN, 'complianz-gdpr/complianz-gpdr.php', 'cookie-law-info/cookie-law-info.php');
        }, 20);

        $ids = wp_list_pluck(WTA_Consent::detect_conflicts(), 'id');

        $this->assertContains('complianz', $ids);
        $this->assertContains('cookieyes', $ids);
        $this->assertNotContains('usercentrics', $ids);
    }

    public function test_usercentrics_and_zaraz_are_detected_in_the_page_html() {
        $html = '<script id="usercentrics-cmp" src="https://app.usercentrics.eu/browser-ui/latest/loader.js"></script>'
            . '<script src="/cdn-cgi/zaraz/i.js"></script>';

        $found = WTA_Consent::detect_conflicts($html);
        $ids   = wp_list_pluck($found, 'id');

        $this->assertContains('usercentrics', $ids);
        $this->assertContains('zaraz', $ids);
        $this->assertSame(array(), WTA_Consent::detect_conflicts('<html><body>Plain page</body></html>'));
    }

    public function test_the_live_page_scan_stores_what_it_found() {
        WTA_Test_HTTP::on(home_url('/'), WTA_Test_HTTP::response('<script src="https://app.usercentrics.eu/x.js"></script>'));

        $result = WTA_Consent::scan_live_page();

        $this->assertTrue($result['ok']);
        $this->assertSame(array('usercentrics'), wp_list_pluck($result['conflicts'], 'id'));
        $this->assertSame($result, WTA_Consent::last_scan());
    }

    public function test_a_failed_scan_says_so() {
        WTA_Test_HTTP::on(home_url('/'), WTA_Test_HTTP::response('nope', 503));

        $result = WTA_Consent::scan_live_page();

        $this->assertFalse($result['ok']);
        $this->assertSame('HTTP 503', $result['error']);
    }

    /* ------------------------------------------------------------ ability */

    public function test_the_status_ability_is_read_only_and_needs_manage_options() {
        if (!function_exists('wp_get_ability')) {
            $this->markTestSkipped('The Abilities API needs WordPress 6.9 or newer.');
        }

        $ability = wp_get_ability('nyuchi-travel/consent-status');
        $this->assertInstanceOf(WP_Ability::class, $ability);

        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $this->setExpectedIncorrectUsage('WP_Ability::execute');
        $this->assertWPError($ability->execute(array()), 'An editor may not read site configuration.');

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $status = $ability->execute(array());

        $this->assertIsArray($status);
        $this->assertFalse($status['module_enabled']);
        $this->assertSame('1', $status['settings']['policy_version']);
        $this->assertSame(180, $status['settings']['lifetime_days']);
        $this->assertSame(array('necessary', 'analytics', 'marketing'), $status['settings']['categories']);
        $this->assertSame('denied', $status['consent_mode']['default']);
        $this->assertSame(500, $status['consent_mode']['wait_for_update']);
        $this->assertStringEndsWith('assets/js/wta-consent.js', $status['scripts']['js']);
        $this->assertStringEndsWith('assets/css/wta-consent.css', $status['scripts']['css']);
        $this->assertSame(0, $status['records']['total']);
        $this->assertFalse($status['records']['logging']);
    }

    public function test_the_status_counts_records_by_choice_over_the_last_30_days() {
        global $wpdb;
        $this->enable_logging();
        WTA_Consent_Log::install();

        WTA_Consent_Log::record(self::UUID, '1', true, true, 'aaaaaaaaaaaaaaaa', 'Chrome');
        WTA_Consent_Log::record('4f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90', '1', true, true, 'aaaaaaaaaaaaaaab', 'Chrome');
        WTA_Consent_Log::record('5f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90', '1', false, false, 'aaaaaaaaaaaaaaac', 'Chrome');
        WTA_Consent_Log::record('6f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90', '1', true, false, 'aaaaaaaaaaaaaaad', 'Chrome');
        WTA_Consent_Log::record('7f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90', '1', true, true, 'aaaaaaaaaaaaaaae', 'Chrome');
        $wpdb->update(WTA_Consent_Log::table(), array('created_at' => gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS)), array('consent_id' => '7f1c2a9e-8b7d-4c6a-9e51-0d2f6a7b8c90'));

        $status = WTA_Consent::status();

        $this->assertTrue($status['module_enabled']);
        $this->assertTrue($status['records']['logging']);
        $this->assertSame(4, $status['records']['total'], 'The 40 day old record is outside the window.');
        $this->assertSame(array('all' => 2, 'necessary' => 1, 'custom' => 1), $status['records']['by_choice']);
        $this->assertSame(3, $status['records']['analytics_granted']);
        $this->assertSame(2, $status['records']['marketing_granted']);
    }

    public function test_the_status_warns_about_a_pixel_that_can_never_load() {
        update_option('wta_module_consent', 1);
        update_option(WTA_Consent::OPTION, array('meta_pixel_id' => '1234567890', 'marketing_on' => 0));

        $this->assertNotEmpty(array_filter(WTA_Consent::status()['warnings'], function ($w) {
            return false !== strpos($w, 'Pixel');
        }));
    }

    /* -------------------------------------------------------------- admin */

    public function test_the_consent_tab_renders_the_settings_and_the_preview() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $_GET['tab'] = 'consent';

        ob_start();
        (new WTA_Admin(WP_Travel_Addons::instance()))->render();
        $html = ob_get_clean();
        unset($_GET['tab']);

        foreach (array('wta_consent[text]', 'wta_consent[privacy_url]', 'wta_consent[policy_version]', 'wta_consent[lifetime_days]', 'wta_consent[meta_pixel_id]', 'wta_consent[log_enabled]', 'name="module_consent"', 'name="wta_consent_nonce"', 'id="wta-consent-preview"') as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertStringContainsString('"preview":true', $html);
        $this->assertStringContainsString('not legal advice', $html);
    }
}
