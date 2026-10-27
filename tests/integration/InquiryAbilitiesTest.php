<?php
/**
 * The four enquiry abilities: get-inquiry-form, update-inquiry-form,
 * list-inquiries and get-inquiry-stats.
 *
 * WP Travel is not installed in the test site, so WP Travel's own form
 * functions do not exist here. What these tests pin down is this plugin's
 * part: who may call what, what is written and what is not, that secrets in
 * WP Travel's settings are carried through and never returned, and that
 * personal data is opt-in. The form as rendered through the filters is in
 * InquiryFormModuleTest.
 *
 * @package WPTravelAddons\Tests
 */

require_once dirname(__DIR__) . '/includes/class-wta-inquiry-test-case.php';

class InquiryAbilitiesTest extends WTA_Inquiry_Test_Case {

    const SECRET = 'SECRET-KEY-ZZZ-do-not-leak';

    /**
     * A WP Travel settings blob the way a live site has it: the keys this layer
     * manages, the nested enquiry template, and keys it must never touch or show.
     */
    protected function wp_travel_settings() {
        return array(
            'recaptcha_v2_site_key'            => 'SITE-KEY-AAA',
            'recaptcha_v2_secret_key'          => self::SECRET,
            'stripe_secret_key'                => 'sk_test_hidden',
            'currency'                         => 'USD',
            'wp_travel_from_email'             => 'from@example.test',
            'wp_travel_gdpr_message'           => 'Old consent text',
            'open_gdpr_in_new_tab'             => 'no',
            'enable_trip_enquiry_option'       => 'yes',
            'enquiry_admin_template_settings'  => array(
                'admin_subject' => 'Old subject',
                'admin_title'   => 'Keep this title',
            ),
        );
    }

    protected function seed_wp_travel_settings() {
        $settings = $this->wp_travel_settings();
        update_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION, $settings);

