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

        $this->add_control('columns', array(
            'label'   => 'Columns',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => array('1' => '1', '2' => '2', '3' => '3', '4' => '4'),
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
            'default' => '4-3',
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

        $this->add_control('cta_request', array(
            'label'       => 'Button label when there is no price',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'Request price',
            'description' => 'A trip with no published price should ask for the enquiry rather than repeat the generic call to action.',
        ));

        $this->end_controls_section();
    }

    /**
     * Duration as a single readable phrase. WP Travel keeps days and nights
     * apart, and a trip may have set only one of them.
     */
    protected function duration_label($post_id) {
        $days   = (int) get_post_meta($post_id, 'wp_travel_trip_duration', true);
        $nights = (int) get_post_meta($post_id, 'wp_travel_trip_duration_night', true);

        if ($days && $nights) {
            return sprintf('%d days / %d nights', $days, $nights);
        }

        if ($days) {
            return sprintf('%d days', $days);
        }

        return $nights ? sprintf('%d nights', $nights) : '';
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

    protected function price_label($post_id) {
        if (!$this->has_price($post_id)) {
            return '';
        }

        $price = get_post_meta($post_id, 'wp_travel_trip_price', true);

        if (function_exists('wp_travel_get_formated_price_currency')) {
            return wp_travel_get_formated_price_currency($price);
        }

        return number_format_i18n((float) $price);
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
        if ('yes' === $settings['use_archive'] && is_tax()) {
            $queried = get_queried_object();

            if ($queried instanceof WP_Term) {
                $args['tax_query'] = array(array(
                    'taxonomy'         => $queried->taxonomy,
                    'field'            => 'term_id',
                    'terms'            => $queried->term_id,
                    // Region parents must include the countries beneath them.
                    'include_children' => true,
                ));

                $paged = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));

                if ($paged > 1) {
                    $args['paged']         = $paged;
                    $args['no_found_rows'] = false;
                }

                return $args;
            }
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

    protected function render() {
        if (!WTA_Trip::is_available()) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">WP Travel is not active, so there are no trips to show.</p></div>';
            return;
        }

        $s     = $this->get_settings_for_display();
        $query = new WP_Query($this->query_args($s));

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

        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();

            $duration    = 'yes' === $s['show_duration'] ? $this->duration_label($id) : '';
            $has_price   = $this->has_price($id);
            $price       = 'yes' === $s['show_price'] ? $this->price_label($id) : '';
            $destination = 'yes' === $s['show_destination'] ? $this->destination_label($id) : '';

            // No price is a different sales conversation, so the card asks for
            // the enquiry instead of repeating the generic call to action.
            $cta_label = (!$has_price && !empty($s['cta_request'])) ? $s['cta_request'] : $s['cta'];

            echo '<article class="wta-card">';
            echo '<a class="wta-card-link" href="' . esc_url(get_permalink($id)) . '">';

            echo '<div class="wta-card-media">';
            if (has_post_thumbnail($id)) {
                // The wrapper clips; the image fills it. Scaling happens here,
                // not on a permanently over-zoomed rest state.
                echo get_the_post_thumbnail($id, 'large', array(
                    'class'   => 'wta-card-img',
                    'loading' => 'lazy',
                    'alt'     => esc_attr(get_the_title($id)),
                ));
            } else {
                echo '<span class="wta-card-noimg" aria-hidden="true"></span>';
            }

            if ($destination) {
                echo '<span class="wta-card-place">' . esc_html($destination) . '</span>';
            }
            echo '</div>';

            echo '<div class="wta-card-body">';
            echo '<h3 class="wta-card-title">' . esc_html(get_the_title($id)) . '</h3>';

            if ('yes' === $s['show_excerpt']) {
                $excerpt = get_the_excerpt($id);
                if ($excerpt) {
                    echo '<p class="wta-card-excerpt">' . esc_html(wp_trim_words($excerpt, 22)) . '</p>';
                }
            }

            $show_request = 'yes' === $s['show_price'] && !$has_price && !empty($s['price_request_label']);

            if ($duration || $price || $show_request) {
                echo '<div class="wta-card-meta">';
                if ($duration) {
                    echo '<span class="wta-card-dur">' . esc_html($duration) . '</span>';
                }
                if ($price) {
                    echo '<span class="wta-card-price"><small>from</small> ' . wp_kses_post($price) . '</span>';
                } elseif ($show_request) {
                    echo '<span class="wta-card-price is-request">' . esc_html($s['price_request_label']) . '</span>';
                }
                echo '</div>';
            }

            if ($cta_label) {
                printf(
                    '<span class="wta-card-cta%s">%s</span>',
                    $has_price ? '' : ' is-request',
                    esc_html($cta_label)
                );
            }

            echo '</div>';
            echo '</a>';
            echo '</article>';
        }

        echo '</div></div>';

        wp_reset_postdata();
    }
}
