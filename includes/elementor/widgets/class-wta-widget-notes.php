<?php
/**
 * Field notes.
 *
 * Visas, vaccinations, luggage limits: short practical warnings that are read
 * by scanning, never in sequence. A flat grid of equal tiles suits that better
 * than an accordion, so nothing here is interactive.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Notes extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    public function get_name() {
        return 'wta-notes';
    }

    public function get_title() {
        return 'Field Notes';
    }

    public function get_icon() {
        return 'eicon-post-info';
    }

    public function get_categories() {
        return array(WTA_Elementor::CATEGORY);
    }

    public function get_style_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    /**
     * Presentation only. The notes come from the trip's `notes` meta so a visa
     * rule change is edited once, not once per page.
     */
    protected function _register_controls() {
        $this->start_controls_section('wta_notes_display', array(
            'label' => 'Display',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('heading', array(
            'label'   => 'Section heading',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Field notes',
        ));

        $this->add_control('standfirst', array(
            'label'   => 'Standfirst',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'rows'    => 3,
            'default' => 'The practical detail that decides whether a trip runs smoothly.',
        ));

        $this->add_responsive_control('columns', array(
            'label'   => 'Columns',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => array(
                '2' => '2',
                '3' => '3',
                '4' => '4',
            ),
            'tablet_default' => '2',
            'mobile_default' => '1',
            'description'    => 'Set per device. A four-column grid that stays four columns on a phone gives each card about eighty pixels, so the tablet and mobile counts are the ones that matter most.',
            'selectors'      => array(
                '{{WRAPPER}} .wta-notegrid' => '--wta-cols: {{VALUE}};',
            ),
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_box_style_section('notes', 'Note', '.wta-note', array('grid' => '.wta-notegrid'));

        $this->wta_text_style_section('notes', 'Note text', array(
            'note'    => array('label' => 'Body',    'selector' => '.wta-note'),
            'eyebrow' => array('label' => 'Eyebrow', 'selector' => '.wta-eyebrow'),
        ));

        $this->start_controls_section('notes_align_style', array(
            'label' => 'Alignment',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->wta_align_control('notes', '.wta-note');
        $this->end_controls_section();

    }

    public function render() {
        $settings = $this->get_settings_for_display();
        $data     = WTA_Elementor::trip_data();
        $notes    = array();

        if (!empty($data['notes']) && is_array($data['notes'])) {
            foreach ($data['notes'] as $note) {
                if (is_array($note)) {
                    $notes[] = array(
                        'heading' => isset($note['heading']) ? (string) $note['heading'] : '',
                        'body'    => isset($note['body']) ? (string) $note['body'] : '',
                    );
                }
            }
        }

        echo '<div class="wta-itin">';

        if (!$notes) {
            echo '<p class="wta-eyebrow">No field notes have been added to this trip yet.</p>';
            echo '</div>';

            return;
        }

        // Clamped rather than trusted: an out-of-range value would emit a grid
        // template the stylesheet has no matching tile styling for.
        $columns = isset($settings['columns']) ? (int) $settings['columns'] : 3;
        $columns = min(4, max(2, $columns));

        echo '<section class="wta-section wta-notes"><div class="wta-wrap">';

        echo '<div class="wta-sec-head">';

        if (!empty($settings['heading'])) {
            echo '<h2>' . esc_html($settings['heading']) . '</h2>';
        }

        if (!empty($settings['standfirst'])) {
            echo '<p>' . esc_html($settings['standfirst']) . '</p>';
        }

        echo '</div>';

        printf(
            '<div class="wta-notegrid" style="grid-template-columns:repeat(%d,1fr)">',
            $columns
        );

        foreach ($notes as $note) {
            echo '<div class="wta-note">';
            echo '<h4>' . esc_html($note['heading']) . '</h4>';

            if ('' !== $note['body']) {
                // Author HTML, already run through wp_kses_post on save.
                echo '<p>' . wp_kses_post($note['body']) . '</p>';
            }

            echo '</div>';
        }

        echo '</div>';

        echo '</div></section>';

        echo '</div>';
    }
}