        return $settings;
    }

    /* ------------------------------------------------------------ registration */

    public function test_all_four_abilities_are_registered_under_the_travel_namespace() {
        foreach (array('get-inquiry-form', 'update-inquiry-form', 'list-inquiries', 'get-inquiry-stats') as $name) {
            $this->assertInstanceOf(WP_Ability::class, wp_get_ability('nyuchi-travel/' . $name), $name);
        }
    }

    /* -------------------------------------------------------- get-inquiry-form */

    public function test_only_administrators_can_read_the_form_configuration() {
        foreach (array('editor', 'author', 'contributor', 'subscriber') as $role) {
            $this->login_as($role);
            $this->assertForbidden($this->run_ability('get-inquiry-form'));
        }

        wp_set_current_user(0);
        $this->assertForbidden($this->run_ability('get-inquiry-form'));

        $this->login_as('administrator');
        $result = $this->run_ability('get-inquiry-form');

        $this->assertIsArray($result);

        // WP Travel is not installed in the test site. (InquiryFormModuleTest
        // defines stand-ins for its form functions, and PHP cannot undefine them.)
        if (!function_exists('wptravel_enquiries_form_fields')) {
            $this->assertFalse($result['available']);
            $this->assertSame(array(), $result['fields']);
        }
    }

    public function test_the_form_configuration_never_contains_a_key_or_secret() {
        $this->seed_wp_travel_settings();
        $this->login_as('administrator');

        $result = $this->run_ability('get-inquiry-form');
        $json   = wp_json_encode($result);

        $this->assertIsArray($result);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString('SITE-KEY-AAA', $json);
        $this->assertStringNotContainsString('sk_test_hidden', $json);
        $this->assertTrue($result['anti_spam']['recaptcha_keys_set'], 'Reported as yes or no only.');
        $this->assertFalse($result['anti_spam']['recaptcha_on_enquiry']);
        $this->assertSame('Old consent text', $result['consent']['text']);
        $this->assertSame('Old subject', $result['notification']['subject']);
        $this->assertSame('from@example.test', $result['notification']['from_email']);
    }

    public function test_the_consent_box_is_reported_as_hidden_until_a_privacy_page_is_set() {
        $this->login_as('administrator');

        $this->assertFalse($this->run_ability('get-inquiry-form')['consent']['shown']);

        $page = self::factory()->post->create(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Privacy'));
        update_option('wp_page_for_privacy_policy', $page);

        $this->assertTrue($this->run_ability('get-inquiry-form')['consent']['shown']);
    }

    /* ----------------------------------------------------- update-inquiry-form */

    public function test_only_administrators_can_update_the_form_and_nothing_is_written_for_anyone_else() {
        $settings = $this->seed_wp_travel_settings();
        $input    = array('consent_text' => 'Changed', 'recipients' => array('a@example.test'), 'dry_run' => false);

        foreach (array('editor', 'author', 'contributor', 'subscriber', null) as $role) {
            $role ? $this->login_as($role) : wp_set_current_user(0);

            $this->assertForbidden($this->run_ability('update-inquiry-form', $input));
        }

        $this->assertSame($settings, get_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION));
        $this->assertFalse(get_option(WTA_Inquiry_Form::OPTION));
    }

    public function test_the_default_is_a_dry_run_that_reports_the_change_and_writes_nothing() {
        $settings = $this->seed_wp_travel_settings();
        $this->login_as('administrator');

        $result = $this->run_ability('update-inquiry-form', array(
            'consent_text'    => 'We reply within a day.',
            'admin_subject'   => 'New trip enquiry',
            'recipients'      => array('sales@example.test'),
            'fields'          => array('note' => array('label' => 'Tell us about your trip')),
            'extras'          => array(array('key' => 'phone', 'label' => 'Phone')),
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['dry_run']);
        $this->assertEqualsCanonicalizing(
            array('fields.note', 'extras', 'recipients', 'consent_text', 'admin_subject'),
            $result['changed']
        );
        $this->assertSame('Old consent text', $result['result']['consent_text']['from']);
        $this->assertSame('We reply within a day.', $result['result']['consent_text']['to']);
        $this->assertSame(array(), $result['rejected']);

        $this->assertSame($settings, get_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION), 'WP Travel settings untouched.');
        $this->assertFalse(get_option(WTA_Inquiry_Form::OPTION), 'Our option not created.');
    }

    public function test_an_explicit_dry_run_true_also_writes_nothing() {
        $this->login_as('administrator');

        $result = $this->run_ability('update-inquiry-form', array('recipients' => array('sales@example.test'), 'dry_run' => true));

        $this->assertTrue($result['dry_run']);
        $this->assertSame(array('recipients'), $result['changed']);
        $this->assertFalse(get_option(WTA_Inquiry_Form::OPTION));
    }

    public function test_applying_changes_only_the_keys_owned_and_carries_every_other_wp_travel_setting_through() {
        $settings = $this->seed_wp_travel_settings();
        $this->login_as('administrator');

        $result = $this->run_ability('update-inquiry-form', array(
            'consent_text'    => 'We reply within a day.',
            'consent_new_tab' => true,
            'enabled'         => false,
            'admin_subject'   => 'New trip enquiry',
            'dry_run'         => false,
        ));

        $this->assertFalse($result['dry_run']);
        $this->assertSame(array(), $result['rejected']);

        $expected = $settings;
        $expected['wp_travel_gdpr_message']                         = 'We reply within a day.';
        $expected['open_gdpr_in_new_tab']                           = 'yes';
        $expected['enable_trip_enquiry_option']                     = 'no';
        $expected['enquiry_admin_template_settings']['admin_subject'] = 'New trip enquiry';

        $saved = get_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION);

        $this->assertSame($expected, $saved, 'Everything else in the blob, including both reCAPTCHA keys and the template title, is exactly as it was.');
        $this->assertSame(self::SECRET, $saved['recaptcha_v2_secret_key']);
        $this->assertSame('Keep this title', $saved['enquiry_admin_template_settings']['admin_title']);
        $this->assertStringNotContainsString(self::SECRET, wp_json_encode($result), 'No secret in the response either.');
    }

    public function test_an_update_of_our_own_settings_does_not_rewrite_wp_travels_option() {
        $settings = $this->seed_wp_travel_settings();
        $this->login_as('administrator');

        $result = $this->run_ability('update-inquiry-form', array(
            'recipients' => array('Sales@Example.test', 'sales@example.test', 'ops@example.test'),
            'extras'     => array(
                array('key' => 'travel_month', 'label' => 'Month', 'type' => 'select', 'options' => array('June', 'July', 'June')),
            ),
            'fields'     => array('email' => array('label' => 'Your email', 'placeholder' => 'you@example.com')),
            'dry_run'    => false,
        ));

        $this->assertSame(array(), $result['rejected']);
        $this->assertSame($settings, get_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION));

        $stored = WTA_Inquiry_Form::stored();

        $this->assertSame(array('sales@example.test', 'ops@example.test'), $stored['recipients'], 'Lower-cased and de-duplicated.');
        $this->assertSame(array('June', 'July'), $stored['extras'][0]['options']);
        $this->assertSame('Your email', $stored['fields']['email']['label']);
    }

    public function test_our_option_is_stored_with_autoload_off() {
        $this->login_as('administrator');

        $this->run_ability('update-inquiry-form', array('recipients' => array('sales@example.test'), 'dry_run' => false));

        $flag = $this->autoload_flag(WTA_Inquiry_Form::OPTION);

        $this->assertNotNull($flag, 'The option was written.');
        $this->assertNotContains($flag, wp_autoload_values_to_autoload(), "autoload is '{$flag}'.");

        // A later update keeps it off.
        $this->run_ability('update-inquiry-form', array('recipients' => array('ops@example.test'), 'dry_run' => false));

        $this->assertNotContains($this->autoload_flag(WTA_Inquiry_Form::OPTION), wp_autoload_values_to_autoload());
    }

    public function test_applying_the_same_input_twice_reports_nothing_to_change() {
        $this->login_as('administrator');
        $input = array('recipients' => array('sales@example.test'), 'consent_text' => 'Hello', 'dry_run' => false);

        $first  = $this->run_ability('update-inquiry-form', $input);
        $second = $this->run_ability('update-inquiry-form', $input);

        $this->assertNotEmpty($first['changed']);
        $this->assertSame(array(), $second['changed']);
        $this->assertStringContainsString('Nothing to change', $second['note']);
    }

    public function test_a_field_override_is_merged_property_by_property() {
        $this->login_as('administrator');

        $this->run_ability('update-inquiry-form', array('fields' => array('note' => array('label' => 'Your plans', 'placeholder' => 'Dates?')), 'dry_run' => false));
        $this->run_ability('update-inquiry-form', array('fields' => array('note' => array('label' => 'Your trip')), 'dry_run' => false));

        $this->assertSame(
            array('label' => 'Your trip', 'placeholder' => 'Dates?'),
            WTA_Inquiry_Form::stored()['fields']['note']
        );
    }

    public function test_name_and_email_cannot_be_made_optional_and_the_rest_of_the_call_still_runs() {
        $this->login_as('administrator');

        $result = $this->run_ability('update-inquiry-form', array(
            'fields'       => array(
                'full_name' => array('required' => false),
                'email'     => array('required' => false, 'label' => 'Email address'),
                'note'      => array('required' => true),
            ),
            'dry_run'      => false,
        ));

        $this->assertCount(2, $result['rejected']);
        $this->assertStringContainsString('must stay true', $result['rejected'][0]);

        $stored = WTA_Inquiry_Form::stored()['fields'];

        $this->assertArrayNotHasKey('full_name', $stored);
        $this->assertArrayNotHasKey('required', $stored['email']);
        $this->assertSame('Email address', $stored['email']['label']);
        $this->assertTrue($stored['note']['required']);
    }

    public function test_an_unknown_field_and_a_message_maxlength_are_rejected() {
        $this->login_as('administrator');

        $result = $this->run_ability('update-inquiry-form', array(
            'fields'  => array('phone' => array('label' => 'Phone'), 'note' => array('maxlength' => 500)),
            'dry_run' => false,
        ));

        $this->assertCount(2, $result['rejected']);
        $this->assertSame(array(), $result['changed']);
        $this->assertFalse(get_option(WTA_Inquiry_Form::OPTION));
    }

    public function test_an_invalid_recipient_refuses_the_whole_list() {
        $this->login_as('administrator');
        $this->run_ability('update-inquiry-form', array('recipients' => array('keep@example.test'), 'dry_run' => false));

        $result = $this->run_callback_update(array('recipients' => array('good@example.test', 'not-an-email'), 'dry_run' => false));

        $this->assertStringContainsString('not a valid email address', $result['rejected'][0]);
        $this->assertSame(array('keep@example.test'), WTA_Inquiry_Form::stored()['recipients']);
    }

    public function test_an_invalid_extra_refuses_the_whole_list_and_keeps_the_saved_one() {
        $this->login_as('administrator');
        $this->run_ability('update-inquiry-form', array('extras' => array(array('key' => 'phone', 'label' => 'Phone')), 'dry_run' => false));

        foreach (array(
            array(array('key' => 'ok_key', 'label' => 'Fine'), array('key' => 'Bad Key', 'label' => 'x')),
            array(array('key' => 'dup', 'label' => 'One'), array('key' => 'dup', 'label' => 'Two')),
            array(array('key' => 'sel', 'label' => 'Pick', 'type' => 'select', 'options' => array('Only one'))),
            array(array('key' => 'weird', 'label' => 'Weird', 'type' => 'file')),
            array(array('key' => 'nolabel', 'label' => '   ')),
        ) as $extras) {
            $result = $this->run_callback_update(array('extras' => $extras, 'dry_run' => false));

            $this->assertNotEmpty($result['rejected']);
            $this->assertStringContainsString('Nothing was changed', end($result['rejected']));
            $this->assertSame('phone', WTA_Inquiry_Form::stored()['extras'][0]['key']);
            $this->assertCount(1, WTA_Inquiry_Form::stored()['extras']);
        }
    }

    public function test_more_than_five_extras_or_recipients_are_refused() {
        $this->login_as('administrator');

        $extras = array();
        $mails  = array();
        for ($i = 1; $i <= 6; $i++) {
            $extras[] = array('key' => 'field_' . $i, 'label' => 'Field ' . $i);
            $mails[]  = "user{$i}@example.test";
        }

        $this->assertWPError($this->run_ability('update-inquiry-form', array('extras' => $extras, 'dry_run' => false)), 'The input schema refuses it.');
        $this->assertWPError($this->run_ability('update-inquiry-form', array('recipients' => $mails, 'dry_run' => false)));

        // And the callback refuses it too, for a client that skips the schema.
        $result = $this->run_callback_update(array('extras' => $extras, 'recipients' => $mails, 'dry_run' => false));

        $this->assertCount(2, $result['rejected']);
        $this->assertFalse(get_option(WTA_Inquiry_Form::OPTION));
    }

    public function test_text_is_sanitised_before_it_is_stored() {
        $this->login_as('administrator');

        $this->run_ability('update-inquiry-form', array(
            'consent_text'  => 'We <script>alert(1)</script>reply <b>fast</b>.',
            'admin_subject' => "New <i>enquiry</i>\n",
            'fields'        => array('note' => array('label' => '<img src=x onerror=alert(1)>Plans', 'placeholder' => '<b>When?</b>')),
            'extras'        => array(array('key' => 'phone', 'label' => 'Phone <script>x</script>', 'placeholder' => '<u>+263</u>')),
            'dry_run'       => false,
        ));

        $wpt = get_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION);

        $this->assertStringNotContainsString('<', $wpt['wp_travel_gdpr_message']);
        $this->assertStringNotContainsString('<', $wpt['enquiry_admin_template_settings']['admin_subject']);

        $stored = WTA_Inquiry_Form::stored();

        $this->assertStringNotContainsString('<', $stored['fields']['note']['label']);
        $this->assertStringNotContainsString('<', $stored['fields']['note']['placeholder']);
        $this->assertStringNotContainsString('<', $stored['extras'][0]['label']);
        $this->assertStringNotContainsString('<', $stored['extras'][0]['placeholder']);
    }

    public function test_an_empty_recipient_list_goes_back_to_the_site_admin_email() {
        $this->login_as('administrator');
        $this->run_ability('update-inquiry-form', array('recipients' => array('sales@example.test'), 'dry_run' => false));

        $this->run_ability('update-inquiry-form', array('recipients' => array(), 'dry_run' => false));

        $this->assertSame(array(), WTA_Inquiry_Form::stored()['recipients']);
        $this->assertSame(array(get_option('admin_email')), WTA_Inquiry_Form::effective_recipients()['addresses']);
    }

    /**
     * The update callback past the input schema, for a client that skips it.
     */
    protected function run_callback_update($input) {
        $ability  = wp_get_ability('nyuchi-travel/update-inquiry-form');
        $property = new ReflectionProperty(WP_Ability::class, 'execute_callback');

        return call_user_func($property->getValue($ability), $input);
    }

    /* ---------------------------------------------------------- list-inquiries */

    public function test_enquiries_can_be_listed_by_editors_and_administrators_only() {
        $this->enquiry();

        foreach (array('author', 'contributor', 'subscriber', null) as $role) {
            $role ? $this->login_as($role) : wp_set_current_user(0);

            $this->assertForbidden($this->run_ability('list-inquiries'));
        }

        foreach (array('editor', 'administrator') as $role) {
            $this->login_as($role);

            $result = $this->run_ability('list-inquiries');

            $this->assertIsArray($result, "{$role} may list enquiries.");
            $this->assertSame(1, $result['total']);
        }
    }

    public function test_personal_data_is_opt_in() {
        $trip = $this->trip();
        $id   = $this->enquiry(array('trip' => $trip));
        $this->login_as('editor');

        $plain = $this->run_ability('list-inquiries');
        $row   = $plain['inquiries'][0];

        $this->assertSame(array('id', 'trip_id', 'trip_title', 'date_gmt', 'status'), array_keys($row));
        $this->assertSame($id, $row['id']);
        $this->assertSame($trip, $row['trip_id']);
        $this->assertSame('Falls and Fireflies', $row['trip_title']);
        $this->assertSame('unread', $row['status']);

        $json = wp_json_encode($plain);
        foreach (array('Tendai', 'tendai@example.test', 'river lodge') as $private) {
            $this->assertStringNotContainsString($private, $json);
        }

        $contact = $this->run_ability('list-inquiries', array('include_contact' => true))['inquiries'][0];

        $this->assertSame('Tendai Moyo', $contact['name']);
        $this->assertSame('tendai@example.test', $contact['email']);
        $this->assertArrayNotHasKey('message', $contact);

        $message = $this->run_ability('list-inquiries', array('include_message' => true))['inquiries'][0];

        $this->assertSame('Is there a river lodge in June?', $message['message']);
        $this->assertArrayNotHasKey('name', $message);
        $this->assertArrayNotHasKey('email', $message);
    }

    public function test_the_message_is_cut_to_280_characters() {
        $this->enquiry(array('message' => str_repeat('é', 400)));
        $this->login_as('editor');

        $row = $this->run_ability('list-inquiries', array('include_message' => true))['inquiries'][0];

        $this->assertSame(280, mb_strlen($row['message']));
    }

    public function test_enquiries_are_newest_first_and_can_be_filtered_by_trip_status_and_date() {
        $a = $this->trip('Trip A');
        $b = $this->trip('Trip B');

        $old    = $this->enquiry(array('trip' => $a, 'date' => '2026-01-05 10:00:00', 'status' => 'read'));
        $middle = $this->enquiry(array('trip' => $b, 'date' => '2026-02-05 10:00:00', 'status' => 'unread'));
        $new    = $this->enquiry(array('trip' => $a, 'date' => '2026-03-05 10:00:00', 'status' => 'unread'));
        $this->login_as('editor');

        $ids = function ($input) {
            return wp_list_pluck($this->run_ability('list-inquiries', $input)['inquiries'], 'id');
        };

        $this->assertSame(array($new, $middle, $old), $ids(array()));
        $this->assertSame(array($new, $old), $ids(array('trip_id' => $a)));
        $this->assertSame(array($new, $middle), $ids(array('status' => 'unread')));
        $this->assertSame(array($old), $ids(array('status' => 'read')));
        $this->assertSame(array($new, $middle), $ids(array('since' => '2026-02-05')), 'since is inclusive.');
        $this->assertSame(array($new), $ids(array('since' => '2026-02-06')));
    }

    public function test_pages_hold_at_most_fifty_and_report_totals() {
        for ($i = 0; $i < 3; $i++) {
            $this->enquiry(array('date' => '2026-03-0' . ($i + 1) . ' 10:00:00'));
        }
        $this->login_as('editor');

        $result = $this->run_ability('list-inquiries', array('per_page' => 2));

        $this->assertCount(2, $result['inquiries']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['pages']);
        $this->assertWPError($this->run_ability('list-inquiries', array('per_page' => 51)));
    }

    public function test_an_impossible_date_is_refused() {
        $this->login_as('editor');

        $result = $this->run_ability('list-inquiries', array('since' => '2026-13-45'));

        $this->assertWPError($result);
        $this->assertSame('wta_bad_date', $result->get_error_code());
    }

    public function test_an_enquiry_about_a_deleted_trip_still_lists_with_trip_zero() {
        $trip = $this->trip();
        $this->enquiry(array('trip' => $trip));
        wp_delete_post($trip, true);
        $this->login_as('editor');

        $row = $this->run_ability('list-inquiries')['inquiries'][0];

        $this->assertSame(0, $row['trip_id']);
        $this->assertSame('', $row['trip_title']);
    }

    public function test_listing_says_so_when_wp_travel_is_not_there() {
        unregister_post_type(WTA_Inquiry_Abilities::POST_TYPE);
        $this->login_as('editor');

        $result = $this->run_ability('list-inquiries');

        $this->assertWPError($result);
        $this->assertSame('wta_post_type_missing', $result->get_error_code());
        $this->assertSame(409, $result->get_error_data()['status']);
    }

    /* ------------------------------------------------------ get-inquiry-stats */

    public function test_stats_need_the_same_capability_as_listing() {
        foreach (array('author', 'contributor', 'subscriber', null) as $role) {
            $role ? $this->login_as($role) : wp_set_current_user(0);

            $this->assertForbidden($this->run_ability('get-inquiry-stats'));
        }

        foreach (array('editor', 'administrator') as $role) {
            $this->login_as($role);

            $this->assertIsArray($this->run_ability('get-inquiry-stats'), $role);
        }
    }

    public function test_stats_count_per_trip_month_and_summary_without_any_personal_data() {
        $a = $this->trip('Trip A');
        $b = $this->trip('Trip B');
        $this->trip('Trip C');

        $this->enquiry(array('trip' => $a, 'date' => '2026-01-10 10:00:00', 'status' => 'read'));
        $this->enquiry(array('trip' => $a, 'date' => '2026-03-02 10:00:00'));
        $this->enquiry(array('trip' => $a, 'date' => '2026-03-20 10:00:00'));
        $this->enquiry(array('trip' => $b, 'date' => '2026-03-21 10:00:00'));
        $this->enquiry(array('trip' => 0, 'date' => '2026-03-22 10:00:00'));
        $this->login_as('editor');

        $result = $this->run_ability('get-inquiry-stats', array('since' => '2026-01-01', 'until' => '2026-03-31'));

        $this->assertIsArray($result);
        $this->assertSame(5, $result['summary']['enquiries']);
        $this->assertSame(4, $result['summary']['unread']);
        $this->assertSame(2, $result['summary']['trips_with_enquiries'], 'The deleted-trip enquiry has no trip row.');
        $this->assertSame(3, $result['summary']['published_trips']);
        $this->assertEquals(1.67, $result['summary']['enquiries_per_published_trip']);
        $this->assertFalse($result['truncated']);

        $this->assertSame(array('Trip A', 'Trip B'), wp_list_pluck($result['by_trip'], 'title'));
        $this->assertSame(array(3, 1), wp_list_pluck($result['by_trip'], 'enquiries'));
        $this->assertSame(array(2, 1), wp_list_pluck($result['by_trip'], 'unread'));

        $this->assertSame(
            array('2026-01' => 1, '2026-02' => 0, '2026-03' => 4),
            array_column($result['by_month'], 'enquiries', 'month'),
            'Every month in the window is present, even an empty one.'
        );

        $json = wp_json_encode($result);
        foreach (array('Tendai', 'tendai@example.test', 'river lodge') as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_stats_window_includes_the_whole_of_the_last_day_and_checks_its_order() {
        $trip = $this->trip();
        $this->enquiry(array('trip' => $trip, 'date' => '2026-03-31 23:30:00'));
        $this->enquiry(array('trip' => $trip, 'date' => '2026-04-01 00:30:00'));
        $this->login_as('editor');

        $result = $this->run_ability('get-inquiry-stats', array('since' => '2026-03-01', 'until' => '2026-03-31'));

        $this->assertSame(1, $result['summary']['enquiries']);

        $bad = $this->run_ability('get-inquiry-stats', array('since' => '2026-04-01', 'until' => '2026-03-01'));
        $this->assertWPError($bad);
        $this->assertSame('wta_bad_window', $bad->get_error_code());

        $bad = $this->run_ability('get-inquiry-stats', array('since' => '2026-02-30'));
        $this->assertWPError($bad);
        $this->assertSame('wta_bad_date', $bad->get_error_code());
    }

    public function test_stats_can_list_trips_with_no_enquiries_after_those_with_some() {
        $a = $this->trip('Alpha');
        $this->trip('Bravo');
        $this->enquiry(array('trip' => $a, 'date' => '2026-03-02 10:00:00'));
        $this->login_as('editor');

        $window = array('since' => '2026-03-01', 'until' => '2026-03-31');

        $without = $this->run_ability('get-inquiry-stats', $window);
        $with    = $this->run_ability('get-inquiry-stats', $window + array('include_zero' => true));

        $this->assertSame(array('Alpha'), wp_list_pluck($without['by_trip'], 'title'));
        $this->assertSame(array('Alpha', 'Bravo'), wp_list_pluck($with['by_trip'], 'title'));
        $this->assertSame(0, $with['by_trip'][1]['enquiries']);
    }

    public function test_stats_count_a_trip_once_in_each_of_its_destinations() {
        register_taxonomy('travel_locations', WTA_Trip::post_type(), array('hierarchical' => true));
        $this->registered_taxes[] = 'travel_locations';

        $a = $this->trip('Trip A');
        $b = $this->trip('Trip B');
        $zim = self::factory()->term->create(array('taxonomy' => 'travel_locations', 'name' => 'Zimbabwe'));
        $bot = self::factory()->term->create(array('taxonomy' => 'travel_locations', 'name' => 'Botswana'));
        wp_set_object_terms($a, array($zim, $bot), 'travel_locations');
        wp_set_object_terms($b, array($bot), 'travel_locations');

        $this->enquiry(array('trip' => $a, 'date' => '2026-03-02 10:00:00'));
        $this->enquiry(array('trip' => $a, 'date' => '2026-03-03 10:00:00'));
        $this->enquiry(array('trip' => $b, 'date' => '2026-03-04 10:00:00'));
        $this->login_as('editor');

        $result = $this->run_ability('get-inquiry-stats', array('since' => '2026-03-01', 'until' => '2026-03-31'));

        $this->assertSame(3, $result['summary']['enquiries']);
        $this->assertSame(
            array('Botswana' => 3, 'Zimbabwe' => 2),
            array_column($result['by_destination'], 'enquiries', 'name')
        );
    }

    public function test_stats_say_so_when_wp_travel_is_not_there() {
        unregister_post_type(WTA_Inquiry_Abilities::POST_TYPE);
        $this->login_as('editor');

        $result = $this->run_ability('get-inquiry-stats');

        $this->assertWPError($result);
        $this->assertSame('wta_post_type_missing', $result->get_error_code());
    }
}
