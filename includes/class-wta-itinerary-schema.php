<?php
/**
 * The trip data WP Travel does not store.
 *
 * WP Travel covers title, duration, price, inclusions and a flat list of days.
 * A richly presented itinerary needs more than that: which days belong to which
 * leg of the journey, how the trip scores month by month, where the stops sit
 * on a map, what the traveller has to choose between, and what it costs at
 * different comfort levels.
 *
 * Each structure below is stored as a single post meta entry holding an array,
 * and every one is exposed as a typed REST field so the whole itinerary can be
 * authored by an API client rather than by hand.
 *
 * Nothing here duplicates WP Travel. Days still live in
 * wp_travel_trip_itinerary_data; legs reference them by index rather than
 * copying them, so there is exactly one source of truth for day content.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Itinerary_Schema {

    /** Meta keys. Prefixed and not protected, so they are editable over REST. */
    const HERO        = 'wta_hero';
    const LEGS        = 'wta_legs';
    const ROUTE       = 'wta_route';
    const SEASONALITY = 'wta_seasonality';
    const OPTIONS     = 'wta_options';
    const COST        = 'wta_cost';
    const CHECKLIST   = 'wta_checklist';
    const NOTES       = 'wta_notes';

    public function __construct() {
        add_action('init', array($this, 'register_meta'), 20);
        add_action('rest_api_init', array($this, 'register_rest_fields'));
    }

    /**
     * Every field, with its REST schema and the callback that cleans it.
     *
     * @return array<string, array>
     */
    public static function fields() {
        return array(
            self::HERO => array(
                'rest_key'    => 'hero',
                'description' => 'Headline block: eyebrow, split headline, standfirst and the stat strip.',
                'sanitize'    => 'sanitize_hero',
                'schema'      => array(
                    'type'       => 'object',
                    'properties' => array(
                        'eyebrow'  => array('type' => 'string'),
                        'headline' => array(
                            'type'        => 'array',
                            'description' => 'Headline rendered one line per entry; the accent flag colours that line.',
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'text'   => array('type' => 'string'),
                                    'accent' => array('type' => 'string', 'enum' => array('', 'warm', 'cool')),
                                ),
                            ),
                        ),
                        'standfirst' => array('type' => 'string'),
                        'stats'      => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'value' => array('type' => 'string'),
                                    'label' => array('type' => 'string'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),

            self::LEGS => array(
                'rest_key'    => 'legs',
                'description' => 'Stages of the journey. Days are referenced by their index in the WP Travel itinerary, never copied.',
                'sanitize'    => 'sanitize_legs',
                'schema'      => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'title'    => array('type' => 'string'),
                            'subtitle' => array('type' => 'string'),
                            'accent'   => array('type' => 'string', 'enum' => array('forest', 'plain', 'ocean')),
                            'day_from' => array('type' => 'integer', 'description' => 'First day index, zero-based, into itinerary_days.'),
                            'day_to'   => array('type' => 'integer', 'description' => 'Last day index, inclusive.'),
                            'term_id'  => array('type' => 'integer', 'description' => 'travel_locations term this leg covers, so the heading links through to the destination. Left at 0, it is resolved by matching the leg title against the trip\'s own destinations.'),
                        ),
                    ),
                ),
            ),

            self::ROUTE => array(
                'rest_key'    => 'route',
                'description' => 'Ordered stops. Positions come from real latitude and longitude, the same coordinates WP Travel stores for the trip map, and are projected into the map viewport automatically. x/y may be set to override a projected position by hand.',
                'sanitize'    => 'sanitize_route',
                'schema'      => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'name'     => array('type' => 'string'),
                            'subtitle' => array('type' => 'string'),
                            'nights'   => array('type' => 'integer'),
                            'leg'      => array('type' => 'integer', 'description' => 'Index into legs.'),
                            // Null is a real, meaningful value here, not an
                            // absence: an unset x/y is what tells the renderer
                            // to derive the position from lat/lng. Declaring
                            // these as plain numbers made REST reject every
                            // route that had not been positioned by hand.
                            'lat'      => array('type' => array('number', 'null'), 'description' => 'Latitude. The same coordinate system WP Travel already uses for the trip map.'),
                            'lng'      => array('type' => array('number', 'null'), 'description' => 'Longitude.'),
                            'x'        => array('type' => array('number', 'null'), 'description' => 'Optional manual horizontal position, 0-100. Leave null to derive from lat/lng.'),
                            'y'        => array('type' => array('number', 'null'), 'description' => 'Optional manual vertical position, 0-100. Leave null to derive from lat/lng.'),
                            'arrive'   => array('type' => 'string', 'enum' => array('fly', 'drive', 'start'), 'description' => 'How the traveller reaches this stop from the previous one.'),
                        ),
                    ),
                ),
            ),

            self::SEASONALITY => array(
                'rest_key'    => 'seasonality',
                'description' => 'Month-by-month suitability. Rows are the things being scored; each month scores every row 0-3 and carries its own verdict.',
                'sanitize'    => 'sanitize_seasonality',
                'schema'      => array(
                    'type'       => 'object',
                    'properties' => array(
                        'rows' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'key'    => array('type' => 'string'),
                                    'label'  => array('type' => 'string'),
                                    'accent' => array('type' => 'string', 'enum' => array('forest', 'plain', 'ocean')),
                                ),
                            ),
                        ),
                        'months' => array(
                            'type'        => 'array',
                            'description' => 'Exactly twelve entries, January first.',
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'scores'  => array(
                                        'type'                 => 'object',
                                        'description'          => 'Row key => 0 (avoid) to 3 (ideal).',
                                        // The keys are defined by `rows`, not
                                        // fixed here, so they must be allowed
                                        // explicitly rather than left implicit.
                                        'additionalProperties' => array('type' => 'integer'),
                                    ),
                                    'rank'    => array('type' => 'string', 'enum' => array('', 'primary', 'alternative')),
                                    'tags'    => array('type' => 'array', 'items' => array('type' => 'string')),
                                    'verdict' => array('type' => 'string'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),

            self::OPTIONS => array(
                'rest_key'    => 'options',
                'description' => 'A choice the traveller makes within the trip, such as which coast or which lodge style.',
                'sanitize'    => 'sanitize_options',
                'schema'      => array(
                    'type'       => 'object',
                    'properties' => array(
                        'title' => array('type' => 'string'),
                        'items' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'name'     => array('type' => 'string'),
                                    'subtitle' => array('type' => 'string'),
                                    'body'     => array('type' => 'string'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),

            self::COST => array(
                'rest_key'    => 'cost',
                'description' => 'Inputs for the cost estimator. Every figure is per person unless the label says otherwise.',
                'sanitize'    => 'sanitize_cost',
                'schema'      => array(
                    'type'       => 'object',
                    'properties' => array(
                        'currency' => array('type' => 'string'),
                        'note'     => array('type' => 'string'),
                        'basis'    => array(
                            'type'        => 'string',
                            'enum'        => array('total', 'per_night'),
                            'description' => 'Whether tier figures are a whole-trip total or a per-person-per-night rate. Per-night is what lets one rate cover a catalogue: the night count comes from WP Travel\'s own duration rather than being retyped per trip.',
                        ),
                        'tiers'    => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'name'    => array('type' => 'string'),
                                    'land'    => array('type' => 'number'),
                                    'flights' => array('type' => 'number'),
                                ),
                            ),
                        ),
                        'fees'      => array('type' => 'number', 'description' => 'Park and conservation fees.'),
                        'buffer_percent' => array(
                            'type'        => 'number',
                            'description' => 'Margin added on top of the derived subtotal. Operator cost is close to the true cost, so a published figure needs headroom. Never applied to a hand-entered total.',
                        ),
                        'estimate'  => array(
                            'type'        => 'boolean',
                            'description' => 'Present the figure as an indicative estimate rather than a price. True for everything that is still quoted individually, which is most of the catalogue.',
                        ),
                        'seasons'   => array(
                            'type'        => 'array',
                            'description' => 'Seasonal bands. African travel is priced by season, so one number per trip cannot be right all year; the multiplier moves the land cost between peak, shoulder and green.',
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'key'        => array('type' => 'string'),
                                    'label'      => array('type' => 'string'),
                                    'multiplier' => array('type' => 'number', 'description' => 'Applied to the land base only. Flights and park fees do not move with the season.'),
                                    'months'     => array(
                                        'type'        => 'array',
                                        'description' => 'Calendar months this band covers, 1-12. A month claimed by no band is simply priced at multiplier 1.',
                                        'items'       => array('type' => 'integer'),
                                    ),
                                ),
                            ),
                        ),
                        'addons'    => array(
                            'type'        => 'array',
                            'description' => 'Optional priced choices such as permits, rendered as a segmented control.',
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'label'   => array('type' => 'string'),
                                    'choices' => array(
                                        'type'  => 'array',
                                        'items' => array(
                                            'type'       => 'object',
                                            'properties' => array(
                                                'name'  => array('type' => 'string'),
                                                'price' => array('type' => 'number'),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),

            self::CHECKLIST => array(
                'rest_key'    => 'checklist',
                'description' => 'What to book, in the order it must be booked.',
                'sanitize'    => 'sanitize_pairs',
                'schema'      => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'heading' => array('type' => 'string'),
                            'body'    => array('type' => 'string'),
                        ),
                    ),
                ),
            ),

            self::NOTES => array(
                'rest_key'    => 'notes',
                'description' => 'Practical warnings: visas, vaccinations, luggage limits.',
                'sanitize'    => 'sanitize_pairs',
                'schema'      => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'heading' => array('type' => 'string'),
                            'body'    => array('type' => 'string'),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Registered so the values are real post meta, but kept out of the `meta`
     * object: these are arrays, and a REST field gives them a typed schema and
     * a sanitiser instead of a serialised blob.
     */
    public function register_meta() {
        if (!WTA_Trip::is_available()) {
            return;
        }

        foreach (self::fields() as $meta_key => $field) {
            // The typed schema is attached here as well as to the REST field.
            // Registering with show_in_rest puts each value inside the response's
            // `meta` object, which is the only surface generic tooling can write
            // to: wp-admin custom fields, ACF, and any client whose write path is
            // a `meta` parameter. Without it these fields are readable but not
            // writable by anything except a bespoke request, which is how 83 of
            // 84 trips ended up empty.
            //
            // The storage key is unchanged, so this is an additional view of the
            // same data rather than a second place to put it.
            register_post_meta(WTA_Trip::post_type(), $meta_key, array(
                // 'object' for hero/seasonality/cost/options, 'array' for the
                // rest. Declaring them all as 'array' would make REST reject a
                // perfectly valid object on write.
                'type'              => isset($field['schema']['type']) ? $field['schema']['type'] : 'object',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array_merge(
                        array('description' => $field['description']),
                        $field['schema']
                    ),
                ),
                'sanitize_callback' => array(__CLASS__, 'sanitize_meta_value'),
                'auth_callback'     => function ($allowed, $key, $post_id) {
                    return current_user_can('edit_post', $post_id);
                },
            ));
        }
    }

    /**
     * Sanitiser for the meta write path.
     *
     * register_post_meta hands us the key, so the same per-field sanitisers the
     * REST field already uses can be reached from one callback. They are
     * structure-preserving by design: nested arrays survive, strings are
     * cleaned, and HTML-bearing fields such as a note body keep their markup
     * through wp_kses_post rather than being flattened.
     *
     * @param mixed  $value
     * @param string $meta_key
     * @return mixed
     */
    public static function sanitize_meta_value($value, $meta_key = '') {
        $fields = self::fields();

        if (!isset($fields[$meta_key]['sanitize'])) {
            return $value;
        }

        $clean = call_user_func(array(__CLASS__, $fields[$meta_key]['sanitize']), $value);

        // A sanitiser may reject outright. Meta has no error channel, so fall
        // back to storing nothing rather than storing the rejected input.
        return is_wp_error($clean) ? array() : $clean;
    }

    public function register_rest_fields() {
        if (!WTA_Trip::is_available()) {
            return;
        }

        foreach (self::fields() as $meta_key => $field) {
            register_rest_field(WTA_Trip::post_type(), $field['rest_key'], array(
                'get_callback'    => function ($post) use ($meta_key) {
                    $value = get_post_meta($post['id'], $meta_key, true);

                    return '' === $value ? null : $value;
                },
                'update_callback' => function ($value, $post) use ($meta_key, $field) {
                    if (!current_user_can('edit_post', $post->ID)) {
                        return new WP_Error('wta_forbidden', 'You are not allowed to edit this trip.', array('status' => 403));
                    }

                    $clean = call_user_func(array(__CLASS__, $field['sanitize']), $value);

                    if (is_wp_error($clean)) {
                        return $clean;
                    }

                    update_post_meta($post->ID, $meta_key, $clean);

                    return true;
                },
                'schema'          => array_merge(
                    array('description' => $field['description'], 'context' => array('view', 'edit')),
                    $field['schema']
                ),
            ));
        }
    }

    /* ---------------------------------------------------------- sanitisers */

    protected static function text($v) {
        return sanitize_text_field((string) $v);
    }

    protected static function html($v) {
        return wp_kses_post((string) $v);
    }

    public static function sanitize_hero($value) {
        if (!is_array($value)) {
            return array();
        }

        $out = array(
            'eyebrow'    => isset($value['eyebrow']) ? self::text($value['eyebrow']) : '',
            'standfirst' => isset($value['standfirst']) ? self::html($value['standfirst']) : '',
            'headline'   => array(),
            'stats'      => array(),
        );

        if (!empty($value['headline']) && is_array($value['headline'])) {
            foreach ($value['headline'] as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $accent = isset($line['accent']) ? sanitize_key($line['accent']) : '';

                $out['headline'][] = array(
                    'text'   => self::text(isset($line['text']) ? $line['text'] : ''),
                    'accent' => in_array($accent, array('warm', 'cool'), true) ? $accent : '',
                );
            }
        }

        if (!empty($value['stats']) && is_array($value['stats'])) {
            foreach ($value['stats'] as $stat) {
                if (!is_array($stat)) {
                    continue;
                }

                $out['stats'][] = array(
                    'value' => self::text(isset($stat['value']) ? $stat['value'] : ''),
                    'label' => self::text(isset($stat['label']) ? $stat['label'] : ''),
                );
            }
        }

        return $out;
    }

    public static function sanitize_legs($value) {
        if (!is_array($value)) {
            return array();
        }

        $out = array();

        foreach ($value as $leg) {
            if (!is_array($leg)) {
                continue;
            }

            $accent = isset($leg['accent']) ? sanitize_key($leg['accent']) : 'forest';

            $from = isset($leg['day_from']) ? max(0, (int) $leg['day_from']) : 0;
            $to   = isset($leg['day_to']) ? max(0, (int) $leg['day_to']) : $from;

            $out[] = array(
                'title'    => self::text(isset($leg['title']) ? $leg['title'] : ''),
                'subtitle' => self::text(isset($leg['subtitle']) ? $leg['subtitle'] : ''),
                'accent'   => in_array($accent, array('forest', 'plain', 'ocean'), true) ? $accent : 'forest',
                'day_from' => $from,
                // A leg that ends before it starts would silently render nothing.
                'day_to'   => max($from, $to),
                'term_id'  => isset($leg['term_id']) ? max(0, (int) $leg['term_id']) : 0,
            );
        }

        return $out;
    }

    public static function sanitize_route($value) {
        if (!is_array($value)) {
            return array();
        }

        $out = array();

        foreach ($value as $stop) {
            if (!is_array($stop)) {
                continue;
            }

            $arrive = isset($stop['arrive']) ? sanitize_key($stop['arrive']) : 'drive';

            $has_lat = isset($stop['lat']) && '' !== $stop['lat'] && null !== $stop['lat'];
            $has_lng = isset($stop['lng']) && '' !== $stop['lng'] && null !== $stop['lng'];

            $out[] = array(
                'name'     => self::text(isset($stop['name']) ? $stop['name'] : ''),
                'subtitle' => self::text(isset($stop['subtitle']) ? $stop['subtitle'] : ''),
                'nights'   => isset($stop['nights']) ? max(0, (int) $stop['nights']) : 0,
                'leg'      => isset($stop['leg']) ? max(0, (int) $stop['leg']) : 0,
                // Real coordinates, clamped to valid ranges. Null means this stop
                // has none and will be skipped by the projection rather than
                // silently plotted at (0,0) off the coast of Africa.
                'lat'      => $has_lat ? min(90, max(-90, (float) $stop['lat'])) : null,
                'lng'      => $has_lng ? min(180, max(-180, (float) $stop['lng'])) : null,
                // Manual override. Null, not 50, so "unset" is distinguishable
                // from "deliberately centred".
                'x'        => (isset($stop['x']) && '' !== $stop['x'] && null !== $stop['x']) ? min(100, max(0, (float) $stop['x'])) : null,
                'y'        => (isset($stop['y']) && '' !== $stop['y'] && null !== $stop['y']) ? min(100, max(0, (float) $stop['y'])) : null,
                'arrive'   => in_array($arrive, array('fly', 'drive', 'start'), true) ? $arrive : 'drive',
            );
        }

        return $out;
    }

    public static function sanitize_seasonality($value) {
        if (!is_array($value)) {
            return array();
        }

        $rows = array();
        $keys = array();

        if (!empty($value['rows']) && is_array($value['rows'])) {
            foreach ($value['rows'] as $row) {
                if (!is_array($row) || empty($row['key'])) {
                    continue;
                }

                $key    = sanitize_key($row['key']);
                $accent = isset($row['accent']) ? sanitize_key($row['accent']) : 'forest';
                $keys[] = $key;

                $rows[] = array(
                    'key'    => $key,
                    'label'  => self::text(isset($row['label']) ? $row['label'] : ''),
                    'accent' => in_array($accent, array('forest', 'plain', 'ocean'), true) ? $accent : 'forest',
                );
            }
        }

        $months = array();
        $given  = (!empty($value['months']) && is_array($value['months'])) ? array_values($value['months']) : array();

        // Always twelve, so the renderer never has to guard against a short
        // array and the matrix cannot come out ragged.
        for ($i = 0; $i < 12; $i++) {
            $month  = isset($given[$i]) && is_array($given[$i]) ? $given[$i] : array();
            $scores = array();

            foreach ($keys as $key) {
                $raw = isset($month['scores'][$key]) ? (int) $month['scores'][$key] : 0;
                $scores[$key] = min(3, max(0, $raw));
            }

            $rank = isset($month['rank']) ? sanitize_key($month['rank']) : '';
            $tags = array();

            if (!empty($month['tags']) && is_array($month['tags'])) {
                foreach (array_slice($month['tags'], 0, 4) as $tag) {
                    $tags[] = self::text($tag);
                }
            }

            $months[] = array(
                'scores'  => $scores,
                'rank'    => in_array($rank, array('primary', 'alternative'), true) ? $rank : '',
                'tags'    => $tags,
                'verdict' => isset($month['verdict']) ? self::html($month['verdict']) : '',
            );
        }

        return array('rows' => $rows, 'months' => $months);
    }

    public static function sanitize_options($value) {
        if (!is_array($value)) {
            return array();
        }

        $items = array();

        if (!empty($value['items']) && is_array($value['items'])) {
            foreach ($value['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $items[] = array(
                    'name'     => self::text(isset($item['name']) ? $item['name'] : ''),
                    'subtitle' => self::text(isset($item['subtitle']) ? $item['subtitle'] : ''),
                    'body'     => self::html(isset($item['body']) ? $item['body'] : ''),
                );
            }
        }

        return array(
            'title' => isset($value['title']) ? self::text($value['title']) : '',
            'items' => $items,
        );
    }

    public static function sanitize_cost($value) {
        if (!is_array($value)) {
            return array();
        }

        $tiers = array();

        if (!empty($value['tiers']) && is_array($value['tiers'])) {
            foreach ($value['tiers'] as $tier) {
                if (!is_array($tier)) {
                    continue;
                }

                $tiers[] = array(
                    'name'    => self::text(isset($tier['name']) ? $tier['name'] : ''),
                    'land'    => isset($tier['land']) ? max(0, (float) $tier['land']) : 0,
                    'flights' => isset($tier['flights']) ? max(0, (float) $tier['flights']) : 0,
                );
            }
        }

        $addons = array();

        if (!empty($value['addons']) && is_array($value['addons'])) {
            foreach ($value['addons'] as $addon) {
                if (!is_array($addon)) {
                    continue;
                }

                $choices = array();

                if (!empty($addon['choices']) && is_array($addon['choices'])) {
                    foreach ($addon['choices'] as $choice) {
                        if (!is_array($choice)) {
                            continue;
                        }

                        $choices[] = array(
                            'name'  => self::text(isset($choice['name']) ? $choice['name'] : ''),
                            'price' => isset($choice['price']) ? max(0, (float) $choice['price']) : 0,
                        );
                    }
                }

                $addons[] = array(
                    'label'   => self::text(isset($addon['label']) ? $addon['label'] : ''),
                    'choices' => $choices,
                );
            }
        }

        $seasons = array();

        if (!empty($value['seasons']) && is_array($value['seasons'])) {
            foreach ($value['seasons'] as $season) {
                if (!is_array($season)) {
                    continue;
                }

                $months = array();

                if (!empty($season['months']) && is_array($season['months'])) {
                    foreach ($season['months'] as $month) {
                        $month = (int) $month;

                        // Out-of-range months are dropped rather than clamped: a
                        // stray 0 or 13 is a data error, and clamping it would
                        // quietly hand January or December to the wrong band.
                        if ($month >= 1 && $month <= 12 && !in_array($month, $months, true)) {
                            $months[] = $month;
                        }
                    }

                    sort($months);
                }

                $key = isset($season['key']) ? sanitize_key($season['key']) : '';

                if ('' === $key) {
                    continue;
                }

                // 0.1-5 covers every real band while ruling out a zero that
                // would silently publish a free trip.
                $multiplier = isset($season['multiplier']) ? (float) $season['multiplier'] : 1;

                $seasons[] = array(
                    'key'        => $key,
                    'label'      => self::text(isset($season['label']) ? $season['label'] : ''),
                    'multiplier' => min(5, max(0.1, $multiplier)),
                    'months'     => $months,
                );
            }
        }

        $basis = isset($value['basis']) ? sanitize_key($value['basis']) : 'total';

        return array(
            'currency'       => isset($value['currency']) ? self::text($value['currency']) : 'USD',
            'note'           => isset($value['note']) ? self::html($value['note']) : '',
            'basis'          => in_array($basis, array('total', 'per_night'), true) ? $basis : 'total',
            'tiers'          => $tiers,
            'fees'           => isset($value['fees']) ? max(0, (float) $value['fees']) : 0,
            // 200% is already an absurd margin; anything above it is a typo, and
            // an unclamped value would publish a figure nobody meant.
            'buffer_percent' => isset($value['buffer_percent']) ? min(200, max(0, (float) $value['buffer_percent'])) : 25,
            'seasons'        => $seasons,
            // Absent means estimate: most of this catalogue is quoted, so the
            // safe default is the one that promises least.
            'estimate'       => isset($value['estimate']) ? (bool) $value['estimate'] : true,
            'addons'         => $addons,
        );
    }

    public static function sanitize_pairs($value) {
        if (!is_array($value)) {
            return array();
        }

        $out = array();

        foreach ($value as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $out[] = array(
                'heading' => self::text(isset($pair['heading']) ? $pair['heading'] : ''),
                'body'    => self::html(isset($pair['body']) ? $pair['body'] : ''),
            );
        }

        return $out;
    }

    /* ---------------------------------------------------------- cost model */

    /**
     * The season a calendar month falls in.
     *
     * Returns the first band that claims the month, so an author who overlaps
     * two bands gets the earlier one rather than an argument. An unclaimed month
     * returns an empty string, which derive_cost() prices at multiplier 1.
     *
     * @param array $seasons Sanitised seasons.
     * @param int   $month   1-12.
     * @return string Season key, or '' when no band claims the month.
     */
    public static function season_for_month($seasons, $month) {
        if (!is_array($seasons)) {
            return '';
        }

        $month = (int) $month;

        foreach ($seasons as $season) {
            if (!is_array($season) || empty($season['key']) || empty($season['months'])) {
                continue;
            }

            foreach ((array) $season['months'] as $candidate) {
                if ((int) $candidate === $month) {
                    return (string) $season['key'];
                }
            }
        }

        return '';
    }

    /**
     * Work one selection of the cost model out into a figure.
     *
     * Kept deliberately free of WordPress: no globals, no queries, no options.
     * The same arithmetic runs again in the browser, and two implementations
     * only stay in step if the authoritative one is small enough to read.
     *
     * Three rules carry the model:
     *
     * - The season multiplier applies to the land base and nothing else. Flights
     *   are ticketed at their own fares and park permits are set by the
     *   authority; neither gets cheaper because it rains. Multiplying the whole
     *   subtotal would inflate the green-season saving into a number the
     *   operator cannot honour.
     * - The buffer applies to the derived subtotal, because it is margin on
     *   costs the operator carries, not a surcharge on a published price.
     * - The result is rounded to the nearest ten. An estimate quoted to the unit
     *   claims a precision it does not have.
     *
     * @param array  $cost          Sanitised cost structure.
     * @param int    $nights        Trip length, used only when basis is per_night.
     * @param string $season_key    Selected season; unknown or empty prices at multiplier 1.
     * @param int    $tier_index    Index into tiers.
     * @param array  $addon_choices Addon index => choice index.
     * @return array{base: float, flights: float, fees: float, addons_total: float, subtotal: float, buffer: float, total: float, season_label: string, multiplier: float, is_estimate: bool}
     */
    public static function derive_cost($cost, $nights, $season_key = '', $tier_index = 0, $addon_choices = array()) {
        $cost = is_array($cost) ? $cost : array();

        $tiers = (isset($cost['tiers']) && is_array($cost['tiers'])) ? array_values($cost['tiers']) : array();
        $tier  = isset($tiers[(int) $tier_index]) && is_array($tiers[(int) $tier_index]) ? $tiers[(int) $tier_index] : array();

        $land    = isset($tier['land']) ? (float) $tier['land'] : 0.0;
        $flights = isset($tier['flights']) ? (float) $tier['flights'] : 0.0;
        $fees    = isset($cost['fees']) ? (float) $cost['fees'] : 0.0;

        $basis  = (isset($cost['basis']) && 'per_night' === $cost['basis']) ? 'per_night' : 'total';
        $nights = max(0, (int) $nights);

        // A per-night rate on a trip whose duration is missing would price the
        // whole journey at zero, so fall back to the rate itself: wrong by a
        // factor, but visibly wrong rather than invisibly free.
        if ('per_night' === $basis) {
            $base = $nights > 0 ? $land * $nights : $land;
        } else {
            $base = $land;
        }

        $multiplier = 1.0;
        $label      = '';

        if ('' !== (string) $season_key && !empty($cost['seasons']) && is_array($cost['seasons'])) {
            foreach ($cost['seasons'] as $season) {
                if (!is_array($season) || !isset($season['key'])) {
                    continue;
                }

                if ((string) $season['key'] === (string) $season_key) {
                    $multiplier = isset($season['multiplier']) ? (float) $season['multiplier'] : 1.0;
                    $label      = isset($season['label']) ? (string) $season['label'] : '';

                    break;
                }
            }
        }

        $base = $base * $multiplier;

        $addons_total = 0.0;
        $addons       = (isset($cost['addons']) && is_array($cost['addons'])) ? array_values($cost['addons']) : array();

        foreach ($addons as $index => $addon) {
            if (!is_array($addon) || empty($addon['choices']) || !is_array($addon['choices'])) {
                continue;
            }

            $choices = array_values($addon['choices']);
            // No explicit selection means the first choice, which is what the
            // renderer marks as pressed before anyone touches the control.
            $chosen  = isset($addon_choices[$index]) ? (int) $addon_choices[$index] : 0;

            if (!isset($choices[$chosen]) || !is_array($choices[$chosen])) {
                continue;
            }

            $addons_total += isset($choices[$chosen]['price']) ? (float) $choices[$chosen]['price'] : 0.0;
        }

        $subtotal = $base + $flights + $fees + $addons_total;

        $percent = isset($cost['buffer_percent']) ? (float) $cost['buffer_percent'] : 25.0;
        $buffer  = $subtotal * ($percent / 100);

        return array(
            'base'         => $base,
            'flights'      => $flights,
            'fees'         => $fees,
            'addons_total' => $addons_total,
            'subtotal'     => $subtotal,
            'buffer'       => $buffer,
            'total'        => round(($subtotal + $buffer) / 10) * 10,
            'season_label' => $label,
            'multiplier'   => $multiplier,
            'is_estimate'  => isset($cost['estimate']) ? (bool) $cost['estimate'] : true,
        );
    }

    /* ---------------------------------------------------------- projection */

    /**
     * Turn latitude and longitude into positions in the map viewport.
     *
     * The stylised map is an SVG with a 1000x750 viewBox, so it is wider than
     * it is tall. Scaling each axis independently to fill that box would
     * stretch the geography — a north-south route would look as wide as an
     * east-west one. Instead a single scale is derived from whichever axis is
     * the tighter fit, and the result is centred, so the shape of the route
     * survives.
     *
     * Longitude is multiplied by cos(latitude) because a degree of longitude
     * covers less ground the further you are from the equator. Without it, an
     * East African route comes out noticeably too wide.
     *
     * A stop that already carries a manual x/y keeps it. A stop with no
     * coordinates at all is left unpositioned for the caller to skip.
     *
     * @param array $stops Sanitised route stops.
     * @return array Same stops with x/y filled in where derivable.
     */
    public static function project_route($stops) {
        if (!is_array($stops) || empty($stops)) {
            return array();
        }

        // Padding in viewBox units. The right side gets more because stop
        // labels are drawn outward from the marker.
        $pad = apply_filters('wta_route_map_padding', array(
            'top' => 70, 'right' => 190, 'bottom' => 70, 'left' => 90,
        ));

        $vb_w = 1000;
        $vb_h = 750;

        $located = array();

        foreach ($stops as $i => $stop) {
            if (null !== $stop['lat'] && null !== $stop['lng']) {
                $located[$i] = $stop;
            }
        }

        if (empty($located)) {
            return $stops;
        }

        $mean_lat = 0.0;
        foreach ($located as $stop) {
            $mean_lat += $stop['lat'];
        }
        $mean_lat = deg2rad($mean_lat / count($located));

        // Equirectangular, north up.
        $points = array();
        foreach ($located as $i => $stop) {
            $points[$i] = array(
                'px' => $stop['lng'] * cos($mean_lat),
                'py' => -$stop['lat'],
            );
        }

        $xs = wp_list_pluck($points, 'px');
        $ys = wp_list_pluck($points, 'py');

        $min_x = min($xs);
        $max_x = max($xs);
        $min_y = min($ys);
        $max_y = max($ys);

        $span_x = $max_x - $min_x;
        $span_y = $max_y - $min_y;

        $avail_w = $vb_w - $pad['left'] - $pad['right'];
        $avail_h = $vb_h - $pad['top'] - $pad['bottom'];

        // A single stop, or several at the same point, has no span to scale
        // against — division would be by zero, so centre them instead.
        if ($span_x < 0.0001 && $span_y < 0.0001) {
            foreach (array_keys($points) as $i) {
                if (null === $stops[$i]['x']) {
                    $stops[$i]['x'] = 50.0;
                }
                if (null === $stops[$i]['y']) {
                    $stops[$i]['y'] = 50.0;
                }
            }

            return $stops;
        }

        $scale = min(
            $span_x > 0.0001 ? $avail_w / $span_x : PHP_INT_MAX,
            $span_y > 0.0001 ? $avail_h / $span_y : PHP_INT_MAX
        );

        // Centre whatever slack the uniform scale leaves on each axis.
        $offset_x = $pad['left'] + ($avail_w - $span_x * $scale) / 2;
        $offset_y = $pad['top'] + ($avail_h - $span_y * $scale) / 2;

        foreach ($points as $i => $p) {
            $vb_x = $offset_x + ($p['px'] - $min_x) * $scale;
            $vb_y = $offset_y + ($p['py'] - $min_y) * $scale;

            // Percentages, so the widget can place markers without knowing the
            // viewBox and a designer can still override by hand.
            if (null === $stops[$i]['x']) {
                $stops[$i]['x'] = round($vb_x / $vb_w * 100, 3);
            }

            if (null === $stops[$i]['y']) {
                $stops[$i]['y'] = round($vb_y / $vb_h * 100, 3);
            }
        }

        return $stops;
    }

    /**
     * Attach each leg to a destination term.
     *
     * A leg titled "Tanzania" on a trip filed under Tanzania is the same thing
     * said twice, so the heading should link through to that destination rather
     * than sit as dead text. Matching is by name against the trip's own terms
     * only — never a site-wide lookup, which would happily link a leg called
     * "The coast" to some unrelated term.
     *
     * An explicitly set term_id always wins.
     */
    /**
     * Keep a leg inside the day list it points at.
     *
     * Legs hold indexes into WP Travel's itinerary array, and that array is
     * edited independently. Adding, deleting or reordering a day leaves the
     * stored indexes pointing somewhere else, so a leg can run past the end of
     * the list. Rendering that produces either the wrong days under a heading
     * or an empty stage, with nothing said about it.
     *
     * Clamping cannot recover the operator's intent - only they know which days
     * a stage was meant to cover - so this limits the damage to a visible one:
     * a leg is trimmed to the days that exist, and a leg with nothing left to
     * show is dropped rather than rendered blank. The trip editor reports the
     * same mismatch so it can actually be corrected at the source.
     *
     * @param array $legs      Resolved legs.
     * @param int   $day_count Days currently in the WP Travel itinerary.
     * @return array
     */
    public static function clamp_legs($legs, $day_count) {
        if (!is_array($legs) || empty($legs)) {
            return array();
        }

        $day_count = (int) $day_count;

        if ($day_count < 1) {
            return array();
        }

        $last = $day_count - 1;
        $out  = array();

        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                continue;
            }

            $from = isset($leg['day_from']) ? (int) $leg['day_from'] : 0;
            $to   = isset($leg['day_to']) ? (int) $leg['day_to'] : $from;

            if ($from > $last) {
                // Nothing of this stage survives in the current itinerary.
                continue;
            }

            $leg['day_from']  = max(0, min($from, $last));
            $leg['day_to']    = max($leg['day_from'], min($to, $last));
            $leg['wta_stale'] = ($leg['day_from'] !== $from || $leg['day_to'] !== $to);

            $out[] = $leg;
        }

        return $out;
    }

    public static function resolve_leg_terms($legs, $post_id) {
        if (empty($legs) || !is_array($legs)) {
            return $legs;
        }

        $terms = get_the_terms($post_id, 'travel_locations');

        if (!$terms || is_wp_error($terms)) {
            return $legs;
        }

        $by_name = array();
        foreach ($terms as $term) {
            $by_name[strtolower(trim($term->name))] = $term;
        }

        foreach ($legs as $i => $leg) {
            $term = null;

            if (!empty($leg['term_id'])) {
                $found = get_term((int) $leg['term_id'], 'travel_locations');
                if ($found && !is_wp_error($found)) {
                    $term = $found;
                }
            }

            if (!$term) {
                $key = strtolower(trim(isset($leg['title']) ? $leg['title'] : ''));
                if ('' !== $key && isset($by_name[$key])) {
                    $term = $by_name[$key];
                }
            }

            $legs[$i]['term_id']   = $term ? (int) $term->term_id : 0;
            $legs[$i]['term_name'] = $term ? $term->name : '';
            $legs[$i]['term_link'] = $term ? get_term_link($term) : '';

            if (is_wp_error($legs[$i]['term_link'])) {
                $legs[$i]['term_link'] = '';
            }
        }

        return $legs;
    }

    /* ------------------------------------------------------------- reading */

    /**
     * Everything the template needs for one trip, with WP Travel's own data
     * folded in and sensible fallbacks applied.
     */
    public static function for_trip($post_id) {
        $data = array();

        foreach (self::fields() as $meta_key => $field) {
            $value = get_post_meta($post_id, $meta_key, true);
            $data[$field['rest_key']] = is_array($value) ? $value : array();
        }

        $facts = WTA_Trip::facts($post_id);
        $days  = get_post_meta($post_id, WTA_Trip::ITINERARY_META, true);

        $data['days']  = is_array($days) ? array_values($days) : array();
        $data['facts'] = $facts;

        // Build on WP Travel's existing map rather than beside it: a trip that
        // has set a location but no route still gets one pin, from the very
        // coordinates its own map feature uses.
        if (empty($data['route'])) {
            $lat = get_post_meta($post_id, 'wp_travel_lat', true);
            $lng = get_post_meta($post_id, 'wp_travel_lng', true);

            if ('' !== $lat && '' !== $lng) {
                $data['route'] = self::sanitize_route(array(array(
                    'name'     => $facts['location'] ? $facts['location'] : get_the_title($post_id),
                    'subtitle' => '',
                    'nights'   => (int) $facts['nights'],
                    'leg'      => 0,
                    'lat'      => $lat,
                    'lng'      => $lng,
                    'arrive'   => 'start',
                )));
            }
        }

        $data['route'] = self::project_route($data['route']);
        $data['legs']  = self::clamp_legs(self::resolve_leg_terms($data['legs'], $post_id), count($data['days']));

        // A trip with no authored hero still deserves a headline block.
        if (empty($data['hero']['stats'])) {
            $stats = array();

            if ($facts['nights']) {
                $stats[] = array('value' => (string) (int) $facts['nights'], 'label' => 'Nights');
            }

            if ($data['route']) {
                $stats[] = array('value' => (string) count($data['route']), 'label' => 'Stops');
            }

            $destinations = get_the_terms($post_id, 'travel_locations');

            if ($destinations && !is_wp_error($destinations)) {
                $stats[] = array('value' => (string) count($destinations), 'label' => 'Destinations');
            }

            $data['hero']['stats'] = $stats;
        }

        // With no legs authored, treat the whole itinerary as one leg so the
        // day cards still render.
        if (empty($data['legs']) && $data['days']) {
            $data['legs'] = array(array(
                'title'    => get_the_title($post_id),
                'subtitle' => '',
                'accent'   => 'forest',
                'day_from' => 0,
                'day_to'   => count($data['days']) - 1,
            ));
        }

        return $data;
    }
}
