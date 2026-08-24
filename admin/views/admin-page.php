<?php
/**
 * Admin screen for WP Travel Addons.
 *
 * Included from WTA_Admin::render(), so $this is the WTA_Admin instance.
 * Styling follows the Mzizi/Bundu brand system used by the sibling Yoast SEO
 * Addons screen: tanzanite primary, cobalt for focus, warm-stone borders,
 * pill buttons, 14px cards.
 *
 * @package WPTravelAddons
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_tab = $this->current_tab();
$tabs        = WTA_Admin::tabs();
$plugin      = $this->plugin();
$module_map  = WP_Travel_Addons::module_map();

$trip_type      = WTA_Trip::post_type();
$trips_present  = WTA_Trip::is_available();
$taxonomies     = $this->taxonomies();
$audit_on       = $this->audit_available();
$findings       = $this->audit_findings();
$severities     = WTA_Admin::severities();
$saved          = $this->consume_saved_flag();

/** Render a pill switch bound to a checkbox. */
if (!function_exists('wta_switch')) {
    function wta_switch($name, $checked, $label, $description = '') {
        $id = 'sw-' . $name;
        ?>
        <div class="nyx-switch-row">
            <label class="nyx-switch" for="<?php echo esc_attr($id); ?>">
                <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                       value="1" <?php checked($checked); ?>>
                <span class="nyx-track" aria-hidden="true"><span class="nyx-thumb"></span></span>
                <span class="nyx-switch-text">
                    <span class="nyx-switch-label"><?php echo esc_html($label); ?></span>
                    <?php if ($description) : ?>
                        <span class="nyx-switch-desc"><?php echo esc_html($description); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        </div>
        <?php
    }
}
?>

