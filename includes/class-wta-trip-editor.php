<?php
/**
 * The trip edit screen for everything WTA_Itinerary_Schema stores.
 *
 * The schema was built API-first, which is fine for a bulk import and useless
 * for the person who has one trip to write up this afternoon. This module puts
 * the same eight structures on the trip edit screen so a whole itinerary can be
 * authored without a REST client.
 *
 * It owns no data shape of its own. The field names here are built to match the
 * arrays WTA_Itinerary_Schema's sanitisers already expect, every value is passed
 * back through those same sanitisers on save, and the meta keys are the schema's
 * constants. If the schema changes, this screen follows it rather than drifting
 * from it.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Trip_Editor {

    /** Nonce action and field name. */
    const NONCE = 'wta_trip_editor';

    /** Root key for every posted value, so save() reads one branch of $_POST. */
    const ROOT = 'wta';

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post', array($this, 'save'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    /* ---------------------------------------------------------------- groups */

    /**
     * The seven panels, in schema order.
     *
     * Derived from WTA_Itinerary_Schema::fields() rather than restated, so the
     * meta key and the sanitiser can never fall out of step with the schema.
     * Only the human labels live here: "options" reads as "Choices" and
     * "checklist" as "Booking order" to the person filling the form in.
     *
     * @return array<string, array{meta: string, label: string, sanitize: string}>
     */
    public static function groups() {
        $labels = array(
            'hero'        => 'Hero',
            'legs'        => 'Legs',
            'route'       => 'Route',
            'seasonality' => 'Seasonality',
            'options'     => 'Choices',
            'checklist'   => 'Booking order',
            'notes'       => 'Field notes',
        );

        $groups = array();

        foreach (WTA_Itinerary_Schema::fields() as $meta_key => $field) {
            $key = $field['rest_key'];

            if (!isset($labels[$key])) {
                continue;
            }

            $groups[$key] = array(
                'meta'     => $meta_key,
                'label'    => $labels[$key],
                'sanitize' => $field['sanitize'],
            );
        }

        return $groups;
    }

    /** Month labels, January first, matching the twelve slots the schema keeps. */
    public static function months() {
        return array(
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        );
    }

    /* ------------------------------------------------------------- registration */

    /**
     * One meta box for all eight structures.
     *
     * Eight separate boxes would each be collapsible and separately sortable,
     * which scatters a single editing job across the screen. Tabs inside one box
     * keep it in one place.
     */
    public function add_meta_box() {
        // WP Travel may be inactive; registering against a post type that does
        // not exist would put the box nowhere useful.
        if (!WTA_Trip::is_available()) {
            return;
        }

        add_meta_box(
            'wta-trip-editor',
            'Itinerary detail — Nyuchi',
            array($this, 'render'),
            WTA_Trip::post_type(),
            'normal',
            'high'
        );
    }

    /**
     * Only load on the trip editor. The hook suffix alone would also match every
     * other post type's edit screen.
     */
    public function enqueue($hook) {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }

        $screen = get_current_screen();

        if (!$screen || WTA_Trip::post_type() !== $screen->post_type) {
            return;
        }

        wp_enqueue_style(
            'wta-trip-editor',
            WTA_URL . 'assets/css/trip-editor.css',
            array(),
            WTA_VERSION
        );

        wp_enqueue_script(
            'wta-trip-editor',
            WTA_URL . 'assets/js/trip-editor.js',
            array(),
            WTA_VERSION,
            true
        );
    }

    /* ------------------------------------------------------------------ saving */

    /**
     * @param int $post_id
     */
    public function save($post_id) {
        if (!isset($_POST[self::NONCE])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE]));

        if (!wp_verify_nonce($nonce, self::NONCE)) {
            return;
        }

        // An autosave posts a partial form; writing it would blank every group
        // the autosave did not carry.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (WTA_Trip::post_type() !== get_post_type($post_id)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $posted = array();

        if (isset($_POST[self::ROOT]) && is_array($_POST[self::ROOT])) {
            // Unslashed once here; the schema sanitisers do the actual cleaning.
            $posted = wp_unslash($_POST[self::ROOT]);
        }

        foreach (self::groups() as $key => $group) {
            $raw = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : array();

            if ('seasonality' === $key) {
                $raw = self::normalise_seasonality($raw);
            }

            $clean = call_user_func(array('WTA_Itinerary_Schema', $group['sanitize']), $raw);

            // Several sanitisers return a fully formed skeleton even from
            // nothing, so a cleared group would otherwise persist as an empty
            // structure and keep overriding the front end's fallbacks.
            if (self::is_blank($clean)) {
                delete_post_meta($post_id, $group['meta']);
                continue;
            }

            update_post_meta($post_id, $group['meta'], $clean);
        }
    }

    /**
     * Tags are entered as one comma-separated line because twelve months of
     * repeaters would be unusable; the schema wants an array.
     *
     * @param array $raw
     * @return array
     */
    protected static function normalise_seasonality($raw) {
        if (empty($raw['months']) || !is_array($raw['months'])) {
            return $raw;
        }

        foreach ($raw['months'] as $i => $month) {
            if (!is_array($month) || !isset($month['tags']) || is_array($month['tags'])) {
                continue;
            }

            $tags = array_filter(array_map('trim', explode(',', (string) $month['tags'])), 'strlen');

            $raw['months'][$i]['tags'] = array_values($tags);
        }

        return $raw;
    }

    /**
     * Whether a sanitised group carries nothing worth storing.
     *
     * Zero counts as blank: every numeric input defaults to 0 once sanitised, so
     * a row of untouched number fields is an empty row, not a row of zeroes.
     *
     * @param mixed $value
     * @return bool
     */
    protected static function is_blank($value) {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::is_blank($item)) {
                    return false;
                }
            }

            return true;
        }

        $scalar = trim((string) $value);

        return '' === $scalar || '0' === $scalar;
    }

    /* --------------------------------------------------------------- rendering */

    /**
     * @param WP_Post $post
     */
    public function render($post) {
        wp_nonce_field(self::NONCE, self::NONCE);

        $groups = self::groups();
        ?>
        <div class="wta-editor">
            <p class="wta-lede">
                Everything WP Travel does not store. Day content still lives in the WP Travel
                itinerary below; nothing here duplicates it.
            </p>

            <div class="wta-tabs" role="tablist" aria-label="Itinerary detail sections">
                <?php $first = true; ?>
                <?php foreach ($groups as $key => $group) : ?>
                    <button type="button"
                            class="wta-tab<?php echo $first ? ' is-active' : ''; ?>"
                            id="wta-tab-<?php echo esc_attr($key); ?>"
                            role="tab"
                            aria-controls="wta-panel-<?php echo esc_attr($key); ?>"
                            aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
                        <?php echo esc_html($group['label']); ?>
                    </button>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>

            <div class="wta-panels">
                <?php $first = true; ?>
                <?php foreach ($groups as $key => $group) : ?>
                    <section class="wta-panel<?php echo $first ? ' is-active' : ''; ?>"
                             id="wta-panel-<?php echo esc_attr($key); ?>"
                             role="tabpanel"
                             aria-labelledby="wta-tab-<?php echo esc_attr($key); ?>"
                             <?php echo $first ? '' : 'hidden'; ?>>
                        <?php $this->panel($key, $post->ID); ?>
                    </section>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param string $key
     * @param int    $post_id
     */
    protected function panel($key, $post_id) {
        $method = 'panel_' . $key;

        if (method_exists($this, $method)) {
            $this->{$method}($post_id);
        }
    }

    /** Stored group value, always an array so the render helpers can index it. */
    protected function value($post_id, $meta_key) {
        $value = get_post_meta($post_id, $meta_key, true);

        return is_array($value) ? $value : array();
    }

    /** @return mixed */
    protected static function pick($array, $key, $default = '') {
        return (is_array($array) && isset($array[$key])) ? $array[$key] : $default;
    }

    /** @return array */
    protected static function rows($array, $key) {
        $rows = self::pick($array, $key, array());

        return is_array($rows) ? array_values($rows) : array();
    }

    /* ----------------------------------------------------------------- panels */

    protected function panel_hero($post_id) {
        $hero = $this->value($post_id, WTA_Itinerary_Schema::HERO);

        $this->section('Headline block', 'The eyebrow, the split headline, the standfirst and the stat strip.');

        $this->field(array(
            'name'  => 'wta[hero][eyebrow]',
            'label' => 'Eyebrow',
            'value' => self::pick($hero, 'eyebrow'),
            'hint'  => 'Short line above the headline.',
        ));

        $this->field(array(
            'type'  => 'textarea',
            'name'  => 'wta[hero][standfirst]',
            'label' => 'Standfirst',
            'value' => self::pick($hero, 'standfirst'),
            'hint'  => 'Opening paragraph. Basic HTML is kept.',
        ));

        $this->subsection('Headline lines', 'One entry per rendered line. The accent colours that line only.');

        $this->repeater('wta[hero][headline]', self::rows($hero, 'headline'), 'Add headline line', function ($row, $prefix) {
            $this->field(array(
                'name'  => $prefix . '[text]',
                'key'   => '[text]',
                'label' => 'Text',
                'value' => self::pick($row, 'text'),
            ));

            $this->field(array(
                'type'    => 'select',
                'name'    => $prefix . '[accent]',
                'key'     => '[accent]',
                'label'   => 'Accent',
                'value'   => self::pick($row, 'accent'),
                'options' => array('' => 'None', 'warm' => 'Warm', 'cool' => 'Cool'),
            ));
        });

        $this->subsection('Stats', 'The short strip under the standfirst.');

        $this->repeater('wta[hero][stats]', self::rows($hero, 'stats'), 'Add stat', function ($row, $prefix) {
            $this->field(array(
                'name'  => $prefix . '[value]',
                'key'   => '[value]',
                'label' => 'Value',
                'value' => self::pick($row, 'value'),
            ));

            $this->field(array(
                'name'  => $prefix . '[label]',
                'key'   => '[label]',
                'label' => 'Label',
                'value' => self::pick($row, 'label'),
            ));
        });
    }

    /**
     * Day choices for a leg, labelled the way the operator sees them.
     *
     * A leg stores an index into WP Travel's itinerary array, but that index is
     * not the day number. An entry labelled "Day 8 & 9" occupies one slot and
     * covers two days, so from that point on the index trails the day number.
     * Asking anyone to work that out by hand invites an off-by-two that renders
     * as the wrong country heading over the wrong days, with nothing to warn
     * them. Presenting the real labels removes the arithmetic entirely.
     *
     * @return array<int, string> index => label
     */
    protected function day_choices($post_id) {
        $days = get_post_meta($post_id, WTA_Trip::ITINERARY_META, true);

        if (!is_array($days) || empty($days)) {
            return array();
        }

        $out = array();

        foreach (array_values($days) as $i => $day) {
            $label = isset($day['label']) ? wp_specialchars_decode((string) $day['label'], ENT_QUOTES) : '';
            $title = isset($day['title']) ? wp_specialchars_decode((string) $day['title'], ENT_QUOTES) : '';
            $label = trim($label) !== '' ? trim($label) : sprintf('Entry %d', $i + 1);
            $title = trim($title);

            $out[$i] = $title !== '' ? $label . ' - ' . $title : $label;
        }

        return $out;
    }

    protected function panel_legs($post_id) {
        $legs    = $this->value($post_id, WTA_Itinerary_Schema::LEGS);
        $choices = $this->day_choices($post_id);
        $days    = count($choices);

        $hint = $days
            ? sprintf('This trip has %d entr%s in the WP Travel itinerary.', $days, 1 === $days ? 'y' : 'ies')
            : 'This trip has no WP Travel itinerary days yet, so there is nothing for a leg to cover.';

        // A leg pointing past the end of the day list renders the wrong days, or
        // none. Say so here rather than letting it fail quietly on the front end.
        $stale = array();

        foreach ($legs as $i => $leg) {
            $from = isset($leg['day_from']) ? (int) $leg['day_from'] : 0;
            $to   = isset($leg['day_to']) ? (int) $leg['day_to'] : 0;

            if ($days === 0 || $from > $days - 1 || $to > $days - 1 || $from < 0 || $to < $from) {
                $title   = isset($leg['title']) && '' !== $leg['title'] ? $leg['title'] : 'Leg ' . ($i + 1);
                $stale[] = sprintf('%s (%d to %d)', $title, $from, $to);
            }
        }

        // section() escapes its note, so this stays plain text by design.
        if ($stale) {
            $hint .= ' Warning: these legs no longer match the itinerary and will render incorrectly - '
                . implode('; ', $stale) . '. Days were most likely added, removed or reordered in'
                . ' WP Travel after the legs were set.';
        }

        $this->section(
            'Stages of the journey',
            $hint . ' Days are referenced, never copied - editing a day in WP Travel updates it here too.'
        );

        $this->repeater('wta[legs]', array_values($legs), 'Add leg', function ($row, $prefix) use ($choices) {
            $this->field(array(
                'name'  => $prefix . '[title]',
                'key'   => '[title]',
                'label' => 'Title',
                'value' => self::pick($row, 'title'),
            ));

            $this->field(array(
                'name'  => $prefix . '[subtitle]',
                'key'   => '[subtitle]',
                'label' => 'Subtitle',
                'value' => self::pick($row, 'subtitle'),
            ));

            $this->field(array(
                'type'    => 'select',
                'name'    => $prefix . '[accent]',
                'key'     => '[accent]',
                'label'   => 'Accent',
                'value'   => self::pick($row, 'accent', 'forest'),
                'options' => array('forest' => 'Forest', 'plain' => 'Plain', 'ocean' => 'Ocean'),
            ));

            $this->field(array(
                'type'    => $choices ? 'select' : 'number',
                'name'    => $prefix . '[day_from]',
                'key'     => '[day_from]',
                'label'   => 'First day',
                'value'   => self::pick($row, 'day_from', 0),
                'options' => $choices,
                'min'     => '0',
                'step'    => '1',
            ));

            $this->field(array(
                'type'    => $choices ? 'select' : 'number',
                'name'    => $prefix . '[day_to]',
                'key'     => '[day_to]',
                'label'   => 'Last day',
                'value'   => self::pick($row, 'day_to', 0),
                'options' => $choices,
                'min'     => '0',
                'step'    => '1',
            ));
        });
    }

    protected function panel_route($post_id) {
        $route = $this->value($post_id, WTA_Itinerary_Schema::ROUTE);

        $this->section(
            'Stops, in order',
            'X and Y are percentages of the stylised map viewport, not latitude and longitude. '
                . 'Leg is the index of the leg this stop belongs to, counting from 0.'
        );

        $this->repeater('wta[route]', array_values($route), 'Add stop', function ($row, $prefix) {
            $this->field(array(
                'name'  => $prefix . '[name]',
                'key'   => '[name]',
                'label' => 'Name',
                'value' => self::pick($row, 'name'),
            ));

            $this->field(array(
                'name'  => $prefix . '[subtitle]',
                'key'   => '[subtitle]',
                'label' => 'Subtitle',
                'value' => self::pick($row, 'subtitle'),
            ));

            $this->field(array(
                'type'  => 'number',
                'name'  => $prefix . '[nights]',
                'key'   => '[nights]',
                'label' => 'Nights',
                'value' => self::pick($row, 'nights', 0),
                'min'   => '0',
                'step'  => '1',
            ));

            $this->field(array(
                'type'  => 'number',
                'name'  => $prefix . '[leg]',
                'key'   => '[leg]',
                'label' => 'Leg index',
                'value' => self::pick($row, 'leg', 0),
                'min'   => '0',
                'step'  => '1',
            ));

            $this->field(array(
                'type'  => 'number',
                'name'  => $prefix . '[x]',
                'key'   => '[x]',
                'label' => 'X (0-100)',
                'value' => self::pick($row, 'x', ''),
                'min'   => '0',
                'max'   => '100',
                'step'  => 'any',
            ));

            $this->field(array(
                'type'  => 'number',
                'name'  => $prefix . '[y]',
                'key'   => '[y]',
                'label' => 'Y (0-100)',
                'value' => self::pick($row, 'y', ''),
                'min'   => '0',
                'max'   => '100',
                'step'  => 'any',
            ));

            $this->field(array(
                'type'    => 'select',
                'name'    => $prefix . '[arrive]',
                'key'     => '[arrive]',
                'label'   => 'Arrive by',
                'value'   => self::pick($row, 'arrive', 'drive'),
                'options' => array('start' => 'Start', 'fly' => 'Fly', 'drive' => 'Drive'),
            ));
        });
    }

    protected function panel_seasonality($post_id) {
        $season = $this->value($post_id, WTA_Itinerary_Schema::SEASONALITY);
        $rows   = self::rows($season, 'rows');
        $months = self::rows($season, 'months');

        $this->section(
            'What is being scored',
            'Each row is one thing the months are judged on. The key is used in the score grid below '
                . 'and by the front end, so keep it short and stable.'
        );

        $this->repeater('wta[seasonality][rows]', $rows, 'Add row', function ($row, $prefix) {
            $this->field(array(
                'name'  => $prefix . '[key]',
                'key'   => '[key]',
                'label' => 'Key',
                'value' => self::pick($row, 'key'),
                'hint'  => 'Lowercase, no spaces.',
            ));

            $this->field(array(
                'name'  => $prefix . '[label]',
                'key'   => '[label]',
                'label' => 'Label',
                'value' => self::pick($row, 'label'),
            ));

            $this->field(array(
                'type'    => 'select',
                'name'    => $prefix . '[accent]',
                'key'     => '[accent]',
                'label'   => 'Accent',
                'value'   => self::pick($row, 'accent', 'forest'),
                'options' => array('forest' => 'Forest', 'plain' => 'Plain', 'ocean' => 'Ocean'),
            ));
        });

        $this->subsection(
            'Month by month',
            $rows
                ? 'Scores run 0 (avoid) to 3 (ideal). Tags are comma separated, four at most.'
                : 'Add at least one row above and save; the score columns are built from the saved rows.'
        );
        ?>
        <div class="wta-months-scroll">
            <div class="wta-months">
                <?php foreach (self::months() as $i => $month) : ?>
                    <?php $data = isset($months[$i]) && is_array($months[$i]) ? $months[$i] : array(); ?>
                    <?php $scores = self::pick($data, 'scores', array()); ?>
                    <?php $tags = self::pick($data, 'tags', array()); ?>
                    <div class="wta-month">
                        <h5 class="wta-month-name"><?php echo esc_html($month); ?></h5>

                        <?php foreach ($rows as $row) : ?>
                            <?php $row_key = self::pick($row, 'key'); ?>
                            <?php if ('' === $row_key) { continue; } ?>
                            <?php
                            $this->field(array(
                                'type'    => 'select',
                                'name'    => 'wta[seasonality][months][' . $i . '][scores][' . $row_key . ']',
                                'label'   => '' !== self::pick($row, 'label') ? self::pick($row, 'label') : $row_key,
                                'value'   => (string) (int) self::pick($scores, $row_key, 0),
                                'options' => array('0' => '0', '1' => '1', '2' => '2', '3' => '3'),
                                'class'   => 'is-score',
                            ));
                            ?>
                        <?php endforeach; ?>

                        <?php
                        $this->field(array(
                            'type'    => 'select',
                            'name'    => 'wta[seasonality][months][' . $i . '][rank]',
                            'label'   => 'Rank',
                            'value'   => self::pick($data, 'rank'),
                            'options' => array('' => 'None', 'primary' => 'Primary', 'alternative' => 'Alternative'),
                        ));

                        $this->field(array(
                            'name'  => 'wta[seasonality][months][' . $i . '][tags]',
                            'label' => 'Tags',
                            'value' => is_array($tags) ? implode(', ', $tags) : (string) $tags,
                            'hint'  => 'Comma separated.',
                        ));

                        $this->field(array(
                            'type'  => 'textarea',
                            'name'  => 'wta[seasonality][months][' . $i . '][verdict]',
                            'label' => 'Verdict',
                            'value' => self::pick($data, 'verdict'),
                            'rows'  => 2,
                        ));
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    protected function panel_options($post_id) {
        $options = $this->value($post_id, WTA_Itinerary_Schema::OPTIONS);

        $this->section('A choice inside the trip', 'Which coast, which lodge style, which add-on week.');

        $this->field(array(
            'name'  => 'wta[options][title]',
            'label' => 'Title',
            'value' => self::pick($options, 'title'),
        ));

        $this->repeater('wta[options][items]', self::rows($options, 'items'), 'Add choice', function ($row, $prefix) {
            $this->field(array(
                'name'  => $prefix . '[name]',
                'key'   => '[name]',
                'label' => 'Name',
                'value' => self::pick($row, 'name'),
            ));

            $this->field(array(
                'name'  => $prefix . '[subtitle]',
                'key'   => '[subtitle]',
                'label' => 'Subtitle',
                'value' => self::pick($row, 'subtitle'),
            ));

            $this->field(array(
                'type'  => 'textarea',
                'name'  => $prefix . '[body]',
                'key'   => '[body]',
                'label' => 'Body',
                'value' => self::pick($row, 'body'),
                'class' => 'is-wide',
            ));
        });
    }

    protected function panel_checklist($post_id) {
        $this->section('What to book, in the order it must be booked', 'Permits first, then flights, then the rest.');

        $this->pairs('wta[checklist]', $this->value($post_id, WTA_Itinerary_Schema::CHECKLIST), 'Add step');
    }

    protected function panel_notes($post_id) {
        $this->section('Practical warnings', 'Visas, vaccinations, luggage limits, cash.');

        $this->pairs('wta[notes]', $this->value($post_id, WTA_Itinerary_Schema::NOTES), 'Add note');
    }

    /** Heading and body repeater, shared by the two sanitize_pairs groups. */
    protected function pairs($base, $rows, $add_label) {
        $this->repeater($base, array_values($rows), $add_label, function ($row, $prefix) {
            $this->field(array(
                'name'  => $prefix . '[heading]',
                'key'   => '[heading]',
                'label' => 'Heading',
                'value' => self::pick($row, 'heading'),
            ));

            $this->field(array(
                'type'  => 'textarea',
                'name'  => $prefix . '[body]',
                'key'   => '[body]',
                'label' => 'Body',
                'value' => self::pick($row, 'body'),
                'class' => 'is-wide',
            ));
        });
    }

    /* ---------------------------------------------------------------- markup */

    protected function section($title, $note = '') {
        ?>
        <h4 class="wta-section"><?php echo esc_html($title); ?></h4>
        <?php if ('' !== $note) : ?>
            <p class="wta-note"><?php echo esc_html($note); ?></p>
        <?php endif; ?>
        <?php
    }

    protected function subsection($title, $note = '') {
        ?>
        <h5 class="wta-subsection"><?php echo esc_html($title); ?></h5>
        <?php if ('' !== $note) : ?>
            <p class="wta-note"><?php echo esc_html($note); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * A repeatable list of rows plus the template the JS clones.
     *
     * The template's own field names carry a placeholder index; template content
     * is never submitted, and the JS renumbers every name after a clone, so the
     * placeholder never reaches $_POST.
     *
     * @param string   $base      Full name path, e.g. wta[hero][stats].
     * @param array    $rows      Stored rows.
     * @param string   $add_label Button text.
     * @param callable $callback  Renders one row: ($row, $prefix).
     * @param string   $sub       Relative path when nested inside another row.
     */
    protected function repeater($base, $rows, $add_label, $callback, $sub = '') {
        ?>
        <div class="wta-rep"
             data-wta-repeater
             <?php if ('' !== $sub) : ?>
                 data-wta-sub="<?php echo esc_attr($sub); ?>"
             <?php else : ?>
                 data-wta-base="<?php echo esc_attr($base); ?>"
             <?php endif; ?>>
            <div class="wta-rep-rows" data-wta-rows>
                <?php foreach (array_values($rows) as $i => $row) : ?>
                    <?php $this->row($callback, is_array($row) ? $row : array(), $base . '[' . $i . ']'); ?>
                <?php endforeach; ?>
            </div>

            <template data-wta-template><?php $this->row($callback, array(), $base . '[__i__]'); ?></template>

            <button type="button" class="wta-btn wta-add" data-wta-add>
                <?php echo esc_html($add_label); ?>
            </button>
        </div>
        <?php
    }

    protected function row($callback, $row, $prefix) {
        ?>
        <div class="wta-rep-row" data-wta-row>
            <div class="wta-rep-fields">
                <?php call_user_func($callback, $row, $prefix); ?>
            </div>
            <button type="button" class="wta-btn wta-remove" data-wta-remove>Remove</button>
        </div>
        <?php
    }

    /**
     * One labelled control.
     *
     * `key` is the row-relative name fragment; the JS uses it to rebuild the
     * full name after a row index changes. Fields outside a repeater omit it.
     */
    protected function field(array $args) {
        $args = array_merge(array(
            'type'    => 'text',
            'name'    => '',
            'key'     => '',
            'label'   => '',
            'value'   => '',
            'hint'    => '',
            'options' => array(),
            'min'     => '',
            'max'     => '',
            'step'    => '',
            'rows'    => 3,
            'class'   => '',
        ), $args);

        $value = is_scalar($args['value']) ? (string) $args['value'] : '';
        $attrs = '';

        if ('' !== $args['key']) {
            $attrs .= ' data-wta-name="' . esc_attr($args['key']) . '"';
        }

        foreach (array('min', 'max', 'step') as $numeric) {
            if ('' !== $args[$numeric]) {
                $attrs .= ' ' . $numeric . '="' . esc_attr($args[$numeric]) . '"';
            }
        }
        ?>
        <label class="wta-field <?php echo esc_attr($args['class']); ?>">
            <span class="wta-field-label"><?php echo esc_html($args['label']); ?></span>

            <?php if ('textarea' === $args['type']) : ?>
                <textarea class="wta-input"
                          name="<?php echo esc_attr($args['name']); ?>"
                          rows="<?php echo esc_attr((string) $args['rows']); ?>"
                          <?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?>><?php echo esc_textarea($value); ?></textarea>

            <?php elseif ('select' === $args['type']) : ?>
                <select class="wta-input"
                        name="<?php echo esc_attr($args['name']); ?>"
                        <?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?>>
                    <?php foreach ($args['options'] as $option => $label) : ?>
                        <option value="<?php echo esc_attr((string) $option); ?>" <?php selected((string) $option, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php else : ?>
                <input class="wta-input"
                       type="<?php echo esc_attr($args['type']); ?>"
                       name="<?php echo esc_attr($args['name']); ?>"
                       value="<?php echo esc_attr($value); ?>"
                       <?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?> />
            <?php endif; ?>

            <?php if ('' !== $args['hint']) : ?>
                <span class="wta-field-hint"><?php echo esc_html($args['hint']); ?></span>
            <?php endif; ?>
        </label>
        <?php
    }
}
