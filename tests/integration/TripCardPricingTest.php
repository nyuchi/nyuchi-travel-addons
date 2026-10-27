<?php
/**
 * The trip card's "From $X" price, qualifier, footnote and no-price branch.
 *
 * WP Travel and Elementor are not installed in the test site: the trip post
 * type is registered here and the widget loads against an empty Elementor
 * base class. The card's markup is built without a query loop, so no globals
 * need setting up.
 *
 * @package WPTravelAddons\Tests
 */

require_once dirname(__DIR__) . '/includes/elementor-stubs.php';
require_once WTA_DIR . 'includes/elementor/trait-wta-widget-styles.php';
require_once WTA_DIR . 'includes/elementor/widgets/class-wta-widget-trip-card.php';

/**
 * Exposes the card's protected builders.
 */
class WTA_Test_Trip_Card extends WTA_Widget_Trip_Card {

    public function card($id, $s) {
        return $this->card_html($id, $s);
    }

    public function footnote($s, $any) {
        return $this->footnote_html($s, $any);
    }

    public function duration($id) {
        return $this->duration_label($id);
    }

    public function price($id) {
        return $this->price_label($id);
    }

    protected function wta_image_size($id, $settings, $fallback = 'large') {
        return 'large';
    }
}

class TripCardPricingTest extends WP_UnitTestCase {

    /** @var WTA_Test_Trip_Card */
    protected $card;

    public function set_up() {
        parent::set_up();

        if (!post_type_exists(WTA_Trip::post_type())) {
            register_post_type(WTA_Trip::post_type(), array('public' => true));
        }

        $this->card = new WTA_Test_Trip_Card();
    }

    /**
     * The widget's own control defaults.
     */
    protected function settings($over = array()) {
        return array_merge(array(
            'show_duration'       => 'yes',
            'show_price'          => 'yes',
            'show_destination'    => 'yes',
            'show_excerpt'        => '',
            'cta'                 => 'View itinerary',
            'cta_request'         => 'Request price',
            'price_request_label' => 'Price on request',
            'price_suffix'        => 'per person sharing',
            'footnote'            => '',
        ), $over);
    }

    protected function trip($meta = array(), $title = 'Serengeti Migration') {
        $id = self::factory()->post->create(array(
            'post_type'   => WTA_Trip::post_type(),
            'post_title'  => $title,
            'post_status' => 'publish',
        ));

        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }

