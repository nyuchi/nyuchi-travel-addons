<?php
/**
 * The plugin's own REST surface.
 *
 * The per-resource endpoints live with their modules (trip meta on the trip
 * post type, term state on the term resource). What is left is the operational
 * layer: what is installed, what is misclassified, what is about to crash, and
 * bulk term operations that would otherwise be one HTTP request per term.
 *
 * Everything here is administrative and authenticated — none of it is intended
 * for anonymous or front-end consumption.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_REST {

    const NAMESPACE_V1 = 'wp-travel-addons/v1';

    /** @var WP_Travel_Addons */
    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;

        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route(self::NAMESPACE_V1, '/status', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_status'),
            'permission_callback' => array($this, 'can_manage'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/audit', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_audit'),
            'permission_callback' => array($this, 'can_manage'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/terms', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_terms'),
                'permission_callback' => array($this, 'can_manage'),
                'args'                => array(
                    'taxonomy' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'description'       => 'Taxonomy slug. Must be one of the audited trip taxonomies.',
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => array($this, 'validate_taxonomy'),
                    ),
                    'status'   => array(
                        'default'           => 'any',
                        'type'              => 'string',
                        'enum'              => array('live', 'draft', 'any'),
                        'sanitize_callback' => 'sanitize_key',
                    ),
                    'per_page' => array(
                        'default'           => 100,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'maximum'           => 500,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'create_term'),
                'permission_callback' => array($this, 'can_manage_terms'),
                'args'                => array(
                    'taxonomy'    => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => array($this, 'validate_taxonomy'),
                    ),
                    'name'        => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => array($this, 'validate_non_empty'),
                    ),
                    'slug'        => array(
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_title',
                    ),
                    'parent'      => array(
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ),
                    'description' => array(
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'wp_kses_post',
                    ),
                    // Draft by default: a term created by an API client should
                    // not become a public archive before anyone has looked at it.
                    'status'      => array(
                        'type'              => 'string',
                        'default'           => WTA_Term_Status::DRAFT,
                        'enum'              => array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT),
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE_V1, '/terms/status', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'set_term_status'),
            'permission_callback' => array($this, 'can_manage_terms'),
            'args'                => array(
                'terms'  => array(
                    'required'    => true,
                    'type'        => 'array',
                    'description' => 'Term IDs to update.',
                    'items'       => array('type' => 'integer'),
                ),
                'status' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT),
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE_V1, '/compat', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_compat'),
            'permission_callback' => array($this, 'can_manage'),
        ));
    }

    /* --------------------------------------------------------- permissions */

    public function can_manage() {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'wta_forbidden',
                'You are not allowed to use the WP Travel Addons API.',
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Term writes are an editorial capability, not an administrative one, so
     * they are gated on the capability that governs the term screens.
     */
    public function can_manage_terms() {
        $can_manage = $this->can_manage();

        if (is_wp_error($can_manage)) {
            return $can_manage;
        }

        if (!current_user_can('manage_categories')) {
            return new WP_Error(
                'wta_forbidden',
                'You are not allowed to manage terms.',
                array('status' => 403)
            );
        }

        return true;
    }

    /* ------------------------------------------------------------ contract */

    /**
     * Taxonomies this API will act on.
     *
     * Deliberately narrow: it keeps a bulk call from reaching categories, tags
     * or another plugin's taxonomy through a guessed slug.
     *
     * @return array<string, string> slug => label
     */
    protected function audited_taxonomies() {
        $stored = get_option('wta_status_taxonomies', null);

        if (!is_array($stored) || empty($stored)) {
            $stored = WTA_Trip::default_taxonomies();
        }

        return $stored;
    }

    public function validate_taxonomy($value) {
        $value = sanitize_key($value);

        if (!array_key_exists($value, $this->audited_taxonomies())) {
            return new WP_Error(
                'wta_unknown_taxonomy',
                'Unknown taxonomy. Allowed: ' . implode(', ', array_keys($this->audited_taxonomies())) . '.',
                array('status' => 400)
            );
        }

        if (!taxonomy_exists($value)) {
            return new WP_Error(
                'wta_taxonomy_missing',
                sprintf('The taxonomy "%s" is configured but not registered on this site.', $value),
                array('status' => 400)
            );
        }

        return true;
    }

    public function validate_non_empty($value) {
        if ('' === trim((string) $value)) {
            return new WP_Error('wta_empty_value', 'Value cannot be empty.', array('status' => 400));
        }

        return true;
    }

    /**
     * A module instance, or a 409 explaining that it is switched off.
     *
     * 409 rather than 404: the route exists and the caller is authorised, the
     * site is simply configured in a way that makes the request impossible.
     *
     * @return object|WP_Error
     */
    protected function require_module($key) {
        $module = $this->plugin->module($key);

        if (!$module) {
            $map   = WP_Travel_Addons::module_map();
            $label = isset($map[$key]['label']) ? $map[$key]['label'] : $key;

            return new WP_Error(
                'wta_module_disabled',
                sprintf('The "%s" module is disabled.', $label),
                array('status' => 409, 'module' => $key)
            );
        }

        return $module;
    }

    /* ----------------------------------------------------------- endpoints */

    /**
     * GET /status — what is installed, what is switched on, what it can see.
     */
    public function get_status(WP_REST_Request $request) {
        $modules = array();

        foreach (WP_Travel_Addons::module_map() as $key => $module) {
            $modules[$key] = array(
                'label'   => $module['label'],
                'detail'  => $module['detail'],
                'enabled' => (bool) get_option('wta_module_' . $key, 1),
                // Enabled but not loaded means the class never materialised —
                // a partial deploy rather than a configuration choice.
                'loaded'  => null !== $this->plugin->module($key),
            );
        }

        $taxonomies = array();

        foreach ($this->audited_taxonomies() as $slug => $label) {
            $exists = taxonomy_exists($slug);

            $taxonomies[$slug] = array(
                'label'  => $label,
                'exists' => $exists,
                'terms'  => $exists ? $this->count_terms($slug) : 0,
                'drafts' => $exists ? $this->count_terms($slug, WTA_Term_Status::DRAFT) : 0,
            );
        }

        return rest_ensure_response(array(
            'version'        => WTA_VERSION,
            'trip_post_type' => WTA_Trip::post_type(),
            'trip_available' => WTA_Trip::is_available(),
            'modules'        => $modules,
            'taxonomies'     => $taxonomies,
        ));
    }

    /**
     * GET /audit — classification problems across the trip taxonomies.
     */
    public function get_audit(WP_REST_Request $request) {
        $module = $this->require_module('audit');

        if (is_wp_error($module)) {
            return $module;
        }

        return rest_ensure_response(array(
            'findings' => $module->run(),
            'summary'  => $module->summary(),
        ));
    }

    /**
     * GET /terms — terms with their publication state.
     */
    public function get_terms(WP_REST_Request $request) {
        $taxonomy = $request->get_param('taxonomy');
        $status   = $request->get_param('status');
        $per_page = min(500, max(1, (int) $request->get_param('per_page')));

        $args = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => $per_page,
            'orderby'    => 'name',
        );

        $meta_query = $this->status_meta_query($status);

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
            $out[] = $this->prepare_term($term);
        }

        return rest_ensure_response(array(
            'taxonomy' => $taxonomy,
            'status'   => $status,
            'count'    => count($out),
            'terms'    => $out,
        ));
    }

    /**
     * POST /terms/status — bulk publication state.
     *
     * Bulk exists because drafting a destination tree means touching dozens of
     * terms at once, and a request per term is both slow and non-atomic from
     * the caller's point of view.
     */
    public function set_term_status(WP_REST_Request $request) {
        $module = $this->require_module('term_status');

        if (is_wp_error($module)) {
            return $module;
        }

        $status = $request->get_param('status');

        if (!in_array($status, array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT), true)) {
            return new WP_Error(
                'wta_bad_status',
                'status must be "live" or "draft".',
                array('status' => 400)
            );
        }

        $term_ids = (array) $request->get_param('terms');

        if (empty($term_ids)) {
            return new WP_Error(
                'wta_no_terms',
                'terms must contain at least one term ID.',
                array('status' => 400)
            );
        }

        $allowed = $this->audited_taxonomies();
        $results = array();
        $updated = 0;

        foreach ($term_ids as $raw_id) {
            $term_id = absint($raw_id);
            $term    = $term_id ? get_term($term_id) : null;

            if (!$term instanceof WP_Term) {
                $results[] = array(
                    'term_id' => $term_id,
                    'updated' => false,
                    'reason'  => 'No such term.',
                );
                continue;
            }

            if (!array_key_exists($term->taxonomy, $allowed)) {
                $results[] = array(
                    'term_id' => $term_id,
                    'updated' => false,
                    'reason'  => sprintf('Taxonomy "%s" is not managed by this plugin.', $term->taxonomy),
                );
                continue;
            }

            WTA_Term_Status::set_status($term_id, $status);
            $updated++;

            $results[] = array(
                'term_id'  => $term_id,
                'name'     => $term->name,
                'taxonomy' => $term->taxonomy,
                'updated'  => true,
                'status'   => WTA_Term_Status::get_status($term_id),
            );
        }

        return rest_ensure_response(array(
            'status'    => $status,
            'requested' => count($term_ids),
            'updated'   => $updated,
            'results'   => $results,
        ));
    }

    /**
     * POST /terms — create a term, drafted unless told otherwise.
     */
    public function create_term(WP_REST_Request $request) {
        $taxonomy = $request->get_param('taxonomy');
        $name     = $request->get_param('name');
        $parent   = (int) $request->get_param('parent');

        if ($parent && !get_term($parent, $taxonomy) instanceof WP_Term) {
            return new WP_Error(
                'wta_bad_parent',
                'The parent term does not exist in this taxonomy.',
                array('status' => 400)
            );
        }

        $existing = term_exists($name, $taxonomy, $parent ? $parent : null);

        if ($existing) {
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
            'slug'        => $request->get_param('slug'),
            'parent'      => $parent,
            'description' => $request->get_param('description'),
        ));

        if (is_wp_error($created)) {
            // A slug collision surfaces here rather than in term_exists(), and
            // the caller still wants the id of whatever is in the way.
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

        WTA_Term_Status::set_status($created['term_id'], $request->get_param('status'));

        $term = get_term($created['term_id'], $taxonomy);

        $response = rest_ensure_response($this->prepare_term($term));
        $response->set_status(201);

        return $response;
    }

    /**
     * GET /compat — trips the vendor widget would break on.
     */
    public function get_compat(WP_REST_Request $request) {
        $module = $this->require_module('compat');

        if (is_wp_error($module)) {
            return $module;
        }

        $findings = WTA_Compat::scan_trips();
        $fatal    = 0;

        foreach ($findings as $finding) {
            if ('fatal' === $finding['mode']) {
                $fatal++;
            }
        }

        return rest_ensure_response(array(
            'guard_active' => WTA_Compat::guard_enabled(),
            'scan_limit'   => WTA_Compat::SCAN_LIMIT,
            'affected'     => count($findings),
            'fatal'        => $fatal,
            'corruption'   => count($findings) - $fatal,
            'findings'     => $findings,
        ));
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * @return array<string, mixed>
     */
    protected function prepare_term($term) {
        if (!$term instanceof WP_Term) {
            return array();
        }

        return array(
            'term_id'    => (int) $term->term_id,
            'name'       => $term->name,
            'slug'       => $term->slug,
            'taxonomy'   => $term->taxonomy,
            'parent'     => (int) $term->parent,
            'count'      => (int) $term->count,
            'wta_status' => WTA_Term_Status::get_status($term->term_id),
        );
    }

    /**
     * Live is stored as the absence of the draft flag, so it cannot be matched
     * on value alone — a term that was never touched has no meta row at all.
     *
     * @return array|null
     */
    protected function status_meta_query($status) {
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

    /**
     * get_terms() rather than wp_count_terms(): the latter's signature changed
     * in WP 5.6 and this plugin supports 5.9 upward with third-party filters in
     * play, so counting ids is the predictable option.
     */
    protected function count_terms($taxonomy, $status = null) {
        $args = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'ids',
        );

        $meta_query = $status ? $this->status_meta_query($status) : null;

        if ($meta_query) {
            $args['meta_query'] = $meta_query;
        }

        $ids = get_terms($args);

        return is_wp_error($ids) ? 0 : count($ids);
    }
}
