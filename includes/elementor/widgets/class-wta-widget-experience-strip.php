<?php
/**
 * Experience strip.
 *
 * An Experiences page is a list of activity terms, and a wrapping grid of
 * twelve of them reads as a sitemap. A strip reads as an invitation: a row of
 * editorial cards that runs off the edge of the page and asks to be pushed.
 *
 * The scrolling is CSS only. A carousel script would be a second lightbox-shaped
 * dependency for something scroll-snap already does, and it would take the
 * keyboard and the trackpad away from the browser, which are the two ways people
 * actually move a row like this.
 *
 * Terms come from one get_terms() call. The strip is the top of an Experiences
 * page and a card each for eight activities is eight queries this way and one
 * the other.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Experience_Strip extends \Elementor\Widget_Base {

    /**
     * Whether the strip CSS has already gone out this request.
     *
     * The rules are identical for every instance, so an Experiences page with a
     * strip per continent would otherwise ship the same block five times.
     *
     * @var bool
     */
    protected static $printed_css = false;

    public function get_name() {
        return 'wta-experiences';
    }

    public function get_title() {
        return 'Experience Strip';
    }

    public function get_icon() {
        return 'eicon-slider-push';
    }

    public function get_categories() {
        return array(defined('WTA_Elementor::CATEGORY') ? WTA_Elementor::CATEGORY : 'nyuchi-travel');
    }

    public function get_style_depends() {
        return array('wta-itinerary');
    }

    protected function register_controls() {
        $this->start_controls_section('content', array(
            'label' => 'Experiences',
        ));

        $this->add_control('heading', array(
            'label'   => 'Section heading',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Ways to travel',
        ));

        $this->add_control('standfirst', array(
            'label'   => 'Standfirst',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'rows'    => 3,
            'default' => 'Pick the experience first. The route follows from it.',
        ));

        $this->add_control('taxonomy', array(
            'label'   => 'Taxonomy',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'activity',
            'options' => WTA_Trip::default_taxonomies(),
        ));

        $this->add_control('count', array(
            'label'   => 'How many',
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 24,
            'default' => 8,
        ));

        $this->add_control('layout', array(
            'label'       => 'Layout',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'strip',
            'options'     => array(
                'strip' => 'Strip (scrolls sideways)',
                'grid'  => 'Grid (wraps)',
            ),
            'description' => 'A strip suits a row inside a longer page; a grid suits the whole page being the list.',
        ));

        $this->add_control('columns', array(
            'label'     => 'Columns',
            'type'      => \Elementor\Controls_Manager::SELECT,
            'default'   => '4',
            'options'   => array('2' => '2', '3' => '3', '4' => '4'),
            'condition' => array('layout' => 'grid'),
        ));

        $this->add_control('scope_to_archive', array(
            'label'       => 'Scope to the current destination',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'On a destination page, show only the activities its trips actually offer.',
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
            'default'     => 'yes',
            'description' => 'A trimmed term description, falling back to the newest trip in the term.',
        ));

        $this->end_controls_section();
    }

    /**
     * A term's image URL.
     *
     * The term-media module owns this, including the borrowed-from-a-trip
     * fallback. That module is optional, so when it is switched off this reads
     * WP Travel's own key directly rather than going fatal.
     *
     * @return string URL, or '' when there is no image.
     */
    protected function term_image_url($term_id, $size = 'medium_large') {
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

    protected function count_label($count) {
        $count = (int) $count;

        return 1 === $count ? '1 trip' : sprintf('%s trips', number_format_i18n($count));
    }

    /**
     * The terms to render, from a single query.
     *
     * hide_empty is applied here rather than in the query for the same reason as
     * the destination grid: WordPress filters on the raw count in SQL, before
     * pad_counts folds the children's trips into a parent, so a broad activity
     * whose trips all sit on its sub-activities would be dropped as empty.
     *
     * @return WP_Term[]
     */
    protected function terms($settings) {
        $args = array(
            'taxonomy'   => $settings['taxonomy'],
            'hide_empty' => false,
            'pad_counts' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        );

        // On a destination archive, "things to do" means things you can do
        // THERE. Listing the global activity set on every destination page is
        // both wrong and useless — Botswana does not offer gorilla trekking.
        // So restrict to the activities actually attached to trips in the
        // destination being viewed.
        if ('yes' === $settings['scope_to_archive'] && is_tax()) {
            $queried = get_queried_object();

            if ($queried instanceof WP_Term && $queried->taxonomy !== $settings['taxonomy']) {
                $ids = $this->terms_present_in($queried, $settings['taxonomy']);

                if (!$ids) {
                    return array();
                }

                $args['include'] = $ids;
            }
        }

        $terms = get_terms($args);

        if (is_wp_error($terms) || !$terms) {
            return array();
        }

        if ('yes' === $settings['hide_empty']) {
            $terms = array_filter($terms, function ($term) {
                return (int) $term->count > 0;
            });
        }

        return array_slice(array_values($terms), 0, max(1, min(24, (int) $settings['count'])));
    }

    /**
     * Which terms of $taxonomy appear on trips filed under $context_term.
     *
     * One query for the trip ids, then one for their terms. Cached per request
     * because a page may hold more than one instance of this widget.
     *
     * @return int[]
     */
    protected function terms_present_in($context_term, $taxonomy) {
        static $cache = array();

        $key = $context_term->term_id . ':' . $taxonomy;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $cache[$key] = array();

        if (!class_exists('WTA_Trip') || !WTA_Trip::is_available()) {
            return $cache[$key];
        }

        $trip_ids = get_posts(array(
            'post_type'              => WTA_Trip::post_type(),
            'post_status'            => 'publish',
            'posts_per_page'         => 200,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => array(array(
                'taxonomy'         => $context_term->taxonomy,
                'field'            => 'term_id',
                'terms'            => $context_term->term_id,
                // A region archive covers its countries' trips too.
                'include_children' => true,
            )),
        ));

        if (!$trip_ids) {
            return $cache[$key];
        }

        $found = wp_get_object_terms($trip_ids, $taxonomy, array('fields' => 'ids'));

        if (!is_wp_error($found)) {
            $cache[$key] = array_values(array_unique(array_map('intval', $found)));
        }

        return $cache[$key];
    }

    /**
     * Layout-only CSS, kept in the widget because the shared reference
     * stylesheet is a fixed design artefact that this plugin does not edit.
     *
     * Everything is scoped under .wta-itin and written against the reference
     * file's custom properties. The scrollbar is hidden cosmetically only — the
     * row still scrolls with the wheel, the trackpad and, because it takes
     * focus, the arrow keys.
     */
    protected function print_css() {
        if (self::$printed_css) {
            return;
        }

        self::$printed_css = true;

        echo '<style id="wta-experiences-css">'
            . '.wta-itin .wta-exp-row{display:grid;gap:16px;margin:0}'
            /* Strip: one row of fixed-width cards that runs past the edge. */
            . '.wta-itin .wta-exp-row[data-layout="strip"]{grid-auto-flow:column;'
            . 'grid-auto-columns:minmax(230px,270px);overflow-x:auto;overscroll-behavior-x:contain;'
            . 'scroll-snap-type:x mandatory;scroll-padding-left:2px;padding-bottom:4px;'
            . 'scrollbar-width:none;-ms-overflow-style:none}'
            . '.wta-itin .wta-exp-row[data-layout="strip"]::-webkit-scrollbar{width:0;height:0}'
            . '.wta-itin .wta-exp-row[data-layout="strip"]>.wta-exp-item{scroll-snap-align:start}'
            /* The row is a focus stop, so it must show that it has focus. */
            . '.wta-itin .wta-exp-row[data-layout="strip"]:focus-visible{outline:2px solid var(--dust);'
            . 'outline-offset:4px;border-radius:2px}'
            . '.wta-itin .wta-exp-row[data-layout="grid"][data-cols="2"]{grid-template-columns:repeat(2,minmax(0,1fr))}'
            . '.wta-itin .wta-exp-row[data-layout="grid"][data-cols="3"]{grid-template-columns:repeat(3,minmax(0,1fr))}'
            . '.wta-itin .wta-exp-row[data-layout="grid"][data-cols="4"]{grid-template-columns:repeat(4,minmax(0,1fr))}'
            . '@media (max-width:1024px){.wta-itin .wta-exp-row[data-layout="grid"][data-cols="3"],'
            . '.wta-itin .wta-exp-row[data-layout="grid"][data-cols="4"]{grid-template-columns:repeat(2,minmax(0,1fr))}'
            . '.wta-itin .wta-exp-row[data-layout="strip"]{grid-auto-columns:minmax(215px,250px)}}'
            . '@media (max-width:640px){.wta-itin .wta-exp-row[data-layout="grid"]{grid-template-columns:minmax(0,1fr)}'
            . '.wta-itin .wta-exp-row[data-layout="strip"]{grid-auto-columns:minmax(76%,80%)}}'
            . '.wta-itin .wta-exp-item{margin:0;min-width:0}'
            . '.wta-itin .wta-exp-link{display:flex;flex-direction:column;gap:10px;height:100%;'
            . 'color:inherit;text-decoration:none}'
            /* The frame owns the ratio and the overflow. */
            . '.wta-itin .wta-exp-media{position:relative;display:block;overflow:hidden;border-radius:4px;'
            . 'aspect-ratio:4/3;background:var(--forest-deep);line-height:0}'
            /* The image is what moves, so the crop stays inside the frame. */
            . '.wta-itin .wta-exp-img{width:100%;height:100%;object-fit:cover;transform:scale(1);transition:transform .5s ease}'
            . '.wta-itin .wta-exp-link:hover .wta-exp-img,'
            . '.wta-itin .wta-exp-link:focus-visible .wta-exp-img{transform:scale(1.06)}'
            . '.wta-itin .wta-exp-blank{position:absolute;inset:0;'
            . 'background:linear-gradient(150deg,var(--ocean) 0%,var(--forest) 100%)}'
            . '.wta-itin .wta-exp-body{display:flex;flex-direction:column;gap:5px;line-height:1.4}'
            . '.wta-itin .wta-exp-name{font-family:var(--display);font-weight:700;font-size:19px;'
            . 'letter-spacing:-.02em;line-height:1.1;margin:0;color:var(--ink)}'
            . '.wta-itin .wta-exp-link:hover .wta-exp-name{color:var(--ocean)}'
            . '.wta-itin .wta-exp-count{font-family:var(--mono);font-size:10px;letter-spacing:.14em;'
            . 'text-transform:uppercase;color:var(--dust-deep)}'
            . '.wta-itin .wta-exp-desc{margin:0;font-size:13.5px;line-height:1.55;color:var(--ink-soft)}'
            . '.wta-itin a.wta-exp-link:focus-visible{outline:2px solid var(--dust);outline-offset:3px}'
            . '@media (prefers-reduced-motion:reduce){.wta-itin .wta-exp-img{transition:none}'
            . '.wta-itin .wta-exp-row[data-layout="strip"]{scroll-behavior:auto}'
            . '.wta-itin .wta-exp-link:hover .wta-exp-img,'
            . '.wta-itin .wta-exp-link:focus-visible .wta-exp-img{transform:none}}'
            . '</style>';
    }

    protected function render() {
        $s        = $this->get_settings_for_display();
        $taxonomy = (string) $s['taxonomy'];

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">That taxonomy is not registered, so there are no experiences to show.</p></div>';

            return;
        }

        $terms = $this->terms($s);

        if (!$terms) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">No experiences match this selection yet.</p></div>';

            return;
        }

        $this->print_css();

        $strip = 'strip' === $s['layout'];
        $label = !empty($s['heading']) ? $s['heading'] : 'Experiences';

        echo '<div class="wta-itin">';
        echo '<section class="wta-experiences wta-section"><div class="wta-wrap">';

        if (!empty($s['heading']) || !empty($s['standfirst'])) {
            echo '<div class="wta-sec-head">';

            if (!empty($s['heading'])) {
                echo '<h2>' . esc_html($s['heading']) . '</h2>';
            }

            if (!empty($s['standfirst'])) {
                echo '<p>' . esc_html($s['standfirst']) . '</p>';
            }

            echo '</div>';
        }

        // A scrolling row that cannot be reached by keyboard is content behind a
        // mouse-only door, so the strip is a labelled, focusable region. The
        // grid is not: nothing there scrolls, and an empty focus stop is worse
        // than none.
        printf(
            '<div class="wta-exp-row" data-layout="%s" data-cols="%s"%s>',
            esc_attr($strip ? 'strip' : 'grid'),
            esc_attr($s['columns']),
            $strip
                ? ' tabindex="0" role="region" aria-label="' . esc_attr($label . ', scroll sideways for more') . '"'
                : ''
        );

        foreach ($terms as $term) {
            $link = get_term_link($term);

            // A term whose archive cannot be resolved would render a card that
            // looks clickable and is not.
            if (is_wp_error($link)) {
                continue;
            }

            $image = $this->term_image_url($term->term_id);
            $desc  = 'yes' === $s['show_description'] ? $this->term_summary($term->term_id) : '';

            echo '<article class="wta-exp-item">';
            echo '<a class="wta-exp-link" href="' . esc_url($link) . '">';

            echo '<span class="wta-exp-media">';
            if ($image) {
                printf(
                    '<img class="wta-exp-img" src="%s" alt="%s" loading="lazy" decoding="async">',
                    esc_url($image),
                    esc_attr($term->name)
                );
            } else {
                echo '<span class="wta-exp-blank" aria-hidden="true"></span>';
            }
            echo '</span>';

            echo '<span class="wta-exp-body">';
            echo '<span class="wta-exp-name">' . esc_html($term->name) . '</span>';

            if ('yes' === $s['show_count']) {
                echo '<span class="wta-exp-count">' . esc_html($this->count_label($term->count)) . '</span>';
            }

            if ($desc) {
                echo '<span class="wta-exp-desc">' . esc_html($desc) . '</span>';
            }

            echo '</span>';
            echo '</a>';
            echo '</article>';
        }

        echo '</div>';
        echo '</div></section></div>';
    }
}
