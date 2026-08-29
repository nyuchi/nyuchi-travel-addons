<?php
/**
 * WP Travel's own data, described so a machine can manage a trip end to end.
 *
 * class-wta-abilities.php exposes the content *this* plugin authors — the
 * itinerary schema, term publication state, the diagnostics. It deliberately
 * says almost nothing about WP Travel itself, so an AI client can read a trip
 * but cannot create one, cannot price one, and cannot see that a trip's real
 * price is not where its post meta says it is.
 *
 * This file is the other half: trips, pricing, dates, taxonomies and the Pro
 * add-on surface, registered under the same namespace so a caller sees one
 * coherent set. Nothing here re-registers an ability that already exists next
 * door; get-trip and update-itinerary stay where they are.
 *
 * Three constraints shape every method below.
 *
 * First, this plugin enhances WP Travel rather than shadowing it. Nothing here
 * registers a meta key of its own, and no ability writes one. Where WP Travel
 * already owns a concept — price in wt_pricings, duration in
 * wp_travel_trip_duration, inclusions in wp_travel_trip_include — the ability
 * is a wrapper over that storage: it validates the input, writes it to WP
 * Travel's own field, and reports when WP Travel's storage disagrees with
 * itself. A second copy under a wta_ key would be a second thing to keep
 * correct, and the two would diverge on the first trip nobody re-saved. The
 * existing wta_ itinerary groups are read here, never written; update-itinerary
 * next door remains their only writer.
 *
 * Second, this plugin must not hard-depend on WP Travel. WP Travel is a separate
 * product that can be deactivated, downgraded or replaced, and a trip tool that
 * fatals when it goes away is worse than one that does nothing. So no WP Travel
 * function or class is called without function_exists()/class_exists() around
 * it, detection reuses WTA_Trip::is_available() rather than inventing a second
 * answer to the same question, and every ability that needs WP Travel returns a
 * structured error naming what is missing instead of dying.
 *
 * Third, WP Travel's storage is only partly post meta. Pricing lives in
 * wp_wt_pricings and wp_wt_price_category_relation; dates live in wp_wt_dates
 * and wp_wt_excluded_dates_times. On the site this was written against,
 * wp_travel_trip_price reads "0" on a published luxury itinerary whose real
 * price is a row in wp_wt_pricings — so an ability that trusted post meta would
 * confidently report the wrong number. The column names of those four tables
 * were NOT available when this was written, and guessing them would produce
 * abilities that fail on the one install they were guessed against. Instead the
 * table abilities discover their own shape with SHOW COLUMNS at call time and
 * return whatever is really there. That is slower and less pretty than a hand-
 * written schema, and it is correct across WP Travel versions, which matters
 * more.
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

class WTA_Travel_Abilities {

    /**
     * Category these abilities are filed under.
     *
     * Deliberately not WTA_Abilities::CATEGORY. Both categories belong to this
     * plugin, but they answer different questions — "what has Nyuchi added to
     * this trip" versus "what does WP Travel itself hold" — and registering the
     * same category slug twice is a collision rather than a merge.
     */
    const CATEGORY = 'nyuchi-wp-travel';

    /** Ability name prefix. Shared with WTA_Abilities so a client sees one set. */
    const NS = 'nyuchi-travel/';

    /**
     * Plugin files as WordPress identifies them in the active-plugins list.
     *
     * Version is read from the plugin header rather than from a constant.
     * WP_TRAVEL_VERSION and its Pro counterpart are plausible names but were
     * not verified against the vendor source, and get_plugin_data() is core,
     * always correct, and works even when the plugin is installed but inactive.
     */
    const PLUGIN_CORE = 'wp-travel/wp-travel.php';
    const PLUGIN_PRO  = 'wp-travel-pro/wp-travel-pro.php';

    /**
     * Custom tables WP Travel creates, without the site table prefix.
     *
     * Confirmed present in the live database. Their columns are not hard-coded
     * anywhere in this file; see the class docblock.
     */
    const TABLES = array(
        'wt_pricings',
        'wt_price_category_relation',
        'wt_dates',
        'wt_excluded_dates_times',
    );

    /**
     * Column names that, when one of them exists on a WP Travel table, hold the
     * trip post ID.
     *
     * This is a lookup order, not an assertion: the first candidate actually
     * present in SHOW COLUMNS wins, and every response reports which one was
     * matched so a caller can see the guess that was made. If none is present
     * the ability says the table cannot be scoped to a trip rather than
     * inventing a join.
     */
    const TRIP_COLUMN_CANDIDATES = array('trip_id', 'post_id', 'itinerary_id', 'trip', 'parent_id');

    /** Upper bound on rows returned from a raw table read, so nothing times out. */
    const ROW_LIMIT = 200;

    /** Upper bound on trips returned from one listing call. */
    const TRIP_LIMIT = 100;

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
            'label'       => 'WP Travel',
            'description' => 'Read and write WP Travel\'s own trip data: trips as posts, their pricing and departure dates (which live in custom database tables rather than post meta), their taxonomy terms, the bookings and enquiries attached to them, and a diagnostic that reports what this install actually has before anything else is called.',
        ));
    }

    public function register_abilities() {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        // Diagnostics first, because it is the only ability that is useful on a
        // site where WP Travel is absent — telling the caller so is its job.
        $this->register_diagnostics();
        $this->register_list_addons();

        $this->register_list_trips();
        $this->register_get_trip_full();
        $this->register_create_trip();
        $this->register_update_trip();
        $this->register_set_itinerary_days();
        $this->register_set_trip_terms();

        $this->register_get_pricing();
        $this->register_set_pricing();
        $this->register_get_dates();
        $this->register_set_dates();

        $this->register_get_term_detail();
        $this->register_update_term();

        $this->register_list_travel_posts();
    }

    /* ------------------------------------------------------- wp-travel-status */

    protected function register_diagnostics() {
        wp_register_ability(self::NS . 'wp-travel-status', array(
            'label'       => 'Report what WP Travel this site has',
            'description' => 'Discovery call. Reports whether WP Travel and WP Travel Pro are installed and active and at what version, which wp-travel-* add-on plugins are present, which post types and taxonomies are actually registered, and which of WP Travel\'s custom tables exist — including their real column names and row counts, read live rather than assumed. Call this first: every other ability in this set behaves differently depending on what it reports, and the pricing and date abilities need the column names from here to be driven correctly.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'include_options' => array(
                        'type'        => 'boolean',
                        'description' => 'Also list the names of WP Travel\'s own option rows. Names only, never values — settings blobs can carry API keys.',
                        'default'     => false,
                    ),
                ),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'wp_travel'     => $this->plugin_schema('WP Travel core.'),
                    'wp_travel_pro' => $this->plugin_schema('WP Travel Pro. Absent or inactive does not stop any ability here; it only narrows what exists to read.'),
                    'addons'        => array(
                        'type'        => 'array',
                        'description' => 'Every installed plugin whose directory starts "wp-travel", excluding core and Pro themselves.',
                        'items'       => $this->plugin_schema('An add-on plugin.'),
                    ),
                    'pro_modules'   => array(
                        'type'        => 'array',
                        'description' => 'WP Travel Pro\'s bundled feature modules, read live from WP Travel\'s own registry. These are not separate plugins and do not appear under addons. See list-travel-addons for the same list with more explanation.',
                        'items'       => array('type' => 'object'),
                    ),
                    'post_types'    => array(
                        'type'        => 'array',
                        'description' => 'Registered post types belonging to WP Travel, with their REST bases. Empty means WP Travel is not active on this request.',
                        'items'       => array('type' => 'object'),
                    ),
                    'taxonomies'    => array(
                        'type'        => 'array',
                        'description' => 'Taxonomies registered against the trip post type, with term counts.',
                        'items'       => array('type' => 'object'),
                    ),
                    'tables'        => array(
                        'type'        => 'array',
                        'description' => 'WP Travel\'s custom tables. Each reports whether it exists, how many rows it holds, its real column names and types, and which column the trip-scoped abilities will treat as the trip reference. A table that exists with zero rows is meaningful: it means the feature is installed but nothing has been entered.',
                        'items'       => array('type' => 'object'),
                    ),
                    'options'       => array(
                        'type'        => 'array',
                        'description' => 'WP Travel option names, when include_options was set. Values are never returned.',
                        'items'       => array('type' => 'string'),
                    ),
                    'notes'         => array(
                        'type'        => 'array',
                        'description' => 'Plain-language observations a caller should read before trusting the numbers, such as pricing not living in post meta.',
                        'items'       => array('type' => 'string'),
                    ),
                ),
                'required'   => array('wp_travel', 'wp_travel_pro', 'addons', 'pro_modules', 'post_types', 'taxonomies', 'tables', 'notes'),
            ),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                global $wpdb;

                $core = self::plugin_info(self::PLUGIN_CORE);
                $pro  = self::plugin_info(self::PLUGIN_PRO, 'WP_Travel_Pro');

                $post_types = array();
                $taxonomies = array();
                $notes      = array();

                if (self::core_available()) {
                    foreach (get_post_types(array(), 'objects') as $type) {
                        // WP Travel's own types are the trip type plus the
                        // booking-side types it registers alongside it. Matching
                        // on the known slugs rather than a name pattern, because
                        // "itineraries" and "tour-extras" share no prefix.
                        if (!in_array($type->name, self::travel_post_types(), true)) {
                            continue;
                        }

                        $counts = wp_count_posts($type->name);

                        $post_types[] = array(
                            'slug'      => $type->name,
                            'label'     => $type->label,
                            'rest_base' => $type->rest_base ? $type->rest_base : $type->name,
                            'public'    => (bool) $type->public,
                            'published' => isset($counts->publish) ? (int) $counts->publish : 0,
                        );
                    }

                    foreach (get_object_taxonomies(WTA_Trip::post_type(), 'objects') as $tax) {
                        $taxonomies[] = array(
                            'slug'         => $tax->name,
                            'label'        => $tax->label,
                            'rest_base'    => $tax->rest_base ? $tax->rest_base : $tax->name,
                            'hierarchical' => (bool) $tax->hierarchical,
                            'terms'        => (int) wp_count_terms(array('taxonomy' => $tax->name, 'hide_empty' => false)),
                            'managed'      => array_key_exists($tax->name, WTA_Trip::default_taxonomies()),
                        );
                    }
                } else {
                    $notes[] = sprintf(
                        'The trip post type "%s" is not registered on this request, so WP Travel is inactive or renamed. Post type and taxonomy lists are empty for that reason, not because the site is empty.',
                        WTA_Trip::post_type()
                    );
                }

                $tables = array();

                foreach (self::TABLES as $suffix) {
                    $tables[] = self::describe_table($suffix);
                }

                $notes[] = 'Trip pricing is not reliably in post meta. wp_travel_trip_price can read "0" on a trip that is priced, because the real figures are rows in wt_pricings. Use get-trip-pricing, not the price field from get-trip-detail.';

                foreach ($tables as $table) {
                    if ($table['exists'] && 0 === $table['rows']) {
                        $notes[] = sprintf(
                            'Table %s exists but holds no rows: the feature it backs is installed and unused on this site.',
                            $table['table']
                        );
                    }
                }

                $options = array();

                if (!empty($input['include_options'])) {
                    // Names only. A settings blob is exactly the kind of place a
                    // payment gateway secret ends up, and this ability is gated
                    // on edit_posts.
                    //
                    // "wp_travel_engine_" is excluded in the query rather than
                    // filtered afterwards. WP Travel Engine is a different
                    // plugin by a different vendor that happens to prefix its
                    // options with a superset of WP Travel's, so a plain
                    // LIKE 'wp_travel%' silently reports another product's
                    // settings as WP Travel's. This site carries a stale
                    // wp_travel_engine_settings row from a past install, so the
                    // confusion is live here, not hypothetical.
                    $options = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT 200",
                            $wpdb->esc_like('wp_travel') . '%',
                            $wpdb->esc_like('wp_travel_engine_') . '%'
                        )
                    );

                    $options = array_map('strval', (array) $options);

                    $foreign = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                            $wpdb->esc_like('wp_travel_engine_') . '%'
                        )
                    );

                    if ($foreign) {
                        $notes[] = sprintf(
                            '%d option row(s) named wp_travel_engine_* were found and deliberately excluded. Those belong to WP Travel Engine, a different plugin by a different vendor, and are almost certainly residue from a past install. Never treat a wp_travel_engine_ prefix as a WP Travel signal.',
                            $foreign
                        );
                    }
                }

                return array(
                    'wp_travel'     => $core,
                    'wp_travel_pro' => $pro,
                    'addons'        => self::addon_plugins(),
                    'pro_modules'   => self::pro_modules(),
                    'post_types'    => $post_types,
                    'taxonomies'    => $taxonomies,
                    'tables'        => $tables,
                    'options'       => $options,
                    'notes'         => $notes,
                );
            },
        ));
    }

    /* ------------------------------------------------------ list-travel-addons */

    protected function register_list_addons() {
        wp_register_ability(self::NS . 'list-travel-addons', array(
            'label'       => 'List WP Travel add-ons',
            'description' => 'List every WP Travel add-on plugin installed on this site, active or not, with its version and description straight from its own plugin header. Use it to find out whether a capability a caller wants — fixed departures, group discounts, trip extras, partial payment — is present before trying to use data that only exists when it is. WP Travel Pro is a single plugin containing many features rather than a family of separate add-ons, so its bundled modules are reported separately, read live from WP Travel\'s own module registry, each with the class that implements it and whether that class is actually loaded.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'active_only' => array(
                        'type'        => 'boolean',
                        'description' => 'Return only add-ons that are switched on. Installed-but-inactive add-ons still own their data, so the default returns both.',
                        'default'     => false,
                    ),
                ),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'core'     => $this->plugin_schema('WP Travel core, for context.'),
                    'pro'      => $this->plugin_schema('WP Travel Pro, for context.'),
                    'addons'   => array(
                        'type'        => 'array',
                        'description' => 'Separately installed add-on plugins, sorted by name.',
                        'items'       => $this->plugin_schema('An add-on plugin.'),
                    ),
                    'modules'  => array(
                        'type'        => 'array',
                        'description' => 'WP Travel Pro\'s bundled feature modules, read from WP Travel\'s own registry rather than from any list held here. Each carries the module key, its label, the class implementing it, whether that class is loaded, and what the settings say. "loaded" is the fact to act on; "enabled" is only what the stored settings intend, and the two disagree on a site whose settings predate the module. Empty when Pro is not active.',
                        'items'       => array('type' => 'object'),
                    ),
                    'total'    => array('type' => 'integer', 'description' => 'Number of add-on plugins returned. Bundled modules are counted separately, under module_total.'),
                    'module_total' => array('type' => 'integer', 'description' => 'Number of Pro modules the registry reported.'),
                    'caveat'   => array(
                        'type'        => 'string',
                        'description' => 'What this listing cannot see. Always populated; read it before concluding a feature is missing.',
                    ),
                ),
                'required' => array('core', 'pro', 'addons', 'modules', 'total', 'module_total', 'caveat'),
            ),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $addons = self::addon_plugins();

                if (!empty($input['active_only'])) {
                    $addons = array_values(array_filter($addons, function ($addon) {
                        return !empty($addon['active']);
                    }));
                }

                $modules = self::pro_modules();

                return array(
                    'core'         => self::plugin_info(self::PLUGIN_CORE),
                    'pro'          => self::plugin_info(self::PLUGIN_PRO, 'WP_Travel_Pro'),
                    'addons'       => $addons,
                    'modules'      => $modules,
                    'total'        => count($addons),
                    'module_total' => count($modules),
                    'caveat'       => $modules
                        ? 'Modules come from WP Travel\'s live registry, so this list is whatever Pro currently registers, not a list written into this plugin. Where a module\'s "loaded" and "enabled" disagree, trust "loaded".'
                        : 'No Pro modules were reported. Either WP Travel Pro is inactive, or it registers its modules under a different name than the one read here; the add-on plugin list above is unaffected either way.',
                );
            },
        ));
    }

    /* ------------------------------------------------------------- list-trips */

    protected function register_list_trips() {
        $taxonomies = array_keys(WTA_Trip::default_taxonomies());

        wp_register_ability(self::NS . 'list-trips', array(
            'label'       => 'List and filter trips',
            'description' => 'Find trips by destination, activity, trip type, keyword, duration, price, publication status or free-text search. Returns a summary per trip — title, status, duration, price, terms — not the full record, so a broad search stays cheap. Feed the returned IDs to get-trip-detail for everything else.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'search'      => array(
                        'type'        => 'string',
                        'description' => 'Free-text search across trip title and content.',
                        'default'     => '',
                    ),
                    'status'      => array(
                        'type'        => 'string',
                        'description' => 'Publication status to match. "any" includes drafts and private trips; the default returns only what a visitor can see.',
                        'enum'        => array('publish', 'draft', 'pending', 'private', 'future', 'trash', 'any'),
                        'default'     => 'publish',
                    ),
                    'destination' => array(
                        'type'        => 'array',
                        'description' => 'Destination term slugs or numeric IDs. Multiple values are OR-ed. Taxonomy travel_locations.',
                        'items'       => array('type' => 'string'),
                    ),
                    'activity'    => array(
                        'type'        => 'array',
                        'description' => 'Activity term slugs or numeric IDs. Taxonomy activity.',
                        'items'       => array('type' => 'string'),
                    ),
                    'trip_type'   => array(
                        'type'        => 'array',
                        'description' => 'Trip type term slugs or numeric IDs. Taxonomy itinerary_types.',
                        'items'       => array('type' => 'string'),
                    ),
                    'keyword'     => array(
                        'type'        => 'array',
                        'description' => 'Keyword term slugs or numeric IDs. Taxonomy travel_keywords.',
                        'items'       => array('type' => 'string'),
                    ),
                    'min_days'    => array(
                        'type'        => 'integer',
                        'description' => 'Shortest duration to include, in days, read from wp_travel_trip_duration.',
                        'minimum'     => 0,
                    ),
                    'max_days'    => array(
                        'type'        => 'integer',
                        'description' => 'Longest duration to include, in days.',
                        'minimum'     => 0,
                    ),
                    'min_price'   => array(
                        'type'        => 'number',
                        'description' => 'Lowest price to include. Read the warning in the response before trusting this: it filters on the wp_travel_trip_price meta field, which is 0 on trips whose real pricing lives in the pricing table, so a price filter can silently exclude priced trips.',
                        'minimum'     => 0,
                    ),
                    'max_price'   => array(
                        'type'        => 'number',
                        'description' => 'Highest price to include. Same warning as min_price.',
                        'minimum'     => 0,
                    ),
                    'per_page'    => array(
                        'type'        => 'integer',
                        'description' => 'Trips per page. Capped so a whole catalogue cannot be pulled in one request.',
                        'minimum'     => 1,
                        'maximum'     => self::TRIP_LIMIT,
                        'default'     => 20,
                    ),
                    'page'        => array(
                        'type'        => 'integer',
                        'description' => 'Page number, counting from 1.',
                        'minimum'     => 1,
                        'default'     => 1,
                    ),
                    'orderby'     => array(
                        'type'        => 'string',
                        'description' => 'Sort field. "title" is alphabetical, "date" is newest first, "menu_order" is the hand-set order.',
                        'enum'        => array('title', 'date', 'modified', 'menu_order', 'ID'),
                        'default'     => 'title',
                    ),
                    'order'       => array(
                        'type'        => 'string',
                        'enum'        => array('ASC', 'DESC'),
                        'default'     => 'ASC',
                        'description' => 'Sort direction.',
                    ),
                ),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trips'    => array(
                        'type'        => 'array',
                        'description' => 'Matching trips, one summary object each.',
                        'items'       => array('type' => 'object'),
                    ),
                    'total'    => array('type' => 'integer', 'description' => 'Total matches across all pages, not the size of this page.'),
                    'pages'    => array('type' => 'integer', 'description' => 'Number of pages at the requested per_page.'),
                    'page'     => array('type' => 'integer', 'description' => 'Page returned.'),
                    'warnings' => array(
                        'type'        => 'array',
                        'description' => 'Anything about this particular query that would make its results misleading.',
                        'items'       => array('type' => 'string'),
                    ),
                ),
                'required' => array('trips', 'total', 'pages', 'page', 'warnings'),
            ),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) use ($taxonomies) {
                $check = self::require_core();

                if (is_wp_error($check)) {
                    return $check;
                }

                $per_page = isset($input['per_page']) ? absint($input['per_page']) : 20;
                $per_page = min(self::TRIP_LIMIT, max(1, $per_page));
                $page     = isset($input['page']) ? max(1, absint($input['page'])) : 1;
                $status   = isset($input['status']) ? sanitize_key($input['status']) : 'publish';

                $args = array(
                    'post_type'      => WTA_Trip::post_type(),
                    'post_status'    => 'any' === $status ? 'any' : $status,
                    'posts_per_page' => $per_page,
                    'paged'          => $page,
                    'orderby'        => isset($input['orderby']) ? sanitize_key($input['orderby']) : 'title',
                    'order'          => (isset($input['order']) && 'DESC' === strtoupper($input['order'])) ? 'DESC' : 'ASC',
                );

                if (!empty($input['search'])) {
                    $args['s'] = sanitize_text_field((string) $input['search']);
                }

                // Slug and ID are both accepted because a caller that found a
                // term through list-terms has an ID, and one working from a URL
                // has a slug. Deciding per value costs nothing and removes a
                // whole class of "which do I pass" mistakes.
                $tax_map = array(
                    'destination' => 'travel_locations',
                    'activity'    => 'activity',
                    'trip_type'   => 'itinerary_types',
                    'keyword'     => 'travel_keywords',
                );

                $tax_query = array();
                $warnings  = array();

                foreach ($tax_map as $key => $taxonomy) {
                    if (empty($input[$key]) || !is_array($input[$key])) {
                        continue;
                    }

                    if (!taxonomy_exists($taxonomy)) {
                        $warnings[] = sprintf('Filter "%s" was ignored: the taxonomy %s is not registered on this site.', $key, $taxonomy);
                        continue;
                    }

                    $ids   = array();
                    $slugs = array();

                    foreach ($input[$key] as $value) {
                        $value = is_scalar($value) ? (string) $value : '';

                        if ('' === $value) {
                            continue;
                        }

                        if (ctype_digit($value)) {
                            $ids[] = (int) $value;
                        } else {
                            $slugs[] = sanitize_title($value);
                        }
                    }

                    if ($ids) {
                        $tax_query[] = array('taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $ids);
                    }

                    if ($slugs) {
                        $tax_query[] = array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $slugs);
                    }
                }

                if (count($tax_query) > 1) {
                    // Two filters mean "both", one filter with several values
                    // means "any of these" — the behaviour a person expects from
                    // a faceted search.
                    $tax_query['relation'] = 'AND';
                }

                if ($tax_query) {
                    $args['tax_query'] = $tax_query;
                }

                $meta_query = array();

                if (isset($input['min_days']) || isset($input['max_days'])) {
                    $min = isset($input['min_days']) ? absint($input['min_days']) : 0;
                    $max = isset($input['max_days']) ? absint($input['max_days']) : PHP_INT_MAX;

                    $meta_query[] = array(
                        'key'     => 'wp_travel_trip_duration',
                        'value'   => array($min, $max),
                        'type'    => 'NUMERIC',
                        'compare' => 'BETWEEN',
                    );
                }

                if (isset($input['min_price']) || isset($input['max_price'])) {
                    $min = isset($input['min_price']) ? (float) $input['min_price'] : 0;
                    $max = isset($input['max_price']) ? (float) $input['max_price'] : PHP_INT_MAX;

                    $meta_query[] = array(
                        'key'     => 'wp_travel_trip_price',
                        'value'   => array($min, $max),
                        'type'    => 'DECIMAL(20,4)',
                        'compare' => 'BETWEEN',
                    );

                    $warnings[] = 'A price filter was applied to the wp_travel_trip_price meta field. On this install that field reads 0 on trips whose pricing is held in the wt_pricings table, so priced trips may be missing from these results. Confirm any trip\'s real price with get-trip-pricing.';
                }

                if (count($meta_query) > 1) {
                    $meta_query['relation'] = 'AND';
                }

                if ($meta_query) {
                    $args['meta_query'] = $meta_query;
                }

                $query = new WP_Query($args);
                $trips = array();

                foreach ($query->posts as $post) {
                    $trips[] = self::prepare_trip_summary($post);
                }

                return array(
                    'trips'    => $trips,
                    'total'    => (int) $query->found_posts,
                    'pages'    => (int) $query->max_num_pages,
                    'page'     => $page,
                    'warnings' => $warnings,
                );
            },
        ));
    }

    /* -------------------------------------------------------- get-trip-detail */

    protected function register_get_trip_full() {
        wp_register_ability(self::NS . 'get-trip-detail', array(
            'label'       => 'Get a trip in full',
            'description' => 'Return one trip as it is actually stored: post fields, every taxonomy term with its taxonomy, and all post meta rather than a hand-picked subset — so a key this plugin has never heard of is still visible. Complements get-trip, which returns the same trip through the Nyuchi itinerary schema; use get-trip when you want the authored legs, route and seasonality, and this when you want the raw record. Neither returns real pricing: that is get-trip-pricing.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_id'         => array(
                        'type'        => 'integer',
                        'description' => 'Post ID of the trip.',
                        'minimum'     => 1,
                    ),
                    'include_content' => array(
                        'type'        => 'boolean',
                        'description' => 'Include post_content and the long HTML meta fields. Trip overview HTML on this site can contain pasted editor wrapper markup, so it is large and messy; set false when you only need the structured facts.',
                        'default'     => true,
                    ),
                ),
                'required'   => array('trip_id'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip'       => array('type' => 'object', 'description' => 'Post fields: ID, title, slug, status, dates, author, permalink, featured image.'),
                    'facts'      => array('type' => 'object', 'description' => 'The normalised trip fields this plugin already understands, by their plain names rather than WP Travel meta keys.'),
                    'terms'      => array('type' => 'object', 'description' => 'Taxonomy slug => array of terms assigned to this trip.'),
                    'meta'       => array('type' => 'object', 'description' => 'All post meta, keyed by meta key. Returned wholesale rather than filtered, because guessing which keys matter is how a caller ends up blind to an add-on\'s data. Serialised values are unserialised by WordPress before they get here.'),
                    'days'       => array('type' => 'array', 'description' => 'WP Travel\'s day-by-day itinerary, from wp_travel_trip_itinerary_data.', 'items' => array('type' => 'object')),
                    'warnings'   => array('type' => 'array', 'description' => 'Things about this trip a caller should know before acting on it.', 'items' => array('type' => 'string')),
                ),
                'required'   => array('trip', 'facts', 'terms', 'meta', 'days', 'warnings'),
            ),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $trip_id = isset($input['trip_id']) ? absint($input['trip_id']) : 0;
                $check   = self::require_trip($trip_id);

                if (is_wp_error($check)) {
                    return $check;
                }

                $with_content = !isset($input['include_content']) || (bool) $input['include_content'];
                $post         = get_post($trip_id);
                $warnings     = array();

                $meta = array();

                foreach ((array) get_post_meta($trip_id) as $key => $values) {
                    // Protected keys starting with an underscore are included:
                    // Yoast primary-term keys live there and are exactly what a
                    // caller editing classification needs to see. This ability
                    // is read-only and gated on edit_posts.
                    $value = (is_array($values) && 1 === count($values)) ? reset($values) : $values;

                    if (!$with_content && self::is_long_html_key($key)) {
                        $meta[$key] = '[omitted: include_content was false]';
                        continue;
                    }

                    $meta[$key] = maybe_unserialize($value);
                }

                $price = get_post_meta($trip_id, 'wp_travel_trip_price', true);

                if ('' === $price || '0' === (string) $price || 0 === (int) $price) {
                    $warnings[] = 'wp_travel_trip_price is empty or zero. That does not mean the trip is free: pricing on this install is held in the wt_pricings table. Call get-trip-pricing.';
                }

                $days = get_post_meta($trip_id, WTA_Trip::ITINERARY_META, true);
                $days = is_array($days) ? array_values($days) : array();

                return array(
                    'trip'     => array(
                        'id'        => (int) $post->ID,
                        'title'     => get_the_title($post),
                        'slug'      => $post->post_name,
                        'status'    => $post->post_status,
                        'type'      => $post->post_type,
                        'author'    => (int) $post->post_author,
                        'created'   => $post->post_date_gmt,
                        'modified'  => $post->post_modified_gmt,
                        'permalink' => get_permalink($post),
                        'thumbnail' => (int) get_post_thumbnail_id($post),
                        'excerpt'   => $post->post_excerpt,
                        'content'   => $with_content ? $post->post_content : '',
                    ),
                    'facts'    => WTA_Trip::facts($trip_id),
                    'terms'    => self::trip_terms($trip_id),
                    'meta'     => $meta,
                    'days'     => $days,
                    'warnings' => $warnings,
                );
            },
        ));
    }

    /* ------------------------------------------------------------ create-trip */

    protected function register_create_trip() {
        wp_register_ability(self::NS . 'create-trip', array(
            'label'       => 'Create a trip',
            'description' => 'Create a new WP Travel trip, optionally with meta fields and taxonomy terms in the same call. Defaults to a dry run that reports exactly what would be created and writes nothing; pass dry_run false to actually create it. New trips default to draft status, so an API client cannot publish an unreviewed trip by omitting a field. Pricing is not settable here — it lives in a separate table and has its own ability.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'title'   => array(
                        'type'        => 'string',
                        'description' => 'Trip title. Required and must not be blank.',
                        'minLength'   => 1,
                    ),
                    'content' => array('type' => 'string', 'description' => 'Post body HTML.', 'default' => ''),
                    'excerpt' => array('type' => 'string', 'description' => 'Short summary.', 'default' => ''),
                    'slug'    => array('type' => 'string', 'description' => 'URL segment. Derived from the title when empty.', 'default' => ''),
                    'status'  => array(
                        'type'        => 'string',
                        'description' => 'Publication status. Defaults to draft so nothing goes live unreviewed.',
                        'enum'        => array('draft', 'pending', 'publish', 'private'),
                        'default'     => 'draft',
                    ),
                    'meta'    => array(
                        'type'        => 'object',
                        'description' => 'WP Travel meta fields to set, keyed by meta key. Only the keys this plugin knows and sanitises are accepted; anything else is reported as rejected rather than written. Call wp-travel-status or get-trip-detail on an existing trip to see the full key set.',
                    ),
                    'terms'   => array(
                        'type'        => 'object',
                        'description' => 'Taxonomy slug => array of term IDs or slugs to assign. Slugs that do not exist are reported, not created; use create-term for that.',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to create anything.',
                    ),
                ),
                'required'   => array('title'),
            ),

            'output_schema' => $this->write_result_schema('The trip that was created, or would be.'),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $check = self::require_core();

                if (is_wp_error($check)) {
                    return $check;
                }

                if (!current_user_can('publish_posts')) {
                    return new WP_Error(
                        'wta_forbidden',
                        'You are not allowed to create trips.',
                        array('status' => 403)
                    );
                }

                $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

                if ('' === trim($title)) {
                    return new WP_Error('wta_empty_value', 'title cannot be empty.', array('status' => 400));
                }

                // Absent means dry run. Anyone who wants a trip created has to
                // say so, and a malformed call fails safe rather than littering
                // the catalogue with half-formed posts.
                $dry = !isset($input['dry_run']) || (bool) $input['dry_run'];

                $status = isset($input['status']) ? sanitize_key($input['status']) : 'draft';

                if (!in_array($status, array('draft', 'pending', 'publish', 'private'), true)) {
                    $status = 'draft';
                }

                $postarr = array(
                    'post_type'    => WTA_Trip::post_type(),
                    'post_title'   => $title,
                    'post_status'  => $status,
                    'post_content' => isset($input['content']) ? wp_kses_post((string) $input['content']) : '',
                    'post_excerpt' => isset($input['excerpt']) ? sanitize_textarea_field((string) $input['excerpt']) : '',
                    'post_name'    => isset($input['slug']) ? sanitize_title((string) $input['slug']) : '',
                );

                $meta  = self::partition_meta(isset($input['meta']) ? $input['meta'] : array());
                $terms = self::resolve_terms(isset($input['terms']) ? $input['terms'] : array());

                if ($dry) {
                    return array(
                        'dry_run'  => true,
                        'trip_id'  => 0,
                        'changed'  => array_merge(array('post'), array_keys($meta['accepted']), array_keys($terms['resolved'])),
                        'rejected' => array_merge($meta['rejected'], $terms['rejected']),
                        'result'   => array('post' => $postarr, 'meta' => $meta['accepted'], 'terms' => $terms['resolved']),
                        'note'     => 'Nothing was created. Call again with dry_run false to create this trip.',
                    );
                }

                $trip_id = wp_insert_post($postarr, true);

                if (is_wp_error($trip_id)) {
                    return new WP_Error('wta_trip_not_created', $trip_id->get_error_message(), array('status' => 400));
                }

                foreach ($meta['accepted'] as $key => $value) {
                    update_post_meta($trip_id, $key, $value);
                }

                foreach ($terms['resolved'] as $taxonomy => $term_ids) {
                    wp_set_object_terms($trip_id, $term_ids, $taxonomy, false);
                }

                return array(
                    'dry_run'  => false,
                    'trip_id'  => (int) $trip_id,
                    'changed'  => array_merge(array('post'), array_keys($meta['accepted']), array_keys($terms['resolved'])),
                    'rejected' => array_merge($meta['rejected'], $terms['rejected']),
                    'result'   => self::prepare_trip_summary(get_post($trip_id)),
                    'note'     => trim('The trip has no pricing yet: pricing is a separate table, so call set-trip-pricing next. ' . self::price_meta_caution($meta['accepted'])),
                );
            },
        ));
    }

    /* ------------------------------------------------------------ update-trip */

    protected function register_update_trip() {
        wp_register_ability(self::NS . 'update-trip', array(
            'label'       => 'Update a trip',
            'description' => 'Change a trip\'s post fields and WP Travel meta. Only the properties supplied are touched, so a partial update cannot blank the fields it does not mention. Defaults to a dry run that reports the before and after of every field it would change and writes nothing; pass dry_run false to apply. This writes WP Travel\'s fields; the Nyuchi itinerary groups are written by update-itinerary instead.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_id' => array('type' => 'integer', 'description' => 'Post ID of the trip to write to.', 'minimum' => 1),
                    'title'   => array('type' => 'string', 'description' => 'New title. Omit to leave unchanged.'),
                    'content' => array('type' => 'string', 'description' => 'New post body HTML. Omit to leave unchanged.'),
                    'excerpt' => array('type' => 'string', 'description' => 'New excerpt. Omit to leave unchanged.'),
                    'slug'    => array('type' => 'string', 'description' => 'New URL segment. Changing this breaks existing links unless a redirect is added.'),
                    'status'  => array(
                        'type'        => 'string',
                        'description' => 'New publication status.',
                        'enum'        => array('draft', 'pending', 'publish', 'private', 'trash'),
                    ),
                    'meta'    => array(
                        'type'        => 'object',
                        'description' => 'WP Travel meta keys to write. An empty string clears a field; omitting the key leaves it alone. Unknown keys are rejected and listed, never written blind.',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to write anything.',
                    ),
                ),
                'required'   => array('trip_id'),
            ),

            'output_schema' => $this->write_result_schema('Field-by-field before and after.'),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
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

                $dry  = !isset($input['dry_run']) || (bool) $input['dry_run'];
                $post = get_post($trip_id);

                $postarr = array('ID' => $trip_id);
                $changed = array();

                $post_map = array(
                    'title'   => array('post_title', 'sanitize_text_field'),
                    'content' => array('post_content', 'wp_kses_post'),
                    'excerpt' => array('post_excerpt', 'sanitize_textarea_field'),
                    'slug'    => array('post_name', 'sanitize_title'),
                    'status'  => array('post_status', 'sanitize_key'),
                );

                foreach ($post_map as $key => $spec) {
                    if (!array_key_exists($key, $input)) {
                        continue;
                    }

                    list($field, $filter) = $spec;
                    $value = call_user_func($filter, (string) $input[$key]);

                    if ($value === $post->$field) {
                        continue;
                    }

                    $postarr[$field]  = $value;
                    $changed[$field]  = array('from' => $post->$field, 'to' => $value);
                }

                $meta = self::partition_meta(isset($input['meta']) ? $input['meta'] : array());

                foreach ($meta['accepted'] as $key => $value) {
                    $current = get_post_meta($trip_id, $key, true);

                    if ((string) $current === (string) $value) {
                        continue;
                    }

                    $changed[$key] = array('from' => $current, 'to' => $value);
                }

                if ($dry) {
                    return array(
                        'dry_run'  => true,
                        'trip_id'  => $trip_id,
                        'changed'  => array_keys($changed),
                        'rejected' => $meta['rejected'],
                        'result'   => $changed,
                        'note'     => 'Nothing was written. Call again with dry_run false to apply these changes.',
                    );
                }

                if (count($postarr) > 1) {
                    $updated = wp_update_post($postarr, true);

                    if (is_wp_error($updated)) {
                        return new WP_Error('wta_trip_not_updated', $updated->get_error_message(), array('status' => 400));
                    }
                }

                foreach ($meta['accepted'] as $key => $value) {
                    if ('' === $value) {
                        delete_post_meta($trip_id, $key);
                        continue;
                    }

                    update_post_meta($trip_id, $key, $value);
                }

                return array(
                    'dry_run'  => false,
                    'trip_id'  => $trip_id,
                    'changed'  => array_keys($changed),
                    'rejected' => $meta['rejected'],
                    'result'   => $changed,
                    'note'     => self::price_meta_caution($meta['accepted']),
                );
            },
        ));
    }

    /**
     * Warn when a caller writes the price meta field.
     *
     * The field is WP Travel's, so writing it is allowed, but it is not where
     * the price actually lives on this install. A caller that sets it and walks
     * away has changed a display fallback and not the price, which is the exact
     * mistake this whole file exists to make hard to commit silently.
     *
     * @param array<string, string> $accepted Meta that was written.
     * @return string Empty when the price field was not touched.
     */
    protected static function price_meta_caution($accepted) {
        if (!array_key_exists('wp_travel_trip_price', $accepted)) {
            return '';
        }

        return 'wp_travel_trip_price was written. On this install that field is a display fallback: the price WP Travel actually charges comes from the wt_pricings table. Use set-trip-pricing to change the price itself.';
    }

    /* --------------------------------------------------- set-itinerary-days */

    protected function register_set_itinerary_days() {
        wp_register_ability(self::NS . 'set-itinerary-days', array(
            'label'       => 'Write the day-by-day itinerary',
            'description' => 'Replace WP Travel\'s own day-by-day itinerary on a trip — the numbered days the single-trip template renders. This is the list get-trip returns as "days" and that the Nyuchi legs reference by index, so renumbering it silently re-points those legs; read the trip first. Supplied days replace the whole list rather than merging, because a merge cannot express a deletion. Defaults to a dry run.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_id' => array('type' => 'integer', 'description' => 'Post ID of the trip.', 'minimum' => 1),
                    'days'    => array(
                        'type'        => 'array',
                        'description' => 'The complete new day list, in order. An empty array clears the itinerary.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'label' => array('type' => 'string', 'description' => 'Day label. Defaults to "Day N" when omitted.'),
                                'title' => array('type' => 'string', 'description' => 'Day heading.'),
                                'desc'  => array('type' => 'string', 'description' => 'Day description, HTML. A literal percent sign here is what scan-compat reports on.'),
                                'date'  => array('type' => 'string', 'description' => 'Optional date for this day.'),
                                'time'  => array('type' => 'string', 'description' => 'Optional time for this day.'),
                            ),
                        ),
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to overwrite the itinerary.',
                    ),
                ),
                'required'   => array('trip_id', 'days'),
            ),

            'output_schema' => $this->write_result_schema('The day list as it would be stored.'),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
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

                if (!isset($input['days']) || !is_array($input['days'])) {
                    return new WP_Error('wta_bad_format', 'days must be an array of day objects.', array('status' => 400));
                }

                $dry     = !isset($input['dry_run']) || (bool) $input['dry_run'];
                $current = get_post_meta($trip_id, WTA_Trip::ITINERARY_META, true);
                $current = is_array($current) ? $current : array();

                $clean = array();

                foreach (array_values($input['days']) as $i => $day) {
                    if (!is_array($day)) {
                        continue;
                    }

                    // Same shape and same sanitisers as the REST field in
                    // WTA_Trip_Meta, so a day written here and a day written
                    // over REST are indistinguishable in the database.
                    $clean[$i] = array(
                        'label' => isset($day['label']) ? sanitize_text_field($day['label']) : sprintf('Day %d', $i + 1),
                        'title' => isset($day['title']) ? sanitize_text_field($day['title']) : '',
                        'date'  => isset($day['date']) ? sanitize_text_field($day['date']) : '',
                        'time'  => isset($day['time']) ? sanitize_text_field($day['time']) : '',
                        'desc'  => isset($day['desc']) ? wp_kses_post($day['desc']) : '',
                    );
                }

                $note = count($clean) === count($current)
                    ? ''
                    : sprintf('Day count changes from %d to %d. Any itinerary leg that referenced a day by index now points somewhere else.', count($current), count($clean));

                if ($dry) {
                    return array(
                        'dry_run'  => true,
                        'trip_id'  => $trip_id,
                        'changed'  => array('days'),
                        'rejected' => array(),
                        'result'   => array('from_count' => count($current), 'to_count' => count($clean), 'days' => $clean),
                        'note'     => trim('Nothing was written. Call again with dry_run false to replace the itinerary. ' . $note),
                    );
                }

                update_post_meta($trip_id, WTA_Trip::ITINERARY_META, $clean);

                return array(
                    'dry_run'  => false,
                    'trip_id'  => $trip_id,
                    'changed'  => array('days'),
                    'rejected' => array(),
                    'result'   => array('from_count' => count($current), 'to_count' => count($clean), 'days' => $clean),
                    'note'     => $note,
                );
            },
        ));
    }

    /* ------------------------------------------------------- set-trip-terms */

    protected function register_set_trip_terms() {
        wp_register_ability(self::NS . 'set-trip-terms', array(
            'label'       => 'Assign taxonomy terms to a trip',
            'description' => 'Classify a trip: set or add its destinations, activities, trip types and keywords. Terms may be given as IDs or slugs. By default the supplied list replaces what the trip already had in that taxonomy, which is what makes a reclassification possible; set append true to add without removing. Terms that do not exist are reported rather than created — create-term exists for that, and creates drafted so a new archive is not published by accident. Defaults to a dry run.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_id' => array('type' => 'integer', 'description' => 'Post ID of the trip.', 'minimum' => 1),
                    'terms'   => array(
                        'type'        => 'object',
                        'description' => 'Taxonomy slug => array of term IDs or slugs. Taxonomies not mentioned are untouched. An empty array clears that taxonomy on this trip.',
                    ),
                    'append'  => array(
                        'type'        => 'boolean',
                        'description' => 'Add to the existing terms instead of replacing them.',
                        'default'     => false,
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to change the trip\'s classification.',
                    ),
                ),
                'required'   => array('trip_id', 'terms'),
            ),

            'output_schema' => $this->write_result_schema('Terms before and after, per taxonomy.'),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
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

                $dry    = !isset($input['dry_run']) || (bool) $input['dry_run'];
                $append = !empty($input['append']);
                $terms  = self::resolve_terms(isset($input['terms']) ? $input['terms'] : array());

                $before = self::trip_terms($trip_id);
                $result = array();

                foreach ($terms['resolved'] as $taxonomy => $term_ids) {
                    $result[$taxonomy] = array(
                        'from' => isset($before[$taxonomy]) ? wp_list_pluck($before[$taxonomy], 'term_id') : array(),
                        'to'   => $term_ids,
                    );
                }

                if ($dry) {
                    return array(
                        'dry_run'  => true,
                        'trip_id'  => $trip_id,
                        'changed'  => array_keys($terms['resolved']),
                        'rejected' => $terms['rejected'],
                        'result'   => $result,
                        'note'     => 'Nothing was changed. Call again with dry_run false to apply this classification.',
                    );
                }

                foreach ($terms['resolved'] as $taxonomy => $term_ids) {
                    wp_set_object_terms($trip_id, $term_ids, $taxonomy, $append);
                }

                return array(
                    'dry_run'  => false,
                    'trip_id'  => $trip_id,
                    'changed'  => array_keys($terms['resolved']),
                    'rejected' => $terms['rejected'],
                    'result'   => $result,
                    'note'     => '',
                );
            },
        ));
    }

    /* ------------------------------------------------------ get-trip-pricing */

    protected function register_get_pricing() {
        wp_register_ability(self::NS . 'get-trip-pricing', array(
            'label'       => 'Read trip pricing',
            'description' => 'Return a trip\'s real pricing. WP Travel keeps pricing in the wt_pricings table with a companion wt_price_category_relation table, not in post meta, which is why wp_travel_trip_price so often reads 0 on a priced trip. This reads those tables directly and returns whatever columns they actually have — the shape is discovered at call time with SHOW COLUMNS rather than assumed, so it stays correct across WP Travel versions. The post-meta price is returned too, clearly labelled, so the discrepancy is visible rather than hidden.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_id' => array('type' => 'integer', 'description' => 'Post ID of the trip.', 'minimum' => 1),
                ),
                'required'   => array('trip_id'),
            ),

            'output_schema' => $this->table_read_schema('Pricing rows for this trip, plus the price categories they reference.'),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $trip_id = isset($input['trip_id']) ? absint($input['trip_id']) : 0;
                $check   = self::require_trip($trip_id);

                if (is_wp_error($check)) {
                    return $check;
                }

                $pricings  = self::read_trip_rows('wt_pricings', $trip_id);
                $relations = self::read_related_rows('wt_price_category_relation', $pricings);

                $meta_price = get_post_meta($trip_id, 'wp_travel_trip_price', true);
                $notes      = array(
                    sprintf('Post meta wp_travel_trip_price reads "%s". Treat it as a display fallback, not the price.', (string) $meta_price),
                );

                if ($pricings['exists'] && empty($pricings['rows'])) {
                    $notes[] = 'The pricing table exists but holds no rows for this trip. Either the trip has never been priced, or it is priced by an add-on that stores elsewhere.';
                }

                if (!$pricings['exists']) {
                    $notes[] = 'The pricing table does not exist on this site, so pricing is not held relationally here. Fall back to the post meta price.';
                }

                // This plugin carries its own cost block, which shadows WP
                // Travel's pricing table. Reporting the overlap is in scope
                // here; writing to that block is not, and no ability in this
                // file does. Pricing belongs to WP Travel.
                $shadow = get_post_meta($trip_id, 'wta_cost', true);

                if (!empty($shadow) && is_array($shadow)) {
                    $notes[] = 'This trip also carries a wta_cost block, a second cost model held by Nyuchi Travel Addons alongside WP Travel\'s pricing table. Two homes for one price will drift. WP Travel\'s table is the owner; treat wta_cost as presentation input under review, and never as the price.';
                }

                return array(
                    'trip_id'    => $trip_id,
                    'tables'     => array(
                        'pricings'  => $pricings,
                        'relations' => $relations,
                    ),
                    'meta_price' => (string) $meta_price,
                    'notes'      => $notes,
                );
            },
        ));
    }

    /* ------------------------------------------------------ set-trip-pricing */

    protected function register_set_pricing() {
        wp_register_ability(self::NS . 'set-trip-pricing', array(
            'label'       => 'Write trip pricing',
            'description' => 'Insert, update or delete a row in WP Travel\'s pricing table for one trip. Column names are not hard-coded anywhere: pass values keyed by the real column names that get-trip-pricing reported, and anything not present in the table is refused rather than written. Writes are scoped to the trip — an update only touches a row that already belongs to it, and an insert forces the trip reference — so a wrong ID cannot reprice someone else\'s trip. Defaults to a dry run. This is a direct table write that bypasses WP Travel\'s own save routine, so anything WP Travel does on save (cache clears, derived fields) does not happen; prefer the WP Travel admin for anything this ability makes awkward.',
            'category'    => self::CATEGORY,

            'input_schema' => $this->table_write_input_schema('pricing row'),

            'output_schema' => $this->write_result_schema('The row as it would be written.'),

            'permission_callback' => array($this, 'can_manage_travel_tables'),

            'execute_callback' => function ($input) {
                return self::write_trip_row('wt_pricings', $input);
            },
        ));
    }

    /* -------------------------------------------------------- get-trip-dates */

    protected function register_get_dates() {
        wp_register_ability(self::NS . 'get-trip-dates', array(
            'label'       => 'Read trip dates and availability',
            'description' => 'Return a trip\'s departure dates and the date/time exclusions applied to them, from WP Travel\'s wt_dates and wt_excluded_dates_times tables. Columns are discovered at call time, so whatever those tables really hold is what comes back. An empty result with the tables present is a real and important answer: it means departure dates have never been entered on this install, and nothing downstream — availability, booking windows, seasonal pricing keyed to dates — has a source. The trip\'s fixed-departure flag from post meta is returned alongside so the two can be reconciled.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'trip_id' => array('type' => 'integer', 'description' => 'Post ID of the trip.', 'minimum' => 1),
                ),
                'required'   => array('trip_id'),
            ),

            'output_schema' => $this->table_read_schema('Date rows for this trip, plus exclusions.'),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $trip_id = isset($input['trip_id']) ? absint($input['trip_id']) : 0;
                $check   = self::require_trip($trip_id);

                if (is_wp_error($check)) {
                    return $check;
                }

                $dates      = self::read_trip_rows('wt_dates', $trip_id);
                $exclusions = self::read_related_rows('wt_excluded_dates_times', $dates);

                $fixed = get_post_meta($trip_id, 'wp_travel_fixed_departure', true);
                $notes = array(
                    sprintf('Post meta wp_travel_fixed_departure reads "%s".', (string) $fixed),
                );

                if ($dates['exists'] && empty($dates['rows'])) {
                    $notes[] = 'The dates table exists but holds no rows for this trip. On this install the table is empty overall, which means departure dates and therefore availability have no home in WP Travel yet — worth saying out loud rather than reporting as "no availability".';
                }

                if (!$dates['exists']) {
                    $notes[] = 'The dates table does not exist on this site.';
                }

                return array(
                    'trip_id'    => $trip_id,
                    'tables'     => array(
                        'dates'      => $dates,
                        'exclusions' => $exclusions,
                    ),
                    'meta_price' => '',
                    'notes'      => $notes,
                );
            },
        ));
    }

    /* -------------------------------------------------------- set-trip-dates */

    protected function register_set_dates() {
        wp_register_ability(self::NS . 'set-trip-dates', array(
            'label'       => 'Write trip dates and availability',
            'description' => 'Insert, update or delete a row in WP Travel\'s departure-dates table for one trip. Works exactly like set-trip-pricing: pass values keyed by the real column names that get-trip-dates reported, unknown columns are refused, writes are scoped to the trip, and it defaults to a dry run. Because the dates table is empty on this install, the first call against it should be a dry run whose reported column list is checked by a human before anything is written — there is no existing row to pattern-match against.',
            'category'    => self::CATEGORY,

            'input_schema' => $this->table_write_input_schema('date row'),

            'output_schema' => $this->write_result_schema('The row as it would be written.'),

            'permission_callback' => array($this, 'can_manage_travel_tables'),

            'execute_callback' => function ($input) {
                return self::write_trip_row('wt_dates', $input);
            },
        ));
    }

    /* --------------------------------------------------------- get-term-detail */

    protected function register_get_term_detail() {
        wp_register_ability(self::NS . 'get-term-detail', array(
            'label'       => 'Get a taxonomy term in full',
            'description' => 'Return one trip taxonomy term with everything attached to it: the core fields, its publication state, the structured facts this plugin adds to destinations and activities, and all raw term meta so an add-on\'s data is visible even when nothing here knows its name. Complements list-terms, which returns many terms in summary; this returns one in depth.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_id'  => array('type' => 'integer', 'description' => 'Term ID.', 'minimum' => 1),
                    'children' => array(
                        'type'        => 'boolean',
                        'description' => 'Also return the term\'s immediate children, for walking a destination tree.',
                        'default'     => false,
                    ),
                ),
                'required'   => array('term_id'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term'     => array('type' => 'object', 'description' => 'Core term fields plus publication state.'),
                    'fields'   => array('type' => 'object', 'description' => 'Structured facts from the Nyuchi term-fields module: country, gateway airport, currency, best months, difficulty and so on, depending on the taxonomy. Empty when that module is disabled or the taxonomy has no field set.'),
                    'meta'     => array('type' => 'object', 'description' => 'All term meta, keyed by meta key.'),
                    'children' => array('type' => 'array', 'description' => 'Immediate child terms, when requested.', 'items' => array('type' => 'object')),
                ),
                'required'   => array('term', 'fields', 'meta', 'children'),
            ),

            'permission_callback' => array($this, 'can_edit_posts'),

            'execute_callback' => function ($input) {
                $term_id = isset($input['term_id']) ? absint($input['term_id']) : 0;
                $term    = $term_id ? get_term($term_id) : null;

                if (!$term instanceof WP_Term) {
                    return new WP_Error('wta_term_not_found', sprintf('No term exists with ID %d.', $term_id), array('status' => 404));
                }

                $allowed = WTA_Trip::default_taxonomies();

                if (!array_key_exists($term->taxonomy, $allowed)) {
                    return new WP_Error(
                        'wta_unknown_taxonomy',
                        sprintf('Taxonomy "%s" is not managed by this plugin.', $term->taxonomy),
                        array('status' => 400)
                    );
                }

                $meta = array();

                foreach ((array) get_term_meta($term_id) as $key => $values) {
                    $value      = (is_array($values) && 1 === count($values)) ? reset($values) : $values;
                    $meta[$key] = maybe_unserialize($value);
                }

                $children = array();

                if (!empty($input['children'])) {
                    $found = get_terms(array(
                        'taxonomy'   => $term->taxonomy,
                        'parent'     => $term_id,
                        'hide_empty' => false,
                        'orderby'    => 'name',
                    ));

                    if (!is_wp_error($found)) {
                        foreach ($found as $child) {
                            $children[] = self::prepare_term($child);
                        }
                    }
                }

                return array(
                    'term'     => self::prepare_term($term),
                    // The fields module is optional, so its absence is an empty
                    // set rather than a fatal — a caller wanting the core term
                    // should still get it.
                    'fields'   => class_exists('WTA_Term_Fields') ? WTA_Term_Fields::all($term_id) : array(),
                    'meta'     => $meta,
                    'children' => $children,
                );
            },
        ));
    }

    /* ------------------------------------------------------------ update-term */

    protected function register_update_term() {
        wp_register_ability(self::NS . 'update-term', array(
            'label'       => 'Update a taxonomy term',
            'description' => 'Rename, re-slug, re-parent or re-describe a trip taxonomy term, and write its structured fields. Only the properties supplied are touched. Renaming a term does not change its slug and therefore does not break its archive URL; changing the slug does. Defaults to a dry run reporting the before and after. Publication state is not set here — set-term-status already does that, in bulk.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_id'     => array('type' => 'integer', 'description' => 'Term ID to update.', 'minimum' => 1),
                    'name'        => array('type' => 'string', 'description' => 'New display name.'),
                    'slug'        => array('type' => 'string', 'description' => 'New URL segment. Changing this breaks links to the existing archive.'),
                    'parent'      => array('type' => 'integer', 'description' => 'New parent term ID, or 0 for top level. Must be in the same taxonomy.', 'minimum' => 0),
                    'description' => array('type' => 'string', 'description' => 'New archive description. Limited HTML is preserved.'),
                    'fields'      => array(
                        'type'        => 'object',
                        'description' => 'Structured facts to write, keyed by the field keys get-term-detail returned under "fields". Fields that do not apply to this term\'s taxonomy are reported as rejected. A blank value clears the field.',
                    ),
                    'dry_run'     => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to write anything.',
                    ),
                ),
                'required'   => array('term_id'),
            ),

            'output_schema' => $this->write_result_schema('Field-by-field before and after.'),

            'permission_callback' => array($this, 'can_manage_categories'),

            'execute_callback' => function ($input) {
                $term_id = isset($input['term_id']) ? absint($input['term_id']) : 0;
                $term    = $term_id ? get_term($term_id) : null;

                if (!$term instanceof WP_Term) {
                    return new WP_Error('wta_term_not_found', sprintf('No term exists with ID %d.', $term_id), array('status' => 404));
                }

                $allowed = WTA_Trip::default_taxonomies();

                if (!array_key_exists($term->taxonomy, $allowed)) {
                    return new WP_Error(
                        'wta_unknown_taxonomy',
                        sprintf('Taxonomy "%s" is not managed by this plugin.', $term->taxonomy),
                        array('status' => 400)
                    );
                }

                $dry      = !isset($input['dry_run']) || (bool) $input['dry_run'];
                $args     = array();
                $changed  = array();
                $rejected = array();

                $core_map = array(
                    'name'        => array('name', 'sanitize_text_field'),
                    'slug'        => array('slug', 'sanitize_title'),
                    'description' => array('description', 'wp_kses_post'),
                );

                foreach ($core_map as $key => $spec) {
                    if (!array_key_exists($key, $input)) {
                        continue;
                    }

                    list($field, $filter) = $spec;
                    $value = call_user_func($filter, (string) $input[$key]);

                    if ($value === $term->$field) {
                        continue;
                    }

                    $args[$field]    = $value;
                    $changed[$field] = array('from' => $term->$field, 'to' => $value);
                }

                if (array_key_exists('parent', $input)) {
                    $parent = absint($input['parent']);

                    if ($parent && !get_term($parent, $term->taxonomy) instanceof WP_Term) {
                        return new WP_Error(
                            'wta_bad_parent',
                            sprintf('The parent term %d does not exist in %s.', $parent, $term->taxonomy),
                            array('status' => 400)
                        );
                    }

                    if ($parent === $term_id) {
                        return new WP_Error('wta_bad_parent', 'A term cannot be its own parent.', array('status' => 400));
                    }

                    if ($parent !== (int) $term->parent) {
                        $args['parent']    = $parent;
                        $changed['parent'] = array('from' => (int) $term->parent, 'to' => $parent);
                    }
                }

                $fields = array();

                if (!empty($input['fields']) && is_array($input['fields'])) {
                    if (!class_exists('WTA_Term_Fields')) {
                        $rejected[] = 'fields: the term-fields module is not loaded on this site.';
                    } else {
                        $known = WTA_Term_Fields::fields($term->taxonomy);

                        foreach ($input['fields'] as $key => $value) {
                            $key = sanitize_key($key);

                            if (!isset($known[$key])) {
                                $rejected[] = sprintf('fields.%s: not a field of the %s taxonomy.', $key, $term->taxonomy);
                                continue;
                            }

                            $fields[$key]           = $value;
                            $changed['fields.' . $key] = array(
                                'from' => WTA_Term_Fields::get($term_id, $key),
                                'to'   => $value,
                            );
                        }
                    }
                }

                if ($dry) {
                    return array(
                        'dry_run'  => true,
                        'trip_id'  => 0,
                        'changed'  => array_keys($changed),
                        'rejected' => $rejected,
                        'result'   => $changed,
                        'note'     => 'Nothing was written. Call again with dry_run false to apply these changes.',
                    );
                }

                if ($args) {
                    $updated = wp_update_term($term_id, $term->taxonomy, $args);

                    if (is_wp_error($updated)) {
                        return new WP_Error('wta_term_not_updated', $updated->get_error_message(), array('status' => 400));
                    }
                }

                foreach ($fields as $key => $value) {
                    WTA_Term_Fields::set($term_id, $key, $value);
                }

                return array(
                    'dry_run'  => false,
                    'trip_id'  => 0,
                    'changed'  => array_keys($changed),
                    'rejected' => $rejected,
                    'result'   => $changed,
                    'note'     => '',
                );
            },
        ));
    }

    /* ------------------------------------------------------ list-travel-posts */

    protected function register_list_travel_posts() {
        wp_register_ability(self::NS . 'list-travel-posts', array(
            'label'       => 'List bookings, enquiries, extras and guides',
            'description' => 'List posts of WP Travel\'s non-trip post types — bookings, enquiries, trip extras and travel guides — with all their meta. Their meta keys were not verifiable when this was written, so nothing is interpreted: the whole meta record comes back as stored and the caller reads what is there. That is deliberately less convenient than named fields and it cannot be wrong about a key that does not exist. Use wp-travel-status first to see which of these types are registered.',
            'category'    => self::CATEGORY,

            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_type' => array(
                        'type'        => 'string',
                        'description' => 'Which WP Travel post type to list. Bookings and enquiries carry customer data, so this ability is gated accordingly.',
                        'enum'        => array('itinerary-booking', 'itinerary-enquiries', 'tour-extras', 'travel-guide'),
                    ),
                    'search'    => array('type' => 'string', 'description' => 'Free-text search.', 'default' => ''),
                    'status'    => array('type' => 'string', 'description' => 'Post status, or "any".', 'default' => 'any'),
                    'per_page'  => array('type' => 'integer', 'description' => 'Results per page, capped.', 'minimum' => 1, 'maximum' => 50, 'default' => 20),
                    'page'      => array('type' => 'integer', 'description' => 'Page number.', 'minimum' => 1, 'default' => 1),
                    'with_meta' => array(
                        'type'        => 'boolean',
                        'description' => 'Include the full meta record for each post. Off by default because booking meta is bulky and contains personal data.',
                        'default'     => false,
                    ),
                ),
                'required'   => array('post_type'),
            ),

            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'posts' => array('type' => 'array', 'description' => 'Matching posts.', 'items' => array('type' => 'object')),
                    'total' => array('type' => 'integer', 'description' => 'Total matches across all pages.'),
                    'pages' => array('type' => 'integer', 'description' => 'Number of pages.'),
                    'note'  => array('type' => 'string', 'description' => 'What is and is not interpreted in this response.'),
                ),
                'required' => array('posts', 'total', 'pages', 'note'),
            ),

            // Bookings and enquiries hold customers' names, emails and payment
            // state. edit_posts is the wrong bar for that; this is the same bar
            // WP Travel's own admin screens sit behind.
            'permission_callback' => array($this, 'can_manage_travel_tables'),

            'execute_callback' => function ($input) {
                $post_type = isset($input['post_type']) ? sanitize_key($input['post_type']) : '';

                if (!in_array($post_type, self::travel_post_types(), true) || WTA_Trip::post_type() === $post_type) {
                    return new WP_Error(
                        'wta_unknown_post_type',
                        sprintf('"%s" is not one of the WP Travel post types this ability lists.', $post_type),
                        array('status' => 400)
                    );
                }

                if (!post_type_exists($post_type)) {
                    return new WP_Error(
                        'wta_post_type_missing',
                        sprintf('The post type "%s" is not registered on this site. Is WP Travel active, and does this install have the feature that registers it?', $post_type),
                        array('status' => 400, 'wp_travel_active' => self::core_available())
                    );
                }

                $per_page = isset($input['per_page']) ? min(50, max(1, absint($input['per_page']))) : 20;
                $page     = isset($input['page']) ? max(1, absint($input['page'])) : 1;

                $query = new WP_Query(array(
                    'post_type'      => $post_type,
                    'post_status'    => isset($input['status']) ? sanitize_key($input['status']) : 'any',
                    's'              => isset($input['search']) ? sanitize_text_field((string) $input['search']) : '',
                    'posts_per_page' => $per_page,
                    'paged'          => $page,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ));

                $with_meta = !empty($input['with_meta']);
                $posts     = array();

                foreach ($query->posts as $post) {
                    $row = array(
                        'id'       => (int) $post->ID,
                        'title'    => get_the_title($post),
                        'status'   => $post->post_status,
                        'created'  => $post->post_date_gmt,
                        'modified' => $post->post_modified_gmt,
                    );

                    if ($with_meta) {
                        $meta = array();

                        foreach ((array) get_post_meta($post->ID) as $key => $values) {
                            $value      = (is_array($values) && 1 === count($values)) ? reset($values) : $values;
                            $meta[$key] = maybe_unserialize($value);
                        }

                        $row['meta'] = $meta;
                    }

                    $posts[] = $row;
                }

                return array(
                    'posts' => $posts,
                    'total' => (int) $query->found_posts,
                    'pages' => (int) $query->max_num_pages,
                    'note'  => 'Meta is returned exactly as stored. No key in these post types was verified against WP Travel source, so nothing here is renamed, typed or interpreted.',
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

    /**
     * Writing WP Travel's tables directly, and reading its bookings, is an
     * administrative act rather than an editorial one.
     *
     * A table write bypasses WP Travel's own save path, and a booking record
     * holds a customer's personal data. Neither belongs behind edit_posts,
     * which a contributor has.
     *
     * @return true|WP_Error
     */
    public function can_manage_travel_tables() {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'wta_forbidden',
                'Writing WP Travel\'s pricing and date tables, and reading booking records, requires an administrator.',
                array('status' => 403)
            );
        }

        return true;
    }

    /* ------------------------------------------------------- detection */

    /**
     * Whether WP Travel is active on this request.
     *
     * Delegates to WTA_Trip::is_available() rather than testing for a class or
     * a constant, so this file and the compat guards agree on what "WP Travel
     * is here" means, and a site that has filtered the trip post type slug is
     * still detected correctly.
     */
    public static function core_available() {
        return class_exists('WTA_Trip') && WTA_Trip::is_available();
    }

    /**
     * Post types WP Travel registers, verified against the live site.
     *
     * The trip type comes from WTA_Trip so a filtered slug is honoured; the
     * rest are literal because they have no shared prefix to match on.
     */
    public static function travel_post_types() {
        $types = array('itinerary-booking', 'itinerary-enquiries', 'tour-extras', 'travel-guide');

        if (class_exists('WTA_Trip')) {
            array_unshift($types, WTA_Trip::post_type());
        }

        return apply_filters('wta_wp_travel_post_types', $types);
    }

    /**
     * Refuse politely when WP Travel is not there.
     *
     * A WP_Error rather than an exception, carrying the detection result in its
     * data, so a caller can tell "WP Travel is off" apart from "your input was
     * wrong" without parsing the message.
     *
     * @return true|WP_Error
     */
    protected static function require_core() {
        if (self::core_available()) {
            return true;
        }

        return new WP_Error(
            'wta_wp_travel_inactive',
            sprintf(
                'WP Travel is not active on this site: the trip post type "%s" is not registered. Call wp-travel-status for what is installed.',
                class_exists('WTA_Trip') ? WTA_Trip::post_type() : 'itineraries'
            ),
            array(
                'status'               => 409,
                'wp_travel_active'     => false,
                'wp_travel_pro_active' => self::plugin_info(self::PLUGIN_PRO, 'WP_Travel_Pro')['active'],
            )
        );
    }

    /**
     * Confirm an ID is a real post of the trip post type.
     *
     * Checks WP Travel is present first: "no such trip" is a misleading answer
     * when the truth is that no trip post type exists at all.
     *
     * @return true|WP_Error
     */
    protected static function require_trip($trip_id) {
        $core = self::require_core();

        if (is_wp_error($core)) {
            return $core;
        }

        if ($trip_id <= 0) {
            return new WP_Error('wta_bad_trip_id', 'trip_id must be a positive post ID.', array('status' => 400));
        }

        $post = get_post($trip_id);

        if (!$post instanceof WP_Post) {
            return new WP_Error('wta_trip_not_found', sprintf('No post exists with ID %d.', $trip_id), array('status' => 404));
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
     * Header data for one plugin file, whether or not it is active.
     *
     * Version comes from the plugin header rather than from a constant. There
     * is no WP_TRAVEL_PRO_VERSION to read — the name is plausible and does not
     * exist — and get_plugin_data() is core, is always right, and works on a
     * plugin that is installed but switched off.
     *
     * get_plugin_data() lives in an admin include that is not loaded on a REST
     * request, which is why it is required here rather than assumed.
     *
     * @param string $file  Plugin file, e.g. wp-travel/wp-travel.php.
     * @param string $class Optional class the plugin defines. "Active" only
     *                      means WordPress was told to load the file; a plugin
     *                      that bailed early on a failed dependency check is
     *                      still active and has defined nothing. Where a class
     *                      name is known, it is the stronger signal, so both
     *                      are reported rather than one being chosen. Only
     *                      pass a class name that has been confirmed to exist:
     *                      a wrong one reports loaded false against active
     *                      true, which reads as a broken plugin. WP Travel
     *                      core is called without one for exactly that reason.
     * @return array<string, mixed>
     */
    protected static function plugin_info($file, $class = '') {
        $out = array(
            'file'        => $file,
            'installed'   => false,
            'active'      => false,
            'loaded'      => '' !== $class ? class_exists($class) : null,
            'version'     => '',
            'name'        => '',
            'description' => '',
        );

        if (!function_exists('get_plugins') || !function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        if (!isset($plugins[$file])) {
            return $out;
        }

        $data = $plugins[$file];

        $out['installed']   = true;
        $out['active']      = is_plugin_active($file);
        $out['version']     = isset($data['Version']) ? $data['Version'] : '';
        $out['name']        = isset($data['Name']) ? $data['Name'] : '';
        $out['description'] = isset($data['Description']) ? wp_strip_all_tags($data['Description']) : '';

        return $out;
    }

    /**
     * WP Travel Pro's bundled feature modules and whether each is switched on.
     *
     * Pro is one plugin containing many features rather than a family of
     * separately installed add-ons, so scanning the plugins directory cannot
     * see them. WP Travel builds its own registry on the 'wptravel_modules'
     * filter, and that is read here.
     *
     * The filter is read directly rather than through the stored settings
     * option: wptravel_get_settings() merges the saved option over the filtered
     * defaults, so a site whose settings were last saved under an older Pro
     * carries a stale module list in the database. The filter is always current.
     *
     * apply_filters() on a name nothing has hooked returns the empty array it
     * was given, so this is safe with Pro absent — no guard needed, and no
     * module name is hard-coded here. Whatever Pro registers is what comes back.
     *
     * Each module names the class that implements it; class_exists() on that is
     * the authoritative "is this feature actually loaded" signal, as opposed to
     * the settings toggle, which only records an intention.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function pro_modules() {
        $modules = apply_filters('wptravel_modules', array());

        if (!is_array($modules)) {
            return array();
        }

        $out = array();

        foreach ($modules as $key => $module) {
            if (!is_array($module)) {
                continue;
            }

            $class = isset($module['core_class']) ? (string) $module['core_class'] : '';

            $out[] = array(
                'key'     => is_string($key) ? $key : '',
                'label'   => isset($module['label']) ? (string) $module['label'] : '',
                'class'   => $class,
                // Three-state on purpose. "loaded" is fact; "enabled" is what
                // the settings say; they disagree on a site whose settings
                // predate the module, and that disagreement is worth seeing.
                'loaded'  => '' !== $class && class_exists($class),
                'enabled' => isset($module['enable']) ? $module['enable'] : null,
            );
        }

        usort($out, function ($a, $b) {
            return strcasecmp($a['key'], $b['key']);
        });

        return $out;
    }

    /**
     * Every installed plugin that is a WP Travel add-on, core and Pro excluded.
     *
     * Directory prefix is the test. It is imperfect — an add-on named something
     * else is missed, and an unrelated plugin called wp-travel-something is
     * included — but it is checkable, unlike a hard-coded list of add-on names
     * that goes stale the moment the vendor ships another one.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function addon_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $out = array();

        foreach (array_keys(get_plugins()) as $file) {
            if (self::PLUGIN_CORE === $file || self::PLUGIN_PRO === $file) {
                continue;
            }

            if (0 !== strpos($file, 'wp-travel')) {
                continue;
            }

            // The Nyuchi plugin itself lives at wp-travel-addons/ and is not a
            // WP Travel add-on in the sense a caller means here.
            if (0 === strpos($file, 'wp-travel-addons/')) {
                continue;
            }

            $out[] = self::plugin_info($file);
        }

        usort($out, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $out;
    }

    /* --------------------------------------------------------- table access */

    /** Prefixed table name for one of the known WP Travel table suffixes. */
    protected static function table_name($suffix) {
        global $wpdb;

        return $wpdb->prefix . $suffix;
    }

    /**
     * A table's real shape, read from the database rather than declared here.
     *
     * Returns column names, types and which column the trip-scoped abilities
     * will treat as the trip reference, so a caller can see the assumption
     * being made and correct it if it is wrong.
     *
     * @return array<string, mixed>
     */
    protected static function describe_table($suffix) {
        global $wpdb;

        $table = self::table_name($suffix);

        $out = array(
            'table'        => $table,
            'suffix'       => $suffix,
            'exists'       => false,
            'rows'         => 0,
            'columns'      => array(),
            'trip_column'  => '',
            'primary_key'  => '',
        );

        if (!in_array($suffix, self::TABLES, true)) {
            return $out;
        }

        // The table name is built from a whitelisted suffix and $wpdb->prefix,
        // so it cannot carry user input; the LIKE value is still prepared.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

        if ($found !== $table) {
            return $out;
        }

        $out['exists'] = true;
        $out['rows']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

        foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM `{$table}`") as $column) {
            $name = isset($column->Field) ? $column->Field : '';

            if ('' === $name) {
                continue;
            }

            $out['columns'][$name] = array(
                'type'     => isset($column->Type) ? $column->Type : '',
                'nullable' => isset($column->Null) && 'YES' === $column->Null,
                'key'      => isset($column->Key) ? $column->Key : '',
                'default'  => isset($column->Default) ? $column->Default : null,
            );

            if (isset($column->Key) && 'PRI' === $column->Key && '' === $out['primary_key']) {
                $out['primary_key'] = $name;
            }
        }

        $out['trip_column'] = self::trip_column($out['columns']);

        return $out;
    }

    /**
     * Which column on a discovered table holds the trip post ID.
     *
     * First candidate that actually exists wins. Returns an empty string when
     * none does, which the callers treat as "this table cannot be scoped to a
     * trip" rather than falling back to something arbitrary.
     */
    protected static function trip_column($columns) {
        foreach (self::TRIP_COLUMN_CANDIDATES as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Rows of a WP Travel table belonging to one trip.
     *
     * @return array<string, mixed> Always includes the table description, so a
     *                              caller that got no rows can still see why.
     */
    protected static function read_trip_rows($suffix, $trip_id) {
        global $wpdb;

        $schema = self::describe_table($suffix);

        $out = array(
            'table'       => $schema['table'],
            'suffix'      => $suffix,
            'exists'      => $schema['exists'],
            'columns'     => array_keys($schema['columns']),
            'column_types'=> $schema['columns'],
            'primary_key' => $schema['primary_key'],
            'trip_column' => $schema['trip_column'],
            'total_rows'  => $schema['rows'],
            'rows'        => array(),
            'scoped'      => false,
        );

        if (!$schema['exists']) {
            return $out;
        }

        if ('' === $schema['trip_column']) {
            // No trip reference means this table is joined to trips through
            // something else. Saying so beats returning the whole table as if
            // it belonged to the requested trip.
            return $out;
        }

        $table  = $schema['table'];
        $column = $schema['trip_column'];

        $out['scoped'] = true;
        $out['rows']   = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `{$column}` = %d LIMIT %d",
                $trip_id,
                self::ROW_LIMIT
            ),
            ARRAY_A
        );

        $out['rows'] = is_array($out['rows']) ? $out['rows'] : array();

        return $out;
    }

    /**
     * Rows of a companion table that reference rows already fetched.
     *
     * The relation is discovered, not declared: the companion's own columns are
     * searched for one holding the parent's primary key values. When no such
     * column is found the companion is returned described but unfiltered-and-
     * empty, with 'scoped' false, so the caller knows the link was not made
     * rather than believing there is nothing to link.
     *
     * @param array<string, mixed> $parent Result of read_trip_rows().
     * @return array<string, mixed>
     */
    protected static function read_related_rows($suffix, $parent) {
        global $wpdb;

        $schema = self::describe_table($suffix);

        $out = array(
            'table'       => $schema['table'],
            'suffix'      => $suffix,
            'exists'      => $schema['exists'],
            'columns'     => array_keys($schema['columns']),
            'column_types'=> $schema['columns'],
            'primary_key' => $schema['primary_key'],
            'trip_column' => $schema['trip_column'],
            'total_rows'  => $schema['rows'],
            'rows'        => array(),
            'scoped'      => false,
            'link_column' => '',
        );

        if (!$schema['exists'] || empty($parent['rows']) || '' === $parent['primary_key']) {
            return $out;
        }

        $ids = array();

        foreach ($parent['rows'] as $row) {
            if (isset($row[$parent['primary_key']])) {
                $ids[] = (int) $row[$parent['primary_key']];
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return $out;
        }

        // Look for a column naming the parent — "pricing_id" or "pricings_id"
        // against wt_pricings, "date_id" against wt_dates — before falling back
        // to the parent's own key name and then to the generic candidates.
        // Anything not found leaves the relation unmade and says so.
        $stem = preg_replace('/^wt_/', '', isset($parent['suffix']) ? $parent['suffix'] : '');

        $candidates = array_merge(
            array(
                $stem . '_id',
                rtrim($stem, 's') . '_id',
                $parent['primary_key'],
            ),
            self::TRIP_COLUMN_CANDIDATES
        );

        $link = '';

        foreach ($candidates as $candidate) {
            if ('' !== $candidate && isset($schema['columns'][$candidate])) {
                $link = $candidate;
                break;
            }
        }

        if ('' === $link) {
            return $out;
        }

        $table        = $schema['table'];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $args         = $ids;
        $args[]       = self::ROW_LIMIT;

        $out['scoped']      = true;
        $out['link_column'] = $link;
        $out['rows']        = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `{$link}` IN ({$placeholders}) LIMIT %d",
                $args
            ),
            ARRAY_A
        );

        $out['rows'] = is_array($out['rows']) ? $out['rows'] : array();

        return $out;
    }

    /**
     * Insert, update or delete one row of a trip-scoped WP Travel table.
     *
     * Shared by set-trip-pricing and set-trip-dates because the safety rules
     * are identical and duplicating them is how one of the two ends up missing
     * the scoping check.
     *
     * @return array<string, mixed>|WP_Error
     */
    protected static function write_trip_row($suffix, $input) {
        global $wpdb;

        $trip_id = isset($input['trip_id']) ? absint($input['trip_id']) : 0;
        $check   = self::require_trip($trip_id);

        if (is_wp_error($check)) {
            return $check;
        }

        $schema = self::describe_table($suffix);

        if (!$schema['exists']) {
            return new WP_Error(
                'wta_table_missing',
                sprintf('The table %s does not exist on this site, so there is nothing to write. Call wp-travel-status to see which tables are present.', $schema['table']),
                array('status' => 409, 'table' => $schema['table'])
            );
        }

        if ('' === $schema['trip_column']) {
            return new WP_Error(
                'wta_table_unscoped',
                sprintf(
                    'The table %s has no column that identifies a trip (looked for: %s), so this ability cannot confine a write to trip %d and refuses to write at all. Its real columns are: %s.',
                    $schema['table'],
                    implode(', ', self::TRIP_COLUMN_CANDIDATES),
                    $trip_id,
                    implode(', ', array_keys($schema['columns']))
                ),
                array('status' => 409, 'columns' => array_keys($schema['columns']))
            );
        }

        if ('' === $schema['primary_key']) {
            return new WP_Error(
                'wta_table_no_key',
                sprintf('The table %s has no primary key, so a single row cannot be addressed safely.', $schema['table']),
                array('status' => 409)
            );
        }

        $dry     = !isset($input['dry_run']) || (bool) $input['dry_run'];
        $row_id  = isset($input['row_id']) ? absint($input['row_id']) : 0;
        $delete  = !empty($input['delete']);
        $table   = $schema['table'];
        $pk      = $schema['primary_key'];
        $trip_col = $schema['trip_column'];

        // Any existing row named must already belong to this trip. Checking the
        // ownership rather than trusting the pair is what stops a wrong id from
        // repricing another trip.
        $existing = null;

        if ($row_id) {
            $existing = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM `{$table}` WHERE `{$pk}` = %d", $row_id),
                ARRAY_A
            );

            if (!$existing) {
                return new WP_Error(
                    'wta_row_not_found',
                    sprintf('No row with %s = %d exists in %s.', $pk, $row_id, $table),
                    array('status' => 404)
                );
            }

            if ((int) $existing[$trip_col] !== $trip_id) {
                return new WP_Error(
                    'wta_row_not_this_trip',
                    sprintf('Row %d in %s belongs to trip %d, not trip %d.', $row_id, $table, (int) $existing[$trip_col], $trip_id),
                    array('status' => 409)
                );
            }
        }

        if ($delete) {
            if (!$row_id) {
                return new WP_Error('wta_no_row_id', 'row_id is required to delete a row.', array('status' => 400));
            }

            if ($dry) {
                return array(
                    'dry_run'  => true,
                    'trip_id'  => $trip_id,
                    'changed'  => array($pk . '=' . $row_id),
                    'rejected' => array(),
                    'result'   => array('action' => 'delete', 'table' => $table, 'row' => $existing),
                    'note'     => 'Nothing was deleted. Call again with dry_run false to remove this row.',
                );
            }

            $wpdb->delete($table, array($pk => $row_id), array('%d'));

            return array(
                'dry_run'  => false,
                'trip_id'  => $trip_id,
                'changed'  => array($pk . '=' . $row_id),
                'rejected' => array(),
                'result'   => array('action' => 'delete', 'table' => $table, 'row' => $existing),
                'note'     => '',
            );
        }

        $values   = isset($input['values']) && is_array($input['values']) ? $input['values'] : array();
        $clean    = array();
        $formats  = array();
        $rejected = array();

        foreach ($values as $column => $value) {
            $column = (string) $column;

            if (!isset($schema['columns'][$column])) {
                $rejected[] = sprintf('%s: not a column of %s. Real columns: %s.', $column, $table, implode(', ', array_keys($schema['columns'])));
                continue;
            }

            if ($column === $pk) {
                $rejected[] = sprintf('%s: the primary key is set by the database, not by a caller. Use row_id to address an existing row.', $column);
                continue;
            }

            if ($column === $trip_col) {
                $rejected[] = sprintf('%s: the trip reference is taken from trip_id, so a row cannot be moved between trips by this ability.', $column);
                continue;
            }

            list($clean[$column], $formats[]) = self::cast_for_column($schema['columns'][$column]['type'], $value);
        }

        if (empty($clean)) {
            return new WP_Error(
                'wta_no_values',
                sprintf(
                    'No writable values were supplied. The columns of %s are: %s. The primary key (%s) and the trip reference (%s) are not writable here.',
                    $table,
                    implode(', ', array_keys($schema['columns'])),
                    $pk,
                    $trip_col
                ),
                array('status' => 400, 'columns' => array_keys($schema['columns']), 'rejected' => $rejected)
            );
        }

        $action = $row_id ? 'update' : 'insert';

        if ($dry) {
            return array(
                'dry_run'  => true,
                'trip_id'  => $trip_id,
                'changed'  => array_keys($clean),
                'rejected' => $rejected,
                'result'   => array(
                    'action'  => $action,
                    'table'   => $table,
                    'columns' => $schema['columns'],
                    'before'  => $existing,
                    'after'   => array_merge((array) $existing, $clean, array($trip_col => $trip_id)),
                ),
                'note'     => sprintf('Nothing was written. Call again with dry_run false to %s this row.', $action),
            );
        }

        if ($row_id) {
            $wpdb->update($table, $clean, array($pk => $row_id), $formats, array('%d'));
            $written = $row_id;
        } else {
            $clean[$trip_col] = $trip_id;
            $formats[]        = '%d';

            $wpdb->insert($table, $clean, $formats);
            $written = (int) $wpdb->insert_id;
        }

        return array(
            'dry_run'  => false,
            'trip_id'  => $trip_id,
            'changed'  => array_keys($clean),
            'rejected' => $rejected,
            'result'   => array(
                'action' => $action,
                'table'  => $table,
                'row_id' => $written,
                'before' => $existing,
                'after'  => $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `{$pk}` = %d", $written), ARRAY_A),
            ),
            'note'     => 'Written directly to the table. WP Travel\'s own save hooks did not run, so any cache it keeps of this data may be stale until it is re-saved from the admin.',
        );
    }

    /**
     * Coerce a supplied value to the column's declared type.
     *
     * The type string comes from SHOW COLUMNS, so this adapts to whatever the
     * table really is rather than to a schema written from memory.
     *
     * @return array{0: mixed, 1: string} Value and its $wpdb format specifier.
     */
    protected static function cast_for_column($type, $value) {
        $type = strtolower((string) $type);

        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|year)/', $type)) {
            return array((int) $value, '%d');
        }

        if (preg_match('/^(decimal|numeric|float|double)/', $type)) {
            return array((float) $value, '%f');
        }

        if (preg_match('/^(text|mediumtext|longtext)/', $type)) {
            return array(sanitize_textarea_field((string) $value), '%s');
        }

        return array(sanitize_text_field((string) $value), '%s');
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * Split a supplied meta object into what may be written and what may not.
     *
     * Only the keys WTA_Trip already declares are accepted, and each is passed
     * through the sanitiser its group implies. A caller that wants a key this
     * plugin has never heard of gets it listed as rejected rather than written
     * unvalidated — an ability that writes arbitrary post meta is a different,
     * much larger, permission decision.
     *
     * @return array{accepted: array<string, string>, rejected: string[]}
     */
    protected static function partition_meta($meta) {
        $accepted = array();
        $rejected = array();

        if (!is_array($meta)) {
            return array('accepted' => $accepted, 'rejected' => array('meta: expected an object of meta key => value.'));
        }

        $fields = WTA_Trip::meta_fields();
        $text   = isset($fields['text']) ? (array) $fields['text'] : array();
        $html   = isset($fields['html']) ? (array) $fields['html'] : array();
        $prot   = isset($fields['protected']) ? (array) $fields['protected'] : array();

        foreach ($meta as $key => $value) {
            $key = (string) $key;

            if (!is_scalar($value) && null !== $value) {
                $rejected[] = sprintf('%s: only scalar values are accepted here.', $key);
                continue;
            }

            if (in_array($key, $text, true) || in_array($key, $prot, true)) {
                $accepted[$key] = sanitize_text_field((string) $value);
                continue;
            }

            if (in_array($key, $html, true)) {
                $accepted[$key] = wp_kses_post((string) $value);
                continue;
            }

            $rejected[] = sprintf(
                '%s: not a WP Travel meta key this plugin knows how to sanitise. Known keys: %s.',
                $key,
                implode(', ', array_merge($text, $html, $prot))
            );
        }

        return array('accepted' => $accepted, 'rejected' => $rejected);
    }

    /**
     * Turn a taxonomy => [ids or slugs] object into taxonomy => [term ids].
     *
     * Unknown taxonomies and missing terms are collected rather than created:
     * silently creating a term from a typo is how a destination tree fills up
     * with near-duplicates that audit-classification then has to report.
     *
     * @return array{resolved: array<string, int[]>, rejected: string[]}
     */
    protected static function resolve_terms($terms) {
        $resolved = array();
        $rejected = array();

        if (!is_array($terms)) {
            return array('resolved' => $resolved, 'rejected' => array('terms: expected an object of taxonomy => term list.'));
        }

        $allowed = WTA_Trip::default_taxonomies();

        foreach ($terms as $taxonomy => $list) {
            $taxonomy = sanitize_key($taxonomy);

            if (!array_key_exists($taxonomy, $allowed)) {
                $rejected[] = sprintf('%s: not a trip taxonomy. Allowed: %s.', $taxonomy, implode(', ', array_keys($allowed)));
                continue;
            }

            if (!taxonomy_exists($taxonomy)) {
                $rejected[] = sprintf('%s: configured but not registered on this site. Is WP Travel active?', $taxonomy);
                continue;
            }

            $ids = array();

            foreach ((array) $list as $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $value = (string) $value;
                $term  = ctype_digit($value)
                    ? get_term((int) $value, $taxonomy)
                    : get_term_by('slug', sanitize_title($value), $taxonomy);

                if (!$term instanceof WP_Term) {
                    $rejected[] = sprintf('%s: no term "%s" exists in that taxonomy. Use create-term first.', $taxonomy, $value);
                    continue;
                }

                $ids[] = (int) $term->term_id;
            }

            $resolved[$taxonomy] = array_values(array_unique($ids));
        }

        return array('resolved' => $resolved, 'rejected' => $rejected);
    }

    /**
     * Every taxonomy term on a trip, grouped by taxonomy.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected static function trip_terms($trip_id) {
        $out = array();

        foreach (array_keys(WTA_Trip::default_taxonomies()) as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($trip_id, $taxonomy);
            $rows  = array();

            if (is_array($terms)) {
                foreach ($terms as $term) {
                    $rows[] = self::prepare_term($term);
                }
            }

            $out[$taxonomy] = $rows;
        }

        return $out;
    }

    /**
     * Term representation, matching the one WTA_Abilities returns so a client
     * only has to learn one shape across both files.
     *
     * @return array<string, mixed>
     */
    protected static function prepare_term($term) {
        if (!$term instanceof WP_Term) {
            return array();
        }

        return array(
            'term_id'  => (int) $term->term_id,
            'taxonomy' => $term->taxonomy,
            'name'     => $term->name,
            'slug'     => $term->slug,
            'parent'   => (int) $term->parent,
            'count'    => (int) $term->count,
            'status'   => class_exists('WTA_Term_Status') ? WTA_Term_Status::get_status($term->term_id) : '',
        );
    }

    /**
     * Enough of a trip to decide whether to fetch the rest of it.
     *
     * @return array<string, mixed>
     */
    protected static function prepare_trip_summary($post) {
        if (!$post instanceof WP_Post) {
            return array();
        }

        $terms   = array();
        $grouped = self::trip_terms($post->ID);

        foreach ($grouped as $taxonomy => $rows) {
            $terms[$taxonomy] = wp_list_pluck($rows, 'name');
        }

        return array(
            'id'         => (int) $post->ID,
            'title'      => get_the_title($post),
            'slug'       => $post->post_name,
            'status'     => $post->post_status,
            'permalink'  => get_permalink($post),
            'modified'   => $post->post_modified_gmt,
            'days'       => (string) get_post_meta($post->ID, 'wp_travel_trip_duration', true),
            'nights'     => (string) get_post_meta($post->ID, 'wp_travel_trip_duration_night', true),
            'meta_price' => (string) get_post_meta($post->ID, 'wp_travel_trip_price', true),
            'code'       => (string) get_post_meta($post->ID, 'wp_travel_trip_code', true),
            'terms'      => $terms,
        );
    }

    /**
     * Meta keys whose values are long HTML documents.
     *
     * Used only to honour include_content. Overview on this site holds pasted
     * editor markup that dwarfs the rest of the record, so being able to ask
     * for the trip without it is the difference between a usable response and
     * an unreadable one.
     */
    protected static function is_long_html_key($key) {
        $fields = WTA_Trip::meta_fields();
        $html   = isset($fields['html']) ? (array) $fields['html'] : array();

        return in_array($key, $html, true);
    }

    /* -------------------------------------------------------------- schemas */

    /**
     * Shape of a plugin as the diagnostics abilities report one.
     */
    protected function plugin_schema($description) {
        return array(
            'type'        => 'object',
            'description' => $description,
            'properties'  => array(
                'file'        => array('type' => 'string', 'description' => 'Plugin file as WordPress identifies it, e.g. wp-travel/wp-travel.php.'),
                'installed'   => array('type' => 'boolean', 'description' => 'Present on disk. An installed but inactive plugin still owns its data.'),
                'active'      => array('type' => 'boolean', 'description' => 'Switched on in WordPress. Necessary but not sufficient: a plugin that bailed early is still "active".'),
                'loaded'      => array('type' => array('boolean', 'null'), 'description' => 'Whether the plugin\'s own class is really in memory. Null when no class name is known for this plugin. Where it is present and disagrees with "active", trust this one.'),
                'version'     => array('type' => 'string', 'description' => 'Version from the plugin header. Empty when not installed.'),
                'name'        => array('type' => 'string', 'description' => 'Plugin name from its own header.'),
                'description' => array('type' => 'string', 'description' => 'Plugin description from its own header, tags stripped.'),
            ),
            'required'    => array('file', 'installed', 'active', 'version'),
        );
    }

    /**
     * Shape of a raw-table read.
     *
     * Column names are data here rather than schema, because they are
     * discovered at call time; the schema can only promise that they will be
     * reported, not what they will be.
     */
    protected function table_read_schema($description) {
        return array(
            'type'        => 'object',
            'description' => $description,
            'properties'  => array(
                'trip_id'    => array('type' => 'integer', 'description' => 'Trip the rows were read for.'),
                'tables'     => array(
                    'type'        => 'object',
                    'description' => 'One entry per table read. Each reports the table name, whether it exists, its real column names and types, its primary key, which column was matched as the trip reference, the total row count in the whole table, and the rows for this trip. "scoped" false means no trip reference column was found, so the empty row list means "could not ask", not "nothing there".',
                ),
                'meta_price' => array('type' => 'string', 'description' => 'The wp_travel_trip_price post meta value, for comparison. Empty on the dates ability.'),
                'notes'      => array(
                    'type'        => 'array',
                    'description' => 'Plain-language reading of the result, including the difference between "no rows" and "no such table".',
                    'items'       => array('type' => 'string'),
                ),
            ),
            'required'    => array('trip_id', 'tables', 'notes'),
        );
    }

    /**
     * Input shape shared by the two raw-table writers.
     *
     * @param string $noun What a row of this table is, for the descriptions.
     */
    protected function table_write_input_schema($noun) {
        return array(
            'type'       => 'object',
            'properties' => array(
                'trip_id' => array(
                    'type'        => 'integer',
                    'description' => 'Post ID of the trip the row belongs to. Every write is confined to this trip.',
                    'minimum'     => 1,
                ),
                'row_id'  => array(
                    'type'        => 'integer',
                    'description' => sprintf('Primary key of an existing %s to update or delete. Omit or pass 0 to insert a new one.', $noun),
                    'minimum'     => 0,
                    'default'     => 0,
                ),
                'values'  => array(
                    'type'        => 'object',
                    'description' => sprintf('Column name => value for the %s. Use the exact column names the matching read ability reported; this plugin does not hard-code them and will reject anything the table does not have. The primary key and the trip reference column are not writable.', $noun),
                ),
                'delete'  => array(
                    'type'        => 'boolean',
                    'description' => 'Delete the row named by row_id instead of writing to it.',
                    'default'     => false,
                ),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'description' => 'Defaults to true. Must be explicitly false to touch the database. A dry run returns the table\'s real columns and the row before and after, which is the intended way to learn the shape before writing.',
                ),
            ),
            'required'   => array('trip_id'),
        );
    }

    /**
     * Shape every write ability returns.
     *
     * One shape for all of them so a caller can check dry_run and rejected the
     * same way regardless of what it just called.
     */
    protected function write_result_schema($description) {
        return array(
            'type'        => 'object',
            'description' => $description,
            'properties'  => array(
                'dry_run'  => array(
                    'type'        => 'boolean',
                    'description' => 'Whether this call only reported. True means nothing was written, whatever else the response says.',
                ),
                'trip_id'  => array('type' => 'integer', 'description' => 'Trip affected, or 0 for abilities that do not act on a trip.'),
                'changed'  => array(
                    'type'        => 'array',
                    'description' => 'Names of the things that changed, or would have. An empty list on a dry run means the input matched what is already stored.',
                    'items'       => array('type' => 'string'),
                ),
                'rejected' => array(
                    'type'        => 'array',
                    'description' => 'Inputs that were refused, each with the reason. Refusals do not abort the rest of the call, so always read this even on success.',
                    'items'       => array('type' => 'string'),
                ),
                'result'   => array(
                    'type'        => 'object',
                    'description' => 'The detail: before and after values, or the record as it would be created.',
                ),
                'note'     => array(
                    'type'        => 'string',
                    'description' => 'What to do next, or what this call did not do. Empty when there is nothing to add.',
                ),
            ),
            'required'    => array('dry_run', 'changed', 'rejected', 'result', 'note'),
        );
    }
}
