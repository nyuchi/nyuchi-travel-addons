<?php
/**
 * Classification diagnostics.
 *
 * A trip catalogue is only findable if its taxonomies actually divide it.
 * Flat trees have nothing to link to, empty terms publish thin archives,
 * terms that sit on everything filter nothing, and two taxonomies holding
 * the same names are two answers to one question.
 *
 * This class only measures — it returns findings as plain arrays and never
 * prints, so the admin screen, the REST layer and WP-CLI can each render the
 * same data their own way.
 *
 * Note on draft terms: WTA_Term_Status hides drafts from get_terms() for
 * visitors only, so an audit run from wp-admin (where this is used) sees the
 * full term set.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Taxonomy_Audit {

    /** A term on this share of the catalogue has stopped being a filter. */
    const SATURATION_RATIO = 0.7;

    /** Below this many published posts the ratio above is just noise. */
    const MIN_CORPUS = 10;

    /** Findings carry sample terms, not the whole term set. */
    const TERM_SAMPLE = 25;

    /**
     * Findings keyed by the taxonomy set they were produced from.
     *
     * An audit walks every term in every taxonomy; nothing in one request
     * should pay for that twice.
     *
     * @var array<string, array[]>
     */
    private $cache = array();

    /**
     * No hooks. The audit is pull-only: it runs when something asks for it,
     * never on a page load that did not request it.
     */
    public function __construct() {
    }

    /**
     * Taxonomies worth auditing.
     *
     * The configured list is aspirational — it survives WP Travel being
     * deactivated — so it is narrowed to what is actually registered.
     *
     * @return array<string, string> slug => human label
     */
    public function taxonomies() {
        $out = array();

        foreach (WTA_Trip::default_taxonomies() as $slug => $label) {
            if (taxonomy_exists($slug)) {
                $out[$slug] = $label;
            }
        }

        return $out;
    }

    /**
     * Run every check.
     *
     * @param array<string, string>|null $taxonomies Defaults to taxonomies().
     * @return array[] Findings, worst first.
     */
    public function run($taxonomies = null) {
        if (null === $taxonomies || !is_array($taxonomies) || empty($taxonomies)) {
            $taxonomies = $this->taxonomies();
        }

        $key = md5(implode('|', array_keys($taxonomies)));

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $findings = array();

        // One fetch per taxonomy, reused by every check below.
        $terms_by_taxonomy = array();

        foreach (array_keys($taxonomies) as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ));

            $terms_by_taxonomy[$taxonomy] = is_wp_error($terms) ? array() : $terms;
        }

        foreach ($terms_by_taxonomy as $taxonomy => $terms) {
            if (empty($terms)) {
                continue;
            }

            $findings = array_merge($findings, $this->check_flat_hierarchy($taxonomy, $terms));
            $findings = array_merge($findings, $this->check_empty_terms($taxonomy, $terms));
            $findings = array_merge($findings, $this->check_non_segmenting($taxonomy, $terms));
            $findings = array_merge($findings, $this->check_near_duplicates_within($taxonomy, $terms));
            $findings = array_merge($findings, $this->check_drafts($taxonomy, $terms));
        }

        $findings = array_merge($findings, $this->check_duplicates_across($terms_by_taxonomy));

        $this->cache[$key] = $this->sort_findings($findings);

        return $this->cache[$key];
    }

    /**
     * Counts by severity, for a headline the reader can act on.
     *
     * @param array[]|null $findings Defaults to a fresh run().
     * @return array{high:int, medium:int, low:int, total:int}
     */
    public function summary($findings = null) {
        if (null === $findings || !is_array($findings)) {
            $findings = $this->run();
        }

        $summary = array('high' => 0, 'medium' => 0, 'low' => 0, 'total' => 0);

        foreach ($findings as $finding) {
            $severity = isset($finding['severity']) ? $finding['severity'] : 'low';

            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }

            $summary['total']++;
        }

        return $summary;
    }

    /* ------------------------------------------------------------- checks */

    /**
     * A hierarchical taxonomy in which nothing is nested.
     *
     * @param string    $taxonomy
     * @param WP_Term[] $terms
     * @return array[]
     */
    protected function check_flat_hierarchy($taxonomy, $terms) {
        if (!is_taxonomy_hierarchical($taxonomy)) {
            return array();
        }

        foreach ($terms as $term) {
            if (0 !== (int) $term->parent) {
                return array();
            }
        }

        return array($this->finding(array(
            'id'       => 'flat-hierarchy',
            'taxonomy' => $taxonomy,
            'severity' => 'medium',
            'title'    => sprintf('%s is hierarchical but entirely flat', $this->label($taxonomy)),
            'detail'   => 'Every term sits at the top level, so there is nothing between the archive index and an individual term. Grouping terms under a region or family parent creates an indexable landing page for the group and gives breadcrumbs another level to show.',
            'count'    => count($terms),
            'terms'    => $terms,
        )));
    }

    /**
     * Terms nothing is filed under.
     *
     * @param string    $taxonomy
     * @param WP_Term[] $terms
     * @return array[]
     */
    protected function check_empty_terms($taxonomy, $terms) {
        $empty = array();

        foreach ($terms as $term) {
            if (0 === (int) $term->count) {
                $empty[] = $term;
            }
        }

        if (empty($empty)) {
            return array();
        }

        return array($this->finding(array(
            'id'       => 'empty-terms',
            'taxonomy' => $taxonomy,
            'severity' => 'low',
            'title'    => sprintf('%d %s terms have no content', count($empty), $this->label($taxonomy)),
            'detail'   => 'Each empty term still publishes an archive with nothing on it. Either fill them, draft them, or remove them so the site is not offering pages that answer nothing.',
            'count'    => count($empty),
            'terms'    => $empty,
        )));
    }

    /**
     * Terms applied to nearly the whole catalogue.
     *
     * @param string    $taxonomy
     * @param WP_Term[] $terms
     * @return array[]
     */
    protected function check_non_segmenting($taxonomy, $terms) {
        $total = $this->published_total($taxonomy);

        // On a small catalogue any term can look saturated by accident.
        if ($total < self::MIN_CORPUS) {
            return array();
        }

        $threshold = $total * self::SATURATION_RATIO;
        $saturated = array();

        foreach ($terms as $term) {
            if ((int) $term->count >= $threshold) {
                $saturated[] = $term;
            }
        }

        if (empty($saturated)) {
            return array();
        }

        return array($this->finding(array(
            'id'       => 'non-segmenting',
            'taxonomy' => $taxonomy,
            'severity' => 'high',
            'title'    => sprintf('%d %s terms cover most of the catalogue', count($saturated), $this->label($taxonomy)),
            'detail'   => sprintf(
                'These terms are on at least %d%% of the %d published items, so choosing one narrows nothing for a visitor and its archive duplicates the main listing. Split them into distinctions people actually search on.',
                (int) round(self::SATURATION_RATIO * 100),
                $total
            ),
            'count'    => count($saturated),
            'terms'    => $saturated,
        )));
    }

    /**
     * Two terms in one taxonomy that are the same term twice.
     *
     * @param string    $taxonomy
     * @param WP_Term[] $terms
     * @return array[]
     */
    protected function check_near_duplicates_within($taxonomy, $terms) {
        $groups = array();

        foreach ($terms as $term) {
            $key = $this->normalise($term->name);

            if ('' === $key) {
                continue;
            }

            $groups[$key][] = $term;
        }

        $collisions = array();

        foreach ($groups as $group) {
            if (count($group) > 1) {
                $collisions = array_merge($collisions, $group);
            }
        }

        if (empty($collisions)) {
            return array();
        }

        return array($this->finding(array(
            'id'       => 'near-duplicate-within',
            'taxonomy' => $taxonomy,
            'severity' => 'medium',
            'title'    => sprintf('%s contains near-duplicate terms', $this->label($taxonomy)),
            'detail'   => 'Terms differing only in case, plural or punctuation split the same content across two archives, so neither accumulates the links or the depth to rank. Merge them and keep the stronger slug.',
            'count'    => count($collisions),
            'terms'    => $collisions,
        )));
    }

    /**
     * The same name living in more than one taxonomy.
     *
     * @param array<string, WP_Term[]> $terms_by_taxonomy
     * @return array[]
     */
    protected function check_duplicates_across($terms_by_taxonomy) {
        $seen = array();

        foreach ($terms_by_taxonomy as $taxonomy => $terms) {
            foreach ($terms as $term) {
                $key = $this->normalise($term->name);

                if ('' === $key) {
                    continue;
                }

                $seen[$key][$taxonomy][] = $term;
            }
        }

        $overlap = array();

        foreach ($seen as $taxonomies) {
            if (count($taxonomies) < 2) {
                continue;
            }

            foreach ($taxonomies as $taxonomy => $terms) {
                foreach ($terms as $term) {
                    // The finding has no taxonomy of its own, so each sample
                    // has to say where it came from or it cannot be acted on.
                    $overlap[] = $this->term_row($term, $taxonomy);
                }
            }
        }

        if (empty($overlap)) {
            return array();
        }

        return array($this->finding(array(
            'id'       => 'duplicate-across-taxonomies',
            'taxonomy' => '',
            'severity' => 'high',
            'title'    => sprintf('%d terms exist in more than one taxonomy', count($overlap)),
            'detail'   => 'Two taxonomies carrying the same names are competing on one axis of meaning, which leaves editors guessing where to file a trip and search engines choosing between two archives about the same thing. Decide which taxonomy owns that axis and empty the other.',
            'count'    => count($overlap),
            'terms'    => $overlap,
        )));
    }

    /**
     * Terms held back from the public site.
     *
     * Not a defect — a drafted term is the intended way to build a tree ahead
     * of its content — but forgotten drafts are content nobody can reach, so
     * the number is worth surfacing.
     *
     * @param string    $taxonomy
     * @param WP_Term[] $terms
     * @return array[]
     */
    protected function check_drafts($taxonomy, $terms) {
        if (!class_exists('WTA_Term_Status')) {
            return array();
        }

        $drafts = array();

        foreach ($terms as $term) {
            if (WTA_Term_Status::DRAFT === WTA_Term_Status::get_status($term->term_id)) {
                $drafts[] = $term;
            }
        }

        if (empty($drafts)) {
            return array();
        }

        return array($this->finding(array(
            'id'       => 'drafts',
            'taxonomy' => $taxonomy,
            'severity' => 'low',
            'title'    => sprintf('%d %s terms are held as drafts', count($drafts), $this->label($taxonomy)),
            'detail'   => 'These terms are assignable in wp-admin but invisible to visitors and search engines. Check none of them are finished work still waiting to be switched live.',
            'count'    => count($drafts),
            'terms'    => $drafts,
        )));
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Published posts across every post type the taxonomy is attached to.
     *
     * This is the denominator for saturation: a term can only be measured
     * against the pool of things that could have carried it.
     */
    protected function published_total($taxonomy) {
        $object = get_taxonomy($taxonomy);

        if (!$object || empty($object->object_type)) {
            return 0;
        }

        $total = 0;

        foreach ((array) $object->object_type as $post_type) {
            $counts = wp_count_posts($post_type);

            if (isset($counts->publish)) {
                $total += (int) $counts->publish;
            }
        }

        return $total;
    }

    /**
     * Reduce a term name to the thing it means.
     *
     * "Victoria Falls", "victoria-falls" and "Victoria Falls " are one
     * destination; the plural strip catches "Safari" against "Safaris".
     */
    protected function normalise($name) {
        $name = strtolower(trim((string) $name));

        // Punctuation and separators are formatting, not meaning.
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));

        if ('' === $name) {
            return '';
        }

        // Only strip a plural that leaves something behind: "s" and "as"
        // are words in their own right on a travel site.
        if (strlen($name) > 3 && 's' === substr($name, -1) && 's' !== substr($name, -2, 1)) {
            $name = substr($name, 0, -1);
        }

        return $name;
    }

    /**
     * Build a finding, normalising the term list to the published shape.
     *
     * @param array $args
     * @return array
     */
    protected function finding($args) {
        $terms = isset($args['terms']) ? $args['terms'] : array();
        $rows  = array();

        foreach ($terms as $term) {
            // check_duplicates_across pre-builds its rows so it can annotate them.
            $rows[] = is_array($term) ? $term : $this->term_row($term);
        }

        return array(
            'id'       => (string) $args['id'],
            'taxonomy' => (string) $args['taxonomy'],
            'severity' => (string) $args['severity'],
            'title'    => (string) $args['title'],
            'detail'   => (string) $args['detail'],
            'count'    => (int) $args['count'],
            'terms'    => array_slice($rows, 0, self::TERM_SAMPLE),
        );
    }

    /**
     * @param WP_Term $term
     * @param string  $taxonomy Appended to the name when the finding itself
     *                          has no taxonomy to display.
     * @return array{term_id:int, name:string, slug:string, count:int}
     */
    protected function term_row($term, $taxonomy = '') {
        $name = $term->name;

        if ('' !== $taxonomy) {
            $name = sprintf('%s (%s)', $name, $this->label($taxonomy));
        }

        return array(
            'term_id' => (int) $term->term_id,
            'name'    => (string) $name,
            'slug'    => (string) $term->slug,
            'count'   => (int) $term->count,
        );
    }

    /**
     * Human label for a taxonomy, falling back to the registered one.
     */
    protected function label($taxonomy) {
        $configured = WTA_Trip::default_taxonomies();

        if (isset($configured[$taxonomy])) {
            return $configured[$taxonomy];
        }

        $object = get_taxonomy($taxonomy);

        return $object ? $object->labels->name : $taxonomy;
    }

    /**
     * Worst first, then biggest first — the order someone would fix them in.
     *
     * @param array[] $findings
     * @return array[]
     */
    protected function sort_findings($findings) {
        $rank = array('high' => 0, 'medium' => 1, 'low' => 2);

        usort($findings, function ($a, $b) use ($rank) {
            $a_rank = isset($rank[$a['severity']]) ? $rank[$a['severity']] : 3;
            $b_rank = isset($rank[$b['severity']]) ? $rank[$b['severity']] : 3;

            if ($a_rank !== $b_rank) {
                return $a_rank - $b_rank;
            }

            if ($a['count'] !== $b['count']) {
                return $b['count'] - $a['count'];
            }

            // Stable output matters: this is rendered and diffed by people.
            return strcmp($a['id'] . $a['taxonomy'], $b['id'] . $b['taxonomy']);
        });

        return $findings;
    }
}
