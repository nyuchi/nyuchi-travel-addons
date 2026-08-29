<?php
/**
 * Activity cards.
 *
 * The Experience Strip presents activities as images and names. This presents
 * them as propositions: how long it takes, how hard it is, when to go, whether
 * a permit is involved. Those are the questions someone actually has about
 * ballooning over the Serengeti or trekking to a gorilla family, and none of
 * them are answerable from a taxonomy term alone — they come from the fields
 * WTA_Term_Fields adds.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Activity_Cards extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    /** @var bool CSS is identical for every instance, so print it once. */
    protected static $printed_css = false;

    public function get_name() {
        return 'wta-activity-cards';
    }

    public function get_title() {
        return 'Activity Cards';
    }

    public function get_icon() {
        return 'eicon-flip-box';
    }

    public function get_categories() {
        return array(defined('WTA_Elementor::CATEGORY') ? WTA_Elementor::CATEGORY : 'nyuchi-travel');
    }

    public function get_style_depends() {
        return array('wta-itinerary');
    }

    protected function register_controls() {
        $this->start_controls_section('content', array('label' => 'Activities'));

        $this->add_control('heading', array(
            'label'   => 'Heading',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Things to do',
        ));

        $this->add_control('standfirst', array(
            'label'   => 'Standfirst',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'default' => '',
        ));

        $this->add_control('count', array(
            'label'   => 'How many',
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 24,
            'default' => 6,
        ));

        $this->add_control('columns', array(
            'label'   => 'Columns',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => array('2' => '2', '3' => '3', '4' => '4'),
        ));

        $this->add_control('scope_to_archive', array(
            'label'       => 'Scope to the current destination',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'On a destination page, show only the activities its trips actually offer.',
        ));

        $this->add_control('hide_empty', array(
            'label'   => 'Hide activities with no trips',
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ));

        foreach (array(
            'show_duration'   => 'Show duration',
            'show_difficulty' => 'Show difficulty',
            'show_months'     => 'Show best months',
            'show_permit'     => 'Show permit notice',
            'show_count'      => 'Show trip count',
            'show_description'=> 'Show description',
        ) as $key => $label) {
            $this->add_control($key, array(
                'label'   => $label,
                'type'    => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'show_description' === $key ? '' : 'yes',
            ));
        }

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_media_style_section(
            'act', 'Image', '.wta-act-media', '.wta-act-img', array('hover' => '.wta-act')
        );

        $this->wta_box_style_section(
            'act', 'Card', '.wta-act', array('grid' => '.wta-acts-grid')
        );

        $this->wta_text_style_section('act', 'Card text', array(
            'name'  => array('label' => 'Name',        'selector' => '.wta-act-name', 'spacing' => true),
            'desc'  => array('label' => 'Description', 'selector' => '.wta-act-desc'),
            'facts' => array('label' => 'Facts',       'selector' => '.wta-act-fact'),
        ));

    }

    /**
     * Activity terms, optionally narrowed to the destination being viewed.
     *
     * @return WP_Term[]
     */
    protected function terms($settings) {
        $args = array(
            'taxonomy'   => 'activity',
            'hide_empty' => false,
            'pad_counts' => true,
            'orderby'    => 'count',
            'order'      => 'DESC',
        );

        if ('yes' === $settings['scope_to_archive'] && is_tax() && !is_tax('activity')) {
            $queried = get_queried_object();

            if ($queried instanceof WP_Term) {
                $ids = $this->activities_in($queried);

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
            $terms = array_filter($terms, function ($t) {
                return (int) $t->count > 0;
            });
        }

        return array_slice(array_values($terms), 0, max(1, (int) $settings['count']));
    }

    /**
     * Activity term ids present on trips filed under a destination term.
     *
     * @return int[]
     */
    protected function activities_in($context) {
        static $cache = array();

        if (isset($cache[$context->term_id])) {
            return $cache[$context->term_id];
        }

        $cache[$context->term_id] = array();

        if (!class_exists('WTA_Trip') || !WTA_Trip::is_available()) {
            return $cache[$context->term_id];
        }

        $trips = get_posts(array(
            'post_type'              => WTA_Trip::post_type(),
            'post_status'            => 'publish',
            'posts_per_page'         => 200,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => array(array(
                'taxonomy'         => $context->taxonomy,
                'field'            => 'term_id',
                'terms'            => $context->term_id,
                'include_children' => true,
            )),
        ));

        if (!$trips) {
            return $cache[$context->term_id];
        }

        $found = wp_get_object_terms($trips, 'activity', array('fields' => 'ids'));

        if (!is_wp_error($found)) {
            $cache[$context->term_id] = array_values(array_unique(array_map('intval', $found)));
        }

        return $cache[$context->term_id];
    }

    protected function field($term_id, $key) {
        if (!class_exists('WTA_Term_Fields')) {
            return '';
        }

        $value = WTA_Term_Fields::get($term_id, $key);

        return is_scalar($value) ? (string) $value : '';
    }

    protected function months($term_id) {
        if (!class_exists('WTA_Term_Fields') || !method_exists('WTA_Term_Fields', 'best_months_label')) {
            return '';
        }

        return (string) WTA_Term_Fields::best_months_label($term_id);
    }

    protected function image_url($term_id) {
        if (class_exists('WTA_Term_Media')) {
            return (string) WTA_Term_Media::get_image_url($term_id, 'large');
        }

        $id = (int) get_term_meta($term_id, 'wp_travel_trip_type_image_id', true);

        return $id ? (string) wp_get_attachment_image_url($id, 'large') : '';
    }

    protected function render() {
        $s     = $this->get_settings_for_display();
        $terms = $this->terms($s);

        $this->print_css();

        echo '<div class="wta-itin"><section class="wta-acts"><div class="wta-wrap">';

        if ($s['heading'] || $s['standfirst']) {
            echo '<div class="wta-sec-head">';
            if ($s['heading']) {
                echo '<h2>' . esc_html($s['heading']) . '</h2>';
            }
            if ($s['standfirst']) {
                echo '<p>' . esc_html($s['standfirst']) . '</p>';
            }
            echo '</div>';
        }

        if (!$terms) {
            echo '<p class="wta-eyebrow">No activities are recorded for this selection yet.</p>';
            echo '</div></section></div>';

            return;
        }

        printf('<div class="wta-acts-grid" data-cols="%s">', esc_attr($s['columns']));

        foreach ($terms as $term) {
            $link = get_term_link($term);

            if (is_wp_error($link)) {
                $link = '#';
            }

            $img        = $this->image_url($term->term_id);
            $duration   = 'yes' === $s['show_duration'] ? $this->field($term->term_id, 'duration') : '';
            $difficulty = 'yes' === $s['show_difficulty'] ? $this->field($term->term_id, 'difficulty') : '';
            $months     = 'yes' === $s['show_months'] ? $this->months($term->term_id) : '';
            $permit     = 'yes' === $s['show_permit'] && $this->field($term->term_id, 'permit_required');
            $min_age    = (int) $this->field($term->term_id, 'min_age');

            echo '<article class="wta-act">';
            echo '<a class="wta-act-link" href="' . esc_url($link) . '">';

            echo '<span class="wta-act-media">';
            if ($img) {
                printf('<span class="wta-act-img" style="background-image:url(%s)"></span>', esc_url($img));
            }
            echo '<span class="wta-act-veil"></span>';
            if ($permit) {
                echo '<span class="wta-act-flag">Permit required</span>';
            }
            echo '</span>';

            echo '<span class="wta-act-body">';
            echo '<span class="wta-act-name">' . esc_html($term->name) . '</span>';

            if ('yes' === $s['show_description']) {
                $desc = class_exists('WTA_Term_Media')
                    ? WTA_Term_Media::get_description($term->term_id, true, 20)
                    : '';

                if ($desc) {
                    echo '<span class="wta-act-desc">' . esc_html($desc) . '</span>';
                }
            }

            // The facts row is what separates this from a pretty tile. Each
            // item is omitted rather than shown blank, so a half-filled term
            // still reads as deliberate.
            $facts = array();

            if ($duration) {
                $facts[] = array('When', $duration);
            }
            if ($months) {
                $facts[] = array('Best', $months);
            }
            if ($difficulty) {
                $facts[] = array('Effort', ucfirst($difficulty));
            }
            if ($min_age > 0) {
                $facts[] = array('Minimum age', (string) $min_age);
            }

            if ($facts) {
                echo '<span class="wta-act-facts">';
                foreach ($facts as $fact) {
                    printf(
                        '<span class="wta-act-fact"><b>%s</b><span>%s</span></span>',
                        esc_html($fact[0]),
                        esc_html($fact[1])
                    );
                }
                echo '</span>';
            }

            if ('yes' === $s['show_count'] && $term->count) {
                printf(
                    '<span class="wta-act-count">%s</span>',
                    esc_html(sprintf(_n('%s trip', '%s trips', $term->count, 'wp-travel-addons'), number_format_i18n($term->count)))
                );
            }

            echo '</span></a></article>';
        }

        echo '</div></div></section></div>';
    }

    protected function print_css() {
        if (self::$printed_css) {
            return;
        }

        self::$printed_css = true;
        ?>
<style id="wta-activity-cards-css">
.wta-itin .wta-acts-grid{display:grid;gap:clamp(14px,2vw,20px)}
.wta-itin .wta-acts-grid[data-cols="2"]{grid-template-columns:repeat(2,minmax(0,1fr))}
.wta-itin .wta-acts-grid[data-cols="3"]{grid-template-columns:repeat(3,minmax(0,1fr))}
.wta-itin .wta-acts-grid[data-cols="4"]{grid-template-columns:repeat(4,minmax(0,1fr))}
@media (max-width:1024px){.wta-itin .wta-acts-grid[data-cols="3"],.wta-itin .wta-acts-grid[data-cols="4"]{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.wta-itin .wta-acts-grid{grid-template-columns:minmax(0,1fr)}}
.wta-itin .wta-act{min-width:0;border:1px solid var(--rule);border-radius:10px;overflow:hidden;background:#fff}
.wta-itin .wta-act-link{display:block;text-decoration:none;color:inherit;height:100%}
.wta-itin .wta-act-link:focus-visible{outline:2px solid var(--gold);outline-offset:2px}
.wta-itin .wta-act-media{position:relative;display:block;aspect-ratio:var(--wta-act-ratio,3/4);overflow:hidden;background:var(--navy)}
.wta-itin .wta-act-img{position:absolute;inset:0;background-size:cover;background-position:center;transform:scale(1);transition:transform .5s ease}
.wta-itin .wta-act:hover .wta-act-img{transform:scale(1.05)}
.wta-itin .wta-act-veil{position:absolute;inset:0;background:linear-gradient(to top,rgba(13,26,46,.55) 0%,rgba(13,26,46,0) 55%)}
.wta-itin .wta-act-flag{
  position:absolute;left:10px;top:10px;
  font-family:var(--mono);font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;
  background:var(--gold);color:var(--navy-deep);padding:4px 9px;border-radius:2px;font-weight:600;
}
.wta-itin .wta-act-body{display:flex;flex-direction:column;gap:8px;padding:15px 16px 17px}
.wta-itin .wta-act-name{font-family:var(--display);font-weight:700;font-size:17px;letter-spacing:-.01em;color:var(--ink);line-height:1.25}
.wta-itin .wta-act-desc{font-size:14px;color:var(--ink-soft);line-height:1.55}
.wta-itin .wta-act-facts{display:flex;flex-direction:column;gap:6px;margin-top:2px;border-top:1px solid var(--rule);padding-top:10px}
.wta-itin .wta-act-fact{display:flex;justify-content:space-between;gap:12px;align-items:baseline}
.wta-itin .wta-act-fact b{
  font-family:var(--mono);font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;
  color:var(--ink-soft);font-weight:400;flex:0 0 auto;
}
.wta-itin .wta-act-fact span{font-size:13.5px;color:var(--ink);text-align:right;min-width:0}
.wta-itin .wta-act-count{font-family:var(--mono);font-size:11.5px;letter-spacing:.07em;text-transform:uppercase;color:var(--gold-deep)}
@media (prefers-reduced-motion:reduce){
  .wta-itin .wta-act-img{transition:none}
  .wta-itin .wta-act:hover .wta-act-img{transform:none}
}
</style>
        <?php
    }
}
