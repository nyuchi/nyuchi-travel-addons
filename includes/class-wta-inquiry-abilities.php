<?php
/**
 * Abilities for WP Travel's enquiry form and the enquiries it collects.
 *
 * Four abilities, filed with the rest of the WP Travel set under the same
 * namespace and category (see WTA_Travel_Abilities):
 *
 *   get-inquiry-form     what the form asks, who receives it, what stands
 *                        between it and a bot. Read only. Administrators.
 *   update-inquiry-form  change that, through WTA_Inquiry_Form's validation.
 *                        Dry run by default. Administrators.
 *   list-inquiries       recent enquiries. Personal data is opt-in. Editors.
 *   get-inquiry-stats    enquiries per trip, destination and month: the
 *                        "enquiries per trip page" growth metric. Editors.
 *
 * list-travel-posts next door can already dump enquiry posts with all their
 * meta behind manage_options. These two are the deliberate counterpart: named
 * fields, minimal by default, and reachable by the people who answer enquiries
 * without making them administrators.
 *
 * Every write follows the conventions of the set: dry_run defaults to true and
 * must be explicitly false to write, refusals are listed under "rejected" and
 * do not abort the rest of the call, and the result carries before and after.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Inquiry_Abilities {

    /** WP Travel's enquiry post type. */
    const POST_TYPE = 'itinerary-enquiries';

    /** Most enquiries one stats call will read. The response says when it hit this. */
    const SCAN_LIMIT = 2000;

    /** Most trips one stats call lists. */
    const TRIP_LIMIT = 100;

    /** Enquiry message length returned by list-inquiries before it is cut. */
    const MESSAGE_LENGTH = 280;

    public static function register() {
        self::register_get_form();
        self::register_update_form();
        self::register_list_inquiries();
        self::register_stats();
    }

    /* --------------------------------------------------------- permissions */

    /**
     * The form's configuration includes where enquiries are emailed, and writing
     * it changes what every visitor sees. Administrators only.
     *
     * @return true|WP_Error
     */
    public static function can_manage_form() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wta_forbidden', 'Reading or changing the enquiry form configuration requires an administrator.', array('status' => 403));
        }

        return true;
    }

    /**
     * Enquiries hold a visitor's name, email address and message. edit_posts is
     * what a contributor has, which is too low. edit_others_posts is the bar for
     * the people who handle other people's content, and does not make someone an
     * administrator.
     *
     * @return true|WP_Error
     */
    public static function can_read_inquiries() {
        if (!current_user_can('edit_others_posts')) {
            return new WP_Error('wta_forbidden', 'Reading enquiries requires the edit_others_posts capability.', array('status' => 403));
        }

        return true;
    }

    /* ------------------------------------------------------------ get form */

    protected static function register_get_form() {
        wp_register_ability(WTA_Travel_Abilities::NS . 'get-inquiry-form', array(
            'label'       => 'Read the trip enquiry form',
            'description' => 'Describe WP Travel\'s trip enquiry form as it renders now, after every filter including WP Travel Pro\'s field editor: each field with its label, placeholder, whether it is required and where it comes from; the consent text and whether the consent box appears at all; who the admin notification is sent to; the email subject; and what anti-spam protection exists. Read only. Secrets are never returned: the reCAPTCHA state is reported as yes or no. The submit button label and the "sent successfully" message are WP Travel\'s own and cannot be changed by update-inquiry-form; they are reported so a caller does not promise copy that cannot be delivered.',
            'category'    => WTA_Travel_Abilities::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'available'  => array('type' => 'boolean', 'description' => 'Whether WP Travel\'s enquiry form function exists on this site. False means everything below is stored configuration that nothing renders.'),
                    'enabled'    => array('type' => 'boolean', 'description' => 'WP Travel\'s own "enable trip enquiry" switch, which controls the Enquiry popup button on trip pages. The wta-enquiry widget renders the form regardless.'),
                    'fields'     => array(
                        'type'        => 'array',
                        'description' => 'Fields in display order.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'key'         => array('type' => 'string', 'description' => 'WP Travel\'s key: full_name, email, note, or the key of an extra field.'),
                                'name'        => array('type' => 'string', 'description' => 'The input name the form posts and WP Travel saves as post meta.'),
                                'type'        => array('type' => 'string'),
                                'label'       => array('type' => 'string'),
                                'placeholder' => array('type' => 'string'),
                                'required'    => array('type' => 'boolean'),
                                'maxlength'   => array('type' => 'integer', 'description' => '0 when unlimited.'),
                                'priority'    => array('type' => 'integer'),
                                'source'      => array('type' => 'string', 'enum' => array('wp-travel', 'nyuchi-extra', 'wp-travel-pro-field-editor')),
                                'options'     => array('type' => 'array', 'items' => array('type' => 'string')),
                            ),
                        ),
                    ),
                    'consent'    => array(
                        'type'        => 'object',
                        'description' => 'The privacy consent checkbox WP Travel appends to the form.',
                        'properties'  => array(
                            'shown'               => array('type' => 'boolean', 'description' => 'False when the site has no privacy policy page set, in which case WP Travel shows no consent box at all.'),
                            'text'                => array('type' => 'string'),
                            'open_in_new_tab'     => array('type' => 'boolean'),
                            'privacy_policy_url'  => array('type' => 'string'),
                        ),
                    ),
                    'notification' => array(
                        'type'        => 'object',
                        'properties'  => array(
                            'recipients' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Who the admin notification goes to now.'),
                            'source'     => array('type' => 'string', 'description' => 'Where those addresses come from.'),
                            'subject'    => array('type' => 'string'),
                            'title'      => array('type' => 'string'),
                            'from_email' => array('type' => 'string'),
                        ),
                    ),
                    'fixed_copy' => array(
                        'type'        => 'object',
                        'description' => 'Copy WP Travel hard-codes. Not editable here.',
                        'properties'  => array(
                            'submit_label'    => array('type' => 'string'),
                            'success_message' => array('type' => 'string'),
                        ),
                    ),
                    'anti_spam'  => array(
                        'type'        => 'object',
                        'properties'  => array(
                            'recaptcha_keys_set'       => array('type' => 'boolean'),
                            'recaptcha_on_enquiry'     => array('type' => 'boolean', 'description' => 'Whether reCAPTCHA is checked on enquiries. WP Travel 12 checks it on reviews only.'),
                            'nonce'                    => array('type' => 'boolean'),
                            'validation_filter_in_use' => array('type' => 'boolean', 'description' => 'Whether something is hooked to WP Travel\'s enquiry validation filter, which is where a honeypot or captcha plugin would attach.'),
                        ),
                    ),
                    'stored'     => array('type' => 'object', 'description' => 'The raw overrides in the wta_inquiry_form option, as update-inquiry-form would receive them.'),
                    'notes'      => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
                'required' => array('available', 'fields', 'consent', 'notification', 'anti_spam', 'notes'),
            ),

            'permission_callback' => array(__CLASS__, 'can_manage_form'),

            'execute_callback' => function () {
                $available = WTA_Inquiry_Form::form_available();
                $wpt       = WTA_Inquiry_Form::wp_travel_settings();
                $recip     = WTA_Inquiry_Form::effective_recipients();
                $policy    = function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '';
                $default   = 'By contacting us, you agree to our privacy policy.';
                $stored    = WTA_Inquiry_Form::stored();
                $notes     = array();

                if (!$available) {
                    $notes[] = 'WP Travel\'s enquiry form function is not available. Call wp-travel-status to see what is installed.';
                }

                if ('' === $policy) {
                    $notes[] = 'No privacy policy page is set (Settings, Privacy), so WP Travel renders no consent checkbox. The consent text is stored but not shown.';
                }

                if (!WTA_Inquiry_Form::anti_spam()['validation_filter_in_use']) {
                    $notes[] = 'Nothing is attached to WP Travel\'s enquiry validation filter, and reCAPTCHA is not applied to enquiries: the only protection is the request nonce.';
                }

                if ($stored['extras']) {
                    $notes[] = 'Extra fields are saved with the enquiry (in wp_travel_trip_enquiry_data) and appended under the message in the admin email. WP Travel\'s own enquiry screen shows only name, email and message.';
                }

                return array(
                    'available'    => $available,
                    'enabled'      => 'no' !== $wpt['enable_trip_enquiry_option'],
                    'fields'       => WTA_Inquiry_Form::effective_fields(),
                    'consent'      => array(
                        'shown'              => '' !== $policy,
                        'text'               => '' !== (string) $wpt['wp_travel_gdpr_message'] ? (string) $wpt['wp_travel_gdpr_message'] : $default,
                        'open_in_new_tab'    => 'yes' === $wpt['open_gdpr_in_new_tab'],
                        'privacy_policy_url' => $policy,
                    ),
                    'notification' => array(
                        'recipients' => $recip['addresses'],
                        'source'     => $recip['source'],
                        'subject'    => '' !== $wpt['admin_subject'] ? $wpt['admin_subject'] : 'New Enquiry',
                        'title'      => '' !== $wpt['admin_title'] ? $wpt['admin_title'] : 'New Enquiry',
                        'from_email' => $wpt['from_email'],
                    ),
                    'fixed_copy'   => array(
                        'submit_label'    => 'Submit Enquiry',
                        'success_message' => 'Submit Enquiry sent successfully !!',
                    ),
                    'anti_spam'    => WTA_Inquiry_Form::anti_spam(),
                    'stored'       => $stored,
                    'notes'        => $notes,
                );
            },
        ));
    }

    /* --------------------------------------------------------- update form */

    protected static function register_update_form() {
        wp_register_ability(WTA_Travel_Abilities::NS . 'update-inquiry-form', array(
            'label'       => 'Update the trip enquiry form',
            'description' => 'Change how WP Travel\'s trip enquiry form looks and where it goes. Only the properties supplied are touched. fields relabels WP Travel\'s three fields (full_name, email, note): label, placeholder, required, maxlength. full_name and email cannot be made optional. extras replaces the whole list of additional fields (text, textarea or select, at most 5). recipients replaces the notification addresses (at most 5; an empty list goes back to the site admin email). consent_text, consent_new_tab, enabled and admin_subject are written to WP Travel\'s own settings, one key at a time. Input names of WP Travel\'s fields never change, so saving keeps working. Defaults to a dry run that reports the before and after of everything it would change and writes nothing; pass dry_run false to apply.',
            'category'    => WTA_Travel_Abilities::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'fields'          => array(
                        'type'                 => 'object',
                        'description'          => 'Overrides keyed by full_name, email or note. Each may carry label, placeholder, required, maxlength (not for note).',
                        'additionalProperties' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'label'       => array('type' => 'string', 'maxLength' => 80),
                                'placeholder' => array('type' => 'string', 'maxLength' => 120),
                                'required'    => array('type' => 'boolean'),
                                'maxlength'   => array('type' => 'integer', 'minimum' => 1, 'maximum' => 1000),
                            ),
                        ),
                    ),
                    'extras'          => array(
                        'type'        => 'array',
                        'description' => 'The complete list of additional fields, replacing the current list. Pass [] to remove them all. An invalid entry refuses the whole list.',
                        'maxItems'    => WTA_Inquiry_Form::MAX_EXTRAS,
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'key'         => array('type' => 'string', 'description' => 'Lowercase letters, digits and underscores, starting with a letter. The input is named wta_enquiry_<key>.', 'pattern' => '^[a-z][a-z0-9_]{1,23}$'),
                                'label'       => array('type' => 'string', 'maxLength' => 80),
                                'type'        => array('type' => 'string', 'enum' => WTA_Inquiry_Form::EXTRA_TYPES, 'default' => 'text'),
                                'required'    => array('type' => 'boolean', 'default' => false),
                                'placeholder' => array('type' => 'string', 'maxLength' => 120),
                                'options'     => array('type' => 'array', 'description' => 'For select: 2 to 12 distinct choices.', 'items' => array('type' => 'string')),
                            ),
                            'required'   => array('key', 'label'),
                        ),
                    ),
                    'recipients'      => array(
                        'type'        => 'array',
                        'description' => 'Email addresses the admin notification goes to, replacing the current list.',
                        'maxItems'    => WTA_Inquiry_Form::MAX_RECIPIENTS,
                        'items'       => array('type' => 'string', 'format' => 'email'),
                    ),
                    'consent_text'    => array('type' => 'string', 'description' => 'Plain text shown beside the privacy policy link. 1 to 300 characters.', 'maxLength' => 300),
                    'consent_new_tab' => array('type' => 'boolean', 'description' => 'Open the privacy policy in a new tab.'),
                    'enabled'         => array('type' => 'boolean', 'description' => 'WP Travel\'s own switch for the Enquiry popup button on trip pages.'),
                    'admin_subject'   => array('type' => 'string', 'description' => 'Subject of the admin notification email.', 'maxLength' => 120),
                    'dry_run'         => array('type' => 'boolean', 'description' => 'Defaults to true. Must be explicitly false to write anything.'),
                ),
            ),

            'output_schema' => array(
                'type'        => 'object',
                'description' => 'Before and after of every setting touched.',
                'properties'  => array(
                    'dry_run'  => array('type' => 'boolean', 'description' => 'True means nothing was written, whatever else the response says.'),
                    'trip_id'  => array('type' => 'integer', 'description' => 'Always 0: the form is not per trip.'),
                    'changed'  => array('type' => 'array', 'description' => 'Names of the settings that changed, or would have. Empty on a dry run means the input matches what is stored.', 'items' => array('type' => 'string')),
                    'rejected' => array('type' => 'array', 'description' => 'Inputs refused, each with the reason. Refusals do not abort the rest of the call, so always read this even on success.', 'items' => array('type' => 'string')),
                    'result'   => array('type' => 'object', 'description' => 'Each changed setting as {from, to}.'),
                    'note'     => array('type' => 'string'),
                ),
                'required'    => array('dry_run', 'changed', 'rejected', 'result', 'note'),
            ),

            'permission_callback' => array(__CLASS__, 'can_manage_form'),

            'execute_callback' => function ($input) {
                return self::update_form(is_array($input) ? $input : array());
            },
        ));
    }

    /**
     * @param array $input
     * @return array|WP_Error
     */
    public static function update_form($input) {
        $dry   = !isset($input['dry_run']) || (bool) $input['dry_run'];
        $clean = WTA_Inquiry_Form::validate($input);

        $stored   = WTA_Inquiry_Form::stored();
        $current  = WTA_Inquiry_Form::wp_travel_settings();
        $next     = $stored;
        $changed  = array();
        $result   = array();

        // Our own option: merge field overrides property by property, replace
        // the extras and recipients lists whole.
        if (isset($clean['overrides']['fields'])) {
            foreach ($clean['overrides']['fields'] as $key => $override) {
                $before = isset($stored['fields'][$key]) ? $stored['fields'][$key] : array();
                $after  = array_merge($before, $override);

                if ($after !== $before) {
                    $next['fields'][$key]       = $after;
                    $changed[]                  = 'fields.' . $key;
                    $result['fields.' . $key]   = array('from' => $before, 'to' => $after);
                }
            }
        }

        foreach (array('extras', 'recipients') as $list) {
            if (isset($clean['overrides'][$list]) && $clean['overrides'][$list] !== $stored[$list]) {
                $next[$list]    = $clean['overrides'][$list];
                $changed[]      = $list;
                $result[$list]  = array('from' => $stored[$list], 'to' => $clean['overrides'][$list]);
            }
        }

        // WP Travel's own settings: compare against what is stored, key by key.
        $map = array(
            'wp_travel_gdpr_message'     => 'consent_text',
            'open_gdpr_in_new_tab'       => 'consent_new_tab',
            'enable_trip_enquiry_option' => 'enabled',
            'admin_subject'              => 'admin_subject',
        );

        foreach ($clean['wp_travel'] as $key => $value) {
            if ((string) $current[$key] === (string) $value) {
                continue;
            }

            $label            = $map[$key];
            $changed[]        = $label;
            $result[$label]   = array('from' => $current[$key], 'to' => $value);
        }

        if ($dry) {
            return array(
                'dry_run'  => true,
                'trip_id'  => 0,
                'changed'  => $changed,
                'rejected' => $clean['rejected'],
                'result'   => $result,
                'note'     => 'Nothing was written. Call again with dry_run false to apply these changes.',
            );
        }

        if ($changed) {
            if (array_intersect(array('fields.full_name', 'fields.email', 'fields.note', 'extras', 'recipients'), $changed)) {
                update_option(WTA_Inquiry_Form::OPTION, $next, false);
            }

            self::write_wp_travel_settings($clean['wp_travel']);
        }

        return array(
            'dry_run'  => false,
            'trip_id'  => 0,
            'changed'  => $changed,
            'rejected' => $clean['rejected'],
            'result'   => $result,
            'note'     => $changed ? 'Applied. Call get-inquiry-form to see the form as it now renders. A page cache may need clearing before visitors see it.' : 'Nothing to change: the input matches what is stored.',
        );
    }

    /**
     * Write the accepted WP Travel settings into its option, key by key.
     *
     * Reads the whole option, replaces only the keys this layer owns and writes
     * it back, so the rest of the blob is carried through untouched. admin_subject
     * lives one level down, in the enquiry email template settings.
     *
     * @param array<string, string> $values
     */
    protected static function write_wp_travel_settings($values) {
        if (!$values) {
            return;
        }

        $all = get_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION, array());
        $all = is_array($all) ? $all : array();

        foreach ($values as $key => $value) {
            if ('admin_subject' === $key) {
                $template = isset($all['enquiry_admin_template_settings']) && is_array($all['enquiry_admin_template_settings'])
                    ? $all['enquiry_admin_template_settings']
                    : array();

                $template['admin_subject']               = $value;
                $all['enquiry_admin_template_settings'] = $template;

                continue;
            }

            if (in_array($key, WTA_Inquiry_Form::wp_travel_keys(), true)) {
                $all[$key] = $value;
            }
        }

        update_option(WTA_Inquiry_Form::WP_TRAVEL_OPTION, $all);
    }

    /* ------------------------------------------------------ list inquiries */

    protected static function register_list_inquiries() {
        wp_register_ability(WTA_Travel_Abilities::NS . 'list-inquiries', array(
            'label'       => 'List recent trip enquiries',
            'description' => 'List enquiries submitted through WP Travel\'s trip enquiry form, newest first. By default each row carries only the enquiry ID, the trip it is about, its date and whether it has been read: no name, email or message. Pass include_contact for the visitor\'s name and email address, and include_message for the message (cut to ' . self::MESSAGE_LENGTH . ' characters), so personal data is only fetched when it is needed. Read only.',
            'category'    => WTA_Travel_Abilities::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_id'         => array('type' => 'integer', 'description' => 'Only enquiries about this trip.', 'minimum' => 1),
                    'since'           => array('type' => 'string', 'description' => 'Only enquiries on or after this date (YYYY-MM-DD, site time).', 'pattern' => '^\d{4}-\d{2}-\d{2}$'),
                    'status'          => array('type' => 'string', 'description' => 'WP Travel\'s read state.', 'enum' => array('any', 'unread', 'read'), 'default' => 'any'),
                    'include_contact' => array('type' => 'boolean', 'description' => 'Include the visitor\'s name and email address.', 'default' => false),
                    'include_message' => array('type' => 'boolean', 'description' => 'Include the message, cut to ' . self::MESSAGE_LENGTH . ' characters.', 'default' => false),
                    'per_page'        => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20),
                    'page'            => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
                ),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'inquiries' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'id'         => array('type' => 'integer'),
                                'trip_id'    => array('type' => 'integer', 'description' => '0 when the trip no longer exists or was never recorded.'),
                                'trip_title' => array('type' => 'string'),
                                'date_gmt'   => array('type' => 'string'),
                                'status'     => array('type' => 'string'),
                                'name'       => array('type' => 'string', 'description' => 'Only with include_contact.'),
                                'email'      => array('type' => 'string', 'description' => 'Only with include_contact.'),
                                'message'    => array('type' => 'string', 'description' => 'Only with include_message.'),
                            ),
                        ),
                    ),
                    'total'     => array('type' => 'integer'),
                    'pages'     => array('type' => 'integer'),
                    'note'      => array('type' => 'string'),
                ),
                'required'   => array('inquiries', 'total', 'pages', 'note'),
            ),

            'permission_callback' => array(__CLASS__, 'can_read_inquiries'),

            'execute_callback' => function ($input) {
                $input = is_array($input) ? $input : array();

                if (!post_type_exists(self::POST_TYPE)) {
                    return self::missing_post_type();
                }

                $per_page = isset($input['per_page']) ? min(50, max(1, absint($input['per_page']))) : 20;
                $page     = isset($input['page']) ? max(1, absint($input['page'])) : 1;

                $args = array(
                    'post_type'      => self::POST_TYPE,
                    'post_status'    => 'any',
                    'posts_per_page' => $per_page,
                    'paged'          => $page,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                );

                $filters = self::query_filters($input);

                if (is_wp_error($filters)) {
                    return $filters;
                }

                $query = new WP_Query(array_merge($args, $filters));

                $with_contact = !empty($input['include_contact']);
                $with_message = !empty($input['include_message']);
                $rows         = array();

                foreach ($query->posts as $post) {
                    $rows[] = self::inquiry_row($post, $with_contact, $with_message);
                }

                return array(
                    'inquiries' => $rows,
                    'total'     => (int) $query->found_posts,
                    'pages'     => (int) $query->max_num_pages,
                    'note'      => ($with_contact || $with_message)
                        ? 'This response contains visitors\' personal data. Do not copy it anywhere it is not needed.'
                        : 'Contact details and messages were not requested. Pass include_contact or include_message when you need them.',
                );
            },
        ));
    }

    /**
     * The trip, date and read-state filters shared by list-inquiries.
     *
     * @return array|WP_Error WP_Query arguments to merge.
     */
    protected static function query_filters($input) {
        $args = array();

        if (!empty($input['trip_id'])) {
            $args['meta_query'][] = array(
                'key'   => 'wp_travel_post_id',
                'value' => absint($input['trip_id']),
            );
        }

        if (isset($input['status']) && 'any' !== $input['status']) {
            if (!in_array($input['status'], array('read', 'unread'), true)) {
                return new WP_Error('wta_bad_status', 'status must be any, unread or read.', array('status' => 400));
            }

            // WP Travel writes "unread" on submission and nothing on a never
            // opened record that predates the key, so "read" is "not unread".
            $args['meta_query'][] = 'unread' === $input['status']
                ? array('key' => 'wp_travel_enquiry_status', 'value' => 'unread')
                : array('key' => 'wp_travel_enquiry_status', 'value' => 'unread', 'compare' => '!=');
        }

        if (!empty($input['since'])) {
            $since = self::parse_date($input['since']);

            if (!$since) {
                return new WP_Error('wta_bad_date', 'since must be a date as YYYY-MM-DD.', array('status' => 400));
            }

            $args['date_query'][] = array('after' => $since, 'inclusive' => true);
        }

        return $args;
    }

    /**
     * @return string|false The date in Y-m-d, or false when it is not a real date.
     */
    protected static function parse_date($value) {
        $value = (string) $value;

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return $value;
    }

    /**
     * One enquiry as a row, with personal data only when asked for.
     *
     * WP Travel stores the fields both as separate meta keys and as one array
     * under wp_travel_trip_enquiry_data. Separate keys first, the array as a
     * fallback for records saved by an older version.
     *
     * @return array<string, mixed>
     */
    protected static function inquiry_row($post, $with_contact, $with_message) {
        $data    = maybe_unserialize(get_post_meta($post->ID, 'wp_travel_trip_enquiry_data', true));
        $data    = is_array($data) ? $data : array();
        $trip_id = absint(get_post_meta($post->ID, 'wp_travel_post_id', true));

        if (!$trip_id && isset($data['post_id'])) {
            $trip_id = absint($data['post_id']);
        }

        $trip   = $trip_id ? get_post($trip_id) : null;
        $status = (string) get_post_meta($post->ID, 'wp_travel_enquiry_status', true);

        $row = array(
            'id'         => (int) $post->ID,
            'trip_id'    => $trip instanceof WP_Post ? $trip_id : 0,
            'trip_title' => $trip instanceof WP_Post ? get_the_title($trip) : '',
            'date_gmt'   => $post->post_date_gmt,
            'status'     => 'unread' === $status ? 'unread' : 'read',
        );

        $pick = function ($key) use ($post, $data) {
            $value = get_post_meta($post->ID, $key, true);

            if ('' === $value && isset($data[$key])) {
                $value = $data[$key];
            }

            return is_scalar($value) ? (string) $value : '';
        };

        if ($with_contact) {
            $row['name']  = sanitize_text_field($pick('wp_travel_enquiry_name'));
            $row['email'] = sanitize_email($pick('wp_travel_enquiry_email'));
        }

        if ($with_message) {
            $row['message'] = mb_substr(sanitize_textarea_field($pick('wp_travel_enquiry_query')), 0, self::MESSAGE_LENGTH);
        }

        return $row;
    }

    /* ---------------------------------------------------------------- stats */

    protected static function register_stats() {
        wp_register_ability(WTA_Travel_Abilities::NS . 'get-inquiry-stats', array(
            'label'       => 'Count trip enquiries by trip, destination and month',
            'description' => 'Aggregate enquiries over a date window (the last 12 months by default) into counts per trip, per destination and per calendar month, plus the summary the "enquiries per trip page" growth metric is built from: total enquiries, trips that received at least one, published trips, and enquiries per published trip. Contains no names, emails or messages. A trip in several destinations counts once in each, so destination counts can add up to more than the total. Reads at most ' . self::SCAN_LIMIT . ' enquiries and says when it stopped there. Read only.',
            'category'    => WTA_Travel_Abilities::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'since'        => array('type' => 'string', 'description' => 'First day of the window (YYYY-MM-DD). Defaults to 12 months ago.', 'pattern' => '^\d{4}-\d{2}-\d{2}$'),
                    'until'        => array('type' => 'string', 'description' => 'Last day of the window (YYYY-MM-DD). Defaults to today.', 'pattern' => '^\d{4}-\d{2}-\d{2}$'),
                    'include_zero' => array('type' => 'boolean', 'description' => 'Also list published trips with no enquiries, after those with some, up to the trip limit.', 'default' => false),
                    'limit'        => array('type' => 'integer', 'description' => 'Most trips to list.', 'minimum' => 1, 'maximum' => self::TRIP_LIMIT, 'default' => 25),
                ),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'window'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'since' => array('type' => 'string'),
                            'until' => array('type' => 'string'),
                        ),
                    ),
                    'summary'      => array(
                        'type'       => 'object',
                        'properties' => array(
                            'enquiries'                  => array('type' => 'integer'),
                            'unread'                     => array('type' => 'integer'),
                            'trips_with_enquiries'       => array('type' => 'integer'),
                            'published_trips'            => array('type' => 'integer'),
                            'enquiries_per_published_trip' => array('type' => 'number', 'description' => 'Total enquiries divided by published trips. 0 when there are no published trips.'),
                        ),
                    ),
                    'by_trip'      => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
                        'trip_id'       => array('type' => 'integer'),
                        'title'         => array('type' => 'string'),
                        'enquiries'     => array('type' => 'integer'),
                        'unread'        => array('type' => 'integer'),
                        'last_date_gmt' => array('type' => 'string'),
                    ))),
                    'by_destination' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
                        'term_id'   => array('type' => 'integer'),
                        'name'      => array('type' => 'string'),
                        'enquiries' => array('type' => 'integer'),
                    ))),
                    'by_month'     => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
                        'month'     => array('type' => 'string', 'description' => 'YYYY-MM, in GMT.'),
                        'enquiries' => array('type' => 'integer'),
                    ))),
                    'truncated'    => array('type' => 'boolean', 'description' => 'True when the window held more enquiries than were read. Narrow the window.'),
                    'note'         => array('type' => 'string'),
                ),
                'required'   => array('window', 'summary', 'by_trip', 'by_destination', 'by_month', 'truncated', 'note'),
            ),

            'permission_callback' => array(__CLASS__, 'can_read_inquiries'),

            'execute_callback' => function ($input) {
                return self::stats(is_array($input) ? $input : array());
            },
        ));
    }

    /**
     * @return array|WP_Error
     */
    public static function stats($input) {
        if (!post_type_exists(self::POST_TYPE)) {
            return self::missing_post_type();
        }

        $now   = time();
        $until = isset($input['until']) ? self::parse_date($input['until']) : gmdate('Y-m-d', $now);
        $since = isset($input['since']) ? self::parse_date($input['since']) : gmdate('Y-m-d', strtotime('-12 months', $now));

        if (!$since || !$until) {
            return new WP_Error('wta_bad_date', 'since and until must be dates as YYYY-MM-DD.', array('status' => 400));
        }

        if ($since > $until) {
            return new WP_Error('wta_bad_window', 'since is after until.', array('status' => 400));
        }

        $limit = isset($input['limit']) ? min(self::TRIP_LIMIT, max(1, absint($input['limit']))) : 25;

        $query = new WP_Query(array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => self::SCAN_LIMIT,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => array(array('after' => $since, 'before' => $until, 'inclusive' => true)),
        ));

        $by_trip  = array();
        $by_month = array();
        $unread   = 0;

        foreach ($query->posts as $post) {
            $trip_id = absint(get_post_meta($post->ID, 'wp_travel_post_id', true));
            $is_new  = 'unread' === get_post_meta($post->ID, 'wp_travel_enquiry_status', true);
            $month   = substr($post->post_date_gmt, 0, 7);

            $by_month[$month] = isset($by_month[$month]) ? $by_month[$month] + 1 : 1;
            $unread          += $is_new ? 1 : 0;

            if (!isset($by_trip[$trip_id])) {
                $by_trip[$trip_id] = array('enquiries' => 0, 'unread' => 0, 'last' => '');
            }

            $by_trip[$trip_id]['enquiries']++;
            $by_trip[$trip_id]['unread'] += $is_new ? 1 : 0;

            if ($post->post_date_gmt > $by_trip[$trip_id]['last']) {
                $by_trip[$trip_id]['last'] = $post->post_date_gmt;
            }
        }

        $total     = count($query->posts);
        $truncated = (int) $query->found_posts > $total;
        $trips     = array();

        // Enquiries on a trip that has since been deleted (id 0 or missing) are
        // counted in the summary and the months but have no trip row to list.
        foreach ($by_trip as $trip_id => $counts) {
            $trip = $trip_id ? get_post($trip_id) : null;

            if (!$trip instanceof WP_Post) {
                continue;
            }

            $trips[$trip_id] = array(
                'trip_id'       => (int) $trip_id,
                'title'         => get_the_title($trip),
                'enquiries'     => $counts['enquiries'],
                'unread'        => $counts['unread'],
                'last_date_gmt' => $counts['last'],
            );
        }

        uasort($trips, function ($a, $b) {
            return $b['enquiries'] <=> $a['enquiries'] ?: strcmp($b['last_date_gmt'], $a['last_date_gmt']);
        });

        $published = class_exists('WTA_Trip') && WTA_Trip::is_available()
            ? (int) wp_count_posts(WTA_Trip::post_type())->publish
            : 0;

        $destinations = self::destination_counts(array_keys($trips), wp_list_pluck($trips, 'enquiries'));
        $listed       = array_slice(array_values($trips), 0, $limit);

        if (!empty($input['include_zero']) && count($listed) < $limit && $published) {
            $zero = get_posts(array(
                'post_type'      => WTA_Trip::post_type(),
                'post_status'    => 'publish',
                'post__not_in'   => array_merge(array(0), array_keys($trips)),
                'posts_per_page' => $limit - count($listed),
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ));

            foreach ($zero as $trip) {
                $listed[] = array(
                    'trip_id'       => (int) $trip->ID,
                    'title'         => get_the_title($trip),
                    'enquiries'     => 0,
                    'unread'        => 0,
                    'last_date_gmt' => '',
                );
            }
        }

        return array(
            'window'         => array('since' => $since, 'until' => $until),
            'summary'        => array(
                'enquiries'                    => $total,
                'unread'                       => $unread,
                'trips_with_enquiries'         => count($trips),
                'published_trips'              => $published,
                'enquiries_per_published_trip' => $published ? round($total / $published, 2) : 0,
            ),
            'by_trip'        => $listed,
            'by_destination' => $destinations,
            'by_month'       => self::fill_months($by_month, $since, $until),
            'truncated'      => $truncated,
            'note'           => $truncated
                ? sprintf('The window holds %d enquiries and only the newest %d were read. Narrow since and until for exact counts.', (int) $query->found_posts, $total)
                : 'Counts are complete for the window. Months are GMT. enquiries_per_published_trip divides by the trips published now, not by the trips live at the time.',
        );
    }

    /**
     * Enquiries per destination, from the destinations of the trips enquired about.
     *
     * @param int[]               $trip_ids
     * @param array<int, int>     $counts   Enquiries per trip, keyed by trip ID.
     * @return array<int, array{term_id: int, name: string, enquiries: int}>
     */
    protected static function destination_counts($trip_ids, $counts) {
        if (!$trip_ids || !taxonomy_exists('travel_locations')) {
            return array();
        }

        $terms = wp_get_object_terms($trip_ids, 'travel_locations', array('fields' => 'all_with_object_id'));

        if (is_wp_error($terms)) {
            return array();
        }

        $out = array();

        foreach ($terms as $term) {
            $id = (int) $term->term_id;

            if (!isset($out[$id])) {
                $out[$id] = array('term_id' => $id, 'name' => $term->name, 'enquiries' => 0);
            }

            $out[$id]['enquiries'] += isset($counts[$term->object_id]) ? (int) $counts[$term->object_id] : 0;
        }

        usort($out, function ($a, $b) {
            return $b['enquiries'] <=> $a['enquiries'] ?: strcmp($a['name'], $b['name']);
        });

        return array_values($out);
    }

    /**
     * Every month in the window, so a chart has no gaps to interpolate.
     *
     * @param array<string, int> $counts YYYY-MM => count.
     * @return array<int, array{month: string, enquiries: int}>
     */
    protected static function fill_months($counts, $since, $until) {
        $out    = array();
        $cursor = strtotime(substr($since, 0, 7) . '-01 00:00:00 UTC');
        $end    = strtotime(substr($until, 0, 7) . '-01 00:00:00 UTC');

        // A window of centuries would build a very long list; the scan limit
        // already bounds the data, this bounds the padding.
        for ($guard = 0; $cursor <= $end && $guard < 240; $guard++) {
            $month = gmdate('Y-m', $cursor);
            $out[] = array('month' => $month, 'enquiries' => isset($counts[$month]) ? (int) $counts[$month] : 0);
            $cursor = strtotime('+1 month', $cursor);
        }

        return $out;
    }

    /**
     * @return WP_Error
     */
    protected static function missing_post_type() {
        return new WP_Error(
            'wta_post_type_missing',
            sprintf('The post type "%s" is not registered on this site, so there are no enquiries to read. Is WP Travel active?', self::POST_TYPE),
            array('status' => 409, 'wp_travel_active' => WTA_Travel_Abilities::core_available())
        );
    }
}
