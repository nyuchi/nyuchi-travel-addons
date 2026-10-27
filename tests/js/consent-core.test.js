/**
 * Pure functions of assets/js/wta-consent.js, run with plain Node:
 *   node tests/js/consent-core.test.js
 * No test runner or dependency needed. Exits non-zero on the first failure.
 */
"use strict";

const assert = require("node:assert/strict");
const path = require("node:path");
const core = require(
  path.join(__dirname, "..", "..", "assets", "js", "wta-consent.js"),
);

const NOW = Date.UTC(2026, 0, 1);
const ts = Math.floor(NOW / 1000);
const good = {
  version: "1",
  ts: ts - 86400,
  analytics: true,
  marketing: false,
  id: "abc",
};

// readCookie
assert.equal(
  core.readCookie("a=1; wta_consent=%7B%7D; b=2", "wta_consent"),
  "%7B%7D",
);
assert.equal(
  core.readCookie("xwta_consent=1", "wta_consent"),
  null,
  "a name that merely ends the same is not a match",
);
assert.equal(core.readCookie("", "wta_consent"), null);

// serialize / parse round trip, plain and URL-encoded
const json = core.serialize(good);
assert.deepEqual(core.parse(json, "1", 180, NOW), good);
assert.deepEqual(core.parse(encodeURIComponent(json), "1", 180, NOW), good);

// rejected: missing, malformed, wrong shape, other policy version, expired
assert.equal(core.parse(null, "1", 180, NOW), null);
assert.equal(core.parse("", "1", 180, NOW), null);
assert.equal(core.parse("{not json", "1", 180, NOW), null);
assert.equal(core.parse("[1,2]", "1", 180, NOW), null);
assert.equal(core.parse('"x"', "1", 180, NOW), null);
assert.equal(
  core.parse(json, "2", 180, NOW),
  null,
  "a new policy version asks again",
);
assert.equal(
  core.parse(
    core.serialize(Object.assign({}, good, { ts: ts - 181 * 86400 })),
    "1",
    180,
    NOW,
  ),
  null,
  "past its lifetime",
);
assert.notEqual(
  core.parse(
    core.serialize(Object.assign({}, good, { ts: ts - 179 * 86400 })),
    "1",
    180,
    NOW,
  ),
  null,
);
assert.equal(
  core.parse(core.serialize(Object.assign({}, good, { ts: 0 })), "1", 180, NOW),
  null,
);

// numeric version in the cookie still matches a string setting
assert.notEqual(
  core.parse(
    '{"version":1,"ts":' + ts + ',"analytics":1,"marketing":0}',
    "1",
    180,
    NOW,
  ),
  null,
);

// truthiness is strict: strings are not consent
const strict = core.parse(
  '{"version":"1","ts":' + ts + ',"analytics":"yes","marketing":"true"}',
  "1",
  180,
  NOW,
);
assert.equal(strict.analytics, false);
assert.equal(strict.marketing, false);

// signals
assert.deepEqual(core.signals({ analytics: true, marketing: false }), {
  analytics_storage: "granted",
  ad_storage: "denied",
  ad_user_data: "denied",
  ad_personalization: "denied",
});
assert.deepEqual(core.signals({ analytics: false, marketing: true }), {
  analytics_storage: "denied",
  ad_storage: "granted",
  ad_user_data: "granted",
  ad_personalization: "granted",
});
assert.equal(core.signals(null).analytics_storage, "denied");

// cookie string: first party, Lax, path /, lifetime in seconds, Secure on https only
const c = core.cookieString("wta_consent", '{"a":1}', 180, true);
assert.match(
  c,
  /^wta_consent=%7B%22a%22%3A1%7D; path=\/; max-age=15552000; SameSite=Lax; Secure$/,
);
assert.doesNotMatch(
  core.cookieString("wta_consent", "x", 180, false),
  /Secure/,
);

// uuid: version 4 shape
for (let i = 0; i < 20; i++) {
  assert.match(
    core.uuid(),
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
  );
}
assert.notEqual(core.uuid(), core.uuid());

console.log("consent core: ok");