<div class="wrap nyx">

    <header class="nyx-head">
        <div class="nyx-head-id">
            <span class="nyx-wordmark">Nyuchi</span>
            <h1 class="nyx-title">WP Travel Addons</h1>
            <span class="nyx-version">v<?php echo esc_html(defined('WTA_VERSION') ? WTA_VERSION : '1.0.0'); ?></span>
        </div>
        <div class="nyx-chips">
            <span class="nyx-chip <?php echo $trips_present ? 'is-on' : 'is-bad'; ?>">
                <?php echo $trips_present ? 'WP Travel detected' : 'WP Travel not detected'; ?>
            </span>
            <span class="nyx-chip is-neutral">
                Trip type: <code><?php echo esc_html($trip_type); ?></code>
            </span>
            <span class="nyx-chip is-neutral">
                <?php
                echo esc_html(sprintf(
                    '%d of %d modules active',
                    $this->active_module_count(),
                    count($module_map)
                ));
                ?>
            </span>
        </div>
    </header>

    <?php if (!$trips_present) : ?>
        <div class="nyx-alert is-bad">
            <strong>WP Travel is not active.</strong>
            The trip post type <code><?php echo esc_html($trip_type); ?></code> is not registered, so
            trip counts and the REST schema will be empty until it is. Term publication
            state still works on any taxonomy that does exist.
        </div>
    <?php endif; ?>

    <?php if ($saved) : ?>
        <div class="nyx-alert is-good">Changes saved.</div>
    <?php endif; ?>

    <nav class="nyx-tabs" aria-label="WP Travel Addons sections">
        <?php foreach ($tabs as $slug => $meta) : ?>
            <a class="nyx-tab <?php echo $current_tab === $slug ? 'is-active' : ''; ?>"
               href="<?php echo esc_url(WTA_Admin::admin_url_for($slug)); ?>"
               <?php echo $current_tab === $slug ? 'aria-current="page"' : ''; ?>>
                <span class="dashicons dashicons-<?php echo esc_attr($meta[1]); ?>"></span>
                <?php echo esc_html($meta[0]); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php // ---------------------------------------------------------- OVERVIEW ?>
    <?php if ('overview' === $current_tab) : ?>

        <div class="nyx-stats">
            <div class="nyx-stat">
                <span class="nyx-stat-label">Published trips</span>
                <span class="nyx-stat-value"><?php echo esc_html(number_format_i18n($this->trip_count())); ?></span>
            </div>
            <div class="nyx-stat">
                <span class="nyx-stat-label">Classified terms</span>
                <span class="nyx-stat-value"><?php echo esc_html(number_format_i18n($this->term_count())); ?></span>
            </div>
            <div class="nyx-stat">
                <span class="nyx-stat-label">Draft terms</span>
                <span class="nyx-stat-value"><?php echo esc_html(number_format_i18n($this->draft_term_count())); ?></span>
            </div>
            <div class="nyx-stat">
                <span class="nyx-stat-label">Audit findings</span>
                <span class="nyx-stat-value">
                    <?php echo $audit_on ? esc_html(number_format_i18n(count($findings))) : '&mdash;'; ?>
                </span>
            </div>
        </div>

        <div class="nyx-grid">

            <section class="nyx-card nyx-span-2">
                <h2>Highest-severity findings</h2>
                <p class="nyx-card-sub">
                    The classification problems worth fixing first. The full list, with the
                    affected terms, is on the Classification tab.
                </p>

                <?php if (!$audit_on) : ?>
                    <p class="nyx-empty">
                        Classification diagnostics are switched off, so nothing has been checked.
                        Turn the module on under
                        <a href="<?php echo esc_url(WTA_Admin::admin_url_for('modules')); ?>">Modules</a>
                        to see flat hierarchies, empty terms and cross-taxonomy duplicates.
                    </p>
                <?php elseif (empty($findings)) : ?>
                    <p class="nyx-empty">
                        Nothing flagged. Every audited taxonomy segments its trips, has no empty
                        terms and no duplicates across taxonomies.
                    </p>
                <?php else : ?>
                    <ul class="nyx-findlist">
                        <?php foreach (array_slice($findings, 0, 5) as $finding) : ?>
                            <li>
                                <span class="nyx-sev is-<?php echo esc_attr($finding['severity']); ?>">
                                    <?php echo esc_html($severities[$finding['severity']]); ?>
                                </span>
                                <span class="nyx-find-body">
                                    <span class="nyx-find-title"><?php echo esc_html($finding['title']); ?></span>
                                    <?php if ('' !== $finding['detail']) : ?>
                                        <span class="nyx-find-detail"><?php echo esc_html($finding['detail']); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="nyx-find-count"><?php echo esc_html(number_format_i18n($finding['count'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="nyx-actions is-inline">
                        <a class="nyx-btn" href="<?php echo esc_url(WTA_Admin::admin_url_for('classification')); ?>">
                            See all findings
                        </a>
                    </div>
                <?php endif; ?>
            </section>

            <section class="nyx-card">
                <h2>Audited taxonomies</h2>
                <p class="nyx-card-sub">Where publication state and diagnostics apply.</p>
                <ul class="nyx-runlist">
                    <?php foreach ($taxonomies as $slug => $label) : ?>
                        <li>
                            <span><?php echo esc_html($label); ?></span>
                            <span class="nyx-mono <?php echo taxonomy_exists($slug) ? '' : 'nyx-dim'; ?>">
                                <?php echo esc_html($slug); ?>
                                <?php echo taxonomy_exists($slug) ? '' : ' (missing)'; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="nyx-card">
                <h2>Modules</h2>
                <p class="nyx-card-sub">Each one is independently switchable.</p>
                <ul class="nyx-runlist">
                    <?php foreach ($module_map as $key => $module) : ?>
                        <li>
                            <span><?php echo esc_html($module['label']); ?></span>
                            <span class="nyx-tag <?php echo $this->module_enabled($key) ? 'is-on' : 'is-off'; ?>">
                                <?php echo $this->module_enabled($key) ? 'Active' : 'Off'; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

        </div>
    <?php endif; ?>

    <?php // ---------------------------------------------------- CLASSIFICATION ?>
    <?php if ('classification' === $current_tab) : ?>

        <p class="nyx-lede">
            A taxonomy earns its place by splitting the catalogue into groups a visitor
            would actually browse. These checks look for the ways that breaks: a flat
            list where a tree was intended, terms with nothing in them, terms that match
            every trip, and the same label duplicated across two taxonomies.
        </p>

        <?php if (!$audit_on) : ?>
            <section class="nyx-card">
                <h2>Diagnostics are off</h2>
                <p class="nyx-card-sub">
                    The classification diagnostics module is not loaded, so no checks have run.
                    It only reads terms and post counts — it never writes.
                </p>
                <div class="nyx-actions is-inline">
                    <a class="nyx-btn is-primary" href="<?php echo esc_url(WTA_Admin::admin_url_for('modules')); ?>">
                        Turn diagnostics on
                    </a>
                </div>
            </section>
        <?php elseif (empty($findings)) : ?>
            <section class="nyx-card">
                <h2>Nothing flagged</h2>
                <p class="nyx-empty">
                    Every audited taxonomy passed. Re-run after a bulk import, when new terms
                    are most likely to arrive without content behind them.
                </p>
            </section>
        <?php else : ?>
            <?php foreach ($this->findings_by_severity() as $severity => $group) : ?>
                <h2 class="nyx-group-head">
                    <?php echo esc_html($severities[$severity]); ?>
                    <span class="nyx-group-count"><?php echo esc_html(number_format_i18n(count($group))); ?></span>
                </h2>
                <div class="nyx-cards">
                    <?php foreach ($group as $finding) : ?>
                        <section class="nyx-find nyx-find-<?php echo esc_attr($severity); ?>">
                            <div class="nyx-find-head">
                                <div class="nyx-find-headtext">
                                    <span class="nyx-sev is-<?php echo esc_attr($severity); ?>">
                                        <?php echo esc_html($severities[$severity]); ?>
                                    </span>
                                    <h3><?php echo esc_html($finding['title']); ?></h3>
                                </div>
                                <span class="nyx-tag">
                                    <?php echo esc_html(number_format_i18n($finding['count'])); ?> affected
                                </span>
                            </div>

                            <?php if ('' !== $finding['detail']) : ?>
                                <p class="nyx-int-desc"><?php echo esc_html($finding['detail']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($finding['terms'])) : ?>
                                <div class="nyx-termchips">
                                    <?php foreach ($finding['terms'] as $term) :
                                        $is_draft = (WTA_Term_Status::DRAFT === $term['status']);
                                        $label    = $term['name'];

                                        if ('' !== $term['taxonomy']) {
                                            $label .= ' · ' . $this->taxonomy_label($term['taxonomy']);
                                        }
                                        ?>
                                        <?php if ('' !== $term['link']) : ?>
                                            <a class="nyx-termchip <?php echo $is_draft ? 'is-draft' : ''; ?>"
                                               href="<?php echo esc_url($term['link']); ?>">
                                                <?php echo esc_html($label); ?>
                                                <?php if ($is_draft) : ?>
                                                    <span class="nyx-termchip-state">draft</span>
                                                <?php endif; ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="nyx-termchip <?php echo $is_draft ? 'is-draft' : ''; ?>">
                                                <?php echo esc_html($label); ?>
                                                <?php if ($is_draft) : ?>
                                                    <span class="nyx-termchip-state">draft</span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ------------------------------------------------------------- TERMS ?>
    <?php if ('terms' === $current_tab) :
        $filter  = $this->current_taxonomy_filter();
        $grouped = $this->terms_by_taxonomy($filter);
        $can_set = current_user_can('manage_categories');
        ?>

        <p class="nyx-lede">
            A draft term stays editable and assignable, but its archive returns 404 and it
            is kept out of menus, term lists and the REST terms endpoints. That is how a
            destination tree gets built out ahead of the content without publishing a set
            of thin, indexable archives.
        </p>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="nyx-filter">
            <input type="hidden" name="page" value="wp-travel-addons">
            <input type="hidden" name="tab" value="terms">
            <label for="wta-taxonomy-filter">Taxonomy</label>
            <select id="wta-taxonomy-filter" name="taxonomy" class="nyx-select">
                <option value="" <?php selected($filter, ''); ?>>All audited taxonomies</option>
                <?php foreach ($taxonomies as $slug => $label) : ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($filter, $slug); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="nyx-btn">Filter</button>
        </form>

        <form method="post" action="<?php echo esc_url(WTA_Admin::admin_url_for('terms')); ?>">
            <?php wp_nonce_field('wta_save_terms', 'wta_terms_nonce'); ?>
            <input type="hidden" name="wta_form" value="terms">

            <div class="nyx-bulkbar">
                <label class="nyx-check">
                    <input type="checkbox" id="wta-select-all" <?php disabled(!$can_set); ?>>
                    <span>Select all</span>
                </label>
                <label class="screen-reader-text" for="wta-bulk-status">Publication state</label>
                <select id="wta-bulk-status" name="bulk_status" class="nyx-select" <?php disabled(!$can_set); ?>>
                    <option value="live">Live &mdash; public and indexable</option>
                    <option value="draft">Draft &mdash; hidden from visitors</option>
                </select>
                <button type="submit" class="nyx-btn is-primary" <?php disabled(!$can_set); ?>>Apply</button>
                <?php if (!$can_set) : ?>
                    <span class="nyx-dim">Your account cannot change term state.</span>
                <?php endif; ?>
            </div>

            <?php foreach ($grouped as $slug => $terms) : ?>
                <section class="nyx-card">
                    <h2><?php echo esc_html($this->taxonomy_label($slug)); ?></h2>
                    <p class="nyx-card-sub">
                        <code><?php echo esc_html($slug); ?></code>
                        &middot; <?php echo esc_html(number_format_i18n(count($terms))); ?> terms
                    </p>

                    <?php if (!taxonomy_exists($slug)) : ?>
                        <p class="nyx-empty">
                            This taxonomy is not registered on this site. It is usually a sign that
                            WP Travel is inactive, or that the taxonomy set has been filtered.
                        </p>
                    <?php elseif (empty($terms)) : ?>
                        <p class="nyx-empty">No terms yet.</p>
                    <?php else : ?>
                        <div class="nyx-tablewrap">
                            <table class="nyx-table">
                                <thead>
                                    <tr>
                                        <th class="nyx-col-check"><span class="screen-reader-text">Select</span></th>
                                        <th>Name</th>
                                        <th>Slug</th>
                                        <th>Taxonomy</th>
                                        <th>Parent</th>
                                        <th>Trips</th>
                                        <th>State</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($terms as $term) :
                                        $status = $this->term_status($term->term_id);
                                        $parent = $this->term_parent_name($term);
                                        $edit   = get_edit_term_link($term->term_id, $term->taxonomy);
                                        ?>
                                        <tr>
                                            <td class="nyx-col-check">
                                                <input type="checkbox" class="wta-term-check" name="term_ids[]"
                                                       value="<?php echo esc_attr($term->term_id); ?>"
                                                       <?php disabled(!$can_set); ?>>
                                            </td>
                                            <td>
                                                <?php if ($edit) : ?>
                                                    <a href="<?php echo esc_url($edit); ?>"><?php echo esc_html($term->name); ?></a>
                                                <?php else : ?>
                                                    <?php echo esc_html($term->name); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="nyx-mono"><?php echo esc_html($term->slug); ?></td>
                                            <td class="nyx-mono nyx-dim"><?php echo esc_html($term->taxonomy); ?></td>
                                            <td>
                                                <?php echo '' !== $parent
                                                    ? esc_html($parent)
                                                    : '<span class="nyx-dim">&mdash;</span>'; ?>
                                            </td>
                                            <td class="nyx-num"><?php echo esc_html(number_format_i18n((int) $term->count)); ?></td>
                                            <td>
                                                <span class="nyx-status is-<?php echo esc_attr($status); ?>">
                                                    <?php echo esc_html(ucfirst($status)); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </form>

        <script>
        (function () {
            var master = document.getElementById('wta-select-all');
            if (!master) { return; }
            master.addEventListener('change', function () {
                var boxes = document.querySelectorAll('.wta-term-check');
                for (var i = 0; i < boxes.length; i++) {
                    if (!boxes[i].disabled) { boxes[i].checked = master.checked; }
                }
            });
        })();
        </script>
    <?php endif; ?>

    <?php // ----------------------------------------------------------- MODULES ?>
    <?php if ('modules' === $current_tab) : ?>
    <form method="post" action="<?php echo esc_url(WTA_Admin::admin_url_for('modules')); ?>">
        <?php wp_nonce_field('wta_save_modules', 'wta_modules_nonce'); ?>
        <input type="hidden" name="wta_form" value="modules">

        <p class="nyx-lede">
            This plugin sits alongside someone else's product, so nothing here is
            load-bearing by default. Switching a module off unregisters its hooks on the
            next request and leaves the stored data untouched, which makes each one
            straightforward to rule out when something upstream misbehaves.
        </p>

        <div class="nyx-cards">
            <?php foreach ($module_map as $key => $module) :
                $enabled = (bool) get_option('wta_module_' . $key, 1);
                $loaded  = $this->module_enabled($key);
                ?>
                <section class="nyx-int <?php echo $loaded ? 'is-available' : 'is-missing'; ?>">
                    <div class="nyx-int-head">
                        <div class="nyx-int-headtext">
                            <h3><?php echo esc_html($module['label']); ?></h3>
                            <span class="nyx-tag <?php echo $loaded ? 'is-on' : 'is-off'; ?>">
                                <?php echo $loaded ? 'Loaded' : 'Not loaded'; ?>
                            </span>
                            <span class="nyx-tag"><code><?php echo esc_html($module['class']); ?></code></span>
                        </div>
                        <label class="nyx-switch is-bare">
                            <input type="checkbox" name="module_<?php echo esc_attr($key); ?>"
                                   value="1" <?php checked($enabled); ?>>
                            <span class="nyx-track" aria-hidden="true"><span class="nyx-thumb"></span></span>
                            <span class="screen-reader-text">
                                Enable <?php echo esc_html($module['label']); ?>
                            </span>
                        </label>
                    </div>
                    <p class="nyx-int-desc"><?php echo esc_html($module['detail']); ?></p>
                </section>
            <?php endforeach; ?>
        </div>

        <section class="nyx-card">
            <h2>Compatibility guard</h2>
            <p class="nyx-card-sub">
                A targeted fix for a defect in another plugin, kept separate from the modules
                above because it should be turned off the moment upstream ships a fix.
            </p>
            <?php
            wta_switch(
                'compat_wt_widgets_printf',
                get_option(WTA_Admin::COMPAT_OPTION, 1),
                'Neutralise the WT Widgets for Elementor printf defect',
                'WT Widgets for Elementor 1.4.7 passes an itinerary day description straight into printf as the format string (trip-outline-widget.php line 1741). Any trip whose day description contains a per-cent sign therefore renders corrupted text, and throws a fatal ArgumentCountError on PHP 8.'
            );
            ?>
            <p class="nyx-help">
                The guard escapes the description before it reaches printf, so the text renders
                as written. It does nothing when that plugin is inactive.
            </p>
        </section>

        <div class="nyx-actions">
            <button type="submit" class="nyx-btn is-primary">Save modules</button>
        </div>
    </form>
    <?php endif; ?>

</div>

<style>
/* WP Travel Addons — Mzizi/Bundu brand system, scoped to .nyx */
.nyx {
    --nyx-primary:   #4B0082;  /* tanzanite  */
    --nyx-info:      #0047AB;  /* cobalt     */
    --nyx-success:   #004D40;  /* malachite  */
    --nyx-warning:   #7A5C00;
    --nyx-danger:    #B3261E;
    --nyx-gold:      #5D4037;  /* nyuchi mineral */
    --nyx-ink:       #1A1917;
    --nyx-muted:     #55514B;
    --nyx-faint:     #86817A;
    --nyx-border:    #E7E5E0;  /* warm stone, not cool grey */
    --nyx-surface:   #FFFFFF;
    --nyx-sunken:    #FAF9F5;
    --nyx-base:      #F3F3F1;
    --nyx-r-card:    14px;
    --nyx-r-sm:      7px;
    --nyx-r-tab:     999px;

    max-width: 1180px;
    color: var(--nyx-ink);
    font-size: 14px;
    line-height: 1.55;
}
.nyx *, .nyx *::before, .nyx *::after { box-sizing: border-box; }
.nyx h1, .nyx h2, .nyx h3 {
    font-family: "Noto Serif", Georgia, "Times New Roman", serif;
    letter-spacing: -0.01em;
    color: var(--nyx-ink);
}

/* Header */
.nyx-head {
    display: flex; flex-wrap: wrap; gap: 14px 20px;
    align-items: center; justify-content: space-between;
    padding: 20px 0 18px; margin-bottom: 4px;
    border-bottom: 1px solid var(--nyx-border);
}
.nyx-head-id { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; min-width: 0; }
.nyx-wordmark {
    font-weight: 700; font-size: 13px; letter-spacing: 0.14em;
    text-transform: none; color: var(--nyx-gold);
    border: 1px solid var(--nyx-border); border-radius: 999px;
    padding: 3px 11px; background: var(--nyx-sunken);
}
.nyx-title { font-size: 25px; margin: 0; font-weight: 600; }
.nyx-version { font-size: 12px; color: var(--nyx-faint); font-variant-numeric: tabular-nums; }
.nyx-chips { display: flex; flex-wrap: wrap; gap: 8px; min-width: 0; }
.nyx-chip {
    font-size: 12px; padding: 4px 12px; border-radius: 999px;
    border: 1px solid var(--nyx-border); background: var(--nyx-sunken);
    color: var(--nyx-muted); white-space: nowrap;
}
.nyx-chip code { background: none; padding: 0; font-size: 11.5px; }
.nyx-chip.is-on   { color: var(--nyx-success); border-color: #B7D9D1; background: #E0F2F1; }
.nyx-chip.is-off  { color: var(--nyx-warning); border-color: #E3D19A; background: #FFF8E1; }
.nyx-chip.is-bad  { color: var(--nyx-danger);  border-color: #E7BDBA; background: #FDEDED; }
.nyx-chip.is-neutral { font-variant-numeric: tabular-nums; }

/* Alerts */
.nyx-alert {
    border: 1px solid var(--nyx-border); border-left-width: 4px;
    border-radius: var(--nyx-r-sm); padding: 12px 16px; margin: 16px 0;
    background: var(--nyx-surface);
}
.nyx-alert.is-good { border-left-color: var(--nyx-success); background: #E0F2F1; }
.nyx-alert.is-bad  { border-left-color: var(--nyx-danger);  background: #FDEDED; }

/* Tabs */
.nyx-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 18px 0 22px; }
.nyx-tab {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 17px; border-radius: var(--nyx-r-tab);
    border: 1px solid var(--nyx-border); background: var(--nyx-surface);
    color: var(--nyx-muted); text-decoration: none; font-weight: 500;
}
.nyx-tab:hover { color: var(--nyx-primary); border-color: #CFC3DC; background: #F3E5F5; }
.nyx-tab.is-active {
    background: var(--nyx-primary); border-color: var(--nyx-primary);
    color: #fff; font-weight: 600;
    /* Stated again rather than inherited: the active tab is also the focused
       one, and anything reaching it at equal specificity would win on source
       order and square off the only filled tab. */
    border-radius: var(--nyx-r-tab);
}
.nyx-tab .dashicons { font-size: 17px; width: 17px; height: 17px; }
/*
 * Focus ring drawn with box-shadow rather than outline.
 *
 * outline has only followed border-radius since Safari 16.4, and not at all in
 * some older engines - so on a pill it paints a rectangle, which on the active
 * tab reads as though that one tab lost its rounding. box-shadow has always
 * followed the radius.
 *
 * The transparent outline stays on purpose: Windows High Contrast Mode drops
 * box-shadow entirely and forces transparent outlines to a visible colour.
 * Without it, focus would be invisible to the people who most need to see it.
 */
.nyx-tab:focus-visible, .nyx a:focus-visible, .nyx button:focus-visible,
.nyx input:focus-visible, .nyx select:focus-visible, .nyx label:focus-within {
    outline: 2px solid transparent;
    outline-offset: 2px;
    box-shadow: 0 0 0 2px var(--nyx-base), 0 0 0 4px var(--nyx-info);
}

/* Layout */
.nyx-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.nyx-span-2 { grid-column: 1 / -1; }
@media (max-width: 900px) { .nyx-grid { grid-template-columns: minmax(0, 1fr); } }

.nyx-card {
    background: var(--nyx-surface);
    border: 1px solid var(--nyx-border);
    border-radius: var(--nyx-r-card);
    padding: 20px;
    min-width: 0;
    margin-bottom: 18px;
}
.nyx-grid .nyx-card { margin-bottom: 0; }
.nyx-card h2 { font-size: 17px; margin: 0 0 4px; }
.nyx-card-sub { color: var(--nyx-muted); margin: 0 0 16px; max-width: 68ch; }
.nyx-lede { color: var(--nyx-muted); max-width: 72ch; margin: 0 0 18px; }

/* Switches */
.nyx-switch-row { padding: 9px 0; border-top: 1px solid var(--nyx-border); }
.nyx-switch-row:first-of-type { border-top: 0; padding-top: 0; }
.nyx-switch { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; min-width: 0; }
.nyx-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.nyx-track {
    flex: 0 0 auto; width: 42px; height: 24px; border-radius: 999px;
    background: #D6D5D1; border: 1px solid var(--nyx-border);
    position: relative; transition: background .15s ease; margin-top: 1px;
}
.nyx-thumb {
    position: absolute; top: 2px; left: 2px; width: 18px; height: 18px;
    border-radius: 50%; background: #fff; transition: transform .15s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,.25);
}
.nyx-switch input:checked + .nyx-track { background: var(--nyx-primary); border-color: var(--nyx-primary); }
.nyx-switch input:checked + .nyx-track .nyx-thumb { transform: translateX(18px); }
.nyx-switch input:disabled + .nyx-track { opacity: .45; }
.nyx-switch input:focus-visible + .nyx-track { outline: 2px solid var(--nyx-info); outline-offset: 2px; }
.nyx-switch-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.nyx-switch-label { font-weight: 500; }
.nyx-switch-desc { color: var(--nyx-faint); font-size: 13px; overflow-wrap: anywhere; }
.nyx-switch.is-bare { gap: 0; flex: 0 0 auto; }

/* Fields */
.nyx-field { margin: 14px 0 0; min-width: 0; }
.nyx-field label { display: block; font-weight: 500; margin-bottom: 5px; }
.nyx-field input {
    width: 100%; max-width: 100%;
    padding: 9px 14px; border-radius: 999px;
    border: 1px solid var(--nyx-border); background: var(--nyx-surface);
    color: var(--nyx-ink); font-size: 14px;
}
.nyx-field input:focus { border-color: var(--nyx-info); }
.nyx-help { color: var(--nyx-faint); font-size: 12.5px; margin: 10px 0 0; max-width: 72ch; }

/* Selects — pill, to match the buttons */
.nyx-select {
    padding: 8px 14px; border-radius: 999px; max-width: 100%;
    border: 1px solid var(--nyx-border); background: var(--nyx-surface);
    color: var(--nyx-ink); font-size: 13.5px; line-height: 1.3; min-width: 0;
}
.nyx-select:focus { border-color: var(--nyx-info); }

/* Checkbox list */
.nyx-check { display: flex; align-items: center; gap: 9px; min-width: 0; }
.nyx-check input { width: 18px; height: 18px; border-radius: var(--nyx-r-sm); margin: 0; flex: 0 0 auto; }
.nyx-check span { min-width: 0; overflow-wrap: anywhere; }

/* Filter bar + bulk-action bar */
.nyx-filter, .nyx-bulkbar {
    display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
    background: var(--nyx-surface); border: 1px solid var(--nyx-border);
    border-radius: var(--nyx-r-card); padding: 13px 17px; margin-bottom: 18px;
    min-width: 0;
}
.nyx-filter label { font-weight: 500; color: var(--nyx-muted); }
.nyx-bulkbar { position: sticky; top: 46px; z-index: 5; }

/* Run list */
.nyx-runlist { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--nyx-border); }
.nyx-runlist li {
    display: flex; justify-content: space-between; align-items: center; gap: 12px;
    padding: 7px 0; border-bottom: 1px solid var(--nyx-border);
    font-size: 13px; color: var(--nyx-muted); min-width: 0;
}
.nyx-runlist li > span { min-width: 0; overflow-wrap: anywhere; }

/* Module / finding cards */
.nyx-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 16px; margin-bottom: 18px; }
.nyx-int, .nyx-find {
    background: var(--nyx-surface); border: 1px solid var(--nyx-border);
    border-left: 4px solid var(--nyx-border);
    border-radius: var(--nyx-r-card); padding: 17px; min-width: 0;
}
.nyx-int.is-available { border-left-color: var(--nyx-success); }
.nyx-int.is-missing { background: var(--nyx-sunken); }
.nyx-int-head, .nyx-find-head {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; min-width: 0;
}
.nyx-int-headtext, .nyx-find-headtext { min-width: 0; }
.nyx-int-head h3, .nyx-find-head h3 { font-size: 15.5px; margin: 0 0 6px; overflow-wrap: anywhere; }
.nyx-find-head h3 { margin: 6px 0 0; }
.nyx-int-desc { color: var(--nyx-muted); margin: 11px 0 0; overflow-wrap: anywhere; }
.nyx-tag {
    display: inline-block; font-size: 11px; letter-spacing: .03em;
    padding: 2px 9px; border-radius: 999px; margin-right: 5px; white-space: nowrap;
    border: 1px solid var(--nyx-border); background: var(--nyx-sunken); color: var(--nyx-muted);
}
.nyx-tag code { background: none; padding: 0; font-size: 11px; }
.nyx-tag.is-on { color: var(--nyx-success); border-color: #B7D9D1; background: #E0F2F1; }
.nyx-tag.is-off { color: var(--nyx-faint); }

/* Severity */
.nyx-group-head {
    font-size: 15px; margin: 22px 0 12px; display: flex; align-items: center; gap: 9px;
}
.nyx-group-count {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px;
    color: var(--nyx-faint); border: 1px solid var(--nyx-border);
    border-radius: 999px; padding: 1px 9px; background: var(--nyx-sunken);
}
.nyx-sev {
    display: inline-block; font-size: 11px; font-weight: 600; letter-spacing: .05em;
    text-transform: uppercase; padding: 2px 10px; border-radius: 999px;
    border: 1px solid var(--nyx-border); background: var(--nyx-sunken);
    color: var(--nyx-muted); white-space: nowrap;
}
.nyx-sev.is-critical { color: var(--nyx-danger);  border-color: #E7BDBA; background: #FDEDED; }
.nyx-sev.is-warning  { color: var(--nyx-warning); border-color: #E3D19A; background: #FFF8E1; }
.nyx-sev.is-notice   { color: var(--nyx-info);    border-color: #BFCFE8; background: #EAF0FA; }
.nyx-sev.is-info     { color: var(--nyx-muted); }
.nyx-find-critical { border-left-color: var(--nyx-danger); }
.nyx-find-warning  { border-left-color: var(--nyx-warning); }
.nyx-find-notice   { border-left-color: var(--nyx-info); }
.nyx-find-info     { border-left-color: var(--nyx-border); }

/* Overview finding list */
.nyx-findlist { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--nyx-border); }
.nyx-findlist li {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 11px 0; border-bottom: 1px solid var(--nyx-border); min-width: 0;
}
.nyx-find-body { display: flex; flex-direction: column; gap: 2px; flex: 1 1 auto; min-width: 0; }
.nyx-find-title { font-weight: 500; overflow-wrap: anywhere; }
.nyx-find-detail { color: var(--nyx-faint); font-size: 13px; overflow-wrap: anywhere; }
.nyx-find-count {
    flex: 0 0 auto; font-variant-numeric: tabular-nums; font-weight: 600; color: var(--nyx-muted);
}

/* Term chips */
.nyx-termchips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; min-width: 0; }
.nyx-termchip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; padding: 3px 11px; border-radius: 999px;
    border: 1px solid var(--nyx-border); background: var(--nyx-sunken);
    color: var(--nyx-muted); text-decoration: none; max-width: 100%; overflow-wrap: anywhere;
}
a.nyx-termchip:hover { color: var(--nyx-primary); border-color: #CFC3DC; background: #F3E5F5; }
.nyx-termchip.is-draft { color: var(--nyx-warning); border-color: #E3D19A; background: #FFF8E1; }
.nyx-termchip-state {
    font-size: 10px; letter-spacing: .06em; text-transform: uppercase; font-weight: 600;
}

/* Stats */
.nyx-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 18px; }
.nyx-stat {
    background: var(--nyx-surface); border: 1px solid var(--nyx-border);
    border-radius: var(--nyx-r-card); padding: 15px 17px;
    display: flex; flex-direction: column; gap: 3px; min-width: 0;
}
.nyx-stat-label { font-size: 11.5px; letter-spacing: .07em; text-transform: uppercase; color: var(--nyx-faint); }
.nyx-stat-value { font-size: 25px; font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }

/* Tables */
.nyx-tablewrap { overflow-x: auto; border: 1px solid var(--nyx-border); border-radius: var(--nyx-r-card); max-width: 100%; }
.nyx-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 720px; }
.nyx-table th, .nyx-table td { text-align: left; padding: 10px 14px; border-top: 1px solid var(--nyx-border); vertical-align: top; }
.nyx-table thead th {
    border-top: 0; background: var(--nyx-sunken); color: var(--nyx-muted);
    font-size: 11.5px; letter-spacing: .06em; text-transform: uppercase; font-weight: 600;
}
.nyx-col-check { width: 38px; }
.nyx-col-check input { width: 18px; height: 18px; margin: 0; }
.nyx-num { font-variant-numeric: tabular-nums; }
.nyx-status {
    display: inline-block; font-size: 11.5px; padding: 2px 9px; border-radius: 999px;
    border: 1px solid var(--nyx-border); background: var(--nyx-sunken); color: var(--nyx-muted);
}
.nyx-status.is-live  { color: var(--nyx-success); border-color: #B7D9D1; background: #E0F2F1; }
.nyx-status.is-draft { color: var(--nyx-warning); border-color: #E3D19A; background: #FFF8E1; }

/* Buttons — always pill, per brand */
.nyx-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
.nyx-actions.is-inline { margin-top: 18px; }
.nyx-btn {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 10px 22px; border-radius: 999px; cursor: pointer; text-decoration: none;
    border: 1px solid var(--nyx-border); background: var(--nyx-surface);
    color: var(--nyx-ink); font-size: 14px; font-weight: 500; line-height: 1.2;
}
.nyx-btn:hover { border-color: #CFC3DC; background: #F3E5F5; color: var(--nyx-primary); }
.nyx-btn.is-primary { background: var(--nyx-primary); border-color: var(--nyx-primary); color: #fff; }
.nyx-btn.is-primary:hover { background: #3B0068; color: #fff; }
.nyx-btn:disabled { opacity: .55; cursor: default; }

.nyx-mono, .nyx .nyx-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
.nyx-dim { color: var(--nyx-faint); }
.nyx-empty { color: var(--nyx-faint); margin: 0; max-width: 72ch; }
.nyx code { background: var(--nyx-sunken); border-radius: 4px; padding: 1px 5px; font-size: 12px; }
</style>
