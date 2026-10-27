<?php
/**
 * The optional consent log: proof that a choice was made, kept without
 * personal data.
 *
 * A record is a random consent id (the uuid in the visitor's cookie), the time,
 * the policy version, the two choices, a salted and truncated hash of the IP
 * address and the browser family. There is no name, email, user id, full IP
 * address or full user agent. The hash cannot be reversed without the site's
 * salts and is only kept to rate-limit the endpoint and to tell apart repeat
 * records.
 *
 * It lives in its own small table (one INSERT per choice, no read-modify-write
 * race, and counts and erasure by consent id are plain queries), created the
 * first time logging is switched on. Records older than 13 months are purged
 * daily by WP-Cron. Logging is off by default.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Consent_Log {

    const ROUTE_NS = 'wta/v1';
    const ROUTE    = '/consent';

    const DB_OPTION  = 'wta_consent_db';
    const DB_VERSION = '1';
    const CRON       = 'wta_consent_purge';

    /** Requests one hashed address may make in one window. */
    const RATE_LIMIT  = 10;
    const RATE_WINDOW = 600;

    /** 13 months, the longest consent should be kept before it is asked again. */
    const RETENTION_DAYS = 396;

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_route'));
        add_action(self::CRON, array(__CLASS__, 'purge'));
        add_action('admin_init', array($this, 'maybe_install'));
        add_action('admin_init', array($this, 'privacy_policy_content'));
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array($this, 'register_eraser'));
    }

    public static function table() {
        global $wpdb;

        return $wpdb->prefix . 'wta_consent_log';
    }

    /* --------------------------------------------------------------- schema */

    public function maybe_install() {
        if (!empty(WTA_Consent::settings()['log_enabled'])) {
            self::install();
        }
    }

    /** Create or update the table once per schema version. */
    public static function install() {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            consent_id char(36) NOT NULL,
            created_at datetime NOT NULL,
            version varchar(20) NOT NULL,
            analytics tinyint(1) NOT NULL DEFAULT 0,
            marketing tinyint(1) NOT NULL DEFAULT 0,
            choice varchar(10) NOT NULL,
            ip_hash char(16) NOT NULL,
            ua_family varchar(20) NOT NULL,
            PRIMARY KEY  (id),
            KEY consent_id (consent_id),
            KEY created_at (created_at)
        ) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    /** Schedule the daily purge while logging is on, clear it otherwise. */
    public static function sync_schedule() {
        $wanted = !empty(WTA_Consent::settings()['log_enabled']) && WP_Travel_Addons::module_enabled('consent');
        $next   = wp_next_scheduled(self::CRON);

        if ($wanted && !$next) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
        } elseif (!$wanted && $next) {
            wp_clear_scheduled_hook(self::CRON);
        }
    }

    /* ------------------------------------------------------------------ REST */

    public function register_route() {
        register_rest_route(self::ROUTE_NS, self::ROUTE, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'handle'),
            // Public on purpose: a visitor records their own choice. There is
            // no nonce because the page is cached and a nonce would be stale.
            // The input is strictly validated, the origin checked, the rate
            // limited and nothing personal is stored.
            'permission_callback' => '__return_true',
            'args'                => array(
                'id'        => array(
                    'type'              => 'string',
                    'required'          => true,
                    'validate_callback' => array(__CLASS__, 'validate_id'),
                ),
                'version'   => array(
                    'type'              => 'string',
                    'required'          => true,
                    'validate_callback' => array(__CLASS__, 'validate_version'),
                ),
                'analytics' => array('type' => 'boolean', 'required' => true, 'validate_callback' => array(__CLASS__, 'validate_bool')),
                'marketing' => array('type' => 'boolean', 'required' => true, 'validate_callback' => array(__CLASS__, 'validate_bool')),
            ),
        ));
    }

    public static function validate_id($value) {
        return is_string($value) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value);
    }

    public static function validate_version($value) {
        return is_string($value) && (bool) preg_match('/^[A-Za-z0-9._-]{1,20}$/', $value);
    }

    public static function validate_bool($value) {
        return is_bool($value);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle($request) {
        $settings = WTA_Consent::settings();

        if (!WP_Travel_Addons::module_enabled('consent') || empty($settings['log_enabled'])) {
            return new WP_Error('wta_consent_log_disabled', 'Consent logging is not switched on.', array('status' => 404));
        }

        if (!self::origin_ok($request)) {
            return new WP_Error('wta_consent_origin', 'Cross-site requests are not accepted.', array('status' => 403));
        }

        if (!empty($settings['log_respect_dnt']) && self::dnt_requested($request)) {
            return new WP_REST_Response(array('logged' => false, 'reason' => 'dnt'), 202);
        }

        $hash = self::ip_hash();

        if (self::rate_limited($hash)) {
            $response = new WP_Error('wta_consent_rate_limited', 'Too many requests.', array('status' => 429));

            return $response;
        }

        $analytics = (bool) $request->get_param('analytics');
        $marketing = (bool) $request->get_param('marketing');

        $stored = self::record(
            (string) $request->get_param('id'),
            (string) $request->get_param('version'),
            $analytics,
            $marketing,
            $hash,
            self::ua_family((string) $request->get_header('user_agent'))
        );

        if (!$stored) {
            return new WP_Error('wta_consent_store', 'The record could not be stored.', array('status' => 500));
        }

        return new WP_REST_Response(array('logged' => true), 201);
    }

    /** A browser sends Origin on a cross-site POST; it must be this site. */
    public static function origin_ok($request) {
        $header = (string) $request->get_header('origin');

        if ('' === $header) {
            return true;
        }

        $origin = wp_parse_url($header);
        $home   = wp_parse_url(home_url('/'));

        return !empty($origin['host']) && !empty($home['host']) && strtolower($origin['host']) === strtolower($home['host']);
    }

    public static function dnt_requested($request) {
        return '1' === trim((string) $request->get_header('dnt'));
    }

    /* ----------------------------------------------------------- hash, limit */

    /** The client address. Filterable for sites behind a proxy that does not set REMOTE_ADDR. */
    public static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        return (string) apply_filters('wta_consent_client_ip', $ip);
    }

    /** Salted, truncated: 16 hex characters (64 bits). Not reversible without the site's salts. */
    public static function ip_hash($ip = null) {
        $ip = null === $ip ? self::client_ip() : $ip;

        return substr(hash_hmac('sha256', $ip, wp_salt('auth') . '|wta_consent'), 0, 16);
    }

    /** Count a request; true once the hashed address is over the limit. */
    public static function rate_limited($hash) {
        $key   = 'wta_cl_' . $hash;
        $limit = (int) apply_filters('wta_consent_rate_limit', self::RATE_LIMIT);
        $state = get_transient($key);
        $now   = time();

        if (!is_array($state) || !isset($state['n'], $state['t']) || $now - (int) $state['t'] >= self::RATE_WINDOW) {
            $state = array('n' => 0, 't' => $now);
        }

        if ((int) $state['n'] >= $limit) {
            return true;
        }

        $state['n'] = (int) $state['n'] + 1;
        set_transient($key, $state, self::RATE_WINDOW);

        return false;
    }

    /** Browser family only, never the full user agent. */
    public static function ua_family($ua) {
        $map = array(
            'Edge'    => '/\bEdg(e|A|iOS)?\//',
            'Opera'   => '/\b(OPR|Opera)\//',
            'Samsung' => '/SamsungBrowser\//',
            'Firefox' => '/\b(Firefox|FxiOS)\//',
            'Chrome'  => '/\b(Chrome|CriOS)\//',
            'Safari'  => '/\bSafari\//',
        );

        foreach ($map as $family => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $family;
            }
        }

        return 'Other';
    }

    /* ---------------------------------------------------------------- storage */

    public static function choice_of($analytics, $marketing) {
        if ($analytics && $marketing) {
            return 'all';
        }

        return ($analytics || $marketing) ? 'custom' : 'necessary';
    }

    /** Insert one record. */
    public static function record($consent_id, $version, $analytics, $marketing, $ip_hash, $ua_family) {
        global $wpdb;

        self::install();

        $ok = $wpdb->insert(
            self::table(),
            array(
                'consent_id' => $consent_id,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'version'    => $version,
                'analytics'  => $analytics ? 1 : 0,
                'marketing'  => $marketing ? 1 : 0,
                'choice'     => self::choice_of($analytics, $marketing),
                'ip_hash'    => $ip_hash,
                'ua_family'  => $ua_family,
            ),
            array('%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );

        return false !== $ok;
    }

    /** Whether the table exists. */
    public static function table_exists() {
        global $wpdb;

        $table  = self::table();
        $quiet  = $wpdb->suppress_errors(true);
        $result = $wpdb->query("SELECT 1 FROM {$table} LIMIT 1"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->suppress_errors($quiet);

        return false !== $result;
    }

    /**
     * Records in the last N days by choice, for the ability and the admin.
     *
     * @return array{logging: bool, window_days: int, total: int, by_choice: array<string,int>, analytics_granted: int, marketing_granted: int}
     */
    public static function summary($days = 30) {
        global $wpdb;

        $empty = array(
            'logging'           => !empty(WTA_Consent::settings()['log_enabled']),
            'window_days'       => (int) $days,
            'total'             => 0,
            'by_choice'         => array('all' => 0, 'necessary' => 0, 'custom' => 0),
            'analytics_granted' => 0,
            'marketing_granted' => 0,
        );

        if (!self::table_exists()) {
            return $empty;
        }

        $since = gmdate('Y-m-d H:i:s', time() - ((int) $days * DAY_IN_SECONDS));
        $table = self::table();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT choice, COUNT(*) AS n, SUM(analytics) AS a, SUM(marketing) AS m FROM {$table} WHERE created_at >= %s GROUP BY choice", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $since
        ));

        foreach ((array) $rows as $row) {
            if (isset($empty['by_choice'][$row->choice])) {
                $empty['by_choice'][$row->choice] = (int) $row->n;
            }

            $empty['total']             += (int) $row->n;
            $empty['analytics_granted'] += (int) $row->a;
            $empty['marketing_granted'] += (int) $row->m;
        }

        return $empty;
    }

    /** Delete records past retention. Returns the number removed. */
    public static function purge() {
        global $wpdb;

        if (!self::table_exists()) {
            return 0;
        }

        $days   = max(1, (int) apply_filters('wta_consent_log_retention_days', self::RETENTION_DAYS));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $table  = self::table();

        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /* ------------------------------------------------------- privacy tools */

    /**
     * The log holds no email address or account, so it cannot be found from the
     * email WordPress's tools ask about. A site that knows which consent ids
     * belong to a person (for example because a visitor sent theirs) maps the
     * email to ids with the wta_consent_ids_for_email filter.
     *
     * @return string[]
     */
    private static function ids_for_email($email) {
        $ids = apply_filters('wta_consent_ids_for_email', array(), $email);

        return array_values(array_filter((array) $ids, array(__CLASS__, 'validate_id')));
    }

    public function register_exporter($exporters) {
        $exporters['nyuchi-travel-addons-consent'] = array(
            'exporter_friendly_name' => 'Cookie consent records',
            'callback'               => array(__CLASS__, 'export'),
        );

        return $exporters;
    }

    public function register_eraser($erasers) {
        $erasers['nyuchi-travel-addons-consent'] = array(
            'eraser_friendly_name' => 'Cookie consent records',
            'callback'             => array(__CLASS__, 'erase'),
        );

        return $erasers;
    }

    /** @return array{data: array, done: bool} */
    public static function export($email, $page = 1) {
        $items = array();

        foreach (self::rows_for_ids(self::ids_for_email($email)) as $row) {
            $items[] = array(
                'group_id'          => 'wta-consent',
                'group_label'       => 'Cookie consent',
                'group_description' => 'Cookie choices recorded on this site.',
                'item_id'           => 'wta-consent-' . $row->id,
                'data'              => array(
                    array('name' => 'Consent ID', 'value' => $row->consent_id),
                    array('name' => 'Recorded (UTC)', 'value' => $row->created_at),
                    array('name' => 'Policy version', 'value' => $row->version),
                    array('name' => 'Analytics cookies', 'value' => $row->analytics ? 'Allowed' : 'Declined'),
                    array('name' => 'Marketing cookies', 'value' => $row->marketing ? 'Allowed' : 'Declined'),
                    array('name' => 'Browser', 'value' => $row->ua_family),
                ),
            );
        }

        return array('data' => $items, 'done' => true);
    }

    /** @return array{items_removed: int, items_retained: bool, messages: string[], done: bool} */
    public static function erase($email, $page = 1) {
        $removed = self::delete_ids(self::ids_for_email($email));

        return array(
            'items_removed'  => $removed,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /** @return object[] */
    public static function rows_for_ids(array $ids) {
        global $wpdb;

        if (!$ids || !self::table_exists()) {
            return array();
        }

        $table        = self::table();
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));

        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE consent_id IN ({$placeholders}) ORDER BY id ASC", $ids)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** Delete every record of the given consent ids. */
    public static function delete_ids(array $ids) {
        global $wpdb;

        if (!$ids || !self::table_exists()) {
            return 0;
        }

        $table        = self::table();
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));

        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE consent_id IN ({$placeholders})", $ids)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** Suggested text for the site's privacy policy (Settings, Privacy). */
    public function privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $text = '<p class="privacy-policy-tutorial">' . esc_html__('The cookie banner stores your choice in a cookie called wta_consent (and a copy in your browser storage) for the period set in its settings. If consent logging is switched on, a record of the choice is kept on the server: a random identifier, the time, the policy version, your choices, a one-way hash of your IP address and the browser family. It contains no name, email address or full IP address, and is deleted after 13 months. This is a suggestion only; have your policy reviewed.', 'nyuchi-travel-addons') . '</p>';

        wp_add_privacy_policy_content('Nyuchi Travel Addons - cookie consent', wp_kses_post($text));
    }
}
