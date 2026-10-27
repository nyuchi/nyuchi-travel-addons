<?php
/**
 * The Consent tab: settings for the cookie consent module, a live preview, and
 * the other consent tools that would show a second banner.
 *
 * Included from admin-page.php, so $this is the WTA_Admin instance and the
 * wta_switch() helper is defined.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

$consent_settings  = WTA_Consent::settings();
$consent_enabled   = WP_Travel_Addons::module_enabled('consent');
$consent_scan      = WTA_Consent::last_scan();
$consent_conflicts = $consent_scan && !empty($consent_scan['ok']) ? $consent_scan['conflicts'] : WTA_Consent::detect_conflicts();
$consent_records   = WTA_Consent_Log::summary(30);

$consent_preview            = WTA_Consent::client_config();
$consent_preview['preview'] = true;
$consent_preview['pixel']   = '';
$consent_preview['log']     = array('enabled' => false, 'url' => '', 'dnt' => true);

/** A switch that always submits a value, so an unchecked box means "off". */
$consent_flag = function ($key, $label, $help = '') use ($consent_settings) {
    ?>
    <input type="hidden" name="wta_consent[<?php echo esc_attr($key); ?>]" value="0">
    <?php wta_switch('wta_consent[' . $key . ']', !empty($consent_settings[$key]), $label, $help); ?>
    <?php
};
?>

<?php if ($consent_enabled && $consent_conflicts) : ?>
    <div class="nyx-alert is-bad">
        <strong>Another consent tool is present, so visitors may see two banners.</strong>
        To avoid a gap, switch these off in this order: turn this module on and check the banner on the live
        site, then switch the other tool's banner off, then remove the tags it was loading.
        <ul>
            <?php foreach ($consent_conflicts as $conflict) : ?>
                <li><strong><?php echo esc_html($conflict['label']); ?></strong>
                    (<?php echo esc_html($conflict['how']); ?>). <?php echo esc_html($conflict['fix']); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="nyx-card">
    <h2>Other consent tools</h2>
    <p class="nyx-card-sub">
        Two banners ask visitors twice and can disagree. Plugins are detected from the active plugin list. Scripts such
        as Usercentrics or Cloudflare Zaraz are not plugins, so they are found by reading the live home page.
    </p>
    <?php if ($consent_scan) : ?>
        <p>
            Last scan <?php echo esc_html(human_time_diff((int) $consent_scan['time'])); ?> ago:
            <?php if (!$consent_scan['ok']) : ?>
                could not read the home page (<?php echo esc_html($consent_scan['error']); ?>).
            <?php elseif (!$consent_scan['conflicts']) : ?>
                no other consent tool found.
            <?php else : ?>
                <?php echo esc_html(implode(', ', wp_list_pluck($consent_scan['conflicts'], 'label'))); ?> found.
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(WTA_Admin::admin_url_for('consent')); ?>" class="nyx-actions is-inline">
        <?php wp_nonce_field('wta_scan_consent', 'wta_consent_scan_nonce'); ?>
        <input type="hidden" name="wta_form" value="consent_scan">
        <button type="submit" class="nyx-btn">Scan the live home page</button>
    </form>
</section>

