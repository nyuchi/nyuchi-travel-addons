<?php
/**
 * Stand-ins for the two WP Travel functions the enquiry form layer calls.
 *
 * WP Travel is not installed in the test site. These mirror what WP Travel 12
 * does that matters here: build the field list, pass it through the
 * wp_travel_enquiries_form_fields filter, and echo a form for the trip in the
 * global $post. They are defined when a test asks for them (not when the file
 * is loaded) and, like any PHP function, stay defined for the rest of the run,
 * so a test that needs WP Travel to be absent must check function_exists().
 *
 * @package WPTravelAddons\Tests
 */

if (!function_exists('wptravel_enquiries_form_fields')) {
    function wptravel_enquiries_form_fields() {
        $fields = array(
            'post_id' => array(
                'type'     => 'hidden',
                'name'     => 'wp_travel_enquiry_post_id',
                'priority' => 1,
            ),
            'full_name' => array(
                'type'        => 'text',
                'label'       => 'Full Name',
                'name'        => 'wp_travel_enquiry_name',
                'id'          => 'wp-travel-enquiry-name',
                'placeholder' => 'Enter your full name',
                'validations' => array('required' => '1', 'maxlength' => '80'),
                'priority'    => 30,
            ),
            'email' => array(
                'type'        => 'email',
                'label'       => 'Email',
                'name'        => 'wp_travel_enquiry_email',
                'id'          => 'wp-travel-enquiry-email',
                'placeholder' => 'Enter your email',
                'validations' => array('required' => '1'),
                'priority'    => 60,
            ),
            'note' => array(
                'type'        => 'textarea',
                'label'       => 'Enquiry Message',
                'name'        => 'wp_travel_enquiry_query',
                'id'          => 'wp-travel-enquiry-query',
                'placeholder' => 'Enter your enquiry',
                'validations' => array('required' => '1'),
                'priority'    => 90,
            ),
        );

        return apply_filters('wp_travel_enquiries_form_fields', $fields);
    }
}

if (!function_exists('wptravel_get_enquiries_form')) {
    function wptravel_get_enquiries_form($with_trip_dropdown = false) {
        global $post;

        echo '<form class="wp-travel-enquiries-form" data-trip="' . (int) ($post ? $post->ID : 0) . '"></form>';
    }
}
