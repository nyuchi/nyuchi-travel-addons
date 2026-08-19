<?php
/**
 * Legs and day cards.
 *
 * Legs do not hold day content: they hold a range of indexes into WP Travel's
 * own itinerary array, so re-grouping the journey never risks the day text
 * drifting from what the trip actually says.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Legs extends \Elementor\Widget_Base {

    public function get_name() {
        return 'wta-legs';
    }

    public function get_title() {
        return esc_html__('Legs & Days', 'wp-travel-addons');
    }

    public function get_icon() {
        return 'eicon-time-line';
    }

    public function get_categories() {
        return array(WTA_Elementor::CATEGORY);
    }

    public function get_style_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    public function get_script_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    protected function _register_controls() {
        $this->start_controls_section('wta_legs_presentation', array(
            'label' => esc_html__('Presentation', 'wp-travel-addons'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        // One widget per leg lets a template interleave legs with other
        // sections; the default renders the itinerary in one block.
        $this->add_control('leg', array(
            'label'       => esc_html__('Leg to render', 'wp-travel-addons'),
            'description' => esc_html__('0 renders every leg.', 'wp-travel-addons'),
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'min'         => 0,
            'step'        => 1,
            'default'     => 0,
        ));

        $this->add_control('show_numbers', array(
            'label'        => esc_html__('Leg numbers', 'wp-travel-addons'),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Show', 'wp-travel-addons'),
            'label_off'    => esc_html__('Hide', 'wp-travel-addons'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ));

        $this->end_controls_section();
    }

    /**
     * The line under a day title. WP Travel has no subtitle field, so the date
     * and time stand in — and where there is neither, nothing is invented.
     */
    protected function day_subtitle($day) {
        $parts = array();

        foreach (array('date', 'time') as $field) {
            if (empty($day[$field])) {
                continue;
            }

            $value = trim((string) $day[$field]);

            // WP Travel writes the literal string "Invalid date" into this field
            // when its date picker has been left in a bad state, and printing it
            // under a day title reads as a broken page rather than a missing
            // value. Anything that is not a real date is dropped.
            if ('' === $value || 0 === strcasecmp($value, 'invalid date')) {
                continue;
            }

            if ('date' === $field && false === strtotime($value)) {
                continue;
            }

            $parts[] = $value;
        }

        return implode(" \u{00B7} ", $parts);
    }

    protected function render() {
        $data = WTA_Elementor::trip_data();
        $legs = (isset($data['legs']) && is_array($data['legs'])) ? array_values($data['legs']) : array();
        $days = (isset($data['days']) && is_array($data['days'])) ? array_values($data['days']) : array();

        if (!$legs || !$days) {
            echo '<div class="wta-itin"><div class="wta-wrap"><p class="wta-eyebrow">'
                . esc_html__('Legs and days: no itinerary authored for this trip.', 'wp-travel-addons')
                . '</p></div></div>';

            return;
        }

        $settings = $this->get_settings_for_display();
        $pick     = isset($settings['leg']) ? (int) $settings['leg'] : 0;

        // Keep the original index: the leg number is the position in the whole
        // journey, not the position in what this widget happens to render.
        $render = array();

        foreach ($legs as $i => $leg) {
            if ($pick > 0 && $pick - 1 !== $i) {
                continue;
            }

            $render[$i] = $leg;
        }

        if (!$render) {
            echo '<div class="wta-itin"><div class="wta-wrap"><p class="wta-eyebrow">'
                . esc_html__('Legs and days: that leg does not exist on this trip.', 'wp-travel-addons')
                . '</p></div></div>';

            return;
        }

        $first_open = true;
        ?>
        <div class="wta-itin">
            <?php foreach ($render as $i => $leg) : ?>
                <?php
                $accent = isset($leg['accent']) ? $leg['accent'] : 'forest';
                $from   = isset($leg['day_from']) ? max(0, (int) $leg['day_from']) : 0;
                $to     = isset($leg['day_to']) ? (int) $leg['day_to'] : $from;
                $slice  = array_slice($days, $from, max(0, $to - $from + 1), true);
                ?>
                <section class="wta-leg wta-accent-<?php echo esc_attr($accent); ?>">
                    <div class="wta-wrap">
                        <div class="wta-leghead">
                            <?php if ('yes' === $settings['show_numbers']) : ?>
                                <div class="wta-legno"><?php echo esc_html(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)); ?></div>
                            <?php endif; ?>
                            <div class="wta-legtitle">
                                <h3><?php
                                    $leg_title = isset($leg['title']) ? $leg['title'] : '';
                                    // A leg named after one of the trip's own destinations should
                                    // lead there. Plain text when it matches nothing.
                                    if (!empty($leg['term_link'])) {
                                        printf(
                                            '<a class="wta-leglink" href="%s">%s</a>',
                                            esc_url($leg['term_link']),
                                            esc_html($leg_title)
                                        );
                                    } else {
                                        echo esc_html($leg_title);
                                    }
                                ?></h3>
                                <?php if (!empty($leg['subtitle'])) : ?>
                                    <p><?php echo esc_html($leg['subtitle']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="wta-days">
                            <?php foreach ($slice as $index => $day) : ?>
                                <?php
                                $open     = $first_open;
                                $body_id  = $this->get_id() . '-day-' . $index;
                                $label    = !empty($day['label']) ? $day['label'] : sprintf('Day %d', $index + 1);
                                $subtitle = $this->day_subtitle($day);

                                $first_open = false;
                                ?>
                                <div class="wta-day<?php echo $open ? ' is-open' : ''; ?>">
                                    <button type="button"
                                            aria-expanded="<?php echo $open ? 'true' : 'false'; ?>"
                                            aria-controls="<?php echo esc_attr($body_id); ?>">
                                        <span class="wta-dnum"><?php echo esc_html($label); ?></span>
                                        <span class="wta-dtitle">
                                            <?php echo esc_html(isset($day['title']) ? $day['title'] : ''); ?>
                                            <?php if ('' !== $subtitle) : ?>
                                                <em><?php echo esc_html($subtitle); ?></em>
                                            <?php endif; ?>
                                        </span>
                                        <span class="wta-chev">+</span>
                                    </button>
                                    <div class="wta-dbody" id="<?php echo esc_attr($body_id); ?>">
                                        <?php // Day copy is author HTML, sanitised on write: escaping it here would print the markup. ?>
                                        <?php echo wp_kses_post(isset($day['desc']) ? $day['desc'] : ''); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
