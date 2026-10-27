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

    const SCRIPT_HANDLE = 'wta-login-otp';

    public function __construct() {
        add_action('workos_authkit_enqueue_assets', array($this, 'enqueue'));
        add_action('workos_authkit_enqueue_assets', array($this, 'enqueue_script'));
        add_action('login_enqueue_scripts', array($this, 'enqueue'));
    }

    /**
     * The one-time code field as separate boxes. The script keeps the page's
     * own single field in step (so pasting, autofill and the page's validation
     * all still work) and submits when the last digit is typed.
     *
     * @return string
     */
    public function script() {
        /**
         * @param string $js The login page script. Return an empty string to keep the plain code field.
         */
        return (string) apply_filters('wta_login_style_js', self::JS);
    }

    /**
     * @return void
     */
    public function enqueue_script() {
        $js = $this->script();

        if ('' === $js) {
            return;
        }

        wp_register_script(self::SCRIPT_HANDLE, false, array(), WTA_VERSION, true);
        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(self::SCRIPT_HANDLE, $js);
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
body.workos-authkit-body #workos-authkit-root .wta-otp{display:flex;gap:.6rem;justify-content:center;margin:.25rem 0 1rem}
body.workos-authkit-body #workos-authkit-root .wta-otp input{box-sizing:border-box;width:3rem;max-width:calc((100% - 3rem) / 6);height:3.5rem;min-height:3.5rem;padding:0;text-align:center;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:1.5rem;font-weight:600;color:#152744;background:#fff;border:1px solid rgba(21,39,68,.25);border-radius:12px}
body.workos-authkit-body #workos-authkit-root .wta-otp input:focus{outline:2px solid #BAA18C;outline-offset:2px;border-color:#152744}
body.workos-authkit-body #workos-authkit-root input.wta-otp-source{position:absolute!important;width:1px!important;height:1px!important;min-height:0!important;margin:-1px!important;padding:0!important;border:0!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;opacity:0!important;pointer-events:none!important}
CSS;

    const JS = <<<'JS'
(function () {
  var SEL = 'input[autocomplete="one-time-code"]';
  var setValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;

  function enhance(src) {
    if (src.getAttribute('data-wta-otp')) { return; }
    src.setAttribute('data-wta-otp', '1');
    var length = src.maxLength > 0 && src.maxLength < 12 ? src.maxLength : 6;
    var group = document.createElement('div');
    group.className = 'wta-otp';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', 'Verification code');
    var boxes = [];
    for (var i = 0; i < length; i++) {
      var box = document.createElement('input');
      box.type = 'text';
      box.inputMode = 'numeric';
      box.autocomplete = 'off';
      box.maxLength = 1;
      box.setAttribute('aria-label', 'Digit ' + (i + 1) + ' of ' + length);
      box.setAttribute('data-i', String(i));
      boxes.push(box);
      group.appendChild(box);
    }
    src.classList.add('wta-otp-source');
    src.tabIndex = -1;
    src.parentNode.insertBefore(group, src);

    function code() { return boxes.map(function (b) { return b.value; }).join(''); }
    function push() {
      var value = code();
      if (src.value === value) { return; }
      setValue.call(src, value);
      src.dispatchEvent(new Event('input', { bubbles: true }));
      if (value.length === length && src.form && typeof src.form.requestSubmit === 'function') {
        setTimeout(function () { if (code().length === length) { src.form.requestSubmit(); } }, 120);
      }
    }
    function fill(from, text) {
      var digits = String(text).replace(/\D/g, '').slice(0, length - from).split('');
      digits.forEach(function (d, k) { boxes[from + k].value = d; });
      var next = Math.min(from + digits.length, length - 1);
      boxes[next].focus();
      push();
    }

    boxes.forEach(function (box, i) {
      box.addEventListener('input', function () {
        var digits = box.value.replace(/\D/g, '');
        box.value = '';
        if (digits) { fill(i, digits); } else { push(); }
      });
      box.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !box.value && i > 0) { boxes[i - 1].value = ''; boxes[i - 1].focus(); push(); e.preventDefault(); }
        else if (e.key === 'ArrowLeft' && i > 0) { boxes[i - 1].focus(); e.preventDefault(); }
        else if (e.key === 'ArrowRight' && i < length - 1) { boxes[i + 1].focus(); e.preventDefault(); }
      });
      box.addEventListener('paste', function (e) {
        var text = (e.clipboardData || window.clipboardData).getData('text');
        e.preventDefault();
        fill(0, text);
      });
      box.addEventListener('focus', function () { box.select(); });
    });

    // The page clears the field after a wrong code: clear the boxes with it.
    var timer = setInterval(function () {
      if (!document.body.contains(src)) { clearInterval(timer); return; }
      if (src.value === '' && code() !== '') { boxes.forEach(function (b) { b.value = ''; }); boxes[0].focus(); }
    }, 400);

    boxes[0].focus();
  }

  function scan() { Array.prototype.forEach.call(document.querySelectorAll(SEL), enhance); }
  new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true });
  scan();
})();
JS;
}
