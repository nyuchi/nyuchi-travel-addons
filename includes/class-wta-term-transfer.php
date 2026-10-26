<?php
/**
 * Export and import of destination and activity terms, as JSON or CSV.
 *
 * The same download, edit offline, upload loop as trips, for the structured
 * facts that drive the archive pages: name, archive description, and the
 * fields WTA_Term_Fields defines (country, gateway airport, currency, visa
 * note, best months, duration, difficulty, minimum age and so on).
 *
 * What it will not do, deliberately:
 *
 * - It never creates terms, and never changes a term's slug, parent or
 *   publication state. A slug change breaks the archive URL, a parent change
 *   restructures the tree, and publication state is how a drafted archive goes
 *   public; each already has a dedicated ability.
 * - It writes through WTA_Term_Fields::set(), so a value is sanitised exactly
 *   as it is on the term edit screen.
 * - It refuses a term whose stored values changed after the export (each
 *   exported term carries an etag), unless forced.
 * - Term images are not included; they are media, not text.
 *
 * CSV follows the trip CSV's rules: a blank cell leaves a value unchanged,
 * [clear] clears it, formula-like cells are escaped, and months are written as
 * numbers joined by "|".
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Term_Transfer {

    const FORMAT  = 'nyuchi-travel/term-export';
    const VERSION = 1;

    /** Terms per call, to stay inside a host's time and size limits. */
    const MAX_TERMS = 200;

    /** Fixed leading columns of terms.csv. */
    const COLUMNS = array('taxonomy', 'id', 'slug', 'parent_slug', 'status', 'etag', 'name', 'description');

    public static function register() {
        if (!class_exists('WTA_Term_Fields')) {
            return;
        }

        self::register_export();
        self::register_import();
    }

    /** @return string[] */
    protected static function taxonomies() {
        return array_values(array_intersect(WTA_Term_Fields::taxonomies(), array_keys((array) WTA_Trip::default_taxonomies())));
    }

    /**
     * Per-term capability is checked inside; this gate only turns away users
     * who cannot manage terms at all.
     *
     * @return true|WP_Error
     */
    public static function can_manage_terms() {
        if (!current_user_can('manage_categories')) {
            return new WP_Error('wta_forbidden', 'You are not allowed to manage trip taxonomy terms.', array('status' => 403));
        }

        return true;
    }

    /* --------------------------------------------------------------- export */

    protected static function register_export() {
        $taxonomies = self::taxonomies();
        $input      = array(
            'type'       => 'object',
            'properties' => array(
                'taxonomy' => array(
                    'type'        => 'string',
                    'description' => 'Which terms to export. Defaults to all of them.',
                    'enum'        => array_merge($taxonomies, array('all')),
                ),
                'term_ids' => array(
                    'type'        => 'array',
                    'description' => 'Limit to these term IDs.',
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'maxItems'    => self::MAX_TERMS,
                ),
            ),
        );

        wp_register_ability(WTA_Travel_Abilities::NS . 'export-terms', array(
            'label'       => 'Export destination and activity terms as JSON',
            'description' => 'Download destination and activity terms (up to ' . self::MAX_TERMS . ' per call) with their name, archive description and structured fields: country, gateway airport, languages, currency, visa note, best months, standout and long description for destinations; duration, difficulty, minimum age, typical cost, permit, best months, standout and long description for activities. Slug, parent and publication state are included for context and ignored on import. Edit the document, then pass it to import-terms.',
            'category'    => WTA_Travel_Abilities::CATEGORY,
            'input_schema' => $input,
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'format'      => array('type' => 'string'),
                    'version'     => array('type' => 'integer'),
                    'exported_at' => array('type' => 'string'),
                    'terms'       => array('type' => 'array', 'items' => array('type' => 'object')),
                    'rejected'    => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
                'required'   => array('format', 'version', 'terms', 'rejected'),
            ),
            'permission_callback' => array(__CLASS__, 'can_manage_terms'),
            'execute_callback'    => function ($in) {
                return self::export(
                    isset($in['taxonomy']) ? (string) $in['taxonomy'] : 'all',
                    isset($in['term_ids']) ? (array) $in['term_ids'] : array()
                );
            },
        ));

        wp_register_ability(WTA_Travel_Abilities::NS . 'export-terms-csv', array(
            'label'       => 'Export destination and activity terms as CSV',
            'description' => 'The same terms as export-terms, as one terms.csv for a spreadsheet: one row per term, a column per field named field:<key>. Months are numbers joined by "|"; permit_required is yes or no. Blank cell keeps a value, [clear] clears it. Pass the edited file to import-terms-csv.',
            'category'    => WTA_Travel_Abilities::CATEGORY,
            'input_schema' => $input,
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'terms_csv' => array('type' => 'string', 'description' => 'Contents of terms.csv (UTF-8 with BOM, for Excel).'),
                    'rejected'  => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
                'required'   => array('terms_csv', 'rejected'),
            ),
            'permission_callback' => array(__CLASS__, 'can_manage_terms'),
            'execute_callback'    => function ($in) {
                $doc = self::export(
                    isset($in['taxonomy']) ? (string) $in['taxonomy'] : 'all',
                    isset($in['term_ids']) ? (array) $in['term_ids'] : array()
                );

                if (is_wp_error($doc)) {
                    return $doc;
                }

                return array('terms_csv' => self::to_csv($doc), 'rejected' => $doc['rejected']);
            },
        ));
    }

    /**
     * @param int[] $term_ids
     * @return array<string, mixed>|WP_Error
     */
    public static function export($taxonomy = 'all', array $term_ids = array()) {
        $allowed = self::taxonomies();

        if ('all' !== $taxonomy && !in_array($taxonomy, $allowed, true)) {
            return new WP_Error('wta_bad_taxonomy', sprintf('taxonomy must be one of: %s, all.', implode(', ', $allowed)), array('status' => 400));
        }

        $taxonomies = 'all' === $taxonomy ? $allowed : array($taxonomy);
        $term_ids   = array_values(array_unique(array_filter(array_map('absint', $term_ids))));
        $rejected   = array();
        $terms      = array();

        if ($term_ids) {
            foreach ($term_ids as $term_id) {
                $term = get_term($term_id);

                if (!$term instanceof WP_Term || !in_array($term->taxonomy, $taxonomies, true)) {
                    $rejected[] = sprintf('%d: not a term in %s.', $term_id, implode(', ', $taxonomies));
                    continue;
                }

                $terms[] = $term;
            }
        } else {
            foreach ($taxonomies as $tax) {
                $found = get_terms(array('taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name', 'number' => self::MAX_TERMS + 1));

                if (!is_wp_error($found)) {
                    $terms = array_merge($terms, $found);
                }
            }
        }

        if (count($terms) > self::MAX_TERMS) {
            return new WP_Error(
                'wta_too_many_terms',
                sprintf('More than %d terms match. Export one taxonomy at a time, or pass term_ids.', self::MAX_TERMS),
                array('status' => 400)
            );
        }

        return array(
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'terms'       => array_map(array(__CLASS__, 'export_one'), $terms),
            'rejected'    => $rejected,
        );
    }

    protected static function export_one(WP_Term $term) {
        $parent = $term->parent ? get_term((int) $term->parent, $term->taxonomy) : null;
        $fields = WTA_Term_Fields::all($term->term_id);

        return array(
            'id'          => (int) $term->term_id,
            'taxonomy'    => $term->taxonomy,
            'slug'        => $term->slug,
            'parent_slug' => $parent instanceof WP_Term ? $parent->slug : '',
            'status'      => class_exists('WTA_Term_Status') ? WTA_Term_Status::get_status($term->term_id) : '',
            'etag'        => self::etag($term->name, $term->description, $fields),
            'name'        => $term->name,
            'description' => $term->description,
            'fields'      => $fields,
        );
    }

    /**
     * A fingerprint of everything import can write, so a stale upload can be
     * told from a current one without terms having a modified date.
     *
     * @param array<string, mixed> $fields
     */
    protected static function etag($name, $description, array $fields) {
        ksort($fields);

        return substr(md5(wp_json_encode(array((string) $name, (string) $description, $fields))), 0, 12);
    }

    /* --------------------------------------------------------------- import */

    protected static function register_import() {
        $schema = array(
            'force'   => array('type' => 'boolean', 'description' => 'Apply even if a term changed after the export. Overwrites that later edit. Defaults to false.'),
            'dry_run' => array('type' => 'boolean', 'description' => 'Defaults to true. Must be explicitly false to write.'),
        );
        $output = array(
            'type'       => 'object',
            'properties' => array(
                'dry_run'      => array('type' => 'boolean', 'description' => 'True means nothing was written.'),
                'applied'      => array('type' => 'array', 'items' => array('type' => 'integer')),
                'terms'        => array('type' => 'array', 'items' => array('type' => 'object'), 'description' => 'Per term: id, status (changed, unchanged or skipped), what changed, warnings, reason.'),
                'csv_warnings' => array('type' => 'array', 'items' => array('type' => 'string')),
                'note'         => array('type' => 'string'),
            ),
            'required'   => array('dry_run', 'applied', 'terms', 'note'),
        );

        wp_register_ability(WTA_Travel_Abilities::NS . 'import-terms', array(
            'label'       => 'Import edited terms from JSON',
            'description' => 'Apply a document produced by export-terms. Updates only: it never creates terms and never changes a slug, parent or publication state. Writes name, archive description and the structured fields, sanitised exactly as the term edit screen does. A term changed since the export is refused unless force is true. Defaults to a dry run.',
            'category'    => WTA_Travel_Abilities::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array_merge(array('document' => array('type' => 'object', 'description' => 'The export-terms output, edited.')), $schema),
                'required'   => array('document'),
            ),
            'output_schema'       => $output,
            'permission_callback' => array(__CLASS__, 'can_manage_terms'),
            'execute_callback'    => function ($in) {
                return self::import(
                    isset($in['document']) ? $in['document'] : null,
                    !empty($in['force']),
                    !isset($in['dry_run']) || (bool) $in['dry_run']
                );
            },
        ));

        wp_register_ability(WTA_Travel_Abilities::NS . 'import-terms-csv', array(
            'label'       => 'Import edited terms from CSV',
            'description' => 'Apply terms.csv from export-terms-csv. A blank cell leaves a value unchanged; [clear] clears a field. Same safeguards as import-terms: dry run by default, no term creation, no slug, parent or status changes, stale terms refused unless force.',
            'category'    => WTA_Travel_Abilities::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array_merge(array('terms_csv' => array('type' => 'string', 'description' => 'Contents of terms.csv.')), $schema),
                'required'   => array('terms_csv'),
            ),
            'output_schema'       => $output,
            'permission_callback' => array(__CLASS__, 'can_manage_terms'),
            'execute_callback'    => function ($in) {
                $parsed = self::from_csv(isset($in['terms_csv']) ? (string) $in['terms_csv'] : '');

                if (is_wp_error($parsed)) {
                    return $parsed;
                }

                $result = self::import($parsed['document'], !empty($in['force']), !isset($in['dry_run']) || (bool) $in['dry_run']);

                if (is_wp_error($result)) {
                    return $result;
                }

                $result['csv_warnings'] = $parsed['warnings'];

                return $result;
            },
        ));
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public static function import($document, $force, $dry) {
        if (!is_array($document) || !isset($document['format']) || self::FORMAT !== $document['format']) {
            return new WP_Error('wta_bad_document', 'document is not an export-terms document (format mismatch).', array('status' => 400));
        }

        if (!isset($document['version']) || (int) $document['version'] > self::VERSION) {
            return new WP_Error('wta_bad_version', 'document version is missing or newer than this plugin understands.', array('status' => 400));
        }

        if (empty($document['terms']) || !is_array($document['terms'])) {
            return new WP_Error('wta_no_terms', 'document has no terms to import.', array('status' => 400));
        }

        if (count($document['terms']) > self::MAX_TERMS) {
            return new WP_Error('wta_too_many_terms', sprintf('At most %d terms per call.', self::MAX_TERMS), array('status' => 400));
        }

        $report  = array();
        $applied = array();

        foreach ($document['terms'] as $entry) {
            $row = self::import_one(is_array($entry) ? $entry : array(), $force, $dry);

            if ('changed' === $row['status'] && !$dry) {
                $applied[] = $row['id'];
            }

            unset($row['plan']);
            $report[] = $row;
        }

        return array(
            'dry_run' => $dry,
            'applied' => $applied,
            'terms'   => $report,
            'note'    => $dry
                ? 'Nothing was written. Call again with dry_run false to apply the terms marked "changed".'
                : 'Written. Purge Cloudflare and Varnish if an archive page shows stale text.',
        );
    }

    protected static function import_one(array $entry, $force, $dry) {
        $term_id = isset($entry['id']) ? absint($entry['id']) : 0;
        $row     = array('id' => $term_id, 'status' => 'skipped', 'changed' => array(), 'warnings' => array(), 'reason' => '');
        $term    = $term_id ? get_term($term_id) : null;

        if (!$term instanceof WP_Term) {
            $row['reason'] = $term_id
                ? sprintf('No term exists with ID %d. Import never creates terms; use create-term first.', $term_id)
                : 'The entry has no id. Import never creates terms; use create-term first.';

            return $row;
        }

        if (!in_array($term->taxonomy, self::taxonomies(), true)) {
            $row['reason'] = sprintf('Taxonomy "%s" is not managed by this plugin.', $term->taxonomy);

            return $row;
        }

        if (!current_user_can('edit_term', $term_id)) {
            $row['reason'] = 'You are not allowed to edit this term.';

            return $row;
        }

        if (!empty($entry['taxonomy']) && (string) $entry['taxonomy'] !== $term->taxonomy) {
            $row['reason'] = sprintf('The entry says taxonomy "%s" but term %d is in "%s". Refusing: the id and taxonomy disagree.', $entry['taxonomy'], $term_id, $term->taxonomy);

            return $row;
        }

        $current = self::export_one($term);

        if (!$force) {
            if (empty($entry['etag'])) {
                $row['reason'] = 'The entry has no etag, so edits since the export cannot be ruled out. Pass force to apply anyway.';

                return $row;
            }

            if ((string) $entry['etag'] !== $current['etag']) {
                $row['reason'] = 'The term changed after the export. Export it again, or pass force to overwrite that edit.';

                return $row;
            }
        }

        foreach (array('slug', 'parent_slug', 'status') as $ignored) {
            if (isset($entry[$ignored]) && '' !== (string) $entry[$ignored] && (string) $entry[$ignored] !== (string) $current[$ignored]) {
                $row['warnings'][] = sprintf('%s differs from the live term and was ignored: import never changes slug, parent or status.', $ignored);
            }
        }

        $plan  = array();
        $args  = array();
        $known = WTA_Term_Fields::fields($term->taxonomy);

        if (array_key_exists('name', $entry) && '' !== trim((string) $entry['name'])) {
            $name = sanitize_text_field((string) $entry['name']);

            if ($name !== $term->name) {
                $args['name'] = $name;
            }
        }

        if (array_key_exists('description', $entry)) {
            $description = wp_kses_post((string) $entry['description']);

            if ($description !== $term->description) {
                $args['description'] = $description;
            }
        }

        if ($args) {
            $plan['term'] = $args;
        }

        if (isset($entry['fields'])) {
            if (!is_array($entry['fields'])) {
                $row['warnings'][] = 'fields: expected an object, skipped.';
            } else {
                foreach ($entry['fields'] as $key => $value) {
                    $key = sanitize_key($key);

                    if (!isset($known[$key])) {
                        $row['warnings'][] = sprintf('fields.%s: not a field of the %s taxonomy, ignored.', $key, $term->taxonomy);
                        continue;
                    }

                    // Normalise the new value the way set() will, and compare
                    // with what is stored, so an unchanged field is not noise.
                    $clean = call_user_func(array('WTA_Term_Fields', $known[$key]['sanitize']), $value);

                    $was = array_key_exists($key, $current['fields']) ? $current['fields'][$key] : null;

                    if ($clean !== $was) {
                        $plan['fields'][$key] = $value;
                    }
                }
            }
        }

        $row['changed'] = array_merge(
            array_keys(isset($plan['term']) ? $plan['term'] : array()),
            array_map(function ($key) {
                return 'fields.' . $key;
            }, array_keys(isset($plan['fields']) ? $plan['fields'] : array()))
        );
        $row['status'] = $plan ? 'changed' : 'unchanged';
        $row['plan']   = $plan;

        if ($dry || !$plan) {
            return $row;
        }

        if (isset($plan['term'])) {
            $updated = wp_update_term($term_id, $term->taxonomy, $plan['term']);

            if (is_wp_error($updated)) {
                $row['status'] = 'skipped';
                $row['reason'] = $updated->get_error_message();

                return $row;
            }
        }

        foreach (isset($plan['fields']) ? $plan['fields'] : array() as $key => $value) {
            WTA_Term_Fields::set($term_id, $key, $value);
        }

        clean_term_cache($term_id, $term->taxonomy);

        return $row;
    }

    /* ------------------------------------------------------------------ CSV */

    /**
     * Every field key across the managed taxonomies, in a stable order.
     *
     * @return string[]
     */
    protected static function field_keys() {
        $keys = array();

        foreach (self::taxonomies() as $taxonomy) {
            foreach (array_keys(WTA_Term_Fields::fields($taxonomy)) as $key) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    /**
     * @param array<string, mixed> $doc An export-terms document.
     */
    public static function to_csv(array $doc) {
        $keys   = self::field_keys();
        $header = array_merge(self::COLUMNS, array_map(function ($key) {
            return 'field:' . $key;
        }, $keys));
        $rows   = array($header);

        foreach ($doc['terms'] as $term) {
            $known = WTA_Term_Fields::fields($term['taxonomy']);
            $row   = array();

            foreach (self::COLUMNS as $column) {
                $row[] = WTA_Trip_CSV::guard_cell(isset($term[$column]) ? $term[$column] : '');
            }

            foreach ($keys as $key) {
                // A field that does not apply to this taxonomy is left blank,
                // which import reads as "unchanged".
                if (!isset($known[$key])) {
                    $row[] = '';
                    continue;
                }

                $row[] = WTA_Trip_CSV::guard_cell(self::field_to_cell($known[$key], isset($term['fields'][$key]) ? $term['fields'][$key] : ''));
            }

            $rows[] = $row;
        }

        return "\xEF\xBB\xBF" . WTA_Trip_CSV::write_csv($rows);
    }

    /**
     * @param array<string, mixed> $field
     * @param mixed                $value
     */
    protected static function field_to_cell(array $field, $value) {
        switch ($field['control']) {
            case 'months':
                return implode('|', array_map('intval', (array) $value));

            case 'checkbox':
                return $value ? 'yes' : 'no';

            default:
                return (string) $value;
        }
    }

    /**
     * @return array{document: array<string, mixed>, warnings: string[]}|WP_Error
     */
    public static function from_csv($csv) {
        $parsed = WTA_Trip_CSV::read_csv($csv);

        if (!in_array('id', $parsed['header'], true)) {
            return new WP_Error('wta_csv_no_id', 'terms.csv needs a header row with an "id" column.', array('status' => 400));
        }

        $warnings = array();
        $keys     = self::field_keys();
        $known    = array_merge(self::COLUMNS, array_map(function ($key) {
            return 'field:' . $key;
        }, $keys));

        foreach ($parsed['header'] as $column) {
            if ('' !== $column && !in_array($column, $known, true)) {
                $warnings[] = sprintf('terms.csv: column "%s" is not one this plugin knows and was ignored.', $column);
            }
        }

        $terms = array();

        foreach ($parsed['rows'] as $row) {
            $entry = array(
                'id'     => absint(isset($row['id']) ? $row['id'] : 0),
                'fields' => array(),
            );

            foreach (array('taxonomy', 'slug', 'parent_slug', 'status', 'etag') as $column) {
                if (isset($row[$column]) && '' !== $row[$column]) {
                    $entry[$column] = $row[$column];
                }
            }

            foreach (array('name', 'description') as $column) {
                if (isset($row[$column]) && '' !== $row[$column]) {
                    $entry[$column] = (WTA_Trip_CSV::CLEAR === $row[$column]) ? '' : $row[$column];
                }
            }

            foreach ($row as $column => $value) {
                if (0 !== strpos($column, 'field:') || '' === $value) {
                    continue;
                }

                $key = substr($column, 6);

                if (WTA_Trip_CSV::CLEAR === $value) {
                    $entry['fields'][$key] = '';
                } elseif (false !== strpos($value, '|')) {
                    $entry['fields'][$key] = explode('|', $value);
                } else {
                    $entry['fields'][$key] = $value;
                }
            }

            $terms[] = $entry;
        }

        return array(
            'document' => array('format' => self::FORMAT, 'version' => self::VERSION, 'terms' => $terms),
            'warnings' => $warnings,
        );
    }
}
