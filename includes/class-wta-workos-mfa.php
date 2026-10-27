<?php
/**
 * Lets the WorkOS sign-in page ask for the second factor.
 *
 * With multi-factor sign-in required, WorkOS answers a correct email code (or
 * password) with an error whose code is `mfa_challenge`, carrying the pending
 * authentication token and the user's enrolled factors. The Integration with
 * WorkOS plugin (1.0.x) only expects that as a successful response, so its
 * sign-in page shows the error text and stops: "The user must complete an MFA
 * challenge to finish authenticating."
 *
 * This turns that one error into the response the sign-in page already
 * understands, so it shows the authenticator-code step. Nothing is relaxed:
 * the user is still not signed in until WorkOS accepts the second factor on
 * the plugin's /auth/mfa/verify route.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_WorkOS_MFA {

    /** REST routes this applies to (the plugin's sign-in endpoints). */
    const ROUTE_PREFIX = '/workos/v1/auth/';

    public function __construct() {
        add_filter('rest_request_after_callbacks', array($this, 'filter_response'), 10, 3);
    }

    /**
     * Replace a WorkOS `mfa_challenge` error with the MFA-required response.
     *
     * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result of the route callback.
     * @param array                                           $handler  Route handler.
     * @param WP_REST_Request                                 $request  Request.
     * @return mixed
     */
    public function filter_response($response, $handler, $request) {
        if (!is_wp_error($response) || !($request instanceof WP_REST_Request)) {
            return $response;
        }

        if (0 !== strpos((string) $request->get_route(), self::ROUTE_PREFIX)) {
            return $response;
        }

        $payload = self::payload_from_error($response);

        return null === $payload ? $response : new WP_REST_Response($payload, 200);
    }

    /**
     * The MFA-required payload for a WorkOS `mfa_challenge` error, or null for
     * any other error.
     *
     * @param WP_Error $error Error from the plugin's WorkOS client.
     * @return array|null
     */
    public static function payload_from_error($error) {
        $data = $error->get_error_data();
        $body = is_array($data) && isset($data['body']) && is_array($data['body']) ? $data['body'] : array();

        if ('mfa_challenge' !== (string) ($body['code'] ?? '')) {
            return null;
        }

        $token = (string) ($body['pending_authentication_token'] ?? '');
        if ('' === $token) {
            return null;
        }

        if (isset($body['authentication_factors']) && is_array($body['authentication_factors'])) {
            $factors = $body['authentication_factors'];
        } elseif (isset($body['authentication_factor']) && is_array($body['authentication_factor'])) {
            $factors = array($body['authentication_factor']);
        } else {
            $factors = array();
        }

        return array(
            'mfa_required'                 => true,
            'pending_authentication_token' => $token,
            'factors'                      => array_values($factors),
        );
    }
}
