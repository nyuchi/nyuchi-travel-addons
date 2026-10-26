<?php
/**
 * Trip hero.
 *
 * The headline is stored as one entry per rendered line rather than as a single
 * string: the design breaks the title across lines deliberately and colours
 * individual lines, which a wrapped block of text cannot express.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Hero extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    public function get_name() {
        return 'wta-hero';
    }

    public function get_title() {
        return esc_html__('Trip Hero', 'wp-travel-addons');
    }

    public function get_icon() {
        return 'eicon-site-title';
    }

    public function get_categories() {
        return array(WTA_Elementor::CATEGORY);
    }

    public function get_style_depends() {
        return array(WTA_Elementor::HANDLE);
    }

    protected function _register_controls() {
        $this->start_controls_section('wta_hero_presentation', array(
            'label' => esc_html__('Presentation', 'wp-travel-addons'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        // Content is trip meta; the only decision left to the page is whether
        // this hero is the one that carries the section divider.
        $this->add_control('show_bar', array(
            'label'        => esc_html__('Gradient bar', 'wp-travel-addons'),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Show', 'wp-travel-addons'),
            'label_off'    => esc_html__('Hide', 'wp-travel-addons'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ));

        $this->add_control('suppress_header_title', array(
            'label'       => 'Hide the header page title',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'The theme header prints the post title as an H1 on single trips. This hero is the page title, so leaving both gives two H1s on the page.',
        ));

        $this->add_control('show_art', array(
            'label'       => 'Show the hero triptych',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'One panel per leg, built from the trip featured image.',
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_media_style_section(
            'hero', 'Feature image', '.wta-hero-art', '.wta-panel-img'
        );

        $this->wta_box_style_section('hero', 'Hero panel', '.wta-hero');

        $this->wta_text_style_section('hero', 'Hero text', array(
            'line'    => array('label' => 'Headline', 'selector' => '.wta-line',       'spacing' => true),
            'sub'     => array('label' => 'Subtitle', 'selector' => '.wta-hero-sub',   'spacing' => true),
            'stats'   => array('label' => 'Stats',    'selector' => '.wta-hero-stats'),
            'eyebrow' => array('label' => 'Eyebrow',  'selector' => '.wta-eyebrow'),
        ));

    }

    /**
     * Hero triptych.
     *
     * One panel per leg, up to three, so the panel count and colours describe
     * the actual shape of the journey rather than being decoration. Falls back
     * to a single panel when a trip has no legs authored.
     */
    protected function render_art($data, $settings) {
        $legs = (!empty($data['legs']) && is_array($data['legs'])) ? array_slice($data['legs'], 0, 3) : array();

        if (!$legs) {
            $legs = array(array('title' => '', 'accent' => 'forest'));
        }

        $image = get_the_post_thumbnail_url(get_the_ID(), $this->wta_image_size('hero', $settings));

        echo '<div class="wta-hero-art" aria-hidden="true"><div class="wta-triptych" data-panels="' . count($legs) . '">';

        foreach ($legs as $i => $leg) {
            $accent = !empty($leg['accent']) ? $leg['accent'] : 'forest';
            $label  = !empty($leg['title']) ? $leg['title'] : '';

            printf('<figure class="wta-panel wta-accent-%s">', esc_attr($accent));

            // The image box is always rendered so every panel is the same size,
            // with or without a photograph. The photograph is shown as it is: no
            // tint, no filter, no overlay.
            printf(
                '<span class="wta-panel-img"%s></span>',
                $image
                    ? sprintf(
                        ' style="background-image:url(%s);background-position:%d%% 50%%"',
                        esc_url($image),
                        (int) (count($legs) > 1 ? $i * (100 / (count($legs) - 1)) : 50)
                    )
                    : ''
            );

            if ($label) {
                printf(
                    '<figcaption><b>%02d</b><span>%s</span></figcaption>',
                    $i + 1,
                    esc_html($label)
                );
            }

            echo '</figure>';
        }

        echo '</div></div>';
    }

    /**
     * "How to read this hero" strip.
     *
     * Describes only what the hero really does: the headline lines, the stats
     * and the numbered panels (one per leg, in trip order, each matching a leg
     * section further down). The panels are not interactive, so no hover or tap
     * behaviour is promised. Items are dropped when they do not apply: no stats
     * means no "at a glance" item, and a single leg (or no triptych) means no
     * panel item. With fewer than two items there is nothing to explain.
     *
     * The live site carries a stopgap copy (.wta-guide-stopgap in the trip
     * template plus Additional CSS) that hides itself when this strip exists.
     */
    protected function render_guide($stats, $data, $settings) {
        $legs = (!empty($data['legs']) && is_array($data['legs'])) ? count(array_slice($data['legs'], 0, 3)) : 0;

        $items = array(
            array(
                'label' => __('The story', 'wp-travel-addons'),
                'text'  => __('The headline tells the journey in a few lines, one after another.', 'wp-travel-addons'),
            ),
        );

        if ($stats) {
            $items[] = array(
                'label' => __('At a glance', 'wp-travel-addons'),
                'text'  => __('The figures beneath it are the essential facts of the trip.', 'wp-travel-addons'),
            );
        }

        if ('yes' === $settings['show_art'] && $legs > 1) {
            $items[] = array(
                'label' => __('The legs', 'wp-travel-addons'),
                'text'  => __('Read the images left to right: each panel is one leg, in the order you travel it, and each is set out in full below.', 'wp-travel-addons'),
            );
        }

        if (count($items) < 2) {
            return;
        }

        echo '<ul class="wta-hero-guide" aria-label="' . esc_attr__('How to read this hero', 'wp-travel-addons') . '">';

        foreach ($items as $item) {
            printf(
                '<li><b>%s</b><span>%s</span></li>',
                esc_html($item['label']),
                esc_html($item['text'])
            );
        }

        echo '</ul>';
    }

    protected function render() {
        $data = WTA_Elementor::trip_data();

        if (empty($data)) {
            echo '<div class="wta-itin"><div class="wta-wrap"><p class="wta-eyebrow">'
                . esc_html__('Trip hero: no trip data on this post.', 'wp-travel-addons')
                . '</p></div></div>';

            return;
        }

        $settings = $this->get_settings_for_display();
        $hero     = isset($data['hero']) && is_array($data['hero']) ? $data['hero'] : array();

        $lines = (!empty($hero['headline']) && is_array($hero['headline'])) ? $hero['headline'] : array();

        // An unauthored hero still has a title worth showing.
        if (!$lines) {
            $lines = array(array('text' => get_the_title(), 'accent' => ''));
        }

        $stats = (!empty($hero['stats']) && is_array($hero['stats'])) ? $hero['stats'] : array();
        ?>
        <?php if ('yes' === $settings['suppress_header_title']) : ?>
            <?php
            // Only H1 headings inside the theme-builder header location are
            // targeted. Everything else in that header is an H2, so this
            // removes the duplicated page title and nothing else.
            ?>
            <style>.elementor-location-header h1.elementor-heading-title{display:none}</style>
        <?php endif; ?>
        <div class="wta-itin">
            <header class="wta-hero">
                <div class="wta-wrap">
                    <div class="wta-hero-grid">
                        <div>
                            <?php if (!empty($hero['eyebrow'])) : ?>
                                <p class="wta-eyebrow"><?php echo esc_html($hero['eyebrow']); ?></p>
                            <?php endif; ?>
                            <h1>
                                <?php foreach ($lines as $line) : ?>
                                    <?php $text = isset($line['text']) ? $line['text'] : ''; ?>
                                    <span class="wta-line" data-accent="<?php echo esc_attr(isset($line['accent']) ? $line['accent'] : ''); ?>"><?php echo esc_html($text); ?></span>
                                <?php endforeach; ?>
                            </h1>
                        </div>
                            <?php if (!empty($hero['standfirst'])) : ?>
                                <?php // Authored rich text: sanitised on write, so keep the markup. ?>
                                <p class="wta-hero-sub"><?php echo wp_kses_post($hero['standfirst']); ?></p>
                            <?php endif; ?>
                            <?php if ($stats) : ?>
                                <div class="wta-hero-stats">
                                    <?php foreach ($stats as $stat) : ?>
                                        <div class="wta-stat">
                                            <b><?php echo esc_html(isset($stat['value']) ? $stat['value'] : ''); ?></b>
                                            <span><?php echo esc_html(isset($stat['label']) ? $stat['label'] : ''); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php
                        // The reference design puts a three-panel illustration here.
                        // Hand-drawn art cannot scale across a catalogue, so the
                        // triptych is built from the trip's own legs: one panel per
                        // leg, carrying that leg's accent, over the featured image.
                        if ('yes' === $settings['show_art']) {
                            $this->render_art($data, $settings);
                        }
                        ?>
                    </div>
                    <?php $this->render_guide($stats, $data, $settings); ?>
                    <?php if ('yes' === $settings['show_bar']) : ?>
                        <div class="wta-gradient-bar"></div>
                    <?php endif; ?>
                </div>
            </header>
        </div>
        <?php
    }
}
