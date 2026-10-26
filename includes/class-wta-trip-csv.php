<?php
/**
 * CSV export and import of trips, for editing in a spreadsheet.
 *
 * This is a converter onto the JSON document WTA_Trip_Transfer already reads
 * and writes, not a second write path: a trip imported from CSV goes through
 * exactly the same checks, sanitisers and dry run as one imported from JSON.
 *
 * Two files:
 *
 * - trips.csv: one row per trip. Fixed columns id, ref, modified_gmt, slug,
 *   status, title, excerpt, content; then meta:<key> for each WP Travel field
 *   this plugin knows, and terms:<taxonomy> holding term slugs joined by "|".
 * - days.csv: one row per day. trip_id (or trip_ref, for a trip that has no id
 *   yet), day, label, title, date, time, desc. A trip that appears here has its
 *   whole day list replaced by these rows; a trip that does not is untouched.
 *
 * Blank cell: leave unchanged. A cell cannot clear a field by being empty, so a
 * stray delete in a spreadsheet cannot wipe content. Write [clear] to clear a
 * meta field or a term list.
 *
 * Not in CSV: the itinerary groups (hero, legs, route, seasonality, options,
 * checklist, notes), which are nested and belong in the JSON document.
 *
 * Cells that a spreadsheet would run as a formula (a leading = + - @ on
 * something that is not a plain number) are written with a leading apostrophe
 * and the apostrophe is removed on import, so a round trip is lossless.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Trip_CSV {

    const CLEAR = '[clear]';

    /** Fixed leading columns of trips.csv. */
    const TRIP_COLUMNS = array('id', 'ref', 'modified_gmt', 'slug', 'status', 'title', 'excerpt', 'content');

    const DAY_COLUMNS = array('trip_id', 'trip_ref', 'day', 'label', 'title', 'date', 'time', 'desc');

    public static function register() {
        self::register_export();
        self::register_import();
    }

    /* --------------------------------------------------------------- export */

    protected static function register_export() {
        wp_register_ability(WTA_Travel_Abilities::NS . 'export-trips-csv', array(
            'label'       => 'Export trips as CSV',
            'description' => 'Download up to ' . WTA_Trip_Transfer::MAX_TRIPS . ' trips as two CSV files for editing in a spreadsheet: trips.csv (one row per trip: post fields, WP Travel meta, terms as slugs joined by "|") and days.csv (one row per day). Leave a cell blank to keep a value; write [clear] to clear a meta field or term list. The itinerary groups (hero, legs, route, seasonality, options, checklist, notes) are not in CSV; use export-trips for those. Pass the edited files to import-trips-csv.',
            'category'    => WTA_Travel_Abilities::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_ids' => array(
                        'type'     => 'array',
                        'items'    => array('type' => 'integer', 'minimum' => 1),
                        'minItems' => 1,
                        'maxItems' => WTA_Trip_Transfer::MAX_TRIPS,
                    ),
                ),
                'required'   => array('trip_ids'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trips_csv' => array('type' => 'string', 'description' => 'Contents of trips.csv (UTF-8 with BOM, for Excel).'),
                    'days_csv'  => array('type' => 'string', 'description' => 'Contents of days.csv.'),
                    'rejected'  => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
                'required'   => array('trips_csv', 'days_csv', 'rejected'),
            ),

            'permission_callback' => array('WTA_Trip_Transfer', 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $doc = WTA_Trip_Transfer::export(isset($input['trip_ids']) ? (array) $input['trip_ids'] : array(), false);

                if (is_wp_error($doc)) {
                    return $doc;
                }

                $files = self::to_csv($doc);

                return array(
                    'trips_csv' => $files['trips'],
                    'days_csv'  => $files['days'],
                    'rejected'  => $doc['rejected'],
                );
            },
        ));
    }

    /**
     * @param array<string, mixed> $doc An export-trips document.
     * @return array{trips: string, days: string}
     */
    public static function to_csv(array $doc) {
        $meta_cols = self::meta_columns();
        $term_cols = self::term_columns();

        $header = array_merge(self::TRIP_COLUMNS, $meta_cols, $term_cols);
        $trips  = array($header);
        $days   = array(self::DAY_COLUMNS);

        foreach ($doc['trips'] as $trip) {
            $row = array(
                'id'           => $trip['id'],
                'ref'          => '',
                'modified_gmt' => $trip['modified_gmt'],
                'slug'         => $trip['slug'],
                'status'       => $trip['status'],
                'title'        => $trip['post']['title'],
                'excerpt'      => $trip['post']['excerpt'],
                'content'      => $trip['post']['content'],
            );

            foreach ($meta_cols as $column) {
                $key          = substr($column, strlen('meta:'));
                $row[$column] = isset($trip['meta'][$key]) ? $trip['meta'][$key] : '';
            }

            foreach ($term_cols as $column) {
                $taxonomy     = substr($column, strlen('terms:'));
                $row[$column] = isset($trip['terms'][$taxonomy]) ? implode('|', $trip['terms'][$taxonomy]) : '';
            }

            $trips[] = array_map(array(__CLASS__, 'guard_cell'), array_values(self::ordered($header, $row)));

            foreach (array_values($trip['days']) as $i => $day) {
                $days[] = array_map(array(__CLASS__, 'guard_cell'), array(
                    $trip['id'],
                    '',
                    $i + 1,
                    isset($day['label']) ? $day['label'] : '',
                    isset($day['title']) ? $day['title'] : '',
                    isset($day['date']) ? $day['date'] : '',
                    isset($day['time']) ? $day['time'] : '',
                    isset($day['desc']) ? $day['desc'] : '',
                ));
            }
        }

        return array('trips' => "\xEF\xBB\xBF" . self::write_csv($trips), 'days' => "\xEF\xBB\xBF" . self::write_csv($days));
    }

    /** @return string[] */
    protected static function meta_columns() {
        $fields = WTA_Trip::meta_fields();
        $keys   = array_merge((array) $fields['text'], (array) $fields['html'], (array) $fields['protected']);

        return array_map(function ($key) {
            return 'meta:' . $key;
        }, $keys);
    }

    /** @return string[] */
    protected static function term_columns() {
        return array_map(function ($taxonomy) {
            return 'terms:' . $taxonomy;
        }, array_keys((array) WTA_Trip::default_taxonomies()));
    }

    /**
     * @param string[]             $header
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function ordered(array $header, array $row) {
        $out = array();

        foreach ($header as $column) {
            $out[$column] = isset($row[$column]) ? $row[$column] : '';
        }

        return $out;
    }

    /**
     * Make a cell safe to open in a spreadsheet. Plain numbers are left alone:
     * "-1.29" is a latitude, not a formula.
     */
    public static function guard_cell($value) {
        $value = (string) $value;

        if ('' !== $value && preg_match('/^[=+\-@\t\r]/', $value) && !is_numeric($value)) {
            return "'" . $value;
        }

        return $value;
    }

    protected static function unguard_cell($value) {
        $value = (string) $value;

        if (preg_match('/^\'[=+\-@\t\r]/', $value)) {
            return substr($value, 1);
        }

        return $value;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    public static function write_csv(array $rows) {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        rewind($handle);
        $out = stream_get_contents($handle);
        fclose($handle);

        return (string) $out;
    }

    /* --------------------------------------------------------------- import */

    protected static function register_import() {
        wp_register_ability(WTA_Travel_Abilities::NS . 'import-trips-csv', array(
            'label'       => 'Import edited trips from CSV',
            'description' => 'Apply trips.csv (and optionally days.csv) from export-trips-csv. A blank cell leaves a value unchanged; [clear] clears a meta field or term list. A trip with a row in days.csv has its whole day list replaced by those rows. A row with no id is skipped unless allow_create is true, in which case a draft trip is created (days.csv can reach it through trip_ref). Same safeguards as import-trips: dry run by default, trips modified since the export are refused unless force, an existing trip\'s slug and status are never changed, terms are never created, pricing and dates are never written.',
            'category'    => WTA_Travel_Abilities::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trips_csv'    => array('type' => 'string', 'description' => 'Contents of trips.csv.'),
                    'days_csv'     => array('type' => 'string', 'description' => 'Contents of days.csv. Optional.'),
                    'sections'     => array(
                        'type'        => 'array',
                        'description' => 'Limit the import to these sections. CSV carries post, meta, days and terms. Defaults to all.',
                        'items'       => array('type' => 'string', 'enum' => array('post', 'meta', 'days', 'terms')),
                    ),
                    'force'        => array('type' => 'boolean', 'description' => 'Apply even if a trip was modified after the export. Defaults to false.'),
                    'allow_create' => array('type' => 'boolean', 'description' => 'Create a draft trip for each row without an id. Defaults to false.'),
                    'dry_run'      => array('type' => 'boolean', 'description' => 'Defaults to true. Must be explicitly false to write.'),
                ),
                'required'   => array('trips_csv'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'dry_run'      => array('type' => 'boolean'),
                    'applied'      => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'trips'        => array('type' => 'array', 'items' => array('type' => 'object')),
                    'csv_warnings' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Problems reading the CSV itself: unknown columns, days for unknown trips.'),
                    'note'         => array('type' => 'string'),
                ),
                'required'   => array('dry_run', 'applied', 'trips', 'csv_warnings', 'note'),
            ),

            'permission_callback' => array('WTA_Trip_Transfer', 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $parsed = self::from_csv(
                    isset($input['trips_csv']) ? (string) $input['trips_csv'] : '',
                    isset($input['days_csv']) ? (string) $input['days_csv'] : ''
                );

                if (is_wp_error($parsed)) {
                    return $parsed;
                }

                $sections = isset($input['sections']) ? (array) $input['sections'] : array('post', 'meta', 'days', 'terms');

                $result = WTA_Trip_Transfer::import(
                    $parsed['document'],
                    $sections,
                    !empty($input['force']),
                    !isset($input['dry_run']) || (bool) $input['dry_run'],
                    !empty($input['allow_create'])
                );

                if (is_wp_error($result)) {
                    return $result;
                }

                $result['csv_warnings'] = $parsed['warnings'];

                return $result;
            },
        ));
    }

    /**
     * @return array{document: array<string, mixed>, warnings: string[]}|WP_Error
     */
    public static function from_csv($trips_csv, $days_csv = '') {
        $warnings = array();
        $trips    = self::read_csv($trips_csv);

        if (empty($trips['header']) || !in_array('id', $trips['header'], true)) {
            return new WP_Error('wta_csv_no_id', 'trips.csv needs a header row with an "id" column (leave the cells blank to create trips).', array('status' => 400));
        }

        $known = array_merge(self::TRIP_COLUMNS, self::meta_columns(), self::term_columns());

        foreach ($trips['header'] as $column) {
            if ('' !== $column && !in_array($column, $known, true)) {
                $warnings[] = sprintf('trips.csv: column "%s" is not one this plugin knows and was ignored.', $column);
            }
        }

        $days = self::read_days($days_csv, $warnings);

        $entries = array();
        $seen    = array();

        foreach ($trips['rows'] as $line => $row) {
            $id  = absint(isset($row['id']) ? $row['id'] : 0);
            $ref = isset($row['ref']) ? trim($row['ref']) : '';

            $entry = array(
                'id'           => $id,
                'modified_gmt' => isset($row['modified_gmt']) ? $row['modified_gmt'] : '',
                'post'         => array(),
                'meta'         => array(),
                'terms'        => array(),
            );

            foreach (array('slug', 'status') as $key) {
                if (isset($row[$key]) && '' !== $row[$key]) {
                    $entry[$key] = $row[$key];
                }
            }

            foreach (array('title', 'excerpt', 'content') as $key) {
                if (isset($row[$key]) && '' !== $row[$key]) {
                    $entry['post'][$key] = ($row[$key] === self::CLEAR) ? '' : $row[$key];
                }
            }

            foreach ($row as $column => $value) {
                if (0 === strpos($column, 'meta:') && '' !== $value) {
                    $entry['meta'][substr($column, 5)] = (self::CLEAR === $value) ? '' : $value;
                } elseif (0 === strpos($column, 'terms:') && '' !== $value) {
                    $entry['terms'][substr($column, 6)] = (self::CLEAR === $value)
                        ? array()
                        : array_values(array_filter(array_map('trim', explode('|', $value)), 'strlen'));
                }
            }

            $day_rows = null;

            if ($id && isset($days['by_id'][$id])) {
                $day_rows        = $days['by_id'][$id];
                $seen['id:' . $id] = true;
            } elseif (!$id && '' !== $ref && isset($days['by_ref'][$ref])) {
                $day_rows            = $days['by_ref'][$ref];
                $seen['ref:' . $ref] = true;
            }

            if (null !== $day_rows) {
                $entry['days'] = $day_rows;
            }

            $entries[] = $entry;
        }

        foreach ($days['by_id'] as $id => $unused) {
            if (!isset($seen['id:' . $id])) {
                $warnings[] = sprintf('days.csv: trip_id %d has no row in trips.csv, so its days were ignored.', $id);
            }
        }

        foreach ($days['by_ref'] as $ref => $unused) {
            if (!isset($seen['ref:' . $ref])) {
                $warnings[] = sprintf('days.csv: trip_ref "%s" matches no row without an id in trips.csv, so its days were ignored.', $ref);
            }
        }

        return array(
            'document' => array(
                'format'  => WTA_Trip_Transfer::FORMAT,
                'version' => WTA_Trip_Transfer::VERSION,
                'trips'   => $entries,
            ),
            'warnings' => $warnings,
        );
    }

    /**
     * @param string[] $warnings
     * @return array{by_id: array<int, array>, by_ref: array<string, array>}
     */
    protected static function read_days($csv, array &$warnings) {
        $out = array('by_id' => array(), 'by_ref' => array());

        if ('' === trim((string) $csv)) {
            return $out;
        }

        $parsed = self::read_csv($csv);

        foreach ($parsed['header'] as $column) {
            if ('' !== $column && !in_array($column, self::DAY_COLUMNS, true)) {
                $warnings[] = sprintf('days.csv: column "%s" is not one this plugin knows and was ignored.', $column);
            }
        }

        $grouped = array();

        foreach ($parsed['rows'] as $index => $row) {
            $id  = absint(isset($row['trip_id']) ? $row['trip_id'] : 0);
            $ref = isset($row['trip_ref']) ? trim($row['trip_ref']) : '';

            if (!$id && '' === $ref) {
                $warnings[] = sprintf('days.csv: row %d has neither trip_id nor trip_ref and was ignored.', $index + 2);
                continue;
            }

            $key = $id ? 'id:' . $id : 'ref:' . $ref;

            $grouped[$key][] = array(
                'order' => isset($row['day']) && is_numeric($row['day']) ? (float) $row['day'] : (float) (PHP_INT_MAX),
                'seq'   => $index,
                'day'   => array(
                    'label' => isset($row['label']) ? $row['label'] : '',
                    'title' => isset($row['title']) ? $row['title'] : '',
                    'date'  => isset($row['date']) ? $row['date'] : '',
                    'time'  => isset($row['time']) ? $row['time'] : '',
                    'desc'  => isset($row['desc']) ? $row['desc'] : '',
                ),
            );
        }

        foreach ($grouped as $key => $items) {
            usort($items, function ($a, $b) {
                return $a['order'] === $b['order'] ? $a['seq'] - $b['seq'] : ($a['order'] < $b['order'] ? -1 : 1);
            });

            $days = array();

            foreach ($items as $i => $item) {
                if ('' === $item['day']['label']) {
                    $item['day']['label'] = sprintf('Day %d', $i + 1);
                }

                $days[] = $item['day'];
            }

            if (0 === strpos($key, 'id:')) {
                $out['by_id'][(int) substr($key, 3)] = $days;
            } else {
                $out['by_ref'][substr($key, 4)] = $days;
            }
        }

        return $out;
    }

    /**
     * Parse CSV text into a header and associative rows. Handles quoted
     * newlines, a UTF-8 BOM, and comma, semicolon or tab delimiters (European
     * spreadsheets save with semicolons).
     *
     * @return array{header: string[], rows: array<int, array<string, string>>}
     */
    public static function read_csv($text) {
        $text = (string) $text;

        if (0 === strpos($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        $first = strtok($text, "\n");
        $delim = ',';

        if (false !== $first) {
            $counts = array(',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"));
            arsort($counts);
            $delim = (string) key($counts);
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);

        $header = null;
        $rows   = array();

        while (false !== ($cells = fgetcsv($handle, 0, $delim, '"', '\\'))) {
            if (array(null) === $cells) {
                continue;
            }

            $cells = array_map(array(__CLASS__, 'unguard_cell'), $cells);

            if (null === $header) {
                $header = array_map('trim', $cells);
                continue;
            }

            if ('' === trim(implode('', $cells))) {
                continue;
            }

            $row = array();

            foreach ($header as $i => $column) {
                if ('' !== $column) {
                    $row[$column] = isset($cells[$i]) ? $cells[$i] : '';
                }
            }

            $rows[] = $row;
        }

        fclose($handle);

        return array('header' => (array) $header, 'rows' => $rows);
    }
}
