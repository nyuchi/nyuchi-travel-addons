<?php
/**
 * Destination grid.
 *
 * The itinerary design language only existed on single trips, so a homepage had
 * to be built out of generic image boxes that drifted away from it within a
 * release. This puts the same tiles — term image, dark navy veil, display-font
 * name — on any page, driven by taxonomy terms rather than hand-placed images,
 * so adding a destination adds a tile.
 *
 * Terms are fetched in one get_terms() call and everything else is decided in
 * PHP. A homepage grid of six regions that queried per tile would be six extra
 * queries for a block that is on every page view.
 *
 * The default is top-level terms only. A homepage wants "East Africa", not the
 * thirty countries and parks beneath it; the country tiles belong on the region
 * archive that this same widget renders.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Destination_Grid extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    /**
     * Whether the grid CSS has already gone out this request.
     *
     * The rules are identical for every instance, so a page carrying a
     * destination grid and an experiences block below it would otherwise ship
     * the same block twice.
     *
     * @var bool
     */
    protected static $printed_css = false;

    public function get_name() {
        return 'wta-destinations';
    }

    public function get_title() {
        return 'Destination Grid';
    }

    public function get_icon() {
        return 'eicon-gallery-grid';
    }

    public function get_categories() {
        return array(defined('WTA_Elementor::CATEGORY') ? WTA_Elementor::CATEGORY : 'nyuchi-travel');
    }

    public function get_style_depends() {
        return array('wta-itinerary');
    }

    protected function register_controls() {
        $this->start_controls_section('content', array(
            'label' => 'Destinations',
        ));

        $this->add_control('taxonomy', array(
            'label'   => 'Taxonomy',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'travel_locations',
            'options' => WTA_Trip::default_taxonomies(),
        ));

        $this->add_control('count', array(
            'label'       => 'How many',
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'min'         => 1,
            'max'         => 24,
            'default'     => 6,
            'description' => 'Capped so a taxonomy of sixty terms cannot turn a homepage into a directory.',
        ));

        $this->add_responsive_control('columns', array(
            'label'   => 'Columns',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => array('2' => '2', '3' => '3', '4' => '4', '5' => '5'),
            'tablet_default' => '2',
            'mobile_default' => '1',
            'description'    => 'Set per device. A four-column grid that stays four columns on a phone gives each card about eighty pixels, so the tablet and mobile counts are the ones that matter most.',
            'selectors'      => array(
                '{{WRAPPER}} .wta-dest-grid' => '--wta-cols: {{VALUE}};',
            ),
        ));

        $this->add_control('aspect', array(
            'label'       => 'Tile shape',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => '3-4',
            'options'     => array(
                '3-4'  => 'Vertical (3:4)',
                '4-3'  => 'Landscape (4:3)',
                '1-1'  => 'Square',
                '16-9' => 'Wide',
            ),
            'description' => 'Vertical is the house default and matches the trip gallery.',
        ));

        $this->add_control('orderby', array(
            'label'   => 'Order by',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'name',
            'options' => array(
                'name'       => 'Name',
                'count'      => 'Most trips first',
                'term_order' => 'Term order',
            ),
        ));

        $this->add_control('top_level', array(
            'label'       => 'Only top-level terms',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'Shows regions rather than every country beneath them. Turn off on a deeper page.',
        ));

        $this->add_control('hide_empty', array(
            'label'       => 'Hide empty',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'Skips terms with no trips. A parent counts the trips of its children.',
        ));

        $this->add_control('show_count', array(
            'label'   => 'Show trip count',
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ));

        $this->add_control('show_description', array(
            'label'       => 'Show description',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => '',
            'description' => 'A trimmed term description, falling back to the newest trip in the term.',
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_media_style_section(
            'dest', 'Image', '.wta-dest-link', '.wta-dest-img'
        );

        $this->wta_box_style_section(
            'dest', 'Tile', '.wta-dest-tile', array('grid' => '.wta-dest-grid')
        );

        $this->wta_text_style_section('dest', 'Tile text', array(
            'name'  => array('label' => 'Name',  'selector' => '.wta-dest-name', 'spacing' => true),
            'desc'  => array('label' => 'Description', 'selector' => '.wta-dest-desc'),
            'count' => array('label' => 'Trip count',  'selector' => '.wta-dest-count'),
        ));

    }

    /**
     * A term's image URL.
     *
     * The term-media module owns this, including the borrowed-from-a-trip
     * fallback that keeps a half-filled taxonomy from rendering as a row of
     * empty boxes. That module is optional, so when it is switched off this
     * reads WP Travel's own key directly rather than going fatal.
     *
     * @return string URL, or '' when there is no image.
     */
    protected function term_image_url($term_id, $size = 'large') {
        if (class_exists('WTA_Term_Media')) {
            return WTA_Term_Media::get_image_url($term_id, $size);
        }

        $attachment_id = (int) get_term_meta($term_id, 'wp_travel_trip_type_image_id', true);

        if ($attachment_id <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($attachment_id, $size);

        return $url ? $url : '';
    }

    /**
     * Card-safe description text, with the same optional-module handling.
     */
    protected function term_summary($term_id, $max_words = 18) {
        if (class_exists('WTA_Term_Media')) {
            return WTA_Term_Media::get_description($term_id, true, $max_words);
        }

        $text = wp_strip_all_tags(strip_shortcodes(term_description($term_id)), true);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return '' === $text ? '' : wp_trim_words($text, $max_words, '…');
    }

    /**
     * Trip count as a readable phrase.
     */
    protected function count_label($count) {
        $count = (int) $count;

        return 1 === $count ? '1 trip' : sprintf('%s trips', number_format_i18n($count));
    }

    /**
     * The terms to render, from a single query.
     *
     * hide_empty is deliberately left off in the query and applied afterwards.
     * WordPress filters on the raw count in SQL, which happens before
     * pad_counts adds the children's trips to a parent, so a region whose trips
     * all live on its countries would be dropped as empty — exactly the terms a
     * homepage most wants to show.
     *
     * Ordering is done here too: the padded count is not the count the database
     * sorted on, and "term order" has no column in core WordPress.
     *
     * @return WP_Term[]
     */
    protected function terms($settings) {
        $terms = get_terms(array(
            'taxonomy'   => $settings['taxonomy'],
            'hide_empty' => false,
            'pad_counts' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        if (is_wp_error($terms) || !$terms) {
            return array();
        }

        if ('yes' === $settings['top_level']) {
            $terms = array_filter($terms, function ($term) {
                return 0 === (int) $term->parent;
            });
        }

        if ('yes' === $settings['hide_empty']) {
            $terms = array_filter($terms, function ($term) {
                return (int) $term->count > 0;
            });
        }

        $terms = array_values($terms);

        if ('count' === $settings['orderby']) {
            usort($terms, function ($a, $b) {
                // Same count, same alphabet: otherwise the order of two equal
                // terms changes between page loads.
                return ((int) $b->count <=> (int) $a->count) ?: strcasecmp($a->name, $b->name);
            });
        } elseif ('term_order' === $settings['orderby']) {
            // Core has no manual term ordering, so the honest stable answer is
            // the order the terms were created in.
            usort($terms, function ($a, $b) {
                return (int) $a->term_id <=> (int) $b->term_id;
            });
        }

        return array_slice($terms, 0, max(1, min(24, (int) $settings['count'])));
    }

    /**
     * Layout-only CSS, kept in the widget because the shared reference
     * stylesheet is a fixed design artefact that this plugin does not edit.
     *
     * Everything is scoped under .wta-itin and written against the reference
     * file's custom properties, so a palette change there reaches these tiles
     * without a single value being restated. The clipping sits on the tile and
     * the transform on the image — the reverse does not clip at all, because
     * overflow has no effect on a replaced element.
     */
    protected function print_css() {
        if (self::$printed_css) {
            return;
        }

        self::$printed_css = true;

        echo '<style id="wta-destinations-css">'
            . '.wta-itin .wta-dest-grid{display:grid;gap:14px;margin:0}'
            . '.wta-itin .wta-dest-grid[data-cols="2"]{grid-template-columns:repeat(2,minmax(0,1fr))}'
            . '.wta-itin .wta-dest-grid[data-cols="3"]{grid-template-columns:repeat(3,minmax(0,1fr))}'
            . '.wta-itin .wta-dest-grid[data-cols="4"]{grid-template-columns:repeat(4,minmax(0,1fr))}'
            . '.wta-itin .wta-dest-grid[data-cols="5"]{grid-template-columns:repeat(5,minmax(0,1fr))}'
            . '@media (max-width:1024px){.wta-itin .wta-dest-grid[data-cols="3"],'
            . '.wta-itin .wta-dest-grid[data-cols="4"],'
            . '.wta-itin .wta-dest-grid[data-cols="5"]{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            . '@media (max-width:640px){.wta-itin .wta-dest-grid{grid-template-columns:minmax(0,1fr)}}'
            . '.wta-itin .wta-dest-tile{margin:0;min-width:0}'
            /* The tile owns the ratio and the overflow. */
            . '.wta-itin .wta-dest-link{aspect-ratio:var(--wta-dest-ratio,3/4);position:relative;display:block;overflow:hidden;border-radius:4px;'
            . 'background:var(--forest-deep);color:var(--bleach);text-decoration:none;line-height:0}'
            . '.wta-itin .wta-dest-grid[data-aspect="3-4"]{--wta-dest-ratio:3/4}'
            . '.wta-itin .wta-dest-grid[data-aspect="4-3"]{--wta-dest-ratio:4/3}'
            . '.wta-itin .wta-dest-grid[data-aspect="1-1"]{--wta-dest-ratio:1/1}'
            . '.wta-itin .wta-dest-grid[data-aspect="16-9"]{--wta-dest-ratio:16/9}'
            /* The image is what moves, so the crop stays inside the tile. */
            . '.wta-itin .wta-dest-img{width:100%;height:100%;object-fit:cover;transform:scale(1);transition:transform .5s ease}'
            . '.wta-itin .wta-dest-link:hover .wta-dest-img,'
            . '.wta-itin .wta-dest-link:focus-visible .wta-dest-img{transform:scale(1.06)}'
            . '.wta-itin .wta-dest-blank{position:absolute;inset:0;'
            . 'background:linear-gradient(150deg,var(--ocean) 0%,var(--forest) 100%)}'
            /* A photograph is not a background: without this the name sits on
               whatever happens to be in the lower third of the picture. */
            . '.wta-itin .wta-dest-veil{position:absolute;inset:0;pointer-events:none;'
            . 'background:linear-gradient(to top,var(--ocean) 0%,transparent 76%);opacity:.9}'
            . '.wta-itin .wta-dest-body{position:absolute;left:0;right:0;bottom:0;'
            . 'padding:16px 16px 18px;line-height:1.4;display:flex;flex-direction:column;gap:5px}'
            . '.wta-itin .wta-dest-name{font-family:var(--display);font-weight:700;'
            . 'font-size:clamp(18px,2.1vw,25px);letter-spacing:-.02em;line-height:1.05;margin:0;color:var(--bleach)}'
            . '.wta-itin .wta-dest-count{font-family:var(--mono);font-size:10px;letter-spacing:.14em;'
            . 'text-transform:uppercase;color:var(--dust)}'
            . '.wta-itin .wta-dest-desc{margin:0;font-size:13px;line-height:1.5;color:rgba(232,220,192,.78)}'
            . '.wta-itin a.wta-dest-link:focus-visible{outline:2px solid var(--dust);outline-offset:3px}'
            . '@media (prefers-reduced-motion:reduce){.wta-itin .wta-dest-img{transition:none}'
            . '.wta-itin .wta-dest-link:hover .wta-dest-img,'
            . '.wta-itin .wta-dest-link:focus-visible .wta-dest-img{transform:none}}'
            . '</style>';
    }

    protected function render() {
        $s        = $this->get_settings_for_display();
        $taxonomy = (string) $s['taxonomy'];

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">That taxonomy is not registered, so there are no destinations to show.</p></div>';

            return;
        }

        $terms = $this->terms($s);

        if (!$terms) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">No destinations match this selection yet.</p></div>';

            return;
        }

        $this->print_css();

        printf(
            '<div class="wta-itin"><div class="wta-dest-grid" data-cols="%s" data-aspect="%s">',
            esc_attr($s['columns']),
            esc_attr($s['aspect'])
        );

        foreach ($terms as $term) {
            $link = get_term_link($term);

            // A term whose archive cannot be resolved would render a tile that
            // looks clickable and is not.
            if (is_wp_error($link)) {
                continue;
            }

            $image = $this->term_image_url($term->term_id, $this->wta_image_size('dest', $s));
            $desc  = 'yes' === $s['show_description'] ? $this->term_summary($term->term_id) : '';

            echo '<article class="wta-dest-tile">';
            echo '<a class="wta-dest-link" href="' . esc_url($link) . '">';

            if ($image) {
                printf(
                    '<img class="wta-dest-img" src="%s" alt="%s" loading="lazy" decoding="async">',
                    esc_url($image),
                    esc_attr($term->name)
                );
            } else {
                echo '<span class="wta-dest-blank" aria-hidden="true"></span>';
            }

            echo '<span class="wta-dest-veil" aria-hidden="true"></span>';

            echo '<span class="wta-dest-body">';
            echo '<span class="wta-dest-name">' . esc_html($term->name) . '</span>';

            if ('yes' === $s['show_count']) {
                echo '<span class="wta-dest-count">' . esc_html($this->count_label($term->count)) . '</span>';
            }

            if ($desc) {
                echo '<span class="wta-dest-desc">' . esc_html($desc) . '</span>';
            }

            echo '</span>';
            echo '</a>';
            echo '</article>';
        }

        echo '</div></div>';
    }
}
