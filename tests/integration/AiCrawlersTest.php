<?php
/**
 * The AI crawler policy appended to robots.txt.
 *
 * @package WPTravelAddons\Tests
 */

class AiCrawlersTest extends WP_UnitTestCase {

    const BASE = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

    public function tear_down() {
        remove_all_filters('wta_ai_search_bots');
        remove_all_filters('wta_ai_training_bots');
        remove_all_filters('wta_ai_content_signal');
        parent::tear_down();
    }

    protected function robots($output = self::BASE, $public = true) {
        return (new WTA_AI_Crawlers())->filter_robots($output, $public);
    }

    public function test_search_bots_are_allowed_and_training_bots_disallowed() {
        $out = $this->robots();

        $this->assertMatchesRegularExpression('/User-agent: OAI-SearchBot\n(?:User-agent: [^\n]+\n)*Allow: \//', $out);
        $this->assertMatchesRegularExpression('/User-agent: GPTBot\n(?:User-agent: [^\n]+\n)*Disallow: \//', $out);
        $this->assertMatchesRegularExpression('/User-agent: ClaudeBot\n(?:User-agent: [^\n]+\n)*Disallow: \//', $out);
        $this->assertStringContainsString('User-agent: Claude-SearchBot', $out);
    }

    public function test_content_signal_sits_in_the_wildcard_group() {
        $out = $this->robots();

        $this->assertStringContainsString("User-agent: *\nContent-Signal: search=yes, ai-input=yes, ai-train=no\nDisallow: /wp-admin/", $out);
    }

    public function test_existing_rules_are_kept() {
        $out = $this->robots();

        $this->assertStringContainsString('Allow: /wp-admin/admin-ajax.php', $out);
    }

    public function test_sitemap_added_once() {
        $this->assertSame(1, substr_count($this->robots(), 'Sitemap:'));
        $this->assertSame(1, substr_count($this->robots(self::BASE . "\nSitemap: https://example.test/sitemap_index.xml\n"), 'Sitemap:'));
    }

    public function test_untouched_when_the_site_is_hidden() {
        $this->assertSame(self::BASE, $this->robots(self::BASE, false));
    }

    public function test_a_bot_in_both_lists_is_treated_as_training() {
        add_filter('wta_ai_search_bots', function ($bots) {
            $bots[] = 'GPTBot';
            return $bots;
        });

        $out = $this->robots();

        $this->assertSame(1, substr_count($out, 'User-agent: GPTBot'));
        $this->assertStringNotContainsString("User-agent: GPTBot\nAllow: /", $out);
    }

    public function test_bot_names_are_cleaned() {
        add_filter('wta_ai_training_bots', function () {
            return array("Bad\nBot", 'Real-Bot');
        });

        $out = $this->robots();

        $this->assertStringContainsString('User-agent: BadBot', $out);
        $this->assertStringContainsString('User-agent: Real-Bot', $out);
    }

    public function test_the_content_signal_can_be_changed_with_a_filter() {
        add_filter('wta_ai_content_signal', function () {
            return 'search=yes, ai-input=no, ai-train=no';
        });

        $this->assertStringContainsString('Content-Signal: search=yes, ai-input=no, ai-train=no', $this->robots());
    }

    public function test_an_empty_signal_writes_nothing_to_robots() {
        add_filter('wta_ai_content_signal', '__return_empty_string');

        $this->assertStringNotContainsString('Content-Signal:', $this->robots());
    }

    public function test_the_header_hook_is_registered() {
        $module = new WTA_AI_Crawlers();

        $this->assertNotFalse(has_action('send_headers', array($module, 'send_content_signal')));
    }
}
