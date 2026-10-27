<?php
/**
 * The compat module's fix for WT Widgets' slick scripts, which the vendor
 * registers without the jQuery dependency.
 *
 * @package WPTravelAddons\Tests
 */

class SlickJqueryTest extends WP_UnitTestCase {

    public function tear_down() {
        foreach (WTA_Compat::SLICK_HANDLES as $handle) {
            wp_deregister_script($handle);
        }
        wp_deregister_script('wta-other');
        remove_all_filters('wta_compat_slick_jquery_fix');
        parent::tear_down();
    }

    protected function run_fix() {
        (new WTA_Compat())->fix_slick_jquery();
    }

    protected function deps($handle) {
        return wp_scripts()->registered[$handle]->deps;
    }

    public function test_adds_jquery_to_slick_registered_without_deps() {
        foreach (WTA_Compat::SLICK_HANDLES as $handle) {
            wp_register_script($handle, 'https://example.test/' . $handle . '.js', array(), true);
        }

        $this->run_fix();

        foreach (WTA_Compat::SLICK_HANDLES as $handle) {
            $this->assertContains('jquery', $this->deps($handle), $handle);
        }
    }

    public function test_jquery_resolves_before_slick() {
        wp_register_script('slick-min-js', 'https://example.test/slick.min.js', array(), true);

        $this->run_fix();

        $scripts = new WP_Scripts();
        $scripts->registered['slick-min-js'] = wp_scripts()->registered['slick-min-js'];
        $scripts->registered['jquery']       = wp_scripts()->registered['jquery'];
        $scripts->registered['jquery-core']  = wp_scripts()->registered['jquery-core'];
        $scripts->registered['jquery-migrate'] = wp_scripts()->registered['jquery-migrate'];
        $scripts->all_deps(array('slick-min-js'));

        $this->assertLessThan(
            array_search('slick-min-js', $scripts->to_do, true),
            array_search('jquery', $scripts->to_do, true)
        );
    }

    public function test_leaves_existing_jquery_dependency_alone() {
        wp_register_script('slick-min-js', 'https://example.test/slick.min.js', array('jquery', 'other'), '1.0');

        $this->run_fix();

        $this->assertSame(array('jquery', 'other'), $this->deps('slick-min-js'));
    }

    public function test_does_nothing_when_handles_are_absent_and_leaves_other_scripts() {
        wp_register_script('wta-other', 'https://example.test/other.js', array(), '1.0');

        $this->run_fix();

        foreach (WTA_Compat::SLICK_HANDLES as $handle) {
            $this->assertArrayNotHasKey($handle, wp_scripts()->registered);
        }
        $this->assertSame(array(), $this->deps('wta-other'));
    }

    public function test_filter_can_switch_the_fix_off() {
        wp_register_script('slick-min-js', 'https://example.test/slick.min.js', array(), true);
        add_filter('wta_compat_slick_jquery_fix', '__return_false');

        $this->run_fix();

        $this->assertSame(array(), $this->deps('slick-min-js'));
    }

    public function test_is_hooked_late_and_before_printing() {
        $compat = new WTA_Compat();

        $this->assertSame(100, has_action('wp_enqueue_scripts', array($compat, 'fix_slick_jquery')));
        $this->assertSame(1, has_action('wp_print_scripts', array($compat, 'fix_slick_jquery')));
    }
}
