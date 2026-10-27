<?php
/**
 * The hero video fallback: no dead play button on phones that refuse autoplay.
 *
 * @package WPTravelAddons\Tests
 */

class HeroVideoTest extends WP_UnitTestCase {

    public function tear_down() {
        remove_all_filters('wta_compat_hero_video_fallback');
        parent::tear_down();
    }

    protected function output() {
        ob_start();
        (new WTA_Compat())->hero_video_fallback();
        return ob_get_clean();
    }

    public function test_prints_css_and_script() {
        $out = $this->output();

        $this->assertStringContainsString('id="wta-hero-video-css"', $out);
        $this->assertStringContainsString('-webkit-media-controls-start-playback-button', $out);
        $this->assertStringContainsString('id="wta-hero-video"', $out);
        $this->assertStringContainsString('video.ie-hero-media', $out);
    }

    public function test_script_falls_back_when_play_is_refused() {
        $js = WTA_Compat::hero_video_js();

        $this->assertStringContainsString('p.catch(drop)', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('saveData', $js);
    }

    public function test_filter_switches_it_off() {
        add_filter('wta_compat_hero_video_fallback', '__return_false');

        $this->assertSame('', $this->output());
    }
}
