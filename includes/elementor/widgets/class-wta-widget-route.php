<?php
/**
 * Route map.
 *
 * Stops carry viewport percentages rather than latitude and longitude, so the
 * map is a stylised diagram an editor can nudge, not a projection. The SVG is
 * inlined rather than loaded as an image because the stops are interactive and
 * inherit the palette from the surrounding CSS custom properties.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Route extends \Elementor\Widget_Base {

    /** The viewBox the coordinate percentages are scaled into. */
    const VIEW_W = 1000;
    const VIEW_H = 750;

    public function get_name() {
        return 'wta-route';
    }

    public function get_title() {
        return esc_html__('Route Map', 'wp-travel-addons');
    }

    public function get_icon() {
        return 'eicon-google-maps';
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
        $this->start_controls_section('wta_route_presentation', array(
            'label' => esc_html__('Presentation', 'wp-travel-addons'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('heading', array(
            'label'   => esc_html__('Section heading', 'wp-travel-addons'),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__('The route', 'wp-travel-addons'),
        ));

        $this->add_control('standfirst', array(
            'label' => esc_html__('Standfirst', 'wp-travel-addons'),
            'type'  => \Elementor\Controls_Manager::TEXTAREA,
            'rows'  => 3,
        ));

        // The map panel is the one element that may need to sit against a
        // different backdrop when the section is reused on a lighter page.
        $this->add_control('map_bg', array(
            'label'     => esc_html__('Map background', 'wp-travel-addons'),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .wta-mapfig' => 'background: {{VALUE}};',
            ),
        ));

        $this->end_controls_section();
    }

    /**
     * Percentage coordinates to viewBox units.
     *
     * @return array{0: float, 1: float}
     */
    protected function point($stop) {
        $x = isset($stop['x']) ? (float) $stop['x'] : 50;
        $y = isset($stop['y']) ? (float) $stop['y'] : 50;

        return array(
            round($x * (self::VIEW_W / 100), 2),
            round($y * (self::VIEW_H / 100), 2),
        );
    }

    /**
     * A flight arcs, a drive runs straight. The arc is bowed off the midpoint
     * perpendicular to the leg so two stops close together still read as a hop
     * rather than a doubled-up line.
     */
    protected function path_d($from, $to, $fly) {
        list($x1, $y1) = $from;
        list($x2, $y2) = $to;

        if (!$fly) {
            return sprintf('M %s %s L %s %s', $x1, $y1, $x2, $y2);
        }

        $dx  = $x2 - $x1;
        $dy  = $y2 - $y1;
        $len = sqrt(($dx * $dx) + ($dy * $dy));

        if ($len <= 0) {
            return sprintf('M %s %s L %s %s', $x1, $y1, $x2, $y2);
        }

        $bow = $len * 0.18;
        $cx  = round((($x1 + $x2) / 2) + ((-$dy / $len) * $bow), 2);
        $cy  = round((($y1 + $y2) / 2) + (($dx / $len) * $bow), 2);

        return sprintf('M %s %s Q %s %s %s %s', $x1, $y1, $cx, $cy, $x2, $y2);
    }

    protected function nights_label($nights) {
        $nights = (int) $nights;

        if ($nights < 1) {
            return esc_html__('Transit', 'wp-travel-addons');
        }

        return esc_html(sprintf(_n('%d night', '%d nights', $nights, 'wp-travel-addons'), $nights));
    }

    protected function render() {
        $data  = WTA_Elementor::trip_data();
        $stops = (isset($data['route']) && is_array($data['route'])) ? array_values($data['route']) : array();

        if (!$stops) {
            echo '<div class="wta-itin"><div class="wta-wrap"><p class="wta-eyebrow">'
                . esc_html__('Route map: no stops authored for this trip.', 'wp-travel-addons')
                . '</p></div></div>';

            return;
        }

        $settings = $this->get_settings_for_display();
        $points   = array_map(array($this, 'point'), $stops);
        ?>
        <div class="wta-itin">
            <section class="wta-route wta-section">
                <div class="wta-wrap">
                    <div class="wta-sec-head">
                        <h2><?php echo esc_html($settings['heading']); ?></h2>
                        <?php if (!empty($settings['standfirst'])) : ?>
                            <p><?php echo esc_html($settings['standfirst']); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="wta-mapwrap">
                        <figure class="wta-mapfig">
                            <svg viewBox="0 0 <?php echo esc_attr(self::VIEW_W); ?> <?php echo esc_attr(self::VIEW_H); ?>"
                                 preserveAspectRatio="xMidYMid meet"
                                 role="img"
                                 aria-label="<?php echo esc_attr__('Route map', 'wp-travel-addons'); ?>">
                                <?php
                                // The connector belongs to the arrival: how you
                                // reach a stop is a property of that stop, and
                                // the first stop is reached by getting there.
                                foreach ($stops as $i => $stop) :
                                    if (0 === $i) {
                                        continue;
                                    }

                                    $arrive = isset($stop['arrive']) ? $stop['arrive'] : 'drive';

                                    if ('start' === $arrive) {
                                        continue;
                                    }

                                    $class = ('fly' === $arrive) ? 'wta-flightpath' : 'wta-roadpath';
                                    $d     = $this->path_d($points[$i - 1], $points[$i], 'fly' === $arrive);
                                    ?>
                                    <path class="<?php echo esc_attr($class); ?>" d="<?php echo esc_attr($d); ?>"></path>
                                <?php endforeach; ?>

                                <?php foreach ($stops as $i => $stop) : ?>
                                    <?php
                                    list($x, $y) = $points[$i];

                                    // Labels flip to the left of the dot near
                                    // the right edge so they stay on canvas.
                                    $flip   = $x > (self::VIEW_W * 0.65);
                                    $anchor = $flip ? 'end' : 'start';
                                    $tx     = $flip ? $x - 20 : $x + 20;
                                    ?>
                                    <g class="wta-stop" data-i="<?php echo esc_attr($i); ?>">
                                        <circle class="wta-hit" cx="<?php echo esc_attr($x); ?>" cy="<?php echo esc_attr($y); ?>" r="28"></circle>
                                        <circle class="wta-dot" cx="<?php echo esc_attr($x); ?>" cy="<?php echo esc_attr($y); ?>" r="6" fill="var(--dust)"></circle>
                                        <text x="<?php echo esc_attr($tx); ?>" y="<?php echo esc_attr($y + 5); ?>" text-anchor="<?php echo esc_attr($anchor); ?>"><?php echo esc_html(isset($stop['name']) ? $stop['name'] : ''); ?></text>
                                    </g>
                                <?php endforeach; ?>
                            </svg>
                        </figure>

                        <div class="wta-maplist">
                            <?php foreach ($stops as $i => $stop) : ?>
                                <button type="button" class="wta-mapitem" data-i="<?php echo esc_attr($i); ?>">
                                    <span class="wta-n"><?php echo esc_html(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                                    <span class="wta-nm">
                                        <?php echo esc_html(isset($stop['name']) ? $stop['name'] : ''); ?>
                                        <?php if (!empty($stop['subtitle'])) : ?>
                                            <em><?php echo esc_html($stop['subtitle']); ?></em>
                                        <?php endif; ?>
                                    </span>
                                    <span class="wta-nt"><?php echo $this->nights_label(isset($stop['nights']) ? $stop['nights'] : 0); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <?php
    }
}
