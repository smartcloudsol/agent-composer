import assert from "node:assert/strict";
import test from "node:test";
import * as core from "../dist/index.js";

test("core exposes only shared runtime constants", () => {
  assert.equal(core.CONTRACT_VERSION, "1.0.0-rc.1");
  assert.equal(core.ENTITY_TYPES.length, 7);
  assert.deepEqual(core.EXCERPT_POLICIES, ["required", "optional", "disabled"]);
});

test("core exports no HTTP client or application store", () => {
  assert.equal("ComposerApiClient" in core, false);
  assert.equal("createComposerStore" in core, false);
  assert.equal("validateConfigPackage" in core, false);
});
