<?php
/**
 * Sign-in page styling.
 *
 * @package WPTravelAddons\Tests
 */

class LoginStyleTest extends WP_UnitTestCase {

    public function tear_down() {
        remove_all_filters('wta_login_style_css');
        remove_all_filters('wta_login_style_js');
        wp_dequeue_script(WTA_Login_Style::SCRIPT_HANDLE);
        wp_deregister_script(WTA_Login_Style::SCRIPT_HANDLE);
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

    public function test_the_email_code_field_is_drawn_as_separate_boxes() {
        $style = new WTA_Login_Style();

        $this->assertStringContainsString('.wta-otp input', $style->css());
        $this->assertStringContainsString('input[autocomplete="one-time-code"]', $style->script());
        $this->assertStringContainsString('requestSubmit', $style->script());
    }

    public function test_the_code_script_is_enqueued_for_the_workos_page_only() {
        $style = new WTA_Login_Style();
        $this->assertNotFalse(has_action('workos_authkit_enqueue_assets', array($style, 'enqueue_script')));
        $this->assertFalse(has_action('login_enqueue_scripts', array($style, 'enqueue_script')));

        $style->enqueue_script();
        $this->assertTrue(wp_script_is(WTA_Login_Style::SCRIPT_HANDLE, 'enqueued'));
    }

    public function test_the_script_can_be_turned_off_with_a_filter() {
        add_filter('wta_login_style_js', '__return_empty_string');
        wp_dequeue_script(WTA_Login_Style::SCRIPT_HANDLE);
        wp_deregister_script(WTA_Login_Style::SCRIPT_HANDLE);

        (new WTA_Login_Style())->enqueue_script();

        $this->assertFalse(wp_script_is(WTA_Login_Style::SCRIPT_HANDLE, 'enqueued'));
    }

    public function test_the_css_can_be_changed_with_a_filter() {
        add_filter('wta_login_style_css', function () {
            return 'body{color:red}';
        });

        $this->assertSame('body{color:red}', (new WTA_Login_Style())->css());
    }
}
