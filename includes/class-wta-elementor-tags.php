<?php
/**
 * Elementor dynamic tags for taxonomy terms.
 *
 * Storing a term image is only half the job. Elementor's archive templates have
 * no native way to reach term meta, which is why a destination archive ends up
 * showing a static image or one borrowed from the first trip in the list —
 * the term's own image is never asked for.
 *
 * These tags close that gap: set an image widget, or a section background, to
 * "Destination image" and every term archive resolves its own picture, with a
 * sensible fallback so no archive renders blank.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Elementor_Tags {

    const GROUP = 'wta-travel';

    public function __construct() {
        add_action('elementor/dynamic_tags/register', array($this, 'register'));

        // Elementor renamed the hook; keep the old one for older installs.
        add_action('elementor/dynamic_tags/register_tags', array($this, 'register_legacy'));
    }

    public function register($manager) {
        if (!class_exists('\Elementor\Core\DynamicTags\Tag')) {
            return;
        }

        $this->register_group($manager);

        require_once __DIR__ . '/elementor/tags/class-wta-tag-term-image.php';
        require_once __DIR__ . '/elementor/tags/class-wta-tag-term-text.php';

        $manager->register(new WTA_Tag_Term_Image());
        $manager->register(new WTA_Tag_Term_Text());
    }

    /**
     * Elementor before 3.5 used register_tag() and a differently named hook.
     */
    public function register_legacy($manager) {
        if (!method_exists($manager, 'register_tag')) {
            return;
        }

        $this->register_group($manager);

        require_once __DIR__ . '/elementor/tags/class-wta-tag-term-image.php';
        require_once __DIR__ . '/elementor/tags/class-wta-tag-term-text.php';

        $manager->register_tag('WTA_Tag_Term_Image');
        $manager->register_tag('WTA_Tag_Term_Text');
    }

    protected function register_group($manager) {
        if (!method_exists($manager, 'register_group')) {
            return;
        }

        $manager->register_group(self::GROUP, array(
            'title' => 'Nyuchi Travel',
        ));
    }

    /**
     * The term a tag should describe.
     *
     * On a term archive that is the queried object. Inside a trip it is the
     * trip's primary destination, so the same tag can be reused on a single
     * template without rewiring anything.
     */
    public static function current_term() {
        if (is_tax() || is_category() || is_tag()) {
            $term = get_queried_object();

            if ($term instanceof WP_Term) {
                return $term;
            }
        }

        if (is_singular() && class_exists('WTA_Trip') && get_post_type() === WTA_Trip::post_type()) {
            $terms = get_the_terms(get_the_ID(), 'travel_locations');

            if ($terms && !is_wp_error($terms)) {
                // Deepest term first: "Serengeti National Park" is more useful
                // than "Tanzania" when a trip carries both.
                usort($terms, function ($a, $b) {
                    return $b->parent <=> $a->parent;
                });

                return $terms[0];
            }
        }

        return null;
    }

    /**
     * Attachment ID for a term.
     *
     * Prefers WTA_Term_Media when that module is active, since it adds caching
     * and a trip-derived fallback. Without it, read WP Travel's own key
     * directly so the tag still works with the module switched off.
     */
    public static function term_image_id($term_id) {
        if (class_exists('WTA_Term_Media') && method_exists('WTA_Term_Media', 'get_image_id')) {
            return (int) WTA_Term_Media::get_image_id($term_id);
        }

        $key = apply_filters('wta_term_image_meta_key', 'wp_travel_trip_type_image_id');
        $id  = (int) get_term_meta($term_id, $key, true);

        if ($id) {
            return $id;
        }

        // Last resort: the newest trip filed under this term.
        $term = get_term($term_id);

        if (!$term || is_wp_error($term) || !class_exists('WTA_Trip')) {
            return 0;
        }

        $posts = get_posts(array(
            'post_type'      => WTA_Trip::post_type(),
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => array(array(
                'taxonomy' => $term->taxonomy,
                'field'    => 'term_id',
                'terms'    => $term_id,
            )),
        ));

        return $posts ? (int) get_post_thumbnail_id($posts[0]) : 0;
    }
}
