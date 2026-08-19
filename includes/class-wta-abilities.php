<?php
/**
 * The plugin's operations, described so a machine can call them.
 *
 * The REST layer in class-wta-rest.php already exposes everything here, but a
 * REST route only tells a client what shape the request must take. It does not
 * say what the operation is for, when it is the right one to reach for, or what
 * the returned numbers mean. An AI client picking between "list terms" and
 * "audit classification" needs that context to choose correctly.
 *
 * The Abilities API is that description layer. Each ability below wraps the
 * same code the REST route calls — the sanitisers on WTA_Itinerary_Schema, the
 * status writer on WTA_Term_Status, the audit and compat scanners — and adds a
 * JSON Schema whose descriptions are written for a reader that has never seen
 * this plugin. Nothing here reimplements behaviour; duplicated logic would drift
 * from the REST surface and the two would eventually disagree.
 *
 * The Abilities API ships separately from WordPress core. Every call into it is
 * guarded with function_exists(), so on a site without it this file registers
 * nothing, throws nothing, and costs two no-op hook callbacks.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Abilities {

    /** Category every ability in this plugin is filed under. */
    const CATEGORY = 'nyuchi-travel';

    /** Ability name prefix. Names are "namespace/ability-name", kebab-case. */
    const NS = 'nyuchi-travel/';

    public function __construct() {
        // Categories must exist before abilities reference them, which is why
        // the API fires two separate actions rather than one.
        add_action('wp_abilities_api_categories_init', array($this, 'register_categories'));
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    /* ---------------------------------------------------------- registration */

    public function register_categories() {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(self::CATEGORY, array(
            'label'       => 'Nyuchi Travel',
            'description' => 'Read and write WP Travel trip content: the structured itinerary (legs, route stops, month-by-month suitability, cost tiers), the taxonomy terms trips are classified under and their live/draft state, plus diagnostics that report classification problems and trips whose content would crash the vendor itinerary widget.',
        ));
    }

    public function register_abilities() {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        // The itinerary abilities read WTA_Itinerary_Schema::fields() at
        // registration time to build their schemas, so they are skipped
        // outright if that class is not loaded rather than fataling here.
        if (class_exists('WTA_Itinerary_Schema')) {
            $this->register_get_trip();
            $this->register_update_itinerary();
        }

        $this->register_list_terms();
        $this->register_set_term_status();
        $this->register_create_term();
        $this->register_audit_classification();
        $this->register_scan_compat();
    }

    /* ----------------------------------------------------------- get-trip */

    protected function register_get_trip() {
        wp_register_ability(self::NS . 'get-trip', array(
            'label'       => 'Get trip itinerary',
            'description' => 'Return everything known about one trip in a single call: the authored itinerary structures, WP Travel\'s own day-by-day list, and the normalised trip facts (price, duration, group size, location). Use this before editing a trip so the existing content is not overwritten blind.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_id' => array(
                        'type'        => 'integer',
                        'description' => 'Post ID of the trip. This is the WP Travel trip post type, not a page or post.',
                        'minimum'     => 1,
                    ),
                ),
                'required'   => array('trip_id'),
            ),

            'output_schema' => $this->trip_output_schema(),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $trip_id = isset($input['trip_id']) ? absint($input['trip_id']) : 0;
                $check   = self::require_trip($trip_id);

                if (is_wp_error($check)) {
                    return $check;
                }

                // for_trip() is the same reader the templates and Elementor
                // widgets use, so an ability sees exactly what the front end
                // sees — including the fallbacks for an unauthored trip.
                return WTA_Itinerary_Schema::for_trip($trip_id);
            },
        ));
    }

    /* --------------------------------------------------- update-itinerary */

    protected function register_update_itinerary() {
        $fields = WTA_Itinerary_Schema::fields();

        $properties = array(
            'trip_id' => array(
                'type'        => 'integer',
                'description' => 'Post ID of the trip to write to.',
                'minimum'     => 1,
            ),
        );

        // One optional property per authored group, carrying the same schema
        // the REST field advertises. Omitting a group leaves it untouched:
        // a partial update must not blank out the groups it does not mention.
        foreach ($fields as $field) {
            $properties[$field['rest_key']] = array_merge(
                array('description' => $field['description'] . ' Omit this property to leave the stored value unchanged.'),
                $field['schema']
            );
        }

        wp_register_ability(self::NS . 'update-itinerary', array(
            'label'       => 'Update trip itinerary',
            'description' => 'Write one or more itinerary groups onto a trip. Every supplied group is sanitised before it is stored — unknown keys are dropped, scores and coordinates are clamped to their valid ranges, and seasonality is always normalised to twelve months. Groups that are not supplied keep their existing values, so this is safe to call with a single group.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => $properties,
                'required'   => array('trip_id'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'updated' => array(
                        'type'        => 'array',
                        'description' => 'Group names that were sanitised and written to the trip.',
                        'items'       => array('type' => 'string'),
                    ),
                    'skipped' => array(
                        'type'        => 'array',
                        'description' => 'Group names that were supplied but rejected, because the value was not the object or array the group expects.',
                        'items'       => array('type' => 'string'),
                    ),
                ),
                'required'   => array('updated', 'skipped'),
            ),

            // The capability is per-post and the post is an input, so it cannot
            // be decided here; the callback checks edit_post on the id it is
            // given. This gate only rejects users who cannot edit anything.
            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) use ($fields) {
                $trip_id = isset($input['trip_id']) ? absint($input['trip_id']) : 0;
                $check   = self::require_trip($trip_id);

                if (is_wp_error($check)) {
                    return $check;
                }

                if (!current_user_can('edit_post', $trip_id)) {
                    return new WP_Error(
                        'wta_forbidden',
                        sprintf('You are not allowed to edit trip %d.', $trip_id),
                        array('status' => 403)
                    );
                }

                $updated = array();
                $skipped = array();

                foreach ($fields as $meta_key => $field) {
                    $rest_key = $field['rest_key'];

                    if (!array_key_exists($rest_key, $input)) {
                        continue;
                    }

                    $value = $input[$rest_key];

                    // Every sanitiser returns an empty array for a non-array
                    // input, which would silently erase the group. Reporting it
                    // as skipped is more useful than a successful wipe.
                    if (!is_array($value)) {
                        $skipped[] = $rest_key;
                        continue;
                    }

                    $clean = call_user_func(array('WTA_Itinerary_Schema', $field['sanitize']), $value);

                    if (is_wp_error($clean)) {
                        return $clean;
                    }

                    update_post_meta($trip_id, $meta_key, $clean);
                    $updated[] = $rest_key;
                }

                return array(
                    'updated' => $updated,
                    'skipped' => $skipped,
                );
            },
        ));
    }

    /* --------------------------------------------------------- list-terms */

    protected function register_list_terms() {
        wp_register_ability(self::NS . 'list-terms', array(
            'label'       => 'List trip taxonomy terms',
            'description' => 'List the terms of one trip taxonomy with their publication state. Use it to find the term ID for a classification change, or to see which parts of a destination tree are still drafted and therefore invisible to visitors.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy' => array(
                        'type'        => 'string',
                        'description' => 'Slug of the trip taxonomy to list. Restricted to the taxonomies WP Travel registers against trips; categories, tags and other plugins\' taxonomies are refused.',
                        'enum'        => array_keys(WTA_Trip::default_taxonomies()),
                    ),
                    'status'   => array(
                        'type'        => 'string',
                        'description' => 'Filter by publication state. "live" is public and indexable, "draft" is assignable but 404s on its archive, "any" returns both.',
                        'enum'        => array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT, 'any'),
                        'default'     => 'any',
                    ),
                    'per_page' => array(
                        'type'        => 'integer',
                        'description' => 'Maximum number of terms to return. Capped so a large destination tree cannot time the request out.',
                        'minimum'     => 1,
                        'maximum'     => 500,
                        'default'     => 100,
                    ),
                ),
                'required'   => array('taxonomy'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'terms' => array(
                        'type'        => 'array',
                        'description' => 'Terms in the requested taxonomy, ordered by name.',
                        'items'       => $this->term_schema(),
                    ),
                    'total' => array(
                        'type'        => 'integer',
                        'description' => 'Number of terms returned. This is the size of the returned page, not the size of the taxonomy.',
                    ),
                ),
                'required'   => array('terms', 'total'),
            ),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $taxonomy = isset($input['taxonomy']) ? sanitize_key($input['taxonomy']) : '';
                $check    = self::require_taxonomy($taxonomy);

                if (is_wp_error($check)) {
                    return $check;
                }

                $status   = isset($input['status']) ? sanitize_key($input['status']) : 'any';
                $per_page = isset($input['per_page']) ? absint($input['per_page']) : 100;
                $per_page = min(500, max(1, $per_page));

                $args = array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'number'     => $per_page,
                    'orderby'    => 'name',
                );

                $meta_query = self::status_meta_query($status);

                if ($meta_query) {
                    $args['meta_query'] = $meta_query;
                }

                $terms = get_terms($args);

                if (is_wp_error($terms)) {
                    return new WP_Error(
                        'wta_term_query_failed',
                        $terms->get_error_message(),
                        array('status' => 400)
                    );
                }

                $out = array();

                foreach ($terms as $term) {
                    $out[] = self::prepare_term($term);
                }

                return array(
                    'terms' => $out,
                    'total' => count($out),
                );
            },
        ));
    }

    /* ---------------------------------------------------- set-term-status */

    protected function register_set_term_status() {
        wp_register_ability(self::NS . 'set-term-status', array(
            'label'       => 'Set term publication state',
            'description' => 'Move terms between live and draft in bulk. Drafting a term keeps it editable and assignable but makes its archive return 404 and removes it from menus and term listings, which is how a destination tree can be built out before the content that fills it exists.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_ids' => array(
                        'type'        => 'array',
                        'description' => 'Term IDs to update. Each is checked individually; one bad ID does not abort the rest.',
                        'items'       => array('type' => 'integer'),
                        'minItems'    => 1,
                    ),
                    'status'   => array(
                        'type'        => 'string',
                        'description' => 'State to apply to every listed term. "live" makes the archive public and indexable; "draft" hides it from visitors.',
                        'enum'        => array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT),
                    ),
                ),
                'required'   => array('term_ids', 'status'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'updated' => array(
                        'type'        => 'integer',
                        'description' => 'How many terms were actually changed. Compare against the number of IDs sent to detect partial success.',
                    ),
                    'results' => array(
                        'type'        => 'array',
                        'description' => 'One entry per requested ID, in the order supplied.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'term_id' => array(
                                    'type'        => 'integer',
                                    'description' => 'The ID as requested, so results can be matched back to the input.',
                                ),
                                'status'  => array(
                                    'type'        => 'string',
                                    'description' => 'State the term is in after the call. On failure this is the unchanged current state, or empty if the term does not exist.',
                                ),
                                'ok'      => array(
                                    'type'        => 'boolean',
                                    'description' => 'Whether this term was updated.',
                                ),
                                'message' => array(
                                    'type'        => 'string',
                                    'description' => 'Why the term was skipped. Empty on success.',
                                ),
                            ),
                        ),
                    ),
                ),
                'required'   => array('updated', 'results'),
            ),

            'permission_callback' => array($this, 'can_manage_categories'),

            'execute_callback' => function ($input) {
                $module = self::require_module('term_status');

                if (is_wp_error($module)) {
                    return $module;
                }

                $status = isset($input['status']) ? sanitize_key($input['status']) : '';

                if (!in_array($status, array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT), true)) {
                    return new WP_Error(
                        'wta_bad_status',
                        'status must be "live" or "draft".',
                        array('status' => 400)
                    );
                }

                $term_ids = isset($input['term_ids']) ? (array) $input['term_ids'] : array();

                if (empty($term_ids)) {
                    return new WP_Error(
                        'wta_no_terms',
                        'term_ids must contain at least one term ID.',
                        array('status' => 400)
                    );
                }

                $allowed = WTA_Trip::default_taxonomies();
                $results = array();
                $updated = 0;

                foreach ($term_ids as $raw_id) {
                    $term_id = absint($raw_id);
                    $term    = $term_id ? get_term($term_id) : null;

                    if (!$term instanceof WP_Term) {
                        $results[] = array(
                            'term_id' => $term_id,
                            'status'  => '',
                            'ok'      => false,
                            'message' => 'No such term.',
                        );
                        continue;
                    }

                    // The same narrowing the REST route applies: a guessed ID
                    // must not be able to reach a term outside the trip
                    // taxonomies this plugin is responsible for.
                    if (!array_key_exists($term->taxonomy, $allowed)) {
                        $results[] = array(
                            'term_id' => $term_id,
                            'status'  => WTA_Term_Status::get_status($term_id),
                            'ok'      => false,
                            'message' => sprintf('Taxonomy "%s" is not managed by this plugin.', $term->taxonomy),
                        );
                        continue;
                    }

                    WTA_Term_Status::set_status($term_id, $status);
                    $updated++;

                    $results[] = array(
                        'term_id' => $term_id,
                        'status'  => WTA_Term_Status::get_status($term_id),
                        'ok'      => true,
                        'message' => '',
                    );
                }

                return array(
                    'updated' => $updated,
                    'results' => $results,
                );
            },
        ));
    }

    /* -------------------------------------------------------- create-term */

    protected function register_create_term() {
        wp_register_ability(self::NS . 'create-term', array(
            'label'       => 'Create a trip taxonomy term',
            'description' => 'Add a term to one of the trip taxonomies. New terms are drafted by default, so a term created by an API client does not become a public, empty archive before anyone has looked at it. If a matching term already exists the call fails and returns its ID, which can be used directly instead of creating a duplicate.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy'    => array(
                        'type'        => 'string',
                        'description' => 'Trip taxonomy the term belongs to.',
                        'enum'        => array_keys(WTA_Trip::default_taxonomies()),
                    ),
                    'name'        => array(
                        'type'        => 'string',
                        'description' => 'Display name, as an editor would type it. Must not be empty.',
                        'minLength'   => 1,
                    ),
                    'slug'        => array(
                        'type'        => 'string',
                        'description' => 'URL segment for the term archive. Leave empty to derive it from the name.',
                        'default'     => '',
                    ),
                    'parent'      => array(
                        'type'        => 'integer',
                        'description' => 'Term ID to nest under. Zero creates a top-level term. The parent must already exist in the same taxonomy.',
                        'minimum'     => 0,
                        'default'     => 0,
                    ),
                    'description' => array(
                        'type'        => 'string',
                        'description' => 'Archive description. Limited HTML is preserved; anything else is stripped.',
                        'default'     => '',
                    ),
                    'status'      => array(
                        'type'        => 'string',
                        'description' => 'Publication state to create the term in. Defaults to draft so nothing becomes publicly indexable by accident.',
                        'enum'        => array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT),
                        'default'     => WTA_Term_Status::DRAFT,
                    ),
                ),
                'required'   => array('taxonomy', 'name'),
            ),

            'output_schema' => $this->term_schema(),

            'permission_callback' => array($this, 'can_manage_categories'),

            'execute_callback' => function ($input) {
                $taxonomy = isset($input['taxonomy']) ? sanitize_key($input['taxonomy']) : '';
                $check    = self::require_taxonomy($taxonomy);

                if (is_wp_error($check)) {
                    return $check;
                }

                $name = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';

                if ('' === trim($name)) {
                    return new WP_Error(
                        'wta_empty_value',
                        'name cannot be empty.',
                        array('status' => 400)
                    );
                }

                $parent = isset($input['parent']) ? absint($input['parent']) : 0;

                if ($parent && !get_term($parent, $taxonomy) instanceof WP_Term) {
                    return new WP_Error(
                        'wta_bad_parent',
                        sprintf('The parent term %d does not exist in %s.', $parent, $taxonomy),
                        array('status' => 400)
                    );
                }

                $existing = term_exists($name, $taxonomy, $parent ? $parent : null);

                if ($existing) {
                    // The caller almost always wants to use the existing term
                    // rather than retry, so its ID travels with the error.
                    return new WP_Error(
                        'wta_term_exists',
                        sprintf('A term named "%s" already exists in %s.', $name, $taxonomy),
                        array(
                            'status'  => 409,
                            'term_id' => isset($existing['term_id']) ? (int) $existing['term_id'] : (int) $existing,
                        )
                    );
                }

                $created = wp_insert_term($name, $taxonomy, array(
                    'slug'        => isset($input['slug']) ? sanitize_title((string) $input['slug']) : '',
                    'parent'      => $parent,
                    'description' => isset($input['description']) ? wp_kses_post((string) $input['description']) : '',
                ));

                if (is_wp_error($created)) {
                    // A slug collision surfaces here rather than in
                    // term_exists(), and the caller still wants the id of
                    // whatever is in the way.
                    $conflict = $created->get_error_data();

                    return new WP_Error(
                        'wta_term_not_created',
                        $created->get_error_message(),
                        array(
                            'status'  => 'term_exists' === $created->get_error_code() ? 409 : 400,
                            'term_id' => is_scalar($conflict) ? (int) $conflict : 0,
                        )
                    );
                }

                $status = isset($input['status']) ? sanitize_key($input['status']) : WTA_Term_Status::DRAFT;

                if (!in_array($status, array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT), true)) {
                    $status = WTA_Term_Status::DRAFT;
                }

                WTA_Term_Status::set_status($created['term_id'], $status);

                return self::prepare_term(get_term($created['term_id'], $taxonomy));
            },
        ));
    }

    /* ----------------------------------------------- audit-classification */

    protected function register_audit_classification() {
        wp_register_ability(self::NS . 'audit-classification', array(
            'label'       => 'Audit trip classification',
            'description' => 'Report structural problems in how trips are classified: taxonomies that are flat where they should be nested, terms with no trips attached, terms applied so widely they no longer segment anything, near-duplicate names, and the same concept duplicated across two taxonomies. Findings are advisory — nothing is changed.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomies' => array(
                        'type'        => 'array',
                        'description' => 'Restrict the audit to these taxonomy slugs. Omit to audit every registered trip taxonomy.',
                        'items'       => array(
                            'type' => 'string',
                            'enum' => array_keys(WTA_Trip::default_taxonomies()),
                        ),
                    ),
                ),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'findings' => array(
                        'type'        => 'array',
                        'description' => 'Individual problems, worst first. Each carries a severity, the taxonomy and terms involved, and a human-readable explanation.',
                        'items'       => array('type' => 'object'),
                    ),
                    'summary'  => array(
                        'type'        => 'object',
                        'description' => 'Finding counts by severity, for a headline before reading the detail.',
                        'properties'  => array(
                            'high'   => array('type' => 'integer', 'description' => 'Problems that are actively misleading visitors or search engines.'),
                            'medium' => array('type' => 'integer', 'description' => 'Problems that weaken navigation but are not breaking anything.'),
                            'low'    => array('type' => 'integer', 'description' => 'Tidying: near-duplicates and unused terms.'),
                            'total'  => array('type' => 'integer', 'description' => 'All findings, regardless of severity.'),
                        ),
                        'required'    => array('high', 'medium', 'low', 'total'),
                    ),
                ),
                'required'   => array('findings', 'summary'),
            ),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $module = self::require_module('audit');

                if (is_wp_error($module)) {
                    return $module;
                }

                // run() wants slug => label, so a supplied slug list is
                // intersected with the known taxonomies rather than trusted.
                $taxonomies = null;

                if (!empty($input['taxonomies']) && is_array($input['taxonomies'])) {
                    $known    = WTA_Trip::default_taxonomies();
                    $selected = array();

                    foreach ($input['taxonomies'] as $slug) {
                        $slug = sanitize_key($slug);

                        if (isset($known[$slug])) {
                            $selected[$slug] = $known[$slug];
                        }
                    }

                    if (empty($selected)) {
                        return new WP_Error(
                            'wta_unknown_taxonomy',
                            'None of the requested taxonomies are managed by this plugin. Allowed: ' . implode(', ', array_keys($known)) . '.',
                            array('status' => 400)
                        );
                    }

                    $taxonomies = $selected;
                }

                $findings = $module->run($taxonomies);

                return array(
                    'findings' => $findings,
                    // Pass the findings back in so the summary describes this
                    // run rather than triggering a second, unfiltered one.
                    'summary'  => $module->summary($findings),
                );
            },
        ));
    }

    /* --------------------------------------------------------- scan-compat */

    protected function register_scan_compat() {
        wp_register_ability(self::NS . 'scan-compat', array(
            'label'       => 'Scan trips for widget crashes',
            'description' => 'Find trips whose itinerary day descriptions would break the WT Widgets for Elementor trip outline widget, which passes that text to printf() as a format string. A "fatal" trip crashes the page on PHP 8; a "corruption" trip renders wrong text at HTTP 200, where nothing is logged. Reports whether the runtime guard that neutralises this is currently active.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'guard_active' => array(
                        'type'        => 'boolean',
                        'description' => 'Whether the escaping guard is switched on. When true the listed trips render correctly despite the vendor bug; when false they are exposed.',
                    ),
                    'fatal'        => array(
                        'type'        => 'integer',
                        'description' => 'Trip days whose text would throw and take the page down.',
                    ),
                    'corruption'   => array(
                        'type'        => 'integer',
                        'description' => 'Trip days whose text would render mangled without any error being raised.',
                    ),
                    'trips'        => array(
                        'type'        => 'array',
                        'description' => 'One entry per offending day, capped at the scan limit.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'post_id' => array('type' => 'integer', 'description' => 'Trip post ID.'),
                                'title'   => array('type' => 'string', 'description' => 'Trip title, so the entry is identifiable without a second lookup.'),
                                'day'     => array('type' => 'integer', 'description' => 'Day number within the itinerary, counting from 1.'),
                                'excerpt' => array('type' => 'string', 'description' => 'Text around the first percent sign, enough to locate the phrase in the editor.'),
                                'mode'    => array(
                                    'type'        => 'string',
                                    'description' => 'How the vendor code fails on this text.',
                                    'enum'        => array('fatal', 'corruption'),
                                ),
                            ),
                        ),
                    ),
                ),
                'required'   => array('guard_active', 'fatal', 'corruption', 'trips'),
            ),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $module = self::require_module('compat');

                if (is_wp_error($module)) {
                    return $module;
                }

                $findings = WTA_Compat::scan_trips();
                $fatal    = 0;

                foreach ($findings as $finding) {
                    if (isset($finding['mode']) && 'fatal' === $finding['mode']) {
                        $fatal++;
                    }
                }

                return array(
                    'guard_active' => WTA_Compat::guard_enabled(),
                    'fatal'        => $fatal,
                    'corruption'   => count($findings) - $fatal,
                    'trips'        => $findings,
                );
            },
        ));
    }

    /* --------------------------------------------------------- permissions */

    /**
     * Reading trip content and running diagnostics is an editorial act, so it
     * is gated on the capability every contributor upward already holds.
     *
     * @return true|WP_Error
     */
    public function can_edit_posts() {
        if (!current_user_can('edit_posts')) {
            return new WP_Error(
                'wta_forbidden',
                'You are not allowed to read WP Travel trip data.',
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Creating terms and changing their publication state is governed by the
     * same capability as the term screens themselves.
     *
     * @return true|WP_Error
     */
    public function can_manage_categories() {
        if (!current_user_can('manage_categories')) {
            return new WP_Error(
                'wta_forbidden',
                'You are not allowed to manage trip taxonomy terms.',
                array('status' => 403)
            );
        }

        return true;
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * A module instance, or an error explaining that it is switched off.
     *
     * Every module in this plugin can be individually disabled, so an ability
     * that depends on one has to say so rather than returning empty results
     * that look like a clean bill of health.
     *
     * @param string $key Module key from WP_Travel_Addons::module_map().
     * @return object|WP_Error
     */
    protected static function require_module($key) {
        if (!class_exists('WP_Travel_Addons')) {
            return new WP_Error(
                'wta_not_loaded',
                'The Nyuchi Travel Addons plugin is not loaded.',
                array('status' => 500)
            );
        }

        $module = WP_Travel_Addons::instance()->module($key);

        if (!$module) {
            $map   = WP_Travel_Addons::module_map();
            $label = isset($map[$key]['label']) ? $map[$key]['label'] : $key;

            return new WP_Error(
                'wta_module_disabled',
                sprintf('The "%s" module is disabled, so this ability cannot run. Enable it under Trip Tools in wp-admin.', $label),
                array('status' => 409, 'module' => $key)
            );
        }

        return $module;
    }

    /**
     * Confirm an ID is a real post of the trip post type.
     *
     * Both failures are reported separately: "no such post" and "that post is
     * a page" lead the caller to different corrections.
     *
     * @return true|WP_Error
     */
    protected static function require_trip($trip_id) {
        if ($trip_id <= 0) {
            return new WP_Error(
                'wta_bad_trip_id',
                'trip_id must be a positive post ID.',
                array('status' => 400)
            );
        }

        $post = get_post($trip_id);

        if (!$post instanceof WP_Post) {
            return new WP_Error(
                'wta_trip_not_found',
                sprintf('No post exists with ID %d.', $trip_id),
                array('status' => 404)
            );
        }

        $expected = WTA_Trip::post_type();

        if ($expected !== $post->post_type) {
            return new WP_Error(
                'wta_not_a_trip',
                sprintf('Post %d is a "%s", not a WP Travel trip ("%s").', $trip_id, $post->post_type, $expected),
                array('status' => 400)
            );
        }

        return true;
    }

    /**
     * Confirm a taxonomy is both managed by this plugin and actually registered.
     *
     * @return true|WP_Error
     */
    protected static function require_taxonomy($taxonomy) {
        $allowed = WTA_Trip::default_taxonomies();

        if (!array_key_exists($taxonomy, $allowed)) {
            return new WP_Error(
                'wta_unknown_taxonomy',
                sprintf('Unknown taxonomy "%s". Allowed: %s.', $taxonomy, implode(', ', array_keys($allowed))),
                array('status' => 400)
            );
        }

        // Configured but unregistered means WP Travel is inactive: a real
        // condition on a site mid-migration, and worth saying plainly.
        if (!taxonomy_exists($taxonomy)) {
            return new WP_Error(
                'wta_taxonomy_missing',
                sprintf('The taxonomy "%s" is configured but not registered on this site. Is WP Travel active?', $taxonomy),
                array('status' => 400)
            );
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function prepare_term($term) {
        if (!$term instanceof WP_Term) {
            return array();
        }

        return array(
            'term_id' => (int) $term->term_id,
            'name'    => $term->name,
            'slug'    => $term->slug,
            'parent'  => (int) $term->parent,
            'count'   => (int) $term->count,
            'status'  => WTA_Term_Status::get_status($term->term_id),
        );
    }

    /**
     * Live is stored as the absence of the draft flag, so it cannot be matched
     * on value alone — a term that was never touched has no meta row at all.
     *
     * @return array|null Null means "no filtering", i.e. status "any".
     */
    protected static function status_meta_query($status) {
        if (WTA_Term_Status::DRAFT === $status) {
            return array(
                array(
                    'key'   => WTA_Term_Status::META_KEY,
                    'value' => WTA_Term_Status::DRAFT,
                ),
            );
        }

        if (WTA_Term_Status::LIVE === $status) {
            return array(
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => WTA_Term_Status::META_KEY,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => WTA_Term_Status::META_KEY,
                        'value'   => WTA_Term_Status::DRAFT,
                        'compare' => '!=',
                    ),
                ),
            );
        }

        return null;
    }

    /* -------------------------------------------------------------- schemas */

    /**
     * Shape of a term as these abilities return it.
     *
     * Shared between list-terms and create-term so a client only has to learn
     * one term representation.
     */
    protected function term_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'term_id' => array('type' => 'integer', 'description' => 'Term ID, the handle every other ability takes.'),
                'name'    => array('type' => 'string', 'description' => 'Display name.'),
                'slug'    => array('type' => 'string', 'description' => 'URL segment of the term archive.'),
                'parent'  => array('type' => 'integer', 'description' => 'Parent term ID, or 0 for a top-level term.'),
                'count'   => array('type' => 'integer', 'description' => 'Number of trips assigned this term. Zero means an empty archive.'),
                'status'  => array(
                    'type'        => 'string',
                    'description' => 'Publication state. Draft terms are assignable but their archive returns 404.',
                    'enum'        => array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT),
                ),
            ),
            'required'   => array('term_id', 'name', 'slug', 'parent', 'count', 'status'),
        );
    }

    /**
     * Shape of a whole trip as get-trip returns it.
     *
     * The authored groups reuse the schemas the REST fields already advertise,
     * so the description of a leg or a cost tier exists in exactly one place;
     * days and facts are appended because they come from WP Travel rather than
     * from this plugin's own meta.
     */
    protected function trip_output_schema() {
        $properties = array();

        foreach (WTA_Itinerary_Schema::fields() as $field) {
            $properties[$field['rest_key']] = array_merge(
                array('description' => $field['description']),
                $field['schema']
            );
        }

        $properties['days'] = array(
            'type'        => 'array',
            'description' => 'WP Travel\'s own day-by-day itinerary, in order. Legs reference these by index rather than copying them, so this is the single source of truth for day content.',
            'items'       => array(
                'type'       => 'object',
                'properties' => array(
                    'title' => array('type' => 'string', 'description' => 'Day heading as WP Travel stores it.'),
                    'desc'  => array('type' => 'string', 'description' => 'Day description, HTML. A percent sign in here is what scan-compat reports on.'),
                ),
            ),
        );

        $properties['facts'] = array(
            'type'        => 'object',
            'description' => 'Trip fields read straight from WP Travel, normalised so a caller does not need to know its meta key names. Values are stored as strings, including the numeric ones.',
            'properties'  => array(
                'price'      => array('type' => 'string', 'description' => 'Headline trip price, in the site currency.'),
                'days'       => array('type' => 'string', 'description' => 'Duration in days.'),
                'nights'     => array('type' => 'string', 'description' => 'Duration in nights.'),
                'group_size' => array('type' => 'string', 'description' => 'Maximum group size.'),
                'code'       => array('type' => 'string', 'description' => 'Internal trip code used for reservations.'),
                'location'   => array('type' => 'string', 'description' => 'Free-text location label, distinct from the destination taxonomy.'),
                'overview'   => array('type' => 'string', 'description' => 'Overview copy, HTML.'),
            ),
        );

        return array(
            'type'       => 'object',
            'properties' => $properties,
            'required'   => array('hero', 'legs', 'route', 'seasonality', 'options', 'cost', 'checklist', 'notes', 'days', 'facts'),
        );
    }
}
