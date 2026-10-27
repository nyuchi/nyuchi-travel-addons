<?php
/**
 * Clean-up of WP Travel's old /sw.js. Every test works on a file in a scratch
 * directory; the real ABSPATH is never touched.
 *
 * @package WPTravelAddons\Tests
 */

class PwaLegacyTest extends WP_UnitTestCase {

    /** @var string */
    protected $dir;

    /** @var string */
    protected $file;

    /** The opening of the worker WP Travel writes, as measured on the live site. */
    const OLD_WORKER = <<<'JS'
const staticWPTravel = "wp-travel";
const assets = ["/"];
self.addEventListener("install", installEvent => {
  installEvent.waitUntil(caches.open(staticWPTravel).then(cache => { cache.addAll(assets) }))
});
self.addEventListener("fetch", fetchEvent => {
  fetchEvent.respondWith(caches.match(fetchEvent.request).then(res => res || fetch(fetchEvent.request)))
});
JS;

    public function set_up() {
        parent::set_up();

        $this->dir  = sys_get_temp_dir() . '/wta-legacy-' . uniqid();
        $this->file = $this->dir . '/sw.js';
        mkdir($this->dir);

        add_filter('wta_pwa_legacy_sw_path', array($this, 'scratch_path'));
        delete_option(WTA_PWA_Legacy::OPTION);
    }

    public function tear_down() {
        remove_filter('wta_pwa_legacy_sw_path', array($this, 'scratch_path'));
        remove_all_filters('filesystem_method');
        remove_all_filters('wta_pwa_legacy_cleanup');
        delete_option(WTA_PWA_Legacy::OPTION);
        delete_option('wp_travel_settings');
        delete_option(WTA_PWA::SERVED_OPTION);
        wp_set_current_user(0);

        if (is_file($this->file)) {
            unlink($this->file);
        }
        rmdir($this->dir);

        parent::tear_down();
    }

    public function scratch_path() {
        return $this->file;
    }

    /* ------------------------------------------------------------ detection */

    public function test_the_signature_matches_wp_travel_s_worker_only() {
        $this->assertTrue(WTA_PWA_Legacy::has_signature(self::OLD_WORKER));
        $this->assertTrue(WTA_PWA_Legacy::has_signature("const  staticWPTravel='wp-travel';"));
        $this->assertFalse(WTA_PWA_Legacy::has_signature("self.addEventListener('fetch', () => {});"));
        $this->assertFalse(WTA_PWA_Legacy::has_signature('const staticOther = "wp-travel";'));
        $this->assertFalse(WTA_PWA_Legacy::has_signature(''));
    }

    public function test_the_default_path_is_in_abspath_and_is_filterable() {
        remove_filter('wta_pwa_legacy_sw_path', array($this, 'scratch_path'));
        $this->assertSame(ABSPATH . 'sw.js', WTA_PWA_Legacy::sw_path());
        add_filter('wta_pwa_legacy_sw_path', array($this, 'scratch_path'));
        $this->assertSame($this->file, WTA_PWA_Legacy::sw_path());
    }

    /* ---------------------------------------------------------- replacement */

    public function test_the_old_worker_is_replaced_by_the_kill_switch() {
        file_put_contents($this->file, self::OLD_WORKER);

        $this->assertSame('legacy', WTA_PWA_Legacy::status()['state']);

        $result = WTA_PWA_Legacy::clean();

        $this->assertSame('cleaned', $result['state']);
        $this->assertSame(WTA_PWA_Legacy::kill_script(), file_get_contents($this->file));
        $this->assertFalse(WTA_PWA_Legacy::has_signature(file_get_contents($this->file)));
        $this->assertSame('cleaned', WTA_PWA_Legacy::status()['state']);
        $this->assertTrue(WTA_PWA_Legacy::status()['cleaned']);

        $script = file_get_contents($this->file);
        $this->assertStringContainsString('caches.delete', $script);
        $this->assertStringContainsString('registration.unregister()', $script);
        $this->assertStringContainsString('c.navigate(c.url)', $script);
    }

    public function test_an_unrelated_sw_js_is_never_overwritten() {
        $mine = "self.addEventListener('fetch', function () {}); // someone else's worker\n";
        file_put_contents($this->file, $mine);

        $result = WTA_PWA_Legacy::clean();

        $this->assertSame('unrelated', $result['state']);
        $this->assertSame($mine, file_get_contents($this->file));
        $this->assertSame('unrelated', WTA_PWA_Legacy::status()['state']);
    }

    public function test_no_file_is_not_an_error_and_nothing_is_created() {
        $result = WTA_PWA_Legacy::clean();

        $this->assertSame('none', $result['state']);
        $this->assertFileDoesNotExist($this->file);
    }

    public function test_the_worker_is_left_alone_while_wp_travel_s_pwa_is_on() {
        update_option('wp_travel_settings', array('enable_pwa' => 'yes'));
        file_put_contents($this->file, self::OLD_WORKER);

        $result = WTA_PWA_Legacy::clean();

        $this->assertSame('pwa_enabled', $result['state']);
        $this->assertSame(self::OLD_WORKER, file_get_contents($this->file));
        $this->assertSame('pwa_enabled', WTA_PWA_Legacy::status()['state']);
    }

