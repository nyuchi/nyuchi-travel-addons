<?php
/**
 * Country FAQs: the `faqs` term field, its transfer and REST support, the
 * lookup from a trip up to its country, the markup and the one FAQPage graph.
 *
 * WP Travel is not installed in the test site, so the destination taxonomy and
 * the trip post type are registered here under the slugs the plugin expects.
 * Elementor is not installed either; the widget is a thin shell over
 * WTA_Term_FAQ, which is what is tested.
 *
 * @package WPTravelAddons\Tests
 */

class TermFaqTest extends WP_UnitTestCase {

    const TAX = 'travel_locations';

    protected $region;
    protected $country;
    protected $park;

    public function set_up() {
        parent::set_up();

        if (!post_type_exists(WTA_Trip::post_type())) {
            register_post_type(WTA_Trip::post_type(), array('public' => true));
        }

        if (!taxonomy_exists(self::TAX)) {
            register_taxonomy(self::TAX, WTA_Trip::post_type(), array(
                'hierarchical' => true,
                'public'       => true,
                'show_in_rest' => true,
            ));
        }

        $this->region  = self::factory()->term->create(array('taxonomy' => self::TAX, 'name' => 'Southern Africa'));
        $this->country = self::factory()->term->create(array('taxonomy' => self::TAX, 'name' => 'Zimbabwe', 'parent' => $this->region));
        $this->park    = self::factory()->term->create(array('taxonomy' => self::TAX, 'name' => 'Mana Pools', 'parent' => $this->country));

        WTA_Term_Fields::flush();
        WTA_Term_FAQ::reset();
        remove_all_filters('wta_term_faq_schema');
        remove_all_filters('wta_term_faq_schema_enabled');
        remove_all_filters('wta_term_faq_other_schema_present');
    }

    public function tear_down() {
        WTA_Term_FAQ::reset();
        remove_all_actions('wp_footer');
        unset($GLOBALS['wp_actions']['wp_footer'], $GLOBALS['post'], $_POST[WTA_Term_Fields::NONCE_FIELD], $_POST[WTA_Term_Fields::INPUT_NAME]);

        parent::tear_down();
    }

    protected function faqs() {
        return array(
            array('question' => 'When is the best time to visit?', 'answer' => "June to October.\n\nThe rains close Mana Pools."),
            array('question' => 'Do I need a visa?', 'answer' => 'See <a href="https://www.evisa.gov.zw">the eVisa site</a>.'),
        );
    }

    protected function stored($term_id) {
        return get_term_meta($term_id, 'wta_faqs', true);
    }

    /** Capture what the footer prints. */
    protected function footer() {
        ob_start();
        WTA_Term_FAQ::print_schema();

        return (string) ob_get_clean();
    }

    /** The decoded JSON-LD blocks in some markup. */
    protected function blocks($html) {
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);

