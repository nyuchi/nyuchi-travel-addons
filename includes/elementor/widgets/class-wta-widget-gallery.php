<?php
/**
 * Trip gallery.
 *
 * WP Travel stores a trip's gallery in post meta, but it has not stored it the
 * same way across versions or across the plugins that write to it: the same key
 * turns up as a serialised array, a comma separated list, and a JSON array.
 * Reading it with a single cast would silently render an empty gallery on the
 * majority of a catalogue, so every shape is normalised to integers here and the
 * sources are tried in order of how deliberately an editor chose them.
 *
 * The default presentation is vertical 3:4 tiles. Portrait framing suits the
 * subject — animals, people, canyon walls — far better than the landscape crop a
 * generic gallery widget imposes, and a grid of portraits reads as a considered
 * set rather than a contact sheet.
 *
 * No lightbox script ships with this widget. Elementor Pro already loads one,
 * and the data attributes below opt each image into it as a grouped slideshow,
 * so the feature costs nothing in page weight.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Widget_Gallery extends \Elementor\Widget_Base {

    use WTA_Widget_Styles;

    /**
     * Whether the gallery CSS has already gone out this request.
     *
     * The rules are identical for every instance, so a page with three galleries
     * would otherwise ship the same block three times.
     *
     * @var bool
     */
    protected static $printed_css = false;

    public function get_name() {
        return 'wta-trip-gallery';
    }

    public function get_title() {
        return 'Trip Gallery';
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
            'label' => 'Gallery',
        ));

        $this->add_control('heading', array(
            'label'   => 'Section heading',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'On this trip',
        ));

        $this->add_control('standfirst', array(
            'label'   => 'Standfirst',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'rows'    => 3,
            'default' => 'Photographs from the route, not stock library scenery.',
        ));

        $this->add_control('count', array(
            'label'       => 'How many images',
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'min'         => 1,
            'max'         => 40,
            'default'     => 12,
            'description' => 'Capped so a trip carrying a hundred attachments cannot turn a page into a download.',
        ));

        $this->add_control('columns', array(
            'label'   => 'Columns',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => array('2' => '2', '3' => '3', '4' => '4'),
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
            'description' => 'Vertical is the house default. Wildlife and people read better upright.',
        ));

        $this->add_control('gap', array(
            'label'      => 'Gap',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px'),
            'range'      => array(
                'px' => array('min' => 0, 'max' => 48, 'step' => 1),
            ),
            'default'    => array('unit' => 'px', 'size' => 6),
        ));

        $this->add_control('lightbox', array(
            'label'       => 'Open in lightbox',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
            'description' => 'Uses the lightbox Elementor already loads. No extra script is enqueued.',
        ));

        $this->add_control('show_caption', array(
            'label'       => 'Show captions',
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => '',
            'description' => 'Reads the attachment caption. Off by default because most trip images have none.',
        ));

        $this->end_controls_section();

        /* ---------------------------------------------------------- style */

        $this->wta_media_style_section(
            'gal', 'Image', '.wta-gallery-media', '.wta-gallery-media img'
        );

        $this->wta_box_style_section(
            'gal', 'Grid', '.wta-gallery-item', array('grid' => '.wta-gallery-grid')
        );

    }

    /**
     * Coerce one stored gallery value into attachment IDs.
     *
     * Accepts every shape the meta has been observed in: a real array (from
     * maybe_unserialize, which get_post_meta already applies), a JSON array, and
     * a comma separated string. Array members may themselves be objects or
     * arrays carrying the ID under one of several keys, which is what the media
     * pickers in newer WP Travel builds write.
     *
     * @param mixed $raw
     * @return int[]
     */
    protected function normalise_ids($raw) {
        if (empty($raw)) {
            return array();
        }

        if (is_string($raw)) {
            $raw = trim($raw);

            // get_post_meta unserialises for us, but the value can also arrive
            // from a filter or an import that never went through that path.
            $unserialised = maybe_unserialize($raw);

            if (is_array($unserialised)) {
                $raw = $unserialised;
            } elseif ('[' === substr($raw, 0, 1) || '{' === substr($raw, 0, 1)) {
                $decoded = json_decode($raw, true);
                $raw     = is_array($decoded) ? $decoded : array();
            } else {
                $raw = explode(',', $raw);
            }
        }

        if (is_object($raw)) {
            $raw = get_object_vars($raw);
        }

        if (!is_array($raw)) {
            return array();
        }

        $ids = array();

        foreach ($raw as $item) {
            if (is_object($item)) {
                $item = get_object_vars($item);
            }

            if (is_array($item)) {
                // Nested shape: the picker stored a whole attachment record.
                foreach (array('id', 'ID', 'image_id', 'attachment_id', 'value') as $key) {
                    if (isset($item[$key])) {
                        $item = $item[$key];
                        break;
                    }
                }
            }

            if (is_array($item) || is_object($item)) {
                continue;
            }

            $id = (int) trim((string) $item);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Gallery IDs for a trip, in order of how deliberate the source is.
     *
     * The editor-curated meta wins, then the secondary gallery key, then
     * whatever happens to be attached to the post, then the featured image on
     * its own. A single-image "gallery" is still a better answer than an empty
     * section.
     *
     * @param int $post_id
     * @return int[]
     */
    protected function gallery_ids($post_id) {
        $ids = $this->normalise_ids(get_post_meta($post_id, 'wp_travel_itinerary_gallery_ids', true));

        if (!$ids) {
            $ids = $this->normalise_ids(get_post_meta($post_id, 'wp_travel_advanced_gallery', true));
        }

        if (!$ids) {
            $attached = get_posts(array(
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'post_parent'    => $post_id,
                'post_status'    => 'inherit',
                'posts_per_page' => 40,
                'orderby'        => 'menu_order ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ));

            if ($attached) {
                $ids = array_map('intval', $attached);
            }
        }

        if (!$ids) {
            $thumb = (int) get_post_thumbnail_id($post_id);

            if ($thumb) {
                $ids = array($thumb);
            }
        }

        // An ID can survive in meta after the attachment is deleted, which would
        // otherwise render an empty figure and a hole in the grid.
        return array_values(array_filter($ids, function ($id) {
            return (bool) wp_get_attachment_image_src($id, 'thumbnail');
        }));
    }

    /**
     * Layout-only CSS, kept in the widget because the shared reference
     * stylesheet is a fixed design artefact that this plugin does not edit.
     *
     * Everything is scoped under .wta-itin, matching the reference file, and the
     * clipping sits on the tile while the transform sits on the image — the
     * reverse does not clip at all, because overflow has no effect on a replaced
     * element.
     */
    protected function print_css() {
        if (self::$printed_css) {
            return;
        }

        self::$printed_css = true;

        echo '<style id="wta-gallery-css">'
            . '.wta-itin .wta-gallery-grid{display:grid;gap:var(--wta-gal-gap,6px);margin:0}'
            . '.wta-itin .wta-gallery-grid[data-cols="2"]{grid-template-columns:repeat(2,minmax(0,1fr))}'
            . '.wta-itin .wta-gallery-grid[data-cols="3"]{grid-template-columns:repeat(3,minmax(0,1fr))}'
            . '.wta-itin .wta-gallery-grid[data-cols="4"]{grid-template-columns:repeat(4,minmax(0,1fr))}'
            . '@media (max-width:1024px){.wta-itin .wta-gallery-grid[data-cols="3"],.wta-itin .wta-gallery-grid[data-cols="4"]{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            . '@media (max-width:640px){.wta-itin .wta-gallery-grid{grid-template-columns:minmax(0,1fr)}}'
            . '.wta-itin .wta-gallery-item{margin:0;min-width:0;display:flex;flex-direction:column;gap:6px}'
            /* The tile owns the ratio and the overflow. */
            . '.wta-itin .wta-gallery-media{aspect-ratio:var(--wta-gal-ratio,3/4);position:relative;display:block;overflow:hidden;border-radius:4px;background:var(--forest-deep);line-height:0}'
            . '.wta-itin .wta-gallery-grid[data-aspect="3-4"]{--wta-gal-ratio:3/4}'
            . '.wta-itin .wta-gallery-grid[data-aspect="4-3"]{--wta-gal-ratio:4/3}'
            . '.wta-itin .wta-gallery-grid[data-aspect="1-1"]{--wta-gal-ratio:1/1}'
            . '.wta-itin .wta-gallery-grid[data-aspect="16-9"]{--wta-gal-ratio:16/9}'
            /* The image is what moves, so the crop stays inside the tile. */
            . '.wta-itin .wta-gallery-img{width:100%;height:100%;object-fit:cover;transform:scale(1);transition:transform .5s ease}'
            . '.wta-itin .wta-gallery-media:hover .wta-gallery-img,'
            . '.wta-itin .wta-gallery-media:focus-visible .wta-gallery-img{transform:scale(1.05)}'
            . '.wta-itin a.wta-gallery-media:focus-visible{outline:2px solid var(--dust);outline-offset:3px}'
            . '.wta-itin .wta-gallery-item figcaption{font-family:var(--mono);font-size:10.5px;letter-spacing:.08em;'
            . 'text-transform:uppercase;color:var(--ink-soft);line-height:1.4;margin:0}'
            . '@media (prefers-reduced-motion:reduce){.wta-itin .wta-gallery-img{transition:none}'
            . '.wta-itin .wta-gallery-media:hover .wta-gallery-img,'
            . '.wta-itin .wta-gallery-media:focus-visible .wta-gallery-img{transform:none}}'
            . '</style>';
    }

    protected function render() {
        if (!WTA_Trip::is_available()) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">WP Travel is not active, so there is no trip gallery to show.</p></div>';

            return;
        }

        $s       = $this->get_settings_for_display();
        $post_id = (int) get_the_ID();

        // Off a trip — a bare template in the editor, say — there is nothing to
        // read, and a placeholder line is friendlier than a warning.
        $ids = ($post_id && get_post_type($post_id) === WTA_Trip::post_type())
            ? $this->gallery_ids($post_id)
            : array();

        if (!$ids) {
            echo '<div class="wta-itin"><p class="wta-eyebrow">This trip has no gallery images yet.</p></div>';

            return;
        }

        $count = max(1, min(40, (int) $s['count']));
        $ids   = array_slice($ids, 0, $count);

        $gap = isset($s['gap']['size']) && '' !== $s['gap']['size'] ? (int) $s['gap']['size'] : 6;

        $this->print_css();

        echo '<div class="wta-itin">';
        echo '<section class="wta-gallery wta-section"><div class="wta-wrap">';

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

        printf(
            '<div class="wta-gallery-grid" data-cols="%s" data-aspect="%s" style="--wta-gal-gap:%dpx">',
            esc_attr($s['columns']),
            esc_attr($s['aspect']),
            (int) $gap
        );

        $trip_title = get_the_title($post_id);
        $slideshow  = 'wta-gallery-' . $post_id;

        foreach ($ids as $id) {
            $alt = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));

            // An empty alt on a decorative-looking safari photo still leaves a
            // screen reader with nothing, so the trip name is the fallback.
            if ('' === $alt) {
                $alt = $trip_title;
            }

            $image = wp_get_attachment_image($id, 'large', false, array(
                'class'   => 'wta-gallery-img',
                'loading' => 'lazy',
                'alt'     => $alt,
            ));

            if (!$image) {
                continue;
            }

            echo '<figure class="wta-gallery-item">';

            if ('yes' === $s['lightbox']) {
                $full = wp_get_attachment_image_url($id, 'full');

                printf(
                    '<a class="wta-gallery-media" href="%s" data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="%s">',
                    esc_url($full ? $full : ''),
                    esc_attr($slideshow)
                );
                echo $image;
                echo '</a>';
            } else {
                echo '<span class="wta-gallery-media">' . $image . '</span>';
            }

            if ('yes' === $s['show_caption']) {
                $caption = wp_get_attachment_caption($id);

                if ($caption) {
                    echo '<figcaption>' . esc_html($caption) . '</figcaption>';
                }
            }

            echo '</figure>';
        }

        echo '</div>';
        echo '</div></section>';
        echo '</div>';
    }
}
