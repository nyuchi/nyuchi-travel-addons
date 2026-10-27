<?php
/**
 * Plugin Name: Nyuchi Travel Addons - Trip Tools for WP Travel
 * Plugin URI:  https://github.com/nyuchi/nyuchi-travel-addons
 * Description: Extends WP Travel with a REST-accessible trip schema, publication state for taxonomy terms, classification diagnostics, and compatibility helpers. By Nyuchi Web Services.
 * Version:     1.12.0
 * Author:      Nyuchi Web Services
 * Author URI:  https://nyuchi.com
 * Developer:   Bryan Fawcett (@bryanfawcett)
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nyuchi-travel-addons
 * Requires at least: 5.9
 * Requires PHP: 8.2
 *
 * Built for WP Travel (wptravel.io) by WEN Solutions — NOT WP Travel Engine,
 * which is a different plugin with a different meta schema.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WTA_VERSION', '1.12.0');
define('WTA_FILE', __FILE__);
define('WTA_DIR', plugin_dir_path(__FILE__));
define('WTA_URL', plugin_dir_url(__FILE__));

require_once WTA_DIR . 'includes/class-wta-trip.php';
require_once WTA_DIR . 'includes/class-wta-trip-meta.php';
require_once WTA_DIR . 'includes/class-wta-itinerary-schema.php';
require_once WTA_DIR . 'includes/class-wta-trip-editor.php';
require_once WTA_DIR . 'includes/class-wta-elementor.php';
require_once WTA_DIR . 'includes/class-wta-term-status.php';
require_once WTA_DIR . 'includes/class-wta-elementor-tags.php';
require_once WTA_DIR . 'includes/class-wta-term-media.php';
require_once WTA_DIR . 'includes/class-wta-term-fields.php';
require_once WTA_DIR . 'includes/class-wta-term-faq.php';
require_once WTA_DIR . 'includes/class-wta-taxonomy-audit.php';
require_once WTA_DIR . 'includes/class-wta-compat.php';
require_once WTA_DIR . 'includes/class-wta-assets.php';
require_once WTA_DIR . 'includes/class-wta-pwa.php';
require_once WTA_DIR . 'includes/class-wta-pwa-legacy.php';
require_once WTA_DIR . 'includes/class-wta-consent.php';
require_once WTA_DIR . 'includes/class-wta-consent-log.php';
require_once WTA_DIR . 'includes/class-wta-ai-crawlers.php';
require_once WTA_DIR . 'includes/class-wta-llms.php';
require_once WTA_DIR . 'includes/class-wta-hardening.php';
require_once WTA_DIR . 'includes/class-wta-travel-abilities.php';
require_once WTA_DIR . 'includes/class-wta-trip-transfer.php';
require_once WTA_DIR . 'includes/class-wta-trip-csv.php';
require_once WTA_DIR . 'includes/class-wta-term-transfer.php';
require_once WTA_DIR . 'includes/class-wta-rest.php';
require_once WTA_DIR . 'includes/class-wta-abilities.php';
require_once WTA_DIR . 'includes/class-wta-admin.php';
require_once WTA_DIR . 'includes/class-wta-updater.php';
require_once WTA_DIR . 'includes/class-wta-github-stars.php';

/**
 * Bootstrap.
 *
 * Every module is individually switchable, because the whole point of this
 * plugin is to sit alongside someone else's product without becoming
 * load-bearing in ways that are hard to back out of.
 */
final class WP_Travel_Addons {

    /** @var WP_Travel_Addons */
    private static $instance;

    /** @var array<string, object> */
    private $modules = array();

