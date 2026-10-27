<?php
/**
 * AI crawler policy for robots.txt.
 *
 * Every AI crawler is welcome by default, so the site can be found, quoted and
 * learned from by assistants: search and answer bots, and bots that collect
 * pages to train models. Nothing is blocked unless a site adds a bot to the
 * blocked list with a filter.
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

    /** AI crawlers named in robots.txt with an explicit Allow. */
    const ALLOWED_BOTS = array(
        'OAI-SearchBot',
        'ChatGPT-User',
        'GPTBot',
        'Claude-SearchBot',
        'Claude-User',
        'ClaudeBot',
        'anthropic-ai',
        'PerplexityBot',
        'Perplexity-User',
        'Google-Extended',
        'Applebot-Extended',
        'CCBot',
    );

    /** Crawlers disallowed by default: none. Add some with wta_ai_blocked_bots. */
    const BLOCKED_BOTS = array();

    /** Content Signals, https://contentsignals.org: what a crawler may do with content. */
    const CONTENT_SIGNAL = 'search=yes, ai-input=yes, ai-train=yes';

    public function __construct() {
        // After Yoast, which appends its sitemap line late.
        add_filter('robots_txt', array($this, 'filter_robots'), 100000, 2);

        // Cloudflare's Markdown for Agents serves pages to agents and adds
        // "ai-train=yes" unless the origin sends its own Content-Signal header.
        add_action('send_headers', array($this, 'send_content_signal'));
    }

    /**
     * Send the Content-Signal header on front-end responses, so the policy
     * also reaches agents that never read robots.txt and overrides the default
     * Cloudflare adds to its Markdown responses.
     *
     * @return void
     */
    public function send_content_signal() {
        if (is_admin() || headers_sent()) {
            return;
        }

        /**
         * The Content-Signal value sent in the response header and written to
         * robots.txt. Return an empty string to send nothing.
         *
         * @param string $signal Default, search=yes, ai-input=yes, ai-train=yes.
         */
        $signal = (string) apply_filters('wta_ai_content_signal', self::CONTENT_SIGNAL);

        if ('' !== trim($signal)) {
            header('Content-Signal: ' . preg_replace('/[^A-Za-z0-9=, _\-]/', '', $signal));
        }
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

        $allowed = (array) apply_filters('wta_ai_allowed_bots', self::ALLOWED_BOTS);
        $blocked = (array) apply_filters('wta_ai_blocked_bots', self::BLOCKED_BOTS);

        // A bot in both lists is blocked: the stricter rule wins.
        $allowed = array_values(array_diff($allowed, $blocked));

        $output = rtrim($output);

        $signal = trim((string) apply_filters('wta_ai_content_signal', self::CONTENT_SIGNAL));

        if ('' !== $signal && false === stripos($output, 'Content-Signal:')) {
            $output = preg_replace('/^(User-agent:\s*\*\s*)$/mi', "$1\nContent-Signal: " . $signal, $output, 1);
        }

        $block = '';

        if ($allowed) {
            $block .= "\n\n# AI search, answer and training crawlers are welcome.\n";
            $block .= self::agents($allowed) . "Allow: /\n";
        }

        if ($blocked) {
            $block .= "\n# Blocked on this site.\n";
            $block .= self::agents($blocked) . "Disallow: /\n";
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
