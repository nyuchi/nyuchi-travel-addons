<?php
/**
 * Sign-in page styling.
 *
 * @package WPTravelAddons\Tests
 */

class LoginStyleTest extends WP_UnitTestCase {

    public function tear_down() {
        remove_all_filters('wta_login_style_css');
        wp_dequeue_style(WTA_Login_Style::HANDLE);
        wp_deregister_style(WTA_Login_Style::HANDLE);
        parent::tear_down();
    }

    public function test_it_hooks_the_workos_page_and_the_standard_login_form() {
        $style = new WTA_Login_Style();

        $this->assertNotFalse(has_action('workos_authkit_enqueue_assets', array($style, 'enqueue')));
        $this->assertNotFalse(has_action('login_enqueue_scripts', array($style, 'enqueue')));
    }

    public function test_it_enqueues_the_brand_css_inline() {
        (new WTA_Login_Style())->enqueue();

        $this->assertTrue(wp_style_is(WTA_Login_Style::HANDLE, 'enqueued'));
        $inline = wp_styles()->get_data(WTA_Login_Style::HANDLE, 'after');
        $this->assertStringContainsString('#F4EFE6', implode('', (array) $inline));
        $this->assertStringContainsString('Instrument Sans', implode('', (array) $inline));
    }

    public function test_the_css_can_be_changed_with_a_filter() {
        add_filter('wta_login_style_css', function () {
            return 'body{color:red}';
        });

        $this->assertSame('body{color:red}', (new WTA_Login_Style())->css());
    }
}
