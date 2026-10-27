<?php
/**
 * WorkOS second-factor step: a mfa_challenge error becomes the MFA-required response.
 *
 * @package WPTravelAddons\Tests
 */

class WorkOSMfaTest extends WP_UnitTestCase {

    protected function challenge_error($code = 'mfa_challenge', $token = 'pat_123') {
        return new WP_Error('workos_api_error', 'The user must complete an MFA challenge to finish authenticating.', array(
            'status' => 403,
            'body'   => array(
                'code'                         => $code,
                'pending_authentication_token' => $token,
                'authentication_factors'       => array(array('id' => 'auth_factor_1', 'type' => 'totp')),
            ),
        ));
    }

    public function test_an_mfa_challenge_error_becomes_the_mfa_required_payload() {
        $payload = WTA_WorkOS_MFA::payload_from_error($this->challenge_error());

        $this->assertTrue($payload['mfa_required']);
        $this->assertSame('pat_123', $payload['pending_authentication_token']);
        $this->assertSame('auth_factor_1', $payload['factors'][0]['id']);
    }

    public function test_other_errors_are_left_alone() {
        $this->assertNull(WTA_WorkOS_MFA::payload_from_error($this->challenge_error('invalid_credentials')));
        $this->assertNull(WTA_WorkOS_MFA::payload_from_error(new WP_Error('x', 'y')));
    }

    public function test_a_challenge_without_a_pending_token_is_not_converted() {
        $this->assertNull(WTA_WorkOS_MFA::payload_from_error($this->challenge_error('mfa_challenge', '')));
    }

    public function test_only_the_workos_sign_in_routes_are_touched() {
        $mfa = new WTA_WorkOS_MFA();

        $sign_in = $mfa->filter_response($this->challenge_error(), array(), new WP_REST_Request('POST', '/workos/v1/auth/magic-code/verify'));
        $this->assertInstanceOf(WP_REST_Response::class, $sign_in);
        $this->assertSame(200, $sign_in->get_status());

        $other = $mfa->filter_response($this->challenge_error(), array(), new WP_REST_Request('POST', '/wp/v2/posts'));
        $this->assertInstanceOf(WP_Error::class, $other);
    }
}
