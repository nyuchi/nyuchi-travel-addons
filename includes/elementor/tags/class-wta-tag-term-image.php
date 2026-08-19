<?php
/**
 * Dynamic tag: the current term's image.
 *
 * Data_Tag rather than Tag because Elementor's image controls expect a value
 * of the shape array( 'id' => int, 'url' => string ), not a rendered string.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Tag_Term_Image extends \Elementor\Core\DynamicTags\Data_Tag {

    public function get_name() {
        return 'wta-term-image';
    }

    public function get_title() {
        return 'Destination image';
    }

    public function get_group() {
        return WTA_Elementor_Tags::GROUP;
    }

    public function get_categories() {
        return array(\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY);
    }

    protected function register_controls() {
        $this->add_control('fallback_source', array(
            'label'       => 'When the term has no image',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'trip',
            'options'     => array(
                'trip' => 'Use the newest trip in that term',
                'none' => 'Show nothing',
            ),
            'description' => 'Most terms have no image of their own yet, so the trip fallback keeps archives from rendering blank.',
        ));

        $this->add_control('fallback_image', array(
            'label'     => 'Or a fixed image',
            'type'      => \Elementor\Controls_Manager::MEDIA,
            'condition' => array('fallback_source' => 'none'),
        ));
    }

    /**
     * @return array{id:int,url:string}
     */
    public function get_value(array $options = array()) {
        $term = WTA_Elementor_Tags::current_term();

        $settings = $this->get_settings();
        $allow    = 'trip' === (isset($settings['fallback_source']) ? $settings['fallback_source'] : 'trip');

        $id = 0;

        if ($term) {
            if ($allow) {
                $id = WTA_Elementor_Tags::term_image_id($term->term_id);
            } else {
                $key = apply_filters('wta_term_image_meta_key', 'wp_travel_trip_type_image_id');
                $id  = (int) get_term_meta($term->term_id, $key, true);
            }
        }

        if ($id) {
            $url = wp_get_attachment_image_url($id, 'full');

            if ($url) {
                return array('id' => $id, 'url' => $url);
            }
        }

        // An explicitly chosen fallback beats an empty frame.
        if (!empty($settings['fallback_image']['url'])) {
            return array(
                'id'  => isset($settings['fallback_image']['id']) ? (int) $settings['fallback_image']['id'] : 0,
                'url' => $settings['fallback_image']['url'],
            );
        }

        return array('id' => 0, 'url' => '');
    }
}
