<?php
/**
 * Shared set-up for the enquiry form tests.
 *
 * WP Travel is not installed in the test site, so the post types it would
 * register are registered here under the slugs the plugin expects.
 *
 * @package WPTravelAddons\Tests
 */

abstract class WTA_Inquiry_Test_Case extends WP_UnitTestCase {

    /** Post types and taxonomies this case registered, removed again in tear_down. */
    protected $registered_types = array();
    protected $registered_taxes = array();

    public function set_up() {
        parent::set_up();

        if (!function_exists('wp_get_ability')) {
            $this->markTestSkipped('The Abilities API needs WordPress 6.9 or newer.');
        }

        if (!post_type_exists(WTA_Trip::post_type())) {
            register_post_type(WTA_Trip::post_type(), array('public' => true));
            $this->registered_types[] = WTA_Trip::post_type();
        }

        if (!post_type_exists(WTA_Inquiry_Abilities::POST_TYPE)) {
            register_post_type(WTA_Inquiry_Abilities::POST_TYPE, array('public' => false, 'show_ui' => true));
            $this->registered_types[] = WTA_Inquiry_Abilities::POST_TYPE;
        }

        delete_option(WTA_Inquiry_Form::OPTION);
        delete_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION);
    }

    public function tear_down() {
        foreach ($this->registered_taxes as $taxonomy) {
            unregister_taxonomy($taxonomy);
        }

        foreach ($this->registered_types as $type) {
            unregister_post_type($type);
        }

        delete_option(WTA_Inquiry_Form::OPTION);
        delete_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION);

        parent::tear_down();
    }

    /**
     * Log in as a new user with the given role.
     */
    protected function login_as($role) {
        $user_id = self::factory()->user->create(array('role' => $role));
        wp_set_current_user($user_id);

        return $user_id;
    }

    /**
     * Run an ability the way a client does: input schema, permission callback,
     * execute callback, output schema.
     *
     * @return array|WP_Error
     */
    protected function run_ability($name, $input = array()) {
        $ability = wp_get_ability('nyuchi-travel/' . $name);
        $this->assertInstanceOf(WP_Ability::class, $ability, "{$name} is registered.");

        return $ability->execute($input);
    }

    /**
     * The result of a call a permission callback refused. WordPress reports
     * every refused ability call as incorrect usage, so the test expects it.
     */
    protected function assertForbidden($result) {
        $this->setExpectedIncorrectUsage('WP_Ability::execute');
        $this->assertWPError($result);
        $this->assertContains(
            $result->get_error_code(),
            array('wta_forbidden', 'ability_invalid_permissions'),
            'The call is refused by the permission check, not by something later.'
        );
    }

    /**
     * A published trip.
     */
    protected function trip($title = 'Falls and Fireflies') {
        return self::factory()->post->create(array(
            'post_type'   => WTA_Trip::post_type(),
            'post_title'  => $title,
            'post_status' => 'publish',
        ));
    }

    /**
     * An enquiry as WP Travel stores it.
     *
     * @param array $args trip, date (Y-m-d H:i:s, GMT), status, name, email, message.
     */
    protected function enquiry($args = array()) {
        $args = array_merge(array(
            'trip'    => 0,
            'date'    => '2026-03-10 09:00:00',
            'status'  => 'unread',
            'name'    => 'Tendai Moyo',
            'email'   => 'tendai@example.test',
            'message' => 'Is there a river lodge in June?',
        ), $args);

        return self::factory()->post->create(array(
            'post_type'     => WTA_Inquiry_Abilities::POST_TYPE,
            'post_status'   => 'publish',
            'post_title'    => 'Enquiry',
            'post_date'     => $args['date'],
            'post_date_gmt' => $args['date'],
            'meta_input'    => array(
                'wp_travel_post_id'        => $args['trip'],
                'wp_travel_enquiry_name'   => $args['name'],
                'wp_travel_enquiry_email'  => $args['email'],
                'wp_travel_enquiry_query'  => $args['message'],
                'wp_travel_enquiry_status' => $args['status'],
            ),
        ));
    }

    /**
     * The autoload flag stored for an option, or null when it does not exist.
     */
    protected function autoload_flag($option) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
    }
}
