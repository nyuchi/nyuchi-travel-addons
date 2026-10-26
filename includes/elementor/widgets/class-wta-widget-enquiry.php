<?php
/**
 * Price, call to action and the trip enquiry form.
 *
 * The form is WP Travel's own, rendered by WP Travel's own function for the trip
 * being viewed, so an enquiry is saved, emailed and counted exactly as one sent
 * from the Enquiry popup. Nothing is copied. The labels, extra fields and
 * notification addresses a person sets with the update-inquiry-form ability are
 * applied by WTA_Inquiry_Form's filters, which WP Travel runs while it builds
 * the form, so this widget has no field controls of its own.
 *
 * Controls here are presentation and the two pieces of copy that belong to the
 * page rather than to the form: the heading and the button.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Enquiry extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    /** Anchor the call to action scrolls to. */
    const ANCHOR = 'wta-enquiry-form';

    public function get_name() {
        return 'wta-enquiry';
    }

    public function get_title() {
        return 'Trip Enquiry';
    }

    public function get_icon() {
        return 'eicon-form-horizontal';
    }

    public function get_categories() {
        return array(WTA_Elementor::CATEGORY);
    }

    public function get_style_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    protected function _register_controls() {
        $this->start_controls_section('wta_enquiry_display', array(
            'label' => 'Content',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('heading', array(
            'label'   => 'Heading',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Plan this trip',
        ));

        $this->add_control('show_price', array(
            'label'        => 'Show price',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
        ));

        $this->add_control('price_from_label', array(
            'label'     => 'Price prefix',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'From',
            'condition' => array('show_price' => 'yes'),
        ));

        $this->add_control('price_suffix', array(
            'label'       => 'Per person suffix',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'pp',
            'description' => 'Shown after a per-person price. A per-group price says "per group" instead.',
            'condition'   => array('show_price' => 'yes'),
        ));

        $this->add_control('price_request_label', array(
            'label'       => 'Label when there is no price',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'Price on request',
            'description' => 'Shown when a trip has no price, or a price of zero.',
            'condition'   => array('show_price' => 'yes'),
        ));

        $this->add_control('cta', array(
            'label'   => 'Button label',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Enquire about this trip',
        ));

        $this->add_control('cta_request', array(
            'label'       => 'Button label when there is no price',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'Request a price',
            'description' => 'A trip with no published price asks for the enquiry rather than repeating the generic button.',
        ));

        $this->add_control('show_form', array(
            'label'        => 'Show the form on the page',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
            'description'  => 'Off leaves the price and button only. The button then scrolls to an element with the id ' . self::ANCHOR . ' elsewhere on the page.',
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_box_style_section('enq', 'Panel', '.wta-enquiry');

        $this->wta_text_style_section('enq', 'Panel text', array(
            'heading' => array('label' => 'Heading', 'selector' => '.wta-enquiry-title', 'spacing' => true),
            'price'   => array('label' => 'Price',   'selector' => '.wta-enquiry-price'),
            'cta'     => array('label' => 'Button',  'selector' => '.wta-enquiry-cta'),
        ));
    }

    public function render() {
        $settings = $this->get_settings_for_display();
        $post_id  = get_the_ID();
        $is_trip  = $post_id && class_exists('WTA_Trip') && get_post_type($post_id) === WTA_Trip::post_type();

        echo '<div class="wta-itin wta-enquiry">';

        if (!$is_trip) {
            echo '<p class="wta-eyebrow">The enquiry form appears on a trip. Preview this template with a trip to see it.</p>';
            echo '</div>';

            return;
        }

        $price     = WTA_Inquiry_Form::trip_price($post_id);
        $has_price = $price['amount'] > 0;
        $cta       = (!$has_price && !empty($settings['cta_request'])) ? $settings['cta_request'] : $settings['cta'];

        if (!empty($settings['heading'])) {
            echo '<h3 class="wta-enquiry-title">' . esc_html($settings['heading']) . '</h3>';
        }

        if ('yes' === $settings['show_price']) {
            if ($has_price) {
                printf(
                    '<p class="wta-enquiry-price">%s<span class="wta-enquiry-amount">%s</span> %s</p>',
                    '' !== (string) $settings['price_from_label'] ? '<small>' . esc_html($settings['price_from_label']) . '</small> ' : '',
                    wp_kses_post($this->format_price($price['amount'])),
                    esc_html('group' === $price['per'] ? 'per group' : (string) $settings['price_suffix'])
                );
            } elseif ('' !== (string) $settings['price_request_label']) {
                echo '<p class="wta-enquiry-price is-request">' . esc_html($settings['price_request_label']) . '</p>';
            }
        }

        if ($cta) {
            printf(
                '<a class="wta-enquiry-cta%s" href="#%s">%s</a>',
                $has_price ? '' : ' is-request',
                esc_attr(self::ANCHOR),
                esc_html($cta)
            );
        }

        if ('yes' === $settings['show_form']) {
            // WP Travel's own markup, built from its own escaped field list; it
            // cannot be run through wp_kses without stripping the form.
            $form = WTA_Inquiry_Form::render_for_trip($post_id);

            echo '<div class="wta-enquiry-form" id="' . esc_attr(self::ANCHOR) . '">';

            if ('' !== $form) {
                echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo '<p class="wta-eyebrow">The enquiry form is not available. Is WP Travel active?</p>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * WP Travel's own currency formatting when it is there, so the symbol and
     * separators match every other price on the site.
     */
    protected function format_price($amount) {
        if (function_exists('wp_travel_get_formated_price_currency')) {
            return wp_travel_get_formated_price_currency($amount);
        }

        return number_format_i18n((float) $amount);
    }
}
