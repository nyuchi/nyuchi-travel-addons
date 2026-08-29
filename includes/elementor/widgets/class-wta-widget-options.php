<?php
/**
 * Traveller choice picker.
 *
 * A trip often forks: which coast, which lodge style, which permit. The choice
 * is authored once in the `options` meta and rendered here as a segmented set
 * of cards with a single explanatory panel, so the page never grows a column
 * per variant.
 *
 * The first option is chosen server-side and every body is shipped alongside as
 * JSON, so the panel is correct before any script runs and swapping costs no
 * request.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Options extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    public function get_name() {
        return 'wta-options';
    }

    public function get_title() {
        return 'Traveller Choice';
    }

    public function get_icon() {
        return 'eicon-toggle';
    }

    public function get_categories() {
        return array(WTA_Elementor::CATEGORY);
    }

    public function get_style_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    /**
     * Presentation only. The options themselves belong to the trip, not to the
     * page: the same trip must read identically wherever it is placed.
     */
    protected function _register_controls() {
        $this->start_controls_section('wta_options_display', array(
            'label' => 'Display',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('title_fallback', array(
            'label'       => 'Heading fallback',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'Choose your route',
            'description' => 'Used only when the trip has no options title of its own.',
        ));

        $this->add_control('show_output', array(
            'label'        => 'Show detail panel',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_box_style_section('opts', 'Option', '.wta-option', array('grid' => '.wta-options'));

        $this->wta_text_style_section('opts', 'Option text', array(
            'option'  => array('label' => 'Option',  'selector' => '.wta-option'),
            'eyebrow' => array('label' => 'Eyebrow', 'selector' => '.wta-eyebrow'),
        ));

    }

    public function render() {
        $settings = $this->get_settings_for_display();
        $data     = WTA_Elementor::trip_data();
        $options  = isset($data['options']) && is_array($data['options']) ? $data['options'] : array();
        $items    = array();

        if (!empty($options['items']) && is_array($options['items'])) {
            foreach ($options['items'] as $item) {
                if (is_array($item)) {
                    $items[] = array(
                        'name'     => isset($item['name']) ? (string) $item['name'] : '',
                        'subtitle' => isset($item['subtitle']) ? (string) $item['subtitle'] : '',
                        'body'     => isset($item['body']) ? (string) $item['body'] : '',
                    );
                }
            }
        }

        echo '<div class="wta-itin">';

        if (!$items) {
            echo '<p class="wta-eyebrow">No traveller choice has been added to this trip yet.</p>';
            echo '</div>';

            return;
        }

        $title = !empty($options['title']) ? $options['title'] : $settings['title_fallback'];

        if ($title) {
            echo '<h4>' . esc_html($title) . '</h4>';
        }

        echo '<div class="wta-options">';

        foreach ($items as $index => $item) {
            printf(
                '<button type="button" class="wta-option" aria-pressed="%s" data-index="%d">',
                0 === $index ? 'true' : 'false',
                (int) $index
            );
            echo '<h4>' . esc_html($item['name']) . '</h4>';

            if ('' !== $item['subtitle']) {
                echo '<span>' . esc_html($item['subtitle']) . '</span>';
            }

            echo '</button>';
        }

        echo '</div>';

        if ('yes' === $settings['show_output']) {
            $first = $items[0];

            // Same shape the script rewrites the panel to, so the first paint
            // and every later one are identical.
            echo '<div class="wta-optionout">';
            echo '<b>' . esc_html($this->label($first)) . '</b>';
            // Author HTML, already run through wp_kses_post on save.
            echo wp_kses_post($first['body']);
            echo '</div>';
        }

        // A JSON island rather than an inline variable: the browser never
        // executes it, so no author string can escape into script context.
        $payload = array('items' => array());

        foreach ($items as $item) {
            $payload['items'][] = array(
                'name'     => $item['name'],
                'subtitle' => $item['subtitle'],
                'body'     => wp_kses_post($item['body']),
            );
        }

        echo '<script type="application/json" class="wta-options-data">'
            . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script>';

        echo '</div>';
    }

    /**
     * The eyebrow above the detail panel: name, then subtitle when there is one.
     *
     * @param array $item
     * @return string
     */
    protected function label($item) {
        if ('' === $item['subtitle']) {
            return $item['name'];
        }

        return $item['name'] . ' · ' . $item['subtitle'];
    }
}
