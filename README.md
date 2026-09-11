# Nyuchi Travel Addons

> Trip tools for WP Travel — a REST-accessible trip schema, an itinerary model
> WP Travel does not have, publication state for taxonomy terms, and Elementor
> widgets to render the lot.

[![Lint](https://github.com/nyuchi/nyuchi-travel-addons/actions/workflows/lint.yml/badge.svg)](https://github.com/nyuchi/nyuchi-travel-addons/actions/workflows/lint.yml)
[![CI](https://github.com/nyuchi/nyuchi-travel-addons/actions/workflows/ci.yml/badge.svg)](https://github.com/nyuchi/nyuchi-travel-addons/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL_v2_or_later-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-21759B?style=flat-square&logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)

**Version:** 1.8.0 | **Requires:** WordPress 5.9+, PHP 7.4+, an active [WP Travel](https://wptravel.io) | **Tested up to:** WordPress 7.0 | **Releases:** [GitHub Releases](https://github.com/nyuchi/nyuchi-travel-addons/releases)

---

## What it is

WP Travel Addons extends **WP Travel** (wptravel.io, by WEN Solutions) with
things that plugin does not provide: a REST-accessible trip schema, a richer
itinerary model, publication state for taxonomy terms, structured destination
and activity metadata, classification diagnostics, Elementor widgets, an
Abilities API surface, and compatibility guards for known defects in WP Travel
companion plugins.

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

WordPress 5.9 or later, PHP 7.4 or later, and an active WP Travel installation.
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

| Key                | Module                          | What it does                                                                                                      |
| ------------------ | ------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `trip_meta`        | Trip REST schema                | Exposes WP Travel trip fields and the day-by-day itinerary through the REST API                                   |
| `itinerary`        | Itinerary schema                | Trip data WP Travel does not store: legs, route stops, month-by-month suitability, traveller choices, field notes |
| `trip_editor`      | Itinerary editor                | Puts the itinerary schema on the trip edit screen, so an itinerary can be authored without a REST client          |
| `elementor`        | Elementor widgets               | A widget per itinerary section, so one trip template can be composed and edited in Elementor                      |
| `term_media`       | Term images and descriptions    | Featured image and dependable description for terms, falling back to a trip so no archive renders blank           |
| `term_fields`      | Destination and activity detail | Country, gateway airport, currency and best months; activity duration, difficulty, minimum age                    |
| `term_status`      | Term publication state          | Adds live/draft status to taxonomy terms so a term can exist before it is public                                  |
| `audit`            | Classification diagnostics      | Reports flat hierarchies, empty terms, non-segmenting terms and cross-taxonomy duplicates                         |
| `travel_abilities` | WP Travel abilities             | Exposes WP Travel and WP Travel Pro over the Abilities API: trips, pricing, dates, taxonomies, diagnostics        |
| `compat`           | Compatibility guards            | Works around known defects in WP Travel companion plugins                                                         |

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
trip card, destination grid, activity cards and experience strip. Each widget
carries style and responsive controls, including per-device grid columns and a
choice of which registered image size to load.

Two Elementor dynamic tags are also registered, for term text and term images,
and `templates/elementor/single-itinerary.json` is an importable template that
assembles the widgets into a working trip page.

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
| `wta_version`           | Installed version                                                 |

## Licence

Licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html);
see [LICENSE](https://github.com/nyuchi/nyuchi-travel-addons/blob/main/LICENSE).

© Nyuchi Web Services. Developed by Bryan Fawcett
([@bryanfawcett](https://github.com/bryanfawcett)).
