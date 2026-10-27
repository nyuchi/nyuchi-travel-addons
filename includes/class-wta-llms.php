<?php
/**
 * /llms.txt and /llms-full.txt for AI assistants.
 *
 * llms.txt (https://llmstxt.org) is a Markdown map of a site written for language
 * models. SEO plugins generate a short one; for a travel catalogue the useful
 * version lists every trip with its length and from-price, the destinations and
 * the pages that answer the questions travellers ask. llms-full.txt carries the
 * detail of each trip: overview, itinerary and what is included.
 *
 * Everything is read from the site itself, nothing is invented, and an unpriced
 * trip is listed without a price. The output is cached and rebuilt when content
 * changes.
 *
 * Yoast SEO writes its own llms.txt as a physical file in the web root, which the
 * web server serves before WordPress runs. Turn Yoast's llms.txt feature off (and
 * delete the file if it is left behind) for this module to be reached.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_LLMS {

    /** Request path => kind of file. */
    const FILES = array(
        'llms.txt'      => 'index',
        'llms-full.txt' => 'full',
    );

    /** Transient prefix; one entry per kind. */
    const CACHE_PREFIX = 'wta_llms_';

    /** Longest day description or overview line, in characters, by kind. */
    const EXCERPT = array(
        'index' => 170,
        'full'  => 1500,
    );

    /** Pages named by slug, in the order they are listed. */
    const PAGES = array(
        'about-us',
        'experiences',
        'destinations-landing',
        'national-parks',
        'initial-planning',
        'safari-investment-guide',
        'packing-lists',
        'faq',
        'reviews',
        'contact-us',
    );

    /** Policy pages named by slug. */
    const POLICIES = array(
        'privacy-policy',
        'terms-and-conditions',
        'cancellation-policy',
    );

    public function __construct() {
        // Before WordPress and other plugins route the request.
        add_action('parse_request', array($this, 'maybe_serve'), 0);

        foreach (array('save_post', 'deleted_post', 'trashed_post', 'edited_term', 'created_term', 'delete_term') as $hook) {
            add_action($hook, array(__CLASS__, 'flush'));
        }

        add_action('admin_notices', array($this, 'conflict_notice'));
        add_action('send_headers', array($this, 'send_link_headers'));
    }

    /**
     * Point agents at the llms.txt files and the sitemap from the home page's
     * response headers, so they can be found without parsing the page.
     *
     * @return string[] The Link header values.
     */
    public function link_headers() {
        return array(
            '<' . home_url('/llms.txt') . '>; rel="alternate"; type="text/plain"; title="llms.txt"',
            '<' . home_url('/llms-full.txt') . '>; rel="alternate"; type="text/plain"; title="llms-full.txt"',
            '<' . home_url('/sitemap_index.xml') . '>; rel="sitemap"; type="application/xml"',
        );
    }

    /**
     * @return void
     */
    public function send_link_headers() {
        if (is_admin() || headers_sent() || !(is_front_page() || is_home())) {
            return;
        }

        foreach ($this->link_headers() as $value) {
            header('Link: ' . $value, false);
        }
    }

    /* ------------------------------------------------------------- routing */

    /**
     * Which file a request is for, or ''.
     *
     * @param WP $wp The WordPress environment.
     */
    public static function request_kind($wp) {
        $path = isset($wp->request) ? trim((string) $wp->request, '/') : '';

        return isset(self::FILES[$path]) ? self::FILES[$path] : '';
    }

    public function maybe_serve($wp) {
        $kind = self::request_kind($wp);

        if ('' === $kind) {
            return;
        }

        WTA_PWA::send(self::response($kind));
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function response($kind) {
        $body = self::body($kind);

        return array(
            'status'  => 200,
            'headers' => array(
                'Content-Type'           => 'text/plain; charset=utf-8',
                'Cache-Control'          => 'public, max-age=3600',
                'ETag'                   => '"' . md5($body) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ),
            'body'    => $body,
        );
    }

    /** Drop the cached files; they are rebuilt on the next request. */
    public static function flush() {
        foreach (self::FILES as $kind) {
            delete_transient(self::CACHE_PREFIX . $kind);
        }
    }

    /**
     * Tell an administrator when Yoast's physical llms.txt is shadowing this one.
     */
    public function conflict_notice() {
        if (!current_user_can('manage_options') || !file_exists(ABSPATH . 'llms.txt')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Nyuchi Travel Addons: a physical llms.txt file in the web root is served before this plugin can. Turn off the llms.txt feature in Yoast SEO and delete the file, then the plugin\'s complete llms.txt is served.', 'wp-travel-addons')
            . '</p></div>';
    }

    /* -------------------------------------------------------------- output */

    protected static function body($kind) {
        $cached = get_transient(self::CACHE_PREFIX . $kind);

        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        $body = 'full' === $kind ? self::build_full() : self::build_index();

        set_transient(self::CACHE_PREFIX . $kind, $body, 6 * HOUR_IN_SECONDS);

        return $body;
    }

    /**
     * The short map: every trip, the destinations and the key pages.
     */
    public static function build_index() {
        $lines   = self::header();
        $trips   = self::trips();
        $groups  = self::group_by_country($trips);
        $excerpt = self::EXCERPT['index'];

        $lines[] = '## Trips';
        $lines[] = '';

        foreach ($groups as $country => $items) {
            $lines[] = '### ' . $country;

            foreach ($items as $trip) {
                $facts = array();

                if ($trip['duration']) {
                    $facts[] = $trip['duration'];
                }

                if ($trip['price']) {
                    $facts[] = $trip['price'];
                }

                $line = '- [' . self::md($trip['title']) . '](' . $trip['url'] . ')';

                if ($facts) {
                    $line .= ': ' . implode(' · ', $facts);
                }

                if ('' !== $trip['overview']) {
                    $line .= '. ' . self::trim_text($trip['overview'], $excerpt);
                }

                $lines[] = $line;
            }

            $lines[] = '';
        }

        $destinations = self::destinations();

        if ($destinations) {
            $lines[] = '## Destinations';
            $lines[] = '';

            foreach ($destinations as $dest) {
                $line = '- [' . self::md($dest['name']) . '](' . $dest['url'] . ')';

                if ('' !== $dest['text']) {
                    $line .= ': ' . self::trim_text($dest['text'], $excerpt);
                }

                $lines[] = $line;
            }

            $lines[] = '';
        }

        $lines = array_merge($lines, self::page_section('Planning and information', self::pages(self::PAGES)));

        $parks = self::park_pages();

        $lines = array_merge($lines, self::page_section('National park guides', $parks));

        $lines = array_merge($lines, self::page_section('Articles', self::recent_posts(20)));

        $lines = array_merge($lines, self::page_section('Policies', self::pages(self::POLICIES)));

        $lines[] = '## Optional';
        $lines[] = '';
        $lines[] = '- [Full catalogue with itineraries and inclusions](' . home_url('/llms-full.txt') . ')';
        $lines[] = '- [Sitemap](' . home_url('/sitemap_index.xml') . ')';
        $lines[] = '';

        return self::finish($lines);
    }

    /**
     * The long file: each trip with its overview, itinerary and inclusions.
     */
    public static function build_full() {
        $lines = self::header();

        $lines[] = 'This file lists every trip in full. A shorter map of the site is at ' . home_url('/llms.txt') . '.';
        $lines[] = '';

        foreach (self::group_by_country(self::trips()) as $country => $items) {
            $lines[] = '# ' . $country;
            $lines[] = '';

            foreach ($items as $trip) {
                $lines[] = '## ' . self::md($trip['title']);
                $lines[] = '';
                $lines[] = 'URL: ' . $trip['url'];

                if ($trip['duration']) {
                    $lines[] = 'Duration: ' . $trip['duration'];
                }

                if ($trip['price']) {
                    $lines[] = 'Price: ' . $trip['price'];
                }

                if ($trip['countries']) {
                    $lines[] = 'Countries: ' . implode(', ', $trip['countries']);
                }

                foreach (array('Trip type' => $trip['types'], 'Activities' => $trip['activities']) as $label => $names) {
                    if ($names) {
                        $lines[] = $label . ': ' . implode(', ', $names);
                    }
                }

                $lines[] = '';

                if ('' !== $trip['overview']) {
                    $lines[] = self::trim_text($trip['overview'], self::EXCERPT['full']);
                    $lines[] = '';
                }

                if ($trip['days']) {
                    $lines[] = 'Itinerary:';

                    foreach ($trip['days'] as $day) {
                        $lines[] = '- ' . $day;
                    }

                    $lines[] = '';
                }

                foreach (array('Included' => $trip['included'], 'Not included' => $trip['excluded']) as $label => $items_list) {
                    if ($items_list) {
                        $lines[] = $label . ':';

                        foreach ($items_list as $item) {
                            $lines[] = '- ' . $item;
                        }

                        $lines[] = '';
                    }
                }
            }
        }

        return self::finish($lines);
    }

    /** Title, summary and notes, shared by both files. */
    protected static function header() {
        $name    = self::plain(get_bloginfo('name'));
        $front   = (int) get_option('page_on_front');
        $summary = $front ? (string) get_post_meta($front, '_yoast_wpseo_metadesc', true) : '';
        $summary = '' !== trim($summary) ? $summary : (string) get_bloginfo('description');

        /**
         * One-line summary shown under the title of llms.txt.
         *
         * @param string $summary Home page meta description, else the tagline.
         */
        $summary = (string) apply_filters('wta_llms_summary', self::plain($summary));

        $notes = array(
            'Prices are from-prices per person sharing' . self::currency_note() . '. Each journey is quoted, and can be rebuilt around your dates, your party and what you want to see.',
            'To plan a trip, use the contact page: ' . home_url('/contact-us/') . '.',
        );

        /**
         * Extra lines of notes under the summary.
         *
         * @param string[] $notes Notes.
         */
        $notes = (array) apply_filters('wta_llms_notes', $notes);

        $lines = array('# ' . $name, '');

        if ('' !== $summary) {
            $lines[] = '> ' . $summary;
            $lines[] = '';
        }

        foreach ($notes as $note) {
            $lines[] = self::plain($note);
            $lines[] = '';
        }

        return $lines;
    }

    protected static function finish($lines) {
        return rtrim(implode("\n", $lines)) . "\n";
    }

    /* ---------------------------------------------------------------- data */

    /**
     * Published trips with everything either file needs.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function trips() {
        if (!WTA_Trip::is_available()) {
            return array();
        }

        $ids = get_posts(array(
            'post_type'        => WTA_Trip::post_type(),
            'post_status'      => 'publish',
            'posts_per_page'   => 500,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ));

        $trips = array();

        foreach ($ids as $id) {
            $price = WTA_Compat::trip_from_price($id);

            $trips[] = array(
                'id'         => $id,
                'title'      => self::plain(get_the_title($id)),
                'url'        => get_permalink($id),
                'duration'   => self::duration($id),
                'price'      => $price > 0 ? self::price_text($price) : '',
                'overview'   => self::plain((string) get_post_meta($id, 'wp_travel_overview', true)),
                'countries'  => self::country_names($id),
                'types'      => self::term_names($id, 'itinerary_types'),
                'activities' => self::term_names($id, 'activity'),
                'days'       => self::day_lines($id),
                'included'   => self::list_items((string) get_post_meta($id, 'wp_travel_trip_include', true)),
                'excluded'   => self::list_items((string) get_post_meta($id, 'wp_travel_trip_exclude', true)),
            );
        }

        return $trips;
    }

    /**
     * Group trips under the country they are filed under, alphabetically.
     *
     * A trip is listed once, under its primary destination's country when one
     * is set, else under its first country. Trips with no destination are
     * listed under "Other journeys".
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected static function group_by_country($trips) {
        $groups = array();

        foreach ($trips as $trip) {
            $key = $trip['countries'] ? self::primary_country($trip['id'], $trip['countries']) : 'Other journeys';

            $groups[$key][] = $trip;
        }

        uksort($groups, function ($a, $b) {
            if ('Other journeys' === $a) {
                return 1;
            }

            if ('Other journeys' === $b) {
                return -1;
            }

            return strcasecmp($a, $b);
        });

        return $groups;
    }

    protected static function primary_country($trip_id, $countries) {
        $primary = (int) get_post_meta($trip_id, '_yoast_wpseo_primary_travel_locations', true);

        if ($primary) {
            $country = self::country_of($primary);

            if ($country && in_array($country->name, $countries, true)) {
                return $country->name;
            }
        }

        return $countries[0];
    }

    /**
     * The country-level term for any destination term: depth 1 under a region.
     * A term with no parent is itself a region, which is returned as is.
     *
     * @return WP_Term|null
     */
    protected static function country_of($term_id) {
        $term = get_term((int) $term_id, 'travel_locations');

        if (!$term || is_wp_error($term)) {
            return null;
        }

        $chain = array_reverse(get_ancestors($term->term_id, 'travel_locations', 'taxonomy'));
        $chain[] = $term->term_id;

        // chain[0] is the region, chain[1] the country.
        $pick = isset($chain[1]) ? $chain[1] : $chain[0];
        $found = get_term((int) $pick, 'travel_locations');

        return ($found && !is_wp_error($found)) ? $found : null;
    }

    /** @return string[] Distinct country names a trip is filed under, sorted. */
    protected static function country_names($trip_id) {
        $terms = get_the_terms($trip_id, 'travel_locations');

        if (!is_array($terms)) {
            return array();
        }

        $names = array();

        foreach ($terms as $term) {
            $country = self::country_of($term->term_id);

            if ($country) {
                $names[$country->name] = self::plain($country->name);
            }
        }

        $names = array_values($names);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    protected static function term_names($trip_id, $taxonomy) {
        $terms = get_the_terms($trip_id, $taxonomy);

        if (!is_array($terms)) {
            return array();
        }

        return array_values(array_map(function ($term) {
            return self::plain($term->name);
        }, $terms));
    }

    /**
     * Country terms with their trip counts, for the Destinations section.
     *
     * @return array<int, array{name: string, url: string, text: string}>
     */
    protected static function destinations() {
        $terms = get_terms(array(
            'taxonomy'   => 'travel_locations',
            'hide_empty' => true,
            'pad_counts' => true,
            'parent'     => 0,
        ));

        if (!is_array($terms)) {
            return array();
        }

        $out = array();

        foreach ($terms as $region) {
            $children = get_terms(array(
                'taxonomy'   => 'travel_locations',
                'hide_empty' => true,
                'pad_counts' => true,
                'parent'     => $region->term_id,
            ));

            foreach ((array) $children as $country) {
                $url = get_term_link($country);

                if (is_wp_error($url)) {
                    continue;
                }

                $out[] = array(
                    'name' => self::plain($country->name),
                    'url'  => $url,
                    'text' => self::plain((string) $country->description),
                );
            }
        }

        usort($out, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $out;
    }

    /**
     * Published pages by slug.
     *
     * @param string[] $slugs Page slugs, in order.
     * @return array<int, array{title: string, url: string, text: string}>
     */
    protected static function pages($slugs) {
        $out = array();

        foreach ($slugs as $slug) {
            $page = get_page_by_path($slug);

            if (!$page || 'publish' !== $page->post_status || '1' === (string) get_post_meta($page->ID, '_yoast_wpseo_meta-robots-noindex', true)) {
                continue;
            }

            $text = self::plain((string) get_post_meta($page->ID, '_yoast_wpseo_metadesc', true));

            if ('' === $text && $page->post_excerpt) {
                $text = self::plain($page->post_excerpt);
            }

            $out[] = array(
                'title' => self::plain(get_the_title($page)),
                'url'   => get_permalink($page),
                'text'  => $text,
            );
        }

        return $out;
    }

    /** @return array<int, array{title: string, url: string, text: string}> */
    protected static function park_pages() {
        $parent = get_page_by_path('national-parks');

        if (!$parent) {
            return array();
        }

        $children = get_pages(array(
            'child_of'    => $parent->ID,
            'post_status' => 'publish',
            'sort_column' => 'post_title',
        ));

        $out = array();

        foreach ((array) $children as $page) {
            $out[] = array(
                'title' => self::plain(get_the_title($page)),
                'url'   => get_permalink($page),
                'text'  => self::plain((string) get_post_meta($page->ID, '_yoast_wpseo_metadesc', true)),
            );
        }

        return $out;
    }

    /** @return array<int, array{title: string, url: string, text: string}> */
    protected static function recent_posts($count) {
        $posts = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $count,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ));

        $out = array();

        foreach ($posts as $post) {
            $text = self::plain((string) get_post_meta($post->ID, '_yoast_wpseo_metadesc', true));

            $out[] = array(
                'title' => self::plain(get_the_title($post)),
                'url'   => get_permalink($post),
                'text'  => '' !== $text ? $text : self::plain(get_the_excerpt($post)),
            );
        }

        return $out;
    }

    protected static function page_section($heading, $items) {
        if (!$items) {
            return array();
        }

        $lines = array('## ' . $heading, '');

        foreach ($items as $item) {
            $line = '- [' . self::md($item['title']) . '](' . $item['url'] . ')';

            if ('' !== $item['text']) {
                $line .= ': ' . self::trim_text($item['text'], self::EXCERPT['index']);
            }

            $lines[] = $line;
        }

        $lines[] = '';

        return $lines;
    }

    /* ------------------------------------------------------------- helpers */

    protected static function duration($trip_id) {
        $days   = max(0, (int) get_post_meta($trip_id, 'wp_travel_trip_duration', true));
        $nights = max(0, (int) get_post_meta($trip_id, 'wp_travel_trip_duration_night', true));

        $parts = array();

        if ($days) {
            $parts[] = $days . ($days > 1 ? ' days' : ' day');
        }

        if ($nights) {
            $parts[] = $nights . ($nights > 1 ? ' nights' : ' night');
        }

        return implode(' / ', $parts);
    }

    protected static function currency_code() {
        $code = '';

        if (function_exists('wptravel_get_settings')) {
            $settings = wptravel_get_settings();
            $code     = is_array($settings) && !empty($settings['currency']) ? (string) $settings['currency'] : '';
        }

        if ('' === $code) {
            $settings = get_option('wp_travel_settings');
            $code     = is_array($settings) && !empty($settings['currency']) ? (string) $settings['currency'] : '';
        }

        /**
         * Currency code used in the files, when WP Travel does not say.
         *
         * @param string $code ISO code, USD by default.
         */
        return (string) apply_filters('wta_llms_currency', '' !== $code ? strtoupper($code) : 'USD');
    }

    protected static function currency_note() {
        return ', in ' . self::currency_code();
    }

    protected static function price_text($price) {
        return 'from ' . self::currency_code() . ' ' . number_format((float) $price, 0, '.', ',') . ' per person sharing';
    }

    /** @return string[] One line per itinerary day. */
    protected static function day_lines($trip_id) {
        $days = get_post_meta($trip_id, WTA_Trip::ITINERARY_META, true);

        if (!is_array($days)) {
            return array();
        }

        $out = array();

        foreach ($days as $i => $day) {
            if (!is_array($day)) {
                continue;
            }

            $label = isset($day['label']) && '' !== trim((string) $day['label']) ? self::plain($day['label']) : 'Day ' . ($i + 1);
            $title = isset($day['title']) ? self::plain($day['title']) : '';
            $desc  = isset($day['desc']) ? self::plain($day['desc']) : '';

            $line = $label . ('' !== $title ? ': ' . $title : '');

            if ('' !== $desc) {
                $line .= '. ' . self::trim_text($desc, 240);
            }

            $out[] = $line;
        }

        return $out;
    }

    /** @return string[] Text of each list item in an HTML list. */
    protected static function list_items($html) {
        if ('' === trim($html)) {
            return array();
        }

        $parts = preg_split('#</li>#i', $html);
        $out   = array();

        foreach ((array) $parts as $part) {
            $text = self::plain($part);

            if ('' !== $text) {
                $out[] = $text;
            }
        }

        return array_slice($out, 0, 30);
    }

    /** Plain text: tags and shortcodes gone, entities decoded, whitespace collapsed. */
    protected static function plain($value) {
        $text = wp_strip_all_tags(strip_shortcodes((string) $value));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    /** Escape the characters that break a Markdown link label. */
    protected static function md($text) {
        return str_replace(array('[', ']'), array('\\[', '\\]'), (string) $text);
    }

    protected static function trim_text($text, $limit) {
        $text = trim((string) $text);

        if (function_exists('mb_strlen') ? mb_strlen($text) <= $limit : strlen($text) <= $limit) {
            return $text;
        }

        $cut  = function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
        $last = strrpos($cut, ' ');

        if (false !== $last && $last > $limit * 0.6) {
            $cut = substr($cut, 0, $last);
        }

        return rtrim($cut, " \t,;:.") . '…';
    }
}
