<?php
/**
 * Elementor bridge.
 *
 * The itinerary design is a page of distinct sections. Shipping it as one
 * monolithic template would force an editor to fork PHP to reorder anything, so
 * each section is an Elementor widget instead and the page is composed visually.
 *
 * Widgets read trip meta directly rather than taking content controls: the
 * itinerary is authored once over REST (see WTA_Itinerary_Schema) and a widget
 * that also carried its own copy of the text would be a second source of truth.
 * Controls here are presentation only.
 *
 * Elementor is optional. Nothing in this file runs unless it is present, so the
 * plugin stays installable on a site that never loads a page builder.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Elementor {

    /** Category slug widgets declare, and the handle shared by all assets. */
    const CATEGORY = 'nyuchi-travel';
    const HANDLE   = 'wta-itinerary';

    public function __construct() {
        // did_action() covers the normal case (Elementor loads on plugins_loaded
        // priority 0, we boot at 20); the action covers a load order change or a
        // site that activates Elementor later in the request.
        if (did_action('elementor/loaded')) {
            $this->hooks();

            return;
        }

        add_action('elementor/loaded', array($this, 'hooks'));
    }

    public function hooks() {
        add_action('elementor/elements/categories_registered', array($this, 'register_category'));
        add_action('elementor/widgets/register', array($this, 'register_widgets'));
        add_action('elementor/frontend/after_enqueue_styles', array($this, 'enqueue_styles'));
        add_action('elementor/frontend/after_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * One category, so the itinerary widgets are not scattered through
     * Elementor's General panel.
     *
     * @param \Elementor\Elements_Manager $elements_manager
     */
    public function register_category($elements_manager) {
        $elements_manager->add_category(self::CATEGORY, array(
            'title' => esc_html__('Nyuchi Travel', 'wp-travel-addons'),
            'icon'  => 'eicon-globe',
        ));
    }

    /**
     * Discover widgets by scanning the widgets folder.
     *
     * Deliberately not a hard-coded list: widgets are added to this plugin one
     * file at a time, and an explicit registry means every new section is two
     * edits in two files, with the second one easy to forget.
     *
     * @param \Elementor\Widgets_Manager $widgets_manager
     */
    public function register_widgets($widgets_manager) {
        // Loaded before the widgets because they use it, and kept outside the
        // widgets directory because the glob below treats everything in there
        // as a widget class to instantiate.
        require_once WTA_DIR . 'includes/elementor/trait-wta-widget-styles.php';

        $files = glob(WTA_DIR . 'includes/elementor/widgets/*.php');

        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            require_once $file;
        }

        foreach (get_declared_classes() as $class) {
            if (0 !== strpos($class, 'WTA_Widget_')) {
                continue;
            }

            if (!is_subclass_of($class, '\Elementor\Widget_Base')) {
                continue;
            }

            // An abstract shared base would satisfy the name test but cannot be
            // instantiated.
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $widgets_manager->register(new $class());
        }
    }

    /**
     * Registered rather than enqueued inline so the handles exist for both the
     * style and the script pass, whichever fires first.
     */
    public function register_assets() {
        if (!wp_style_is(self::HANDLE, 'registered')) {
            // Fonts come through wp_enqueue_style because an @import inside the
            // stylesheet blocks rendering until the font CSS resolves.
            wp_register_style(
                self::HANDLE . '-fonts',
                'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,700;12..96,800&family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500;700&display=swap',
                array(),
                null
            );

            wp_register_style(
                self::HANDLE,
                WTA_URL . 'design/itinerary-reference.css',
                array(self::HANDLE . '-fonts'),
                WTA_VERSION
            );
        }

        if (!wp_script_is(self::HANDLE, 'registered')) {
            wp_register_script(
                self::HANDLE,
                WTA_URL . 'assets/js/itinerary.js',
                array(),
                WTA_VERSION,
                true
            );
        }
    }

    public function enqueue_styles() {
        $this->register_assets();

        wp_enqueue_style(self::HANDLE . '-fonts');
        wp_enqueue_style(self::HANDLE);
    }

    public function enqueue_scripts() {
        $this->register_assets();

        wp_enqueue_script(self::HANDLE);
    }

    /**
     * The trip currently being rendered, in the shape the widgets expect.
     *
     * Returns an empty array off a trip — in the Elementor editor a widget is
     * often previewed against a page or a bare template, and every widget is
     * expected to degrade to a placeholder rather than warn.
     *
     * @return array
     */
    public static function trip_data() {
        $post_id = get_the_ID();

        if (!$post_id || !class_exists('WTA_Itinerary_Schema')) {
            return array();
        }

        if (get_post_type($post_id) !== WTA_Trip::post_type()) {
            return array();
        }

        return WTA_Itinerary_Schema::for_trip($post_id);
    }
}
