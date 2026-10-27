/**
 * The inline Consent Mode snippet printed in the head (WTA_Consent::defaults_js(),
 * written to a file by the PHP test), run against a fake document:
 *   node tests/js/head-snippet.test.js <snippet.js>
 */
"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const code = fs.readFileSync(process.argv[2], "utf8");

function run(cookie) {
  const sandbox = { document: { cookie }, window: {} };
  sandbox.window = sandbox;
  sandbox.dataLayer = undefined;
  vm.runInNewContext(code, sandbox);
  return sandbox.dataLayer.map((a) => Array.from(a));
}

// No cookie: the defaults only, denied, with the update window.
let calls = run("");
assert.equal(calls[0][0], "consent");
assert.equal(calls[0][1], "default");
assert.deepEqual(
  {
    a: calls[0][2].ad_storage,
    u: calls[0][2].ad_user_data,
    p: calls[0][2].ad_personalization,
    s: calls[0][2].analytics_storage,
  },
  { a: "denied", u: "denied", p: "denied", s: "denied" },
);
assert.equal(calls[0][2].functionality_storage, "granted");
assert.equal(calls[0][2].security_storage, "granted");
assert.equal(calls[0][2].wait_for_update, 500);
assert.equal(calls.filter((c) => c[1] === "update").length, 0);

// A current choice is applied straight after the defaults.
const choice = encodeURIComponent(
  JSON.stringify({
    version: "1",
    ts: 1,
    analytics: true,
    marketing: false,
    id: "x",
  }),
);
calls = run("a=b; wta_consent=" + choice);
const update = calls.find((c) => c[1] === "update");
assert.ok(update, "a stored choice produces an update");
assert.equal(
  calls.indexOf(update) > 0,
  true,
  "the update comes after the default",
);
assert.equal(update[2].analytics_storage, "granted");
assert.equal(update[2].ad_storage, "denied");

// A choice for another policy version is ignored.
const old = encodeURIComponent(
  JSON.stringify({
    version: "0",
    ts: 1,
    analytics: true,
    marketing: true,
    id: "x",
  }),
);
assert.equal(
  run("wta_consent=" + old).filter((c) => c[1] === "update").length,
  0,
);

// A corrupt cookie does not throw.
assert.doesNotThrow(() => run("wta_consent=%E0%A4%A"));

console.log("head snippet: ok");
