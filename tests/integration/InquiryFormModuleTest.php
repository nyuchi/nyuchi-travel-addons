<?php
/**
 * The inquiry_form module: WP Travel's enquiry form, customised.
 *
 * The module hooks WP Travel's own filters. Until something is saved with
 * update-inquiry-form those filters must hand WP Travel back exactly what it
 * gave them, so switching the module on changes nothing on a live site. Once
 * something is saved, it is applied without changing the input names WP Travel
 * saves on.
 *
 * WP Travel is not installed in the test site; tests/includes/wp-travel-
 * enquiry-stubs.php supplies the two functions the module calls.
 *
 * @package WPTravelAddons\Tests
 */

require_once dirname(__DIR__) . '/includes/class-wta-inquiry-test-case.php';

class InquiryFormModuleTest extends WTA_Inquiry_Test_Case {

    public function set_up() {
        parent::set_up();

        require_once dirname(__DIR__) . '/includes/wp-travel-enquiry-stubs.php';
    }

    /**
     * WP Travel's field list before any filter, with the filters put back
     * afterwards so the test can go on to save something and look again.
     */
    protected function default_fields() {
        global $wp_filter;

        $hook = 'wp_travel_enquiries_form_fields';
        $held = isset($wp_filter[$hook]) ? clone $wp_filter[$hook] : null;

        remove_all_filters($hook);
        $fields = wptravel_enquiries_form_fields();

        if (null !== $held) {
            $wp_filter[$hook] = $held;
        }

        return $fields;
    }

    /**
     * A fresh instance of the module, to call a filter method directly.
     */
    protected function module() {
        return new WTA_Inquiry_Form();
    }

    protected function save($input) {
        $this->login_as('administrator');

        $result = $this->run_ability('update-inquiry-form', $input + array('dry_run' => false));

        $this->assertIsArray($result);
        $this->assertSame(array(), $result['rejected'], 'The test input is valid.');

        return $result;
    }

    /* ----------------------------------------------- nothing changes until saved */

    public function test_the_module_is_on_by_default_and_hooks_wp_travels_filters() {
        $this->assertTrue(WP_Travel_Addons::module_enabled('inquiry_form'));
        $this->assertNotFalse(has_filter('wp_travel_enquiries_form_fields'));
        $this->assertNotFalse(has_filter('wp_travel_enquiries_admin_emails'));
        $this->assertNotFalse(has_filter('wp_travel_admin_enquery_email_tags'));
    }

