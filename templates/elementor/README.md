# Elementor template

`single-itinerary.json` is an Elementor **section** template containing the seven
itinerary widgets in reading order: hero, seasonality, route, legs, options,
checklist, notes. Each widget sits in its own full-width section with no
column gap, so the widgets' own full-bleed backgrounds run edge to edge.

## Importing

Elementor → Templates → Saved Templates → Import Templates, then upload
`single-itinerary.json`. It appears under Saved Templates as a section.

## Using it for every trip

The template is not a page. Create an Elementor Theme Builder **Single**
template, set its display condition to the `itineraries` post type, and insert
the imported section into it. Every trip of that post type then renders through
the same layout automatically.

## Per-trip content

Nothing is configured per trip. Each widget reads its content from the trip's
post meta — the fields registered by `WTA_Itinerary_Schema` — so the same
template produces a different page for every trip. A trip with no data for a
given section simply renders nothing for it.

## Bespoke pages

The widgets are independent. Any of them can be dragged onto an ordinary page or
a different Theme Builder template on its own, in any order, if a particular
trip needs a layout of its own. The widgets still read the same post meta, so
they need a trip context to render.
