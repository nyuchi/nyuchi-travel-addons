<?php
/**
 * WTA_Elementor::trip_data(), what every itinerary widget renders from.
 *
 * WP Travel is not installed in the test site, so the trip post type is
 * registered here under the slug WTA_Trip expects.
 *
 * @package WPTravelAddons\Tests
 */

class TripDataTest extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();

        if (!post_type_exists(WTA_Trip::post_type())) {
            register_post_type(WTA_Trip::post_type(), array('public' => true));
        }
    }

    public function tear_down() {
        unset($GLOBALS['post']);

        parent::tear_down();
    }

    /**
     * Make $post_id the post being rendered.
     *
     * @param int $post_id Post ID.
     */
    protected function render($post_id) {
        $GLOBALS['post'] = get_post($post_id);
        setup_postdata($GLOBALS['post']);
    }

    public function test_a_trip_with_partial_data_still_gets_every_section() {
        $trip = self::factory()->post->create(array(
            'post_type'  => WTA_Trip::post_type(),
            'post_title' => 'Falls and Fireflies',
        ));

        // Only what a half-finished WP Travel trip typically has: nights, a
        // location with coordinates, and two days. No hero, legs or route.
        update_post_meta($trip, 'wp_travel_trip_duration_night', '3');
        update_post_meta($trip, 'wp_travel_location', 'Victoria Falls');
        update_post_meta($trip, 'wp_travel_lat', '-17.9243');
        update_post_meta($trip, 'wp_travel_lng', '25.8572');
        update_post_meta($trip, WTA_Trip::ITINERARY_META, array(
            array('label' => 'Day 1', 'title' => 'Arrive'),
            array('label' => 'Day 2', 'title' => 'The falls'),
        ));

        $this->render($trip);
        $data = WTA_Elementor::trip_data();

        foreach (array('hero', 'legs', 'route', 'seasonality', 'options', 'checklist', 'notes', 'days', 'facts') as $key) {
            $this->assertArrayHasKey($key, $data);
            $this->assertIsArray($data[$key], "{$key} must be an array so a widget can iterate it.");
        }

        $this->assertCount(2, $data['days']);
        $this->assertSame('3', $data['facts']['nights']);
        $this->assertSame('', $data['facts']['price'], 'An unset fact is an empty string, not missing.');

        // One pin from WP Travel's own map coordinates, centred.
        $this->assertCount(1, $data['route']);
        $this->assertSame('Victoria Falls', $data['route'][0]['name']);
        $this->assertSame('start', $data['route'][0]['arrive']);
        $this->assertEqualsWithDelta(-17.9243, $data['route'][0]['lat'], 0.0001);
        $this->assertEquals(50, $data['route'][0]['x']);
        $this->assertEquals(50, $data['route'][0]['y']);
        $this->assertSame(3, $data['route'][0]['nights']);

        // A headline strip built from what is known.
        $this->assertSame(
            array(
                array('value' => '3', 'label' => 'Nights'),
                array('value' => '1', 'label' => 'Stops'),
            ),
            $data['hero']['stats']
        );

        // The whole itinerary as one leg so the day cards render.
        $this->assertCount(1, $data['legs']);
        $this->assertSame('Falls and Fireflies', $data['legs'][0]['title']);
        $this->assertSame(0, $data['legs'][0]['day_from']);
        $this->assertSame(1, $data['legs'][0]['day_to']);
    }

    public function test_a_trip_with_nothing_but_a_title() {
        $trip = self::factory()->post->create(array('post_type' => WTA_Trip::post_type()));

        $this->render($trip);
        $data = WTA_Elementor::trip_data();

        $this->assertSame(array(), $data['days']);
        $this->assertSame(array(), $data['route'], 'No coordinates: no pin, rather than one at (0,0).');
        $this->assertSame(array(), $data['legs']);
        $this->assertSame(array(), $data['hero']['stats']);
    }

    public function test_stale_legs_are_clamped_to_the_days_that_exist() {
        $trip = self::factory()->post->create(array('post_type' => WTA_Trip::post_type()));

        update_post_meta($trip, WTA_Trip::ITINERARY_META, array(array(), array(), array()));
        update_post_meta($trip, WTA_Itinerary_Schema::LEGS, WTA_Itinerary_Schema::sanitize_legs(array(
            array('title' => 'North', 'day_from' => 0, 'day_to' => 1),
            array('title' => 'South', 'day_from' => 2, 'day_to' => 9),
            array('title' => 'Gone', 'day_from' => 5, 'day_to' => 6),
        )));

        $this->render($trip);
        $legs = WTA_Elementor::trip_data()['legs'];

        $this->assertSame(array('North', 'South'), wp_list_pluck($legs, 'title'), 'A leg entirely past the last day is dropped.');
        $this->assertSame(2, $legs[1]['day_to']);
        $this->assertTrue($legs[1]['wta_stale']);
        $this->assertFalse($legs[0]['wta_stale']);
    }

    public function test_off_a_trip_there_is_no_data() {
        $this->assertSame(array(), WTA_Elementor::trip_data(), 'No post being rendered.');

        $this->render(self::factory()->post->create(array('post_type' => 'page')));
        $this->assertSame(array(), WTA_Elementor::trip_data(), 'A page is not a trip.');
    }
}
