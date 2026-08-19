<?php
/**
 * Admin screen for WP Travel Addons.
 *
 * A top-level menu rather than a Settings sub-item: term publication state and
 * classification diagnostics are worked on daily, not configured once.
 *
 * Every read of another module goes through WP_Travel_Addons::module(), which
 * returns null when that module is switched off, so this class must never
 * assume a module is loaded.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Admin {

    const SLUG = 'wp-travel-addons';

    /** @var WP_Travel_Addons */
    private $plugin;

    /** @var array<int, array>|null Memoised audit findings. */
    private $findings;

    /** Compatibility guard option. */
    const COMPAT_OPTION = 'wta_compat_wt_widgets_printf';

    public function __construct($plugin) {
        $this->plugin = $plugin;

        add_action('admin_menu', array($this, 'add_menu'));

        // Saving happens on admin_init so wp_safe_redirect() runs before any
        // admin markup has been sent.
        add_action('admin_init', array($this, 'handle_post'));
    }

    /* ----------------------------------------------------------------- Menu */

    /**
     * Tabs, in menu order. Key => array(label, dashicon).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tabs() {
        return array(
            'overview'       => array('Overview', 'palmtree'),
            'classification' => array('Classification', 'category'),
            'terms'          => array('Terms', 'tag'),
            'modules'        => array('Modules', 'admin-plugins'),
        );
    }

    public function add_menu() {
        add_menu_page(
            'WP Travel Addons',
            'Travel Addons',
            'manage_options',
            self::SLUG,
            array($this, 'render'),
            'dashicons-palmtree',
            59
        );

        foreach (self::tabs() as $tab => $meta) {
            add_submenu_page(
                self::SLUG,
                'WP Travel Addons — ' . $meta[0],
                $meta[0],
                'manage_options',
                self::SLUG . ('overview' === $tab ? '' : '&tab=' . $tab),
                array($this, 'render')
            );
        }

        // The auto-added first submenu repeats the parent title; relabel it.
        global $submenu;
        if (isset($submenu[self::SLUG][0][0])) {
            $submenu[self::SLUG][0][0] = 'Overview';
        }
    }

    /**
     * Admin screen URL for a given tab.
     */
    public static function admin_url_for($tab = 'overview') {
        $url = admin_url('admin.php?page=' . self::SLUG);

        return ('overview' === $tab || '' === $tab) ? $url : $url . '&tab=' . rawurlencode($tab);
    }

    /**
     * The tab currently being viewed, always one of self::tabs().
     */
    public function current_tab() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';

        return array_key_exists($tab, self::tabs()) ? $tab : 'overview';
    }

    /* ------------------------------------------------------------- Plumbing */

    /** @return WP_Travel_Addons */
    public function plugin() {
        return $this->plugin;
    }

    /**
     * A module instance, or null when the module is switched off.
     *
     * @return object|null
     */
    public function module($key) {
        if (!is_object($this->plugin) || !method_exists($this->plugin, 'module')) {
            return null;
        }

        return $this->plugin->module($key);
    }

    public function module_enabled($key) {
        return null !== $this->module($key);
    }

    /** How many of the switchable modules are currently loaded. */
    public function active_module_count() {
        $count = 0;

        foreach (array_keys(WP_Travel_Addons::module_map()) as $key) {
            if ($this->module_enabled($key)) {
                $count++;
            }
        }

        return $count;
    }

    /** Whether the "saved" notice should be shown, consuming the flag. */
    public function consume_saved_flag() {
        if (get_transient('wta_saved')) {
            delete_transient('wta_saved');

            return true;
        }

        return false;
    }

    /* ------------------------------------------------------------- POST */

    public function handle_post() {
        if (empty($_POST['wta_form'])) {
            return;
        }

        $form = sanitize_key(wp_unslash($_POST['wta_form']));

        if ('modules' === $form) {
            $this->save_modules();
        } elseif ('terms' === $form) {
            $this->save_terms();
        }
    }

    private function save_modules() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change module state.', 'wp-travel-addons'));
        }

        check_admin_referer('wta_save_modules', 'wta_modules_nonce');

        foreach (array_keys(WP_Travel_Addons::module_map()) as $key) {
            update_option('wta_module_' . $key, isset($_POST['module_' . $key]) ? 1 : 0);
        }

        update_option(self::COMPAT_OPTION, isset($_POST['compat_wt_widgets_printf']) ? 1 : 0);

        $this->redirect_to('modules');
    }

    private function save_terms() {
        // Term state is a taxonomy capability, not a settings one.
        if (!current_user_can('manage_categories')) {
            wp_die(esc_html__('You do not have permission to change term publication state.', 'wp-travel-addons'));
        }

        check_admin_referer('wta_save_terms', 'wta_terms_nonce');

        $status = isset($_POST['bulk_status']) ? sanitize_key(wp_unslash($_POST['bulk_status'])) : '';

        if (!in_array($status, array(WTA_Term_Status::LIVE, WTA_Term_Status::DRAFT), true)) {
            $this->redirect_to('terms');
        }

        $term_ids = isset($_POST['term_ids']) ? (array) wp_unslash($_POST['term_ids']) : array();
        $term_ids = array_filter(array_map('absint', $term_ids));

        $allowed = array_keys($this->taxonomies());

        foreach ($term_ids as $term_id) {
            $term = get_term($term_id);

            // Only touch terms in taxonomies this plugin is responsible for.
            if (!$term instanceof WP_Term || !in_array($term->taxonomy, $allowed, true)) {
                continue;
            }

            WTA_Term_Status::set_status($term_id, $status);
        }

        $this->redirect_to('terms');
    }

    private function redirect_to($tab) {
        set_transient('wta_saved', 1, 30);

        $url = self::admin_url_for($tab);

        if ('terms' === $tab) {
            $taxonomy = $this->current_taxonomy_filter();

            if ('' !== $taxonomy) {
                $url = add_query_arg('taxonomy', $taxonomy, $url);
            }
        }

        wp_safe_redirect($url);
        exit;
    }

    /* --------------------------------------------------------------- Data */

    /**
     * Taxonomies under audit, slug => label.
     *
     * Reads the term-status module when it is loaded, so a custom taxonomy set
     * is honoured; falls back to the stored option and then to WP Travel's
     * defaults so the screen still works with the module off.
     *
     * @return array<string, string>
     */
    public function taxonomies() {
        $module = $this->module('term_status');

        if ($module && method_exists($module, 'taxonomies')) {
            $taxonomies = $module->taxonomies();
        } else {
            $taxonomies = get_option('wta_status_taxonomies', null);
        }

        if (!is_array($taxonomies) || empty($taxonomies)) {
            $taxonomies = WTA_Trip::default_taxonomies();
        }

        return $taxonomies;
    }

    /** Taxonomy the Terms tab is filtered to, or '' for all. */
    public function current_taxonomy_filter() {
        $requested = isset($_GET['taxonomy']) ? sanitize_key(wp_unslash($_GET['taxonomy'])) : '';

        return array_key_exists($requested, $this->taxonomies()) ? $requested : '';
    }

    /**
     * Every term in the audited taxonomies, grouped by taxonomy slug.
     *
     * @return array<string, WP_Term[]>
     */
    public function terms_by_taxonomy($only = '') {
        $out = array();

        foreach ($this->taxonomies() as $slug => $label) {
            if ('' !== $only && $slug !== $only) {
                continue;
            }

            if (!taxonomy_exists($slug)) {
                $out[$slug] = array();
                continue;
            }

            $terms = get_terms(array(
                'taxonomy'   => $slug,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
                // Drafts must stay visible on this screen: managing them is
                // the entire point of it.
                'meta_query' => array(),
            ));

            $out[$slug] = is_wp_error($terms) ? array() : $terms;
        }

        return $out;
    }

    public function term_count() {
        $total = 0;

        foreach ($this->terms_by_taxonomy() as $terms) {
            $total += count($terms);
        }

        return $total;
    }

    public function draft_term_count() {
        $total = 0;

        foreach ($this->terms_by_taxonomy() as $terms) {
            foreach ($terms as $term) {
                if (WTA_Term_Status::DRAFT === $this->term_status($term->term_id)) {
                    $total++;
                }
            }
        }

        return $total;
    }

    public function term_status($term_id) {
        return WTA_Term_Status::get_status($term_id);
    }

    public function term_parent_name($term) {
        if (empty($term->parent)) {
            return '';
        }

        $parent = get_term($term->parent, $term->taxonomy);

        return ($parent instanceof WP_Term) ? $parent->name : '';
    }

    public function taxonomy_label($slug) {
        $taxonomies = $this->taxonomies();

        if (isset($taxonomies[$slug])) {
            return $taxonomies[$slug];
        }

        $object = get_taxonomy($slug);

        return ($object && isset($object->labels->name)) ? $object->labels->name : $slug;
    }

    /** Published trips. */
    public function trip_count() {
        $post_type = WTA_Trip::post_type();

        if (!post_type_exists($post_type)) {
            return 0;
        }

        $counts = wp_count_posts($post_type);

        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    /* -------------------------------------------------------------- Audit */

    /**
     * Normalised audit findings, highest severity first.
     *
     * Returns an empty array when the audit module is off — callers should ask
     * audit_available() first if they need to tell "off" from "nothing wrong".
     *
     * @return array<int, array{severity: string, title: string, detail: string, count: int, terms: array}>
     */
    public function audit_findings() {
        if (null !== $this->findings) {
            return $this->findings;
        }

        $this->findings = array();

        $module = $this->module('audit');

        if (!$module || !method_exists($module, 'run')) {
            return $this->findings;
        }

        $raw = $module->run();

        if (!is_array($raw)) {
            return $this->findings;
        }

        foreach ($raw as $key => $finding) {
            $normalised = $this->normalise_finding($key, $finding);

            if (null !== $normalised) {
                $this->findings[] = $normalised;
            }
        }

        $order = array_flip(array_keys(self::severities()));

        usort($this->findings, function ($a, $b) use ($order) {
            $rank_a = isset($order[$a['severity']]) ? $order[$a['severity']] : 99;
            $rank_b = isset($order[$b['severity']]) ? $order[$b['severity']] : 99;

            if ($rank_a === $rank_b) {
                return strcmp($a['title'], $b['title']);
            }

            return ($rank_a < $rank_b) ? -1 : 1;
        });

        return $this->findings;
    }

    public function audit_available() {
        $module = $this->module('audit');

        return (bool) ($module && method_exists($module, 'run'));
    }

    /** Severity keys in descending order, key => human label. */
    public static function severities() {
        return array(
            'critical' => 'Critical',
            'warning'  => 'Warning',
            'notice'   => 'Notice',
            'info'     => 'Information',
        );
    }

    /**
     * Findings grouped by severity, in severity order, empty groups dropped.
     *
     * @return array<string, array>
     */
    public function findings_by_severity() {
        $grouped = array();

        foreach (array_keys(self::severities()) as $severity) {
            $grouped[$severity] = array();
        }

        foreach ($this->audit_findings() as $finding) {
            $grouped[$finding['severity']][] = $finding;
        }

        return array_filter($grouped, function ($set) {
            return !empty($set);
        });
    }

    /**
     * Coerce whatever the audit module returns into a predictable shape.
     *
     * @return array|null
     */
    private function normalise_finding($key, $finding) {
        if (is_string($finding)) {
            $finding = array('title' => $finding);
        }

        if (!is_array($finding)) {
            return null;
        }

        $severity = isset($finding['severity']) ? sanitize_key($finding['severity']) : 'notice';

        if (!array_key_exists($severity, self::severities())) {
            $severity = 'notice';
        }

        $title = '';
        foreach (array('title', 'label', 'name') as $candidate) {
            if (!empty($finding[$candidate]) && is_string($finding[$candidate])) {
                $title = $finding[$candidate];
                break;
            }
        }

        if ('' === $title) {
            $title = is_string($key) ? ucwords(str_replace(array('_', '-'), ' ', $key)) : 'Finding';
        }

        $detail = '';
        foreach (array('detail', 'description', 'message') as $candidate) {
            if (!empty($finding[$candidate]) && is_string($finding[$candidate])) {
                $detail = $finding[$candidate];
                break;
            }
        }

        $terms = $this->normalise_finding_terms(isset($finding['terms']) ? $finding['terms'] : array());

        $count = isset($finding['count']) ? (int) $finding['count'] : count($terms);

        return array(
            'severity' => $severity,
            'title'    => $title,
            'detail'   => $detail,
            'count'    => $count,
            'terms'    => $terms,
        );
    }

    /**
     * Terms attached to a finding, as flat display rows.
     *
     * Accepts term IDs, WP_Term objects, arrays or plain strings, because the
     * audit module's checks each build their own lists.
     *
     * @return array<int, array{name: string, taxonomy: string, status: string, link: string}>
     */
    private function normalise_finding_terms($terms) {
        if (!is_array($terms)) {
            return array();
        }

        $rows = array();

        foreach ($terms as $term) {
            if (is_numeric($term)) {
                $term = get_term((int) $term);
            }

            if ($term instanceof WP_Term) {
                $rows[] = array(
                    'name'     => $term->name,
                    'taxonomy' => $term->taxonomy,
                    'status'   => $this->term_status($term->term_id),
                    'link'     => (string) get_edit_term_link($term->term_id, $term->taxonomy),
                );
                continue;
            }

            if (is_array($term)) {
                $term_id  = isset($term['term_id']) ? (int) $term['term_id'] : 0;
                $taxonomy = isset($term['taxonomy']) ? (string) $term['taxonomy'] : '';
                $name     = isset($term['name']) ? (string) $term['name'] : '';

                if ('' === $name && $term_id) {
                    $object = get_term($term_id);
                    $name   = ($object instanceof WP_Term) ? $object->name : (string) $term_id;
                }

                $rows[] = array(
                    'name'     => $name,
                    'taxonomy' => $taxonomy,
                    'status'   => $term_id ? $this->term_status($term_id) : WTA_Term_Status::LIVE,
                    'link'     => ($term_id && $taxonomy) ? (string) get_edit_term_link($term_id, $taxonomy) : '',
                );
                continue;
            }

            if (is_string($term) && '' !== $term) {
                $rows[] = array(
                    'name'     => $term,
                    'taxonomy' => '',
                    'status'   => WTA_Term_Status::LIVE,
                    'link'     => '',
                );
            }
        }

        return $rows;
    }

    /* -------------------------------------------------------------- Render */

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'wp-travel-addons'));
        }

        include WTA_DIR . 'admin/views/admin-page.php';
    }
}