        return $id;
    }

    public function test_a_priced_trip_reads_from_price_per_person_sharing() {
        $id   = $this->trip(array('wp_travel_trip_price' => '2450'));
        $card = $this->card->card($id, $this->settings());

        $this->assertTrue($card['priced']);
        $this->assertStringContainsString('<small class="wta-card-from">From</small>', $card['html']);
        $this->assertMatchesRegularExpression('/wta-card-amount">[^<]*2,450/', $card['html']);
        $this->assertStringContainsString('<small class="wta-card-suffix">per person sharing</small>', $card['html']);
        $this->assertStringNotContainsString('is-request', $card['html']);
        $this->assertStringContainsString('View itinerary', $card['html']);
    }

    public function test_the_qualifier_is_editable_and_an_empty_one_is_hidden() {
        $id = $this->trip(array('wp_travel_trip_price' => '980'));

        $custom = $this->card->card($id, $this->settings(array('price_suffix' => 'per couple')));
        $this->assertStringContainsString('>per couple</small>', $custom['html']);
        $this->assertStringNotContainsString('per person sharing', $custom['html']);

        $none = $this->card->card($id, $this->settings(array('price_suffix' => '')));
        $this->assertTrue($none['priced']);
        $this->assertStringNotContainsString('wta-card-suffix', $none['html']);
        $this->assertStringContainsString('From', $none['html']);
    }

    public function test_a_missing_or_zero_price_asks_for_the_enquiry() {
        foreach (array(array(), array('wp_travel_trip_price' => '0'), array('wp_travel_trip_price' => '')) as $meta) {
            $card = $this->card->card($this->trip($meta), $this->settings());

            $this->assertFalse($card['priced']);
            $this->assertStringContainsString('<span class="wta-card-price is-request">Price on request</span>', $card['html']);
            $this->assertStringContainsString('wta-card-cta is-request">Request price</span>', $card['html']);
            $this->assertStringNotContainsString('From', $card['html']);
            $this->assertStringNotContainsString('$0', $card['html']);
        }
    }

    public function test_price_display_off_shows_no_price_and_no_request_prompt() {
        $id   = $this->trip(array('wp_travel_trip_price' => '2450'));
        $card = $this->card->card($id, $this->settings(array('show_price' => '', 'show_duration' => '')));

        $this->assertFalse($card['priced']);
        $this->assertStringNotContainsString('wta-card-price', $card['html']);
        $this->assertStringNotContainsString('wta-card-meta', $card['html'], 'No duration and no price leaves no meta row.');
    }

    public function test_the_footnote_needs_text_and_at_least_one_price() {
        $s = $this->settings(array('footnote' => "Based on two sharing.\nExcludes flights."));

        $html = $this->card->footnote($s, true);
        $this->assertStringStartsWith('<p class="wta-cards-footnote">', $html);
        $this->assertStringContainsString('Based on two sharing.<br />', $html);

        $this->assertSame('', $this->card->footnote($s, false), 'No card shows a price, so nothing to qualify.');
        $this->assertSame('', $this->card->footnote($this->settings(), true), 'Empty by default.');
        $this->assertSame('', $this->card->footnote($this->settings(array('footnote' => "  \n ")), true));
    }

    public function test_the_footnote_is_escaped() {
        $html = $this->card->footnote($this->settings(array('footnote' => '<script>x</script> & more')), true);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&amp; more', $html);
    }

    public function test_duration_with_only_days_or_only_nights() {
        $this->assertSame('10 days', $this->card->duration($this->trip(array('wp_travel_trip_duration' => '10'))));
        $this->assertSame('1 day', $this->card->duration($this->trip(array('wp_travel_trip_duration' => '1'))));
        $this->assertSame('3 nights', $this->card->duration($this->trip(array('wp_travel_trip_duration_night' => '3'))));
        $this->assertSame('1 night', $this->card->duration($this->trip(array('wp_travel_trip_duration_night' => '1'))));
        $this->assertSame('4 days / 3 nights', $this->card->duration($this->trip(array(
            'wp_travel_trip_duration'       => '4',
            'wp_travel_trip_duration_night' => '3',
        ))));
        $this->assertSame('', $this->card->duration($this->trip()));
        $this->assertSame('', $this->card->duration($this->trip(array('wp_travel_trip_duration' => 'abc'))));
    }

    public function test_a_days_only_trip_shows_its_duration() {
        $id   = $this->trip(array('wp_travel_trip_duration' => '10', 'wp_travel_trip_price' => '3100'));
        $card = $this->card->card($id, $this->settings());

        $this->assertStringContainsString('<span class="wta-card-dur">10 days</span>', $card['html']);
    }

    public function test_the_meta_row_is_absent_without_duration_or_price_prompt() {
        $id   = $this->trip();
        $card = $this->card->card($id, $this->settings(array('price_request_label' => '')));

        $this->assertStringNotContainsString('wta-card-meta', $card['html']);
        $this->assertStringContainsString('Request price', $card['html'], 'The button still asks for the enquiry.');
    }

    public function test_the_card_is_one_link_named_by_title_price_and_duration() {
        $id   = $this->trip(array('wp_travel_trip_price' => '2450', 'wp_travel_trip_duration' => '10'));
        $card = $this->card->card($id, $this->settings());

        $this->assertSame(1, substr_count($card['html'], '<a '));
        $this->assertMatchesRegularExpression(
            '/aria-label="Serengeti Migration, From [^"]*2,450 per person sharing, 10 days"/',
            $card['html']
        );
    }

    public function test_the_name_of_a_trip_without_a_price_uses_the_request_label() {
        $card = $this->card->card($this->trip(array('wp_travel_trip_duration' => '5')), $this->settings());

        $this->assertStringContainsString('aria-label="Serengeti Migration, Price on request, 5 days"', $card['html']);
    }

    public function test_the_title_in_the_name_is_escaped_once() {
        $card = $this->card->card($this->trip(array(), 'Gorillas & Mist'), $this->settings());

        $this->assertStringContainsString('aria-label="Gorillas &amp; Mist,', $card['html']);
        $this->assertStringNotContainsString('&amp;amp;', $card['html']);
    }

    public function test_the_price_falls_back_when_wp_travel_has_no_formatter() {
        $this->assertFalse(function_exists('wptravel_get_formated_price_currency'));

        $this->assertSame('$1,200', $this->card->price($this->trip(array('wp_travel_trip_price' => '1200'))));
        $this->assertSame('', $this->card->price($this->trip()));

        add_filter('wta_trip_card_currency_symbol', function () {
            return 'R';
        });
        $this->assertSame('R1,200', $this->card->price($this->trip(array('wp_travel_trip_price' => '1200'))));
    }
}
