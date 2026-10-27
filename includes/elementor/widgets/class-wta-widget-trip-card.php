<?php
/**
 * Trip cards.
 *
 * Replaces the practice of styling Elementor's Portfolio widget into a trip
 * card with custom CSS. That approach has two structural problems: the overflow
 * that clips a scaled thumbnail has to sit on the container rather than the
 * image, and holding a rest-state scale of 1.2 permanently over-crops every
 * thumbnail so the framing is never what the editor chose.
 *
 * Here the media wrapper owns the overflow and a fixed aspect ratio, the image
 * rests at its natural framing, and the zoom happens on hover. Trip facts come
 * from WP Travel rather than from a portfolio taxonomy, so the card can show
 * duration, price and destination without any of it being hand-written.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Trip_Card extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    public function get_name() {
        return 'wta-trip-cards';
    }

    public function get_title() {
        return 'Trip Cards';
    }

    public function get_icon() {
        return 'eicon-posts-grid';
    }

    public function get_categories() {
        return array(defined('WTA_Elementor::CATEGORY') ? WTA_Elementor::CATEGORY : 'nyuchi-travel');
    }

    public function get_style_depends() {
        return array('wta-itinerary');
    }

    protected function register_controls() {
        $this->start_controls_section('query', array(
            'label' => 'Trips',
        ));

        $this->add_control('count', array(
            'label'   => 'How many',
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 48,
            'default' => 6,
        ));

        $this->add_responsive_control('columns', array(
            'label'   => 'Columns',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => array('1' => '1', '2' => '2', '3' => '3', '4' => '4'),
            'tablet_default' => '2',
            'mobile_default' => '1',
            'description'    => 'Set per device. A four-column grid that stays four columns on a phone gives each card about eighty pixels, so the tablet and mobile counts are the ones that matter most.',
            'selectors'      => array(
                '{{WRAPPER}} .wta-cards-grid' => '--wta-cols: {{VALUE}};',
            ),
        ));

        $this->add_control('orderby', array(
            'label'   => 'Order by',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'date',
            'options' => array(
                'date'       => 'Newest first',
                'title'      => 'Title',
                'menu_order' => 'Manual order',
                'rand'       => 'Random',
            ),
        ));

        $this->add_control('taxonomy', array(
            'label'       => 'Filter by taxonomy',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => '',
            'options'     => array_merge(array('' => 'No filter'), WTA_Trip::default_taxonomies()),
            'description' => 'Leave unfiltered to show all trips.',
        ));

        $this->add_control('terms', array(
            'label'       => 'Term slugs',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => '',
            'placeholder' => 'tanzania, kenya',
            'description' => 'Comma separated. Only applies when a taxonomy is chosen.',
            'condition'   => array('taxonomy!' => ''),
        ));

        $this->add_control('filter_taxonomy', array(
            'label'       => 'Also require a term in',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => '',
            'options'     => self::trip_taxonomy_options(),
            'description' => 'Combined with the archive scope, so a destination page can show only its featured trips.',
        ));

        $this->add_control('filter_terms', array(
            'label'       => 'Term slugs (optional)',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => '',
            'placeholder' => 'leave blank for any term',
            'description' => 'Blank means "has any term in that taxonomy" — which is what "featured" usually means.',
            'condition'   => array('filter_taxonomy!' => ''),
        ));

        $this->add_control('filter_fallback', array(
            'label'       => 'Fall back when the filter is empty',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'If no trip matches the extra filter here, show the unfiltered set instead of an empty section.',
            'condition'   => array('filter_taxonomy!' => ''),
        ));

        $this->add_control('use_archive', array(
            'label'       => 'Follow the archive',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'On a destination, activity or trip-type archive, show only that term\'s trips and honour paging. Turn off to query independently.',
        ));

        $this->add_control('current_terms', array(
            'label'        => 'Match the current trip instead',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => '',
            'description'  => 'On a single trip, show other trips sharing its terms. Turns this into a related-trips block.',
            'condition'    => array('taxonomy!' => ''),
        ));

        $this->end_controls_section();

        $this->start_controls_section('layout', array(
            'label' => 'Card',
        ));

        $this->add_control('style', array(
            'label'   => 'Style',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'overlay',
            'options' => array(
                'overlay' => 'Text over image',
                'stacked' => 'Text below image',
            ),
        ));

        $this->add_control('ratio', array(
            'label'   => 'Image shape',
            'type'    => \Elementor\Controls_Manager::SELECT,
            // Portrait by default: trip cards read better tall, and it matches
            // the gallery, so a page mixing the two stays on one rhythm.
            'default' => '3-4',
            'options' => array(
                '1-1'  => 'Square',
                '4-3'  => 'Landscape',
                '3-4'  => 'Portrait',
                '16-9' => 'Wide',
            ),
        ));

        foreach (array(
            'show_duration'    => 'Show duration',
            'show_price'       => 'Show price',
            'show_destination' => 'Show destination',
            'show_excerpt'     => 'Show summary',
        ) as $key => $label) {
            $this->add_control($key, array(
                'label'   => $label,
                'type'    => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'excerpt' === substr($key, 5) ? '' : 'yes',
            ));
        }

        $this->add_control('cta', array(
            'label'   => 'Button label',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'View itinerary',
        ));

        $this->add_control('price_request_label', array(
            'label'       => 'Label when there is no price',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'Price on request',
            'description' => 'Shown in place of the price when a trip has no price, or a price of zero.',
            'condition'   => array('show_price' => 'yes'),
        ));

        $this->add_control('price_suffix', array(
            'label'       => 'Qualifier after the price',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'per person sharing',
            'description' => 'Small text shown under "From $X". Leave empty to hide it.',
            'condition'   => array('show_price' => 'yes'),
        ));

        $this->add_control('cta_request', array(
            'label'       => 'Button label when there is no price',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'Request price',
            'description' => 'A trip with no published price should ask for the enquiry rather than repeat the generic call to action.',
        ));

        $this->add_control('footnote', array(
            'label'       => 'Footnote',
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'default'     => '',
            'rows'        => 3,
            'description' => 'Shown once under the cards, and only when at least one card shows a price. Use it for what the price includes or excludes.',
            'condition'   => array('show_price' => 'yes'),
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_media_style_section(
            'card', 'Image', '.wta-card-media', '.wta-card-img', array('hover' => '.wta-card')
        );

        $this->wta_box_style_section(
            'card', 'Card', '.wta-card', array('grid' => '.wta-cards-grid')
        );

        $this->wta_text_style_section('card', 'Card text', array(
            'title'   => array('label' => 'Title',   'selector' => '.wta-card-title',   'spacing' => true),
            'meta'    => array('label' => 'Meta',    'selector' => '.wta-card-meta'),
            'excerpt' => array('label' => 'Excerpt', 'selector' => '.wta-card-excerpt', 'spacing' => true),
            'price'   => array('label' => 'Price',   'selector' => '.wta-card-price'),
        ));

    }

    /**
     * Taxonomies registered against the trip post type.
     *
     * Deliberately not limited to the REST-exposed four: "featured" is
     * registered without show_in_rest, and it is exactly the taxonomy an
     * editor wants here.
     */
    protected static function trip_taxonomy_options() {
        $options = array('' => 'No extra filter');

        if (!class_exists('WTA_Trip') || !WTA_Trip::is_available()) {
            return $options;
        }

        foreach (get_object_taxonomies(WTA_Trip::post_type(), 'objects') as $slug => $tax) {
            $options[$slug] = $tax->labels->singular_name . ' (' . $slug . ')';
        }

        return $options;
    }

    /**
     * Duration as a single readable phrase. WP Travel keeps days and nights
     * apart, and a trip may have set only one of them, so each combination
     * reads on its own: "10 days", "3 nights" or "4 days / 3 nights".
     */
    protected function duration_label($post_id) {
        $days   = max(0, (int) get_post_meta($post_id, 'wp_travel_trip_duration', true));
        $nights = max(0, (int) get_post_meta($post_id, 'wp_travel_trip_duration_night', true));

        $day_text   = $days ? sprintf(_n('%d day', '%d days', $days, 'wp-travel-addons'), $days) : '';
        $night_text = $nights ? sprintf(_n('%d night', '%d nights', $nights, 'wp-travel-addons'), $nights) : '';

        if ($day_text && $night_text) {
            return $day_text . ' / ' . $night_text;
        }

        return $day_text . $night_text;
    }

    /**
     * Whether this trip has a real, publishable price.
     *
     * A stored zero means "not costed yet", not "free". Rendering "$0" beside a
     * safari is worse than rendering nothing, so callers swap in a request
     * prompt instead. On catalogues where most trips are quoted rather than
     * listed, this is the common path rather than the exception.
     */
    protected function has_price($post_id) {
        $price = get_post_meta($post_id, 'wp_travel_trip_price', true);

        return '' !== $price && null !== $price && (float) $price > 0;
    }

    /**
     * The trip's starting-from price as formatted text, or '' when it has none.
     *
     * Uses WP Travel's own formatter so the symbol, position and separators
     * match the rest of the site. Newer WP Travel releases renamed the function
     * from wp_travel_* to wptravel_*, so both are tried; if neither exists the
     * number is formatted here.
     */
    protected function price_label($post_id) {
        if (!$this->has_price($post_id)) {
            return '';
        }

        $price = get_post_meta($post_id, 'wp_travel_trip_price', true);

        foreach (array('wptravel_get_formated_price_currency', 'wp_travel_get_formated_price_currency') as $formatter) {
            if (function_exists($formatter)) {
                $formatted = $formatter($price);

                if (is_string($formatted) && '' !== trim(wp_strip_all_tags($formatted))) {
                    return $formatted;
                }
            }
        }

        $symbol = '$';

        foreach (array('wptravel_get_currency_symbol', 'wp_travel_get_currency_symbol') as $getter) {
            if (function_exists($getter)) {
                $found = $getter();

                if (is_string($found) && '' !== $found) {
                    $symbol = $found;
                    break;
                }
            }
        }

        /**
         * Currency symbol used when WP Travel's formatter is unavailable.
         *
         * @param string $symbol  Symbol, "$" unless WP Travel supplies one.
         * @param int    $post_id Trip ID.
         */
        $symbol = (string) apply_filters('wta_trip_card_currency_symbol', $symbol, $post_id);

        return esc_html($symbol) . number_format_i18n((float) $price);
    }

    /**
     * "From $X" plus the small qualifier. The caller has already established
     * that the trip has a price.
     */
    protected function price_markup($price, $suffix) {
        $html  = '<span class="wta-card-price">';
        $html .= '<small class="wta-card-from">' . esc_html__('From', 'wp-travel-addons') . '</small> ';
        $html .= '<span class="wta-card-amount">' . wp_kses_post($price) . '</span>';

        if ('' !== $suffix) {
            $html .= ' <small class="wta-card-suffix">' . esc_html($suffix) . '</small>';
        }

        return $html . '</span>';
    }

    /**
     * The card link's accessible name: title, then price, then duration.
     *
     * The link wraps the whole card, so without a name a screen reader reads
     * every fragment inside it in DOM order. One sentence is easier to scan.
     *
     * @param string $title    Trip title.
     * @param string $price    Price as shown, qualifier included ("" if none).
     * @param string $duration Duration as shown ("" if none).
     */
    protected function card_name($title, $price, $duration) {
        $parts = array();

        foreach (array($title, $price, $duration) as $part) {
            $part = trim(html_entity_decode(wp_strip_all_tags((string) $part), ENT_QUOTES, 'UTF-8'));

            if ('' !== $part) {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * One card. Returns the markup and whether it displays a price, which
     * decides if the footnote is printed.
     *
     * @return array{html:string,priced:bool}
     */
    protected function card_html($id, $s) {
        $has_price   = $this->has_price($id);
        $show_price  = 'yes' === $s['show_price'];
        $suffix      = isset($s['price_suffix']) ? trim((string) $s['price_suffix']) : 'per person sharing';
        $duration    = 'yes' === $s['show_duration'] ? $this->duration_label($id) : '';
        $price       = ($show_price && $has_price) ? $this->price_label($id) : '';
        $destination = 'yes' === $s['show_destination'] ? $this->destination_label($id) : '';
        $request     = ($show_price && !$has_price && !empty($s['price_request_label'])) ? $s['price_request_label'] : '';
        $title       = get_the_title($id);

        // No price is a different sales conversation, so the card asks for
        // the enquiry instead of repeating the generic call to action.
        $cta_label = (!$has_price && !empty($s['cta_request'])) ? $s['cta_request'] : $s['cta'];

        $priced = '' !== $price;
        $name   = $this->card_name(
            $title,
            $priced
                ? esc_html__('From', 'wp-travel-addons') . ' ' . $price . ('' !== $suffix ? ' ' . $suffix : '')
                : $request,
            $duration
        );

        $html  = '<article class="wta-card">';
        $html .= '<a class="wta-card-link" href="' . esc_url(get_permalink($id)) . '" aria-label="' . esc_attr($name) . '">';

        $html .= '<div class="wta-card-media">';
        if (has_post_thumbnail($id)) {
            // The wrapper clips; the image fills it. Scaling happens here,
            // not on a permanently over-zoomed rest state. The link's name
            // already carries the title, so the image is decorative.
            $html .= get_the_post_thumbnail($id, $this->wta_image_size('card', $s), array(
                'class'   => 'wta-card-img',
                'loading' => 'lazy',
                'alt'     => '',
            ));
        } else {
            $html .= '<span class="wta-card-noimg" aria-hidden="true"></span>';
        }

        if ($destination) {
            $html .= '<span class="wta-card-place">' . esc_html($destination) . '</span>';
        }
        $html .= '</div>';

        $html .= '<div class="wta-card-body">';
        $html .= '<h3 class="wta-card-title">' . esc_html($title) . '</h3>';

        if ('yes' === $s['show_excerpt']) {
            $excerpt = get_the_excerpt($id);
            if ($excerpt) {
                $html .= '<p class="wta-card-excerpt">' . esc_html(wp_trim_words($excerpt, 22)) . '</p>';
            }
        }

        // The row only exists when it has something in it.
        if ($duration || $priced || $request) {
            $html .= '<div class="wta-card-meta">';
            if ($duration) {
                $html .= '<span class="wta-card-dur">' . esc_html($duration) . '</span>';
            }
            if ($priced) {
                $html .= $this->price_markup($price, $suffix);
            } elseif ($request) {
                $html .= '<span class="wta-card-price is-request">' . esc_html($request) . '</span>';
            }
            $html .= '</div>';
        }

        if ($cta_label) {
            $html .= sprintf(
                '<span class="wta-card-cta%s">%s</span>',
                $has_price ? '' : ' is-request',
                esc_html($cta_label)
            );
        }

        $html .= '</div></a></article>';

        return array('html' => $html, 'priced' => $priced);
    }

    /**
     * The footnote under the grid, or '' when there is none to show.
     *
     * A footnote qualifies a price, so it is dropped when no card on the page
     * displays one.
     */
    protected function footnote_html($s, $any_priced) {
        $text = isset($s['footnote']) ? trim((string) $s['footnote']) : '';

        if (!$any_priced || '' === $text) {
            return '';
        }

        return '<p class="wta-cards-footnote">' . nl2br(esc_html($text)) . '</p>';
    }

    protected function destination_label($post_id) {
        $terms = get_the_terms($post_id, 'travel_locations');

        if (!$terms || is_wp_error($terms)) {
            return '';
        }

        // Deepest term first: "Serengeti National Park" tells the reader more
        // than "Tanzania" when both are attached.
        usort($terms, function ($a, $b) {
            return $b->parent <=> $a->parent;
        });

        return $terms[0]->name;
    }

    protected function query_args($settings) {
        $args = array(
            'post_type'           => WTA_Trip::post_type(),
            'post_status'         => 'publish',
            'posts_per_page'      => max(1, (int) $settings['count']),
            'orderby'             => $settings['orderby'],
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        );

        if ('menu_order' === $settings['orderby']) {
            $args['order'] = 'ASC';
        }

        // On a term archive the widget must describe THAT term, otherwise
        // dropping it into an archive template silently lists the whole
        // catalogue on every destination page.
        if ('yes' === $settings['use_archive'] && (is_tax() || is_post_type_archive(WTA_Trip::post_type()) || is_search())) {
            // Inherit the page's own query first. WP Travel's filter widgets
            // (duration, price, activity and so on) work by modifying the main
            // query, so copying its clauses is what makes those filters apply
            // to these cards. Rebuilding the query by hand would silently
            // ignore every filter the visitor set.
            $inherited = $this->inherited_query_args();

            if ($inherited) {
                $args = array_merge($args, $inherited);
            }

            $queried = get_queried_object();

            if ($queried instanceof WP_Term && empty($args['tax_query'])) {
                $args['tax_query'] = array(array(
                    'taxonomy'         => $queried->taxonomy,
                    'field'            => 'term_id',
                    'terms'            => $queried->term_id,
                    // Region parents must include the countries beneath them.
                    'include_children' => true,
                ));
            }

            if ($queried instanceof WP_Term) {

                $extra = $this->extra_tax_clause($settings);

                if ($extra) {
                    // AND, not OR: "featured trips in Tanzania" means both.
                    $args['tax_query'] = array(
                        'relation' => 'AND',
                        $args['tax_query'][0],
                        $extra,
                    );
                }

                $paged = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));

                if ($paged > 1) {
                    $args['paged']         = $paged;
                    $args['no_found_rows'] = false;
                }

                return $args;
            }
        }

        $extra = $this->extra_tax_clause($settings);

        if ($extra && empty($settings['taxonomy'])) {
            $args['tax_query'] = array($extra);

            return $args;
        }

        $taxonomy = $settings['taxonomy'];

        if (!$taxonomy) {
            return $args;
        }

        $slugs = array();

        if ('yes' === $settings['current_terms'] && is_singular(WTA_Trip::post_type())) {
            $current = get_the_terms(get_the_ID(), $taxonomy);

            if ($current && !is_wp_error($current)) {
                $slugs = wp_list_pluck($current, 'slug');
            }

            $args['post__not_in'] = array(get_the_ID());
        } elseif ($settings['terms']) {
            $slugs = array_filter(array_map('trim', explode(',', $settings['terms'])));
        }

        if ($slugs) {
            $args['tax_query'] = array(array(
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $slugs,
            ));
        }

        return $args;
    }

    /**
     * Query clauses copied from the page's own main query.
     *
     * Deliberately a whitelist rather than the whole query_vars array: copying
     * everything would drag in pagination internals and post_type overrides
     * that fight the widget's own settings. These are the keys a filter UI
     * actually writes to.
     *
     * @return array
     */
    protected function inherited_query_args() {
        global $wp_query;

        if (!$wp_query instanceof WP_Query) {
            return array();
        }

        $out  = array();
        $vars = $wp_query->query_vars;

        foreach (array('tax_query', 'meta_query', 's', 'post__in', 'post__not_in', 'meta_key', 'author') as $key) {
            if (!empty($vars[$key])) {
                $out[$key] = $vars[$key];
            }
        }

        // Registered taxonomy query vars, which is how ?activity=ballooning and
        // friends arrive from a filter form.
        foreach (get_object_taxonomies(WTA_Trip::post_type()) as $taxonomy) {
            $tax_obj = get_taxonomy($taxonomy);

            if (!$tax_obj) {
                continue;
            }

            $qv = !empty($tax_obj->query_var) ? $tax_obj->query_var : $taxonomy;

            if (!empty($vars[$qv])) {
                $out[$qv] = $vars[$qv];
            }
        }

        return $out;
    }

    /**
     * The optional second tax_query clause.
     *
     * With no term slugs given the clause becomes EXISTS, which is how a
     * "featured" flag actually behaves: any term in that taxonomy counts.
     *
     * @return array|null
     */
    protected function extra_tax_clause($settings) {
        $taxonomy = isset($settings['filter_taxonomy']) ? $settings['filter_taxonomy'] : '';

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return null;
        }

        $slugs = array();

        if (!empty($settings['filter_terms'])) {
            $slugs = array_filter(array_map('trim', explode(',', $settings['filter_terms'])));
        }

        if ($slugs) {
            return array(
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $slugs,
            );
        }

        return array(
            'taxonomy' => $taxonomy,
            'operator' => 'EXISTS',
        );
    }

    protected function render() {
        if (!WTA_Trip::is_available()) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">WP Travel is not active, so there are no trips to show.</p></div>';
            return;
        }

        $s     = $this->get_settings_for_display();
        $args  = $this->query_args($s);
        $query = new WP_Query($args);

        // A curated flag such as "featured" is applied to a handful of trips,
        // so on most destinations the filtered set is empty. Rendering nothing
        // there reads as a broken section, so drop the extra clause and show
        // the destination's own trips rather than a void.
        if (!$query->have_posts()
            && 'yes' === $s['filter_fallback']
            && !empty($s['filter_taxonomy'])) {

            $relaxed = $args;

            if (isset($relaxed['tax_query']['relation'])) {
                unset($relaxed['tax_query']['relation'], $relaxed['tax_query'][1]);
                $relaxed['tax_query'] = array_values($relaxed['tax_query']);
            } else {
                unset($relaxed['tax_query']);
            }

            $query = new WP_Query($relaxed);
        }

        if (!$query->have_posts()) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">No trips match this selection.</p></div>';
            wp_reset_postdata();
            return;
        }

        printf(
            '<div class="wta-itin"><div class="wta-cards-grid" data-cols="%s" data-style="%s" data-ratio="%s">',
            esc_attr($s['columns']),
            esc_attr($s['style']),
            esc_attr($s['ratio'])
        );

        $any_priced = false;

        while ($query->have_posts()) {
            $query->the_post();

            $card = $this->card_html(get_the_ID(), $s);

            $any_priced = $any_priced || $card['priced'];

            echo $card['html']; // Escaped where built, in card_html().
        }

        echo '</div>';
        echo $this->footnote_html($s, $any_priced); // Escaped where built.
        echo '</div>';

        wp_reset_postdata();
    }
}
