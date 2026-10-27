<?php
/**
 * /llms.txt and /llms-full.txt: every trip with its facts, grouped by country,
 * built from the site and cached.
 *
 * WP Travel is not installed in the test site: the trip post type and the
 * destination taxonomy are registered here under the slugs the plugin expects.
 *
 * @package WPTravelAddons\Tests
 */

class LlmsTest extends WP_UnitTestCase {

    const TAX = 'travel_locations';

    protected $region;
    protected $tanzania;
    protected $kenya;

    public function set_up() {
        parent::set_up();

        if (!post_type_exists(WTA_Trip::post_type())) {
            register_post_type(WTA_Trip::post_type(), array('public' => true));
        }

        if (!taxonomy_exists(self::TAX)) {
            // Same arguments as TermFaqTest: whichever test runs first registers
            // the taxonomy for the rest of the run, and that one needs REST.
            register_taxonomy(self::TAX, WTA_Trip::post_type(), array(
                'hierarchical' => true,
                'public'       => true,
                'show_in_rest' => true,
            ));
        }

        $this->region   = self::factory()->term->create(array('taxonomy' => self::TAX, 'name' => 'East Africa'));
        $this->tanzania = self::factory()->term->create(array('taxonomy' => self::TAX, 'name' => 'Tanzania', 'parent' => $this->region));
        $this->kenya    = self::factory()->term->create(array('taxonomy' => self::TAX, 'name' => 'Kenya', 'parent' => $this->region));

        // The module is off by default, so nothing has constructed it: do it here
        // to register the hooks that flush the cache when content changes.
        new WTA_LLMS();

        WTA_LLMS::flush();
    }

    public function tear_down() {
        remove_all_filters('wta_llms_summary');
        remove_all_filters('wta_llms_notes');
        remove_all_filters('wta_llms_currency');
        WTA_LLMS::flush();
        parent::tear_down();
    }

    protected function trip($title, $term, $meta = array()) {
        $id = self::factory()->post->create(array(
            'post_type'   => WTA_Trip::post_type(),
            'post_status' => 'publish',
            'post_title'  => $title,
        ));

        wp_set_object_terms($id, array((int) $term), self::TAX);

        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }

        return $id;
    }

    public function test_request_kind_matches_only_the_two_files() {
        $wp = new stdClass();

        $wp->request = 'llms.txt';
        $this->assertSame('index', WTA_LLMS::request_kind($wp));

        $wp->request = 'llms-full.txt/';
        $this->assertSame('full', WTA_LLMS::request_kind($wp));

        $wp->request = 'robots.txt';
        $this->assertSame('', WTA_LLMS::request_kind($wp));
    }

    public function test_the_index_names_the_site_and_lists_trips_by_country() {
        $this->trip('Serengeti Migration Safari', $this->tanzania, array('wp_travel_trip_duration' => 7, 'wp_travel_trip_duration_night' => 6, 'wp_travel_trip_price' => '14200'));
        $this->trip('Masai Mara Classic', $this->kenya, array('wp_travel_trip_duration' => 5, 'wp_travel_trip_price' => '6100'));

        $out = WTA_LLMS::build_index();

        $this->assertStringStartsWith('# ' . get_bloginfo('name'), $out);
        $this->assertStringContainsString('### Kenya', $out);
        $this->assertStringContainsString('### Tanzania', $out);
        $this->assertLessThan(strpos($out, '### Tanzania'), strpos($out, '### Kenya'), 'countries are alphabetical');
        $this->assertStringContainsString('7 days / 6 nights', $out);
        $this->assertStringContainsString('from USD 14,200 per person sharing', $out);
        $this->assertStringContainsString('5 days', $out);
    }

    public function test_an_unpriced_trip_is_listed_without_a_price() {
        $this->trip('Quote Only Safari', $this->tanzania, array('wp_travel_trip_duration' => 4, 'wp_travel_trip_price' => '0'));

        $out = WTA_LLMS::build_index();

        $this->assertStringContainsString('Quote Only Safari', $out);
        $this->assertStringNotContainsString('USD 0', $out);
    }

    public function test_html_and_entities_are_cleaned_from_text() {
        $this->trip('Tents &amp; Lodges', $this->tanzania, array('wp_travel_overview' => '<p>Sleep in <strong>tents</strong> &amp; lodges.</p>'));

        $out = WTA_LLMS::build_index();

        $this->assertStringContainsString('Tents & Lodges', $out);
        $this->assertStringContainsString('Sleep in tents & lodges.', $out);
        $this->assertStringNotContainsString('<strong>', $out);
    }

    public function test_the_full_file_carries_itinerary_and_inclusions() {
        $this->trip('Serengeti Migration Safari', $this->tanzania, array(
            'wp_travel_trip_duration'      => 2,
            WTA_Trip::ITINERARY_META       => array(
                array('label' => 'Day 1', 'title' => 'Arrive in Arusha', 'desc' => '<p>Meet your guide.</p>'),
                array('label' => 'Day 2', 'title' => 'Into the Serengeti', 'desc' => ''),
            ),
            'wp_travel_trip_include'       => '<ul><li>All park fees</li><li>Private guide</li></ul>',
            'wp_travel_trip_exclude'       => '<ul><li>International flights</li></ul>',
        ));

        $out = WTA_LLMS::build_full();

        $this->assertStringContainsString('- Day 1: Arrive in Arusha. Meet your guide.', $out);
        $this->assertStringContainsString('- Day 2: Into the Serengeti', $out);
        $this->assertStringContainsString("Included:\n- All park fees\n- Private guide", $out);
        $this->assertStringContainsString("Not included:\n- International flights", $out);
    }

    public function test_the_index_links_to_the_full_file_and_the_sitemap() {
        $out = WTA_LLMS::build_index();

        $this->assertStringContainsString(home_url('/llms-full.txt'), $out);
        $this->assertStringContainsString(home_url('/sitemap_index.xml'), $out);
    }

    public function test_filters_change_the_summary_and_currency() {
        add_filter('wta_llms_summary', function () {
            return 'A custom summary.';
        });
        add_filter('wta_llms_currency', function () {
            return 'EUR';
        });

        $this->trip('Euro Trip', $this->kenya, array('wta_x' => '1', 'wp_travel_trip_price' => '900'));

        $out = WTA_LLMS::build_index();

        $this->assertStringContainsString('> A custom summary.', $out);
        $this->assertStringContainsString('from EUR 900 per person sharing', $out);
    }

    public function test_the_response_is_plain_text_and_cached_until_content_changes() {
        $this->trip('Cached Trip', $this->tanzania, array('wp_travel_trip_price' => '1000'));

        $first = WTA_LLMS::response('index');

        $this->assertSame(200, $first['status']);
        $this->assertStringStartsWith('text/plain', $first['headers']['Content-Type']);
        $this->assertStringContainsString('Cached Trip', $first['body']);

        // A new trip appears once the cache is flushed, which save_post does.
        $this->trip('Second Trip', $this->tanzania, array('wp_travel_trip_price' => '2000'));

        $this->assertStringContainsString('Second Trip', WTA_LLMS::response('index')['body']);
    }
}
