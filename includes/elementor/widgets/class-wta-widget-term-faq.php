<?php
/**
 * Term FAQ.
 *
 * Shows the frequently asked questions written on a destination term, so each
 * country has one FAQ source instead of a copy pasted into every page that
 * mentions it. Placed on a country page or archive it shows that country's
 * list; placed on a trip, or on a park beneath a country, it walks up to the
 * country and shows the same list.
 *
 * Nothing is authored in the widget. Its controls choose where the FAQs come
 * from and how they look; the questions and answers are the term's `faqs`
 * field, edited on the term screen, over REST or through the term import.
 *
 * All the logic is in WTA_Term_FAQ so it can run without Elementor.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Term_FAQ extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    public function get_name() {
        return 'wta-term-faq';
    }

    public function get_title() {
        return esc_html__('Term FAQ', 'wp-travel-addons');
    }

    public function get_icon() {
        return 'eicon-help-o';
    }

    public function get_categories() {
        return array(WTA_Elementor::CATEGORY);
    }

    public function get_keywords() {
        return array('faq', 'questions', 'accordion', 'country', 'destination', 'schema');
    }

    public function get_style_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    /**
     * What this prints depends on the page it is on, so it is never safe to
     * serve a stored copy of its output to another page.
     */
    protected function is_dynamic_content(): bool {
        return true;
    }

    protected function register_controls() {
        $this->start_controls_section('wta_faq_source', array(
            'label' => esc_html__('Source', 'wp-travel-addons'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('term_id', array(
            'label'       => esc_html__('Destination', 'wp-travel-addons'),
            'type'        => \Elementor\Controls_Manager::SELECT2,
            'default'     => '',
            'options'     => $this->term_options(),
            'label_block' => true,
            'description' => esc_html__('Leave on Automatic on a trip or a destination archive: the widget finds the country from the page. Choose a destination to show its FAQs on a page that has no destination of its own, such as a country page built from a regular page.', 'wp-travel-addons'),
        ));

        $this->add_control('scope', array(
            'label'       => esc_html__('When the destination has no FAQs', 'wp-travel-addons'),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'nearest',
            'options'     => array(
                'nearest' => esc_html__('Use the nearest parent that has some (park to country)', 'wp-travel-addons'),
                'own'     => esc_html__('Show nothing', 'wp-travel-addons'),
            ),
            'condition'   => array('term_id' => ''),
        ));

        $this->end_controls_section();

        $this->start_controls_section('wta_faq_display', array(
            'label' => esc_html__('Display', 'wp-travel-addons'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('heading', array(
            'label'   => esc_html__('Section heading', 'wp-travel-addons'),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__('Frequently asked questions', 'wp-travel-addons'),
        ));

        $this->add_control('heading_tag', array(
            'label'       => esc_html__('Heading level', 'wp-travel-addons'),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'h2',
            'options'     => array('h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4'),
            'description' => esc_html__('Pick the level that fits the page outline, not the size you want; size is in the Style tab.', 'wp-travel-addons'),
        ));

        $this->add_control('standfirst', array(
            'label'   => esc_html__('Standfirst', 'wp-travel-addons'),
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'rows'    => 3,
            'default' => '',
        ));

        $this->add_control('open_first', array(
            'label'   => esc_html__('Open the first question', 'wp-travel-addons'),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => '',
        ));

        $this->add_control('exclusive', array(
            'label'       => esc_html__('One open at a time', 'wp-travel-addons'),
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => '',
            'description' => esc_html__('Opening a question closes the others. Browsers that do not support this yet leave them independent.', 'wp-travel-addons'),
        ));

        $this->end_controls_section();

        $this->start_controls_section('wta_faq_seo', array(
            'label' => esc_html__('Search engines', 'wp-travel-addons'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('schema', array(
            'label'       => esc_html__('Publish FAQ schema', 'wp-travel-addons'),
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => esc_html__('Adds one FAQPage block describing these questions. It is left out when another plugin or widget on the page already publishes FAQ schema, so the page never has two.', 'wp-travel-addons'),
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_box_style_section('faq', esc_html__('Question box', 'wp-travel-addons'), '.wta-faq__item', array('grid' => '.wta-faq__list'));

        $this->wta_text_style_section('faq', esc_html__('Text', 'wp-travel-addons'), array(
            'heading' => array('label' => esc_html__('Heading', 'wp-travel-addons'), 'selector' => '.wta-faq__title', 'spacing' => false),
            'q'       => array('label' => esc_html__('Question', 'wp-travel-addons'), 'selector' => '.wta-faq__qtext'),
            'a'       => array('label' => esc_html__('Answer', 'wp-travel-addons'), 'selector' => '.wta-faq__a'),
        ));
    }

    /**
     * Destinations for the picker, indented by depth.
     *
     * Built only inside the editor: it is a query, and a visitor's page view
     * has no use for a list of choices.
     *
     * @return array<string, string>
     */
    protected function term_options() {
        $options = array('' => esc_html__('Automatic (from the page)', 'wp-travel-addons'));

        if (!class_exists('\\Elementor\\Plugin') || !\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            return $options;
        }

        $terms = get_terms(array(
            'taxonomy'   => 'travel_locations',
            'hide_empty' => false,
            'orderby'    => 'name',
            'number'     => 500,
        ));

        if (is_wp_error($terms)) {
            return $options;
        }

        foreach ($terms as $term) {
            $depth = count(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy'));

            $options[(string) $term->term_id] = str_repeat('— ', $depth) . $term->name;
        }

        return $options;
    }

    public function render() {
        if (!class_exists('WTA_Term_FAQ') || !WTA_Term_FAQ::enabled()) {
            return;
        }

        $settings = $this->get_settings_for_display();
        $term_id  = isset($settings['term_id']) ? absint($settings['term_id']) : 0;
        $scope    = isset($settings['scope']) && 'own' === $settings['scope'] ? 'own' : 'nearest';
        $found    = WTA_Term_FAQ::find($term_id, $scope);

        // Visitors see nothing when there are no FAQs. The prompt is for
        // whoever is building the page, so it only appears in the editor.
        if (!$found) {
            WTA_Elementor::empty_notice(
                '<div class="wta-itin"><p class="wta-eyebrow">'
                . esc_html__('No FAQs found. Add them on the destination, in its FAQs field, or choose a destination in this widget.', 'wp-travel-addons')
                . '</p></div>'
            );

            return;
        }

        $tag     = isset($settings['heading_tag']) && in_array($settings['heading_tag'], array('h2', 'h3', 'h4'), true) ? $settings['heading_tag'] : 'h2';
        $head_id = 'wta-faq-' . $this->get_id();
        $heading = isset($settings['heading']) ? (string) $settings['heading'] : '';

        echo '<div class="wta-itin">';
        printf(
            '<section class="wta-section wta-faq"%s><div class="wta-wrap">',
            '' !== $heading ? ' aria-labelledby="' . esc_attr($head_id) . '"' : ''
        );

        if ('' !== $heading || !empty($settings['standfirst'])) {
            echo '<div class="wta-sec-head">';

            if ('' !== $heading) {
                printf('<%1$s id="%2$s" class="wta-faq__title">%3$s</%1$s>', tag_escape($tag), esc_attr($head_id), esc_html($heading));
            }

            if (!empty($settings['standfirst'])) {
                echo '<p>' . esc_html($settings['standfirst']) . '</p>';
            }

            echo '</div>';
        }

        echo WTA_Term_FAQ::render_list($found['faqs'], array( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside render_list().
            'open_first' => 'yes' === $settings['open_first'],
            'group'      => 'yes' === $settings['exclusive'] ? 'wta-faq-group-' . $this->get_id() : '',
        ));

        echo '</div></section></div>';

        if ('yes' === $settings['schema']) {
            echo WTA_Term_FAQ::queue($found['faqs'], $found['term']); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON encoded with HEX flags in WTA_Term_FAQ::script().
        }
    }
}
