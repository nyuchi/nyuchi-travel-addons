<?php
/**
 * SafariBookings reviews script: loaded from WordPress on the public site.
 *
 * @package WPTravelAddons\Tests
 */

class SafariBookingsTest extends WP_UnitTestCase {

    public function test_it_defaults_to_the_known_widget_address() {
        $this->assertSame(WTA_SafariBookings::SRC, WTA_SafariBookings::src());
    }

    public function test_the_address_is_filterable_and_https_only() {
        add_filter('wta_safaribookings_src', function () {
            return 'http://example.org/x.js';
        });
        $this->assertSame('', WTA_SafariBookings::src());
        remove_all_filters('wta_safaribookings_src');
    }

    public function test_it_enqueues_asynchronously_in_the_footer() {
        new WTA_SafariBookings();
        $this->go_to(home_url('/'));
        do_action('wp_enqueue_scripts');

        $this->assertTrue(wp_script_is(WTA_SafariBookings::HANDLE, 'enqueued'));
        $this->assertSame('async', wp_scripts()->get_data(WTA_SafariBookings::HANDLE, 'strategy'));
    }

    public function test_it_is_not_loaded_in_wp_admin() {
        set_current_screen('dashboard');
        $this->assertFalse(WTA_SafariBookings::should_load());
    }
}