        return array_map(function ($json) {
            return json_decode($json, true);
        }, $m[1]);
    }

    /* ------------------------------------------------------------ the field */

    public function test_the_field_belongs_to_destinations_only() {
        $this->assertArrayHasKey('faqs', WTA_Term_Fields::fields(self::TAX));
        $this->assertArrayNotHasKey('faqs', WTA_Term_Fields::fields('activity'));
    }

    public function test_it_is_stored_as_json_in_wta_faqs_and_reads_back() {
        $this->assertTrue(WTA_Term_Fields::set($this->country, 'faqs', $this->faqs()));

        $stored = $this->stored($this->country);
        $this->assertIsString($stored);
        $this->assertSame(array_column($this->faqs(), 'question'), array_column(json_decode($stored, true), 'question'));

        WTA_Term_Fields::flush();
        $this->assertSame($this->faqs()[0]['answer'], WTA_Term_Fields::get($this->country, 'faqs')[0]['answer']);
    }

    public function test_quotes_backslashes_and_unicode_survive_the_database() {
        $faqs = array(array('question' => 'Where is “Vic Falls”?', 'answer' => 'A \\ backslash, "quotes" and Mosi-oa-Tunya (Smoke that Thunders) — 100%.'));

        WTA_Term_Fields::set($this->country, 'faqs', $faqs);
        WTA_Term_Fields::flush();

        $this->assertSame($faqs, WTA_Term_Fields::get($this->country, 'faqs'));
    }

    public function test_an_empty_list_deletes_the_row() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());
        WTA_Term_Fields::set($this->country, 'faqs', array());

        $this->assertSame('', $this->stored($this->country));
        $this->assertSame(array(), WTA_Term_Fields::get($this->country, 'faqs'));
    }

    public function test_the_sanitiser_drops_half_rows_and_dangerous_html() {
        $clean = WTA_Term_Fields::sanitize_faqs(array(
            array('question' => '  Visa? ', 'answer' => '<p onclick="x()">Check <a href="javascript:alert(1)" onclick="y()">this</a><script>alert(1)</script><h1>big</h1><strong>now</strong></p>'),
            array('question' => 'No answer', 'answer' => ''),
            array('question' => '', 'answer' => 'No question'),
            'not a row',
            array('question' => '<b>Tagged</b> question', 'answer' => 'Fine'),
        ));

        $this->assertCount(2, $clean);
        $this->assertSame('Visa?', $clean[0]['question']);
        $this->assertSame('Tagged question', $clean[1]['question']);
        $this->assertStringNotContainsString('onclick', $clean[0]['answer']);
        $this->assertStringNotContainsString('javascript:', $clean[0]['answer']);
        $this->assertStringNotContainsString('<script', $clean[0]['answer']);
        $this->assertStringNotContainsString('<h1', $clean[0]['answer']);
        $this->assertStringContainsString('<strong>now</strong>', $clean[0]['answer']);
    }

    public function test_the_sanitiser_accepts_json_and_ignores_junk() {
        $this->assertCount(1, WTA_Term_Fields::sanitize_faqs('[{"question":"Q","answer":"A"}]'));
        $this->assertSame(array(), WTA_Term_Fields::sanitize_faqs('not json'));
        $this->assertSame(array(), WTA_Term_Fields::sanitize_faqs(''));
        $this->assertSame(array(), WTA_Term_Fields::sanitize_faqs(42));
    }

    public function test_the_list_is_capped() {
        add_filter('wta_faq_max_items', function () {
            return 2;
        });

        $rows = array();

        for ($i = 1; $i <= 5; $i++) {
            $rows[] = array('question' => "Q$i", 'answer' => "A$i");
        }

        $this->assertCount(2, WTA_Term_Fields::sanitize_faqs($rows));
    }

    public function test_the_edit_screen_saves_the_repeater() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));

        $_POST[WTA_Term_Fields::NONCE_FIELD] = wp_create_nonce(WTA_Term_Fields::NONCE_ACTION);
        $_POST[WTA_Term_Fields::INPUT_NAME]  = wp_slash(array(
            'faqs' => array(
                '0'  => array('question' => 'First?', 'answer' => 'Yes.'),
                '7'  => array('question' => '', 'answer' => ''),
                '12' => array('question' => 'Second?', 'answer' => 'No.'),
            ),
        ));

        wp_update_term($this->country, self::TAX, array('name' => 'Zimbabwe'));
        WTA_Term_Fields::flush();

        $this->assertSame(array('First?', 'Second?'), array_column(WTA_Term_Fields::get($this->country, 'faqs'), 'question'));
    }

    public function test_the_list_table_reports_the_faq_count() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());

        $fields = new ReflectionClass('WTA_Term_Fields');
        $html   = $fields->newInstanceWithoutConstructor()->render_column('', 'wta_details', $this->country);

        $this->assertStringContainsString('2 FAQs', $html);
    }

    /* ----------------------------------------------------------------- REST */

    protected function rest_server() {
        $GLOBALS['wp_rest_server'] = null;
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));

        return rest_get_server();
    }

    public function test_rest_reads_and_writes_the_field() {
        $server = $this->rest_server();

        $request = new WP_REST_Request('POST', '/wp/v2/' . self::TAX . '/' . $this->country);
        $request->set_body_params(array('wta_faqs' => $this->faqs()));
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Do I need a visa?', $response->get_data()['wta_faqs'][1]['question']);

        $read = $server->dispatch(new WP_REST_Request('GET', '/wp/v2/' . self::TAX . '/' . $this->country));
        $this->assertCount(2, $read->get_data()['wta_faqs']);
    }

    public function test_rest_refuses_a_malformed_list() {
        $server = $this->rest_server();

        $request = new WP_REST_Request('POST', '/wp/v2/' . self::TAX . '/' . $this->country);
        $request->set_body_params(array('wta_faqs' => array(array('question' => 'No answer'))));
        $response = $server->dispatch($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
        $this->assertSame(array(), WTA_Term_Fields::get($this->country, 'faqs'));
    }

    /* ------------------------------------------------------------- transfer */

    public function test_export_carries_the_faqs_and_an_unchanged_import_is_a_no_op() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());

        $doc  = WTA_Term_Transfer::export(self::TAX, array($this->country));
        $this->assertCount(2, $doc['terms'][0]['fields']['faqs']);

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $result = WTA_Term_Transfer::import($doc, false, true);
        $this->assertSame('unchanged', $result['terms'][0]['status']);
    }

    public function test_json_import_replaces_the_list() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());

        $doc = WTA_Term_Transfer::export(self::TAX, array($this->country));
        $doc['terms'][0]['fields']['faqs'] = array(array('question' => 'Only one?', 'answer' => 'Yes.'));

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $result = WTA_Term_Transfer::import($doc, false, false);

        $this->assertContains('fields.faqs', $result['terms'][0]['changed']);
        WTA_Term_Fields::flush();
        $this->assertSame(array('Only one?'), array_column(WTA_Term_Fields::get($this->country, 'faqs'), 'question'));
    }

    public function test_csv_round_trips_answers_with_pipes_quotes_and_newlines() {
        $faqs = array(array('question' => 'A | B?', 'answer' => "Either \"one\" | the other.\n\nSecond paragraph."));
        WTA_Term_Fields::set($this->country, 'faqs', $faqs);

        $csv = WTA_Term_Transfer::to_csv(WTA_Term_Transfer::export(self::TAX, array($this->country)));
        $this->assertStringContainsString('field:faqs', $csv);

        // Clear the field, then restore it from the CSV.
        WTA_Term_Fields::set($this->country, 'faqs', array());
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));

        $parsed = WTA_Term_Transfer::from_csv($csv);
        $this->assertNotWPError($parsed);

        // The export predates the clear, so its etag is stale by design.
        $result = WTA_Term_Transfer::import($parsed['document'], true, false);
        $this->assertContains('fields.faqs', $result['terms'][0]['changed']);

        WTA_Term_Fields::flush();
        $this->assertSame($faqs, WTA_Term_Fields::get($this->country, 'faqs'));
    }

    /* --------------------------------------------------------------- lookup */

    public function test_a_place_without_faqs_finds_its_countrys() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());

        $found = WTA_Term_FAQ::find($this->park);
        $this->assertNull($found, 'automatic lookup reads the page, not the argument');

        $found = $this->find_from_archive($this->park);
        $this->assertSame($this->country, $found['term']->term_id);
        $this->assertCount(2, $found['faqs']);
    }

    public function test_a_place_with_its_own_faqs_keeps_them() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());
        WTA_Term_Fields::set($this->park, 'faqs', array(array('question' => 'Walk?', 'answer' => 'With a guide.')));

        $found = $this->find_from_archive($this->park);
        $this->assertSame($this->park, $found['term']->term_id);
    }

    public function test_own_scope_does_not_walk_up() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());

        $this->assertNull($this->find_from_archive($this->park, 'own'));
    }

    public function test_a_chosen_term_is_used_exactly() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());

        $this->assertSame($this->country, WTA_Term_FAQ::find($this->country)['term']->term_id);
        $this->assertNull(WTA_Term_FAQ::find($this->park), 'a chosen place does not borrow from its country');
    }

    public function test_a_trip_finds_the_country_through_its_park() {
        WTA_Term_Fields::set($this->country, 'faqs', $this->faqs());

        $trip = self::factory()->post->create(array('post_type' => WTA_Trip::post_type(), 'post_status' => 'publish'));
        wp_set_object_terms($trip, array($this->park), self::TAX);
        $this->go_to(get_permalink($trip));

        $found = WTA_Term_FAQ::find();
        $this->assertNotNull($found);
        $this->assertSame($this->country, $found['term']->term_id);
    }

    public function test_nothing_is_found_without_faqs_or_off_a_destination() {
        $this->go_to(home_url('/'));
        $this->assertNull(WTA_Term_FAQ::find());
        $this->assertNull($this->find_from_archive($this->park));
    }

    protected function find_from_archive($term_id, $scope = 'nearest') {
        $this->go_to(get_term_link((int) $term_id, self::TAX));

        return WTA_Term_FAQ::find(0, $scope);
    }

    /* --------------------------------------------------------------- markup */

    public function test_the_list_is_native_details_and_escapes_everything() {
        $html = WTA_Term_FAQ::render_list(array(
            array('question' => 'Is <b>this</b> & that safe?', 'answer' => "First.\n\nSecond <script>alert(1)</script> <a href=\"https://example.com\" onclick=\"x()\">link</a>"),
            array('question' => 'Second?', 'answer' => 'Yes.'),
        ), array('open_first' => true, 'group' => 'g1'));

        $this->assertSame(2, substr_count($html, '<details'));
        $this->assertSame(2, substr_count($html, '<summary'));
        $this->assertStringContainsString('name="g1"', $html);
        $this->assertSame(1, substr_count($html, ' open'));
        $this->assertStringContainsString('Is &lt;b&gt;this&lt;/b&gt; &amp; that safe?', $html);
        $this->assertStringContainsString('<p>First.</p>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function test_no_group_and_closed_by_default() {
        $html = WTA_Term_FAQ::render_list($this->faqs());

        $this->assertStringNotContainsString(' name=', $html);
        $this->assertStringNotContainsString(' open', $html);
    }

    /* --------------------------------------------------------------- schema */

    public function test_one_faqpage_graph_is_printed() {
        WTA_Term_FAQ::queue($this->faqs());

        $blocks = $this->blocks($this->footer());

        $this->assertCount(1, $blocks);
        $this->assertSame('https://schema.org', $blocks[0]['@context']);
        $this->assertCount(1, $blocks[0]['@graph']);
        $this->assertSame('FAQPage', $blocks[0]['@graph'][0]['@type']);

        $entities = $blocks[0]['@graph'][0]['mainEntity'];
        $this->assertCount(2, $entities);
        $this->assertSame('Question', $entities[0]['@type']);
        $this->assertSame('When is the best time to visit?', $entities[0]['name']);
        $this->assertSame('Answer', $entities[0]['acceptedAnswer']['@type']);
        $this->assertStringContainsString('<p>June to October.</p>', $entities[0]['acceptedAnswer']['text']);
    }

    public function test_the_footer_hook_is_registered_once() {
        WTA_Term_FAQ::queue($this->faqs());
        WTA_Term_FAQ::queue($this->faqs());

        $this->assertSame(20, has_action('wp_footer', array('WTA_Term_FAQ', 'print_schema')));
    }

    public function test_two_widgets_merge_into_one_graph_without_repeating_a_question() {
        WTA_Term_FAQ::queue($this->faqs());
        WTA_Term_FAQ::queue(array(
            array('question' => '  do I need a VISA?  ', 'answer' => 'Same question, different spacing and case.'),
            array('question' => 'Is it safe?', 'answer' => 'Yes.'),
        ));

        $blocks = $this->blocks($this->footer());

        $this->assertCount(1, $blocks);
        $this->assertCount(3, $blocks[0]['@graph'][0]['mainEntity']);
        $this->assertSame('', $this->footer(), 'printed once, then empty');
    }

    public function test_json_cannot_break_out_of_its_script_tag() {
        WTA_Term_FAQ::queue(array(array('question' => 'Q </script><img src=x onerror=alert(1)>', 'answer' => '</script><b>x</b> & "y"')));

        $out = $this->footer();

        $this->assertSame(1, substr_count($out, '</script>'));
        $this->assertStringNotContainsString('<img', $out);
        $this->assertSame('Q </script><img src=x onerror=alert(1)>', $this->blocks($out)[0]['@graph'][0]['mainEntity'][0]['name']);
    }

    public function test_the_graph_is_filterable_and_can_be_turned_off() {
        add_filter('wta_term_faq_schema', function ($graph) {
            $graph['@graph'][0]['name'] = 'Zimbabwe FAQ';

            return $graph;
        });
        WTA_Term_FAQ::queue($this->faqs());
        $this->assertSame('Zimbabwe FAQ', $this->blocks($this->footer())[0]['@graph'][0]['name']);

        remove_all_filters('wta_term_faq_schema');
        add_filter('wta_term_faq_schema', '__return_null');
        WTA_Term_FAQ::queue($this->faqs());
        $this->assertSame('', $this->footer());

        remove_all_filters('wta_term_faq_schema');
        add_filter('wta_term_faq_schema_enabled', '__return_false');
        WTA_Term_FAQ::queue($this->faqs());
        $this->assertSame('', $this->footer());
    }

    public function test_it_yields_to_a_yoast_faq_page() {
        WTA_Term_FAQ::scan_schema_graph(array(
            array('@type' => 'WebPage'),
            array('@type' => array('Article', 'FAQPage')),
        ));
        WTA_Term_FAQ::queue($this->faqs());

        $this->assertSame('', $this->footer());
    }

    public function test_it_ignores_a_graph_without_a_faq_page() {
        WTA_Term_FAQ::scan_schema_graph(array(array('@type' => 'WebPage'), array('@type' => 'Organization')));
        WTA_Term_FAQ::queue($this->faqs());

        $this->assertCount(1, $this->blocks($this->footer()));
    }

    public function test_it_yields_to_faq_schema_in_widget_or_content_markup() {
        $elementor = '<div class="elementor-accordion"></div><script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[]}</script>';

        $this->assertSame($elementor, WTA_Term_FAQ::scan_markup($elementor), 'a scan never changes the markup');
        WTA_Term_FAQ::queue($this->faqs());
        $this->assertSame('', $this->footer());

        WTA_Term_FAQ::reset();
        WTA_Term_FAQ::scan_markup('<div itemscope itemtype="https://schema.org/FAQPage"></div>');
        WTA_Term_FAQ::queue($this->faqs());
        $this->assertSame('', $this->footer());
    }

    public function test_the_words_faqpage_in_ordinary_text_do_not_count() {
        WTA_Term_FAQ::scan_markup('<p>We publish FAQPage schema.</p><script type="application/ld+json">{"@type":"Organization"}</script>');
        WTA_Term_FAQ::queue($this->faqs());

        $this->assertCount(1, $this->blocks($this->footer()));
    }

    public function test_a_site_can_declare_other_schema_present() {
        add_filter('wta_term_faq_other_schema_present', '__return_true');
        WTA_Term_FAQ::queue($this->faqs());

        $this->assertSame('', $this->footer());
    }

    public function test_a_widget_after_the_footer_prints_the_graph_in_place() {
        // As if the footer had already run, without running every theme's footer.
        $GLOBALS['wp_actions']['wp_footer'] = 1;

        $out = WTA_Term_FAQ::queue($this->faqs());

        $this->assertCount(1, $this->blocks($out));
        $this->assertSame('', $this->footer());
    }

    public function test_nothing_is_queued_for_no_faqs() {
        WTA_Term_FAQ::queue(array());

        $this->assertSame('', $this->footer());
    }
}
