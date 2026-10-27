<?php
/**
 * WP Travel's TouristTrip schema: the real from-price instead of a zero offer.
 *
 * @package WPTravelAddons\Tests
 */

class TripSchemaTest extends WP_UnitTestCase {

    protected $trip;

    public function set_up() {
        parent::set_up();

        if (!post_type_exists(WTA_Trip::post_type())) {
            register_post_type(WTA_Trip::post_type(), array('public' => true));
        }

        $this->trip = self::factory()->post->create(array('post_type' => WTA_Trip::post_type()));
    }

    public function tear_down() {
        remove_all_filters('wta_compat_trip_schema_fix');
        parent::tear_down();
    }

    protected function schema($extra = array()) {
        $schema = array_merge(array(
            '@type'  => 'TouristTrip',
            'name'   => 'Serengeti &amp; Ruaha',
            'offers' => array('@type' => 'Offer', 'price' => 0, 'priceCurrency' => 'USD', 'eligibleQuantity' => array('minValue' => '')),
        ), $extra);

        return (new WTA_Compat())->fix_trip_schema($schema, $this->trip);
    }

    public function test_zero_offer_is_replaced_by_the_from_price() {
        update_post_meta($this->trip, 'wp_travel_trip_price', '14200');

        $out = $this->schema();

        $this->assertSame(14200.0, $out['offers']['price']);
        $this->assertSame('USD', $out['offers']['priceCurrency']);
        $this->assertSame('person sharing', $out['offers']['priceSpecification']['referenceQuantity']['unitText']);
        $this->assertArrayNotHasKey('eligibleQuantity', $out['offers']);
    }

    public function test_offer_is_dropped_when_the_trip_has_no_price() {
        update_post_meta($this->trip, 'wp_travel_trip_price', '0');

        $this->assertArrayNotHasKey('offers', $this->schema());
    }

    public function test_entities_are_decoded() {
        $this->assertSame('Serengeti & Ruaha', $this->schema()['name']);
    }

    public function test_keeps_the_currency_wp_travel_chose() {
        update_post_meta($this->trip, 'wp_travel_trip_price', '900');

        $out = $this->schema(array('offers' => array('price' => 0, 'priceCurrency' => 'EUR')));

        $this->assertSame('EUR', $out['offers']['priceCurrency']);
    }

    public function test_filter_switches_it_off() {
        add_filter('wta_compat_trip_schema_fix', '__return_false');
        update_post_meta($this->trip, 'wp_travel_trip_price', '14200');

        $this->assertSame(0, $this->schema()['offers']['price']);
    }
}
