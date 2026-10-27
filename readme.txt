=== Nyuchi Travel Addons - Trip Tools for WP Travel ===
Contributors: nyuchi
Tags: wp travel, travel, itinerary, taxonomy, rest api
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.21.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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

= Compatibility helpers =

Small helpers for WP Travel companion plugins: the single-trip timeline stylesheet, and a
scan for itinerary day text that breaks WT Widgets for Elementor older than 1.4.8 (the printf
defect fixed upstream, WP Travel ticket #2051). The runtime guard that worked around that
defect has been removed. It also declares the jQuery dependency that WT Widgets for
Elementor 1.4.9 leaves out of its slick carousel scripts, which can be switched off with the
wta_compat_slick_jquery_fix filter. Helpers are deleted once they are no longer needed.

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

= Does the plugin contact GitHub? =

Yes, for two things, and neither sends anything about your site. The updater checks the
repository's latest release so WordPress can offer updates. The Travel Addons screen shows
the repository's GitHub star count with a link to star it; that is an anonymous request
for the public repository record, made only when the screen is opened, cached for 12
hours, and it carries no site URL, no version numbers and no identifier. There is no
telemetry or usage ping. To hide the star count, add
`define( 'WTA_HIDE_STAR_COUNT', true );` to wp-config.php or return false from the
`wta_show_star_count` filter.

== Changelog ==

= 1.21.0 =
* Fix: the WorkOS sign-in page no longer gets stuck on "The user must complete an MFA challenge to finish authenticating" when multi-factor sign-in is required (off by default, the `workos_mfa` module). It turns that WorkOS reply into the authenticator-code step the page already has. The second factor is still checked by WorkOS.
* New: sign-in page styling module (off by default). Gives the WorkOS sign-in page and the standard wp-login.php form the site look: sand background, the site's display and body fonts, a white card and navy pill buttons. The one-time code field is drawn as six boxes. Adjust it with the `wta_login_style_css` filter.

= 1.20.0 =
* New: login hardening module (off by default). Hides the public list of usernames in the REST API (`/wp-json/wp/v2/users`) from visitors and closes XML-RPC, the two doors password-guessing bots use first. Editors and admins still see the user list. Keep either open with the `wta_hardening_hide_rest_users` or `wta_hardening_close_xmlrpc` filter.
* New: with the llms.txt module on, the home page sends `Link` headers pointing to `/llms.txt`, `/llms-full.txt` and the sitemap.

= 1.19.0 =
* New: a complete llms.txt for AI assistants (off by default). Serves /llms.txt, listing every trip by country with its length and from-price, the destinations, key pages, park guides, articles and policies, and /llms-full.txt with each trip's overview, itinerary and inclusions. Cached and rebuilt when content changes. Yoast SEO writes its own llms.txt as a physical file, so turn that off for this one to be reached.
* Change: the AI crawler policy module (off by default) now welcomes every AI crawler, training bots included, instead of blocking training bots. It names them with an explicit Allow in robots.txt, declares `Content-Signal: search=yes, ai-input=yes, ai-train=yes` in robots.txt and as a response header (which overrides the default Cloudflare adds to its Markdown responses) and adds the sitemap. Block a bot with the `wta_ai_blocked_bots` filter.

= 1.18.0 =
* New: an AI crawler policy module for robots.txt (off by default).
* Fix: trip pages no longer tell search engines and AI assistants that every trip costs 0. The TouristTrip schema now carries the trip's from-price per person sharing (the same figure the trip cards show), is left without an offer when a trip has no price, and no longer shows "&amp;" literally in day titles. Switch off with the `wta_compat_trip_schema_fix` filter.



= 1.17.0 =
* Fix: the home page hero video no longer shows an untappable play button on phones that refuse to autoplay it. The button is hidden and, if playback does not start, the poster image shows instead. Switch off with the `wta_compat_hero_video_fallback` filter.

= 1.16.0 =
* New: a cookie consent banner. It asks first-time visitors, and everyone again after you
  change the policy version, to accept all, decline (necessary cookies only) or choose
  analytics and marketing separately. Accept and Decline look and weigh the same, it works
  with the keyboard and screen readers, and on phones it is a bottom sheet that does not
  cover the page. The choice is remembered in a first-party cookie for 180 days by
  default. Off by default; switch it on from the new Consent tab, where the wording,
  privacy policy link, categories, position and a live preview are set.
* New: Google Consent Mode v2. Before a choice, Google tags are told that advertising and
  analytics storage are denied, then updated when the visitor chooses. The banner works
  with a cached site, because the visitor's choice is handled in the browser. Google Tag
  Manager is not added by this plugin; the container must read Consent Mode (see the README).
* New: a [wta_cookie_settings] shortcode and window.wtaConsent.open() to reopen the
  choices, a wta_consent_script() helper and data-wta-consent attribute to hold back other
  scripts until a category is allowed, and an optional Meta Pixel loader that only loads
  after marketing consent and respects withdrawal.
* New: an optional consent log (off by default) that keeps a random id, the time, the
  policy version, the choices, a salted and shortened IP hash and the browser family for 13
  months, with WordPress export and erase support, and a consent-status ability. The Consent
  tab lists other consent tools (Usercentrics, Cloudflare Zaraz, Complianz and others) that
  would show a second banner. This is a tool, not legal advice: have the wording reviewed.

= 1.15.0 =
* Fixed: removes a JavaScript error ("jQuery is not defined") that appeared in the browser
  console on every page. WT Widgets for Elementor loads its slick carousel scripts without
  telling WordPress they need jQuery, so they ran too early. The compatibility module now
  declares that dependency, only for those scripts and only when it is missing. No change
  to how any page looks.

= 1.14.0 =
* New: a progressive web app module that fixes WP Travel's built-in PWA. It serves a
  current web app manifest, a service worker and an offline page from the plugin, so
  guests can install the site and still see a friendly page without a connection. Pages
  always come from the network, so a returning visitor is never shown a stale page; only
  static files are cached, and every release clears old caches. It never touches wp-admin,
  login, the REST API, forms, checkout or enquiry pages. Off by default; switch it on from
  the new PWA tab, where the name, colours, icons and offline message are set.
* Fixed: returning visitors could keep seeing an out-of-date homepage because WP Travel's
  old /sw.js stays in the web root after its PWA is switched off. The plugin now replaces
  that file (only when it is WP Travel's own) with a worker that removes itself and its
  caches from visitors' browsers, and tells you in wp-admin what it did. If the file
  cannot be written, the notice gives the path and the script to paste in. This does not
  depend on the PWA module being on.
* New: the pwa-status ability reports the PWA setup, icon checks and the old worker's state.

* New: country FAQs. Destinations have an FAQs field (question and answer pairs, edited
  on the term screen, over REST, in the term export and import, and through update-term),
  and a new Term FAQ Elementor widget shows them as an accessible accordion. On a trip or
  a place it shows its country's FAQs, so each country has one FAQ source. The widget
  publishes a single FAQPage schema block and leaves it out when another plugin or widget
  on the page already publishes one.
* Changed: destination terms exported before this release show as changed after it,
  because the export now includes the FAQs. Export again before importing.
= 1.12.0 =
* Changed: requires PHP 8.2 or later (it was 7.4). Sites on older PHP need to upgrade
  PHP first; WordPress will not install this update on PHP older than 8.2.
* Fixed: updates now arrive automatically. The updater works during background update
  checks (scheduled checks, automatic updates, WP-CLI and REST), not only while someone
  is in wp-admin, so new releases appear without waiting for an admin visit.
* Fixed: the GitHub token (WTA_GITHUB_TOKEN, used only for a private repository) could
  be sent to other hosts. It now goes only to GitHub's API over HTTPS, and it is removed
  when GitHub redirects a download to its file storage.
* Fixed: users without permission to publish trips can no longer publish them through
  the create-trip and update-trip abilities. A request to publish, schedule or make a
  trip private without that permission is refused, rather than going live.
* Changed: users who may create trips but not publish them (contributors, on a default
  site) can now create draft and pending trips through create-trip.
* Changed: update-trip no longer moves trips to the bin. Bin a trip from the Trips screen.
* New: a Star on GitHub link, with the current star count, at the foot of the Travel
  Addons screen and on the Plugins screen. It sends nothing about your site and can be
  hidden with `define( 'WTA_HIDE_STAR_COUNT', true );` in wp-config.php.
* Behind the scenes: an automated test suite now runs against a real WordPress on every
  change, and the build tooling is up to date. The tests are not part of the installed
  plugin.

= 1.11.0 =
* Changed: trip cards follow Mzizi's card spec (14px radius, 14px padding, 12px
  image corners).
* Changed: the trip hero shows trip images without a colour overlay at a fluid,
  vertically centred size, with the caption under the image and a readable first
  headline line. Spacing and typography are device-aware.
* Fixed: the Booking Order, Field Notes, Route, Seasonality, Legs and Options
  widgets render nothing for visitors when a trip has no data. The "not added yet"
  prompt shows only in the Elementor editor.
* Fixed: 44px touch targets for month cells and leg links, and gallery layout on
  small screens.

= 1.10.0 =
* New: conditional asset loading module, off until switched on from the Modules tab.
  It keeps the WP Travel booking widget, Google Maps and WP Travel Pro scripts off
  pages that cannot use them. Single trips, trip archives and taxonomies, WP Travel
  checkout, cart and account pages, /my-bookings/, /thank-you/ and any page using a
  WP Travel shortcode keep everything.
* New: export and import trips as JSON or CSV (export-trips, import-trips,
  export-trips-csv, import-trips-csv). Update-only and dry run by default; a trip
  edited after the export is refused unless forced; new trips are created only with
  allow_create and are always drafts. Slugs, statuses, pricing and dates are never
  changed by an import.
* New: export and import destination and activity terms as JSON or CSV
  (export-terms, import-terms, export-terms-csv, import-terms-csv). Update-only,
  never creates terms or changes a slug, parent or publication state.
* New: set-trip-price writes one price row, confined to its trip, dry run by default.
* Changed: update-itinerary now defaults to a dry run. Pass dry_run false to write.
  Anything that called it without dry_run and relied on it writing must be updated.
* Changed: modules can declare a default, so an update never switches on a module
  that changes what the front end loads.
* Fixed: set-trip-terms with append reports the complete resulting set, with added
  and removed term IDs, instead of only the terms supplied.
* Removed: the WT Widgets for Elementor printf guard, fixed upstream in 1.4.8
  (WP Travel ticket 2051). scan-compat remains as a diagnostic for older versions
  and no longer returns guard_active.
* Developer: tools/trips_xlsx.py converts the CSV files to a formatted Excel
  workbook and back. It is a repository helper and is not part of the plugin zip.

= 1.9.0 =
* Maintenance release: CI runs on staging, staging merges are tagged as
  patches and releases to main as minors (org versioning policy). No
  plugin code changes.

= 1.8.1 =
* Security: the seasonality verdict panel and the options widget no longer
  assign author HTML to innerHTML. Verdicts and option bodies are still stored
  through wp_kses_post, and the front end now also parses them in an inert
  document and rebuilds them from an allow-list of inline and block tags,
  keeping only safe link hrefs, before they reach the page.
* CI runs with a read-only token.

= 1.8.0 =
* The wta_cost block is gone: the meta key, its REST field, the estimator
  widget, the trip-editor panel and the front-end arithmetic. WP Travel already
  owns pricing in its own pricing tables, and WP Travel Pro owns deposit terms.
  A third cost model in this plugin's meta was a second home for a number that
  can only have one, and two homes for one price drift apart.
* This plugin exists to add the trip data WP Travel does not hold. Pricing is
  not that data, so it does not belong here.
* Nothing is migrated because nothing was stored. The block was empty on every
  trip it was checked against, so there is no data to move and no shim to keep.
* The Elementor template no longer carries a cost section, and the diagnostics
  no longer report a wta_cost block shadowing WP Travel's pricing table, because
  there is no longer a block to shadow it with.

= 1.7.0 =
* Column counts are set per device. They were fixed at whatever the desktop
  control said, and the tablet and phone counts were hardcoded in the
  stylesheet, out of reach of anyone editing the page. A four-column grid that
  stays four columns on a phone gives each card about eighty pixels.
* Four grids had no stylesheet rules at all - activity cards, destinations,
  gallery and the experience strip all rendered unstyled. They now share the
  same grid model as the trip cards.
* Gallery spacing is set per device for the same reason.
* Columns are driven by a custom property rather than a data attribute. An
  attribute carries one value for every screen, which is why the breakpoints
  had to be written into the stylesheet in the first place.

= 1.6.0 =
* Expose WP Travel and WP Travel Pro through the Abilities API: trips, pricing,
  dates, taxonomies, Pro module detection and diagnostics.
* Pricing and dates are read by discovering the real table columns at call time
  rather than assuming a shape. A schema copied from one WP Travel version is
  correct until the day it changes and then fails silently.
* WP Travel's own storage is read and written in place. This plugin adds the
  trip data WP Travel does not hold; it does not keep a second copy of the data
  WP Travel does.
* WP Travel is not a hard dependency. Every ability reports its absence rather
  than fataling, and Pro is detected by whether its class actually loaded, not
  merely whether WordPress was told to activate it.
* Diagnostics distinguish WP Travel from WP Travel Engine, a different plugin
  that leaves a similarly named option behind. Reporting one as the other was a
  real defect during development.

= 1.5.0 =
* Widgets can choose which image size to load. Every widget asked for 'large'
  and nothing offered a way to change it, so a grid of small cards fetched the
  same file as a full-width hero.
* The control lists the sizes the site actually registers and labels each with
  its dimensions, because "medium" does not tell anyone whether it is bigger
  than the card it is going into.
* The default for each widget is whatever that widget already requested, so an
  existing placement renders exactly as it did before until the size is
  changed deliberately.

= 1.4.0 =
* Style controls on all thirteen widgets. Every widget shipped with its
  controls in the Content tab and its appearance fixed in a stylesheet, so
  there was nothing to adjust without editing CSS.
* Responsive throughout. Nothing was registered responsively before, so a
  setting applied at every screen width - the tablet and mobile switches in
  the editor had no effect on any of these widgets.
* Control over how an image sits inside its frame, not just the frame. The
  frame owns the aspect ratio; the image now has its own object-fit and
  object-position, so a landscape photo in a portrait card can keep the sky
  instead of being cropped from the centre with no way to say otherwise.
* Aspect ratio, corner radius, CSS filters and hover zoom per device.
* Typography, colour and spacing per text element, plus padding, background,
  border, radius, shadow and grid gap on the repeating boxes.
* Registered once in a shared trait rather than copied into each widget, so
  the same control cannot come to mean different things in different widgets.
* Fixed the reason none of this would have worked: the stylesheet set
  aspect-ratio through descendant selectors that outrank the CSS Elementor
  generates for a control. Those are now defaults held in custom properties,
  which a control can override. The controls would otherwise have appeared to
  work in the editor and changed nothing on the page.

= 1.3.8 =
* The active tab in the admin rendered square while every other tab and button
  was a pill. After a click the active tab is also the focused one, and the
  focus ring used outline - which has only followed border-radius since Safari
  16.4 and not at all in some older engines, so it painted a rectangle around a
  pill. The ring now uses box-shadow, which has always followed the radius.
* A transparent outline is kept alongside it deliberately: Windows High
  Contrast Mode drops box-shadow entirely and forces transparent outlines to a
  visible colour, so removing it would make focus invisible to the people who
  most depend on seeing it.
* Tabs take the same pill radius as the buttons beside them.
* The admin submenu highlight no longer sticks on Overview. Every submenu item
  registers as the same page slug with a different &tab=, and WordPress decides
  which one is current by comparing that slug against a value holding only the
  page query var - identical for all of them - so the first entry matched every
  time. The plugin now tells WordPress which item the current screen belongs
  to.
* The Nyuchi wordmark is capitalised. It was being lowercased in CSS, which
  rendered the company name as a styling choice rather than a name.

= 1.3.7 =
* Styles WP Travel's own itinerary timeline on single trips, so a trip rendered
  without an Elementor template looks like the same website as one that is.
* Replaces a block that had been living in Customizer > Additional CSS. That
  copy carried a media query missing its opening brace, which a CSS parser
  cannot recover from - the selector is absorbed into the media condition, the
  condition becomes invalid, and the rule never applies at any width. The
  mobile padding it set had silently never worked.
* The timeline spine was declared `border-left: 2px solid` with no colour, so
  it resolved to currentColor and changed with the surrounding text. It now
  takes a brand colour.
* Switchable through the wta_compat_timeline_css filter, and loaded only on a
  trip rather than site-wide.

= 1.3.6 =
* Licensed GPLv2 or later, which is what wordpress.org expects of a plugin in
  the directory. 1.3.4 moved to MIT; MIT is GPL-compatible, but GPLv2-or-later
  is the convention for WordPress plugins and the safe answer for a submission.
* Full licence text added to the repository.

= 1.3.5 =
* Legs are chosen by day, not by index. A leg stores a position in WP Travel's
  itinerary array, and that position is not the day number - an entry labelled
  "Day 8 & 9" fills one slot but covers two days, so from there on the index
  trails the day. The editor now lists the real day labels to pick from, which
  removes the arithmetic that made an off-by-two easy and invisible.
* The trip editor reports legs that no longer fit the itinerary, which happens
  whenever days are added, removed or reordered after the legs were set.
* A leg that runs past the end of the itinerary is trimmed to the days that
  exist, and one with nothing left to show is dropped rather than rendered as
  an empty stage.

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
