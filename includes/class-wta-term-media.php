<?php
/**
 * Images and card text for taxonomy terms.
 *
 * Destination, activity and keyword archives render blank because most terms
 * have neither an image nor a usable description. WP Travel does have a term
 * image field, but only a minority of terms have ever been filled in, and
 * nobody is going to hand-edit sixty terms to fix the archives.
 *
 * So this does two things. It gives every taxonomy a proper media picker on
 * the term screens, writing to WP Travel's own meta key rather than a rival
 * one — an image set in either UI is the same image. And when a term has no
 * image of its own, it borrows the featured image of the most recent trip in
 * that term, so an archive is never blank while the real images are still
 * being sourced.
 *
 * The borrowed image is derived by query, which makes it dangerous on an
 * archive page listing many terms. It is therefore cached in term meta with a
 * timestamp, so the query happens at most twice a day per term.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Term_Media {

    /**
     * WP Travel's own term image key.
     *
     * Deliberately not a WTA_ key. WP Travel writes an attachment ID here from
     * its own term UI, and 29 terms on this site already have one. Inventing a
     * second key would mean two images per term and no agreement on which wins.
     */
    const IMAGE_META = 'wp_travel_trip_type_image_id';

    /** Derived image, cached so archives do not query per term. */
    const FALLBACK_META = '_wta_image_fallback';

    /** When the derived image was last worked out. */
    const FALLBACK_TIME_META = '_wta_image_fallback_time';

    /** Trips get published and re-imaged often enough that a day is too long. */
    const FALLBACK_TTL = 43200;

    const NONCE_ACTION = 'wta_term_image_save';
    const NONCE_FIELD  = 'wta_term_image_nonce';
    const INPUT_FIELD  = 'wta_term_image_id';

    public function __construct() {
        foreach (array_keys(self::taxonomies()) as $taxonomy) {
            add_action("{$taxonomy}_add_form_fields", array($this, 'render_add_field'));
            add_action("{$taxonomy}_edit_form_fields", array($this, 'render_edit_field'), 10, 2);
            add_filter("manage_edit-{$taxonomy}_columns", array($this, 'add_column'));
            add_filter("manage_{$taxonomy}_custom_column", array($this, 'render_column'), 10, 3);
        }

        add_action('created_term', array($this, 'save_term_image'), 10, 3);
        add_action('edited_term', array($this, 'save_term_image'), 10, 3);

        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('rest_api_init', array($this, 'register_rest_field'));
    }

    /**
     * Taxonomies this applies to.
     *
     * @return array<string, string> slug => human label
     */
    public static function taxonomies() {
        return WTA_Trip::default_taxonomies();
    }

    public function handles($taxonomy) {
        return array_key_exists($taxonomy, self::taxonomies());
    }

    /**
     * The meta key images are read from and written to.
     *
     * Filterable because WP Travel has renamed fields between majors before,
     * and a site pinned to an older version must be able to follow.
     */
    public static function image_meta_key() {
        return apply_filters('wta_term_image_meta_key', self::IMAGE_META);
    }

    /* --------------------------------------------------------------- Images */

    /**
     * A term's image attachment ID.
     *
     * @param int  $term_id
     * @param bool $allow_fallback Borrow from the newest trip when unset.
     * @return int Attachment ID, or 0 when there is nothing to show.
     */
    public static function get_image_id($term_id, $allow_fallback = true) {
        $term_id = (int) $term_id;

        if ($term_id <= 0) {
            return 0;
        }

        $explicit = (int) get_term_meta($term_id, self::image_meta_key(), true);

        if ($explicit > 0) {
            return $explicit;
        }

        if (!$allow_fallback) {
            return 0;
        }

        $cached = get_term_meta($term_id, self::FALLBACK_META, true);
        $stamp  = (int) get_term_meta($term_id, self::FALLBACK_TIME_META, true);

        // '' means never derived. A stored 0 is a real answer — the term has no
        // trip, or the trip has no featured image — and is cached just as hard,
        // because the empty terms are exactly the ones an archive lists most.
        if ('' !== $cached && (time() - $stamp) < self::FALLBACK_TTL) {
            return (int) $cached;
        }

        $derived = 0;
        $trip    = self::latest_trip_in_term($term_id);

        if ($trip) {
            $derived = (int) get_post_thumbnail_id($trip);
        }

        update_term_meta($term_id, self::FALLBACK_META, $derived);
        update_term_meta($term_id, self::FALLBACK_TIME_META, time());

        return $derived;
    }

    /**
     * A term's image URL at a given size.
     *
     * @return string URL, or '' when there is no image.
     */
    public static function get_image_url($term_id, $size = 'large', $allow_fallback = true) {
        $attachment_id = self::get_image_id($term_id, $allow_fallback);

        if (!$attachment_id) {
            return '';
        }

        $url = wp_get_attachment_image_url($attachment_id, $size);

        return $url ? $url : '';
    }

    /**
     * Whether the image currently shown for a term is borrowed rather than set.
     */
    public static function is_fallback($term_id) {
        return 0 === self::get_image_id($term_id, false) && self::get_image_id($term_id) > 0;
    }

    /**
     * Store or clear a term's image.
     *
     * @param int $attachment_id 0 or an invalid ID clears the field.
     */
    public static function set_image_id($term_id, $attachment_id) {
        $term_id       = (int) $term_id;
        $attachment_id = (int) $attachment_id;

        // The derived image only applies while there is no explicit one, so any
        // change either way invalidates it.
        delete_term_meta($term_id, self::FALLBACK_META);
        delete_term_meta($term_id, self::FALLBACK_TIME_META);

        if ($attachment_id <= 0 || 'attachment' !== get_post_type($attachment_id)) {
            // An empty picker means "no image", not "attachment 0", and a term
            // holding a literal 0 reads as set to every consumer of the key.
            return delete_term_meta($term_id, self::image_meta_key());
        }

        return update_term_meta($term_id, self::image_meta_key(), $attachment_id);
    }

    /* ---------------------------------------------------------- Description */

    /**
     * Card-safe description text for a term.
     *
     * Always plain text: term descriptions are edited in a rich editor and
     * trip excerpts can carry shortcodes, neither of which survives being
     * dropped into a card, a meta tag or an image alt.
     *
     * @param int  $term_id
     * @param bool $fallback_to_trip Use the newest trip's excerpt when empty.
     * @param int  $max_words        0 means no trim.
     * @return string
     */
    public static function get_description($term_id, $fallback_to_trip = false, $max_words = 0) {
        $term_id = (int) $term_id;

        if ($term_id <= 0) {
            return '';
        }

        $text = self::plain_text(term_description($term_id));

        if ('' === $text && $fallback_to_trip) {
            $trip = self::latest_trip_in_term($term_id);

            if ($trip) {
                $text = self::plain_text(get_the_excerpt($trip));
            }
        }

        $max_words = (int) $max_words;

        if ($max_words > 0 && '' !== $text) {
            $text = wp_trim_words($text, $max_words, '…');
        }

        return $text;
    }

    /**
     * Strip everything a card cannot render and collapse the leftovers.
     */
    protected static function plain_text($text) {
        if (!is_string($text) || '' === $text) {
            return '';
        }

        $text = strip_shortcodes($text);
        $text = wp_strip_all_tags($text, true);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    /* ---------------------------------------------------------------- Query */

    /**
     * Newest published trip in a term.
     *
     * Static cache because a single archive render asks for the image and the
     * description of the same term, and the admin note asks a third time.
     *
     * @return int Post ID, or 0.
     */
    protected static function latest_trip_in_term($term_id) {
        static $cache = array();

        $term_id = (int) $term_id;

        if (isset($cache[$term_id])) {
            return $cache[$term_id];
        }

        $cache[$term_id] = 0;

        if (!WTA_Trip::is_available()) {
            return 0;
        }

        $term = get_term($term_id);

        if (!$term instanceof WP_Term) {
            return 0;
        }

        $ids = get_posts(array(
            'post_type'              => WTA_Trip::post_type(),
            'post_status'            => 'publish',
            // Ten candidates rather than one: the archive lists newest-first, so
            // always borrowing the newest trip's image makes the term hero an
            // exact duplicate of the first card underneath it. Picking
            // deterministically from a small pool keeps it stable per term
            // while avoiding that collision.
            'posts_per_page'         => 10,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => array(
                array(
                    'taxonomy'         => $term->taxonomy,
                    'field'            => 'term_id',
                    'terms'            => $term_id,
                    // A parent term's archive lists its children's trips, so its
                    // borrowed image should come from the same pool. Without this
                    // a region like "East Africa" resolves to nothing at all.
                    'include_children' => true,
                ),
            ),
        ));

        if (!empty($ids)) {
            // Keep only trips that actually have a featured image, then pick by
            // term id so the choice is stable across page loads and differs from
            // the newest trip, which is the first card on the archive.
            $with_image = array();

            foreach ($ids as $id) {
                if (get_post_thumbnail_id($id)) {
                    $with_image[] = $id;
                }
            }

            if ($with_image) {
                // Return the POST id: callers take the thumbnail from it, and
                // get_description() uses the same trip for its excerpt.
                $cache[$term_id] = (int) $with_image[$term_id % count($with_image)];
            } else {
                $cache[$term_id] = (int) $ids[0];
            }
        }

        return $cache[$term_id];
    }

    /* ------------------------------------------------------------------ UI */

    public function render_add_field($taxonomy) {
        ?>
        <div class="form-field">
            <label for="<?php echo esc_attr(self::INPUT_FIELD); ?>">Image</label>
            <?php $this->render_picker(0); ?>
            <p>Used on archive headers and destination cards. Leave empty to borrow the featured image of the newest trip in this term.</p>
        </div>
        <?php
    }

    public function render_edit_field($term, $taxonomy) {
        $explicit = self::get_image_id($term->term_id, false);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="<?php echo esc_attr(self::INPUT_FIELD); ?>">Image</label></th>
            <td>
                <?php $this->render_picker($explicit, self::get_image_id($term->term_id)); ?>
                <p class="description">
                    Used on archive headers and destination cards.
                    <?php echo esc_html($this->fallback_note($term->term_id, $explicit)); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * Explain a picture the editor did not choose.
     *
     * Without this, a term with no image still shows one, and the only way to
     * find out where it came from is to read the code.
     */
    protected function fallback_note($term_id, $explicit) {
        if ($explicit > 0) {
            return '';
        }

        $derived = self::get_image_id($term_id);

        if (!$derived) {
            return 'No image is set, and no trip in this term has a featured image to borrow.';
        }

        $trip = self::latest_trip_in_term($term_id);

        return sprintf(
            'No image is set, so the preview above is borrowed from the newest trip in this term, %s. Choosing an image here replaces it.',
            $trip ? get_the_title($trip) : 'a trip in this term'
        );
    }

    /**
     * The picker itself. Shared by the add and edit screens so the two cannot
     * drift apart.
     *
     * @param int $attachment_id Explicitly set image; the value that is saved.
     * @param int $preview_id    Borrowed image to show when nothing is set, so
     *                           the editor sees what the front end will render.
     */
    protected function render_picker($attachment_id, $preview_id = 0) {
        $attachment_id = (int) $attachment_id;
        $preview_id    = $attachment_id > 0 ? $attachment_id : (int) $preview_id;
        ?>
        <div class="wta-term-image" data-wta-term-image>
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, false); ?>
            <input type="hidden"
                   name="<?php echo esc_attr(self::INPUT_FIELD); ?>"
                   id="<?php echo esc_attr(self::INPUT_FIELD); ?>"
                   class="wta-term-image-id"
                   value="<?php echo esc_attr($attachment_id ? $attachment_id : ''); ?>">
            <div class="wta-term-image-preview" style="margin-bottom:8px;">
                <?php echo self::preview_html($preview_id); ?>
            </div>
            <button type="button" class="button wta-term-image-select">Select image</button>
            <button type="button" class="button-link wta-term-image-remove" style="margin-left:8px;">Remove</button>
        </div>
        <?php
    }

    protected static function preview_html($attachment_id) {
        $attachment_id = (int) $attachment_id;

        if ($attachment_id <= 0) {
            return '';
        }

        return wp_get_attachment_image($attachment_id, 'thumbnail', false, array(
            'style' => 'max-width:150px;height:auto;display:block;',
        ));
    }

    public function save_term_image($term_id, $tt_id, $taxonomy) {
        if (!$this->handles($taxonomy)) {
            return;
        }

        // Absent field means some other code path saved this term — a quick
        // edit, an importer, a REST write — and must not blank the image.
        if (!isset($_POST[self::NONCE_FIELD], $_POST[self::INPUT_FIELD])) {
            return;
        }

        $nonce = sanitize_key(wp_unslash($_POST[self::NONCE_FIELD]));

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can('manage_categories')) {
            return;
        }

        self::set_image_id($term_id, absint(wp_unslash($_POST[self::INPUT_FIELD])));
    }

    public function add_column($columns) {
        $out = array();

        foreach ($columns as $key => $label) {
            // Ahead of the name, so the thumbnails form a single column the eye
            // can scan for gaps.
            if ('name' === $key) {
                $out['wta_image'] = 'Image';
            }

            $out[$key] = $label;
        }

        return $out;
    }

    public function render_column($content, $column, $term_id) {
        if ('wta_image' !== $column) {
            return $content;
        }

        $explicit = self::get_image_id($term_id, false);
        $shown    = $explicit > 0 ? $explicit : self::get_image_id($term_id);

        if (!$shown) {
            return '<span style="color:#8C8F94;">—</span>';
        }

        $url = wp_get_attachment_image_url($shown, 'thumbnail');

        if (!$url) {
            return '<span style="color:#8C8F94;">—</span>';
        }

        $borrowed = 0 === $explicit;

        return sprintf(
            '<img src="%s" alt="" width="40" height="40" style="width:40px;height:40px;object-fit:cover;border-radius:3px;%s" title="%s">',
            esc_url($url),
            $borrowed ? 'opacity:.6;' : '',
            esc_attr($borrowed ? 'Borrowed from the newest trip in this term' : 'Set on this term')
        );
    }

    /* ------------------------------------------------------------- Assets */

    /**
     * Media modal only where a term is edited. wp_enqueue_media() is expensive
     * enough that loading it across wp-admin would be rude.
     */
    public function enqueue($hook_suffix) {
        if (!in_array($hook_suffix, array('edit-tags.php', 'term.php'), true)) {
            return;
        }

        $screen = get_current_screen();

        if (!$screen || !$this->handles($screen->taxonomy)) {
            return;
        }

        wp_enqueue_media();

        // Attached to media-editor rather than jquery: it is what wp_enqueue_media
        // registers, so wp.media is guaranteed to exist by the time this runs.
        wp_add_inline_script('media-editor', $this->inline_script());
    }

    protected function inline_script() {
        return <<<'JS'
(function () {
    'use strict';

    function preview(wrap, url) {
        var box = wrap.querySelector('.wta-term-image-preview');

        if (!box) {
            return;
        }

        box.innerHTML = '';

        if (!url) {
            return;
        }

        var img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.style.maxWidth = '150px';
        img.style.height = 'auto';
        img.style.display = 'block';
        box.appendChild(img);
    }

    // Delegated: WordPress replaces the add-term form over AJAX, so any handler
    // bound directly to the buttons dies with the first term created.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.wta-term-image-select, .wta-term-image-remove');

        if (!button) {
            return;
        }

        event.preventDefault();

        var wrap = button.closest('[data-wta-term-image]');
        var input = wrap ? wrap.querySelector('.wta-term-image-id') : null;

        if (!wrap || !input) {
            return;
        }

        if (button.classList.contains('wta-term-image-remove')) {
            input.value = '';
            preview(wrap, '');
            return;
        }

        if (!window.wp || !window.wp.media) {
            return;
        }

        var frame = window.wp.media({
            title: 'Select term image',
            button: { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var url = attachment.url;

            if (attachment.sizes && attachment.sizes.medium) {
                url = attachment.sizes.medium.url;
            } else if (attachment.sizes && attachment.sizes.thumbnail) {
                url = attachment.sizes.thumbnail.url;
            }

            input.value = attachment.id;
            preview(wrap, url);
        });

        frame.open();
    });

    // After an inline add, the form is reused for the next term — leaving the
    // last selection on screen would silently attach it to the wrong term.
    if (window.jQuery) {
        window.jQuery(document).on('ajaxSuccess', function (event, xhr, settings) {
            if (!settings || !settings.data || settings.data.indexOf('action=add-tag') === -1) {
                return;
            }

            var wraps = document.querySelectorAll('#addtag [data-wta-term-image]');

            Array.prototype.forEach.call(wraps, function (wrap) {
                var input = wrap.querySelector('.wta-term-image-id');

                if (input) {
                    input.value = '';
                }

                preview(wrap, '');
            });
        });
    }
}());
JS;
    }

    /* ---------------------------------------------------------------- REST */

    public function register_rest_field() {
        foreach (array_keys(self::taxonomies()) as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            register_rest_field($taxonomy, 'wta_image', array(
                'get_callback'    => function ($term) {
                    return self::rest_image($term['id']);
                },
                'update_callback' => function ($value, $term) {
                    if (!current_user_can('manage_categories')) {
                        return new WP_Error('wta_forbidden', 'You cannot change term images.', array('status' => 403));
                    }

                    // Accept the read shape back unchanged, so a client can GET,
                    // edit one field and PUT the whole object.
                    if (is_array($value) && isset($value['id'])) {
                        $value = $value['id'];
                    }

                    if (!is_numeric($value)) {
                        return new WP_Error('wta_bad_image', 'wta_image must be an attachment ID.', array('status' => 400));
                    }

                    $attachment_id = (int) $value;

                    if ($attachment_id > 0 && 'attachment' !== get_post_type($attachment_id)) {
                        return new WP_Error(
                            'wta_bad_image',
                            sprintf('%d is not an attachment.', $attachment_id),
                            array('status' => 400)
                        );
                    }

                    self::set_image_id($term->term_id, $attachment_id);

                    return true;
                },
                'schema'          => array(
                    'description' => 'Term image. Reads as an object; write an attachment ID, or 0 to clear.',
                    'type'        => array('object', 'integer'),
                    'context'     => array('view', 'edit'),
                    'properties'  => array(
                        'id'          => array(
                            'description' => 'Attachment ID, or 0 when there is no image.',
                            'type'        => 'integer',
                            'context'     => array('view', 'edit'),
                        ),
                        'url'         => array(
                            'description' => 'Large-size URL, or an empty string.',
                            'type'        => 'string',
                            'format'      => 'uri',
                            'context'     => array('view', 'edit'),
                            'readonly'    => true,
                        ),
                        'is_fallback' => array(
                            'description' => 'True when the image is borrowed from a trip rather than set on the term.',
                            'type'        => 'boolean',
                            'context'     => array('view', 'edit'),
                            'readonly'    => true,
                        ),
                    ),
                ),
            ));
        }
    }

    /**
     * @return array{id: int, url: string, is_fallback: bool}
     */
    protected static function rest_image($term_id) {
        $explicit = self::get_image_id($term_id, false);
        $shown    = $explicit > 0 ? $explicit : self::get_image_id($term_id);
        $url      = $shown ? wp_get_attachment_image_url($shown, 'large') : '';

        return array(
            'id'          => (int) $shown,
            'url'         => $url ? $url : '',
            'is_fallback' => ($shown > 0 && 0 === $explicit),
        );
    }
}