    public static function instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'boot'), 20);

        register_activation_hook(WTA_FILE, array($this, 'activate'));
    }

    /**
     * Modules and the option that gates each one.
     */
    public static function module_map() {
        return array(
            'trip_meta'  => array(
                'class'  => 'WTA_Trip_Meta',
                'label'  => 'Trip REST schema',
                'detail' => 'Exposes WP Travel trip fields and the day-by-day itinerary through the REST API.',
            ),
            'itinerary' => array(
                'class'  => 'WTA_Itinerary_Schema',
                'label'  => 'Itinerary schema',
                'detail' => 'Adds the trip data WP Travel does not store: legs, route stops, month-by-month suitability, traveller choices, booking order and field notes.',
            ),
            'trip_editor' => array(
                'class'  => 'WTA_Trip_Editor',
                'label'  => 'Itinerary editor',
                'detail' => 'Puts the itinerary schema on the trip edit screen, so a whole itinerary can be authored without a REST client.',
            ),
            'elementor' => array(
                'class'  => 'WTA_Elementor',
                'label'  => 'Elementor widgets',
                'detail' => 'Registers a widget per itinerary section so a single trip template can be composed and edited in Elementor.',
            ),
            'term_media' => array(
                'class'  => 'WTA_Term_Media',
                'label'  => 'Term images and descriptions',
                'detail' => 'Gives destination, activity and keyword terms a featured image and a dependable description, falling back to a trip in that term so no archive renders blank.',
            ),
            'term_fields' => array(
                'class'  => 'WTA_Term_Fields',
                'label'  => 'Destination and activity detail',
                'detail' => 'Structured facts for destinations (country, gateway airport, currency, best months) and activities (duration, difficulty, minimum age, best months).',
            ),
            'term_faq' => array(
                'class'  => 'WTA_Term_FAQ',
                'label'  => 'Country FAQs',
                'detail' => 'The Term FAQ widget: shows the FAQs written on a country (or on a destination) as an accessible accordion, and publishes them as one FAQPage schema graph that steps aside for FAQ schema from another source.',
            ),
            'term_status' => array(
                'class'  => 'WTA_Term_Status',
                'label'  => 'Term publication state',
                'detail' => 'Adds live/draft status to taxonomy terms so a term can exist before it is public.',
            ),
            'audit' => array(
                'class'  => 'WTA_Taxonomy_Audit',
                'label'  => 'Classification diagnostics',
                'detail' => 'Reports flat hierarchies, empty terms, non-segmenting terms and cross-taxonomy duplicates.',
            ),
            'travel_abilities' => array(
                'class'  => 'WTA_Travel_Abilities',
                'label'  => 'WP Travel abilities',
                'detail' => 'Exposes WP Travel and WP Travel Pro over the Abilities API: trips, pricing, dates, taxonomies and diagnostics. Reads WP Travel\'s own storage rather than mirroring it, and reports rather than fails when WP Travel is absent.',
            ),
            'compat' => array(
                'class'  => 'WTA_Compat',
                'label'  => 'Compatibility helpers',
                'detail' => 'Trip timeline styling, and a scan for itinerary text that breaks WT Widgets for Elementor older than 1.4.8.',
            ),
            'assets' => array(
                'class'   => 'WTA_Assets',
                'label'   => 'Conditional asset loading',
                'detail'  => 'Keeps the WP Travel booking widget, Google Maps and WP Travel Pro bundles off pages that cannot use them. Off until switched on, because it changes what the front end loads.',
                'default' => 0,
            ),
            'pwa' => array(
                'class'   => 'WTA_PWA',
                'label'   => 'Progressive web app',
                'detail'  => 'An installable, offline-capable site: a current web app manifest, a service worker that never serves a stale page, and an offline page. Replaces the WP Travel PWA. Off until switched on, because it changes what the front end prints and registers. Settings are on the PWA tab.',
                'default' => 0,
            ),
            'consent' => array(
                'class'   => 'WTA_Consent',
                'label'   => 'Cookie consent',
                'detail'  => 'A cookie consent banner (accept, decline, manage categories) with Google Consent Mode v2 signalling, held-back scripts, an optional Meta Pixel loader and an optional consent log. Off until switched on, because it changes what the front end prints. Settings are on the Consent tab.',
                'default' => 0,
            ),
            'ai_crawlers' => array(
                'class'   => 'WTA_AI_Crawlers',
                'label'   => 'AI crawler policy',
                'detail'  => 'Adds a policy for AI crawlers to robots.txt: every AI crawler is welcome (search, answer and training bots), plus a Content-Signal line and header and the sitemap. Blocking a bot is opt-in. Off until switched on, because it changes your robots.txt.',
                'default' => 0,
            ),
            'llms' => array(
                'class'   => 'WTA_LLMS',
                'label'   => 'llms.txt for AI assistants',
                'detail'  => 'Serves /llms.txt (every trip with its length and from-price, destinations, key pages, park guides and recent articles) and /llms-full.txt (each trip with overview, itinerary and inclusions), built from the site and cached. Off until switched on. Yoast SEO writes its own llms.txt as a physical file; turn that feature off in Yoast so this one is reached.',
                'default' => 0,
            ),
            'hardening' => array(
                'class'   => 'WTA_Hardening',
                'label'   => 'Login hardening',
                'detail'  => 'Hides the public list of usernames in the REST API (/wp-json/wp/v2/users) and closes XML-RPC, the two doors password-guessing bots use first. Logged-in editors and admins still see the user list. Off until switched on, because a site that uses XML-RPC (Jetpack, old mobile apps) would lose it.',
                'default' => 0,
            ),
        );
    }

    /**
     * Whether a module is switched on. Modules default to on unless the map
     * says otherwise, so an update never silently enables a new one that
     * changes front-end behaviour.
     */
    public static function module_enabled($key) {
        $map     = self::module_map();
        $default = isset($map[$key]['default']) ? (int) $map[$key]['default'] : 1;

        return (bool) get_option('wta_module_' . $key, $default);
    }

    public function boot() {
        foreach (self::module_map() as $key => $module) {
            if (!self::module_enabled($key)) {
                continue;
            }

            if (class_exists($module['class'])) {
                $this->modules[$key] = new $module['class']();
            }
        }

        // Always loaded: these read module state rather than adding behaviour.
        $this->modules['rest']  = new WTA_REST($this);
        $this->modules['abilities'] = new WTA_Abilities();

        // Dynamic tags are what let an Elementor archive template reach term
        // meta at all, so they load regardless of the Elementor module switch.
        $this->modules['tags'] = new WTA_Elementor_Tags();

        // Always constructed. WordPress runs update checks outside wp-admin:
        // the wp-cron wp_update_plugins event, automatic background updates,
        // REST/MCP-triggered checks and WP-CLI. If the filters are only
        // registered for admin requests, those checks never see our release.
        // The constructor only adds hooks and the GitHub call is cached, so
        // this costs nothing on an ordinary front-end request.
        $this->modules['updater'] = new WTA_Updater();
        $this->modules['admin'] = new WTA_Admin($this);

        // Always constructed, whatever the pwa module switch says: it removes
        // WP Travel's old /sw.js, which keeps running in visitors' browsers
        // after WP Travel's PWA setting is turned off. It registers hooks
        // only and reads the file on admin_init when something has changed.
        $this->modules['pwa_legacy'] = new WTA_PWA_Legacy();

        // Always constructed, whatever the consent module switch says: the
        // privacy tools' exporter and eraser, the retention purge and the
        // logging endpoint must keep working for records already stored. The
        // endpoint answers 404 while the module or logging is off.
        $this->modules['consent_log'] = new WTA_Consent_Log();

        // Only adds the Plugins-row link; the count is fetched by the admin
        // screen itself when it renders.
        if (is_admin()) {
            $this->modules['stars'] = new WTA_GitHub_Stars();
        }
    }

    /**
     * @return object|null
     */
    public function module($key) {
        return isset($this->modules[$key]) ? $this->modules[$key] : null;
    }

    public function activate() {
        foreach (self::module_map() as $key => $module) {
            if (false === get_option('wta_module_' . $key, false)) {
                add_option('wta_module_' . $key, isset($module['default']) ? (int) $module['default'] : 1);
            }
        }

        add_option('wta_status_taxonomies', WTA_Trip::default_taxonomies());
        update_option('wta_version', WTA_VERSION);

        // Term status changes what is publicly queryable, so the rules cache
        // has to be rebuilt.
        flush_rewrite_rules();
    }
}

WP_Travel_Addons::instance();
