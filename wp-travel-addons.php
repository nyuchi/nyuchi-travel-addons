<?php
/**
 * Plugin Name: Nyuchi Travel Addons - Trip Tools for WP Travel
 * Plugin URI:  https://github.com/nyuchi/nyuchi-travel-addons
 * Description: Extends WP Travel with a REST-accessible trip schema, publication state for taxonomy terms, classification diagnostics, and compatibility guards. By Nyuchi Web Services.
 * Version:     1.6.0
 * Author:      Nyuchi Web Services
 * Author URI:  https://nyuchi.com
 * Developer:   Bryan Fawcett (@bryanfawcett)
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nyuchi-travel-addons
 * Requires at least: 5.9
 * Requires PHP: 7.4
 *
 * Built for WP Travel (wptravel.io) by WEN Solutions — NOT WP Travel Engine,
 * which is a different plugin with a different meta schema.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WTA_VERSION', '1.6.0');
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
require_once WTA_DIR . 'includes/class-wta-taxonomy-audit.php';
require_once WTA_DIR . 'includes/class-wta-compat.php';
require_once WTA_DIR . 'includes/class-wta-travel-abilities.php';
require_once WTA_DIR . 'includes/class-wta-rest.php';
require_once WTA_DIR . 'includes/class-wta-abilities.php';
require_once WTA_DIR . 'includes/class-wta-admin.php';
require_once WTA_DIR . 'includes/class-wta-updater.php';

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
                'detail' => 'Adds the trip data WP Travel does not store: legs, route stops, month-by-month suitability, traveller choices, cost tiers, booking order and field notes.',
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
                'label'  => 'Compatibility guards',
                'detail' => 'Works around known defects in WP Travel companion plugins.',
            ),
        );
    }

    public function boot() {
        foreach (self::module_map() as $key => $module) {
            if (!get_option('wta_module_' . $key, 1)) {
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

        // Update checking is admin-only work; no reason to carry it on every
        // front-end request.
        if (is_admin()) {
            $this->modules['updater'] = new WTA_Updater();
        }
        $this->modules['admin'] = new WTA_Admin($this);
    }

    /**
     * @return object|null
     */
    public function module($key) {
        return isset($this->modules[$key]) ? $this->modules[$key] : null;
    }

    public function activate() {
        foreach (array_keys(self::module_map()) as $key) {
            if (false === get_option('wta_module_' . $key, false)) {
                add_option('wta_module_' . $key, 1);
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
