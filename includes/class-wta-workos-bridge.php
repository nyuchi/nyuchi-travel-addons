<?php
/**
 * Finishes a WorkOS sign-in on wp-login.php, where the login cookie survives.
 *
 * On this host the login cookie that Integration with WorkOS sets is lost on
 * the plugin's own sign-in routes (the REST call and /workos/callback): WordPress
 * creates the session, but the browser never receives the cookie, so the next
 * request lands back on the sign-in page. wp-login.php itself sets cookies fine.
 *
 * So after WorkOS has authenticated the user, this module hands the final step to
 * wp-login.php with a one-time token, and wp-login.php sets the cookie. The token
 * is created only inside the plugin's `workos_user_authenticated` action (the user
 * has just passed WorkOS, including any second factor), is random, single use,
 * valid for 90 seconds and tied to the browser that signed in.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_WorkOS_Bridge {

    const QUERY = 'wta_sso';

    const TTL = 90;

    const ROUTE_PREFIX = '/workos/v1/auth/';

    /** Token issued during this request, waiting to be put into the redirect. */
    protected static $pending = '';

    public function __construct() {
        add_action('workos_user_authenticated', array($this, 'issue'), 10, 1);
        add_filter('rest_request_after_callbacks', array($this, 'rewrite_rest'), 20, 3);
        add_filter('wp_redirect', array($this, 'rewrite_redirect'), 1, 2);
        add_action('login_init', array($this, 'consume'), 1);
    }

    /* ------------------------------------------------------------- issuing */

    /**
     * Create a one-time token for the user WorkOS has just authenticated.
     *
     * @param int $user_id WordPress user ID.
     * @return string The token.
     */
    public function issue($user_id) {
        $token = bin2hex(random_bytes(24));

        set_transient(self::key($token), array(
            'user' => (int) $user_id,
            'ua'   => self::ua_hash(),
        ), self::TTL);

        self::$pending = $token;

        return $token;
    }

    protected static function key($token) {
        return 'wta_sso_' . hash('sha256', (string) $token);
    }

    protected static function ua_hash() {
        return hash('sha256', isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '');
    }

    /**
     * The wp-login.php address that finishes the sign-in.
     *
     * @param string $token Token.
     * @param string $to    Where to go afterwards.
     * @return string
     */
    public static function url($token, $to) {
        return add_query_arg(array(
            self::QUERY   => $token,
            'redirect_to' => rawurlencode((string) $to),
        ), site_url('wp-login.php', 'login'));
    }

    /* ------------------------------------------------------- the hand-over */

    /**
     * Point the sign-in REST response at wp-login.php.
     *
     * @param mixed           $response REST result.
     * @param array           $handler  Handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public function rewrite_rest($response, $handler, $request) {
        if ('' === self::$pending || !($request instanceof WP_REST_Request) || 0 !== strpos((string) $request->get_route(), self::ROUTE_PREFIX)) {
            return $response;
        }

        if ($response instanceof WP_REST_Response && is_array($response->data) && isset($response->data['redirect_to']) && is_string($response->data['redirect_to'])) {
            $data                = $response->data;
            $data['redirect_to'] = self::url(self::$pending, $data['redirect_to']);
            $response->set_data($data);
            self::$pending = '';
        }

        return $response;
    }

    /**
     * Same for the hosted-page callback, which finishes with a redirect.
     *
     * @param string $location Redirect target.
     * @param int    $status   Status.
     * @return string
     */
    public function rewrite_redirect($location, $status = 302) {
        if ('' === self::$pending || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $location;
        }

        $token         = self::$pending;
        self::$pending = '';

        return self::url($token, $location);
    }

    /* ---------------------------------------------------------- redeeming */

    /**
     * Spend a token: the user ID it was issued for, or 0.
     *
     * @param string $token Token from the query string.
     * @return int
     */
    public function redeem($token) {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) $token));

        if ('' === $token) {
            return 0;
        }

        $key = self::key($token);
        $rec = get_transient($key);
        delete_transient($key); // Single use, whatever happens next.

        if (!is_array($rec) || empty($rec['user']) || !hash_equals((string) ($rec['ua'] ?? ''), self::ua_hash())) {
            return 0;
        }

        return get_userdata((int) $rec['user']) ? (int) $rec['user'] : 0;
    }

    /**
     * On wp-login.php: set the login cookie for a valid token and move on.
     *
     * @return void
     */
    public function consume() {
        if (empty($_GET[self::QUERY])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the one-time token is the credential.
            return;
        }

        nocache_headers();

        $user_id = $this->redeem(wp_unslash($_GET[self::QUERY])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ($user_id <= 0) {
            wp_safe_redirect(site_url('wp-login.php', 'login'));
            exit;
        }

        wp_set_auth_cookie($user_id, true);
        wp_set_current_user($user_id);

        $to = isset($_GET['redirect_to']) ? rawurldecode((string) wp_unslash($_GET['redirect_to'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        wp_safe_redirect(wp_validate_redirect($to, admin_url()));
        exit;
    }
}
