<?php
/**
 * Cost estimator.
 *
 * Trips of this kind have no single price: the figure moves with comfort tier,
 * optional permits and party size. Rather than publish one number and qualify
 * it in prose, the widget publishes the model and lets the reader turn the
 * dials.
 *
 * Every total is computed in PHP for the default selection, so the panel is
 * already truthful with scripting off; the same model is emitted as JSON so the
 * script recomputes locally instead of asking the server.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Cost extends \Elementor\Widget_Base {

    /** Guard rails for the party-size stepper. */
    const MIN_TRAVELLERS = 1;
    const MAX_TRAVELLERS = 12;

    public function get_name() {
        return 'wta-cost';
    }

    public function get_title() {
        return 'Cost Estimator';
    }

    public function get_icon() {
        return 'eicon-price-table';
    }

    public function get_categories() {
        return array(WTA_Elementor::CATEGORY);
    }

    public function get_style_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    /**
     * Presentation only. Prices, tiers and add-ons come from the trip's `cost`
     * meta so a rate change is edited once, not once per page.
     */
    protected function _register_controls() {
        $this->start_controls_section('wta_cost_display', array(
            'label' => 'Display',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('heading', array(
            'label'   => 'Section heading',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'What it costs',
        ));

        $this->add_control('standfirst', array(
            'label'   => 'Standfirst',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'rows'    => 3,
            'default' => 'Indicative per-person pricing. Move the controls to see how the estimate changes.',
        ));

        $this->add_control('travellers', array(
            'label'   => 'Default travellers',
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'min'     => self::MIN_TRAVELLERS,
            'max'     => self::MAX_TRAVELLERS,
            'step'    => 1,
            'default' => 2,
        ));

        $this->add_control('request_label', array(
            'label'   => 'Label when the trip has no price',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Priced on request',
        ));

        $this->add_control('request_body', array(
            'label'   => 'Explanation when the trip has no price',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'default' => 'This itinerary is costed individually. The final figure depends on the season you travel, the camps chosen, and the size of your party.',
        ));

        $this->add_control('request_cta', array(
            'label'   => 'Button label',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Request a quote',
        ));

        $this->add_control('request_link', array(
            'label'       => 'Button link',
            'type'        => \Elementor\Controls_Manager::URL,
            'default'     => array('url' => '#wp-travel-enquiries'),
            'description' => 'Defaults to the WP Travel enquiry form on the same page.',
        ));

        $this->end_controls_section();
    }

    /**
     * The state for a trip with no published price.
     *
     * Deliberately not a placeholder: it keeps the section heading a priced
     * trip would have, explains what the figure depends on, and ends on the
     * enquiry rather than on nothing.
     */
    protected function render_on_request($settings) {
        $heading  = !empty($settings['heading']) ? $settings['heading'] : 'What it costs';
        $label    = !empty($settings['request_label']) ? $settings['request_label'] : 'Priced on request';
        $body     = !empty($settings['request_body'])
            ? $settings['request_body']
            : 'This itinerary is costed individually. The final figure depends on the season you travel, the camps chosen, and the size of your party.';
        $cta      = !empty($settings['request_cta']) ? $settings['request_cta'] : 'Request a quote';
        $cta_link = !empty($settings['request_link']['url']) ? $settings['request_link']['url'] : '#wp-travel-enquiries';

        echo '<section class="wta-calc"><div class="wta-wrap">';
        echo '<div class="wta-sec-head"><h2>' . esc_html($heading) . '</h2></div>';
        echo '<div class="wta-onrequest">';
        echo '<div class="wta-onrequest-lead">' . esc_html($label) . '</div>';
        echo '<div class="wta-onrequest-body"><p>' . wp_kses_post($body) . '</p>';
        echo '<a class="wta-onrequest-cta" href="' . esc_url($cta_link) . '">' . esc_html($cta) . '</a>';
        echo '</div></div>';
        echo '</div></section>';
    }

    public function render() {
        $settings = $this->get_settings_for_display();
        $data     = WTA_Elementor::trip_data();
        $cost     = isset($data['cost']) && is_array($data['cost']) ? $data['cost'] : array();

        $tiers  = $this->tiers($cost);
        $addons = $this->addons($cost);

        echo '<div class="wta-itin">';

        if (!$tiers) {
            // Most of this catalogue is quoted rather than listed, so an absent
            // price is the normal case, not an error. Saying so deliberately
            // beats hiding the section: the reader still learns what drives the
            // cost, and is handed the next step.
            $this->render_on_request($settings);
            echo '</div>';

            return;
        }

        $currency = !empty($cost['currency']) ? (string) $cost['currency'] : 'USD';
        $fees     = isset($cost['fees']) ? (float) $cost['fees'] : 0.0;
        $note     = isset($cost['note']) ? (string) $cost['note'] : '';

        $travellers = isset($settings['travellers']) ? (int) $settings['travellers'] : 2;
        $travellers = min(self::MAX_TRAVELLERS, max(self::MIN_TRAVELLERS, $travellers));

        // The default selection: first tier, first choice of every add-on.
        $tier      = $tiers[0];
        $selected  = array();
        $addon_sum = 0.0;

        foreach ($addons as $addon) {
            $choice      = $addon['choices'][0];
            $selected[]  = array('label' => $addon['label'], 'choice' => $choice);
            $addon_sum  += (float) $choice['price'];
        }

        $per_person = (float) $tier['land'] + $addon_sum + $fees + (float) $tier['flights'];
        $party      = $per_person * $travellers;

        echo '<section class="wta-section wta-calc"><div class="wta-wrap">';

        echo '<div class="wta-sec-head">';

        if (!empty($settings['heading'])) {
            echo '<h2>' . esc_html($settings['heading']) . '</h2>';
        }

        if (!empty($settings['standfirst'])) {
            echo '<p>' . esc_html($settings['standfirst']) . '</p>';
        }

        echo '</div>';

        echo '<div class="wta-calcgrid">';

        /* ------------------------------------------------------- controls */

        echo '<div>';

        echo '<div class="wta-field">';
        echo '<label>Comfort level</label>';
        // data-role and data-addon are how the script tells the segmented
        // groups apart; without them it would have to trust document order.
        echo '<div class="wta-seg" data-role="tier">';

        foreach ($tiers as $index => $entry) {
            printf(
                '<button type="button" aria-pressed="%s" data-index="%d">%s</button>',
                0 === $index ? 'true' : 'false',
                (int) $index,
                esc_html($entry['name'])
            );
        }

        echo '</div></div>';

        foreach ($addons as $a => $addon) {
            echo '<div class="wta-field">';
            echo '<label>' . esc_html($addon['label']) . '</label>';
            printf('<div class="wta-seg" data-role="addon" data-addon="%d">', (int) $a);

            foreach ($addon['choices'] as $c => $choice) {
                printf(
                    '<button type="button" aria-pressed="%s" data-index="%d">%s</button>',
                    0 === $c ? 'true' : 'false',
                    (int) $c,
                    esc_html($choice['name'])
                );
            }

            echo '</div></div>';
        }

        echo '<div class="wta-field">';
        echo '<label>Travellers</label>';
        echo '<div class="wta-stepper">';
        printf(
            '<button type="button" data-step="-1" aria-label="Fewer travellers" data-min="%d">&minus;</button>',
            self::MIN_TRAVELLERS
        );
        // The script seeds its party size from this value, so the server-side
        // default survives hydration.
        printf('<output aria-live="polite">%d</output>', $travellers);
        printf(
            '<button type="button" data-step="1" aria-label="More travellers" data-max="%d">+</button>',
            self::MAX_TRAVELLERS
        );
        echo '</div></div>';

        echo '</div>';

        /* -------------------------------------------------------- readout */

        echo '<div>';

        /*
         * Only the component lines live inside .wta-readout: the script rebuilds
         * that element wholesale on every change, so the total, the party line
         * and the disclaimer have to sit outside it to survive.
         */
        echo '<div class="wta-readout">';

        $this->line($tier['name'] . ' — land', $tier['land'], $currency);
        $this->line('Internal flights', $tier['flights'], $currency);

        // A zero fee is not a cost component; the script omits the row too.
        if ($fees) {
            $this->line('Park and conservation fees', $fees, $currency);
        }

        foreach ($selected as $entry) {
            $this->line(
                $entry['label'] . ' — ' . $entry['choice']['name'],
                $entry['choice']['price'],
                $currency
            );
        }

        echo '</div>';

        echo '<div class="wta-total">';
        echo '<span class="wta-lab">Per person</span>';
        echo '<span class="wta-amt">' . esc_html($this->money($per_person, $currency)) . '</span>';
        echo '</div>';

        printf(
            '<div class="wta-groupline">%s</div>',
            esc_html($this->party_line($travellers, $party, $currency))
        );

        if ('' !== $note) {
            // Author HTML, already run through wp_kses_post on save.
            echo '<div class="wta-disc">' . wp_kses_post($note) . '</div>';
        }

        echo '</div>';

        echo '</div></div></section>';

        // A JSON island rather than an inline variable: the browser never
        // executes it, so no author string can escape into script context.
        $payload = array(
            'currency'   => $currency,
            'symbol'     => $this->symbol($currency),
            'fees'       => $fees,
            'tiers'      => $tiers,
            'addons'     => $addons,
            'travellers' => array(
                'value' => $travellers,
                'min'   => self::MIN_TRAVELLERS,
                'max'   => self::MAX_TRAVELLERS,
            ),
        );

        echo '<script type="application/json" class="wta-cost-data">'
            . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script>';

        echo '</div>';
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * Tiers, normalised. A tier with no name is still priceable, so it is kept
     * and labelled by position rather than dropped.
     *
     * @param array $cost
     * @return array<int, array{name: string, land: float, flights: float}>
     */
    protected function tiers($cost) {
        $out = array();

        if (empty($cost['tiers']) || !is_array($cost['tiers'])) {
            return $out;
        }

        foreach (array_values($cost['tiers']) as $index => $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $name = isset($tier['name']) ? (string) $tier['name'] : '';

            $out[] = array(
                'name'    => '' !== $name ? $name : 'Option ' . ($index + 1),
                'land'    => isset($tier['land']) ? (float) $tier['land'] : 0.0,
                'flights' => isset($tier['flights']) ? (float) $tier['flights'] : 0.0,
            );
        }

        return $out;
    }

    /**
     * Add-ons, normalised. An add-on with no choices cannot be selected, so it
     * is dropped rather than rendered as an empty control.
     *
     * @param array $cost
     * @return array<int, array{label: string, choices: array}>
     */
    protected function addons($cost) {
        $out = array();

        if (empty($cost['addons']) || !is_array($cost['addons'])) {
            return $out;
        }

        foreach ($cost['addons'] as $addon) {
            if (!is_array($addon)) {
                continue;
            }

            $choices = array();

            if (!empty($addon['choices']) && is_array($addon['choices'])) {
                foreach (array_values($addon['choices']) as $index => $choice) {
                    if (!is_array($choice)) {
                        continue;
                    }

                    $name = isset($choice['name']) ? (string) $choice['name'] : '';

                    $choices[] = array(
                        'name'  => '' !== $name ? $name : 'Choice ' . ($index + 1),
                        'price' => isset($choice['price']) ? (float) $choice['price'] : 0.0,
                    );
                }
            }

            if (!$choices) {
                continue;
            }

            $label = isset($addon['label']) ? (string) $addon['label'] : '';

            $out[] = array(
                'label'   => '' !== $label ? $label : 'Add-on',
                'choices' => $choices,
            );
        }

        return $out;
    }

    /**
     * One readout row, in the shape the script rebuilds rows in.
     *
     * @param string $label
     * @param float  $amount
     * @param string $currency
     */
    protected function line($label, $amount, $currency) {
        printf(
            '<div class="wta-costline"><span>%s</span><span>%s</span></div>',
            esc_html($label),
            esc_html($this->money($amount, $currency))
        );
    }

    /**
     * A solo traveller is quoted a different way: the per-person figures assume
     * shared accommodation, so the party total would understate the real cost.
     *
     * @param int    $travellers
     * @param float  $party
     * @param string $currency
     * @return string
     */
    protected function party_line($travellers, $party, $currency) {
        if (1 === (int) $travellers) {
            return 'Travelling alone — a single supplement applies and is not included above.';
        }

        return $travellers . ' travellers · ' . $this->money($party, $currency) . ' total';
    }

    /**
     * Symbol for the currencies we actually quote in; anything else keeps its
     * ISO code, which is unambiguous even if it is less pretty.
     *
     * @param string $currency
     * @return string
     */
    protected function symbol($currency) {
        $symbols = array(
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'ZAR' => 'R',
            'AUD' => 'A$',
            'CAD' => 'C$',
        );

        $code = strtoupper(trim($currency));

        return isset($symbols[$code]) ? $symbols[$code] : $code . ' ';
    }

    /**
     * Estimates are never quoted to the cent; rounding to whole units keeps the
     * readout honest about its precision.
     *
     * @param float  $amount
     * @param string $currency
     * @return string
     */
    protected function money($amount, $currency) {
        return $this->symbol($currency) . number_format(round((float) $amount), 0, '.', ',');
    }
}
