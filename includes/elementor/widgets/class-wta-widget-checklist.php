<?php
/**
 * Booking order checklist.
 *
 * The steps are numbered because sequence matters: permits and flights sell out
 * in a fixed order, and a traveller who books them out of order pays twice.
 *
 * The ticks are deliberately unpersisted. This is a reading aid for one sitting,
 * not an account feature, so nothing is stored and no request is made.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Checklist extends \Elementor\Widget_Base {

    public function get_name() {
        return 'wta-checklist';
    }

    public function get_title() {
        return 'Booking Order';
    }

    public function get_icon() {
        return 'eicon-checkbox';
    }

    public function get_categories() {
        return array(WTA_Elementor::CATEGORY);
    }

    public function get_style_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    /**
     * Presentation only. The steps come from the trip's `checklist` meta so the
     * same trip reads identically wherever it is placed.
     */
    protected function _register_controls() {
        $this->start_controls_section('wta_checklist_display', array(
            'label' => 'Display',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('heading', array(
            'label'   => 'Section heading',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Book it in this order',
        ));

        $this->add_control('standfirst', array(
            'label'   => 'Standfirst',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'rows'    => 3,
            'default' => 'Work down the list. Each step depends on the one above it.',
        ));

        $this->end_controls_section();
    }

    public function render() {
        $settings = $this->get_settings_for_display();
        $data     = WTA_Elementor::trip_data();
        $steps    = array();

        if (!empty($data['checklist']) && is_array($data['checklist'])) {
            foreach ($data['checklist'] as $step) {
                if (is_array($step)) {
                    $steps[] = array(
                        'heading' => isset($step['heading']) ? (string) $step['heading'] : '',
                        'body'    => isset($step['body']) ? (string) $step['body'] : '',
                    );
                }
            }
        }

        // data-trip is what the script keys its remembered ticks on, so a reader
        // who returns to this trip keeps their place and one who moves to
        // another trip starts clean.
        printf('<div class="wta-itin" data-trip="%d">', (int) get_the_ID());

        if (!$steps) {
            echo '<p class="wta-eyebrow">No booking steps have been added to this trip yet.</p>';
            echo '</div>';

            return;
        }

        echo '<section class="wta-section"><div class="wta-wrap">';

        echo '<div class="wta-sec-head">';

        if (!empty($settings['heading'])) {
            echo '<h2>' . esc_html($settings['heading']) . '</h2>';
        }

        if (!empty($settings['standfirst'])) {
            echo '<p>' . esc_html($settings['standfirst']) . '</p>';
        }

        echo '</div>';

        // Width is driven by the script; zero is the honest starting state.
        echo '<div class="wta-progressbar"><i style="width:0"></i></div>';

        echo '<div class="wta-checks">';

        foreach ($steps as $index => $step) {
            printf(
                '<button type="button" class="wta-check" aria-pressed="false" data-index="%d">',
                (int) $index
            );
            echo '<span class="wta-box" aria-hidden="true">&check;</span>';
            echo '<span class="wta-ctext">';
            printf(
                '<h4>%d. %s</h4>',
                (int) $index + 1,
                esc_html($step['heading'])
            );

            if ('' !== $step['body']) {
                // Author HTML, already run through wp_kses_post on save.
                echo '<p>' . wp_kses_post($step['body']) . '</p>';
            }

            echo '</span>';
            echo '</button>';
        }

        echo '</div>';

        echo '</div></section>';

        echo '</div>';
    }
}
