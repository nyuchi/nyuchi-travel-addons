<?php
/**
 * The PWA tab: settings for the progressive web app module, and the state of
 * WP Travel's old service worker.
 *
 * Included from admin-page.php, so $this is the WTA_Admin instance and the
 * wta_switch() helper is defined.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

$pwa_settings = WTA_PWA::settings();
$pwa_status   = WTA_PWA::status();
$pwa_enabled  = WP_Travel_Addons::module_enabled('pwa');
$pwa_legacy   = $pwa_status['legacy_service_worker'];

/** Media picker row: an attachment ID field with a preview and a Choose button. */
$pwa_picker = function ($key, $label, $help) use ($pwa_settings) {
    $id  = (int) $pwa_settings[$key];
    $src = $id ? wp_get_attachment_image_url($id, 'thumbnail') : '';
    ?>
    <div class="nyx-field wta-pwa-picker" data-key="<?php echo esc_attr($key); ?>">
        <label for="wta-pwa-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
        <div class="wta-pwa-picker-row">
            <img src="<?php echo esc_url($src); ?>" alt="" width="48" height="48" <?php echo $src ? '' : 'hidden'; ?>>
            <input type="number" min="0" id="wta-pwa-<?php echo esc_attr($key); ?>" name="wta_pwa[<?php echo esc_attr($key); ?>]"
                   value="<?php echo esc_attr($id ?: ''); ?>" placeholder="Attachment ID">
            <button type="button" class="nyx-btn wta-pwa-choose">Choose</button>
            <button type="button" class="nyx-btn wta-pwa-clear">Clear</button>
        </div>
        <p class="nyx-help"><?php echo esc_html($help); ?></p>
    </div>
    <?php
};

$legacy_text = array(
    'none'         => 'No old service worker file found.',
    'legacy'       => 'The old WP Travel worker is in the web root and has not been replaced yet.',
    'cleaned'      => 'The old WP Travel worker has been replaced by a kill-switch that removes it from visitors\' browsers.',
    'unrelated'    => 'There is a sw.js, but it is not WP Travel\'s, so it was left alone.',
    'pwa_enabled'  => 'WP Travel\'s PWA is still on and is using the file. Switch it off first.',
    'not_writable' => 'The old worker is still in place and could not be replaced. See the notice above for the file and the script to paste in.',
    'error'        => 'The old worker is still in place and replacing it failed. See the notice above.',
    'disabled'     => 'Clean-up is switched off by the wta_pwa_legacy_cleanup filter.',
);
?>

<?php if ($pwa_status['wp_travel_pwa_enabled']) : ?>
    <div class="nyx-alert is-bad">
        <strong>WP Travel's own PWA is still on.</strong>
        While it is, this module prints no manifest and registers no service worker, so the
        site never has two. Switch WP Travel's PWA off (WP Travel settings, PWA) to let this one take over.
    </div>
<?php endif; ?>

<section class="nyx-card">
    <h2>Old WP Travel service worker</h2>
    <p class="nyx-card-sub">
        WP Travel writes a <code>sw.js</code> into the web root that answers the homepage from a cache first. It stays
        there after WP Travel's PWA is switched off, so returning visitors can see an out-of-date homepage.
        This is cleaned up whether or not the PWA module below is on.
    </p>
    <p>
        <span class="nyx-tag <?php echo in_array($pwa_legacy['state'], array('none', 'cleaned', 'unrelated'), true) ? 'is-on' : 'is-off'; ?>">
            <?php echo esc_html($pwa_legacy['state']); ?>
        </span>
        <?php echo esc_html(isset($legacy_text[$pwa_legacy['state']]) ? $legacy_text[$pwa_legacy['state']] : ''); ?>
    </p>
    <p class="nyx-help">Checked file: <code><?php echo esc_html($pwa_legacy['path']); ?></code></p>
    <?php if (WTA_PWA_Legacy::enabled() && in_array($pwa_legacy['state'], array('legacy', 'not_writable', 'error'), true)) : ?>
        <div class="nyx-actions is-inline">
            <a class="nyx-btn is-primary" href="<?php echo esc_url(WTA_PWA_Legacy::remove_url()); ?>">Remove old service worker</a>
        </div>
    <?php endif; ?>
</section>