    public function test_an_already_replaced_file_is_recognised() {
        file_put_contents($this->file, WTA_PWA_Legacy::kill_script());

        $this->assertSame('cleaned', WTA_PWA_Legacy::clean()['state']);
        $this->assertSame(WTA_PWA_Legacy::kill_script(), file_get_contents($this->file));
    }

    public function test_a_file_that_cannot_be_written_is_reported_with_its_path() {
        file_put_contents($this->file, self::OLD_WORKER);
        // Anything but "direct" means WordPress would need credentials.
        add_filter('filesystem_method', function () {
            return 'ftpext';
        });

        $result = WTA_PWA_Legacy::clean();

        $this->assertSame('not_writable', $result['state']);
        $this->assertSame(self::OLD_WORKER, file_get_contents($this->file), 'Nothing was written.');
        $this->assertSame('not_writable', WTA_PWA_Legacy::status()['state']);

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        ob_start();
        (new WTA_PWA_Legacy())->notice();
        $notice = ob_get_clean();

        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString(esc_html($this->file), $notice, 'The exact path is shown.');
        $this->assertStringContainsString(esc_textarea(WTA_PWA_Legacy::kill_script()), $notice, 'The replacement script is shown.');
        $this->assertStringContainsString('Remove old service worker', $notice);
        $this->assertStringContainsString('_wpnonce=', $notice);
    }

    /* ------------------------------------------------------------- the run */

    public function test_it_runs_on_admin_init_once_and_again_only_when_the_file_changes() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        file_put_contents($this->file, self::OLD_WORKER);
        $legacy = new WTA_PWA_Legacy();

        $legacy->maybe_run();
        $first = get_option(WTA_PWA_Legacy::OPTION);
        $this->assertSame('cleaned', $first['state']);
        $this->assertSame($this->file, $first['path']);
        $this->assertSame(WTA_VERSION, $first['version']);

        // Make the recorded time distinguishable, then run again unchanged.
        update_option(WTA_PWA_Legacy::OPTION, array_merge($first, array('time' => 1)));
        $legacy->maybe_run();
        $this->assertSame(1, get_option(WTA_PWA_Legacy::OPTION)['time'], 'An unchanged file is not looked at again.');

        // WP Travel writes its worker again (PWA toggled on and off).
        file_put_contents($this->file, self::OLD_WORKER . "\n// again\n");
        $legacy->maybe_run();
        $this->assertGreaterThan(1, get_option(WTA_PWA_Legacy::OPTION)['time']);
        $this->assertSame(WTA_PWA_Legacy::kill_script(), file_get_contents($this->file));
    }

    public function test_it_does_not_run_for_users_who_cannot_manage_options() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        file_put_contents($this->file, self::OLD_WORKER);

        (new WTA_PWA_Legacy())->maybe_run();

        $this->assertSame(self::OLD_WORKER, file_get_contents($this->file));
    }

    public function test_a_filter_switches_the_whole_clean_up_off() {
        add_filter('wta_pwa_legacy_cleanup', '__return_false');
        file_put_contents($this->file, self::OLD_WORKER);

        $this->assertSame('disabled', WTA_PWA_Legacy::status()['state']);

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $legacy = new WTA_PWA_Legacy();
        $legacy->maybe_run();

        $this->assertSame(self::OLD_WORKER, file_get_contents($this->file));
        $this->assertFalse(has_action('admin_post_' . WTA_PWA_Legacy::ACTION, array($legacy, 'handle_remove')));
    }

    public function test_the_success_notice_reports_the_result() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        file_put_contents($this->file, self::OLD_WORKER);
        WTA_PWA_Legacy::clean();

        ob_start();
        (new WTA_PWA_Legacy())->notice();
        $notice = ob_get_clean();

        $this->assertStringContainsString('notice-success', $notice);
        $this->assertStringContainsString('Old WP Travel service worker removed', $notice);
    }

    /* ------------------------------------------------------ manual button */

    public function test_the_button_needs_manage_options() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));

        $this->expectException(WPDieException::class);
        (new WTA_PWA_Legacy())->handle_remove();
    }

    public function test_the_button_needs_a_valid_nonce() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        file_put_contents($this->file, self::OLD_WORKER);
        $_REQUEST['_wpnonce'] = 'bogus';

        try {
            (new WTA_PWA_Legacy())->handle_remove();
            $this->fail('A bad nonce must stop the request.');
        } catch (WPDieException $e) {
            $this->assertSame(self::OLD_WORKER, file_get_contents($this->file));
        } finally {
            unset($_REQUEST['_wpnonce']);
        }
    }

    public function test_the_button_url_carries_a_nonce_for_the_action() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $url = WTA_PWA_Legacy::remove_url();

        $this->assertStringContainsString('action=' . WTA_PWA_Legacy::ACTION, $url);
        parse_str((string) wp_parse_url(html_entity_decode($url), PHP_URL_QUERY), $query);
        $this->assertSame(1, wp_verify_nonce($query['_wpnonce'], WTA_PWA_Legacy::ACTION));
    }

    /* ------------------------------------------------------ retired worker */

    public function test_the_retired_worker_url_answers_with_the_kill_switch() {
        $response = WTA_PWA_Legacy::retired_response();

        $this->assertSame(WTA_PWA_Legacy::kill_script(), $response['body']);
        $this->assertSame('/', $response['headers']['Service-Worker-Allowed']);
        $this->assertStringContainsString('no-cache', $response['headers']['Cache-Control']);
    }
}
