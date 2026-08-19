<?php
/**
 * Dynamic tag: the current term's description, name or trip count.
 *
 * The description is the field most often left blank, and Elementor's own
 * archive description tag renders nothing when it is. This one can fall back to
 * the excerpt of a trip in that term, so a destination archive still opens with
 * a sentence rather than a gap.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Tag_Term_Text extends \Elementor\Core\DynamicTags\Tag {

    public function get_name() {
        return 'wta-term-text';
    }

    public function get_title() {
        return 'Destination text';
    }

    public function get_group() {
        return WTA_Elementor_Tags::GROUP;
    }

    public function get_categories() {
        return array(
            \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
            \Elementor\Modules\DynamicTags\Module::POST_META_CATEGORY,
        );
    }

    protected function register_controls() {
        $this->add_control('field', array(
            'label'   => 'Field',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'description',
            'options' => array(
                'description' => 'Description',
                'name'        => 'Name',
                'count'       => 'Number of trips',
                'parent'      => 'Parent destination',
            ),
        ));

        $this->add_control('words', array(
            'label'       => 'Trim to words',
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'default'     => 0,
            'min'         => 0,
            'max'         => 200,
            'description' => '0 keeps the full text.',
            'condition'   => array('field' => 'description'),
        ));

        $this->add_control('fallback_to_trip', array(
            'label'       => 'Fall back to a trip excerpt',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'Used when the term itself has no description.',
            'condition'   => array('field' => 'description'),
        ));
    }

    public function render() {
        $term = WTA_Elementor_Tags::current_term();

        if (!$term) {
            return;
        }

        $settings = $this->get_settings();
        $field    = isset($settings['field']) ? $settings['field'] : 'description';

        switch ($field) {
            case 'name':
                echo esc_html($term->name);
                return;

            case 'count':
                echo esc_html(number_format_i18n((int) $term->count));
                return;

            case 'parent':
                if ($term->parent) {
                    $parent = get_term($term->parent, $term->taxonomy);
                    if ($parent && !is_wp_error($parent)) {
                        echo esc_html($parent->name);
                    }
                }
                return;
        }

        echo wp_kses_post($this->description($term, $settings));
    }

    protected function description($term, $settings) {
        $text = trim((string) $term->description);

        if ('' === $text && 'yes' === (isset($settings['fallback_to_trip']) ? $settings['fallback_to_trip'] : 'yes')) {
            $text = $this->trip_excerpt($term);
        }

        if ('' === $text) {
            return '';
        }

        $words = isset($settings['words']) ? (int) $settings['words'] : 0;

        if ($words > 0) {
            // Trim on the plain text, so a cut never lands mid-tag.
            $text = wp_trim_words(wp_strip_all_tags($text), $words, '');
        }

        return $text;
    }

    protected function trip_excerpt($term) {
        if (!class_exists('WTA_Trip')) {
            return '';
        }

        $posts = get_posts(array(
            'post_type'      => WTA_Trip::post_type(),
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'no_found_rows'  => true,
            'tax_query'      => array(array(
                'taxonomy' => $term->taxonomy,
                'field'    => 'term_id',
                'terms'    => $term->term_id,
            )),
        ));

        if (!$posts) {
            return '';
        }

        return wp_strip_all_tags(get_the_excerpt($posts[0]));
    }
}
