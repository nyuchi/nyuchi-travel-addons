<?php
/**
 * Country FAQs: finding them, rendering them, and publishing them as schema.
 *
 * A country's frequently asked questions are written once, on the country term
 * (see the `faqs` field in WTA_Term_Fields). This class is everything the Term
 * FAQ widget needs that is not Elementor:
 *
 * - which term's FAQs belong on the page being rendered, including the walk
 *   from a trip, or a park, up to the country that owns them;
 * - the accessible markup, native <details> and <summary>, no script;
 * - one FAQPage JSON-LD graph per page, which stays out of the way when
 *   something else on the page already publishes FAQ schema.
 *
 * It lives apart from the widget so it can be tested without Elementor, and so
 * a theme template can call it without a widget.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Term_FAQ {

    /** Field key on the term; see WTA_Term_Fields::fields(). */
    const FIELD = 'faqs';

    /**
     * FAQs waiting for the page's one schema graph, keyed by a fingerprint of
     * the question so the same question asked twice is published once.
     *
     * @var array<string, array{question: string, answer: string}>
     */
    protected static $queue = array();

    /** Whether something else on this page already publishes FAQPage schema. */
    protected static $other_schema = false;

    public function __construct() {
        // Seen early enough for the cases where the other schema is printed
        // before this page's widgets render: the head, and any widget or block
        // earlier in the content. A widget that prints its own FAQ schema later
        // on the page is caught by the footer check, which sees all of these.
        add_filter('wpseo_schema_graph', array(__CLASS__, 'scan_schema_graph'), 99);
        add_filter('rank_math/json_ld', array(__CLASS__, 'scan_schema_graph'), 99);
        add_filter('elementor/widget/render_content', array(__CLASS__, 'scan_markup'), 99);
        add_filter('the_content', array(__CLASS__, 'scan_markup'), 99999);
    }

    /**
     * Whether the module is switched on. The widget renders nothing without it.
     */
    public static function enabled() {
        return class_exists('WP_Travel_Addons') ? WP_Travel_Addons::module_enabled('term_faq') : true;
    }

    /* ----------------------------------------------------------- Finding */

    /**
     * The term whose FAQs belong on this page, and those FAQs.
     *
     * A term chosen by hand is used as it is. Otherwise the term comes from the
     * page the same way the Elementor dynamic tags find it: the queried term on
     * an archive, a trip's deepest destination on a trip. From there the walk
     * goes up through the term's ancestors and stops at the first one that has
     * FAQs. FAQs live on countries, so a trip to Mana Pools finds Zimbabwe's,
     * and a place with its own list keeps it.
     *
     * @param int    $term_id Term chosen in the widget, or 0 for automatic.
     * @param string $scope   'nearest' to walk up, 'own' to look at the term only.
     * @return array{term: WP_Term, faqs: array}|null Null when there is nothing to show.
     */
    public static function find($term_id = 0, $scope = 'nearest') {
        $term_id = (int) $term_id;
        $term    = null;

        if ($term_id > 0) {
            $found = get_term($term_id);
            $term  = $found instanceof WP_Term ? $found : null;
            $scope = 'own';
        } elseif (class_exists('WTA_Elementor_Tags')) {
            $term = WTA_Elementor_Tags::current_term();
        }

        /**
         * The term the FAQs are looked up from.
         *
         * @param WP_Term|null $term
         * @param int          $term_id Term chosen in the widget, 0 for automatic.
         */
        $term = apply_filters('wta_term_faq_term', $term, $term_id);

        if (!$term instanceof WP_Term) {
            return null;
        }

        $candidates = array($term);

        if ('own' !== $scope) {
            foreach (get_ancestors($term->term_id, $term->taxonomy, 'taxonomy') as $ancestor_id) {
                $ancestor = get_term((int) $ancestor_id, $term->taxonomy);

                if ($ancestor instanceof WP_Term) {
                    $candidates[] = $ancestor;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $faqs = self::faqs($candidate->term_id);

            if ($faqs) {
                return array('term' => $candidate, 'faqs' => $faqs);
            }
        }

        return null;
    }

    /**
     * A term's FAQs, or an empty list when it has none or the term-fields
     * module is off.
     *
     * @param int $term_id
     * @return array<int, array{question: string, answer: string}>
     */
    public static function faqs($term_id) {
        if (!class_exists('WTA_Term_Fields')) {
            return array();
        }

        $faqs = WTA_Term_Fields::get((int) $term_id, self::FIELD, array());

        return is_array($faqs) ? $faqs : array();
    }

    /* --------------------------------------------------------- Rendering */

    /**
     * Markup for the question list.
     *
     * Native <details> and <summary>: keyboard operable, announced as
     * disclosure widgets, and open or closed with no script. Questions are
     * escaped; answers are run through the same allow-list they were saved
     * with, so a value written to the database by any other route is still
     * safe to print.
     *
     * @param array<int, array{question: string, answer: string}> $faqs
     * @param array $args {
     *     @type bool   $open_first Open the first item. Default false.
     *     @type string $group      Shared name that makes the items an
     *                              exclusive group (one open at a time). Empty
     *                              leaves them independent.
     * }
     * @return string
     */
    public static function render_list(array $faqs, array $args = array()) {
        $args = array_merge(array('open_first' => false, 'group' => ''), $args);
        $html = '<div class="wta-faq__list">';

        foreach (array_values($faqs) as $i => $faq) {
            $html .= sprintf(
                '<details class="wta-faq__item"%s%s><summary class="wta-faq__q"><span class="wta-faq__qtext">%s</span></summary><div class="wta-faq__a">%s</div></details>',
                '' !== $args['group'] ? ' name="' . esc_attr($args['group']) . '"' : '',
                (0 === $i && $args['open_first']) ? ' open' : '',
                esc_html($faq['question']),
                self::answer_html($faq['answer'])
            );
        }

        return $html . '</div>';
    }

    /**
     * An answer as safe, paragraphed HTML.
     *
     * Answers typed into the admin are plain text with blank lines between
     * paragraphs, so wpautop supplies the paragraph tags; markup that already
     * has them passes through it unchanged.
     *
     * @param string $answer
     * @return string
     */
    public static function answer_html($answer) {
        return wp_kses(wpautop((string) $answer), WTA_Term_Fields::faq_allowed_html());
    }

    /* ------------------------------------------------------------ Schema */

    /**
     * The FAQPage graph for a list of FAQs.
     *
     * @param array<int, array{question: string, answer: string}> $faqs
     * @param WP_Term|null $term
     * @return array<string, mixed>|null Null when filtered away or empty.
     */
    public static function schema(array $faqs, $term = null) {
        if (!$faqs) {
            return null;
        }

        $entities = array();

        foreach ($faqs as $faq) {
            $entities[] = array(
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => self::answer_html($faq['answer']),
                ),
            );
        }

        $page = array(
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        );

        $url = self::page_url();

        if ('' !== $url) {
            $page['@id'] = $url . '#faq';
        }

        $graph = array(
            '@context' => 'https://schema.org',
            '@graph'   => array($page),
        );

        /**
         * The FAQPage graph before it is printed.
         *
         * Return null or an empty value to publish no FAQ schema; return a
         * changed array to add to it, for example to attach the page to an
         * existing @id.
         *
         * @param array<string, mixed> $graph
         * @param array                $faqs
         * @param WP_Term|null         $term
         */
        $graph = apply_filters('wta_term_faq_schema', $graph, $faqs, $term);

        return is_array($graph) && $graph ? $graph : null;
    }

    /**
     * Queue FAQs for the page's single schema graph.
     *
     * Several widgets on one page, or the same country twice, merge into one
     * FAQPage with each question once: search engines accept one FAQPage per
     * page and ignore or penalise duplicates. The graph is printed in the
     * footer, after every widget has had its say.
     *
     * Nothing is queued while a page is being built in Elementor, where the
     * editor re-renders the widget on every keystroke.
     *
     * @param array<int, array{question: string, answer: string}> $faqs
     * @param WP_Term|null $term
     * @return string Markup to print now, which is only ever the graph itself,
     *                and only when the footer has already run.
     */
    public static function queue(array $faqs, $term = null) {
        /**
         * Whether to publish FAQPage schema for the Term FAQ widget at all.
         *
         * @param bool $enabled
         */
        $enabled = (bool) apply_filters('wta_term_faq_schema_enabled', true);

        if (!$enabled || self::is_builder_context()) {
            return '';
        }

        foreach ($faqs as $faq) {
            self::$queue[self::fingerprint($faq['question'])] = $faq;
        }

        if (!$faqs) {
            return '';
        }

        // A widget rendered after wp_footer has no later moment to publish in.
        if (did_action('wp_footer')) {
            return self::flush($term);
        }

        if (!has_action('wp_footer', array(__CLASS__, 'print_schema'))) {
            add_action('wp_footer', array(__CLASS__, 'print_schema'), 20);
        }

        return '';
    }

    /** wp_footer callback. */
    public static function print_schema() {
        echo self::flush(); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON encoded with HEX flags by script().
    }

    /**
     * Build, and empty, the queue.
     *
     * @param WP_Term|null $term
     * @return string
     */
    protected static function flush($term = null) {
        $faqs        = array_values(self::$queue);
        self::$queue = array();

        if (!$faqs || self::other_schema_present($faqs)) {
            return '';
        }

        $graph = self::schema($faqs, $term);

        return $graph ? self::script($graph) : '';
    }

    /**
     * Whether something else on this page already publishes FAQPage schema.
     *
     * Two FAQPage blocks on one page are invalid structured data, and which of
     * them a search engine keeps is not ours to decide, so this yields to
     * whatever got there first: Yoast SEO's graph (including its FAQ block),
     * Rank Math, the FAQ schema switch on Elementor's accordion and toggle
     * widgets, and any markup in the content that carries FAQPage schema.
     *
     * @param array $faqs The FAQs this would publish.
     * @return bool
     */
    public static function other_schema_present(array $faqs = array()) {
        /**
         * Whether another source already publishes FAQPage schema on this page.
         *
         * @param bool  $present Whether one of the known sources was seen.
         * @param array $faqs    The FAQs the widget would publish.
         */
        return (bool) apply_filters('wta_term_faq_other_schema_present', self::$other_schema, $faqs);
    }

    /**
     * Filter callback for schema graphs (Yoast SEO, Rank Math): note a FAQPage,
     * change nothing.
     *
     * @param mixed $graph
     * @return mixed
     */
    public static function scan_schema_graph($graph) {
        if (is_array($graph) && self::graph_has_faq_page($graph)) {
            self::$other_schema = true;
        }

        return $graph;
    }

    /**
     * Filter callback for rendered markup: note FAQPage schema, change nothing.
     *
     * @param mixed $markup
     * @return mixed
     */
    public static function scan_markup($markup) {
        if (is_string($markup) && self::markup_has_faq_page($markup)) {
            self::$other_schema = true;
        }

        return $markup;
    }

    /**
     * @param array $node A schema graph, or any part of one.
     */
    public static function graph_has_faq_page(array $node) {
        if (isset($node['@type'])) {
            foreach ((array) $node['@type'] as $type) {
                if ('FAQPage' === $type) {
                    return true;
                }
            }
        }

        foreach ($node as $child) {
            if (is_array($child) && self::graph_has_faq_page($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * JSON-LD or microdata declaring a FAQPage.
     */
    public static function markup_has_faq_page($markup) {
        if (false === stripos($markup, 'FAQPage')) {
            return false;
        }

        if (
            false !== stripos($markup, 'application/ld+json')
            && preg_match('/"@type"\s*:\s*(?:\[[^\]]*)?"FAQPage"/', $markup)
        ) {
            return true;
        }

        return (bool) preg_match('/itemtype\s*=\s*["\']https?:\/\/schema\.org\/FAQPage["\']/i', $markup);
    }

    /**
     * A JSON-LD script tag.
     *
     * The HEX flags turn <, >, & and quotes into \u escapes, so an answer that
     * contains "</script>" cannot end the tag early. The JSON is still valid
     * and decodes to the original text.
     *
     * @param array $graph
     * @return string
     */
    protected static function script(array $graph) {
        $json = wp_json_encode(
            $graph,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if (!is_string($json)) {
            return '';
        }

        return '<script type="application/ld+json" class="wta-faq-schema">' . $json . "</script>\n";
    }

    /** Case, spacing and punctuation-insensitive identity of a question. */
    protected static function fingerprint($question) {
        return md5(strtolower(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $question)))));
    }

    /** Canonical URL of the page being rendered, or ''. */
    protected static function page_url() {
        if (is_singular()) {
            $url = get_permalink();
        } elseif (is_tax() || is_category() || is_tag()) {
            $term = get_queried_object();
            $url  = $term instanceof WP_Term ? get_term_link($term) : '';
        } else {
            $url = '';
        }

        return is_string($url) ? $url : '';
    }

    /** Whether Elementor is building or previewing the page. */
    protected static function is_builder_context() {
        if (!class_exists('\\Elementor\\Plugin') || !isset(\Elementor\Plugin::$instance)) {
            return false;
        }

        $plugin = \Elementor\Plugin::$instance;

        return (isset($plugin->editor) && $plugin->editor->is_edit_mode())
            || (isset($plugin->preview) && $plugin->preview->is_preview_mode());
    }

    /**
     * Forget everything queued or seen. For tests, and for a long-running
     * process that renders more than one page.
     */
    public static function reset() {
        self::$queue        = array();
        self::$other_schema = false;
    }
}
