=== Nyuchi Travel Addons - Trip Tools for WP Travel ===
Contributors: nyuchi
Tags: wp travel, travel, itinerary, taxonomy, rest api
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Adds a REST-accessible trip schema, draft state for taxonomy terms, and classification diagnostics to sites running WP Travel.

== Description ==

Nyuchi Travel Addons extends **WP Travel** (wptravel.io, by WEN Solutions) with the pieces
a larger travel catalogue needs once it is being maintained by more than one person or by
automation.

It is not connected to, endorsed by, or affiliated with WEN Solutions or WP Travel. It is
also **not** for WP Travel Engine, which is a separate plugin with a different data schema.

= Trip data over the REST API =

WP Travel stores trip fields as post meta on a post type registered without `custom-fields`
support. WordPress only adds the `meta` object to a REST resource when the post type
declares that support, so trip meta is registered but never appears in the API response.
This plugin adds the missing support and registers each trip field explicitly, with the
correct sanitisation for plain text and for HTML.

The day-by-day itinerary is stored as a PHP-serialised array. Handing that to an API client
as a raw serialised string invites corruption, so it is exposed instead as a proper JSON
array called `itinerary_days`, with each day an object of `label`, `title`, `desc`, `date`
and `time`. Writes are validated and re-serialised safely.

WP Travel also records trip duration twice: as two separate values, and again inside a
serialised array which is what the front end actually renders. Updating only the first pair
leaves the visible duration stale, so the plugin keeps the two in step automatically.

= Publication state for taxonomy terms =

WordPress has no draft state for taxonomy terms. A term is public the moment it is created,
which makes it impossible to build out a destination or activity tree ahead of the content
without also publishing a set of empty, thin archive pages.

This plugin adds a live/draft state to terms. A draft term stays fully editable and can be
assigned to trips, but it is excluded from term listings, its archive returns a 404, and it
is marked noindex. Users who can edit posts continue to see draft terms, so an editor can
review the structure before it goes public.

Terms with no stored state are treated as live, so installing the plugin changes nothing
until a term is explicitly set to draft.

= Classification diagnostics =

A report over the trip taxonomies covering hierarchical taxonomies left entirely flat, terms
with no content, terms applied so widely that they cannot segment anything, and the same
term name appearing in two taxonomies at once, which usually means two taxonomies are
competing to answer the same question.

= Compatibility guards =

Individually switchable workarounds for defects in third-party WP Travel companion plugins,
each documented in the code with the version it applies to. Guards are intended to be
removed once the upstream plugin ships a fix.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the Plugins
   screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Open **Travel Addons** in the admin menu to review which modules are active.

WP Travel must be installed and active. Each module can be switched off individually from
the Modules tab if you only want part of the plugin.

== Frequently Asked Questions ==

= Does this work with WP Travel Engine? =

No. WP Travel and WP Travel Engine are different plugins from different authors with
different meta schemas. This plugin targets WP Travel from wptravel.io. The trip post type
is filterable through `wta_trip_post_type` if your installation uses a different one.

= Will draft terms hide trips that are assigned to them? =

No. Draft state affects the term, not the content. Trips assigned to a draft term stay
published and visible; only the term's own archive page and its appearance in term lists
are suppressed.

= Are protected meta fields safe to expose? =

Read access to protected meta registered with `show_in_rest` is not restricted by the
authentication callback, which governs writes only. The plugin only exposes protected keys
whose values already appear in the public page source. Review the list before adding to it.

= Does the plugin modify WP Travel's data? =

It writes only through documented WordPress meta and term APIs, and only to fields WP Travel
already uses. The duration mirror is kept in sync because leaving it stale is a visible bug.

== Changelog ==

= 1.3.4 =
* Relicensed under MIT. MIT is GPL-compatible, so this remains valid for
  distribution alongside WordPress and for a wordpress.org submission.
* Corrected the Plugin URI, which pointed at a repository name that does not
  exist - the "Visit plugin site" link in the plugins screen was dead.
* Repository made public, which is what allows the built-in updater to see
  releases without a personal access token configured on each site.

= 1.3.3 =
* Bring the changelog up to date: 1.3.0 through 1.3.2 shipped without readme entries,
  so the "View details" panel in WordPress showed nothing for three releases.
* No functional change. This release also verifies the update path end to end -
  every prior version was installed by hand, so the GitHub release feed had never
  actually delivered an update to a site.

= 1.3.2 =
* Trip schema fields registered as post meta rather than REST-only fields, so
  automation clients can write them through the standard `meta` object.
* Route coordinates accept null, which is meaningful - it triggers position
  derivation from latitude and longitude rather than fixed placement.
* Seasonality scores allow dynamic month keys instead of a fixed schema.

= 1.3.1 =
* Activity cards for experiences such as ballooning, birding and wine tasting,
  carrying duration, difficulty and typical cost.
* Term images fall back to a trip within the term when the term has none of its own.
* "Best selling" scoped to featured trips within the current destination.

= 1.3.0 =
* Continuous integration across PHP 7.4 and 8.3, with a guard against version drift
  between the plugin header, WTA_VERSION and the readme stable tag.
* Release pipeline builds the distributable zip on tag and refuses to publish when
  the tag disagrees with the version in the code.
* Updates delivered through GitHub Releases, so sites see update notices and the
  one-click update works as it does for a directory plugin.

= 1.0.0 =
* Initial release.
* Trip fields and the day-by-day itinerary exposed through the REST API.
* Live/draft publication state for taxonomy terms, with a 404 and noindex on draft archives.
* Classification diagnostics across the trip taxonomies.
* Switchable compatibility guards for third-party companion plugins.
* Admin screen with per-module switches.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
