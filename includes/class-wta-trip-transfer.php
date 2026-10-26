<?php
/**
 * Export and import of whole trips.
 *
 * Lets a client download trips as one JSON document, correct them offline, and
 * upload the result, instead of making dozens of single-field calls.
 *
 * Import reuses the sanitisers the single-purpose abilities already use, so a
 * trip written this way is indistinguishable from one written field by field.
 *
 * What it will not do, deliberately:
 *
 * - It never creates terms. Missing terms are reported; create them with
 *   create-term first, so a typo cannot publish a new archive. Trips are
 *   created only when allow_create is set, and always as drafts.
 * - It never changes an existing trip's slug or status. A slug change breaks
 *   URLs without a redirect, and status is how an unreviewed trip goes live.
 * - It never writes pricing or dates. Those are exported for reference only;
 *   prices are written one confirmed figure at a time by set-trip-price.
 * - It refuses a trip that changed after it was exported, unless forced, so an
 *   upload cannot silently undo someone else's edit.
 *
 * Bookings and enquiries are not part of a trip and are never exported.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Trip_Transfer {

    const FORMAT  = 'nyuchi-travel/trip-export';
    const VERSION = 1;

    /** Trips per call: keeps one request inside a host's time and size limits. */
    const MAX_TRIPS = 25;

    /** Sections an import may be limited to. */
    const SECTIONS = array('post', 'meta', 'days', 'itinerary', 'terms');

    public static function register() {
        self::register_export();
        self::register_import();

        if (class_exists('WTA_Trip_CSV')) {
            WTA_Trip_CSV::register();
        }
    }

    /**
     * Per-trip capability is checked inside export and import; this gate only
     * turns away users who cannot edit anything.
     *
     * @return true|WP_Error
     */
    public static function can_edit_posts() {
        if (!current_user_can('edit_posts')) {
            return new WP_Error('wta_forbidden', 'You are not allowed to read or edit WP Travel trips.', array('status' => 403));
        }

        return true;
    }

    /* --------------------------------------------------------------- export */

    protected static function register_export() {
        wp_register_ability(WTA_Travel_Abilities::NS . 'export-trips', array(
            'label'       => 'Export trips as JSON',
            'description' => 'Download up to ' . self::MAX_TRIPS . ' trips as one JSON document: post fields, WP Travel meta, the day-by-day list, the Nyuchi itinerary groups (hero, legs, route, seasonality, options, checklist, notes) and terms by slug. Pricing and dates are included under "reference" for context only and are ignored on import. Each trip carries its modified_gmt, which import uses to detect edits made since. Contains no booking or enquiry data. Edit the document, then pass it to import-trips.',
            'category'    => WTA_Travel_Abilities::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_ids'          => array(
                        'type'        => 'array',
                        'description' => 'Post IDs of the trips to export.',
                        'items'       => array('type' => 'integer', 'minimum' => 1),
                        'minItems'    => 1,
                        'maxItems'    => self::MAX_TRIPS,
                    ),
                    'include_reference' => array(
                        'type'        => 'boolean',
                        'description' => 'Include pricing and dates rows, read-only. Defaults to true.',
                    ),
                ),
                'required'   => array('trip_ids'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'format'      => array('type' => 'string'),
                    'version'     => array('type' => 'integer'),
                    'exported_at' => array('type' => 'string'),
                    'site'        => array('type' => 'string'),
                    'trips'       => array('type' => 'array', 'items' => array('type' => 'object')),
                    'rejected'    => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
                'required'   => array('format', 'version', 'trips', 'rejected'),
            ),

            'permission_callback' => array(__CLASS__, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                return self::export(
                    isset($input['trip_ids']) ? (array) $input['trip_ids'] : array(),
                    !isset($input['include_reference']) || (bool) $input['include_reference']
                );
            },
        ));
    }

    /**
     * @param int[] $trip_ids
     * @return array<string, mixed>|WP_Error
     */
    public static function export($trip_ids, $include_reference = true) {
        $trip_ids = array_values(array_unique(array_filter(array_map('absint', $trip_ids))));

        if (empty($trip_ids)) {
            return new WP_Error('wta_no_trips', 'trip_ids must list at least one trip.', array('status' => 400));
        }

        if (count($trip_ids) > self::MAX_TRIPS) {
            return new WP_Error(
                'wta_too_many_trips',
                sprintf('At most %d trips per call; %d were requested. Export in batches.', self::MAX_TRIPS, count($trip_ids)),
                array('status' => 400)
            );
        }

        $trips    = array();
        $rejected = array();

        foreach ($trip_ids as $trip_id) {
            $check = WTA_Travel_Abilities::require_trip($trip_id);

            if (is_wp_error($check)) {
                $rejected[] = sprintf('%d: %s', $trip_id, $check->get_error_message());
                continue;
            }

            if (!current_user_can('edit_post', $trip_id)) {
                $rejected[] = sprintf('%d: you are not allowed to edit this trip, so it was not exported.', $trip_id);
                continue;
            }

            $trips[] = self::export_one($trip_id, $include_reference);
        }

        return array(
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'site'        => home_url(),
            'trips'       => $trips,
            'rejected'    => $rejected,
        );
    }

    protected static function export_one($trip_id, $include_reference) {
        $post   = get_post($trip_id);
        $fields = WTA_Trip::meta_fields();
        $meta   = array();

        foreach (array_merge((array) $fields['text'], (array) $fields['html'], (array) $fields['protected']) as $key) {
            if (metadata_exists('post', $trip_id, $key)) {
                $meta[$key] = (string) get_post_meta($trip_id, $key, true);
            }
        }

        // Stored values, not for_trip(): that reader fills fallbacks for an
        // unauthored trip, and exporting a fallback would turn it into
        // authored content on the next import.
        $itinerary = array();

        foreach (WTA_Itinerary_Schema::fields() as $meta_key => $field) {
            $stored = get_post_meta($trip_id, $meta_key, true);

            if (is_array($stored) && !empty($stored)) {
                $itinerary[$field['rest_key']] = $stored;
            }
        }

        $days = get_post_meta($trip_id, WTA_Trip::ITINERARY_META, true);

        $terms = array();

        foreach (WTA_Travel_Abilities::trip_terms($trip_id) as $taxonomy => $rows) {
            $terms[$taxonomy] = array_values(array_map(function ($row) {
                return $row['slug'];
            }, $rows));
        }

        $out = array(
            'id'           => (int) $post->ID,
            'slug'         => $post->post_name,
            'status'       => $post->post_status,
            'modified_gmt' => $post->post_modified_gmt,
            'post'         => array(
                'title'   => $post->post_title,
                'excerpt' => $post->post_excerpt,
                'content' => $post->post_content,
            ),
            'meta'         => $meta,
            'days'         => is_array($days) ? array_values($days) : array(),
            'itinerary'    => $itinerary,
            'terms'        => $terms,
        );

        if ($include_reference) {
            $pricings = WTA_Travel_Abilities::read_trip_rows('wt_pricings', $trip_id);

            $out['reference'] = array(
                'note'      => 'Read-only context. Ignored by import-trips.',
                'pricing'   => $pricings['rows'],
                'relations' => WTA_Travel_Abilities::read_related_rows('wt_price_category_relation', $pricings)['rows'],
                'dates'     => WTA_Travel_Abilities::read_trip_rows('wt_dates', $trip_id)['rows'],
            );
        }

        return $out;
    }

    /* --------------------------------------------------------------- import */

    protected static function register_import() {
        wp_register_ability(WTA_Travel_Abilities::NS . 'import-trips', array(
            'label'       => 'Import edited trips from JSON',
            'description' => 'Apply a document produced by export-trips back onto the same trips. Updates existing trips; with allow_create it also creates a draft trip for each entry without an id. It never creates terms, never changes the slug or status of an existing trip, and never writes pricing or dates. Only the sections present for a trip are touched, and each is sanitised exactly as the single-purpose abilities do. A trip that changed since it was exported is refused unless force is true. Defaults to a dry run that reports, per trip, which sections would change and writes nothing.',
            'category'    => WTA_Travel_Abilities::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'document' => array(
                        'type'        => 'object',
                        'description' => 'The export-trips output, edited. Needs "format" and a "trips" array.',
                    ),
                    'sections' => array(
                        'type'        => 'array',
                        'description' => 'Limit the import to these sections. Defaults to all present.',
                        'items'       => array('type' => 'string', 'enum' => self::SECTIONS),
                    ),
                    'force'    => array(
                        'type'        => 'boolean',
                        'description' => 'Apply even if a trip was modified after the export. Overwrites that later edit. Defaults to false.',
                    ),
                    'allow_create' => array(
                        'type'        => 'boolean',
                        'description' => 'Create a new trip for each entry that has no id. New trips are always drafts, whatever status the entry carries. Defaults to false, in which case an entry without an id is skipped.',
                    ),
                    'dry_run'  => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to write.',
                    ),
                ),
                'required'   => array('document'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'dry_run' => array('type' => 'boolean', 'description' => 'True means nothing was written.'),
                    'applied' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'Trips written (empty on a dry run).'),
                    'trips'   => array('type' => 'array', 'items' => array('type' => 'object'), 'description' => 'Per trip: id, status (changed, unchanged or skipped), changed sections, warnings, reason.'),
                    'note'    => array('type' => 'string'),
                ),
                'required'   => array('dry_run', 'applied', 'trips', 'note'),
            ),

            'permission_callback' => array(__CLASS__, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                return self::import(
                    isset($input['document']) ? $input['document'] : null,
                    isset($input['sections']) ? (array) $input['sections'] : self::SECTIONS,
                    !empty($input['force']),
                    !isset($input['dry_run']) || (bool) $input['dry_run'],
                    !empty($input['allow_create'])
                );
            },
        ));
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public static function import($document, array $sections, $force, $dry, $allow_create = false) {
        if (!is_array($document) || !isset($document['format']) || self::FORMAT !== $document['format']) {
            return new WP_Error('wta_bad_document', 'document is not an export-trips document (format mismatch).', array('status' => 400));
        }

        if (!isset($document['version']) || (int) $document['version'] > self::VERSION) {
            return new WP_Error('wta_bad_version', 'document version is missing or newer than this plugin understands.', array('status' => 400));
        }

        if (empty($document['trips']) || !is_array($document['trips'])) {
            return new WP_Error('wta_no_trips', 'document has no trips to import.', array('status' => 400));
        }

        if (count($document['trips']) > self::MAX_TRIPS) {
            return new WP_Error('wta_too_many_trips', sprintf('At most %d trips per call.', self::MAX_TRIPS), array('status' => 400));
        }

        $sections = array_values(array_intersect(self::SECTIONS, $sections));

        $report  = array();
        $applied = array();

        foreach ($document['trips'] as $entry) {
            $row = self::import_one(is_array($entry) ? $entry : array(), $sections, $force, $dry, $allow_create);

            if ('changed' === $row['status'] && !$dry) {
                $applied[] = $row['id'];
            }

            unset($row['plan']);
            $report[] = $row;
        }

        return array(
            'dry_run' => $dry,
            'applied' => $applied,
            'trips'   => $report,
            'note'    => $dry
                ? 'Nothing was written. Call again with dry_run false to apply the trips marked "changed".'
                : 'Written. New trips are drafts: review and publish them in the admin. Purge Cloudflare and Varnish if the front end shows stale content.',
        );
    }

    protected static function import_one(array $entry, array $sections, $force, $dry, $allow_create = false) {
        $trip_id = isset($entry['id']) ? absint($entry['id']) : 0;
        $is_new  = 0 === $trip_id;
        $row     = array('id' => $trip_id, 'status' => 'skipped', 'new' => $is_new, 'changed' => array(), 'warnings' => array(), 'reason' => '');
        $post    = null;

        if ($is_new) {
            if (!$allow_create) {
                $row['reason'] = 'The entry has no id, so importing it would create a new trip. Pass allow_create to create trips.';
                return $row;
            }

            $type_object = get_post_type_object(WTA_Trip::post_type());
            $create_cap  = ($type_object && isset($type_object->cap->create_posts)) ? $type_object->cap->create_posts : 'edit_posts';

            if (!current_user_can($create_cap)) {
                $row['reason'] = 'You are not allowed to create trips.';
                return $row;
            }

            if (!isset($entry['post']['title']) || '' === trim(sanitize_text_field((string) $entry['post']['title']))) {
                $row['reason'] = 'A new trip needs post.title.';
                return $row;
            }

            if (isset($entry['status']) && 'draft' !== (string) $entry['status']) {
                $row['warnings'][] = 'status was ignored: new trips are always created as drafts.';
            }
        } else {
            $check = WTA_Travel_Abilities::require_trip($trip_id);

            if (is_wp_error($check)) {
                $row['reason'] = $check->get_error_message();
                return $row;
            }

            if (!current_user_can('edit_post', $trip_id)) {
                $row['reason'] = 'You are not allowed to edit this trip.';
                return $row;
            }

            $post = get_post($trip_id);

            if (!$force) {
                if (empty($entry['modified_gmt'])) {
                    $row['reason'] = 'The entry has no modified_gmt, so edits since the export cannot be ruled out. Pass force to apply anyway.';
                    return $row;
                }

                if ((string) $entry['modified_gmt'] !== (string) $post->post_modified_gmt) {
                    $row['reason'] = sprintf('Trip was modified at %s, after the export (%s). Export it again, or pass force to overwrite that edit.', $post->post_modified_gmt, $entry['modified_gmt']);
                    return $row;
                }
            }

            foreach (array('slug', 'status') as $ignored) {
                if (isset($entry[$ignored]) && (string) $entry[$ignored] !== (string) $post->{'post_' . ('slug' === $ignored ? 'name' : 'status')}) {
                    $row['warnings'][] = sprintf('%s differs from the live trip and was ignored: import never changes the slug or status of an existing trip.', $ignored);
                }
            }
        }

        $plan = array();

        if (in_array('post', $sections, true) && isset($entry['post']) && is_array($entry['post'])) {
            $map = array(
                'title'   => array('post_title', 'sanitize_text_field'),
                'content' => array('post_content', 'wp_kses_post'),
                'excerpt' => array('post_excerpt', 'sanitize_textarea_field'),
            );
            $postarr = array();

            foreach ($map as $key => $spec) {
                if (!array_key_exists($key, $entry['post'])) {
                    continue;
                }

                $value = call_user_func($spec[1], (string) $entry['post'][$key]);

                if ($is_new ? '' !== $value : $value !== $post->{$spec[0]}) {
                    $postarr[$spec[0]] = $value;
                }
            }

            // A new trip may carry a slug (WordPress makes it unique); an
            // existing trip's slug is never touched.
            if ($is_new && !empty($entry['slug'])) {
                $postarr['post_name'] = sanitize_title((string) $entry['slug']);
            }

            if ($postarr) {
                $plan['post'] = $postarr;
            }
        }

        if (in_array('meta', $sections, true) && isset($entry['meta'])) {
            $meta = WTA_Travel_Abilities::partition_meta($entry['meta']);
            $diff = array();

            foreach ($meta['accepted'] as $key => $value) {
                $current = $is_new ? '' : (string) get_post_meta($trip_id, $key, true);

                if ($current !== (string) $value) {
                    $diff[$key] = $value;
                }
            }

            foreach ($meta['rejected'] as $reason) {
                $row['warnings'][] = $reason;
            }

            if ($diff) {
                $plan['meta'] = $diff;
            }
        }

        if (in_array('days', $sections, true) && isset($entry['days'])) {
            if (!is_array($entry['days'])) {
                $row['warnings'][] = 'days: expected an array, skipped.';
            } else {
                $days    = self::clean_days($entry['days']);
                $current = $is_new ? array() : get_post_meta($trip_id, WTA_Trip::ITINERARY_META, true);
                $current = is_array($current) ? self::clean_days($current) : array();

                if ($days !== $current) {
                    $plan['days'] = $days;

                    if (!$is_new && count($days) !== count($current)) {
                        $row['warnings'][] = sprintf('days: count changes from %d to %d; any leg that referenced a day by index now points elsewhere.', count($current), count($days));
                    }
                }

                foreach ($days as $i => $day) {
                    if (false !== strpos($day['desc'], '%')) {
                        $row['warnings'][] = sprintf('days: day %d contains a literal percent sign, which can break the trip outline template. Write "percent".', $i + 1);
                    }
                }
            }
        }

        if (in_array('itinerary', $sections, true) && isset($entry['itinerary'])) {
            if (!is_array($entry['itinerary'])) {
                $row['warnings'][] = 'itinerary: expected an object, skipped.';
            } else {
                foreach (WTA_Itinerary_Schema::fields() as $meta_key => $field) {
                    $rest_key = $field['rest_key'];

                    if (!array_key_exists($rest_key, $entry['itinerary'])) {
                        continue;
                    }

                    if (!is_array($entry['itinerary'][$rest_key])) {
                        $row['warnings'][] = sprintf('itinerary.%s: expected an object or array, skipped.', $rest_key);
                        continue;
                    }

                    $clean = call_user_func(array('WTA_Itinerary_Schema', $field['sanitize']), $entry['itinerary'][$rest_key]);

                    if (is_wp_error($clean)) {
                        $row['warnings'][] = sprintf('itinerary.%s: %s', $rest_key, $clean->get_error_message());
                        continue;
                    }

                    if ($is_new ? !empty($clean) : get_post_meta($trip_id, $meta_key, true) !== $clean) {
                        $plan['itinerary'][$meta_key] = $clean;
                    }
                }
            }
        }

        if (in_array('terms', $sections, true) && isset($entry['terms'])) {
            $resolved = WTA_Travel_Abilities::resolve_terms($entry['terms']);
            $before   = $is_new ? array() : WTA_Travel_Abilities::trip_terms($trip_id);

            foreach ($resolved['rejected'] as $reason) {
                $row['warnings'][] = $reason;
            }

            foreach ($resolved['resolved'] as $taxonomy => $ids) {
                $now = isset($before[$taxonomy]) ? array_map('intval', wp_list_pluck($before[$taxonomy], 'term_id')) : array();
                $a   = $ids;
                $b   = $now;
                sort($a);
                sort($b);

                if ($a !== $b) {
                    $plan['terms'][$taxonomy] = $ids;
                }
            }
        }

        $row['changed'] = array_keys($plan);
        $row['status']  = $plan ? 'changed' : 'unchanged';
        $row['plan']    = $plan;

        if ($dry || !$plan) {
            return $row;
        }

        if ($is_new) {
            $inserted = wp_insert_post(
                array_merge(
                    array('post_type' => WTA_Trip::post_type(), 'post_status' => 'draft'),
                    isset($plan['post']) ? $plan['post'] : array()
                ),
                true
            );

            if (is_wp_error($inserted) || !$inserted) {
                $row['status'] = 'skipped';
                $row['reason'] = is_wp_error($inserted) ? $inserted->get_error_message() : 'The trip could not be created.';
                return $row;
            }

            $trip_id    = (int) $inserted;
            $row['id']  = $trip_id;
            unset($plan['post']);
        } elseif (isset($plan['post'])) {
            $updated = wp_update_post(array_merge(array('ID' => $trip_id), $plan['post']), true);

            if (is_wp_error($updated)) {
                $row['status'] = 'skipped';
                $row['reason'] = $updated->get_error_message();
                return $row;
            }
        }

        foreach (isset($plan['meta']) ? $plan['meta'] : array() as $key => $value) {
            if ('' === $value) {
                delete_post_meta($trip_id, $key);
            } else {
                update_post_meta($trip_id, $key, $value);
            }
        }

        if (isset($plan['days'])) {
            update_post_meta($trip_id, WTA_Trip::ITINERARY_META, $plan['days']);
        }

        foreach (isset($plan['itinerary']) ? $plan['itinerary'] : array() as $meta_key => $clean) {
            update_post_meta($trip_id, $meta_key, $clean);
        }

        foreach (isset($plan['terms']) ? $plan['terms'] : array() as $taxonomy => $ids) {
            wp_set_object_terms($trip_id, $ids, $taxonomy, false);
        }

        clean_post_cache($trip_id);

        return $row;
    }

    /**
     * Same shape and sanitisers as set-itinerary-days and the REST field, so a
     * day written here is indistinguishable in the database.
     *
     * @param array<int, mixed> $days
     * @return array<int, array<string, string>>
     */
    protected static function clean_days(array $days) {
        $clean = array();

        foreach (array_values($days) as $i => $day) {
            if (!is_array($day)) {
                continue;
            }

            $clean[$i] = array(
                'label' => isset($day['label']) ? sanitize_text_field($day['label']) : sprintf('Day %d', $i + 1),
                'title' => isset($day['title']) ? sanitize_text_field($day['title']) : '',
                'date'  => isset($day['date']) ? sanitize_text_field($day['date']) : '',
                'time'  => isset($day['time']) ? sanitize_text_field($day['time']) : '',
                'desc'  => isset($day['desc']) ? wp_kses_post($day['desc']) : '',
            );
        }

        return array_values($clean);
    }
}
