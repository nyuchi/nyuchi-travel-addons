<?php
/**
 * The WP Travel enquiry form, made customisable.
 *
 * WP Travel already has an enquiry form: a popup on the trip page that saves an
 * `itinerary-enquiries` post and emails the site admin. This plugin does not
 * replace it and does not store a second copy of any enquiry. What it adds is a
 * small, validated layer of overrides that WP Travel's own filters then apply,
 * so a trip template can ask for a phone number or a travel month, relabel the
 * message box, or send enquiries to the sales inbox without editing WP Travel.
 *
 * Where each setting lives is deliberate, and follows the rule the rest of this
 * plugin follows: WP Travel's own storage is wrapped, never shadowed.
 *
 *   - Consent copy, "open the privacy page in a new tab", the master enquiry
 *     switch and the admin email subject already have a home in WP Travel's
 *     `wp_travel_settings` option. They are read from and written to that option,
 *     key by key, and nothing else in it is ever touched or returned (the blob
 *     carries payment and API keys).
 *   - Field labels, placeholders, required flags, extra fields and recipient
 *     addresses have no home in WP Travel core, so they live in this plugin's
 *     own `wta_inquiry_form` option and are applied by the filters below.
 *
 * Hooks used, all WP Travel's, all verified against WP Travel 12.0.4:
 *
 *   wp_travel_enquiries_form_fields   the field list the form renders and saves
 *   wp_travel_enquiries_admin_emails  who the admin notification goes to
 *   wp_travel_admin_enquery_email_tags the {tags} the admin email template fills
 *                                      (the misspelling is WP Travel's)
 *
 * WP Travel hard-codes the submit button label and the "sent successfully"
 * message, and the consent checkbox is appended after the field filter runs, so
 * neither can be overridden here. get-inquiry-form reports both as read-only.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Inquiry_Form {

    /** This plugin's own overrides. Autoload off: read on enquiry requests only. */
    const OPTION = 'wta_inquiry_form';

    /** WP Travel's settings option. Only the keys in WP_TRAVEL_KEYS are ever touched. */
    const WP_TRAVEL_OPTION = 'wp_travel_settings';

    /** The three fields WP Travel ships. Their names are what its save routine keys on. */
    const CORE_FIELDS = array('full_name', 'email', 'note');

    /** Fields that must stay required: without them there is nobody to reply to. */
    const ALWAYS_REQUIRED = array('full_name', 'email');

    /** Prefix on the input name of every extra field, so it cannot collide with WP Travel's. */
    const EXTRA_PREFIX = 'wta_enquiry_';

    const MAX_EXTRAS     = 5;
    const MAX_RECIPIENTS = 5;
    const MAX_OPTIONS    = 12;

    /** Input types an extra field may use. Each is one WP Travel's form framework renders. */
    const EXTRA_TYPES = array('text', 'textarea', 'select');

    public function __construct() {
        add_filter('wp_travel_enquiries_form_fields', array($this, 'filter_fields'), 99);
        add_filter('wp_travel_enquiries_admin_emails', array($this, 'filter_recipients'), 20);
        add_filter('wp_travel_admin_enquery_email_tags', array($this, 'filter_email_tags'), 20, 2);
    }

    /* ------------------------------------------------------------- storage */

    /**
     * The stored overrides, with every key present.
     *
     * @return array{fields: array, extras: array, recipients: array}
     */
    public static function stored() {
        $raw = get_option(self::OPTION, array());
        $raw = is_array($raw) ? $raw : array();

        return array(
            'fields'     => isset($raw['fields']) && is_array($raw['fields']) ? $raw['fields'] : array(),
            'extras'     => isset($raw['extras']) && is_array($raw['extras']) ? array_values($raw['extras']) : array(),
            'recipients' => isset($raw['recipients']) && is_array($raw['recipients']) ? array_values($raw['recipients']) : array(),
        );
    }

    /**
     * The WP Travel settings this plugin may read and write, and nothing else.
     *
     * @return string[]
     */
    public static function wp_travel_keys() {
        return array('enable_trip_enquiry_option', 'wp_travel_gdpr_message', 'open_gdpr_in_new_tab');
    }

    /**
     * The WP Travel settings this layer manages, read straight from the option.
     *
     * Read from the option rather than wptravel_get_settings() so that what is
     * reported is what is stored, not what WP Travel's defaults fill in.
     *
     * @return array<string, mixed>
     */
    public static function wp_travel_settings() {
        $all = get_option(self::WP_TRAVEL_OPTION, array());
        $all = is_array($all) ? $all : array();
        $out = array();

        foreach (self::wp_travel_keys() as $key) {
            $out[$key] = isset($all[$key]) ? $all[$key] : '';
        }

        $subject = isset($all['enquiry_admin_template_settings']) && is_array($all['enquiry_admin_template_settings'])
            ? $all['enquiry_admin_template_settings']
            : array();

        $out['admin_subject'] = isset($subject['admin_subject']) ? (string) $subject['admin_subject'] : '';
        $out['admin_title']   = isset($subject['admin_title']) ? (string) $subject['admin_title'] : '';
        $out['from_email']    = isset($all['wp_travel_from_email']) ? (string) $all['wp_travel_from_email'] : '';

        return $out;
    }

    /* ----------------------------------------------------------- validation */

    /**
     * Check and clean a proposed change.
     *
     * Pure: reads nothing but its argument and returns what would be stored, so
     * the dry run and the real write cannot disagree about what was accepted.
     * Only the parts supplied are returned; the caller merges them over what is
     * stored, so a partial update cannot blank what it does not mention.
     *
     * @param array $input Ability input.
     * @return array{overrides: array, wp_travel: array, rejected: string[]}
     */
    public static function validate($input) {
        $out = array(
            'overrides' => array(),
            'wp_travel' => array(),
            'rejected'  => array(),
        );

        if (isset($input['fields'])) {
            if (!is_array($input['fields'])) {
                $out['rejected'][] = 'fields: must be an object keyed by full_name, email or note.';
            } else {
                foreach ($input['fields'] as $key => $spec) {
                    $key = sanitize_key($key);

                    if (!in_array($key, self::CORE_FIELDS, true)) {
                        $out['rejected'][] = sprintf('fields.%s: not one of WP Travel\'s fields (%s). Add new fields with extras.', $key, implode(', ', self::CORE_FIELDS));
                        continue;
                    }

                    $clean = self::validate_field_override($key, is_array($spec) ? $spec : array(), $out['rejected']);

                    if ($clean) {
                        $out['overrides']['fields'][$key] = $clean;
                    }
                }
            }
        }

        if (isset($input['extras'])) {
            $extras = self::validate_extras($input['extras'], $out['rejected']);

            if (null !== $extras) {
                $out['overrides']['extras'] = $extras;
            }
        }

        if (isset($input['recipients'])) {
            $recipients = self::validate_recipients($input['recipients'], $out['rejected']);

            if (null !== $recipients) {
                $out['overrides']['recipients'] = $recipients;
            }
        }

        if (isset($input['consent_text'])) {
            $text = trim(wp_strip_all_tags((string) $input['consent_text']));

            if ('' === $text || strlen($text) > 300) {
                $out['rejected'][] = 'consent_text: must be 1 to 300 characters of plain text. It is shown beside the privacy policy link.';
            } else {
                $out['wp_travel']['wp_travel_gdpr_message'] = $text;
            }
        }

        if (isset($input['consent_new_tab'])) {
            $out['wp_travel']['open_gdpr_in_new_tab'] = $input['consent_new_tab'] ? 'yes' : 'no';
        }

        if (isset($input['enabled'])) {
            $out['wp_travel']['enable_trip_enquiry_option'] = $input['enabled'] ? 'yes' : 'no';
        }

        if (isset($input['admin_subject'])) {
            $subject = trim(sanitize_text_field((string) $input['admin_subject']));

            if ('' === $subject || strlen($subject) > 120) {
                $out['rejected'][] = 'admin_subject: must be 1 to 120 characters.';
            } else {
                $out['wp_travel']['admin_subject'] = $subject;
            }
        }

        return $out;
    }

    /**
     * @param string[] $rejected Appended to.
     * @return array
     */
    protected static function validate_field_override($key, $spec, &$rejected) {
        $clean = array();

        foreach (array('label' => 80, 'placeholder' => 120) as $prop => $max) {
            if (!array_key_exists($prop, $spec)) {
                continue;
            }

            $value = trim(sanitize_text_field((string) $spec[$prop]));

            if ('label' === $prop && '' === $value) {
                $rejected[] = sprintf('fields.%s.label: cannot be empty.', $key);
            } elseif (strlen($value) > $max) {
                $rejected[] = sprintf('fields.%s.%s: longer than %d characters.', $key, $prop, $max);
            } else {
                $clean[$prop] = $value;
            }
        }

        if (array_key_exists('required', $spec)) {
            if (!$spec['required'] && in_array($key, self::ALWAYS_REQUIRED, true)) {
                $rejected[] = sprintf('fields.%s.required: must stay true. Enquiries need a name and an email address to be answered.', $key);
            } else {
                $clean['required'] = (bool) $spec['required'];
            }
        }

        if (array_key_exists('maxlength', $spec)) {
            $length = is_numeric($spec['maxlength']) ? (int) $spec['maxlength'] : 0;

            if ('note' === $key || $length < 1 || $length > 1000) {
                $rejected[] = sprintf('fields.%s.maxlength: must be 1 to 1000, and does not apply to the message box.', $key);
            } else {
                $clean['maxlength'] = $length;
            }
        }

        return $clean;
    }

    /**
     * @param mixed    $extras
     * @param string[] $rejected Appended to.
     * @return array|null The complete replacement list, or null when the input was unusable.
     */
    protected static function validate_extras($extras, &$rejected) {
        if (!is_array($extras)) {
            $rejected[] = 'extras: must be a list. Pass an empty list to remove every extra field.';

            return null;
        }

        if (count($extras) > self::MAX_EXTRAS) {
            $rejected[] = sprintf('extras: at most %d extra fields. Nothing was changed.', self::MAX_EXTRAS);

            return null;
        }

        $clean = array();
        $seen  = array();

        foreach (array_values($extras) as $index => $extra) {
            $extra = is_array($extra) ? $extra : array();
            $key   = isset($extra['key']) ? strtolower((string) $extra['key']) : '';
            $label = isset($extra['label']) ? trim(sanitize_text_field((string) $extra['label'])) : '';
            $type  = isset($extra['type']) ? (string) $extra['type'] : 'text';

            if (!preg_match('/^[a-z][a-z0-9_]{1,23}$/', $key)) {
                $rejected[] = sprintf('extras[%d].key: lowercase letters, digits and underscores, starting with a letter, 2 to 24 characters.', $index);
                continue;
            }

            if (isset($seen[$key])) {
                $rejected[] = sprintf('extras[%d].key: "%s" is used twice.', $index, $key);
                continue;
            }

            if ('' === $label || strlen($label) > 80) {
                $rejected[] = sprintf('extras[%d].label: required, 80 characters at most.', $index);
                continue;
            }

            if (!in_array($type, self::EXTRA_TYPES, true)) {
                $rejected[] = sprintf('extras[%d].type: "%s" is not supported. Use one of %s.', $index, $type, implode(', ', self::EXTRA_TYPES));
                continue;
            }

            $entry = array(
                'key'         => $key,
                'label'       => $label,
                'type'        => $type,
                'required'    => !empty($extra['required']),
                'placeholder' => isset($extra['placeholder']) ? mb_substr(trim(sanitize_text_field((string) $extra['placeholder'])), 0, 120) : '',
                'options'     => array(),
            );

            if ('select' === $type) {
                $options = isset($extra['options']) && is_array($extra['options']) ? $extra['options'] : array();
                $options = array_values(array_unique(array_filter(array_map(function ($option) {
                    return mb_substr(trim(sanitize_text_field((string) $option)), 0, 80);
                }, $options), 'strlen')));

                if (count($options) < 2 || count($options) > self::MAX_OPTIONS) {
                    $rejected[] = sprintf('extras[%d].options: a select needs 2 to %d distinct options.', $index, self::MAX_OPTIONS);
                    continue;
                }

                $entry['options'] = $options;
            }

            $seen[$key] = true;
            $clean[]    = $entry;
        }

        // A list that lost entries is not applied: silently dropping a field the
        // caller meant to keep is worse than refusing the whole replacement.
        if (count($clean) !== count($extras)) {
            $rejected[] = 'extras: the list was not applied because an entry above was refused. Nothing was changed.';

            return null;
        }

        return $clean;
    }

    /**
     * @param mixed    $recipients
     * @param string[] $rejected Appended to.
     * @return string[]|null
     */
    protected static function validate_recipients($recipients, &$rejected) {
        if (!is_array($recipients)) {
            $rejected[] = 'recipients: must be a list of email addresses. Pass an empty list to go back to the site admin email.';

            return null;
        }

        if (count($recipients) > self::MAX_RECIPIENTS) {
            $rejected[] = sprintf('recipients: at most %d addresses.', self::MAX_RECIPIENTS);

            return null;
        }

        $clean = array();

        foreach ($recipients as $address) {
            $address = sanitize_email((string) $address);

            if ('' === $address || !is_email($address)) {
                $rejected[] = 'recipients: "' . sanitize_text_field((string) $address) . '" is not a valid email address. Nothing was changed.';

                return null;
            }

            $clean[] = strtolower($address);
        }

        return array_values(array_unique($clean));
    }

    /* --------------------------------------------------------------- filters */

    /**
     * Apply the stored overrides to WP Travel's field list.
     *
     * Runs last so it has the final say over WP Travel Pro's field editor. Input
     * names are never changed, because WP Travel's save routine keys on them.
     *
     * @param mixed $fields
     * @return mixed
     */
    public function filter_fields($fields) {
        if (!is_array($fields)) {
            return $fields;
        }

        $stored = self::stored();

        foreach ($stored['fields'] as $key => $override) {
            if (!isset($fields[$key]) || !is_array($fields[$key]) || !is_array($override)) {
                continue;
            }

            $field = $fields[$key];

            if (isset($override['label'])) {
                $field['label'] = $override['label'];
            }

            if (isset($override['placeholder'])) {
                $field['placeholder']               = $override['placeholder'];
                $field['attributes']                = isset($field['attributes']) && is_array($field['attributes']) ? $field['attributes'] : array();
                $field['attributes']['placeholder'] = $override['placeholder'];
            }

            $field['validations'] = isset($field['validations']) && is_array($field['validations']) ? $field['validations'] : array();

            if (isset($override['required'])) {
                if ($override['required']) {
                    $field['validations']['required'] = '1';
                } else {
                    unset($field['validations']['required']);
                }
            }

            if (isset($override['maxlength'])) {
                $field['validations']['maxlength'] = (string) $override['maxlength'];
            }

            $fields[$key] = $field;
        }

        foreach ($stored['extras'] as $index => $extra) {
            if (!is_array($extra) || empty($extra['key']) || empty($extra['type'])) {
                continue;
            }

            $field = array(
                'type'     => $extra['type'],
                'label'    => isset($extra['label']) ? $extra['label'] : $extra['key'],
                'name'     => self::EXTRA_PREFIX . $extra['key'],
                'id'       => 'wta-enquiry-' . str_replace('_', '-', $extra['key']),
                // Between the email (60) and the message (90) in WP Travel's order.
                'priority' => 70 + $index,
                'wta_extra' => true,
            );

            if (!empty($extra['required'])) {
                $field['validations'] = array('required' => '1');
            }

            if (!empty($extra['placeholder'])) {
                $field['attributes'] = array('placeholder' => $extra['placeholder']);
            }

            if ('textarea' === $extra['type']) {
                $field['wrapper_class']       = 'full-width textarea-field';
                $field['attributes']['rows']  = 3;
            }

            if ('select' === $extra['type']) {
                $options = array('' => esc_html__('Select', 'nyuchi-travel-addons'));

                foreach ((array) $extra['options'] as $option) {
                    $options[$option] = $option;
                }

                $field['options'] = $options;
            }

            $fields[self::EXTRA_PREFIX . $extra['key']] = $field;
        }

        return $fields;
    }

    /**
     * Send the admin notification to the configured addresses instead of the
     * site admin email. Unchanged when none are configured.
     *
     * @param mixed $to
     * @return mixed
     */
    public function filter_recipients($to) {
        $stored = self::stored();

        return $stored['recipients'] ? $stored['recipients'] : $to;
    }

    /**
     * WP Travel's admin email template has no place for a field it does not
     * know, so the extra answers ride along under the message.
     *
     * @param mixed $tags
     * @param mixed $formdata The sanitised POST data.
     * @return mixed
     */
    public function filter_email_tags($tags, $formdata = array()) {
        $stored = self::stored();

        if (!is_array($tags) || !$stored['extras'] || !is_array($formdata) || !isset($tags['{customer_note}'])) {
            return $tags;
        }

        $lines = array();

        foreach ($stored['extras'] as $extra) {
            $name = isset($extra['key']) ? self::EXTRA_PREFIX . $extra['key'] : '';

            if ('' === $name || !isset($formdata[$name]) || '' === trim((string) $formdata[$name])) {
                continue;
            }

            $lines[] = '<b>' . esc_html($extra['label']) . ':</b> ' . esc_html((string) $formdata[$name]);
        }

        if ($lines) {
            $tags['{customer_note}'] = $tags['{customer_note}'] . '<br><br>' . implode('<br>', $lines);
        }

        return $tags;
    }

    /* -------------------------------------------------------------- reading */

    /**
     * Whether WP Travel's own form function is here to call.
     */
    public static function form_available() {
        return function_exists('wptravel_get_enquiries_form') && function_exists('wptravel_enquiries_form_fields');
    }

    /**
     * The fields the form really renders, after every filter, as plain data.
     *
     * Hidden plumbing fields (action, the trip id, button labels) are left out:
     * they are not something a person fills in and not something to customise.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function effective_fields() {
        if (!self::form_available()) {
            return array();
        }

        $fields = wptravel_enquiries_form_fields();
        $fields = is_array($fields) ? $fields : array();
        $rows   = array();

        foreach ($fields as $key => $field) {
            if (!is_array($field) || (isset($field['type']) && 'hidden' === $field['type'])) {
                continue;
            }

            $validations = isset($field['validations']) && is_array($field['validations']) ? $field['validations'] : array();
            $attributes  = isset($field['attributes']) && is_array($field['attributes']) ? $field['attributes'] : array();
            $placeholder = isset($attributes['placeholder']) ? $attributes['placeholder'] : (isset($field['placeholder']) ? $field['placeholder'] : '');

            $row = array(
                'key'         => (string) $key,
                'name'        => isset($field['name']) ? (string) $field['name'] : '',
                'type'        => isset($field['type']) ? (string) $field['type'] : 'text',
                'label'       => isset($field['label']) ? wp_strip_all_tags((string) $field['label']) : '',
                'placeholder' => wp_strip_all_tags((string) $placeholder),
                'required'    => !empty($validations['required']),
                'maxlength'   => isset($validations['maxlength']) ? (int) $validations['maxlength'] : 0,
                'priority'    => isset($field['priority']) ? (int) $field['priority'] : 0,
                'source'      => !empty($field['wta_extra']) ? 'nyuchi-extra' : (in_array((string) $key, self::CORE_FIELDS, true) ? 'wp-travel' : 'wp-travel-pro-field-editor'),
                'options'     => array(),
            );

            if (!empty($field['options']) && is_array($field['options'])) {
                $row['options'] = array_values(array_filter(array_map('strval', array_keys($field['options'])), 'strlen'));
            }

            $rows[] = $row;
        }

        usort($rows, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        return $rows;
    }

    /**
     * Who the admin notification goes to right now, and why.
     *
     * @return array{addresses: string[], source: string}
     */
    public static function effective_recipients() {
        $default = get_option('admin_email');
        $to      = apply_filters('wp_travel_enquiries_admin_emails', $default);
        $list    = is_array($to) ? $to : array_map('trim', explode(',', (string) $to));
        $list    = array_values(array_filter($list, 'is_email'));
        $stored  = self::stored();

        return array(
            'addresses' => $list,
            'source'    => $stored['recipients'] ? 'wta_inquiry_form option' : 'site admin email (WP Travel default)',
        );
    }

    /**
     * What stands between a bot and the enquiry form. Booleans and names only:
     * the settings option holds the reCAPTCHA secret and none of it is returned.
     *
     * @return array<string, mixed>
     */
    public static function anti_spam() {
        $all = get_option(self::WP_TRAVEL_OPTION, array());
        $all = is_array($all) ? $all : array();

        return array(
            'recaptcha_keys_set'      => !empty($all['recaptcha_v2_site_key']) && !empty($all['recaptcha_v2_secret_key']),
            // WP Travel 12.0.4 reads the reCAPTCHA keys only for its review form,
            // behind a filter that is off by default. They do not protect enquiries.
            'recaptcha_on_enquiry'    => false,
            'nonce'                   => true,
            'validation_filter_in_use' => (bool) has_filter('wp_travel_frontend_enqueries_validation_check'),
        );
    }

    /* --------------------------------------------------------------- render */

    /**
     * WP Travel's own enquiry form for one trip, as HTML.
     *
     * wptravel_get_enquiries_form() reads the trip from the global $post and
     * echoes, so the global is swapped for the call and restored after, and the
     * output is captured. Called with false because the true form turns the
     * trip into a dropdown of every trip, which is the shortcode's behaviour and
     * the wrong one on a trip page.
     *
     * @return string Empty when WP Travel is not there or the trip is not one.
     */
    public static function render_for_trip($trip_id) {
        $trip_id = absint($trip_id);

        if (!self::form_available() || !$trip_id || !class_exists('WTA_Trip') || get_post_type($trip_id) !== WTA_Trip::post_type()) {
            return '';
        }

        global $post;

        $previous = $post;
        $post     = get_post($trip_id); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        ob_start();
        wptravel_get_enquiries_form(false);
        $html = ob_get_clean();

        $post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        return is_string($html) ? $html : '';
    }

    /* ---------------------------------------------------------------- price */

    /**
     * The lowest published price for a trip, or 0 when there is none.
     *
     * wp_travel_trip_price reads 0 on trips whose real price is a row in
     * wt_pricings, so the post meta is only the first answer. When it is empty
     * the pricing tables are read through the same discovery helpers the pricing
     * abilities use, and the sale price wins over the regular price on a row
     * that is on sale. A per-person price and a per-group price are not
     * comparable, so the result says which it is.
     *
     * @return array{amount: float, per: string}
     */
    public static function trip_price($trip_id) {
        $none = array('amount' => 0.0, 'per' => '');
        $meta = get_post_meta($trip_id, 'wp_travel_trip_price', true);

        if ('' !== $meta && null !== $meta && (float) $meta > 0) {
            return array('amount' => (float) $meta, 'per' => 'person');
        }

        if (!class_exists('WTA_Travel_Abilities')) {
            return $none;
        }

        $pricings = WTA_Travel_Abilities::read_trip_rows('wt_pricings', $trip_id);

        if (empty($pricings['rows'])) {
            return $none;
        }

        $relations = WTA_Travel_Abilities::read_related_rows('wt_price_category_relation', $pricings);
        $best      = $none;

        foreach ((array) $relations['rows'] as $row) {
            $sale    = isset($row['sale_price']) ? (float) $row['sale_price'] : 0.0;
            $regular = isset($row['regular_price']) ? (float) $row['regular_price'] : 0.0;
            $amount  = (!empty($row['is_sale']) && $sale > 0) ? $sale : $regular;

            if ($amount <= 0) {
                continue;
            }

            if (0.0 === $best['amount'] || $amount < $best['amount']) {
                $best = array(
                    'amount' => $amount,
                    'per'    => isset($row['price_per']) && 'group' === $row['price_per'] ? 'group' : 'person',
                );
            }
        }

        return $best;
    }
}
