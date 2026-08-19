<?php
/**
 * The facts a destination or an activity needs before it can be presented.
 *
 * WordPress gives a term a name and a description; WP Travel adds an image and
 * stops there. That is enough for a filter but not for a page. An activity card
 * for "Ballooning" has to say how long it takes and when to do it, and a
 * destination has to carry its own gateway airport, currency and season, none of
 * which exist anywhere in the stack today.
 *
 * The two taxonomies want genuinely different facts, so there is one field map
 * per taxonomy rather than a shared superset with half the boxes greyed out. A
 * field is described once, in fields(), and the admin control, the sanitiser,
 * the REST schema and the list-table summary are all derived from that entry —
 * adding a field is a one-place edit.
 *
 * Every value is term meta under a filterable wta_ prefix, one key per field, so
 * a value stays legible in the database and readable by anything that knows the
 * key name. Nothing here is content: the operator supplies every value.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Term_Fields {

    /**
     * Prefix for every meta key this class owns.
     *
     * Filterable for the same reason WTA_Term_Media's key is: a site that has
     * already imported these facts under another prefix must be able to point
     * this at them rather than re-key its database.
     */
    const META_PREFIX = 'wta_';

    /** One nonce covers the whole field set — the form is saved as a unit. */
    const NONCE_ACTION = 'wta_term_fields_save';
    const NONCE_FIELD  = 'wta_term_fields_nonce';

    /** All inputs post as one array, so save() can iterate the field map. */
    const INPUT_NAME = 'wta_term_fields';

    /** Difficulty is a closed set; '' is a real choice meaning "not graded". */
    const DIFFICULTIES = array('', 'easy', 'moderate', 'strenuous');

    /**
     * all() results for this request, keyed by term ID.
     *
     * A destination archive asks for the same term's facts from the header, the
     * card and the schema markup; without this that is three passes over the
     * whole field map.
     *
     * @var array<int, array>
     */
    protected static $cache = array();

    public function __construct() {
        foreach (self::taxonomies() as $taxonomy) {
            add_action("{$taxonomy}_add_form_fields", array($this, 'render_add_fields'));
            add_action("{$taxonomy}_edit_form_fields", array($this, 'render_edit_fields'), 10, 2);
            add_filter("manage_edit-{$taxonomy}_columns", array($this, 'add_column'));
            add_filter("manage_{$taxonomy}_custom_column", array($this, 'render_column'), 10, 3);
        }

        add_action('created_term', array($this, 'save'), 10, 3);
        add_action('edited_term', array($this, 'save'), 10, 3);

        add_action('init', array($this, 'register_meta'), 20);
        add_action('rest_api_init', array($this, 'register_rest_fields'));
    }

    /**
     * Taxonomies that carry a field set.
     *
     * Only two of WP Travel's four: trip types and keywords are navigation
     * devices, not places or things to do, and have no facts of their own.
     *
     * @return string[]
     */
    public static function taxonomies() {
        return apply_filters('wta_term_field_taxonomies', array('activity', 'travel_locations'));
    }

    public function handles($taxonomy) {
        return in_array($taxonomy, self::taxonomies(), true);
    }

    /**
     * Meta key for a field.
     *
     * @param string $key Field key, e.g. 'duration'.
     * @return string e.g. 'wta_duration'.
     */
    public static function meta_key($key) {
        return apply_filters('wta_term_meta_prefix', self::META_PREFIX) . $key;
    }

    /* ----------------------------------------------------------- Field map */

    /**
     * The field set for a taxonomy.
     *
     * Each entry carries everything the rest of the class needs:
     *
     *   label       Admin label, also the REST description prefix.
     *   help        One line under the control. Absent means none.
     *   control     text | number | select | checkbox | months | textarea
     *   sanitize    Method on this class. Lenient: never returns WP_Error.
     *   rest        REST schema fragment for this field.
     *   default     Value get() reports when nothing is stored.
     *
     * @param string $taxonomy
     * @return array<string, array> Empty for a taxonomy with no field set.
     */
    public static function fields($taxonomy) {
        $months = array(
            'label'    => 'Best months',
            'help'     => 'Leave all unticked when the answer is any time of year.',
            'control'  => 'months',
            'sanitize' => 'sanitize_months',
            'default'  => array(),
            'rest'     => array(
                'type'        => 'array',
                'description' => 'Calendar months, 1-12, when this is at its best.',
                'items'       => array('type' => 'integer', 'minimum' => 1, 'maximum' => 12),
            ),
        );

        $standout = array(
            'label'    => 'Standout',
            'help'     => 'One line: the single thing that makes this worth the trip.',
            'control'  => 'text',
            'sanitize' => 'sanitize_text',
            'default'  => '',
            'rest'     => array('type' => 'string'),
        );

        $long_description = array(
            'label'    => 'Long description',
            'help'     => 'The body copy for the archive header. Basic HTML is kept.',
            'control'  => 'textarea',
            'sanitize' => 'sanitize_html',
            'default'  => '',
            'rest'     => array('type' => 'string'),
        );

        $sets = array(
            'activity' => array(
                // Free text, not a number: an activity is honestly described as
                // "3-4 hours" or "Half day", and forcing that into minutes would
                // make the operator invent a precision that does not exist.
                'duration'         => array(
                    'label'    => 'Duration',
                    'help'     => 'As you would say it: "3-4 hours", "Half day", "1 hour".',
                    'control'  => 'text',
                    'sanitize' => 'sanitize_text',
                    'default'  => '',
                    'rest'     => array('type' => 'string'),
                ),
                'best_months'      => $months,
                'difficulty'       => array(
                    'label'    => 'Difficulty',
                    'control'  => 'select',
                    'options'  => array(
                        ''          => 'Not graded',
                        'easy'      => 'Easy',
                        'moderate'  => 'Moderate',
                        'strenuous' => 'Strenuous',
                    ),
                    'sanitize' => 'sanitize_difficulty',
                    'default'  => '',
                    'rest'     => array('type' => 'string', 'enum' => self::DIFFICULTIES),
                ),
                'min_age'          => array(
                    'label'    => 'Minimum age',
                    'help'     => '0 when there is no minimum.',
                    'control'  => 'number',
                    'sanitize' => 'sanitize_age',
                    'default'  => 0,
                    'rest'     => array('type' => 'integer', 'minimum' => 0),
                ),
                // Also free text: these figures are indicative, usually ranged,
                // and quoted per person in whichever currency the supplier uses.
                'typical_cost'     => array(
                    'label'    => 'Typical cost',
                    'help'     => 'Indicative only, e.g. "from $450 pp".',
                    'control'  => 'text',
                    'sanitize' => 'sanitize_text',
                    'default'  => '',
                    'rest'     => array('type' => 'string'),
                ),
                'permit_required'  => array(
                    'label'    => 'Permit required',
                    'help'     => 'Tick when the activity cannot be booked without a permit.',
                    'control'  => 'checkbox',
                    'sanitize' => 'sanitize_bool',
                    'default'  => false,
                    'rest'     => array('type' => 'boolean'),
                ),
                'standout'         => $standout,
                'long_description' => $long_description,
            ),

            'travel_locations' => array(
                'best_months'      => $months,
                // A park or a city term sits inside a country, but the taxonomy
                // is not reliably nested that way, so the country is recorded
                // rather than derived from the term's parent.
                'country'          => array(
                    'label'    => 'Country',
                    'control'  => 'text',
                    'sanitize' => 'sanitize_text',
                    'default'  => '',
                    'rest'     => array('type' => 'string'),
                ),
                'airport'          => array(
                    'label'    => 'Main gateway airport',
                    'help'     => 'Name and code, e.g. "Kilimanjaro International, JRO".',
                    'control'  => 'text',
                    'sanitize' => 'sanitize_text',
                    'default'  => '',
                    'rest'     => array('type' => 'string'),
                ),
                'languages'        => array(
                    'label'    => 'Languages',
                    'control'  => 'text',
                    'sanitize' => 'sanitize_text',
                    'default'  => '',
                    'rest'     => array('type' => 'string'),
                ),
                'currency'         => array(
                    'label'    => 'Currency',
                    'control'  => 'text',
                    'sanitize' => 'sanitize_text',
                    'default'  => '',
                    'rest'     => array('type' => 'string'),
                ),
                'visa_note'        => array(
                    'label'    => 'Visa note',
                    'help'     => 'A sentence or two. Basic HTML is kept.',
                    'control'  => 'textarea',
                    'sanitize' => 'sanitize_html',
                    'default'  => '',
                    'rest'     => array('type' => 'string'),
                ),
                'standout'         => $standout,
                'long_description' => $long_description,
            ),
        );

        $set = isset($sets[$taxonomy]) ? $sets[$taxonomy] : array();

        return apply_filters('wta_term_fields', $set, $taxonomy);
    }

    /* -------------------------------------------------------------- Reading */

    /**
     * Every field for a term, sanitised and ready to render.
     *
     * @param int $term_id
     * @return array<string, mixed> Field key => value. Empty for a term in a
     *                               taxonomy with no field set.
     */
    public static function all($term_id) {
        $term_id = (int) $term_id;

        if ($term_id <= 0) {
            return array();
        }

        if (isset(self::$cache[$term_id])) {
            return self::$cache[$term_id];
        }

        // Cached before the work, so a term in a taxonomy with no field set —
        // or a term that does not exist — is not re-derived on every ask.
        self::$cache[$term_id] = array();

        $term = get_term($term_id);

        if (!$term instanceof WP_Term) {
            return array();
        }

        $fields = self::fields($term->taxonomy);

        if (!$fields) {
            return array();
        }

        // One read for the whole term. Asking per field would still hit the
        // meta cache after the first call, but only because something else
        // primed it — this makes the single round trip explicit, which matters
        // on an archive rendering the facts of thirty terms.
        $raw = get_term_meta($term_id);
        $raw = is_array($raw) ? $raw : array();

        $out = array();

        foreach ($fields as $key => $field) {
            $meta_key = self::meta_key($key);

            if (!isset($raw[$meta_key][0])) {
                $out[$key] = $field['default'];
                continue;
            }

            // A keyless get_term_meta() returns the raw column, so arrays come
            // back still serialised.
            $value = maybe_unserialize($raw[$meta_key][0]);
            $value = self::clean($field, $value);

            $out[$key] = self::is_blank($field, $value) ? $field['default'] : $value;
        }

        self::$cache[$term_id] = $out;

        return $out;
    }

    /**
     * One field for a term.
     *
     * @param int    $term_id
     * @param string $key     Field key without the prefix.
     * @param mixed  $default Returned when the field is unset or unknown.
     * @return mixed
     */
    public static function get($term_id, $key, $default = null) {
        $values = self::all($term_id);

        if (!array_key_exists($key, $values)) {
            return $default;
        }

        // A stored blank is indistinguishable from an unset field — both are
        // deleted on save — so an explicit $default wins over the field default.
        if (null !== $default) {
            $field = self::field($term_id, $key);

            if ($field && self::is_blank($field, $values[$key])) {
                return $default;
            }
        }

        return $values[$key];
    }

    /**
     * Store or clear one field.
     *
     * @param int    $term_id
     * @param string $key
     * @param mixed  $value Raw; sanitised here. A blank deletes the row.
     * @return bool False when the field does not apply to this term.
     */
    public static function set($term_id, $key, $value) {
        $term_id = (int) $term_id;
        $field   = self::field($term_id, $key);

        if (!$field) {
            return false;
        }

        self::flush($term_id);

        $clean = self::clean($field, $value);

        if (self::is_blank($field, $clean)) {
            // An empty string or an empty array stored against a term reads as
            // "answered, with nothing" to every consumer, and makes the list
            // table unable to show which terms still need filling in.
            delete_term_meta($term_id, self::meta_key($key));

            return true;
        }

        update_term_meta($term_id, self::meta_key($key), $clean);

        return true;
    }

    /**
     * The field definition for a term's taxonomy, or null.
     */
    protected static function field($term_id, $key) {
        $term = get_term((int) $term_id);

        if (!$term instanceof WP_Term) {
            return null;
        }

        $fields = self::fields($term->taxonomy);

        return isset($fields[$key]) ? $fields[$key] : null;
    }

    /**
     * Drop the per-request cache.
     *
     * Every write goes through set(), which calls this, so a save followed by a
     * read in the same request — which is exactly what the term list table does
     * after an inline edit — cannot serve the values from before the save.
     *
     * @param int $term_id 0 clears every term.
     */
    public static function flush($term_id = 0) {
        $term_id = (int) $term_id;

        if ($term_id > 0) {
            unset(self::$cache[$term_id]);

            return;
        }

        self::$cache = array();
    }

    /* --------------------------------------------------------------- Months */

    /**
     * Month abbreviations, January first.
     *
     * @return array<int, string>
     */
    public static function month_labels() {
        return apply_filters('wta_term_month_labels', array(
            1  => 'Jan',
            2  => 'Feb',
            3  => 'Mar',
            4  => 'Apr',
            5  => 'May',
            6  => 'Jun',
            7  => 'Jul',
            8  => 'Aug',
            9  => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dec',
        ));
    }

    /**
     * A term's best months as a compact human string.
     *
     * Reads the one key directly rather than through all(), so the most-rendered
     * string in the system does not have to load a term object first.
     *
     * @param int $term_id
     * @return string e.g. "Jun-Sep", "Nov-Feb", "Mar". '' when unset.
     */
    public static function best_months_label($term_id) {
        $stored = get_term_meta((int) $term_id, self::meta_key('best_months'), true);

        return self::months_label($stored);
    }

    /**
     * Collapse a set of months into runs.
     *
     * @param array|string $months
     * @return string
     */
    public static function months_label($months) {
        $months = self::sanitize_months($months);

        if (!$months) {
            return '';
        }

        if (12 === count($months)) {
            return 'Year-round';
        }

        $names = self::month_labels();
        $runs  = array();
        $start = null;
        $prev  = null;

        foreach ($months as $month) {
            if (null === $start) {
                $start = $month;
                $prev  = $month;
                continue;
            }

            if ($month === $prev + 1) {
                $prev = $month;
                continue;
            }

            $runs[] = array($start, $prev);
            $start  = $month;
            $prev   = $month;
        }

        $runs[] = array($start, $prev);

        // A season crossing the new year arrives as two runs at opposite ends of
        // an ascending list. Left alone, the green season reads "Jan-Feb,
        // Nov-Dec" — which is four months stated as two seasons, and wrong about
        // most of Africa. Joining the tail to the head restores "Nov-Feb".
        $count = count($runs);

        if ($count > 1 && 1 === $runs[0][0] && 12 === $runs[$count - 1][1]) {
            $head = $runs[0];
            $tail = $runs[$count - 1];

            array_pop($runs);
            array_shift($runs);
            array_unshift($runs, array($tail[0], $head[1]));
        }

        $parts = array();

        foreach ($runs as $run) {
            $parts[] = $run[0] === $run[1]
                ? $names[$run[0]]
                : $names[$run[0]] . '-' . $names[$run[1]];
        }

        return implode(', ', $parts);
    }

    /* ---------------------------------------------------------- Sanitisers */

    /**
     * Run a field's own sanitiser. Lenient by design: bad parts are dropped
     * rather than rejected, because the admin form must never lose a whole save
     * over one malformed control.
     */
    protected static function clean($field, $value) {
        return call_user_func(array(__CLASS__, $field['sanitize']), $value);
    }

    public static function sanitize_text($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return sanitize_text_field((string) $value);
    }

    public static function sanitize_html($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim(wp_kses_post((string) $value));
    }

    public static function sanitize_bool($value) {
        if (is_string($value)) {
            return in_array(strtolower($value), array('1', 'true', 'yes', 'on'), true);
        }

        return (bool) $value;
    }

    public static function sanitize_age($value) {
        if (is_array($value) || is_object($value) || !is_numeric($value)) {
            return 0;
        }

        // Nothing on the catalogue has a minimum past the late teens; the cap
        // stops a typo storing an age that hides the activity from everyone.
        return min(120, max(0, (int) $value));
    }

    public static function sanitize_difficulty($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $value = sanitize_key((string) $value);

        return in_array($value, self::DIFFICULTIES, true) ? $value : '';
    }

    /**
     * Months as a sorted, deduplicated list of ints 1-12.
     *
     * Accepts a comma-separated string too, because that is what an importer or
     * a hand-written REST call tends to send.
     *
     * @return int[]
     */
    public static function sanitize_months($value) {
        if (is_string($value)) {
            $value = ('' === trim($value)) ? array() : explode(',', $value);
        }

        if (!is_array($value)) {
            return array();
        }

        $out = array();

        foreach ($value as $month) {
            if (is_array($month) || is_object($month) || !is_numeric($month)) {
                continue;
            }

            $month = (int) $month;

            if ($month < 1 || $month > 12) {
                continue;
            }

            // Keyed by value: a term with January listed twice is one January.
            $out[$month] = $month;
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * Whether a value counts as "not answered" and should be deleted.
     */
    protected static function is_blank($field, $value) {
        switch ($field['control']) {
            case 'months':
                return !is_array($value) || !$value;

            case 'checkbox':
                return !$value;

            case 'number':
                // 0 is the documented "no minimum", which is the same as unset.
                return 0 === (int) $value;

            default:
                return '' === trim((string) $value);
        }
    }

    /* -------------------------------------------------------------- Admin UI */

    /**
     * Add-term screen. WordPress wraps these in the form itself, so each field
     * is its own div rather than a table row.
     */
    public function render_add_fields($taxonomy) {
        $fields = self::fields($taxonomy);

        if (!$fields) {
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, false);

        foreach ($fields as $key => $field) {
            ?>
            <div class="form-field">
                <label for="<?php echo esc_attr(self::input_id($key)); ?>"><?php echo esc_html($field['label']); ?></label>
                <?php $this->render_control($key, $field, $field['default']); ?>
                <?php if (!empty($field['help'])) : ?>
                    <p><?php echo esc_html($field['help']); ?></p>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    /**
     * Edit-term screen, which is a table.
     */
    public function render_edit_fields($term, $taxonomy) {
        $fields = self::fields($taxonomy);

        if (!$fields) {
            return;
        }

        $values = self::all($term->term_id);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, false);

        foreach ($fields as $key => $field) {
            $value = array_key_exists($key, $values) ? $values[$key] : $field['default'];
            ?>
            <tr class="form-field">
                <th scope="row">
                    <label for="<?php echo esc_attr(self::input_id($key)); ?>"><?php echo esc_html($field['label']); ?></label>
                </th>
                <td>
                    <?php $this->render_control($key, $field, $value); ?>
                    <?php if (!empty($field['help'])) : ?>
                        <p class="description"><?php echo esc_html($field['help']); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * One control. Shared by both screens so they cannot drift apart.
     */
    protected function render_control($key, $field, $value) {
        $name = self::input_name($key);
        $id   = self::input_id($key);

        switch ($field['control']) {
            case 'months':
                $this->render_months($key, is_array($value) ? $value : array());
                break;

            case 'select':
                $options = isset($field['options']) ? $field['options'] : array();
                ?>
                <select name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>">
                    <?php foreach ($options as $option_value => $option_label) : ?>
                        <option value="<?php echo esc_attr($option_value); ?>" <?php selected((string) $value, (string) $option_value); ?>>
                            <?php echo esc_html($option_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php
                break;

            case 'checkbox':
                ?>
                <label for="<?php echo esc_attr($id); ?>">
                    <input type="checkbox"
                           name="<?php echo esc_attr($name); ?>"
                           id="<?php echo esc_attr($id); ?>"
                           value="1"
                           <?php checked((bool) $value); ?>>
                    <?php echo esc_html($field['label']); ?>
                </label>
                <?php
                break;

            case 'number':
                ?>
                <input type="number"
                       name="<?php echo esc_attr($name); ?>"
                       id="<?php echo esc_attr($id); ?>"
                       value="<?php echo esc_attr((string) (int) $value); ?>"
                       min="0"
                       max="120"
                       step="1"
                       class="small-text">
                <?php
                break;

            case 'textarea':
                ?>
                <textarea name="<?php echo esc_attr($name); ?>"
                          id="<?php echo esc_attr($id); ?>"
                          rows="5"
                          cols="50"
                          class="large-text"><?php echo esc_textarea((string) $value); ?></textarea>
                <?php
                break;

            default:
                ?>
                <input type="text"
                       name="<?php echo esc_attr($name); ?>"
                       id="<?php echo esc_attr($id); ?>"
                       value="<?php echo esc_attr((string) $value); ?>"
                       class="regular-text">
                <?php
                break;
        }
    }

    /**
     * Twelve checkboxes rather than a multi-select: a season is read as a shape
     * across the year, and a grid shows that shape at a glance.
     */
    protected function render_months($key, $selected) {
        $name   = self::input_name($key) . '[]';
        $labels = self::month_labels();
        ?>
        <span id="<?php echo esc_attr(self::input_id($key)); ?>" style="display:inline-block;">
            <?php foreach ($labels as $month => $label) : ?>
                <label style="display:inline-block;min-width:64px;margin:0 4px 4px 0;">
                    <input type="checkbox"
                           name="<?php echo esc_attr($name); ?>"
                           value="<?php echo esc_attr((string) $month); ?>"
                           <?php checked(in_array($month, $selected, true)); ?>>
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </span>
        <?php
    }

    protected static function input_name($key) {
        return self::INPUT_NAME . '[' . $key . ']';
    }

    protected static function input_id($key) {
        return 'wta-term-field-' . str_replace('_', '-', $key);
    }

    /* ----------------------------------------------------------------- Save */

    /**
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     */
    public function save($term_id, $tt_id, $taxonomy) {
        if (!$this->handles($taxonomy)) {
            return;
        }

        // No nonce means this term was saved by something that is not our form
        // — a quick edit, an importer, a REST write — and blanking every field
        // because its inputs are absent would be data loss.
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        $nonce = sanitize_key(wp_unslash($_POST[self::NONCE_FIELD]));

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can('manage_categories')) {
            return;
        }

        $fields = self::fields($taxonomy);

        if (!$fields) {
            return;
        }

        $posted = isset($_POST[self::INPUT_NAME]) && is_array($_POST[self::INPUT_NAME])
            ? wp_unslash($_POST[self::INPUT_NAME])
            : array();

        foreach ($fields as $key => $field) {
            // An absent key is a real answer here: an unticked checkbox and an
            // empty month grid post nothing at all, and both mean "clear it".
            $raw = array_key_exists($key, $posted) ? $posted[$key] : null;

            self::set($term_id, $key, $raw);
        }
    }

    /* --------------------------------------------------------- List table */

    /**
     * A Details column, placed before the count so the facts sit next to the
     * name and the gaps line up vertically.
     */
    public function add_column($columns) {
        $out = array();

        foreach ($columns as $key => $label) {
            if ('posts' === $key) {
                $out['wta_details'] = 'Details';
            }

            $out[$key] = $label;
        }

        if (!isset($out['wta_details'])) {
            $out['wta_details'] = 'Details';
        }

        return $out;
    }

    public function render_column($content, $column, $term_id) {
        if ('wta_details' !== $column) {
            return $content;
        }

        $term = get_term((int) $term_id);

        if (!$term instanceof WP_Term) {
            return $content;
        }

        $values = self::all($term_id);
        $parts  = array();

        // Two facts per taxonomy, chosen because they are the ones a card shows
        // and therefore the ones whose absence is visible on the front end.
        if ('activity' === $term->taxonomy) {
            if (!empty($values['duration'])) {
                $parts[] = $values['duration'];
            }
        } elseif (!empty($values['country'])) {
            $parts[] = $values['country'];
        }

        $months = isset($values['best_months']) ? self::months_label($values['best_months']) : '';

        if ('' !== $months) {
            $parts[] = $months;
        }

        if (!$parts) {
            return '<span style="color:#8C8F94;">—</span>';
        }

        return esc_html(implode(' · ', $parts));
    }

    /* ----------------------------------------------------------------- REST */

    /**
     * Registered as real term meta, but kept out of the generic `meta` object:
     * the REST fields below give each key a typed schema and a validator, which
     * a raw meta passthrough cannot.
     */
    public function register_meta() {
        foreach (self::taxonomies() as $taxonomy) {
            foreach (self::fields($taxonomy) as $key => $field) {
                register_term_meta($taxonomy, self::meta_key($key), array(
                    'type'          => 'months' === $field['control'] ? 'array' : $field['rest']['type'],
                    'single'        => true,
                    'show_in_rest'  => false,
                    'auth_callback' => function () {
                        return current_user_can('manage_categories');
                    },
                ));
            }
        }
    }

    /**
     * One REST field per key, so wta_duration and wta_best_months appear on the
     * term object itself rather than nested inside a container the client has
     * to know about.
     */
    public function register_rest_fields() {
        foreach (self::taxonomies() as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            foreach (self::fields($taxonomy) as $key => $field) {
                register_rest_field($taxonomy, self::meta_key($key), array(
                    'get_callback'    => function ($term) use ($key, $field) {
                        $value = self::get($term['id'], $key);

                        return null === $value ? $field['default'] : $value;
                    },
                    'update_callback' => function ($value, $term) use ($key, $field) {
                        if (!current_user_can('manage_categories')) {
                            return new WP_Error(
                                'wta_forbidden',
                                'You are not allowed to edit term details.',
                                array('status' => 403)
                            );
                        }

                        $checked = self::validate($key, $field, $value);

                        if (is_wp_error($checked)) {
                            return $checked;
                        }

                        self::set($term->term_id, $key, $checked);

                        return true;
                    },
                    'schema'          => array_merge(
                        array(
                            'description' => $field['label'] . (empty($field['help']) ? '' : '. ' . $field['help']),
                            'context'     => array('view', 'edit'),
                        ),
                        $field['rest']
                    ),
                ));
            }
        }
    }

    /**
     * Strict checking for API writes.
     *
     * The admin form drops what it cannot use, because a human is looking at the
     * screen and will see the result. An API client is not, so a bad month or an
     * unknown difficulty is refused rather than silently discarded.
     *
     * @return mixed|WP_Error
     */
    protected static function validate($key, $field, $value) {
        $meta_key = self::meta_key($key);

        if ('months' === $field['control']) {
            if (is_string($value)) {
                $value = ('' === trim($value)) ? array() : explode(',', $value);
            }

            if (!is_array($value)) {
                return new WP_Error(
                    'wta_bad_months',
                    sprintf('%s must be an array of month numbers.', $meta_key),
                    array('status' => 400)
                );
            }

            foreach ($value as $month) {
                if (!is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
                    return new WP_Error(
                        'wta_bad_months',
                        sprintf('%s accepts month numbers 1-12 only.', $meta_key),
                        array('status' => 400)
                    );
                }
            }

            return self::sanitize_months($value);
        }

        if (isset($field['rest']['enum'])) {
            $candidate = is_scalar($value) ? (string) $value : null;

            if (null === $candidate || !in_array($candidate, $field['rest']['enum'], true)) {
                return new WP_Error(
                    'wta_bad_enum',
                    sprintf(
                        '%s must be one of: %s.',
                        $meta_key,
                        implode(', ', array_map(function ($allowed) {
                            return '' === $allowed ? '""' : $allowed;
                        }, $field['rest']['enum']))
                    ),
                    array('status' => 400)
                );
            }

            return $candidate;
        }

        if ('integer' === $field['rest']['type'] && !is_numeric($value)) {
            return new WP_Error(
                'wta_bad_integer',
                sprintf('%s must be a number.', $meta_key),
                array('status' => 400)
            );
        }

        if ('boolean' === $field['rest']['type'] && !is_scalar($value) && null !== $value) {
            return new WP_Error(
                'wta_bad_boolean',
                sprintf('%s must be true or false.', $meta_key),
                array('status' => 400)
            );
        }

        if ('string' === $field['rest']['type'] && !is_scalar($value) && null !== $value) {
            return new WP_Error(
                'wta_bad_string',
                sprintf('%s must be a string.', $meta_key),
                array('status' => 400)
            );
        }

        return $value;
    }
}
