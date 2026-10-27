/**
 * Cookie consent banner (Nyuchi Travel Addons, "consent" module).
 *
 * The Mzizi cookie-consent design (role=dialog, aria-label, data-slot,
 * data-portal, a fixed bottom bar with Manage / Decline / Accept) in plain
 * JavaScript. No dependencies. The settings come from the JSON document
 * #wta-consent-config; the page itself is identical for every visitor, so a
 * page cache is unaffected.
 *
 * The pure functions at the top (cookie parsing, signals, expiry) are exported
 * for Node tests (tests/js/consent-core.test.js).
 */
(function () {
  "use strict";

  var DAY = 86400000;

  /* ---------------------------------------------------------------- core */

  function readCookie(header, name) {
    var parts = String(header || "").split("; ");
    for (var i = 0; i < parts.length; i++) {
      var eq = parts[i].indexOf("=");
      if (eq > 0 && parts[i].slice(0, eq) === name) {
        return parts[i].slice(eq + 1);
      }
    }
    return null;
  }

  /**
   * A stored choice from its raw (URL-encoded or plain) JSON, or null when it
   * is missing, malformed, from another policy version, or past its lifetime.
   */
  function parse(raw, version, lifetimeDays, now) {
    if (raw === null || raw === undefined || raw === "") {
      return null;
    }
    var data;
    try {
      var text = String(raw);
      data = JSON.parse(
        text.charAt(0) === "{" ? text : decodeURIComponent(text),
      );
    } catch (e) {
      return null;
    }
    if (!data || typeof data !== "object" || Array.isArray(data)) {
      return null;
    }
    if (String(data.version) !== String(version)) {
      return null;
    }
    var ts = Number(data.ts);
    if (!isFinite(ts) || ts <= 0) {
      return null;
    }
    if (lifetimeDays && (now || Date.now()) - ts * 1000 > lifetimeDays * DAY) {
      return null;
    }
    return {
      version: String(data.version),
      ts: ts,
      analytics: data.analytics === true || data.analytics === 1,
      marketing: data.marketing === true || data.marketing === 1,
      id: typeof data.id === "string" ? data.id : "",
    };
  }

  function serialize(state) {
    return JSON.stringify({
      version: state.version,
      ts: state.ts,
      analytics: !!state.analytics,
      marketing: !!state.marketing,
      id: state.id,
    });
  }

  /** Google Consent Mode v2 signals for a choice. */
  function signals(state) {
    var a = state && state.analytics ? "granted" : "denied";
    var m = state && state.marketing ? "granted" : "denied";
    return {
      analytics_storage: a,
      ad_storage: m,
      ad_user_data: m,
      ad_personalization: m,
    };
  }

  function cookieString(name, value, days, secure) {
    return (
      name +
      "=" +
      encodeURIComponent(value) +
      "; path=/; max-age=" +
      Math.round(days * 86400) +
      "; SameSite=Lax" +
      (secure ? "; Secure" : "")
    );
  }

  function uuid() {
    var c = typeof crypto !== "undefined" ? crypto : null;
    if (c && typeof c.randomUUID === "function") {
      return c.randomUUID();
    }
    var b = new Uint8Array(16);
    if (c && c.getRandomValues) {
      c.getRandomValues(b);
    } else {
      for (var i = 0; i < 16; i++) {
        b[i] = Math.floor(Math.random() * 256);
      }
    }
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = [];
    for (var j = 0; j < 16; j++) {
      h.push((b[j] + 256).toString(16).slice(1));
    }
    return (
      h.slice(0, 4).join("") +
      "-" +
      h.slice(4, 6).join("") +
      "-" +
      h.slice(6, 8).join("") +
      "-" +
      h.slice(8, 10).join("") +
      "-" +
      h.slice(10).join("")
    );
  }

  var core = {
    readCookie: readCookie,
    parse: parse,
    serialize: serialize,
    signals: signals,
    cookieString: cookieString,
    uuid: uuid,
  };

  if (typeof module === "object" && module.exports) {
    module.exports = core;
    return;
  }
  if (typeof window === "undefined" || typeof document === "undefined") {
    return;
  }

  /* ------------------------------------------------------------- browser */

  var cfg = null;
  var preview = false;
  var state = null;
  var root = null; // the dialog element
  var backdrop = null;
  var live = null; // status region
  var opener = null; // what had focus before the panel opened
  var listeners = [];
  var pixelReady = false;
  var pixelLoaded = false;

  function loadConfig() {
    var el = document.getElementById("wta-consent-config");
    if (!el) {
      return null;
    }
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  }

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === "text") {
        el.textContent = attrs[k];
      } else if (attrs[k] !== false && attrs[k] !== null) {
        el.setAttribute(k, attrs[k] === true ? "" : attrs[k]);
      }
    });
    (kids || []).forEach(function (kid) {
      if (kid) {
        el.appendChild(kid);
      }
    });
    return el;
  }

  /* -------------------------------------------------------------- storage */

  function secure() {
    return window.location.protocol === "https:";
  }

  function readStored() {
    var raw = readCookie(document.cookie, cfg.cookie);
    var s = parse(raw, cfg.version, cfg.lifetime);
    if (s) {
      return s;
    }
    try {
      s = parse(
        window.localStorage.getItem(cfg.cookie),
        cfg.version,
        cfg.lifetime,
      );
      if (s) {
        writeStored(s);
      }
    } catch (e) {
      s = null;
    }
    return s;
  }

  function writeStored(s) {
    var json = serialize(s);
    document.cookie = cookieString(cfg.cookie, json, cfg.lifetime, secure());
    try {
      window.localStorage.setItem(cfg.cookie, json);
    } catch (e) {
      /* storage blocked */
    }
  }

  /* ------------------------------------------------------------ signalling */

  function gtag() {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(arguments);
  }

  function applyChoice(s, source) {
    gtag("consent", "update", signals(s));
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      event: "wta_consent_update",
      wta_consent: {
        necessary: true,
        analytics: !!s.analytics,
        marketing: !!s.marketing,
        version: s.version,
        source: source,
      },
    });
    activateScripts(s);
    handlePixel(s);
    listeners.forEach(function (fn) {
      try {
        fn(publicState(s));
      } catch (e) {
        /* ignore */
      }
    });
  }

  function activateScripts(s) {
    var held = document.querySelectorAll(
      'script[type="text/plain"][data-wta-consent]',
    );
    for (var i = 0; i < held.length; i++) {
      var old = held[i];
      var cat = old.getAttribute("data-wta-consent");
      if (!s || (cat !== "analytics" && cat !== "marketing") || !s[cat]) {
        continue;
      }
      var next = document.createElement("script");
      for (var j = 0; j < old.attributes.length; j++) {
        var a = old.attributes[j];
        if (a.name !== "type" && a.name !== "data-wta-consent") {
          next.setAttribute(a.name, a.value);
        }
      }
      next.setAttribute("data-wta-consent-activated", cat);
      if (old.src && !old.hasAttribute("async")) {
        next.async = false;
      }
      if (!old.src) {
        next.text = old.text;
      }
      old.parentNode.replaceChild(next, old);
    }
  }

  function handlePixel(s) {
    if (!cfg.pixel) {
      return;
    }
    if (s && s.marketing) {
      if (!pixelLoaded) {
        loadPixel(cfg.pixel);
      } else if (window.fbq) {
        window.fbq("consent", "grant");
      }
    } else if (window.fbq) {
      window.fbq("consent", "revoke");
    }
  }

  function loadPixel(id) {
    pixelLoaded = true;
    if (!window.fbq) {
      var n = (window.fbq = function () {
        if (n.callMethod) {
          n.callMethod.apply(n, arguments);
        } else {
          n.queue.push(arguments);
        }
      });
      if (!window._fbq) {
        window._fbq = n;
      }
      n.push = n;
      n.loaded = true;
      n.version = "2.0";
      n.queue = [];
      var t = document.createElement("script");
      t.async = true;
      t.src = "https://connect.facebook.net/en_US/fbevents.js";
      var first = document.getElementsByTagName("script")[0];
      if (first && first.parentNode) {
        first.parentNode.insertBefore(t, first);
      } else {
        document.head.appendChild(t);
      }
    }
    window.fbq("consent", "grant");
    if (!pixelReady) {
      pixelReady = true;
      window.fbq("init", id);
      window.fbq("track", "PageView");
    }
  }

  function logChoice(s) {
    var l = cfg.log;
    if (!l || !l.enabled || !l.url || !window.fetch) {
      return;
    }
    if (l.dnt && (navigator.doNotTrack === "1" || window.doNotTrack === "1")) {
      return;
    }
    try {
      window
        .fetch(l.url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          credentials: "omit",
          keepalive: true,
          body: JSON.stringify({
            id: s.id,
            version: s.version,
            analytics: !!s.analytics,
            marketing: !!s.marketing,
          }),
        })
        .catch(function () {});
    } catch (e) {
      /* logging is best effort */
    }
  }

  function publicState(s) {
    return s
      ? {
          necessary: true,
          analytics: !!s.analytics,
          marketing: !!s.marketing,
          version: s.version,
        }
      : null;
  }

  function save(analytics, marketing) {
    var s = {
      version: cfg.version,
      ts: Math.floor(Date.now() / 1000),
      analytics: !!analytics,
      marketing: !!marketing,
      id: (state && state.id) || uuid(),
    };
    if (preview) {
      announce(cfg.labels.saved);
      return;
    }
    state = s;
    writeStored(s);
    applyChoice(s, "user");
    logChoice(s);
    close(true);
    announce(cfg.labels.saved);
  }

  /* ----------------------------------------------------------------- UI */

  function announce(text) {
    if (!live) {
      live = h("div", {
        class: "wta-consent-live",
        role: "status",
        "aria-live": "polite",
      });
      document.body.appendChild(live);
    }
    live.textContent = "";
    window.setTimeout(function () {
      live.textContent = text;
    }, 50);
  }

  function categoryOn(id) {
    return !!(state && state[id]);
  }

  function build() {
    var L = cfg.labels;
    var cats = (cfg.categories || []).filter(function (c) {
      return !c.required;
    });
    var hasManage = cats.length > 0;

    var privacy =
      cfg.privacy && cfg.privacy.url
        ? h("a", {
            href: cfg.privacy.url,
            class: "wta-consent__policy",
            text: cfg.privacy.label,
          })
        : null;
    var text = h("p", { id: "wta-consent-text", class: "wta-consent__text" }, [
      document.createTextNode(cfg.text + " "),
      privacy,
    ]);

    var accept = h("button", {
      type: "button",
      class: "wta-consent__btn",
      "data-action": "accept",
      text: L.accept,
    });
    var decline = h("button", {
      type: "button",
      class: "wta-consent__btn",
      "data-action": "decline",
      text: L.decline,
    });
    var manage = hasManage
      ? h("button", {
          type: "button",
          class: "wta-consent__link",
          "data-action": "manage",
          "aria-expanded": "false",
          "aria-controls": "wta-consent-panel",
          text: L.manage,
        })
      : null;
    var bar = h("div", { class: "wta-consent__bar" }, [
      text,
      h("div", { class: "wta-consent__actions" }, [manage, decline, accept]),
    ]);

    var list = h("ul", { class: "wta-consent__cats" });
    (cfg.categories || []).forEach(function (c) {
      var id = "wta-consent-cat-" + c.id;
      var input = h("input", {
        type: "checkbox",
        id: id,
        "data-cat": c.id,
        class: "wta-consent__switch",
        "aria-describedby": id + "-d",
        disabled: c.required ? true : false,
      });
      input.checked = c.required ? true : categoryOn(c.id);
      list.appendChild(
        h("li", { class: "wta-consent__cat" }, [
          h("div", { class: "wta-consent__cathead" }, [
            h("label", { for: id, text: c.label }),
            c.required
              ? h("span", { class: "wta-consent__always", text: L.always })
              : null,
            input,
          ]),
          h("p", { id: id + "-d", text: c.description }),
        ]),
      );
    });
    var panel = h(
      "div",
      { id: "wta-consent-panel", class: "wta-consent__panel", hidden: true },
      [
        h("h2", {
          id: "wta-consent-title",
          class: "wta-consent__title",
          tabindex: "-1",
          text: L.title,
        }),
        list,
        h(
          "div",
          { class: "wta-consent__actions wta-consent__actions--panel" },
          [
            h("button", {
              type: "button",
              class: "wta-consent__link",
              "data-action": "back",
              text: L.back,
            }),
            h("button", {
              type: "button",
              class: "wta-consent__btn",
              "data-action": "save",
              text: L.save,
            }),
          ],
        ),
      ],
    );

    var el = h(
      "div",
      {
        "data-slot": "cookie-consent",
        "data-portal": "https://mzizi.dev/components/cookie-consent",
        class:
          "wta-consent is-" +
          (cfg.position || "bottom") +
          (preview ? " is-preview" : ""),
        role: "dialog",
        "aria-label": L.dialog,
        "aria-describedby": "wta-consent-text",
      },
      [bar, panel],
    );

    el.addEventListener("click", onClick);
    el.addEventListener("keydown", onKey);
    return el;
  }

  function onClick(e) {
    var t = e.target.closest ? e.target.closest("[data-action]") : null;
    if (!t) {
      return;
    }
    var action = t.getAttribute("data-action");
    if (action === "accept") {
      save(true, true);
    } else if (action === "decline") {
      save(false, false);
    } else if (action === "manage") {
      showPanel();
    } else if (action === "back") {
      hidePanel();
    } else if (action === "save") {
      var on = function (id) {
        var i = root.querySelector('[data-cat="' + id + '"]');
        return !!(i && i.checked);
      };
      save(on("analytics"), on("marketing"));
    }
  }

  function focusables() {
    return Array.prototype.filter.call(
      root.querySelectorAll("button, a[href], input"),
      function (el) {
        return (
          !el.disabled &&
          el.getClientRects().length > 0 &&
          !el.closest("[hidden]")
        );
      },
    );
  }

  function onKey(e) {
    var panel = root.querySelector(".wta-consent__panel");
    if (panel.hidden) {
      return;
    }
    if (e.key === "Escape") {
      e.preventDefault();
      e.stopPropagation();
      hidePanel();
    } else if (e.key === "Tab") {
      var f = focusables();
      if (!f.length) {
        return;
      }
      var first = f[0];
      var last = f[f.length - 1];
      if (
        e.shiftKey &&
        (document.activeElement === first ||
          document.activeElement === panel.querySelector(".wta-consent__title"))
      ) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }

  function setPad(on) {
    var de = document.documentElement;
    if (preview) {
      return;
    }
    if (on) {
      var wide = root.classList.contains("is-bottom");
      var small =
        window.matchMedia && window.matchMedia("(max-width: 640px)").matches;
      if (wide || small) {
        de.style.setProperty("--wta-consent-h", root.offsetHeight + "px");
        de.classList.add("wta-consent-pad");
        return;
      }
    }
    de.classList.remove("wta-consent-pad");
    de.style.removeProperty("--wta-consent-h");
  }

  function showPanel() {
    var bar = root.querySelector(".wta-consent__bar");
    var panel = root.querySelector(".wta-consent__panel");
    var manage = root.querySelector('[data-action="manage"]');
    ["analytics", "marketing"].forEach(function (id) {
      var i = root.querySelector('[data-cat="' + id + '"]');
      if (i) {
        i.checked = categoryOn(id);
      }
    });
    bar.hidden = true;
    panel.hidden = false;
    root.setAttribute("aria-modal", "true");
    root.classList.add("is-panel");
    if (manage) {
      manage.setAttribute("aria-expanded", "true");
    }
    if (backdrop) {
      backdrop.hidden = false;
    }
    setPad(false);
    panel.querySelector(".wta-consent__title").focus();
  }

  function hidePanel() {
    var bar = root.querySelector(".wta-consent__bar");
    var panel = root.querySelector(".wta-consent__panel");
    var manage = root.querySelector('[data-action="manage"]');
    panel.hidden = true;
    root.removeAttribute("aria-modal");
    root.classList.remove("is-panel");
    if (backdrop) {
      backdrop.hidden = true;
    }
    if (!state && !preview) {
      // No choice yet: back to the bar, with focus on Manage.
      bar.hidden = false;
      if (manage) {
        manage.setAttribute("aria-expanded", "false");
        manage.focus();
      }
      setPad(true);
    } else if (preview) {
      bar.hidden = false;
    } else {
      close(true);
    }
  }

  function close(restoreFocus) {
    if (!root) {
      return;
    }
    var hadFocus = root.contains(document.activeElement);
    root.parentNode.removeChild(root);
    if (backdrop && backdrop.parentNode) {
      backdrop.parentNode.removeChild(backdrop);
    }
    root = null;
    backdrop = null;
    setPadOff();
    if (restoreFocus && hadFocus && opener && document.body.contains(opener)) {
      opener.focus();
    }
    opener = null;
  }

  function setPadOff() {
    document.documentElement.classList.remove("wta-consent-pad");
    document.documentElement.style.removeProperty("--wta-consent-h");
  }

  function mount(openPanel) {
    if (root) {
      if (openPanel && !preview) {
        showPanel();
      }
      return;
    }
    root = build();
    backdrop = h("div", { class: "wta-consent-backdrop", hidden: true });
    backdrop.addEventListener("click", function () {
      if (!root.querySelector(".wta-consent__panel").hidden) {
        hidePanel();
      }
    });
    var host = preview
      ? document.getElementById("wta-consent-preview")
      : document.body;
    if (!host) {
      root = null;
      return;
    }
    if (preview) {
      host.textContent = "";
      host.appendChild(root);
    } else {
      // First in the document so keyboard and screen reader users meet it
      // before the page; it is positioned at the bottom by CSS.
      host.insertBefore(backdrop, host.firstChild);
      host.insertBefore(root, host.firstChild);
    }
    if (openPanel) {
      showPanel();
    } else {
      setPad(true);
    }
  }

  /* ----------------------------------------------------------------- API */

  function open() {
    if (!cfg) {
      return;
    }
    opener =
      document.activeElement && document.activeElement !== document.body
        ? document.activeElement
        : null;
    mount(true);
  }

  function init() {
    cfg = loadConfig();
    if (!cfg) {
      return;
    }
    preview = !!cfg.preview;

    window.wtaConsent = {
      open: open,
      get: function () {
        return publicState(state);
      },
      has: function (cat) {
        return categoryOn(cat);
      },
      onChange: function (fn) {
        if (typeof fn === "function") {
          listeners.push(fn);
        }
      },
      version: cfg.version,
      preview: function (patch) {
        if (!preview) {
          return;
        }
        Object.keys(patch || {}).forEach(function (k) {
          cfg[k] = patch[k];
        });
        var panelOpen =
          root && !root.querySelector(".wta-consent__panel").hidden;
        close(false);
        mount(false);
        if (panelOpen) {
          showPanel();
        }
      },
      previewPanel: function (on) {
        if (!preview || !root) {
          return;
        }
        var panel = root.querySelector(".wta-consent__panel");
        var bar = root.querySelector(".wta-consent__bar");
        panel.hidden = !on;
        bar.hidden = !!on;
      },
    };

    // Any element can reopen the choices.
    document.addEventListener("click", function (e) {
      var t = e.target.closest
        ? e.target.closest(
            '[data-wta-consent-open], a[href="#wta-cookie-settings"]',
          )
        : null;
      if (t) {
        e.preventDefault();
        open();
      }
    });

    if (preview) {
      mount(false);
      return;
    }

    state = readStored();
    if (state) {
      applyChoice(state, "stored");
    } else {
      mount(false);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
