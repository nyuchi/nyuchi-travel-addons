<?php
/**
 * WTA_Itinerary_Schema::sanitize_route(), the cleaner for wta_route.
 *
 * The transfer-mode tests pin down today's behaviour: a stop with a missing
 * or unrecognised "arrive" value becomes 'drive'. That silently turns a boat
 * transfer or a walk into a drive on the route map. When the planned fix
 * lands, change the expectations in arrive_values() first and let them fail.
 *
 * @package WPTravelAddons\Tests
 */

class RouteSanitizeTest extends WP_UnitTestCase {

    /**
     * @param array $stop One stop.
     * @return array The cleaned stop.
     */
    protected function clean($stop) {
        $out = WTA_Itinerary_Schema::sanitize_route(array($stop));
        $this->assertCount(1, $out);

        return $out[0];
    }

    public static function arrive_values() {
        return array(
            'fly kept'                        => array(array('arrive' => 'fly'), 'fly'),
            'drive kept'                      => array(array('arrive' => 'drive'), 'drive'),
            'start kept'                      => array(array('arrive' => 'start'), 'start'),
            'upper case is normalised'        => array(array('arrive' => 'FLY'), 'fly'),
            // Current behaviour, documented rather than endorsed:
            'missing defaults to drive'       => array(array(), 'drive'),
            'empty string defaults to drive'  => array(array('arrive' => ''), 'drive'),
            'unknown mode (boat) becomes drive' => array(array('arrive' => 'boat'), 'drive'),
            'unknown mode (walk) becomes drive' => array(array('arrive' => 'walk'), 'drive'),
            'unknown mode (train) becomes drive' => array(array('arrive' => 'train'), 'drive'),
            'non-string becomes drive'        => array(array('arrive' => array('fly')), 'drive'),
        );
    }

    /**
     * @dataProvider arrive_values
     *
     * @param array  $stop     Stop input.
     * @param string $expected Stored transfer mode.
     */
    public function test_transfer_mode($stop, $expected) {
        $this->assertSame($expected, $this->clean($stop + array('name' => 'Stop'))['arrive']);
    }

    public function test_coordinates_are_clamped_and_absent_ones_stay_null() {
        $stop = $this->clean(array('name' => 'Pole', 'lat' => '95', 'lng' => -200, 'x' => 120, 'y' => '-3'));

        // assertEquals, not assertSame: min()/max() return the int bound
        // when it wins, so a clamped value is 90 rather than 90.0.
        $this->assertEquals(90, $stop['lat']);
        $this->assertEquals(-180, $stop['lng']);
        $this->assertEquals(100, $stop['x']);
        $this->assertEquals(0, $stop['y']);

        $none = $this->clean(array('name' => 'Unplaced', 'lat' => '', 'lng' => null));

        $this->assertNull($none['lat'], 'No coordinates must not become (0,0).');
        $this->assertNull($none['lng']);
        $this->assertNull($none['x'], 'Unset map position must be distinguishable from centred.');
        $this->assertNull($none['y']);
    }

    public function test_text_is_sanitised_and_counts_are_non_negative() {
        $stop = $this->clean(array(
            'name'     => '<b>Hwange</b><script>x()</script>',
            'subtitle' => "Main\ncamp",
            'nights'   => '-2',
            'leg'      => '1',
        ));

        $this->assertSame('Hwange', $stop['name']);
        $this->assertSame('Main camp', $stop['subtitle']);
        $this->assertSame(0, $stop['nights']);
        $this->assertSame(1, $stop['leg']);
    }

    public function test_non_array_input_and_stops_are_dropped() {
        $this->assertSame(array(), WTA_Itinerary_Schema::sanitize_route('not a route'));
        $this->assertSame(array(), WTA_Itinerary_Schema::sanitize_route(null));

        $out = WTA_Itinerary_Schema::sanitize_route(array('junk', array('name' => 'Real'), 42));

        $this->assertCount(1, $out);
        $this->assertSame('Real', $out[0]['name']);
    }

    public function test_the_rest_schema_and_the_sanitiser_agree() {
        $fields = WTA_Itinerary_Schema::fields();
        $this->assertSame('route', $fields[WTA_Itinerary_Schema::ROUTE]['rest_key']);
        $this->assertSame('sanitize_route', $fields[WTA_Itinerary_Schema::ROUTE]['sanitize']);
        $this->assertSame(
            array('fly', 'drive', 'start'),
            $fields[WTA_Itinerary_Schema::ROUTE]['schema']['items']['properties']['arrive']['enum'],
            'The REST schema and the sanitiser must agree on the transfer modes.'
        );
    }
}
