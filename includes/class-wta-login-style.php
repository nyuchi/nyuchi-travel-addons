<?php
/**
 * Brands the sign-in page: the Iconic sand background, display and body fonts,
 * navy pill button and white card, on the WorkOS sign-in page
 * (Integration with WorkOS, custom login mode) and on the standard
 * wp-login.php form.
 *
 * The WorkOS page prints only an explicit queue of styles, so the stylesheet is
 * enqueued on the plugin's own `workos_authkit_enqueue_assets` action. The
 * fonts are the ones the site already serves (Cloudflare Fonts), with system
 * fonts as the fallback.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WTA_Login_Style {

    const HANDLE = 'wta-login-style';

    public function __construct() {
        add_action('workos_authkit_enqueue_assets', array($this, 'enqueue'));
        add_action('login_enqueue_scripts', array($this, 'enqueue'));
    }

    /**
     * The stylesheet. Filterable so a site can adjust the colours or fonts.
     *
     * @return string
     */
    public function css() {
        /**
         * @param string $css The login page CSS.
         */
        return (string) apply_filters('wta_login_style_css', self::CSS);
    }

    /**
     * Register the (empty) handle and attach the CSS inline, so there is no
     * extra request and no file to cache.
     *
     * @return void
     */
    public function enqueue() {
        wp_register_style(self::HANDLE, false, array(), WTA_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, $this->css());
    }

    const CSS = <<<'CSS'
@font-face{font-family:"Bricolage Grotesque";font-style:normal;font-weight:600;font-display:swap;src:url(/cf-fonts/v/bricolage-grotesque/5.2.10/latin/opsz/normal.woff2) format("woff2")}
@font-face{font-family:"Instrument Sans";font-style:normal;font-weight:400;font-display:swap;src:url(/cf-fonts/v/instrument-sans/5.2.8/latin/wght/normal.woff2) format("woff2")}
@font-face{font-family:"Instrument Sans";font-style:normal;font-weight:600;font-display:swap;src:url(/cf-fonts/v/instrument-sans/5.2.8/latin/wght/normal.woff2) format("woff2")}
body.workos-authkit-body,body.login{background:#F4EFE6;color:#152744;font-family:"Instrument Sans",system-ui,-apple-system,"Segoe UI",sans-serif;border-top:3px solid #BAA18C;min-height:100vh}
body.workos-authkit-body #workos-authkit-root .wa-logo{width:200px!important;max-width:70%;height:auto!important}
body.workos-authkit-body #workos-authkit-root .wa-card{background:#fff;border:1px solid rgba(21,39,68,.12);border-radius:16px;box-shadow:0 10px 30px rgba(21,39,68,.08);padding:32px}
body.workos-authkit-body #workos-authkit-root .wa-heading{font-family:"Bricolage Grotesque","Instrument Sans",system-ui,sans-serif;font-weight:600;font-size:26px;line-height:1.2;letter-spacing:-.01em;color:#152744}
body.workos-authkit-body #workos-authkit-root .wa-subheading{font-size:15px;line-height:1.55;color:#4a5568}
body.workos-authkit-body #workos-authkit-root .wa-btn{border-radius:9999px;min-height:48px;font-family:"Instrument Sans",system-ui,sans-serif;font-weight:600;font-size:16px;letter-spacing:.01em}
body.workos-authkit-body #workos-authkit-root .wa-btn-primary{background:#152744;color:#F4EFE6;border-color:#152744}
body.workos-authkit-body #workos-authkit-root .wa-btn-primary:hover{background:#5C4326;border-color:#5C4326}
body.workos-authkit-body #workos-authkit-root input{border-radius:9999px;min-height:48px;padding:0 20px;border:1px solid rgba(21,39,68,.25);font-family:inherit;font-size:16px}
body.workos-authkit-body #workos-authkit-root input:focus{outline:2px solid #BAA18C;outline-offset:2px;border-color:#152744}
body.workos-authkit-body #workos-authkit-root a{color:#152744;text-underline-offset:3px}
body.workos-authkit-body #workos-authkit-root .wa-below-card-back{color:#5C4326;font-size:14px}
body.workos-authkit-body :focus-visible{outline:2px solid #BAA18C;outline-offset:2px}
@media (max-width:480px){body.workos-authkit-body #workos-authkit-root .wa-card{padding:24px 20px}}
CSS;
}
