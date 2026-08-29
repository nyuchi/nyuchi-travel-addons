<?php
/**
 * When to go: the month-by-month suitability matrix and its verdict panel.
 *
 * The whole twelve-month dataset is printed into the page as JSON. Switching
 * month is a reading action, not a navigation one, so it must not cost a
 * request; and the initially selected month is also rendered as real markup so
 * the panel still says something useful with scripting off.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Seasonality extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    public function get_name() {
        return 'wta-seasonality';
    }

    public function get_title() {
        return esc_html__('When To Go', 'wp-travel-addons');
    }

    public function get_icon() {
        return 'eicon-calendar';
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
        $this->start_controls_section('wta_season_presentation', array(
            'label' => esc_html__('Presentation', 'wp-travel-addons'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        // Section framing is page copy, not trip data: the same matrix reads
        // differently under "When to go" and "Weather and wildlife".
        $this->add_control('heading', array(
            'label'   => esc_html__('Section heading', 'wp-travel-addons'),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__('When to go', 'wp-travel-addons'),
        ));

        $this->add_control('standfirst', array(
            'label' => esc_html__('Standfirst', 'wp-travel-addons'),
            'type'  => \Elementor\Controls_Manager::TEXTAREA,
            'rows'  => 3,
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_box_style_section('seas', 'Matrix', '.wta-matrix');

        $this->wta_text_style_section('seas', 'Matrix text', array(
            'rowlabel' => array('label' => 'Row label', 'selector' => '.wta-rowlabel'),
            'month'    => array('label' => 'Month',     'selector' => '.wta-monthbtn'),
            'legend'   => array('label' => 'Legend',    'selector' => '.wta-legend'),
        ));

    }

    /**
     * Month names, taken from the site locale so a translated site does not get
     * hard-coded English.
     *
     * @return array<int, array{full: string, abbr: string}>
     */
    protected function months() {
        $names = array();

        for ($i = 1; $i <= 12; $i++) {
            $stamp   = gmmktime(0, 0, 0, $i, 1, 2024);
            $names[] = array(
                'full' => date_i18n('F', $stamp),
                'abbr' => date_i18n('M', $stamp),
            );
        }

        return $names;
    }

    /**
     * How a month's rank reads in the verdict panel.
     */
    protected function rank_label($rank) {
        if ('primary' === $rank) {
            return esc_html__('Primary window', 'wp-travel-addons');
        }

        if ('alternative' === $rank) {
            return esc_html__('Alternative window', 'wp-travel-addons');
        }

        return esc_html__('Shoulder month', 'wp-travel-addons');
    }

    protected function render() {
        $data   = WTA_Elementor::trip_data();
        $season = isset($data['seasonality']) && is_array($data['seasonality']) ? $data['seasonality'] : array();

        $rows   = (!empty($season['rows']) && is_array($season['rows'])) ? $season['rows'] : array();
        $months = (!empty($season['months']) && is_array($season['months'])) ? array_values($season['months']) : array();

        if (!$rows || !$months) {
            echo '<div class="wta-itin"><div class="wta-wrap"><p class="wta-eyebrow">'
                . esc_html__('When to go: no seasonality authored for this trip.', 'wp-travel-addons')
                . '</p></div></div>';

            return;
        }

        $settings = $this->get_settings_for_display();
        $names    = $this->months();

        // Default to the first month the trip calls a primary window; that is
        // the answer most readers arrive looking for.
        $selected = 0;

        foreach ($months as $i => $month) {
            if (isset($month['rank']) && 'primary' === $month['rank']) {
                $selected = $i;
                break;
            }
        }

        $current = $months[$selected];
        $tags    = (!empty($current['tags']) && is_array($current['tags'])) ? $current['tags'] : array();

        // Shipped to the client so month switching needs no request. Tag and
        // angle brackets are hex-encoded: verdicts are author HTML and would
        // otherwise be able to close the script element.
        $payload = wp_json_encode(
            array(
                'selected' => $selected,
                'rows'     => $rows,
                'months'   => $this->month_payload($months, $names),
            ),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        ?>
        <div class="wta-itin">
            <section class="wta-season wta-section">
                <div class="wta-wrap">
                    <div class="wta-sec-head">
                        <h2><?php echo esc_html($settings['heading']); ?></h2>
                        <?php if (!empty($settings['standfirst'])) : ?>
                            <p><?php echo esc_html($settings['standfirst']); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="wta-matrix">
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $key    = isset($row['key']) ? $row['key'] : '';
                            $label  = isset($row['label']) ? $row['label'] : $key;
                            $accent = isset($row['accent']) ? $row['accent'] : 'forest';
                            ?>
                            <div class="wta-rowlabel"><?php echo esc_html($label); ?></div>
                            <div class="wta-mrow" data-accent="<?php echo esc_attr($accent); ?>" data-row="<?php echo esc_attr($key); ?>">
                                <?php foreach ($months as $i => $month) : ?>
                                    <?php $score = isset($month['scores'][$key]) ? (int) $month['scores'][$key] : 0; ?>
                                    <button type="button"
                                            class="wta-mcell"
                                            data-v="<?php echo esc_attr($score); ?>"
                                            data-month="<?php echo esc_attr($i); ?>"
                                            aria-label="<?php echo esc_attr(sprintf('%1$s, %2$s: %3$d of 3', $label, $names[$i]['full'], $score)); ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php // Empty label cell keeps the month strip aligned with the score columns. ?>
                        <div class="wta-rowlabel"></div>
                        <div class="wta-monthstrip">
                            <?php foreach ($months as $i => $month) : ?>
                                <button type="button"
                                        class="wta-monthbtn"
                                        data-month="<?php echo esc_attr($i); ?>"
                                        data-rank="<?php echo esc_attr(isset($month['rank']) ? $month['rank'] : ''); ?>"
                                        aria-pressed="<?php echo $i === $selected ? 'true' : 'false'; ?>"><?php echo esc_html($names[$i]['abbr']); ?><span class="wta-flag"></span></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="wta-legend">
                        <?php foreach ($rows as $row) : ?>
                            <?php $accent = isset($row['accent']) ? $row['accent'] : 'forest'; ?>
                            <span class="wta-accent-<?php echo esc_attr($accent); ?>">
                                <i style="background:var(--legfill)"></i><?php echo esc_html(isset($row['label']) ? $row['label'] : ''); ?>
                            </span>
                        <?php endforeach; ?>
                        <span><i style="background:rgba(20,17,14,.07)"></i><?php echo esc_html__('Avoid', 'wp-travel-addons'); ?></span>
                    </div>

                    <div class="wta-verdict">
                        <div class="wta-verdict-month">
                            <?php echo esc_html($names[$selected]['full']); ?>
                            <small><?php echo $this->rank_label(isset($current['rank']) ? $current['rank'] : ''); ?></small>
                        </div>
                        <div class="wta-verdict-body">
                            <?php // Verdicts are author HTML, sanitised on write. ?>
                            <p><?php echo wp_kses_post(isset($current['verdict']) ? $current['verdict'] : ''); ?></p>
                            <?php if ($tags) : ?>
                                <div class="wta-verdict-tags">
                                    <?php foreach ($tags as $tag) : ?>
                                        <span class="wta-tag"><?php echo esc_html($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <script type="application/json" class="wta-season-data"><?php echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, tag characters hex-encoded. ?></script>
                </div>
            </section>
        </div>
        <?php
    }

    /**
     * Month records for the client, with the display names resolved server-side
     * so the script never has to know about locales.
     *
     * @return array<int, array>
     */
    protected function month_payload($months, $names) {
        $out = array();

        foreach ($months as $i => $month) {
            $out[] = array(
                'index'     => $i,
                'name'      => $names[$i]['full'],
                'abbr'      => $names[$i]['abbr'],
                'rank'      => isset($month['rank']) ? $month['rank'] : '',
                'rankLabel' => $this->rank_label(isset($month['rank']) ? $month['rank'] : ''),
                'verdict'   => isset($month['verdict']) ? $month['verdict'] : '',
                'tags'      => (!empty($month['tags']) && is_array($month['tags'])) ? array_values($month['tags']) : array(),
                'scores'    => (!empty($month['scores']) && is_array($month['scores'])) ? $month['scores'] : array(),
            );
        }

        return $out;
    }
}
