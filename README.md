# Nyuchi Travel Addons

> Trip tools for WP Travel — a REST-accessible trip schema, an itinerary model
> WP Travel does not have, publication state for taxonomy terms, and Elementor
> widgets to render the lot.

[![Lint](https://github.com/nyuchi/nyuchi-travel-addons/actions/workflows/lint.yml/badge.svg)](https://github.com/nyuchi/nyuchi-travel-addons/actions/workflows/lint.yml)
[![CI](https://github.com/nyuchi/nyuchi-travel-addons/actions/workflows/ci.yml/badge.svg)](https://github.com/nyuchi/nyuchi-travel-addons/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL_v2_or_later-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-21759B?style=flat-square&logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)

**Version:** 1.12.0 | **Requires:** WordPress 5.9+, PHP 8.2+, an active [WP Travel](https://wptravel.io) | **Tested up to:** WordPress 7.0 | **Releases:** [GitHub Releases](https://github.com/nyuchi/nyuchi-travel-addons/releases)

---

## What it is

WP Travel Addons extends **WP Travel** (wptravel.io, by WEN Solutions) with
things that plugin does not provide: a REST-accessible trip schema, a richer
itinerary model, publication state for taxonomy terms, structured destination
and activity metadata, classification diagnostics, Elementor widgets, an
Abilities API surface, and compatibility helpers for WP Travel companion
plugins.

## What it is not

This is **not** an addon for **WP Travel Engine**. WP Travel Engine is a
different plugin by a different vendor with a different meta schema, and none of
the meta keys this plugin reads and writes exist there. Installing this
alongside WP Travel Engine will do nothing useful. This is the single most
common confusion about the two products, so check which one the site actually
runs before going further.

The trip post type is `itineraries`, and every trip meta key begins
`wp_travel_`. If the site's trips live in a post type called `trip` and their
meta keys begin `wte_`, the site is running WP Travel Engine and this plugin is
the wrong tool.

## Requirements

WordPress 5.9 or later, PHP 8.2 or later, and an active WP Travel installation.
The PHP minimum was raised from 7.4 to 8.2: a breaking requirement change for
sites on older PHP, shipping in the next minor release. There is no maximum PHP
version.
Each module checks that the trip post type exists before registering anything,
so the plugin stays inert rather than erroring if WP Travel is deactivated.

The term publication state module hooks `wpseo_robots_array`, which is a Yoast
SEO filter. Without Yoast that particular safety net is absent; the 404 on draft
term archives still applies.

## Install

Copy the plugin directory to `wp-content/plugins/wp-travel-addons` and activate
it from the Plugins screen, or install the zip from
[Releases](https://github.com/nyuchi/nyuchi-travel-addons/releases) through
**Plugins → Add New → Upload Plugin**.

Activate WP Travel first. The plugin loads on `plugins_loaded` at priority 20
and every module checks that the trip post type exists before registering
anything, but leaving WP Travel inactive simply means nothing happens.

Activation seeds every module option to on, seeds `wta_status_taxonomies` from
the WP Travel taxonomy list, records the version, and flushes rewrite rules.
The flush is necessary because term publication state changes what is publicly
queryable.

The plugin wires GitHub Releases into WordPress's update machinery, so later
versions show up as a normal update notice.

## The modules

Each module is switchable independently through the option
`wta_module_<key>`, which defaults to on. The point of the plugin is to sit
alongside someone else's product without becoming load-bearing in ways that are
hard to back out of, so any single module can be turned off without disturbing
the others.

| Key                | Module                          | What it does                                                                                                                    |
| ------------------ | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `trip_meta`        | Trip REST schema                | Exposes WP Travel trip fields and the day-by-day itinerary through the REST API                                                 |
| `itinerary`        | Itinerary schema                | Trip data WP Travel does not store: legs, route stops, month-by-month suitability, traveller choices, field notes               |
| `trip_editor`      | Itinerary editor                | Puts the itinerary schema on the trip edit screen, so an itinerary can be authored without a REST client                        |
| `elementor`        | Elementor widgets               | A widget per itinerary section, so one trip template can be composed and edited in Elementor                                    |
| `term_media`       | Term images and descriptions    | Featured image and dependable description for terms, falling back to a trip so no archive renders blank                         |
| `term_fields`      | Destination and activity detail | Country, gateway airport, currency and best months; activity duration, difficulty, minimum age                                  |
| `term_faq`         | Country FAQs                    | The Term FAQ widget: a country's FAQs as an accessible accordion, plus one FAQPage schema graph that yields to other FAQ schema |
| `term_status`      | Term publication state          | Adds live/draft status to taxonomy terms so a term can exist before it is public                                                |
| `audit`            | Classification diagnostics      | Reports flat hierarchies, empty terms, non-segmenting terms and cross-taxonomy duplicates                                       |
| `travel_abilities` | WP Travel abilities             | Exposes WP Travel and WP Travel Pro over the Abilities API: trips, pricing, dates, taxonomies, diagnostics                      |
| `compat`           | Compatibility helpers           | Trip timeline stylesheet, and a scan for day text that breaks WT Widgets older than 1.4.8                                       |
| `assets`           | Conditional asset loading       | Dequeues the WP Travel booking widget, Google Maps and WP Travel Pro bundles on pages that cannot use them. **Off by default**  |
| `pwa`              | Progressive web app             | Installable, offline-capable site: manifest, service worker and offline page served by the plugin. **Off by default**          |

The `assets` module is the one exception to "every module defaults to on": it
changes what the front end loads, so it stays off until switched on from the
Modules tab. It keeps the full asset set on single trips, trip archives and
taxonomies, WP Travel checkout/cart/account pages, `/my-bookings/`,
`/thank-you/`, any page whose content mentions a WP Travel shortcode, and
Elementor/Customizer previews. What it removes, and where it keeps everything,
is adjustable through the `wta_assets_rules`, `wta_assets_keep_paths`,
`wta_assets_keep_shortcodes` and `wta_assets_needed` filters. It only
dequeues, never deregisters.

### Progressive web app (the `pwa` module)

WP Travel's built-in PWA has three faults: its service worker caches `/` when
it installs and answers cache-first, so a returning visitor can be shown an
out-of-date homepage indefinitely; it has no offline page and no error
handling; and its `/sw.js` is a physical file in the web root that stays
behind, still running in visitors' browsers, after the PWA setting is turned
off. The `pwa` module replaces it. It is **off by default**; switch it on from
the **PWA** tab (the same switch is on the Modules tab).

What it serves, all from the plugin and nothing written to the web root:

| URL                         | What                                                              |
| --------------------------- | ----------------------------------------------------------------- |
| `/wta-manifest.webmanifest` | The web app manifest, built from the settings                     |
| `/wta-sw.js`                | The service worker, with `Service-Worker-Allowed: /` and no-cache |
| `/wta-offline`              | The page shown when the network is down                           |

Requests are matched on `parse_request`, so no rewrite rules are added and
nothing needs flushing. Without pretty permalinks, or with the **Endpoint
style** set to query URLs, the same three are served at
`/?wta_pwa=manifest|sw|offline`. Use that if a web server answers a missing
`.js` or `.webmanifest` URL with its own 404 before WordPress sees it.

Settings (PWA tab): name, short name, description, theme and background
colour, start URL, display mode, the 192 px, 512 px and maskable 512 px icons
from the media library, the offline page title, message and image, a cache
version and the endpoint style. An icon slot left empty falls back to the
WordPress site icon (which WordPress crops to 192 and 512). The maskable icon
never falls back, because only a person can check its safe zone. Every icon is
checked (square, large enough, PNG or WebP) and the manifest declares its real
size; the tab says what is wrong, and the manifest leaves out an icon that
would make it invalid.

The page head gets `<link rel="manifest">`, `theme-color` and, when no site
icon is set (WordPress prints its own when one is), `apple-touch-icon`. The
registration script is printed in the footer, with a feature check, only when
the module is on, and never in wp-admin or for signed-in users.

How the worker behaves:

- **Pages are never served stale.** A navigation always goes to the network.
  Only when that fetch fails (offline) does the worker answer, with the
  precached offline page. HTML is never stored in a cache.
- **Static files** under `/wp-content/` (scripts, styles) are served
  stale-while-revalidate; fonts and images are cache-first, each cache with a
  maximum number of entries, a maximum size per file and an expiry.
- **Never touched:** `/wp-admin`, `wp-login.php`, `/wp-json`, `admin-ajax.php`,
  every request that is not a GET, any URL whose query string contains
  `nonce` or `preview`, and enquiry, checkout, cart, booking and account
  pages. Signed-in users are not registered. Extend the list with the
  `wta_pwa_exclude_paths` filter.
- **Releases invalidate old caches.** Cache names include the plugin version,
  the **Cache version** setting and a hash of the offline page, and the worker
  deletes every `wta-pwa-` cache that is not current when it activates. It
  calls `skipWaiting()` and `clients.claim()`, so a new worker takes over at
  once.
- **Two PWAs never run at once.** While WP Travel's own PWA is on
  (`wp_travel_settings.enable_pwa` is `yes`) the module prints no manifest and
  registers no worker, and an admin notice says why.
- **Switching the module off cleans up after itself.** Once the worker has
  been served, `/wta-sw.js` answers with the kill-switch script while the
  module is off, so browsers that registered it remove it.

#### Removing WP Travel's old service worker

This runs whether or not the module is on, because the stale worker is already
in visitors' browsers. When WP Travel's PWA setting is not `yes`, and
`ABSPATH/sw.js` contains WP Travel's signature
(`const staticWPTravel = "wp-travel"`), the file's contents are replaced with a
kill-switch worker (`assets/js/wta-sw-kill.js`) that deletes all caches,
unregisters itself and reloads open pages. A `sw.js` without the signature is
never changed. It is written through `WP_Filesystem`, once on `admin_init`
(again only if the file changes, or after an update following a failure), and
the result is kept in the `wta_pwa_legacy` option and shown in an admin
notice. The PWA tab shows the state and a **Remove old service worker**
button (nonce, `manage_options`). If the file cannot be written, the notice
gives the exact path and the script to paste in. Disable all of it with
`add_filter( 'wta_pwa_legacy_cleanup', '__return_false' );`.

The `nyuchi-travel/pwa-status` ability (`manage_options`, read-only) reports
whether the module is on and active, the manifest, worker and offline URLs,
the cache build, each icon check and the state of the old `sw.js`.

Filters: `wta_pwa_defaults`, `wta_pwa_settings`, `wta_pwa_manifest`,
`wta_pwa_categories`, `wta_pwa_exclude_paths`, `wta_pwa_cache_limits`,
`wta_pwa_precache`, `wta_pwa_font_hosts`, `wta_pwa_register_logged_in`,
`wta_pwa_wp_travel_active`, `wta_pwa_legacy_cleanup`, `wta_pwa_legacy_sw_path`.

### Trip abilities added with the WP Travel abilities module

- `nyuchi-travel/export-trips` downloads up to 25 trips as one JSON document
  (post fields, WP Travel meta, days, itinerary groups, terms by slug, and
  read-only pricing and dates for context). No booking or enquiry data.
- `nyuchi-travel/import-trips` applies an edited document back. Dry run by
  default. It updates existing trips and never changes their slug or status,
  creates terms, or writes pricing or dates. A trip modified after the export
  is refused unless `force` is set. With `allow_create`, an entry without an
  id creates a new trip, always as a draft.
- `nyuchi-travel/export-trips-csv` and `nyuchi-travel/import-trips-csv` do the
  same through two spreadsheet-friendly files: `trips.csv` (one row per trip:
  post fields, WP Travel meta, terms as slugs joined by `|`) and `days.csv`
  (one row per day). A blank cell leaves a value unchanged; write `[clear]` to
  clear a meta field or term list. A trip with rows in `days.csv` has its whole
  day list replaced. The itinerary groups (hero, legs, route, seasonality,
  options, checklist, notes) are JSON only. CSV import is a converter onto the
  JSON import, so it has the same safeguards.
- `nyuchi-travel/export-terms`, `import-terms`, `export-terms-csv` and
  `import-terms-csv` do the same for destination and activity terms: name,
  archive description and the structured fields (country, gateway airport,
  languages, currency, visa note, best months, duration, difficulty, minimum
  age, typical cost, permit, standout, long description). Import is update-only
  and dry run by default; it never creates terms and never changes a slug,
  parent or publication state. Each exported term carries an `etag`, and a term
  changed since the export is refused unless `force` is set. In CSV, months are
  numbers joined by `|` and `permit_required` is `yes` or `no`.
- `nyuchi-travel/set-trip-price` writes one price row, confined to its trip by
  joining the price row to its pricing option and the option to the trip. Dry
  run by default. Administrators only.

Three further components are always loaded: a REST module, an Abilities module
and an admin module. They read module state rather than adding behaviour of
their own.

Post type and taxonomy names are held in one place, `WTA_Trip`, so an upstream
schema change is a one-file edit rather than a hunt:

| Name         | Slug               | Filter                |
| ------------ | ------------------ | --------------------- |
| Trips        | `itineraries`      | `wta_trip_post_type`  |
| Destinations | `travel_locations` | `wta_trip_taxonomies` |
| Activities   | `activity`         | `wta_trip_taxonomies` |
| Trip Types   | `itinerary_types`  | `wta_trip_taxonomies` |
| Keywords     | `travel_keywords`  | `wta_trip_taxonomies` |

## The trip REST schema

WP Travel stores trip data in a mix of plain meta, protected meta and one
PHP-serialised array, and registers none of it for REST. The whole trip
catalogue is therefore out of reach of API clients out of the box.

Calling `register_post_meta()` with `show_in_rest` is not enough on its own.
`WP_REST_Posts_Controller` only adds the `meta` object to a resource when the
post type declares support for `custom-fields`, and WP Travel registers
`itineraries` without it. Every meta registration is silently inert until that
support exists — the meta is registered, but never appears in the response. The
module therefore calls `add_post_type_support()` on `init` at priority 20: after
WP Travel has registered the post type, and before REST builds its schema on
`rest_api_init`.

Fields are registered in three groups, sanitised according to what they hold.

Scalars, sanitised with `sanitize_text_field`:

```text
wp_travel_trip_price
wp_travel_trip_duration
wp_travel_trip_duration_night
wp_travel_group_size
wp_travel_trip_code
wp_travel_location
wp_travel_lat
wp_travel_lng
wp_travel_fixed_departure
wp_travel_trip_map_use_lat_lng
```

Rich text, sanitised with `wp_kses_post` so the markup the theme renders
survives:

```text
wp_travel_overview
wp_travel_outline
wp_travel_trip_include
wp_travel_trip_exclude
```

Protected Yoast SEO keys, writable only by a user who can edit the trip:

```text
_yoast_wpseo_title
_yoast_wpseo_metadesc
_yoast_wpseo_focuskw
_yoast_wpseo_primary_travel_locations
_yoast_wpseo_primary_activity
_yoast_wpseo_primary_itinerary_types
```

> **Warning.** Protected meta with `show_in_rest` enabled is returned to any
> reader, authenticated or not. The `auth_callback` gates writes, not reads.
> Only keys whose values are already public in the page source are exposed
> here. Adding your own keys through `wta_trip_meta_fields` means publishing
> them, so do not add anything you would not print in the page.

### The itinerary field

The day-by-day itinerary is stored PHP-serialised in
`wp_travel_trip_itinerary_data`. Exposing that through `register_post_meta()`
would hand clients a raw serialised string, and a malformed write would corrupt
the trip. It is exposed instead as a proper JSON array field, `itinerary_days`,
registered with `register_rest_field()`:

```json
{
  "itinerary_days": [
    {
      "label": "Day 1",
      "title": "Arrival in Harare",
      "desc": "<p>Transfer to the lodge.</p>",
      "date": "",
      "time": ""
    }
  ]
}
```

Reads normalise every day block to those five string keys, so a client never
has to defend against missing ones. Writes require `edit_post` on the trip,
reject anything that is not an array with HTTP 400, sanitise `label`, `title`,
`date` and `time` as plain text and `desc` as post HTML, and fall back to
`Day N` when a block arrives without a label.

### Duration is stored twice

WP Travel writes trip duration as two scalars, `wp_travel_trip_duration` and
`wp_travel_trip_duration_night`, and again as a serialised mirror in
`wp_travel_trip_duration_formating`. The mirror is what the front end actually
renders. Writing only the scalars leaves the trip meta strip showing stale
values, so the module watches `added_post_meta` and `updated_post_meta` for
either scalar and rewrites the mirror from both.

Pricing is left to WP Travel. The plugin does not keep a cost field of its own.

## Term publication state

WordPress has no draft state for terms. A term exists and is public the moment
it is created, which makes it impossible to build a destination tree out ahead
of the content: every empty term is immediately a thin, indexable archive.

This module adds live/draft to terms in the configured taxonomies, stored in
term meta under `_wta_status`. Live is stored as the absence of the flag, so a
term with no stored status is treated as live and the module changes nothing
until someone deliberately drafts something.

A draft term stays fully editable and assignable in wp-admin. What changes is
what the public sees:

- It is excluded from `get_terms()` results, which covers menus, widgets,
  filter dropdowns, term clouds and the REST terms endpoints.
- Its archive returns 404 through `template_redirect`.
- It is marked `noindex, nofollow` via `wpseo_robots_array`, in case something
  else serves the archive before `template_redirect` can 404 it.

Suppression applies only to requests from users who cannot `edit_posts`, and
never inside wp-admin outside AJAX. An editor building the tree keeps seeing
drafts and can preview them; visitors, anonymous REST consumers and search
engines do not.

The term edit screens gain a Publication state select, and the term list tables
gain a State column immediately after the name. Saving the field requires
`manage_categories`. The taxonomies covered are held in the option
`wta_status_taxonomies`, which is seeded on activation from the four WP Travel
taxonomies.

## Elementor widgets

The `elementor` module registers one widget per itinerary section, so a single
trip template can be composed and edited in Elementor rather than in a theme
file: hero, legs, route, seasonality, options, checklist, notes, gallery,
trip card, destination grid, activity cards, experience strip and term FAQ. Each widget
carries style and responsive controls, including per-device grid columns and a
choice of which registered image size to load.

### Country FAQs

Each country has one FAQ source: the **FAQs** field on its term (Trips >
Destinations > edit a country), a repeater of question and answer pairs. Answers
keep basic formatting (paragraphs, bold, italic, lists, links). The values are
stored as JSON in the term meta `wta_faqs`, and are available over REST as
`wta_faqs`, in the term export and import (JSON, and CSV as one JSON cell in
`field:faqs`), and through the `update-term` ability.

The **Term FAQ** widget (`wta-term-faq`) shows them:

- On a country archive it shows that country's FAQs. On a trip, or on a place
  beneath a country, it walks up the term's ancestors to the nearest one that has
  FAQs. On a regular page, which has no destination, choose the destination in
  the widget's Source control.
- It renders native `<details>` and `<summary>` items, styled with the itinerary
  design tokens, and prints nothing when there are no FAQs.
- It publishes **one** FAQPage JSON-LD graph in the footer. Several widgets merge
  into it, and a question is never published twice. It is left out when Yoast SEO
  (including its FAQ block), Rank Math, an Elementor accordion or toggle with its
  FAQ schema switch on, or markup in the content already publishes FAQPage schema.

Filters: `wta_term_faq_term` (the term to look up from), `wta_term_faq_schema`
(the graph; return null to publish none), `wta_term_faq_schema_enabled`,
`wta_term_faq_other_schema_present`, `wta_faq_allowed_html` and
`wta_faq_max_items` (default 50).

Two Elementor dynamic tags are also registered, for term text and term images,
and `templates/elementor/single-itinerary.json` is an importable template that
assembles the widgets into a working trip page.

## Standard components

### Trip card: the way to list trips

The **Trip Cards** widget (`wta-trip-cards`) is the one way to list trips on
a site, whether that is a home page row, a destination archive or a related
trips block. Do not rebuild a trip list from Elementor's Portfolio or Posts
widgets with custom CSS.

Each card is a single link to the trip. Its accessible name reads title,
price, then duration, for example "Serengeti Migration, From $2,450 per person
sharing, 10 days". The price is always the starting-from price, never a range:
`From $X` with a small qualifier beneath it. A trip with no price, or a price
of zero, shows the request label instead of a number. A trip with only days
set shows "10 days"; with only nights, "3 nights". When there is neither a
duration nor a price line, the meta row is left out.

| Control               | Default              | What it does                                                                                                              |
| --------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `count`               | 6                    | How many trips (1 to 48)                                                                                                  |
| `columns`             | 3 / 2 / 1            | Columns on desktop, tablet and mobile                                                                                     |
| `orderby`             | `date`               | Newest first, title, manual order or random                                                                               |
| `taxonomy`, `terms`   | none                 | Restrict to term slugs in one taxonomy                                                                                    |
| `filter_taxonomy`     | none                 | Also require a term, for example "featured"; `filter_terms` narrows it, `filter_fallback` relaxes it when nothing matches |
| `use_archive`         | on                   | On a destination, activity or trip-type archive, show that term's trips and honour paging                                 |
| `current_terms`       | off                  | On a single trip, show other trips sharing its terms                                                                      |
| `style`               | `overlay`            | `overlay` (text over image) or `stacked` (text below image)                                                               |
| `ratio`               | `3-4`                | Image shape: `1-1`, `4-3`, `3-4` or `16-9`                                                                                |
| `show_duration`       | on                   | Duration line                                                                                                             |
| `show_price`          | on                   | Starting-from price                                                                                                       |
| `show_destination`    | on                   | Destination badge on the image                                                                                            |
| `show_excerpt`        | off                  | Trip summary                                                                                                              |
| `cta`                 | `View itinerary`     | Button label                                                                                                              |
| `price_request_label` | `Price on request`   | Shown in place of the price when a trip has none                                                                          |
| `price_suffix`        | `per person sharing` | Small qualifier after "From $X". Editable; leave empty to hide                                                            |
| `cta_request`         | `Request price`      | Button label for a trip with no price                                                                                     |
| `footnote`            | empty                | Short note printed once under the grid, only when at least one card shows a price                                         |

The widget's own defaults for `style` and `ratio` are `overlay` and `3-4`.
This site's trip lists use **`style` stacked, `ratio` 4-3, `columns` 3 / 2 / 1,
`cta` "View itinerary" and `cta_request` "Request price"**, so set those when
adding the widget to a page, and fill in `footnote` wherever a price shows.

Prices go through WP Travel's own formatter (`wptravel_get_formated_price_currency`,
or `wp_travel_get_formated_price_currency` on older releases), so the symbol
and separators match the rest of the site. If neither function exists the card
formats the number itself, using WP Travel's currency symbol or `$`; the
`wta_trip_card_currency_symbol` filter overrides the symbol.

## REST API reference

The plugin extends the core REST endpoints for the trip post type and its
taxonomies rather than introducing a parallel API.

| Method | Route                                  | Purpose                                                                                       |
| ------ | -------------------------------------- | --------------------------------------------------------------------------------------------- |
| `GET`  | `/wp-json/wp/v2/itineraries`           | Trip collection, each item carrying `meta` and `itinerary_days`                               |
| `GET`  | `/wp-json/wp/v2/itineraries/<id>`      | Single trip                                                                                   |
| `POST` | `/wp-json/wp/v2/itineraries/<id>`      | Write trip meta and `itinerary_days`. Requires `edit_post`                                    |
| `GET`  | `/wp-json/wp/v2/travel_locations`      | Destination terms, each carrying `wta_status`. Drafts are omitted for unauthenticated callers |
| `POST` | `/wp-json/wp/v2/travel_locations/<id>` | Write `wta_status`. Requires `manage_categories`                                              |

The same term routes apply to `activity`, `itinerary_types` and
`travel_keywords`, wherever WP Travel exposes the taxonomy in REST.

Fields added to those resources:

| Field            | Resource | Type             | Notes                                                        |
| ---------------- | -------- | ---------------- | ------------------------------------------------------------ |
| `meta`           | trip     | object           | Present only because the module adds `custom-fields` support |
| `itinerary_days` | trip     | array of objects | `label`, `title`, `desc`, `date`, `time`                     |
| `wta_status`     | term     | string           | `live` or `draft`                                            |

Error responses raised by the plugin:

| Code             | Status | Cause                                                                                |
| ---------------- | ------ | ------------------------------------------------------------------------------------ |
| `wta_forbidden`  | 403    | Caller lacks `edit_post` on the trip, or `manage_categories` for a term state change |
| `wta_bad_format` | 400    | `itinerary_days` was not an array                                                    |
| `wta_bad_status` | 400    | `wta_status` was neither `live` nor `draft`                                          |

Reading a trip:

```bash
curl -s "https://example.com/wp-json/wp/v2/itineraries/123?_fields=id,title,meta,itinerary_days"
```

Writing the itinerary:

```bash
curl -s -X POST "https://example.com/wp-json/wp/v2/itineraries/123" \
  -u "user:application-password" \
  -H "Content-Type: application/json" \
  -d '{"itinerary_days":[{"label":"Day 1","title":"Arrival"}]}'
```

Drafting a term:

```bash
curl -s -X POST "https://example.com/wp-json/wp/v2/travel_locations/45" \
  -u "user:application-password" \
  -H "Content-Type: application/json" \
  -d '{"wta_status":"draft"}'
```

## Filters

| Filter                 | Returns | Purpose                                                                                          |
| ---------------------- | ------- | ------------------------------------------------------------------------------------------------ |
| `wta_trip_post_type`   | string  | The trip post type slug. Default `itineraries`. The slug has differed between WP Travel versions |
| `wta_trip_taxonomies`  | array   | Taxonomy slug to human label. Drives both term publication state and the audit                   |
| `wta_trip_meta_fields` | array   | The `text`, `html` and `protected` field groups registered for REST                              |

Adding a key to `wta_trip_meta_fields` publishes it. See the warning above.

Options the plugin owns:

| Option                  | Purpose                                                           |
| ----------------------- | ----------------------------------------------------------------- |
| `wta_module_<key>`      | Per-module switch, one per module in the table above, seeded to 1 |
| `wta_status_taxonomies` | Taxonomies that carry publication state                           |
| `wta_pwa_settings`      | PWA module settings (the PWA tab)                                 |
| `wta_pwa_legacy`        | Result of the last old-`sw.js` clean-up                           |
| `wta_version`           | Installed version                                                 |

## Versioning

Releases follow the org versioning policy
([nyuchi/.github#80](https://github.com/nyuchi/.github/issues/80)). The
branch decides the bump, and there are no version labels:

- **A merge into `staging`** (the live beta) is tagged as the next
  **patch** automatically, by `staging-version.yml`.
- **A release to `main`** is the next **minor** above the highest tag. The
  PR that releases sets `WTA_VERSION` in `wp-travel-addons.php` to it. CI refuses any other version
  and names the right one.
- **A major** is only ever made by hand: the owner runs the release
  workflow from the Actions tab with `bump: major`.
- Each segment holds 0–999. Patch 999 rolls into the next minor; minor 999
  stops and asks for a manual major.

Versions released before 2026-10-04 are not renumbered.

## Licence

Licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html);
see [LICENSE](https://github.com/nyuchi/nyuchi-travel-addons/blob/main/LICENSE).

© Nyuchi Web Services. Developed by Bryan Fawcett
([@bryanfawcett](https://github.com/bryanfawcett)).
