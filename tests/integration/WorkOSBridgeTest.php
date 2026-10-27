<?php
/**
 * WorkOS cookie bridge: one-time hand-over of the final sign-in step to wp-login.php.
 *
 * @package WPTravelAddons\Tests
 */

class WorkOSBridgeTest extends WP_UnitTestCase {

    protected $bridge;

    public function set_up() {
        parent::set_up();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit browser';
        $this->bridge               = new WTA_WorkOS_Bridge();
    }

    protected function verify_response($redirect = 'https://example.org/wp-admin/') {
        return new WP_REST_Response(array('success' => true, 'redirect_to' => $redirect));
    }

    protected function token_from($url) {
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
        return $q[WTA_WorkOS_Bridge::QUERY];
    }

    public function test_a_token_is_single_use() {
        $user  = self::factory()->user->create();
        $token = $this->bridge->issue($user);

        $this->assertSame($user, $this->bridge->redeem($token));
        $this->assertSame(0, $this->bridge->redeem($token));
    }

    public function test_a_token_is_tied_to_the_browser() {
        $user  = self::factory()->user->create();
        $token = $this->bridge->issue($user);

        $_SERVER['HTTP_USER_AGENT'] = 'Someone else';

        $this->assertSame(0, $this->bridge->redeem($token));
    }

    public function test_junk_tokens_are_refused() {
        $this->assertSame(0, $this->bridge->redeem(''));
        $this->assertSame(0, $this->bridge->redeem('not-a-token'));
        $this->assertSame(0, $this->bridge->redeem(str_repeat('a', 48)));
    }

    public function test_the_rest_redirect_is_pointed_at_wp_login() {
        $user = self::factory()->user->create();
        $this->bridge->issue($user);

        $request = new WP_REST_Request('POST', '/workos/v1/auth/magic-code/verify');
        $result  = $this->bridge->rewrite_rest($this->verify_response(), array(), $request);

        $url = $result->data['redirect_to'];
        $this->assertStringContainsString('wp-login.php', $url);
        $this->assertSame($user, $this->bridge->redeem($this->token_from($url)));
    }

    public function test_nothing_is_rewritten_without_an_authenticated_user() {
        $request = new WP_REST_Request('POST', '/workos/v1/auth/magic-code/verify');
        $result  = $this->bridge->rewrite_rest($this->verify_response(), array(), $request);

        $this->assertSame('https://example.org/wp-admin/', $result->data['redirect_to']);
    }

    public function test_other_routes_are_not_touched() {
        $this->bridge->issue(self::factory()->user->create());

        $request = new WP_REST_Request('POST', '/wp/v2/posts');
        $result  = $this->bridge->rewrite_rest($this->verify_response(), array(), $request);

        $this->assertSame('https://example.org/wp-admin/', $result->data['redirect_to']);
    }

    public function test_the_original_destination_survives_the_round_trip() {
        $this->bridge->issue(self::factory()->user->create());

        $url = $this->bridge->rewrite_redirect('https://example.org/wp-admin/edit.php?post_type=page', 302);

        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('https://example.org/wp-admin/edit.php?post_type=page', rawurldecode($q['redirect_to']));
    }
}
