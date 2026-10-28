<?php
/**
 * Route stops that take their position from a Mukoko Kweli place.
 *
 * @package WPTravelAddons\Tests
 */

class KweliTest extends WP_UnitTestCase {

    protected $calls = 0;

    protected $reply;

    public function set_up() {
        parent::set_up();
        $this->calls = 0;
        $this->reply = $this->ok(-3.2, 35.5);

        add_filter('pre_http_request', array($this, 'fake_http'), 10, 3);
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'fake_http'), 10);
        parent::tear_down();
    }

    public function fake_http($pre, $args, $url) {
        if (false === strpos($url, 'api.nyuchi.com/v1/places/')) {
            return $pre;
        }

        $this->calls++;

        return $this->reply;
    }

    protected function ok($lat, $lng, $extra = array()) {
        return array(
            'response' => array('code' => 200, 'message' => 'OK'),
            'body'     => wp_json_encode(array('place' => array_merge(array(
                'name'     => 'Ngorongoro Conservation Area',
                'slug'     => 'ngorongoro-conservation-area-aefb8e',
                'isActive' => true,
                'geo'      => array('type' => 'Point', 'coordinates' => array($lng, $lat)),
                'address'  => array('addressRegion' => 'Arusha Region', 'addressCountry' => 'Tanzania'),
            ), $extra))),
        );
    }

    protected function stop($more = array()) {
        return array_merge(array('name' => '', 'subtitle' => '', 'nights' => 1, 'leg' => 0, 'lat' => null, 'lng' => null, 'x' => null, 'y' => null, 'arrive' => 'drive', 'place' => 'ng-1'), $more);
    }

    public function test_coordinates_are_read_as_longitude_then_latitude() {
        $place = WTA_Kweli::parse(json_decode($this->ok(-3.2, 35.5)['body'], true));

        $this->assertSame(-3.2, $place['lat']);
        $this->assertSame(35.5, $place['lng']);
        $this->assertSame('Arusha Region', $place['region']);
    }

    public function test_unusable_places_are_rejected() {
        $this->assertNull(WTA_Kweli::parse(array()));
        $this->assertNull(WTA_Kweli::parse(array('place' => array('geo' => array('coordinates' => array(0, 0))))));
        $this->assertNull(WTA_Kweli::parse(json_decode($this->ok(-3.2, 35.5, array('isActive' => false))['body'], true)));
        $this->assertNull(WTA_Kweli::parse(json_decode($this->ok(95, 35.5)['body'], true)));
    }

    public function test_a_stop_takes_its_position_from_the_place() {
        $out = (new WTA_Kweli())->resolve_route(array($this->stop()));

        $this->assertSame(-3.2, $out[0]['lat']);
        $this->assertSame(35.5, $out[0]['lng']);
        $this->assertSame('Ngorongoro Conservation Area', $out[0]['name']);
        $this->assertSame('Arusha Region', $out[0]['subtitle']);
    }

    public function test_authored_text_is_not_overwritten() {
        $out = (new WTA_Kweli())->resolve_route(array($this->stop(array('name' => 'The crater', 'subtitle' => 'Two nights'))));

        $this->assertSame('The crater', $out[0]['name']);
        $this->assertSame('Two nights', $out[0]['subtitle']);
    }

    public function test_a_place_far_from_the_stops_own_position_is_not_trusted() {
        // Latitude and longitude swapped in Kweli.
        $this->reply = $this->ok(35.5, -3.2);

        $out = (new WTA_Kweli())->resolve_route(array($this->stop(array('lat' => -3.2, 'lng' => 35.5))));

        $this->assertSame(-3.2, $out[0]['lat']);
        $this->assertSame(35.5, $out[0]['lng']);
    }

    public function test_a_failed_fetch_leaves_the_stop_alone_and_is_remembered() {
        $this->reply = new WP_Error('http_request_failed', 'down');

        $kweli = new WTA_Kweli();
        $stop  = $this->stop(array('lat' => -3.2, 'lng' => 35.5, 'place' => 'down-1'));

        $this->assertSame(-3.2, $kweli->resolve_route(array($stop))[0]['lat']);
        $kweli->resolve_route(array($stop));

        $this->assertSame(1, $this->calls, 'A failed lookup is cached, so the API is not hit on every page view.');
    }

    public function test_a_found_place_is_cached() {
        $kweli = new WTA_Kweli();

        $kweli->resolve_route(array($this->stop(array('place' => 'cache-1'))));
        $kweli->resolve_route(array($this->stop(array('place' => 'cache-1'))));

        $this->assertSame(1, $this->calls);
    }

    public function test_an_id_that_could_change_the_path_makes_no_request() {
        $this->assertNull(WTA_Kweli::place('../admin'));
        $this->assertNull(WTA_Kweli::place('a/b'));
        $this->assertSame(0, $this->calls);
    }

    public function test_stops_without_a_place_pass_through_untouched() {
        $stop = $this->stop(array('place' => '', 'lat' => -2.45, 'lng' => 34.83));

        $this->assertSame(array($stop), (new WTA_Kweli())->resolve_route(array($stop)));
        $this->assertSame(0, $this->calls);
    }

    public function test_the_route_sanitiser_keeps_a_valid_place_and_drops_a_bad_one() {
        $out = WTA_Itinerary_Schema::sanitize_route(array(
            array('name' => 'A', 'place' => 'ngorongoro-conservation-area-aefb8e'),
            array('name' => 'B', 'place' => '../x'),
            array('name' => 'C'),
        ));

        $this->assertSame('ngorongoro-conservation-area-aefb8e', $out[0]['place']);
        $this->assertSame('', $out[1]['place']);
        $this->assertSame('', $out[2]['place']);
    }
}
