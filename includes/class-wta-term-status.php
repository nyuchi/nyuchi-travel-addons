<?php
/**
 * Publication state for taxonomy terms.
 *
 * WordPress has no draft state for terms — a term exists and is public the
 * moment it is created. That makes it impossible to build out a destination
 * tree ahead of the content, because every empty term immediately becomes a
 * thin, indexable archive.
 *
 * This adds live/draft to terms in the configured taxonomies. A draft term is
 * fully editable and assignable in wp-admin, but is hidden from term listings,
 * its archive returns 404, and it is marked noindex for anyone who reaches it.
 *
 * Terms with no stored status are treated as live, so enabling this module
 * changes nothing until a term is explicitly drafted.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Term_Status {

    const META_KEY = '_wta_status';
    const LIVE     = 'live';
    const DRAFT    = 'draft';

    public function __construct() {
        // Front-end suppression
        add_filter('get_terms_args', array($this, 'exclude_drafts_from_queries'), 10, 2);
        add_action('template_redirect', array($this, 'block_draft_archives'));
        add_filter('wpseo_robots_array', array($this, 'noindex_draft_archive'));

        // Term editing UI
        foreach (array_keys($this->taxonomies()) as $taxonomy) {
            add_action("{$taxonomy}_add_form_fields", array($this, 'render_add_field'));
            add_action("{$taxonomy}_edit_form_fields", array($this, 'render_edit_field'), 10, 2);
            add_filter("manage_edit-{$taxonomy}_columns", array($this, 'add_column'));
            add_filter("manage_{$taxonomy}_custom_column", array($this, 'render_column'), 10, 3);
        }

        add_action('created_term', array($this, 'save_term_status'), 10, 3);
        add_action('edited_term', array($this, 'save_term_status'), 10, 3);

        // REST
        add_action('rest_api_init', array($this, 'register_rest_field'));
    }

    /**
     * Taxonomies this applies to.
     *
     * @return array<string, string>
     */
    public function taxonomies() {
        $stored = get_option('wta_status_taxonomies', null);

        if (!is_array($stored) || empty($stored)) {
            $stored = WTA_Trip::default_taxonomies();
        }

        return $stored;
    }

    public function handles($taxonomy) {
        return array_key_exists($taxonomy, $this->taxonomies());
    }

    /**
     * A term's status. Absent meta means live — this module must be inert
     * until someone deliberately drafts something.
     */
    public static function get_status($term_id) {
        $status = get_term_meta($term_id, self::META_KEY, true);

        return self::DRAFT === $status ? self::DRAFT : self::LIVE;
    }

    public static function set_status($term_id, $status) {
        if (self::DRAFT === $status) {
            return update_term_meta($term_id, self::META_KEY, self::DRAFT);
        }

        // Live is the default, so it is stored as the absence of the flag.
        return delete_term_meta($term_id, self::META_KEY);
    }

    /**
     * Whether the current request should have drafts hidden from it.
     *
     * Anyone who can edit content keeps seeing drafts, so an editor can preview
     * the tree they are building. Everyone else — visitors, anonymous REST
     * consumers, search engines — does not.
     */
    protected function should_hide_drafts() {
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }

        return !current_user_can('edit_posts');
    }

    /**
     * Keep draft terms out of get_terms() results: menus, widgets, filter
     * dropdowns, term clouds and the REST terms endpoints all run through it.
     */
    public function exclude_drafts_from_queries($args, $taxonomies) {
        if ($this->should_hide_drafts() === false) {
            return $args;
        }

        $taxonomies = (array) $taxonomies;
        $relevant   = array_intersect($taxonomies, array_keys($this->taxonomies()));

        if (empty($relevant)) {
            return $args;
        }

        $meta_query = isset($args['meta_query']) && is_array($args['meta_query'])
            ? $args['meta_query']
            : array();

        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => self::META_KEY,
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => self::META_KEY,
                'value'   => self::DRAFT,
                'compare' => '!=',
            ),
        );

        $args['meta_query'] = $meta_query;

        return $args;
    }

    /**
     * A draft term's archive should not be reachable.
     */
    public function block_draft_archives() {
        if (!is_tax(array_keys($this->taxonomies()))) {
            return;
        }

        if ($this->should_hide_drafts() === false) {
            return;
        }

        $term = get_queried_object();

        if (!$term instanceof WP_Term || self::DRAFT !== self::get_status($term->term_id)) {
            return;
        }

        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();

        include get_query_template('404');
        exit;
    }

    /**
     * Belt and braces for the case where something else serves the archive
     * before template_redirect can 404 it.
     */
    public function noindex_draft_archive($robots) {
        if (!is_tax(array_keys($this->taxonomies()))) {
            return $robots;
        }

        $term = get_queried_object();

        if ($term instanceof WP_Term && self::DRAFT === self::get_status($term->term_id)) {
            $robots['index']  = 'noindex';
            $robots['follow'] = 'nofollow';
        }

        return $robots;
    }

    /* ------------------------------------------------------------------ UI */

    public function render_add_field($taxonomy) {
        ?>
        <div class="form-field">
            <label for="wta_status">Publication state</label>
            <select name="wta_status" id="wta_status">
                <option value="live">Live — public and indexable</option>
                <option value="draft">Draft — hidden from visitors</option>
            </select>
            <p>Draft terms can be assigned to content, but their archive returns 404 and they are hidden from menus and term lists.</p>
        </div>
        <?php
    }

    public function render_edit_field($term, $taxonomy) {
        $status = self::get_status($term->term_id);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="wta_status">Publication state</label></th>
            <td>
                <select name="wta_status" id="wta_status">
                    <option value="live" <?php selected($status, self::LIVE); ?>>Live — public and indexable</option>
                    <option value="draft" <?php selected($status, self::DRAFT); ?>>Draft — hidden from visitors</option>
                </select>
                <p class="description">
                    Draft terms stay editable and assignable. Visitors get a 404 on the
                    archive, and the term is excluded from menus and term listings.
                </p>
            </td>
        </tr>
        <?php
    }

    public function save_term_status($term_id, $tt_id, $taxonomy) {
        if (!$this->handles($taxonomy) || !isset($_POST['wta_status'])) {
            return;
        }

        if (!current_user_can('manage_categories')) {
            return;
        }

        self::set_status($term_id, sanitize_key($_POST['wta_status']));
    }

    public function add_column($columns) {
        $out = array();

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            // Sit the state directly after the name, where it is read.
            if ('name' === $key) {
                $out['wta_status'] = 'State';
            }
        }

        return $out;
    }

    public function render_column($content, $column, $term_id) {
        if ('wta_status' !== $column) {
            return $content;
        }

        $status = self::get_status($term_id);
        $style  = self::DRAFT === $status
            ? 'background:#FFF8E1;color:#7A5C00;border-color:#E3D19A;'
            : 'background:#E0F2F1;color:#004D40;border-color:#B7D9D1;';

        return sprintf(
            '<span style="display:inline-block;padding:2px 10px;border-radius:999px;border:1px solid;font-size:12px;%s">%s</span>',
            esc_attr($style),
            esc_html(ucfirst($status))
        );
    }

    /* ---------------------------------------------------------------- REST */

    public function register_rest_field() {
        foreach (array_keys($this->taxonomies()) as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            register_rest_field($taxonomy, 'wta_status', array(
                'get_callback'    => function ($term) {
                    return self::get_status($term['id']);
                },
                'update_callback' => function ($value, $term) {
                    if (!current_user_can('manage_categories')) {
                        return new WP_Error('wta_forbidden', 'You cannot change term state.', array('status' => 403));
                    }

                    $value = sanitize_key($value);

                    if (!in_array($value, array(self::LIVE, self::DRAFT), true)) {
                        return new WP_Error('wta_bad_status', 'wta_status must be "live" or "draft".', array('status' => 400));
                    }

                    self::set_status($term->term_id, $value);

                    return true;
                },
                'schema'          => array(
                    'description' => 'Publication state: live or draft.',
                    'type'        => 'string',
                    'enum'        => array(self::LIVE, self::DRAFT),
                    'context'     => array('view', 'edit'),
                ),
            ));
        }
    }
}