    public function test_with_nothing_saved_the_form_the_recipients_and_the_email_are_unchanged() {
        $module = $this->module();

        $fields = array('full_name' => array('type' => 'text', 'label' => 'Full Name', 'validations' => array('required' => '1')));
        $tags   = array('{customer_note}' => 'Hello', '{customer_name}' => 'Tendai');

        $this->assertSame($fields, $module->filter_fields($fields));
        $this->assertSame('admin@example.test', $module->filter_recipients('admin@example.test'));
        $this->assertSame($tags, $module->filter_email_tags($tags, array('wta_enquiry_phone' => '123')));

        // The real hooks agree with the direct calls.
        $this->assertSame(wptravel_enquiries_form_fields(), $this->default_fields());
        $this->assertSame('admin@example.test', apply_filters('wp_travel_enquiries_admin_emails', 'admin@example.test'));
        $this->assertSame($tags, apply_filters('wp_travel_admin_enquery_email_tags', $tags, array('wta_enquiry_phone' => '123')));

        $this->assertFalse(get_option(WTA_Inquiry_Form::OPTION), 'Reading the form creates no option.');
        $this->assertFalse(get_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION));
    }

    public function test_a_dry_run_changes_nothing_on_the_form() {
        $before = wptravel_enquiries_form_fields();

        $this->login_as('administrator');
        $this->run_ability('update-inquiry-form', array(
            'fields'     => array('note' => array('label' => 'Your plans')),
            'extras'     => array(array('key' => 'phone', 'label' => 'Phone')),
            'recipients' => array('sales@example.test'),
        ));

        $this->assertSame($before, wptravel_enquiries_form_fields());
        $this->assertSame(get_option('admin_email'), apply_filters('wp_travel_enquiries_admin_emails', get_option('admin_email')));
    }

    public function test_unusable_filter_input_and_unusable_stored_data_are_passed_through() {
        $module = $this->module();

        $this->assertSame('not a list', $module->filter_fields('not a list'));
        $this->assertSame('x', $module->filter_email_tags('x', array()));

        update_option(WTA_Inquiry_Form::OPTION, 'junk', false);

        $fields = array('note' => array('label' => 'Message'));

        $this->assertSame($fields, $module->filter_fields($fields));
        $this->assertSame('admin@example.test', $module->filter_recipients('admin@example.test'));
        $this->assertSame(array('fields' => array(), 'extras' => array(), 'recipients' => array()), WTA_Inquiry_Form::stored());
    }

    /* ---------------------------------------------------- once saved, applied */

    public function test_saved_labels_placeholders_and_flags_are_applied_and_input_names_never_change() {
        $before = $this->default_fields();

        $this->save(array('fields' => array(
            'full_name' => array('label' => 'Your name', 'maxlength' => 40),
            'note'      => array('label' => 'Tell us about your trip', 'placeholder' => 'Dates, group size', 'required' => false),
        )));

        $after = wptravel_enquiries_form_fields();

        $this->assertSame('Your name', $after['full_name']['label']);
        $this->assertSame('40', $after['full_name']['validations']['maxlength']);
        $this->assertSame('Tell us about your trip', $after['note']['label']);
        $this->assertSame('Dates, group size', $after['note']['attributes']['placeholder']);
        $this->assertArrayNotHasKey('required', $after['note']['validations'], 'Message made optional.');

        foreach (array('full_name', 'email', 'note') as $key) {
            $this->assertSame($before[$key]['name'], $after[$key]['name'], "{$key} keeps the input name WP Travel saves on.");
        }

        $this->assertSame($before['email'], $after['email'], 'A field with no override is untouched.');
        $this->assertSame($before['post_id'], $after['post_id']);
    }

    public function test_extra_fields_are_added_between_the_email_and_the_message_with_a_prefixed_name() {
        $this->save(array('extras' => array(
            array('key' => 'phone', 'label' => 'Phone', 'required' => true, 'placeholder' => '+263'),
            array('key' => 'month', 'label' => 'Travel month', 'type' => 'select', 'options' => array('June', 'July')),
            array('key' => 'plans', 'label' => 'Plans', 'type' => 'textarea'),
        )));

        $fields = wptravel_enquiries_form_fields();

        $this->assertSame('wta_enquiry_phone', $fields['wta_enquiry_phone']['name']);
        $this->assertSame('wta-enquiry-phone', $fields['wta_enquiry_phone']['id']);
        $this->assertSame('1', $fields['wta_enquiry_phone']['validations']['required']);
        $this->assertSame('+263', $fields['wta_enquiry_phone']['attributes']['placeholder']);
        $this->assertSame(array('', 'June', 'July'), array_keys($fields['wta_enquiry_month']['options']));
        $this->assertSame('textarea', $fields['wta_enquiry_plans']['type']);

        foreach (array('wta_enquiry_phone', 'wta_enquiry_month', 'wta_enquiry_plans') as $name) {
            $this->assertGreaterThan($fields['email']['priority'], $fields[$name]['priority']);
            $this->assertLessThan($fields['note']['priority'], $fields[$name]['priority']);
        }
    }

    public function test_get_inquiry_form_describes_the_form_as_it_now_renders() {
        $this->save(array(
            'fields'     => array('note' => array('label' => 'Your plans')),
            'extras'     => array(array('key' => 'month', 'label' => 'Travel month', 'type' => 'select', 'options' => array('June', 'July'))),
            'recipients' => array('sales@example.test'),
        ));

        $result = $this->run_ability('get-inquiry-form');

        $this->assertTrue($result['available']);
        $this->assertSame(
            array('full_name', 'email', 'wta_enquiry_month', 'note'),
            wp_list_pluck($result['fields'], 'key'),
            'In display order, and without the hidden plumbing field.'
        );

        $by_key = array_column($result['fields'], null, 'key');

        $this->assertSame('Your plans', $by_key['note']['label']);
        $this->assertSame('wp-travel', $by_key['note']['source']);
        $this->assertSame('nyuchi-extra', $by_key['wta_enquiry_month']['source']);
        $this->assertSame(array('June', 'July'), $by_key['wta_enquiry_month']['options']);
        $this->assertTrue($by_key['email']['required']);
        $this->assertSame(array('sales@example.test'), $result['notification']['recipients']);
        $this->assertSame('wta_inquiry_form option', $result['notification']['source']);
    }

    public function test_notifications_go_to_the_saved_addresses_until_they_are_cleared() {
        $this->save(array('recipients' => array('sales@example.test', 'ops@example.test')));

        $this->assertSame(
            array('sales@example.test', 'ops@example.test'),
            apply_filters('wp_travel_enquiries_admin_emails', 'admin@example.test')
        );

        $this->save(array('recipients' => array()));

        $this->assertSame('admin@example.test', apply_filters('wp_travel_enquiries_admin_emails', 'admin@example.test'));
    }

    public function test_extra_answers_are_appended_to_the_email_and_escaped() {
        $this->save(array('extras' => array(
            array('key' => 'phone', 'label' => 'Phone'),
            array('key' => 'month', 'label' => 'Month'),
        )));

        $tags = apply_filters(
            'wp_travel_admin_enquery_email_tags',
            array('{customer_note}' => 'Hello', '{customer_name}' => 'Tendai'),
            array('wta_enquiry_phone' => '<script>alert(1)</script>+263', 'wta_enquiry_month' => '  ')
        );

        $this->assertSame('Tendai', $tags['{customer_name}']);
        $this->assertStringStartsWith('Hello<br><br><b>Phone:</b> ', $tags['{customer_note}']);
        $this->assertStringNotContainsString('<script>', $tags['{customer_note}']);
        $this->assertStringContainsString('&lt;script&gt;', $tags['{customer_note}']);
        $this->assertStringNotContainsString('Month', $tags['{customer_note}'], 'A blank answer is left out.');

        // No note tag to extend: left alone rather than invented.
        $bare = array('{customer_name}' => 'Tendai');
        $this->assertSame($bare, apply_filters('wp_travel_admin_enquery_email_tags', $bare, array('wta_enquiry_phone' => '1')));
    }

    /* ---------------------------------------------------------- the widget side */

    public function test_the_form_renders_for_a_trip_only_and_the_global_post_is_restored() {
        $trip = $this->trip();
        $page = self::factory()->post->create(array('post_type' => 'page', 'post_status' => 'publish'));

        $GLOBALS['post'] = get_post($page);

        $html = WTA_Inquiry_Form::render_for_trip($trip);

        $this->assertStringContainsString('data-trip="' . $trip . '"', $html);
        $this->assertSame($page, $GLOBALS['post']->ID, 'The page being rendered is put back.');
        $this->assertSame('', WTA_Inquiry_Form::render_for_trip($page), 'Not a trip.');
        $this->assertSame('', WTA_Inquiry_Form::render_for_trip(0));
        $this->assertSame('', WTA_Inquiry_Form::render_for_trip(PHP_INT_MAX));
    }

    public function test_a_trip_price_comes_from_its_meta_and_a_zero_price_is_no_price() {
        $priced = $this->trip('Priced');
        $free   = $this->trip('Unpriced');
        update_post_meta($priced, 'wp_travel_trip_price', '1850');
        update_post_meta($free, 'wp_travel_trip_price', '0');

        $this->assertSame(array('amount' => 1850.0, 'per' => 'person'), WTA_Inquiry_Form::trip_price($priced));
        $this->assertSame(0.0, WTA_Inquiry_Form::trip_price($free)['amount']);
    }
}
