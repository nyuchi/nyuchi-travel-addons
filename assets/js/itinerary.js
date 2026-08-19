/*
 * Nyuchi Travel Addons — itinerary front end.
 *
 * One file, no jQuery, no build step. Every behaviour is opt-in: it only runs
 * when its markup is on the page, so this script is safe to enqueue site-wide.
 *
 * Everything is scoped to a container rather than to the document, because an
 * Elementor user can drop the same widget onto a page twice and each copy has
 * to behave independently.
 *
 * Class names and state classes come from design/itinerary-reference.css; the
 * JSON payloads come from the meta registered in class-wta-itinerary-schema.php.
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------- utilities */

  /**
   * The nearest sensible boundary for "this widget instance".
   *
   * Elementor wraps each widget in .elementor-widget, which is the natural
   * scope. The other selectors are fallbacks for markup rendered outside
   * Elementor (a shortcode, a theme template, the design reference page).
   */
  function scopeOf(el) {
    return (
      el.closest('[data-wta-root]') ||
      el.closest('.elementor-widget') ||
      el.closest('.wta-section') ||
      el.closest('.wta-itin') ||
      document.documentElement
    );
  }

  function all(root, sel) {
    return Array.prototype.slice.call(root.querySelectorAll(sel));
  }

  /** Parse a JSON <script> payload. A malformed payload must not break the page. */
  function payload(script) {
    if (!script) {
      return null;
    }

    try {
      return JSON.parse(script.textContent || 'null');
    } catch (e) {
      return null;
    }
  }

  /**
   * Bind-once guard. Elementor re-renders widgets in the editor, and this
   * script re-initialises on those events, so every binder has to be safe to
   * call again on markup it has already seen.
   */
  function claim(el, flag) {
    if (el.getAttribute(flag) === '1') {
      return false;
    }

    el.setAttribute(flag, '1');

    return true;
  }

  /** Reduced motion is a comfort setting, not a preference we get to override. */
  function scrollBehaviour() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
      ? 'auto'
      : 'smooth';
  }

  function reveal(el) {
    el.scrollIntoView({ block: 'start', behavior: scrollBehaviour() });
  }

  /** localStorage throws outright in Safari private browsing, not just on quota. */
  function storeRead(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function storeWrite(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (e) {
      /* Persistence is a convenience; losing it must not break the widget. */
    }
  }

  /**
   * A money formatter for one currency.
   *
   * Intl throws a RangeError on anything that is not a valid ISO 4217 code, and
   * the currency is author-entered free text, so the constructor is guarded and
   * falls back to a plain grouped number with the code in front.
   */
  function money(currency) {
    var code = String(currency || '').trim().toUpperCase();
    var fmt = null;
    var plain = null;

    try {
      fmt = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: code,
        maximumFractionDigits: 0
      });
    } catch (e) {
      fmt = null;
    }

    try {
      plain = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
    } catch (e) {
      plain = null;
    }

    return function (n) {
      var v = Number(n) || 0;

      if (fmt) {
        return fmt.format(v);
      }

      var body = plain ? plain.format(v) : String(Math.round(v));

      return code ? code + ' ' + body : body;
    };
  }

  /* -------------------------------------------------------- a. day accordions */

  /*
   * Delegated at the document, which is legitimate here because the toggle only
   * ever touches the button's own parent — no cross-instance state exists, and
   * delegation covers day cards Elementor inserts after load.
   */
  function bindDays() {
    if (!claim(document.documentElement, 'data-wta-days')) {
      return;
    }

    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('button') : null;

      if (!btn) {
        return;
      }

      var day = btn.parentElement;

      if (!day || !day.classList.contains('wta-day')) {
        return;
      }

      var open = day.classList.toggle('is-open');

      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ----------------------------------------------------------- b. seasonality */

  var MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];

  var TAG_GO = /best|primary|peak|driest|begin/i;
  var TAG_NO = /avoid/i;

  function bindSeasonality(script) {
    var root = scopeOf(script);

    // Claimed on the payload element, not the scope: two widgets can share a
    // fallback scope, and only the script is guaranteed to be one per instance.
    if (!claim(script, 'data-wta-season')) {
      return;
    }

    var data = payload(script);

    if (!data || !Array.isArray(data.months) || !data.months.length) {
      return;
    }

    var months = data.months;
    var buttons = all(root, '.wta-monthbtn');
    var cells = all(root, '.wta-mcell');
    var monthEl = root.querySelector('.wta-verdict-month');
    var bodyEl = root.querySelector('.wta-verdict-body p');
    var tagsEl = root.querySelector('.wta-verdict-tags');

    /**
     * The authored rank wins; where none was authored the scores decide, so a
     * month that is bad across the board is never shown as merely "workable".
     */
    function rankLabel(month) {
      if (month.rank === 'primary') {
        return 'Primary window';
      }

      if (month.rank === 'alternative') {
        return 'Strong alternative';
      }

      var scores = month.scores && typeof month.scores === 'object' ? month.scores : {};
      var sum = 0;

      Object.keys(scores).forEach(function (k) {
        sum += Number(scores[k]) || 0;
      });

      return sum <= 2 ? 'Not recommended' : 'Workable';
    }

    function select(index) {
      var i = Math.max(0, Math.min(months.length - 1, index));
      var month = months[i] || {};

      buttons.forEach(function (b, n) {
        b.setAttribute('aria-pressed', n === i ? 'true' : 'false');
      });

      if (monthEl) {
        // The <small> rank sits inside .wta-verdict-month, so only the leading
        // text node is replaced — rewriting innerHTML would destroy it.
        var first = monthEl.firstChild;

        if (first && first.nodeType === 3) {
          first.nodeValue = MONTHS[i] || '';
        } else {
          monthEl.insertBefore(document.createTextNode(MONTHS[i] || ''), monthEl.firstChild);
        }

        var small = monthEl.querySelector('small');

        if (small) {
          small.textContent = rankLabel(month);
        }
      }

      if (bodyEl) {
        // Verdict text is stored through wp_kses_post, so inline mark-up is
        // both expected and already sanitised server side.
        bodyEl.innerHTML = month.verdict || '';
      }

      if (tagsEl) {
        tagsEl.textContent = '';

        (Array.isArray(month.tags) ? month.tags : []).forEach(function (tag) {
          var span = document.createElement('span');

          span.className = 'wta-tag';
          span.textContent = tag;

          if (TAG_GO.test(tag)) {
            span.setAttribute('data-t', 'go');
          } else if (TAG_NO.test(tag)) {
            span.setAttribute('data-t', 'no');
          }

          tagsEl.appendChild(span);
        });
      }
    }

    buttons.forEach(function (b, i) {
      b.addEventListener('click', function () {
        select(i);
      });
    });

    /*
     * Cells are laid out row by row across twelve months, so the month a cell
     * belongs to is its position modulo twelve — no per-cell attribute needed.
     * An explicit data-month wins where the renderer provides one.
     */
    cells.forEach(function (cell, n) {
      var attr = cell.getAttribute('data-month');
      var index = attr === null ? n % 12 : parseInt(attr, 10);

      cell.addEventListener('click', function () {
        select(index);
      });
    });

    // Start on whichever month the server marked as pressed, else January.
    var initial = 0;

    buttons.some(function (b, i) {
      if (b.getAttribute('aria-pressed') === 'true') {
        initial = i;

        return true;
      }

      return false;
    });

    select(initial);
  }

  /* ------------------------------------------------------------ c. route map */

  function bindRoute(list) {
    var root = scopeOf(list);

    if (!claim(list, 'data-wta-route')) {
      return;
    }

    var items = all(list, '.wta-mapitem');
    var stops = all(root, '.wta-stop');

    // Legs live in their own widget, so look wider than the map's own scope.
    var legScope = list.closest('.wta-itin') || document;

    function legFor(index, el) {
      var legs = all(legScope, '.wta-leg');

      if (!legs.length) {
        return null;
      }

      // Stops carry a leg index in the schema; where the renderer has emitted
      // it, honour it. Otherwise fall back to positional matching.
      var attr = el && el.getAttribute('data-leg');
      var n = attr === null || attr === '' ? index : parseInt(attr, 10);

      return legs[n] || null;
    }

    /*
     * Matched by position rather than by name: names are author-entered text
     * that can repeat or be reworded, whereas both lists are rendered from the
     * same ordered route array.
     */
    function highlight(index, on) {
      var stop = stops[index];

      if (stop) {
        stop.classList.toggle('is-on', on);
      }

      var item = items[index];

      if (item) {
        item.classList.toggle('is-on', on);
      }
    }

    function go(index, el) {
      var leg = legFor(index, el);

      if (leg) {
        reveal(leg);
      }
    }

    items.forEach(function (item, i) {
      item.addEventListener('mouseenter', function () {
        highlight(i, true);
      });

      item.addEventListener('mouseleave', function () {
        highlight(i, false);
      });

      item.addEventListener('focus', function () {
        highlight(i, true);
      });

      item.addEventListener('blur', function () {
        highlight(i, false);
      });

      item.addEventListener('click', function () {
        go(i, item);
      });
    });

    stops.forEach(function (stop, i) {
      stop.addEventListener('mouseenter', function () {
        highlight(i, true);
      });

      stop.addEventListener('mouseleave', function () {
        highlight(i, false);
      });

      stop.addEventListener('click', function () {
        go(i, stop);
      });

      // SVG <g> elements are not buttons, so keyboard activation is manual.
      // The renderer is expected to give them tabindex and a role.
      stop.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
          e.preventDefault();
          go(i, stop);
        }
      });
    });
  }

  /* --------------------------------------------------------- d. option picker */

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function bindOptions(script) {
    var root = scopeOf(script);

    if (!claim(script, 'data-wta-options')) {
      return;
    }

    var data = payload(script);
    var items = data && Array.isArray(data.items) ? data.items : Array.isArray(data) ? data : [];

    if (!items.length) {
      return;
    }

    var buttons = all(root, '.wta-option');
    var out = root.querySelector('.wta-optionout');

    function select(i) {
      buttons.forEach(function (b, n) {
        b.setAttribute('aria-pressed', n === i ? 'true' : 'false');
      });

      if (!out) {
        return;
      }

      var item = items[i] || {};
      var head = [item.name, item.subtitle].filter(Boolean).join(' · ');

      // Names are escaped because they are plain text fields; the body is
      // wp_kses_post HTML and is meant to keep its mark-up.
      out.innerHTML = '<b>' + esc(head) + '</b>' + (item.body || '');
    }

    buttons.forEach(function (b, i) {
      b.addEventListener('click', function () {
        select(i);
      });
    });

    var initial = 0;

    buttons.some(function (b, i) {
      if (b.getAttribute('aria-pressed') === 'true') {
        initial = i;

        return true;
      }

      return false;
    });

    select(initial);
  }

  /* -------------------------------------------------------- e. cost estimator */

  function bindCost(script) {
    var root = scopeOf(script);

    if (!claim(script, 'data-wta-cost')) {
      return;
    }

    var data = payload(script);

    if (!data || !Array.isArray(data.tiers) || !data.tiers.length) {
      return;
    }

    var tiers = data.tiers;
    var addons = Array.isArray(data.addons) ? data.addons : [];
    var fees = Number(data.fees) || 0;
    var fmt = money(data.currency);

    var readout = root.querySelector('.wta-readout');
    var amtEl = root.querySelector('.wta-amt');
    var groupEl = root.querySelector('.wta-groupline');
    var stepper = root.querySelector('.wta-stepper');
    var output = stepper ? stepper.querySelector('output') : null;

    var state = {
      tier: 0,
      addon: addons.map(function () {
        return 0;
      }),
      people: 2
    };

    /*
     * Segmented groups are matched to the payload by explicit attributes where
     * the renderer supplies them, and by document order otherwise: the tier
     * control is rendered first, then one group per add-on in payload order.
     */
    var groups = all(root, '.wta-seg');
    var tierGroup = null;
    var addonGroups = [];

    groups.forEach(function (group) {
      var addonAttr = group.getAttribute('data-addon');

      if (group.getAttribute('data-role') === 'tier') {
        tierGroup = group;
      } else if (addonAttr !== null && addonAttr !== '') {
        addonGroups[parseInt(addonAttr, 10)] = group;
      } else if (!tierGroup) {
        tierGroup = group;
      } else {
        addonGroups.push(group);
      }
    });

    function line(label, value) {
      var row = document.createElement('div');
      var a = document.createElement('span');
      var b = document.createElement('span');

      row.className = 'wta-costline';
      a.textContent = label;
      b.textContent = value;
      row.appendChild(a);
      row.appendChild(b);

      return row;
    }

    function perPerson() {
      var tier = tiers[state.tier] || {};
      var total = (Number(tier.land) || 0) + (Number(tier.flights) || 0) + fees;

      addons.forEach(function (addon, i) {
        var choice = (addon.choices || [])[state.addon[i]];

        total += choice ? Number(choice.price) || 0 : 0;
      });

      return total;
    }

    function render() {
      var tier = tiers[state.tier] || {};
      var total = perPerson();

      if (readout) {
        readout.textContent = '';
        readout.appendChild(line((tier.name || 'Land arrangements') + ' — land', fmt(Number(tier.land) || 0)));
        readout.appendChild(line('Internal flights', fmt(Number(tier.flights) || 0)));

        if (fees) {
          readout.appendChild(line('Park and conservation fees', fmt(fees)));
        }

        addons.forEach(function (addon, i) {
          var choice = (addon.choices || [])[state.addon[i]];

          if (!choice) {
            return;
          }

          readout.appendChild(line((addon.label || 'Option') + ' — ' + (choice.name || ''), fmt(Number(choice.price) || 0)));
        });
      }

      if (amtEl) {
        amtEl.textContent = fmt(total);
      }

      if (groupEl) {
        groupEl.textContent = state.people === 1
          ? 'Travelling alone — a single supplement applies and is not included above.'
          : state.people + ' travellers · ' + fmt(total * state.people) + ' total';
      }

      if (output) {
        output.textContent = String(state.people);
      }
    }

    if (tierGroup) {
      all(tierGroup, 'button').forEach(function (b, i) {
        b.addEventListener('click', function () {
          state.tier = i;

          all(tierGroup, 'button').forEach(function (o, n) {
            o.setAttribute('aria-pressed', n === i ? 'true' : 'false');
          });

          render();
        });
      });
    }

    addonGroups.forEach(function (group, gi) {
      if (!group) {
        return;
      }

      var buttons = all(group, 'button');

      buttons.forEach(function (b, i) {
        b.addEventListener('click', function () {
          state.addon[gi] = i;

          buttons.forEach(function (o, n) {
            o.setAttribute('aria-pressed', n === i ? 'true' : 'false');
          });

          render();
        });
      });
    });

    if (stepper) {
      var steps = all(stepper, 'button');

      steps.forEach(function (b, i) {
        // data-step where present, otherwise first button decrements and last
        // increments, which is the order the reference mark-up uses.
        var attr = b.getAttribute('data-step');
        var delta = attr !== null && attr !== '' ? parseInt(attr, 10) : (i === 0 ? -1 : 1);

        b.addEventListener('click', function () {
          // Ceiling comes from the widget, which owns the pricing model; 24 is
          // only the fallback when the markup does not declare one.
          var ceiling = parseInt(b.getAttribute('data-max') || out.getAttribute('data-max'), 10);
          if (!ceiling || ceiling < 1) { ceiling = 24; }
          state.people = Math.max(1, Math.min(ceiling, state.people + delta));
          render();
        });
      });

      if (output) {
        var seeded = parseInt(output.textContent, 10);

        if (!isNaN(seeded) && seeded > 0) {
          state.people = seeded;
        }
      }
    }

    // Adopt whatever the server rendered as pressed so the first paint matches.
    if (tierGroup) {
      all(tierGroup, 'button').some(function (b, i) {
        if (b.getAttribute('aria-pressed') === 'true') {
          state.tier = i;

          return true;
        }

        return false;
      });
    }

    addonGroups.forEach(function (group, gi) {
      if (!group) {
        return;
      }

      all(group, 'button').some(function (b, i) {
        if (b.getAttribute('aria-pressed') === 'true') {
          state.addon[gi] = i;

          return true;
        }

        return false;
      });
    });

    render();
  }

  /* --------------------------------------------------------------- f. checklist */

  function bindChecklist(bar) {
    var root = scopeOf(bar);

    if (!claim(bar, 'data-wta-check')) {
      return;
    }

    var checks = all(root, '.wta-check');

    if (!checks.length) {
      return;
    }

    var fill = bar.querySelector('i');
    var tripEl = root.closest('[data-trip]') || root.querySelector('[data-trip]');
    var key = tripEl ? 'wta-check-' + tripEl.getAttribute('data-trip') : null;

    function paint() {
      if (!fill) {
        return;
      }

      var done = checks.filter(function (c) {
        return c.getAttribute('aria-pressed') === 'true';
      }).length;

      fill.style.width = (done / checks.length) * 100 + '%';
    }

    function save() {
      if (!key) {
        return;
      }

      storeWrite(key, JSON.stringify(checks.map(function (c) {
        return c.getAttribute('aria-pressed') === 'true' ? 1 : 0;
      })));
    }

    if (key) {
      var raw = storeRead(key);

      if (raw) {
        try {
          var saved = JSON.parse(raw);

          if (Array.isArray(saved)) {
            checks.forEach(function (c, i) {
              c.setAttribute('aria-pressed', saved[i] ? 'true' : 'false');
            });
          }
        } catch (e) {
          /* A corrupt entry just means starting from an empty checklist. */
        }
      }
    }

    checks.forEach(function (c) {
      c.addEventListener('click', function () {
        c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
        paint();
        save();
      });
    });

    paint();
  }

  /* --------------------------------------------------------------------- init */

  function init(context) {
    var ctx = context && context.querySelectorAll ? context : document;

    bindDays();

    all(ctx, 'script.wta-season-data').forEach(bindSeasonality);
    all(ctx, '.wta-maplist').forEach(bindRoute);
    all(ctx, 'script.wta-options-data').forEach(bindOptions);
    all(ctx, 'script.wta-cost-data').forEach(bindCost);
    all(ctx, '.wta-progressbar').forEach(bindChecklist);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      init(document);
    });
  } else {
    init(document);
  }

  /*
   * Elementor re-renders a widget every time it is edited in the panel. The
   * binders are idempotent, so re-running on that hook costs nothing and keeps
   * the preview interactive.
   */
  window.addEventListener('elementor/frontend/init', function () {
    if (window.elementorFrontend && window.elementorFrontend.hooks) {
      window.elementorFrontend.hooks.addAction('frontend/element_ready/widget', function ($scope) {
        var el = $scope && $scope[0] ? $scope[0] : $scope;

        if (el && el.querySelectorAll) {
          init(el);
        }
      });
    }
  });
})();