<form method="post" action="<?php echo esc_url(WTA_Admin::admin_url_for('consent')); ?>" id="wta-consent-form">
    <?php wp_nonce_field('wta_save_consent', 'wta_consent_nonce'); ?>
    <input type="hidden" name="wta_form" value="consent">

    <section class="nyx-card">
        <h2>Cookie consent</h2>
        <p class="nyx-card-sub">
            A banner with Accept, Decline and Manage, and Google Consent Mode v2 signalling. Off by default. This is a
            tool, not legal advice: have the wording reviewed for GDPR and POPIA.
        </p>
        <?php wta_switch('module_consent', $consent_enabled, 'Enable the cookie consent banner', 'Prints the Consent Mode defaults in the head and the banner on public pages. Nothing is printed in wp-admin or the Elementor editor.'); ?>

        <div class="nyx-grid">
            <div class="nyx-field nyx-span-2">
                <label for="wta-consent-text">Banner text</label>
                <textarea id="wta-consent-text" name="wta_consent[text]" rows="3" maxlength="600" class="large-text"><?php echo esc_textarea($consent_settings['text']); ?></textarea>
                <p class="nyx-help">Plain text. Say what the cookies are for; do not promise legal compliance.</p>
            </div>
            <div class="nyx-field">
                <label for="wta-consent-privacy-url">Privacy policy link</label>
                <input type="text" id="wta-consent-privacy-url" name="wta_consent[privacy_url]" value="<?php echo esc_attr($consent_settings['privacy_url']); ?>">
                <p class="nyx-help">A path such as <code>/privacy-policy/</code> or a full https address. The page must exist.</p>
            </div>
            <div class="nyx-field">
                <label for="wta-consent-privacy-label">Link text</label>
                <input type="text" id="wta-consent-privacy-label" name="wta_consent[privacy_label]" maxlength="40" value="<?php echo esc_attr($consent_settings['privacy_label']); ?>">
            </div>
            <div class="nyx-field">
                <label for="wta-consent-position">Position</label>
                <select id="wta-consent-position" name="wta_consent[position]" class="nyx-select">
                    <option value="bottom" <?php selected($consent_settings['position'], 'bottom'); ?>>Bottom bar</option>
                    <option value="bottom-left" <?php selected($consent_settings['position'], 'bottom-left'); ?>>Bottom left card</option>
                    <option value="bottom-right" <?php selected($consent_settings['position'], 'bottom-right'); ?>>Bottom right card</option>
                </select>
                <p class="nyx-help">On phones it is always a bottom sheet.</p>
            </div>
            <div class="nyx-field">
                <label for="wta-consent-lifetime">Consent lifetime (days)</label>
                <input type="number" min="1" max="395" id="wta-consent-lifetime" name="wta_consent[lifetime_days]" value="<?php echo esc_attr($consent_settings['lifetime_days']); ?>">
                <p class="nyx-help">How long a choice is remembered before asking again. At most 395 days (13 months).</p>
            </div>
            <div class="nyx-field">
                <label for="wta-consent-version">Policy version</label>
                <input type="text" id="wta-consent-version" name="wta_consent[policy_version]" maxlength="20" value="<?php echo esc_attr($consent_settings['policy_version']); ?>">
                <p class="nyx-help">Change it (for example 1 to 2) after you change what cookies do or your policy, and every visitor is asked again.</p>
            </div>
        </div>
    </section>

    <section class="nyx-card">
        <h2>Categories</h2>
        <p class="nyx-card-sub">Necessary cookies are always on. Switch a category off to hide it from visitors.</p>
        <div class="nyx-grid">
            <div class="nyx-field nyx-span-2">
                <label for="wta-consent-necessary-desc">Necessary description (always on)</label>
                <textarea id="wta-consent-necessary-desc" name="wta_consent[necessary_desc]" rows="2" maxlength="300" class="large-text"><?php echo esc_textarea($consent_settings['necessary_desc']); ?></textarea>
            </div>
            <div class="nyx-span-2">
                <?php $consent_flag('analytics_on', 'Analytics', 'Show this category to visitors.'); ?>
                <div class="nyx-field">
                <label for="wta-consent-analytics-desc">Analytics description</label>
                <textarea id="wta-consent-analytics-desc" name="wta_consent[analytics_desc]" rows="2" maxlength="300" class="large-text"><?php echo esc_textarea($consent_settings['analytics_desc']); ?></textarea>
                </div>
            </div>
            <div class="nyx-span-2">
                <?php $consent_flag('marketing_on', 'Marketing', 'Show this category to visitors.'); ?>
                <div class="nyx-field">
                <label for="wta-consent-marketing-desc">Marketing description</label>
                <textarea id="wta-consent-marketing-desc" name="wta_consent[marketing_desc]" rows="2" maxlength="300" class="large-text"><?php echo esc_textarea($consent_settings['marketing_desc']); ?></textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="nyx-card">
        <h2>Preview</h2>
        <p class="nyx-card-sub">The real banner, drawn from the fields above. Nothing here is saved or stored.</p>
        <p>
            <button type="button" class="nyx-btn" id="wta-consent-prev-bar">Banner</button>
            <button type="button" class="nyx-btn" id="wta-consent-prev-panel">Manage panel</button>
        </p>
        <div id="wta-consent-preview" style="background:#F4EFE6;padding:24px;border-radius:14px;min-height:200px"></div>
        <script type="application/json" id="wta-consent-config"><?php echo wp_json_encode($consent_preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
    </section>

    <section class="nyx-card">
        <h2>Google Consent Mode</h2>
        <p class="nyx-card-sub">
            Before a choice the page tells Google tags that ad_storage, ad_user_data, ad_personalization and
            analytics_storage are denied (functionality and security storage are granted), and asks them to wait 500 ms
            for an update. Denied everywhere is the default. To start granted in chosen places, list ISO 3166 codes
            (for example <code>US, US-CA</code>); Google applies the most specific match. The banner still appears
            everywhere, and a visitor's choice replaces the default.
        </p>
        <div class="nyx-grid">
            <div class="nyx-field">
                <label for="wta-consent-regions-a">Analytics granted by default in</label>
                <input type="text" id="wta-consent-regions-a" name="wta_consent[regions_analytics]" value="<?php echo esc_attr($consent_settings['regions_analytics']); ?>" placeholder="empty: denied everywhere">
            </div>
            <div class="nyx-field">
                <label for="wta-consent-regions-m">Marketing granted by default in</label>
                <input type="text" id="wta-consent-regions-m" name="wta_consent[regions_marketing]" value="<?php echo esc_attr($consent_settings['regions_marketing']); ?>" placeholder="empty: denied everywhere">
            </div>
        </div>
        <p class="nyx-help">
            This module does not load Google Tag Manager. Site Kit or Elementor already do, and the container has to
            read Consent Mode; see the Consent section of the README.
        </p>
    </section>

    <section class="nyx-card">
        <h2>Meta Pixel</h2>
        <div class="nyx-grid">
            <div class="nyx-field">
                <label for="wta-consent-pixel">Pixel ID</label>
                <input type="text" id="wta-consent-pixel" name="wta_consent[meta_pixel_id]" inputmode="numeric" value="<?php echo esc_attr($consent_settings['meta_pixel_id']); ?>" placeholder="empty: off">
                <p class="nyx-help">
                    Loads Meta's script only after the visitor allows marketing cookies, and tells it when they take that
                    back. Leave empty if Zaraz, GTM or another plugin already loads the pixel; two loaders double-count.
                </p>
            </div>
        </div>
    </section>

    <section class="nyx-card">
        <h2>Consent log</h2>
        <p class="nyx-card-sub">
            Off by default. When on, each choice is stored for 13 months: a random id, the time, the policy version, the
            two choices, a salted and truncated hash of the IP address and the browser family. No name, email or full
            IP address.
        </p>
        <?php
        $consent_flag('log_enabled', 'Keep a consent log');
        $consent_flag('log_respect_dnt', 'Do not log visitors who send Do Not Track');
        ?>
        <p class="nyx-help">
            Last 30 days:
            <?php echo esc_html($consent_records['total']); ?> records
            (accept all <?php echo esc_html($consent_records['by_choice']['all']); ?>,
            necessary only <?php echo esc_html($consent_records['by_choice']['necessary']); ?>,
            custom <?php echo esc_html($consent_records['by_choice']['custom']); ?>).
        </p>
    </section>

    <section class="nyx-card">
        <h2>Using it</h2>
        <ul class="nyx-runlist">
            <li><span>Footer link</span><span><code>[wta_cookie_settings]</code> or any link to <code>#wta-cookie-settings</code></span></li>
            <li><span>From JavaScript</span><span><code>window.wtaConsent.open()</code></span></li>
            <li><span>Hold back a script</span><span><code>&lt;script type="text/plain" data-wta-consent="analytics"&gt;</code></span></li>
            <li><span>From PHP</span><span><code>echo wta_consent_script( 'marketing', $src_or_inline );</code></span></li>
        </ul>
    </section>

    <div class="nyx-actions">
        <button type="submit" class="nyx-btn is-primary">Save consent settings</button>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('wta-consent-form');
    if (!form) { return; }
    var timer;

    function val(name) {
        var el = form.elements['wta_consent[' + name + ']'];
        if (!el) { return ''; }
        if (el.length) { el = el[el.length - 1]; }
        return el.value;
    }
    function flag(name) {
        var box = form.querySelector('input[type=checkbox][name="wta_consent[' + name + ']"]');
        return box ? box.checked : true;
    }

    function patch() {
        var cats = [{ id: 'necessary', label: 'Necessary', description: val('necessary_desc'), required: true }];
        if (flag('analytics_on')) { cats.push({ id: 'analytics', label: 'Analytics', description: val('analytics_desc'), required: false }); }
        if (flag('marketing_on')) { cats.push({ id: 'marketing', label: 'Marketing', description: val('marketing_desc'), required: false }); }
        return {
            text: val('text'),
            position: val('position') || 'bottom',
            categories: cats,
            privacy: { url: val('privacy_url') || '/privacy-policy/', label: val('privacy_label') || 'Privacy policy' }
        };
    }

    function update() {
        if (window.wtaConsent && window.wtaConsent.preview) { window.wtaConsent.preview(patch()); }
    }

    form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(update, 120); });
    form.addEventListener('change', update);
    document.getElementById('wta-consent-prev-bar').addEventListener('click', function () { window.wtaConsent.previewPanel(false); });
    document.getElementById('wta-consent-prev-panel').addEventListener('click', function () { window.wtaConsent.previewPanel(true); });
}());
</script>
