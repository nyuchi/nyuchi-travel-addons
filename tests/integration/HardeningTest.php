<?php
/**
 * Login hardening: the REST user list and XML-RPC.
 *
 * @package WPTravelAddons\Tests
 */

class HardeningTest extends WP_UnitTestCase {

    protected $hardening;

    public function set_up() {
        parent::set_up();
        $this->hardening = new WTA_Hardening();
    }

    public function tear_down() {
        remove_all_filters('wta_hardening_hide_rest_users');
        remove_all_filters('wta_hardening_close_xmlrpc');
        remove_all_filters('xmlrpc_enabled');
        remove_all_filters('xmlrpc_methods');
        remove_all_filters('wp_headers');
        remove_all_filters('rest_endpoints');
        wp_set_current_user(0);
        parent::tear_down();
    }

    protected function routes() {
        return array(
            '/wp/v2/users'              => array(),
            '/wp/v2/users/(?P<id>[\d]+)' => array(),
            '/wp/v2/users/me'           => array(),
            '/wp/v2/posts'              => array(),
        );
    }

    public function test_visitors_lose_the_user_routes_but_keep_the_rest() {
        $out = $this->hardening->filter_rest_endpoints($this->routes());

        $this->assertArrayNotHasKey('/wp/v2/users', $out);
        $this->assertArrayNotHasKey('/wp/v2/users/(?P<id>[\d]+)', $out);
        $this->assertArrayHasKey('/wp/v2/users/me', $out);
        $this->assertArrayHasKey('/wp/v2/posts', $out);
    }

    public function test_an_administrator_still_sees_the_user_routes() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));

        $this->assertArrayHasKey('/wp/v2/users', $this->hardening->filter_rest_endpoints($this->routes()));
    }

    public function test_the_user_routes_can_be_left_open_with_a_filter() {
        add_filter('wta_hardening_hide_rest_users', '__return_false');

        $this->assertArrayHasKey('/wp/v2/users', $this->hardening->filter_rest_endpoints($this->routes()));
    }

    public function test_xmlrpc_is_closed_and_the_pingback_header_dropped() {
        $this->assertFalse(apply_filters('xmlrpc_enabled', true));
        $this->assertSame(array(), apply_filters('xmlrpc_methods', array('wp.getUsersBlogs' => 'x')));
        $this->assertArrayNotHasKey('X-Pingback', $this->hardening->drop_pingback_header(array('X-Pingback' => 'x', 'Link' => 'y')));
    }

    public function test_the_llms_module_advertises_the_files_in_link_headers() {
        $links = implode("\n", (new WTA_LLMS())->link_headers());

        $this->assertStringContainsString('/llms.txt>; rel="alternate"', $links);
        $this->assertStringContainsString('/llms-full.txt>', $links);
        $this->assertStringContainsString('/sitemap_index.xml>; rel="sitemap"', $links);
    }
}
