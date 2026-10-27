<?php
/**
 * AI crawler policy for robots.txt.
 *
 * Search and answer crawlers are welcome, so the site can be found and quoted
 * by assistants. Crawlers that collect pages to train models are disallowed.
 * The lists are filterable so a site can move a bot from one to the other.
 *
 * WordPress builds robots.txt on the fly and Yoast adds its own lines to the
 * same filter, so this module only appends. A physical robots.txt file in the
 * web root bypasses WordPress entirely and is not touched.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_AI_Crawlers {

    /** Crawlers that fetch pages to answer a question or build a search index. */
    const SEARCH_BOTS = array(
        'OAI-SearchBot',
        'ChatGPT-User',
        'Claude-SearchBot',
        'Claude-User',
        'PerplexityBot',
        'Perplexity-User',
    );

    /** Crawlers that collect pages to train models. */
    const TRAINING_BOTS = array(
        'GPTBot',
        'ClaudeBot',
        'anthropic-ai',
        'Google-Extended',
        'Applebot-Extended',
        'CCBot',
        'Bytespider',
        'Meta-ExternalAgent',
    );

    /** Content Signals, https://contentsignals.org: what a crawler may do with content. */
    const CONTENT_SIGNAL = 'search=yes, ai-input=yes, ai-train=no';

    public function __construct() {
        // After Yoast, which appends its sitemap line late.
        add_filter('robots_txt', array($this, 'filter_robots'), 100000, 2);
    }

    /**
     * Append the policy to robots.txt.
     *
     * @param string $output Current robots.txt.
     * @param bool   $public Whether the site is visible to search engines.
     * @return string
     */
    public function filter_robots($output, $public = true) {
        if (!$public || !is_string($output)) {
            return $output;
        }

        $search   = (array) apply_filters('wta_ai_search_bots', self::SEARCH_BOTS);
        $training = (array) apply_filters('wta_ai_training_bots', self::TRAINING_BOTS);

        // A bot in both lists is treated as a training bot: the stricter rule wins.
        $search = array_values(array_diff($search, $training));

        $output = rtrim($output);

        if (false === stripos($output, 'Content-Signal:')) {
            $output = preg_replace('/^(User-agent:\s*\*\s*)$/mi', "$1\nContent-Signal: " . self::CONTENT_SIGNAL, $output, 1);
        }

        $block = '';

        if ($search) {
            $block .= "\n\n# Search and answer engines are welcome.\n";
            $block .= self::agents($search) . "Allow: /\n";
        }

        if ($training) {
            $block .= "\n# Crawlers that collect pages to train AI models are not.\n";
            $block .= self::agents($training) . "Disallow: /\n";
        }

        $output .= $block;

        if (false === stripos($output, 'Sitemap:')) {
            $output .= "\nSitemap: " . home_url('/sitemap_index.xml') . "\n";
        } else {
            $output .= "\n";
        }

        return $output;
    }

    /**
     * One "User-agent:" line per bot, so they share the rule that follows.
     *
     * @param string[] $bots Crawler names.
     * @return string
     */
    protected static function agents($bots) {
        $lines = '';

        foreach ($bots as $bot) {
            $bot = trim(preg_replace('/[^A-Za-z0-9._\-]/', '', (string) $bot));

            if ('' !== $bot) {
                $lines .= 'User-agent: ' . $bot . "\n";
            }
        }

        return $lines;
    }
}
