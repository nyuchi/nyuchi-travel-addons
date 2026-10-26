<?php
/**
 * Boot one WordPress request in a given context, in its own PHP process.
 *
 *     php tests/request.php <context> <scenario>
 *
 * context:  front | admin | cron | rest | cli
 * scenario: check       - wp_update_plugins(), report the update_plugins transient
 *           auto-update - wp_maybe_auto_update(), the wp-cron background updater
 *           auth        - make the requests in WTA_TESTS_URLS, report their headers
 *
 * Why a separate process: whether the updater is registered is decided once,
 * while WordPress loads plugins, from constants such as DOING_CRON and
 * WP_ADMIN that cannot be undefined again. A fresh process per context is the
 * only honest way to test "WordPress checked for updates from wp-cron".
 *
 * Driven by tests/integration/UpdaterContextTest.php, which hands it a copy of
 * the test database and a scratch plugins directory through the environment
 * (see wp-tests-config.php), so an automatic update can overwrite the plugin
 * without touching the working tree. Prints one JSON object between markers.
 *
 * @package WPTravelAddons\Tests
 */

if ('cli' !== PHP_SAPI) {
    exit(1);
}

$wta_context  = isset($argv[1]) ? $argv[1] : 'front';
$wta_scenario = isset($argv[2]) ? $argv[2] : 'check';
$wta_errors   = array();

require __DIR__ . '/includes/wta-test-env.php';
require __DIR__ . '/includes/class-wta-test-http.php';

// Record every notice, warning and deprecation instead of printing it, so a
// test can assert the request raised none from the plugin.
set_error_handler(function ($no, $message, $file, $line) use (&$wta_errors) {
    $wta_errors[] = array('type' => $no, 'message' => $message, 'file' => $file, 'line' => $line);

    return true;
});

switch ($wta_context) {
    case 'cron':
        // What wp-cron.php defines before it loads WordPress.
        define('DOING_CRON', true);
        $_SERVER['REQUEST_URI'] = '/wp-cron.php';
        break;
    case 'rest':
        define('REST_REQUEST', true);
        $_SERVER['REQUEST_URI'] = '/wp-json/wp-abilities/v1/abilities';
        break;
    case 'cli':
        // What WP-CLI defines before it loads WordPress.
        define('WP_CLI', true);
        break;
    case 'admin':
        define('WP_ADMIN', true);
        $_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';
        break;
    case 'front':
        break;
    default:
        fwrite(STDERR, "Unknown context {$wta_context}\n");
        exit(1);
}

if (getenv('WTA_TESTS_TOKEN')) {
    define('WTA_GITHUB_TOKEN', getenv('WTA_TESTS_TOKEN'));
}

$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

$PHP_SELF            = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/wp-tests-config.php';

define('FS_METHOD', 'direct');

// Hooks registered before WordPress loads, the way the test library's
// tests_add_filter() does it.
$wta_hooks = array(
    'pre_http_request'                   => array(array('WTA_Test_HTTP', 'handle'), 3),
    'pre_option_active_plugins'          => array(function () {
        return array(WTA_TEST_PLUGIN);
    }, 1),
    // The scratch directory may sit inside a checkout on a developer machine,
    // and WordPress refuses to auto-update anything under version control.
    // That is a property of where the test runs, not of the plugin.
    'automatic_updates_is_vcs_checkout'  => array('__return_false', 1),
    'auto_plugin_update_send_email'      => array('__return_false', 1),
    'auto_theme_update_send_email'       => array('__return_false', 1),
    'auto_core_update_send_email'        => array('__return_false', 1),
    'send_core_update_notification_email' => array('__return_false', 1),
    'auto_update_core'                   => array('__return_false', 1),
    'auto_update_translation'            => array('__return_false', 1),
);

foreach ($wta_hooks as $wta_hook => $wta_spec) {
    $GLOBALS['wp_filter'][$wta_hook][10][] = array('function' => $wta_spec[0], 'accepted_args' => $wta_spec[1]);
}

