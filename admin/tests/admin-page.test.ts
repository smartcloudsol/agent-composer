import assert from "node:assert/strict";
import test from "node:test";
import { resolveComposerAdminPage } from "../src/admin-page.ts";

test("Composer opens each requested supported section", () => {
  for (const section of ["overview", "configuration", "blueprints", "proposals", "mcp-access", "providers", "audit"]) assert.equal(resolveComposerAdminPage(`?page=plugin&section=${section}`), section);
});
test("Composer rejects arbitrary and missing section values", () => {
  for (const search of ["", "?section=unknown", "?section=OVERVIEW", "?section=", "?section=__proto__", "?section=constructor"])
    assert.equal(resolveComposerAdminPage(search), "overview");
});
