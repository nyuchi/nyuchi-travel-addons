<?php
/**
 * Publishing a trip through create-trip and update-trip needs the trip post
 * type's publish capability (#39, fixed in #48).
 *
 * WP Travel is not installed in the test site, so the trip post type is
 * registered here under the slug WTA_Trip expects.
 *
 * @package WPTravelAddons\Tests
 */

class TripStatusPermissionTest extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();

        // create-trip and update-trip are abilities: WordPress's Abilities
        // API is core since 6.9, and on an older WordPress the plugin
        // registers none, so there is nothing to check.
        if (!function_exists('wp_get_ability')) {
            $this->markTestSkipped('The Abilities API needs WordPress 6.9 or newer.');
        }

        $this->register_trip_type(array('public' => true));
    }

    public function tear_down() {
        // Put back the plain registration for the tests that follow.
        $this->register_trip_type(array('public' => true));

        parent::tear_down();
    }

    /**
     * (Re-)register the trip post type with the given arguments.
     *
     * @param array $args register_post_type() arguments.
     */
    protected function register_trip_type($args) {
        if (post_type_exists(WTA_Trip::post_type())) {
            unregister_post_type(WTA_Trip::post_type());
        }

        register_post_type(WTA_Trip::post_type(), $args);
    }

    /**
     * Log in as a new user with the given role.
     *
     * @param string $role Role name.
     * @return int User ID.
     */
    protected function login_as($role) {
        $user_id = self::factory()->user->create(array('role' => $role));
        wp_set_current_user($user_id);

        return $user_id;
    }

    /**
     * A trip owned by $author in $status.
     */
    protected function trip($author, $status = 'draft') {
        return self::factory()->post->create(array(
            'post_type'   => WTA_Trip::post_type(),
            'post_title'  => 'Falls and Fireflies',
            'post_status' => $status,
            'post_author' => $author,
        ));
    }

    /**
     * Run an ability the way a client does: schema validation, permission
     * callback, then the execute callback.
     */
    protected function run_ability($name, $input) {
        $ability = wp_get_ability('nyuchi-travel/' . $name);
        $this->assertInstanceOf(WP_Ability::class, $ability, "{$name} is registered.");

        return $ability->execute($input);
    }

    /**
     * Call the execute callback directly, past the input schema, to check the
     * callback's own whitelist: a client that skips the schema must not get
     * further than one that respects it.
     */
    protected function run_callback($name, $input) {
        $ability  = wp_get_ability('nyuchi-travel/' . $name);
        $property = new ReflectionProperty(WP_Ability::class, 'execute_callback');

        return call_user_func($property->getValue($ability), $input);
    }

    protected function assertRefusedToPublish($result) {
        $this->assertWPError($result);
        $this->assertSame('wta_cannot_publish', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    /* ------------------------------------------------------------ update-trip */

    public function test_a_contributor_cannot_publish_their_trip() {
        $user = $this->login_as('contributor');
        $trip = $this->trip($user, 'pending');

        foreach (array('publish', 'future', 'private') as $status) {
            $result = $this->run_ability('update-trip', array('trip_id' => $trip, 'status' => $status, 'dry_run' => false));

            $this->assertRefusedToPublish($result);
            $this->assertSame('pending', get_post_status($trip), "status {$status} must not be written.");
        }
    }

    public function test_a_refused_publish_writes_nothing_else_either() {
        $user = $this->login_as('contributor');
        $trip = $this->trip($user);

        $result = $this->run_ability('update-trip', array(
            'trip_id' => $trip,
            'title'   => 'Renamed',
            'status'  => 'publish',
            'dry_run' => false,
        ));

        $this->assertRefusedToPublish($result);
        $this->assertSame('Falls and Fireflies', get_the_title($trip));
    }

    public function test_a_contributor_can_still_move_their_trip_between_draft_and_pending() {
        $user = $this->login_as('contributor');
        $trip = $this->trip($user);

        $result = $this->run_ability('update-trip', array('trip_id' => $trip, 'status' => 'pending', 'dry_run' => false));

        $this->assertIsArray($result);
        $this->assertSame(array('post_status'), $result['changed']);
        $this->assertSame(array(), $result['rejected']);
        $this->assertSame('pending', get_post_status($trip));
    }

    public function test_a_dry_run_reports_the_refusal_and_writes_nothing() {
        $user = $this->login_as('contributor');
        $trip = $this->trip($user);

        $result = $this->run_ability('update-trip', array('trip_id' => $trip, 'status' => 'publish', 'title' => 'Renamed'));

        $this->assertIsArray($result);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(array('post_title'), $result['changed'], 'The status change is not offered as a change.');
        $this->assertCount(1, $result['rejected']);
        $this->assertStringContainsString('"publish" would be refused with 403', $result['rejected'][0]);
        $this->assertSame('draft', get_post_status($trip));
        $this->assertSame('Falls and Fireflies', get_the_title($trip));
    }

    public function test_editors_and_administrators_publish_as_before() {
        foreach (array('editor', 'administrator') as $role) {
            $user = $this->login_as($role);
            $trip = $this->trip($user);

            $result = $this->run_ability('update-trip', array('trip_id' => $trip, 'status' => 'publish', 'dry_run' => false));

            $this->assertIsArray($result, "{$role} can publish.");
            $this->assertSame(array('post_status'), $result['changed']);
            $this->assertSame('publish', get_post_status($trip));

            $result = $this->run_ability('update-trip', array('trip_id' => $trip, 'status' => 'private', 'dry_run' => false));

            $this->assertIsArray($result);
            $this->assertSame('private', get_post_status($trip));
        }
    }

    public function test_trash_and_unknown_statuses_are_refused_by_the_schema() {
        $this->login_as('administrator');
        $trip = $this->trip(get_current_user_id());

        foreach (array('trash', 'foo') as $status) {
            $result = $this->run_ability('update-trip', array('trip_id' => $trip, 'status' => $status, 'dry_run' => false));

            $this->assertWPError($result, "status {$status} is refused.");
            $this->assertSame('draft', get_post_status($trip));
        }
    }

    public function test_trash_and_unknown_statuses_are_listed_in_rejected_past_the_schema() {
        $this->login_as('administrator');
        $trip = $this->trip(get_current_user_id());

        foreach (array('trash', 'foo') as $status) {
            $result = $this->run_callback('update-trip', array('trip_id' => $trip, 'status' => $status, 'title' => 'Renamed ' . $status, 'dry_run' => false));

            $this->assertIsArray($result);
            $this->assertSame(array('post_title'), $result['changed']);
            $this->assertCount(1, $result['rejected']);
            $this->assertStringContainsString('"' . $status . '" is not an accepted status', $result['rejected'][0]);
            $this->assertSame('draft', get_post_status($trip));
        }
    }

    public function test_the_trip_post_types_own_publish_capability_is_checked() {
        // Trips with their own capabilities: an editor holds publish_posts but
        // not publish_trips, and must not publish through the generic cap.
        $this->register_trip_type(array(
            'public'          => true,
            'capability_type' => array('trip', 'trips'),
            'map_meta_cap'    => true,
        ));

        $role = get_role('editor');
        foreach (array('edit_trips', 'edit_others_trips', 'edit_published_trips') as $cap) {
            $role->add_cap($cap);
        }

        $user = $this->login_as('editor');
        $trip = $this->trip($user);

        $this->assertTrue(current_user_can('publish_posts'));
        $this->assertFalse(current_user_can('publish_trips'));

        $result = $this->run_ability('update-trip', array('trip_id' => $trip, 'status' => 'publish', 'dry_run' => false));
        $this->assertRefusedToPublish($result);

        $result = $this->run_ability('create-trip', array('title' => 'Live', 'status' => 'publish', 'dry_run' => false));
        $this->assertRefusedToPublish($result);

        $role->add_cap('publish_trips');
        wp_set_current_user(0);
        wp_set_current_user($user);

        $result = $this->run_ability('update-trip', array('trip_id' => $trip, 'status' => 'publish', 'dry_run' => false));
        $this->assertIsArray($result);
        $this->assertSame('publish', get_post_status($trip));

        foreach (array('edit_trips', 'edit_others_trips', 'edit_published_trips', 'publish_trips') as $cap) {
            $role->remove_cap($cap);
        }
    }

    /* ------------------------------------------------------------ create-trip */

    public function test_a_contributor_cannot_create_a_published_trip() {
        $this->login_as('contributor');

        foreach (array('publish', 'private') as $status) {
            $result = $this->run_ability('create-trip', array('title' => 'Live', 'status' => $status, 'dry_run' => false));

            $this->assertRefusedToPublish($result);
        }

        $this->assertSame(0, (int) wp_count_posts(WTA_Trip::post_type())->publish);
        $this->assertSame(0, (int) wp_count_posts(WTA_Trip::post_type())->private);
    }

    public function test_a_contributor_create_dry_run_reports_the_refusal() {
        $this->login_as('contributor');

        $result = $this->run_ability('create-trip', array('title' => 'Live', 'status' => 'publish'));

        $this->assertIsArray($result);
        $this->assertTrue($result['dry_run']);
        $this->assertStringContainsString('"publish" would be refused with 403', $result['rejected'][0]);
    }

    public function test_a_contributor_can_create_a_draft_trip() {
        $user = $this->login_as('contributor');

        $result = $this->run_ability('create-trip', array('title' => 'Draft trip', 'dry_run' => false));

        $this->assertIsArray($result);
        $this->assertSame('draft', get_post_status($result['trip_id']));
        $this->assertSame($user, (int) get_post_field('post_author', $result['trip_id']));
    }

    public function test_editors_and_administrators_create_published_trips_as_before() {
        foreach (array('editor', 'administrator') as $role) {
            $this->login_as($role);

            $result = $this->run_ability('create-trip', array('title' => "{$role} trip", 'status' => 'publish', 'dry_run' => false));

            $this->assertIsArray($result, "{$role} can create a published trip.");
            $this->assertSame(array(), $result['rejected']);
            $this->assertSame('publish', get_post_status($result['trip_id']));
        }
    }

    public function test_create_lists_an_unknown_status_past_the_schema() {
        $this->login_as('administrator');

        $result = $this->run_callback('create-trip', array('title' => 'Odd', 'status' => 'trash', 'dry_run' => false));

        $this->assertIsArray($result);
        $this->assertStringContainsString('"trash" is not an accepted status', $result['rejected'][0]);
        $this->assertSame('draft', get_post_status($result['trip_id']));
    }
}
