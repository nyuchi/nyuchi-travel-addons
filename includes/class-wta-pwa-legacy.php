<?php
/**
 * Clean-up of WP Travel's old service worker.
 *
 * WP Travel's PWA writes a physical /sw.js into the web root. The file stays
 * after the PWA setting is switched off, so browsers that registered it keep
 * running a worker that answers the homepage cache-first, indefinitely. The
 * only way to remove a worker from a visitor's browser is to serve a new
 * script at the same URL that unregisters itself, so this class replaces the
 * file's contents with exactly that (assets/js/wta-sw-kill.js).
 *
 * This is not behind the PWA module switch: it repairs damage already done
 * and is needed whether or not the module is ever used.
 *
 * Safety rules:
 *  - The file is only touched when it carries WP Travel's own signature.
 *    An unrelated sw.js (another plugin, a hand-written one) is never changed.
 *  - Nothing is touched while WP Travel's PWA setting is still on, because
 *    the worker is in use.
 *  - It is written through WP_Filesystem. When the file cannot be written the
 *    admin notice gives the exact path and the script to paste in by hand.
 *
 * It runs on admin_init, and again only when the file has changed or the
 * plugin has been updated after a failure. A button on the PWA screen and in
 * the notice runs it on demand.
 *
 * Disable all of it with: add_filter( 'wta_pwa_legacy_cleanup', '__return_false' );
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_PWA_Legacy {

    const OPTION = 'wta_pwa_legacy';

    const ACTION = 'wta_pwa_remove_legacy';

    const DISMISS_ACTION = 'wta_pwa_dismiss_legacy';

    /** What the old WP Travel worker declares near its top. */
    const SIGNATURE_PATTERN = '/const\s+staticWPTravel\s*=\s*["\']wp-travel["\']/';

    /** First line of the replacement, so an already-cleaned file is recognised. */
    const KILL_MARKER = 'wta-pwa-kill-switch';

    public function __construct() {
        // The retired-worker route works on the front end; the rest is admin.
        add_action('parse_request', array($this, 'serve_retired_worker'), 1);

        if (!self::enabled()) {
            return;
        }

        add_action('admin_init', array($this, 'maybe_run'));
        add_action('admin_notices', array($this, 'notice'));
        add_action('admin_post_' . self::ACTION, array($this, 'handle_remove'));
        add_action('admin_post_' . self::DISMISS_ACTION, array($this, 'handle_dismiss'));
    }

    public static function enabled() {
        return (bool) apply_filters('wta_pwa_legacy_cleanup', true);
    }

    /** Absolute path of the file WP Travel writes. */
    public static function sw_path() {
        return (string) apply_filters('wta_pwa_legacy_sw_path', ABSPATH . 'sw.js');
    }

    /** The replacement worker. */
    public static function kill_script() {
        $file = (defined('WTA_DIR') ? WTA_DIR : plugin_dir_path(dirname(__FILE__))) . 'assets/js/wta-sw-kill.js';
        $js   = is_readable($file) ? file_get_contents($file) : false;

        if (false === $js || '' === $js) {
            $js = "// " . self::KILL_MARKER . "\nself.addEventListener('install', () => self.skipWaiting());\n"
                . "self.addEventListener('activate', (event) => { event.waitUntil(caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k)))).then(() => self.registration.unregister()).then(() => self.clients.matchAll({ type: 'window' })).then((clients) => clients.forEach((c) => c.navigate(c.url)))); });\n";
        }

        return $js;
    }

    /** Whether file contents are WP Travel's old worker. */
    public static function has_signature($contents) {
        return is_string($contents) && 1 === preg_match(self::SIGNATURE_PATTERN, $contents);
    }

    /** Whether file contents are already the replacement. */
    public static function is_killed($contents) {
        return is_string($contents) && false !== strpos($contents, self::KILL_MARKER);
    }

    /* ------------------------------------------------------ retired worker */

    /**
     * Once the PWA module has served its worker, switching the module off
     * must not strand it in visitors' browsers: the same stale-worker problem
     * this class exists to fix. While the module is off, the worker URL
     * answers with the kill-switch script, which removes itself and its
     * caches. A site that never switched the module on is never affected.
     *
     * @param WP $wp The WordPress environment.
     */
    public function serve_retired_worker($wp) {
        if (WP_Travel_Addons::module_enabled('pwa') || !get_option(WTA_PWA::SERVED_OPTION)) {
            return;
        }

        if ('sw' !== WTA_PWA::request_kind($wp)) {
            return;
        }

        WTA_PWA::send(self::retired_response());
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public static function retired_response() {
        return array(
            'status'  => 200,
            'headers' => array(
                'Content-Type'           => 'application/javascript; charset=utf-8',
                'Cache-Control'          => 'no-cache, no-store, must-revalidate, max-age=0',
                'Service-Worker-Allowed' => WTA_PWA::scope_path(),
                'X-Content-Type-Options' => 'nosniff',
            ),
            'body'    => self::kill_script(),
        );
    }

    /* --------------------------------------------------------------- state */

    /**
     * Live look at the file plus the last recorded result.
     *
     * state is one of: none (no file), legacy (old worker present), cleaned
     * (replaced), unrelated (some other sw.js), pwa_enabled (WP Travel's PWA
     * is on and the file is in use), not_writable, error, disabled.
     *
     * @return array<string, mixed>
     */
    public static function status() {
        $path   = self::sw_path();
        $record = get_option(self::OPTION, array());
        $record = is_array($record) ? $record : array();
        $state  = 'none';

        if (!self::enabled()) {
            $state = 'disabled';
        } elseif (is_file($path)) {
            $contents = file_get_contents($path);

            if (self::has_signature($contents)) {
                if (WTA_PWA::wp_travel_pwa_active()) {
                    $state = 'pwa_enabled';
                } elseif (isset($record['state']) && in_array($record['state'], array('not_writable', 'error'), true)) {
                    $state = $record['state'];
                } else {
                    $state = 'legacy';
                }
            } elseif (self::is_killed($contents)) {
                $state = 'cleaned';
            } else {
                $state = 'unrelated';
            }
        }

        return array(
            'state'      => $state,
            'path'       => $path,
            'present'    => is_file($path),
            'cleaned'    => 'cleaned' === $state,
            'last_run'   => isset($record['time']) ? (int) $record['time'] : 0,
            'last_state' => isset($record['state']) ? (string) $record['state'] : '',
            'message'    => isset($record['message']) ? (string) $record['message'] : '',
        );
    }

    private static function fingerprint($path) {
        clearstatcache(true, $path);

        return is_file($path) ? filemtime($path) . '-' . filesize($path) : '';
    }

    /* ----------------------------------------------------------------- run */

    /**
     * admin_init: run when there is something new to look at. A cheap check
     * (one stat) on every admin request, a read and possible write only when
     * the file is new, changed, or a failed attempt predates this version.
     */
    public function maybe_run() {
        if (!self::enabled() || wp_doing_ajax() || wp_doing_cron() || !current_user_can('manage_options')) {
            return;
        }

        $record = get_option(self::OPTION, array());
        $record = is_array($record) ? $record : array();
        $path   = self::sw_path();
        $print  = self::fingerprint($path);

        $unchanged = isset($record['fingerprint']) && $record['fingerprint'] === $print;
        $failed    = isset($record['state']) && in_array($record['state'], array('not_writable', 'error'), true);
        $retry     = $failed && (!isset($record['version']) || WTA_VERSION !== $record['version']);
        // Left alone while WP Travel's PWA was on; look again once it is off.
        $waiting   = isset($record['state']) && 'pwa_enabled' === $record['state'] && !WTA_PWA::wp_travel_pwa_active();

        if ($unchanged && !$retry && !$waiting) {
            return;
        }

        self::clean();
    }

    /**
     * Replace the old worker if, and only if, it is WP Travel's. Records and
     * returns the outcome.
     *
     * @return array{state: string, message: string}
     */
    public static function clean() {
        if (!self::enabled()) {
            return array('state' => 'disabled', 'message' => 'Clean-up is switched off by the wta_pwa_legacy_cleanup filter.');
        }

        $path = self::sw_path();

        if (WTA_PWA::wp_travel_pwa_active() && is_file($path) && self::has_signature((string) file_get_contents($path))) {
            return self::record('pwa_enabled', 'WP Travel\'s PWA setting is still on, so the file was left alone.');
        }

        if (!is_file($path)) {
            return self::record('none', 'There is no sw.js in the web root.');
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            return self::record('error', 'The file could not be read.');
        }

        if (self::is_killed($contents)) {
            return self::record('cleaned', 'The old worker was already replaced.');
        }

        if (!self::has_signature($contents)) {
            return self::record('unrelated', 'sw.js is not WP Travel\'s worker, so it was left alone.');
        }

        $written = self::write($path, self::kill_script());

        if (true !== $written) {
            return self::record($written['state'], $written['message']);
        }

        return self::record('cleaned', 'The old WP Travel worker was replaced. Returning visitors lose its caches and are reloaded the next time their browser checks it.');
    }

    /**
     * @return true|array{state: string, message: string}
     */
    private static function write($path, $script) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $dir = dirname($path);

        // WP_Filesystem can only be used silently when it needs no
        // credentials (the "direct" method). Prompting for FTP details from
        // an admin_init hook is not possible, so that case reports instead.
        if ('direct' !== get_filesystem_method(array(), $dir)) {
            return array('state' => 'not_writable', 'message' => 'WordPress cannot write to the web root without FTP credentials.');
        }

        global $wp_filesystem;

        if (!WP_Filesystem(false, $dir) || !is_object($wp_filesystem)) {
            return array('state' => 'error', 'message' => 'The WordPress filesystem could not be initialised.');
        }

        if (!$wp_filesystem->is_writable($path)) {
            return array('state' => 'not_writable', 'message' => 'The file is not writable by the web server.');
        }

        if (!$wp_filesystem->put_contents($path, $script, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644)) {
            return array('state' => 'error', 'message' => 'Writing the file failed.');
        }

        clearstatcache(true, $path);

        if (file_get_contents($path) !== $script) {
            return array('state' => 'error', 'message' => 'The file was written but does not match the replacement.');
        }

        return true;
    }

    private static function record($state, $message) {
        $path = self::sw_path();

        update_option(self::OPTION, array(
            'state'       => $state,
            'message'     => $message,
            'time'        => time(),
            'version'     => defined('WTA_VERSION') ? WTA_VERSION : '',
            'path'        => $path,
            'fingerprint' => self::fingerprint($path),
            'dismissed'   => false,
        ), false);

        return array('state' => $state, 'message' => $message);
    }

    /* ----------------------------------------------------------- admin post */

    public function handle_remove() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'nyuchi-travel-addons'), '', array('response' => 403));
        }

        check_admin_referer(self::ACTION);

        self::clean();

        wp_safe_redirect(WTA_Admin::admin_url_for('pwa'));
        exit;
    }

    public function handle_dismiss() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'nyuchi-travel-addons'), '', array('response' => 403));
        }

        check_admin_referer(self::DISMISS_ACTION);

        $record = get_option(self::OPTION, array());

        if (is_array($record)) {
            $record['dismissed'] = true;
            update_option(self::OPTION, $record, false);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /** URL of the "Remove old service worker" button. */
    public static function remove_url() {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION);
    }

    /* --------------------------------------------------------------- notice */

    /**
     * Notice from the recorded result (no file reads on ordinary admin pages).
     * Failures show everywhere until fixed; a success shows until dismissed.
     */
    public function notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $record = get_option(self::OPTION, array());

        if (!is_array($record) || empty($record['state'])) {
            return;
        }

        $state = $record['state'];
        $path  = isset($record['path']) ? (string) $record['path'] : self::sw_path();

        if ('cleaned' === $state && empty($record['dismissed']) && !empty($record['time']) && (time() - (int) $record['time']) < 30 * DAY_IN_SECONDS && 'cleaned' === (self::status()['state'])) {
            printf(
                '<div class="notice notice-success"><p><strong>Old WP Travel service worker removed.</strong> %s <a href="%s">Dismiss</a></p></div>',
                esc_html($record['message']),
                esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::DISMISS_ACTION), self::DISMISS_ACTION))
            );

            return;
        }

        if ('not_writable' === $state || 'error' === $state) {
            // Re-check live: the owner may have fixed it by hand.
            $live = self::status();

            if ($live['state'] !== $state) {
                return;
            }

            echo '<div class="notice notice-error"><p><strong>The old WP Travel service worker is still in place.</strong> ';
            echo esc_html($record['message']);
            echo ' Returning visitors can keep seeing a stale homepage until it is replaced.</p>';
            echo '<p>Replace the contents of <code>' . esc_html($path) . '</code> with the script below (or make the file writable and press the button).</p>';
            echo '<p><textarea readonly rows="8" style="width:100%;font-family:monospace" onfocus="this.select()">' . esc_textarea(self::kill_script()) . '</textarea></p>';
            echo '<p><a class="button button-primary" href="' . esc_url(self::remove_url()) . '">Remove old service worker</a></p></div>';
        }
    }
}