require ABSPATH . 'wp-settings.php';

// --------------------------------------------------------------- mocked network

$wta_release = getenv('WTA_TESTS_RELEASE') ? json_decode(getenv('WTA_TESTS_RELEASE'), true) : WTA_Test_HTTP::release();
WTA_Test_HTTP::serve_release($wta_release);

$wta_package = getenv('WTA_TESTS_PACKAGE');

if ($wta_package) {
    $wta_zip = WTA_Test_HTTP::response(file_get_contents($wta_package), 200, array('content-type' => 'application/zip'));
    WTA_Test_HTTP::on(WTA_Test_HTTP::asset_download_url($wta_release['tag_name']), $wta_zip);
    WTA_Test_HTTP::on(WTA_Test_HTTP::asset_api_url(), $wta_zip);
}

// The loopback request the automatic updater makes to look for a fatal error
// in the freshly updated plugin. A page without the scrape marker means none.
WTA_Test_HTTP::on(home_url('/'), WTA_Test_HTTP::response('<html></html>'));

// ------------------------------------------------------------------- scenario

if (getenv('WTA_TESTS_AUTO_UPDATE')) {
    update_site_option('auto_update_plugins', array(WTA_TEST_PLUGIN));
}

$wta_hooked = 0;

if (isset($GLOBALS['wp_filter']['pre_set_site_transient_update_plugins'])) {
    foreach ($GLOBALS['wp_filter']['pre_set_site_transient_update_plugins']->callbacks as $wta_callbacks) {
        foreach ($wta_callbacks as $wta_cb) {
            if (is_array($wta_cb['function']) && $wta_cb['function'][0] instanceof WTA_Updater) {
                $wta_hooked++;
            }
        }
    }
}

$wta_out = array(
    'context'        => $wta_context,
    'scenario'       => $wta_scenario,
    'is_admin'       => is_admin(),
    'doing_cron'     => wp_doing_cron(),
    'updater_hooked' => $wta_hooked,
    'response'       => null,
);

if ('check' === $wta_scenario || 'auto-update' === $wta_scenario) {
    delete_site_transient('update_plugins');
    delete_site_transient('wta_release_check');

    if ('check' === $wta_scenario) {
        wp_update_plugins();
    } else {
        $wta_out['version_before'] = get_plugin_data(WP_PLUGIN_DIR . '/' . WTA_TEST_PLUGIN, false, false)['Version'];
        wp_maybe_auto_update();
        clearstatcache();
        $wta_out['version_after'] = get_plugin_data(WP_PLUGIN_DIR . '/' . WTA_TEST_PLUGIN, false, false)['Version'];
    }

    $wta_transient = get_site_transient('update_plugins');

    if (is_object($wta_transient) && isset($wta_transient->response[WTA_TEST_PLUGIN])) {
        $wta_out['response'] = (array) $wta_transient->response[WTA_TEST_PLUGIN];
    }
}

if ('auth' === $wta_scenario) {
    $wta_out['headers'] = array();

    foreach (json_decode((string) getenv('WTA_TESTS_URLS'), true) as $wta_url) {
        WTA_Test_HTTP::on($wta_url, WTA_Test_HTTP::response('ok'));
        wp_remote_get($wta_url, array('headers' => array('Accept' => 'text/plain')));
        $wta_seen = WTA_Test_HTTP::requests_to($wta_url);
        $wta_out['headers'][$wta_url] = $wta_seen ? end($wta_seen)['args']['headers'] : null;
    }
}

$wta_out['requests'] = array_map(function ($r) {
    return $r['url'];
}, WTA_Test_HTTP::$requests);

$wta_out['errors'] = $wta_errors;

echo "\n<<<WTA-RESULT\n" . json_encode($wta_out) . "\nWTA-RESULT>>>\n";
