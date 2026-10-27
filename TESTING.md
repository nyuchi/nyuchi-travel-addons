# Testing

The PHPUnit suite runs against a real WordPress, on SQLite, so it needs no
MySQL server: the WordPress test library (`wp-phpunit/wp-phpunit`), WordPress
core and the [SQLite Database Integration](https://wordpress.org/plugins/sqlite-database-integration/)
drop-in. The plugin is loaded the way a site loads it, from
`wp-content/plugins/nyuchi-travel-addons` (a symlink to the working tree) via
`active_plugins`.

## Running it

Requirements: PHP 8.2 or newer with `pdo_sqlite`, Composer, `curl`.

```bash
composer install
bash tests/bin/install-wp.sh   # once: WordPress + SQLite into $WP_CORE_DIR
vendor/bin/phpunit
```

`WP_CORE_DIR` defaults to `${TMPDIR:-/tmp}/wordpress-tests`. Set it to keep
WordPress somewhere else (outside any git checkout: WordPress will not
auto-update a plugin that sits under version control, and one test checks
that it would). `WP_VERSION` picks the WordPress release, e.g.
`WP_VERSION=6.8 bash tests/bin/install-wp.sh` for the newest 6.8.x; use a
fresh `WP_CORE_DIR` per version, and the matching test library:

```bash
composer update wp-phpunit/wp-phpunit --with "wp-phpunit/wp-phpunit:~6.8.0"
```

A run takes about ten seconds.

## What is covered

- `tests/integration/UpdaterTest.php`: the GitHub updater inside WordPress,
  with GitHub mocked on `pre_http_request`. Registration outside wp-admin,
  `wp_update_plugins()`, the automatic updater's decision, failure caching,
  version comparison, asset selection, the "View details" modal, and where the
  token is sent.
- `tests/integration/UpdaterContextTest.php`: each test boots a fresh
  WordPress in a child process (`tests/request.php`) as a wp-cron, REST,
  WP-CLI, front-end or wp-admin request, and one lets the wp-cron background
  updater really install a 9.9.9 release zip into a scratch plugins
  directory.
- `tests/integration/TokenRedirectTest.php`: real HTTP through WordPress's
  transport against PHP's built-in server: the token does not follow a
  redirect to another host.
- `RouteSanitizeTest.php` and `TripDataTest.php`: the `wta_route` sanitiser
  (including today's "unknown transfer mode becomes drive" behaviour) and
  `WTA_Elementor::trip_data()` for a partially filled trip.
- `TripCardPricingTest.php`: the trip card's "From $X" price and
  `price_suffix` qualifier, the once-only footnote (only when a card shows a
  price), the no-price branch, days-only and nights-only durations, the card
  link's accessible name, and the currency fallback. Elementor is not
  installed, so the widget loads against an empty base class
  (`tests/includes/elementor-stubs.php`).
- `TripStatusPermissionTest.php`: `create-trip` and `update-trip` refuse a
  publish, schedule or private status without the trip post type's publish
  capability (#39), write nothing else when they refuse, still let a
  contributor move a trip between draft and pending, and report the refusal
  in a dry run. Skipped on WordPress older than 6.9 (no Abilities API).
- `PwaTest.php`: the `pwa` module is off by default and registers nothing
  while off but the legacy clean-up; defaults and sanitising; the manifest
  (valid JSON, headers, icons with real sizes, invalid icons reported); the
  endpoint URLs; the generated worker text (exclusions, network-first
  navigation that never stores a page, asset strategies and limits, cache
  names that change with the release); the offline page; head and footer
  output, including nothing while WP Travel's PWA is on; the `pwa-status`
  ability.
- `PwaLegacyTest.php`: detection of WP Travel's old `sw.js` by its signature
  and its replacement by the kill-switch worker, on a file in a scratch
  directory (never the real `ABSPATH`); an unrelated `sw.js` is left alone;
  the not-writable report with path and script; run-once on `admin_init`; the
  disabling filter; the button's capability and nonce checks.
- `TermFaqTest.php`: country FAQs. The `faqs` term field (JSON storage, the
  sanitiser and its HTML allow-list, the admin save), REST, JSON and CSV
  transfer, the walk from a trip or place up to its country, the `<details>`
  markup, and the single FAQPage graph (merging, de-duplication, escaping, and
  yielding to Yoast, Rank Math, Elementor and in-content FAQ schema). The Elementor
  widget is a thin shell over `WTA_Term_FAQ`, which is what runs here: Elementor
  is not installed in the test site.

- `SlickJqueryTest.php`: the `compat` module adds the missing `jquery`
  dependency to WT Widgets' slick scripts, resolves jQuery first, and leaves
  alone scripts that already depend on it, absent handles, other scripts, and
  everything when `wta_compat_slick_jquery_fix` is false.

No test reaches the network: `tests/includes/class-wta-test-http.php` answers
every request or refuses it.

## CI

`.github/workflows/tests.yml` runs the suite on PHP 8.2, 8.3, 8.4 and 8.5
against the latest WordPress, and on PHP 8.2 against WordPress 6.8. The
`PHPUnit` job is the single check to require.

PHPUnit stays on 9.6: the WordPress test library still calls PHPUnit APIs
that were removed in PHPUnit 10.

None of `composer.*`, `phpunit.xml.dist`, `tests/`, `vendor/` or this file is
shipped in the release zip.