<form method="post" action="<?php echo esc_url(WTA_Admin::admin_url_for('pwa')); ?>">
    <?php wp_nonce_field('wta_save_pwa', 'wta_pwa_nonce'); ?>
    <input type="hidden" name="wta_form" value="pwa">

    <section class="nyx-card">
        <h2>Progressive web app</h2>
        <p class="nyx-card-sub">
            Makes the site installable and gives it an offline page. Pages always come from the network when it works;
            only static files (scripts, styles, fonts, images) are cached. Off by default.
        </p>
        <?php wta_switch('module_pwa', $pwa_enabled, 'Enable the progressive web app', 'Prints the manifest and theme-color tags and registers the service worker for signed-out visitors.'); ?>

        <div class="nyx-grid">
            <div class="nyx-field">
                <label for="wta-pwa-name">Name</label>
                <input type="text" id="wta-pwa-name" name="wta_pwa[name]" maxlength="45" value="<?php echo esc_attr($pwa_settings['name']); ?>">
            </div>
            <div class="nyx-field">
                <label for="wta-pwa-short">Short name</label>
                <input type="text" id="wta-pwa-short" name="wta_pwa[short_name]" maxlength="12" value="<?php echo esc_attr($pwa_settings['short_name']); ?>">
                <p class="nyx-help">Shown under the home-screen icon. 12 characters at most.</p>
            </div>
            <div class="nyx-field nyx-span-2">
                <label for="wta-pwa-description">Description</label>
                <input type="text" id="wta-pwa-description" name="wta_pwa[description]" maxlength="300" value="<?php echo esc_attr($pwa_settings['description']); ?>">
            </div>
            <div class="nyx-field">
                <label for="wta-pwa-theme">Theme colour</label>
                <input type="text" id="wta-pwa-theme" name="wta_pwa[theme_color]" maxlength="7" value="<?php echo esc_attr($pwa_settings['theme_color']); ?>">
                <p class="nyx-help">The browser toolbar colour, as hex, for example #1A1917.</p>
            </div>
            <div class="nyx-field">
                <label for="wta-pwa-bg">Background colour</label>
                <input type="text" id="wta-pwa-bg" name="wta_pwa[background_color]" maxlength="7" value="<?php echo esc_attr($pwa_settings['background_color']); ?>">
                <p class="nyx-help">Shown while the app opens, and behind the offline page.</p>
            </div>
            <div class="nyx-field">
                <label for="wta-pwa-start">Start URL</label>
                <input type="text" id="wta-pwa-start" name="wta_pwa[start_url]" value="<?php echo esc_attr($pwa_settings['start_url']); ?>">
                <p class="nyx-help">A path on this site, such as <code>/</code>.</p>
            </div>
            <div class="nyx-field">
                <label for="wta-pwa-display">Display mode</label>
                <select id="wta-pwa-display" name="wta_pwa[display]" class="nyx-select">
                    <?php foreach (WTA_PWA::DISPLAY_MODES as $mode) : ?>
                        <option value="<?php echo esc_attr($mode); ?>" <?php selected($pwa_settings['display'], $mode); ?>><?php echo esc_html($mode); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="nyx-card">
        <h2>Icons</h2>
        <p class="nyx-card-sub">
            Browsers need a square 192 px and 512 px icon before they offer to install. Choose images from the media
            library, or leave a slot empty to use the WordPress site icon (Appearance, Customize, Site Identity),
            which WordPress crops to both sizes. A maskable icon keeps its artwork inside the middle 80 per cent so
            Android can crop it into a circle; it is never filled in automatically.
        </p>
        <div class="nyx-tablewrap">
            <table class="nyx-table">
                <thead><tr><th>Icon</th><th>Source</th><th>Size</th><th>Check</th></tr></thead>
                <tbody>
                <?php foreach ($pwa_status['icons'] as $icon) : ?>
                    <tr>
                        <td><?php echo esc_html($icon['label']); ?></td>
                        <td><?php echo esc_html(str_replace('_', ' ', $icon['source'])); ?></td>
                        <td class="nyx-num"><?php echo esc_html($icon['sizes'] ?: '-'); ?></td>
                        <td>
                            <?php if ($icon['ok']) : ?>
                                <span class="nyx-status is-live">OK</span>
                            <?php else : ?>
                                <span class="nyx-status is-draft"><?php echo esc_html($icon['required'] ? 'Needs attention' : 'Optional'); ?></span>
                                <?php echo esc_html(implode(' ', $icon['problems'])); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="nyx-grid">
            <?php
            $pwa_picker('icon_192', '192 px icon', 'Square PNG, at least 192 x 192.');
            $pwa_picker('icon_512', '512 px icon', 'Square PNG, at least 512 x 512.');
            $pwa_picker('icon_maskable', 'Maskable 512 px icon', 'Square PNG, at least 512 x 512, with a safe zone.');
            ?>
        </div>
    </section>

    <section class="nyx-card">
        <h2>Offline page</h2>
        <p class="nyx-card-sub">Shown instead of the browser's error when someone opens a page without a connection.</p>
        <div class="nyx-grid">
            <div class="nyx-field">
                <label for="wta-pwa-offline-title">Title</label>
                <input type="text" id="wta-pwa-offline-title" name="wta_pwa[offline_title]" maxlength="80" value="<?php echo esc_attr($pwa_settings['offline_title']); ?>">
            </div>
            <?php $pwa_picker('offline_image', 'Image', 'Optional. Cached with the offline page.'); ?>
            <div class="nyx-field nyx-span-2">
                <label for="wta-pwa-offline-message">Message</label>
                <input type="text" id="wta-pwa-offline-message" name="wta_pwa[offline_message]" maxlength="400" value="<?php echo esc_attr($pwa_settings['offline_message']); ?>">
            </div>
        </div>
        <p class="nyx-help"><a href="<?php echo esc_url($pwa_status['offline_url']); ?>" target="_blank" rel="noopener">Preview the offline page</a></p>
    </section>

    <section class="nyx-card">
        <h2>Advanced</h2>
        <div class="nyx-grid">
            <div class="nyx-field">
                <label for="wta-pwa-cache-version">Cache version</label>
                <input type="number" min="1" max="99999" id="wta-pwa-cache-version" name="wta_pwa[cache_version]" value="<?php echo esc_attr($pwa_settings['cache_version']); ?>">
                <p class="nyx-help">Raise it to make every visitor's browser drop its cached files on the next visit. Each plugin release does this by itself.</p>
            </div>
            <div class="nyx-field">
                <label for="wta-pwa-style">Endpoint style</label>
                <select id="wta-pwa-style" name="wta_pwa[endpoint_style]" class="nyx-select">
                    <option value="auto" <?php selected($pwa_settings['endpoint_style'], 'auto'); ?>>Automatic</option>
                    <option value="pretty" <?php selected($pwa_settings['endpoint_style'], 'pretty'); ?>>Clean URLs (/wta-sw.js)</option>
                    <option value="query" <?php selected($pwa_settings['endpoint_style'], 'query'); ?>>Query URLs (/?wta_pwa=sw)</option>
                </select>
                <p class="nyx-help">Use query URLs if the manifest or worker URL below returns a 404 from the web server.</p>
            </div>
        </div>
        <ul class="nyx-runlist">
            <li><span>Manifest</span><span><a href="<?php echo esc_url($pwa_status['manifest_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($pwa_status['manifest_url']); ?></a></span></li>
            <li><span>Service worker</span><span><a href="<?php echo esc_url($pwa_status['service_worker_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($pwa_status['service_worker_url']); ?></a></span></li>
            <li><span>Cache build</span><span><code><?php echo esc_html($pwa_status['cache_build']); ?></code></span></li>
        </ul>
    </section>

    <div class="nyx-actions">
        <button type="submit" class="nyx-btn is-primary">Save PWA settings</button>
    </div>
</form>

<style>
.wta-pwa-picker-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.wta-pwa-picker-row img { border-radius: 8px; object-fit: cover; }
.wta-pwa-picker-row input { max-width: 9rem; }
</style>
<script>
(function () {
    if (typeof wp === 'undefined' || !wp.media) { return; }
    document.querySelectorAll('.wta-pwa-picker').forEach(function (row) {
        var input = row.querySelector('input');
        var img = row.querySelector('img');
        var frame;
        row.querySelector('.wta-pwa-choose').addEventListener('click', function () {
            if (!frame) {
                frame = wp.media({ title: 'Choose image', library: { type: 'image' }, multiple: false });
                frame.on('select', function () {
                    var a = frame.state().get('selection').first().toJSON();
                    input.value = a.id;
                    img.src = (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url);
                    img.hidden = false;
                });
            }
            frame.open();
        });
        row.querySelector('.wta-pwa-clear').addEventListener('click', function () {
            input.value = '';
            img.hidden = true;
        });
    });
})();
</script>
